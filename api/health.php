<?php
declare(strict_types=1);

/**
 * Endpoint συγχρονισμού Apple Health.
 * POST JSON με προπονήσεις + κλειδί πρόσβασης (Authorization: Bearer <token>, X-API-Key ή ?token=).
 */
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_out(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    api_out(405, ['ok' => false, 'error' => 'Use POST']);
}

$given = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
    $given = $m[1];
} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
    $given = (string)$_SERVER['HTTP_X_API_KEY'];
} elseif (!empty($_GET['token'])) {
    $given = (string)$_GET['token'];
}

$expected = (string)(varos_get_settings()['api_token'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    api_out(401, ['ok' => false, 'error' => 'Invalid or missing token']);
}

$raw = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024 + 1);
if ($raw === false || $raw === '' || strlen($raw) > 8 * 1024 * 1024) {
    api_out(400, ['ok' => false, 'error' => 'Empty or too large body']);
}
$json = json_decode($raw, true);
if (!is_array($json)) {
    api_out(400, ['ok' => false, 'error' => 'Body is not valid JSON']);
}

$workouts = parse_health_payload($json);

$pdo = varos_db();
$pdo->beginTransaction();
try {
    foreach ($workouts as $w) {
        upsert_workout($w);
    }
    varos_touch_last_sync();
    $pdo->commit();
} catch (Throwable $ex) {
    $pdo->rollBack();
    api_out(500, ['ok' => false, 'error' => 'Could not store workouts']);
}

api_out(200, ['ok' => true, 'imported' => count($workouts)]);
