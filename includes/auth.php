<?php
declare(strict_types=1);

/**
 * Σύνδεση διαχειριστή (session). Οι σελίδες προβολής είναι ανοιχτές· ρυθμίσεις και
 * καταχώριση/διαγραφή βάρους απαιτούν σύνδεση.
 */

const VAROS_SESSION_DAYS = 30;

/** Ρυθμίσεις session: δικός μας φάκελος (data/sessions) ώστε να μην τα σβήνει το cron του συστήματος. */
function varos_session_configure(): void
{
    $dir = __DIR__ . '/../data/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    // Οι σελίδες στο api/ δεν χρειάζονται session· το cookie ισχύει για ολόκληρη την εφαρμογή.
    if (str_ends_with($path, '/api')) {
        $path = substr($path, 0, -4);
    }
    session_name('varos_sid');
    ini_set('session.gc_maxlifetime', (string)(VAROS_SESSION_DAYS * 86400));
    session_set_cookie_params([
        'lifetime' => VAROS_SESSION_DAYS * 86400,
        'path' => $path === '' ? '/' : $path . '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Ξεκινά το session μόνο αν υπάρχει ήδη cookie (ή αν ζητηθεί ρητά, π.χ. στη σύνδεση). */
function varos_session_start(bool $force = false): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (!$force && !isset($_COOKIE['varos_sid'])) {
        return;
    }
    varos_session_configure();
    @session_start();
}

function varos_is_admin(): bool
{
    return !empty($_SESSION['varos_auth']);
}

function varos_login(): void
{
    varos_session_start(true);
    session_regenerate_id(true);
    $_SESSION['varos_auth'] = true;
}

function varos_logout(): void
{
    varos_session_start();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
}

/** Ανακατεύθυνση στη σύνδεση αν ο χρήστης δεν είναι συνδεδεμένος. */
function varos_require_admin(string $returnTo): void
{
    if (!varos_is_admin()) {
        header('Location: login.php?redirect=' . urlencode($returnTo));
        exit;
    }
}
