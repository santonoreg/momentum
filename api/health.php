<?php
declare(strict_types=1);

/**
 * Endpoint συγχρονισμού Apple Health.
 * POST JSON με προπονήσεις + κλειδί πρόσβασης (Authorization: Bearer <token>, X-API-Key ή ?token=).
 * Κάθε αίτημα καταγράφεται στο data/sync.log (τα τελευταία 30) για διάγνωση προβλημάτων.
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$GLOBALS['sync_note'] = '';

function sync_log(int $code, array $data): void
{
    try {
        $file = __DIR__ . '/../data/sync.log';
        $keys = [];
        $raw = $GLOBALS['sync_raw'] ?? '';
        $json = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($json)) {
            $keys = array_slice(array_keys($json), 0, 8);
            if (isset($json['data']) && is_array($json['data'])) {
                $keys = array_merge($keys, array_map(fn($k) => 'data.' . $k, array_slice(array_keys($json['data']), 0, 8)));
            }
        }
        $line = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'http' => $code,
            'result' => $data,
            'note' => $GLOBALS['sync_note'],
            'body_bytes' => strlen($raw),
            'top_keys' => $keys,
            'body_start' => substr($raw, 0, 600),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $lines[] = $line;
        file_put_contents($file, implode("\n", array_slice($lines, -30)) . "\n", LOCK_EX);
    } catch (Throwable $e) {
        // Η καταγραφή δεν πρέπει ποτέ να χαλάει την απάντηση.
        error_log('varos sync_log: ' . $e->getMessage());
    }
}

function api_out(int $code, array $data): void
{
    sync_log($code, $data);
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Τιμή κεφαλίδας — με fallback για ρυθμίσεις (nginx/FPM) που δεν την περνούν στο $_SERVER. */
function request_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$key])) {
        return (string)$_SERVER[$key];
    }
    if ($name === 'Authorization' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, $name) === 0) {
                return (string)$v;
            }
        }
    }
    return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    api_out(405, ['ok' => false, 'error' => 'Use POST']);
}

$raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
$GLOBALS['sync_raw'] = $raw === false ? '' : $raw;

$given = '';
if (preg_match('/^Bearer\s+(\S+)/i', request_header('Authorization'), $m)) {
    $given = $m[1];
} elseif (request_header('X-API-Key') !== '') {
    $given = request_header('X-API-Key');
} elseif (!empty($_GET['token'])) {
    $given = (string)$_GET['token'];
}

$expected = (string)(varos_get_settings()['api_token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    $GLOBALS['sync_note'] = $given === '' ? 'no token received (Authorization header missing/stripped?)' : 'wrong token';
    api_out(401, ['ok' => false, 'error' => 'Invalid or missing token']);
}

if ($raw === false || $raw === '' || strlen($raw) > 8 * 1024 * 1024) {
    api_out(400, ['ok' => false, 'error' => 'Empty or too large body']);
}
$json = json_decode($raw, true);
if (!is_array($json)) {
    api_out(400, ['ok' => false, 'error' => 'Body is not valid JSON']);
}

$workouts = parse_health_payload($json);
$steps = parse_health_steps($json);
if (!$workouts && !$steps) {
    $names = payload_metric_names($json);
    $GLOBALS['sync_note'] = 'authenticated, but no workouts or step_count found in payload'
        . ($names ? ' (metrics received: ' . implode(', ', array_slice($names, 0, 10)) . ')' : '');
}

$pdo = varos_db();
$pdo->beginTransaction();
try {
    foreach ($workouts as $w) {
        upsert_workout($w);
    }
    upsert_step_samples($steps);
    varos_touch_last_sync();
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    $GLOBALS['sync_note'] = 'db error: ' . $ex->getMessage();
    api_out(500, ['ok' => false, 'error' => 'Could not store workouts']);
}

api_out(200, ['ok' => true, 'imported' => count($workouts), 'step_samples' => count($steps)]);
