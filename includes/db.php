<?php
declare(strict_types=1);

/**
 * Σύνδεση με τη βάση SQLite + δημιουργία schema αν δεν υπάρχει.
 */
function varos_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbDir = __DIR__ . '/../data';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }
    $dbPath = $dbDir . '/varos.db';
    $isNew = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            height_cm REAL,
            start_weight REAL,
            goal_weight REAL,
            start_date TEXT,
            milestone_count INTEGER NOT NULL DEFAULT 8
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entry_date TEXT NOT NULL UNIQUE,
            weight REAL NOT NULL,
            note TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS workouts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_id TEXT NOT NULL UNIQUE,
            workout_date TEXT NOT NULL,
            start_time TEXT,
            type TEXT NOT NULL,
            duration_min REAL NOT NULL DEFAULT 0,
            distance_km REAL,
            calories REAL,
            avg_hr REAL,
            source TEXT NOT NULL DEFAULT \'apple_health\',
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workouts_date ON workouts (workout_date)');

    // Βήματα: ένα δείγμα ανά χρονική στιγμή (ημερήσιο ή ωριαίο, ανάλογα με τον συγκεντρωτικό τρόπο του export).
    // Το κλειδί είναι η στιγμή του δείγματος, ώστε η επαναποστολή να μην διπλομετράει.
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS step_samples (
            sample_ts TEXT PRIMARY KEY,
            day TEXT NOT NULL,
            steps REAL NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_step_samples_day ON step_samples (day)');

    // Μετανάστευση: νέες στήλες στον πίνακα ρυθμίσεων (κλειδί API + τελευταίος συγχρονισμός).
    $cols = array_column($pdo->query('PRAGMA table_info(settings)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('api_token', $cols, true)) {
        $pdo->exec('ALTER TABLE settings ADD COLUMN api_token TEXT');
    }
    if (!in_array('entry_code_hash', $cols, true)) {
        $pdo->exec('ALTER TABLE settings ADD COLUMN entry_code_hash TEXT');
    }
    if (!in_array('last_sync_at', $cols, true)) {
        $pdo->exec('ALTER TABLE settings ADD COLUMN last_sync_at TEXT');
    }

    // Εξασφαλίζουμε ότι υπάρχει πάντα η μοναδική γραμμή ρυθμίσεων (id=1).
    $exists = $pdo->query('SELECT COUNT(*) FROM settings WHERE id = 1')->fetchColumn();
    if (!$exists) {
        $pdo->exec("INSERT INTO settings (id, milestone_count) VALUES (1, 8)");
    }

    // Κλειδί πρόσβασης για το API συγχρονισμού (δημιουργείται μία φορά).
    if (!$pdo->query('SELECT api_token FROM settings WHERE id = 1')->fetchColumn()) {
        $pdo->prepare('UPDATE settings SET api_token = :t WHERE id = 1')->execute([':t' => bin2hex(random_bytes(20))]);
    }

    return $pdo;
}

/** Έχει οριστεί κωδικός καταχώρισης; (αν όχι, η καταχώριση είναι ελεύθερη) */
function varos_has_entry_code(): bool
{
    return (string)(varos_get_settings()['entry_code_hash'] ?? '') !== '';
}

/** Σωστός κωδικός; Επιστρέφει true και όταν δεν έχει οριστεί κωδικός. */
function varos_check_entry_code(string $code): bool
{
    $hash = (string)(varos_get_settings()['entry_code_hash'] ?? '');
    if ($hash === '') {
        return true;
    }
    if (password_verify($code, $hash)) {
        return true;
    }
    usleep(600000); // επιβράδυνση για να δυσκολεύει η δοκιμή πολλών κωδικών
    return false;
}

function varos_set_entry_code(?string $code): void
{
    varos_db()->prepare('UPDATE settings SET entry_code_hash = :h WHERE id = 1')
        ->execute([':h' => $code === null ? null : password_hash($code, PASSWORD_DEFAULT)]);
}

function varos_regenerate_token(): void
{
    varos_db()->prepare('UPDATE settings SET api_token = :t WHERE id = 1')->execute([':t' => bin2hex(random_bytes(20))]);
}

function varos_touch_last_sync(): void
{
    varos_db()->prepare('UPDATE settings SET last_sync_at = :t WHERE id = 1')->execute([':t' => date('Y-m-d H:i:s')]);
}

function varos_get_settings(): array
{
    $row = varos_db()->query('SELECT * FROM settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

function varos_save_settings(array $data): void
{
    $stmt = varos_db()->prepare('
        UPDATE settings SET
            height_cm = :height_cm,
            start_weight = :start_weight,
            goal_weight = :goal_weight,
            start_date = :start_date,
            milestone_count = :milestone_count
        WHERE id = 1
    ');
    $stmt->execute([
        ':height_cm' => $data['height_cm'],
        ':start_weight' => $data['start_weight'],
        ':goal_weight' => $data['goal_weight'],
        ':start_date' => $data['start_date'],
        ':milestone_count' => $data['milestone_count'],
    ]);
}
