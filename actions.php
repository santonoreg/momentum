<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reports.php');
    exit;
}

$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? 'reports.php';
// Μόνο τοπικά αρχεία επιτρέπονται ως προορισμός ανακατεύθυνσης.
if (!preg_match('/^[a-z_]+\.php$/', $redirect)) {
    $redirect = 'reports.php';
}

function back(string $to, string $status = 'ok'): void
{
    $sep = str_contains($to, '?') ? '&' : '?';
    header('Location: ' . $to . $sep . 'flash=' . urlencode($status));
    exit;
}

switch ($action) {
    case 'save_entry': {
        $date = trim($_POST['entry_date'] ?? '');
        $weightRaw = str_replace(',', '.', trim($_POST['weight'] ?? ''));
        $note = trim($_POST['note'] ?? '');
        $note = $note === '' ? null : $note;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($weightRaw)) {
            back($redirect, 'error');
        }
        $weight = (float)$weightRaw;
        if ($weight <= 0 || $weight > 500) {
            back($redirect, 'error');
        }
        upsert_entry($date, $weight, $note);
        back($redirect, 'saved');
        break;
    }

    case 'delete_entry': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            delete_entry($id);
        }
        back($redirect, 'deleted');
        break;
    }

    case 'save_settings': {
        $heightRaw = str_replace(',', '.', trim($_POST['height_cm'] ?? ''));
        $startRaw = str_replace(',', '.', trim($_POST['start_weight'] ?? ''));
        $goalRaw = str_replace(',', '.', trim($_POST['goal_weight'] ?? ''));
        $startDate = trim($_POST['start_date'] ?? '');
        $milestoneCount = (int)($_POST['milestone_count'] ?? 8);
        $milestoneCount = max(1, min(20, $milestoneCount));

        varos_save_settings([
            'height_cm' => is_numeric($heightRaw) ? (float)$heightRaw : null,
            'start_weight' => is_numeric($startRaw) ? (float)$startRaw : null,
            'goal_weight' => is_numeric($goalRaw) ? (float)$goalRaw : null,
            'start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) ? $startDate : null,
            'milestone_count' => $milestoneCount,
        ]);
        back($redirect, 'saved');
        break;
    }

    case 'save_prefs': {
        $lang = $_POST['lang'] ?? '';
        $width = $_POST['width'] ?? '';
        if (!isset(VAROS_LANGS[$lang]) || !in_array($width, VAROS_WIDTHS, true)) {
            back($redirect, 'error');
        }
        varos_set_pref_cookie('varos_lang', $lang);
        varos_set_pref_cookie('varos_width', $width);
        back($redirect, 'saved');
        break;
    }

    case 'regen_token': {
        varos_regenerate_token();
        back($redirect, 'token');
        break;
    }

    default:
        back($redirect, 'error');
}
