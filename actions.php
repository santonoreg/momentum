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

// Πριν οριστεί κωδικός επιτρέπεται μόνο η αρχική ρύθμιση (και οι προτιμήσεις εμφάνισης).
if (!varos_has_entry_code() && !in_array($action, ['setup_code', 'save_prefs'], true)) {
    header('Location: setup.php');
    exit;
}

/** Ελέγχει τον κωδικό που πληκτρολογήθηκε· αλλιώς επιστρέφει με μήνυμα λάθους. */
function require_code(string $redirect, string $field = 'code'): void
{
    if (!varos_check_entry_code((string)($_POST[$field] ?? ''))) {
        back($redirect, 'badcode');
    }
}

switch ($action) {
    case 'setup_code': {
        // Αρχική ρύθμιση: επιτρέπεται μόνο όσο δεν υπάρχει κωδικός.
        if (varos_has_entry_code()) {
            back('reports.php', 'error');
        }
        $new = (string)($_POST['new_code'] ?? '');
        if (strlen($new) < 4 || strlen($new) > 64 || $new !== (string)($_POST['confirm_code'] ?? '')) {
            back('setup.php', 'error');
        }
        varos_set_entry_code($new);
        varos_login();
        back('reports.php', 'saved');
        break;
    }

    case 'login': {
        if (!varos_check_entry_code((string)($_POST['password'] ?? ''))) {
            back('login.php?redirect=' . urlencode($redirect), 'badcode');
        }
        varos_login();
        header('Location: ' . $redirect);
        exit;
    }

    case 'logout': {
        varos_logout();
        header('Location: reports.php');
        exit;
    }

    case 'save_entry': {
        varos_require_admin($redirect);
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
        varos_require_admin($redirect);
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            delete_entry($id);
        }
        back($redirect, 'deleted');
        break;
    }

    case 'save_settings': {
        varos_require_admin($redirect);
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

    case 'save_code': {
        // Αλλαγή κωδικού: απαιτείται σύνδεση και ο τρέχων κωδικός.
        varos_require_admin($redirect);
        require_code($redirect, 'current_code');
        $new = (string)($_POST['new_code'] ?? '');
        if (strlen($new) < 4 || strlen($new) > 64) {
            back($redirect, 'error');
        }
        varos_set_entry_code($new);
        back($redirect, 'saved');
        break;
    }

    case 'regen_token': {
        varos_require_admin($redirect);
        varos_regenerate_token();
        back($redirect, 'token');
        break;
    }

    default:
        back($redirect, 'error');
}
