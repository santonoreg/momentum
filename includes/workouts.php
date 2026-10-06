<?php
declare(strict_types=1);

/**
 * Προπονήσεις: αποθήκευση, στατιστικά και ανάλυση των δεδομένων που στέλνει το iPhone
 * (Health Auto Export ή Συντομεύσεις) στο api/health.php.
 */

/** Όλες οι προπονήσεις, οι πιο πρόσφατες πρώτα. */
function get_workouts(?int $limit = null): array
{
    $sql = 'SELECT * FROM workouts ORDER BY workout_date DESC, COALESCE(start_time, \'\') DESC, id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    return varos_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function count_workouts(): int
{
    return (int)varos_db()->query('SELECT COUNT(*) FROM workouts')->fetchColumn();
}

function upsert_workout(array $w): void
{
    $stmt = varos_db()->prepare('
        INSERT INTO workouts (external_id, workout_date, start_time, type, duration_min, distance_km, calories, avg_hr)
        VALUES (:id, :d, :t, :type, :dur, :dist, :cal, :hr)
        ON CONFLICT(external_id) DO UPDATE SET
            workout_date = excluded.workout_date, start_time = excluded.start_time, type = excluded.type,
            duration_min = excluded.duration_min, distance_km = COALESCE(excluded.distance_km, distance_km),
            calories = COALESCE(excluded.calories, calories), avg_hr = COALESCE(excluded.avg_hr, avg_hr)
    ');
    $stmt->execute([
        ':id' => $w['external_id'], ':d' => $w['date'], ':t' => $w['time'], ':type' => $w['type'],
        ':dur' => $w['duration_min'], ':dist' => $w['distance_km'], ':cal' => $w['calories'], ':hr' => $w['avg_hr'],
    ]);
}

/** Σύνολα (πλήθος, λεπτά, kcal, χλμ) για τις τελευταίες $days ημέρες, σήμερα συμπεριλαμβανομένης. */
function workouts_summary(array $workouts, int $days): array
{
    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $sum = ['count' => 0, 'minutes' => 0.0, 'calories' => 0.0, 'distance' => 0.0];
    foreach ($workouts as $w) {
        if ($w['workout_date'] < $since) {
            continue;
        }
        $sum['count']++;
        $sum['minutes'] += (float)$w['duration_min'];
        $sum['calories'] += (float)$w['calories'];
        $sum['distance'] += (float)$w['distance_km'];
    }
    return $sum;
}

/** Λεπτά άσκησης ανά εβδομάδα (Δευτέρα–Κυριακή) για τις τελευταίες $weeks εβδομάδες. */
function weekly_minutes(array $workouts, int $weeks = 12): array
{
    $labels = [];
    $values = [];
    for ($i = $weeks - 1; $i >= 0; $i--) {
        $start = date('Y-m-d', strtotime('monday this week', strtotime("-{$i} weeks")));
        $end = date('Y-m-d', strtotime($start . ' +6 days'));
        $minutes = 0.0;
        foreach ($workouts as $w) {
            if ($w['workout_date'] >= $start && $w['workout_date'] <= $end) {
                $minutes += (float)$w['duration_min'];
            }
        }
        $labels[] = date('j/n', strtotime($start));
        $values[] = round($minutes);
    }
    return [$labels, $values];
}

/** «HKWorkoutActivityTypeRunning» / «TraditionalStrengthTraining» / «Outdoor Run» -> αναγνώσιμο όνομα. */
function normalize_workout_type(string $raw): string
{
    $s = trim($raw);
    if (str_starts_with($s, 'HKWorkoutActivityType')) {
        $s = substr($s, strlen('HKWorkoutActivityType'));
    }
    $s = str_replace('_', ' ', $s);
    if (!str_contains($s, ' ')) {
        $s = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $s);
    }
    return $s === '' ? 'Workout' : $s;
}

/** Όνομα προπόνησης στην τρέχουσα γλώσσα (αλλιώς το αρχικό όνομα). */
function workout_type_label(string $type): string
{
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($type)), '_');
    return t_has('wt.' . $slug) ? t('wt.' . $slug) : $type;
}

// ---------------------------------------------------------------- ανάλυση payload

/** Τιμή + μονάδα από 5.2 | "5.2 km" | {"qty":5.2,"units":"km"} | {"avg":{"qty":..}}. */
function hk_qty(mixed $v): ?array
{
    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
        return [(float)$v, null];
    }
    if (is_string($v) && preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s*([A-Za-z]*)/', $v, $m)) {
        return [(float)str_replace(',', '.', $m[1]), $m[2] !== '' ? $m[2] : null];
    }
    if (is_array($v)) {
        foreach (['qty', 'value', 'avg'] as $k) {
            if (isset($v[$k])) {
                $r = hk_qty($v[$k]);
                if ($r !== null) {
                    return [$r[0], $r[1] ?? ($v['units'] ?? $v['unit'] ?? null)];
                }
            }
        }
    }
    return null;
}

function hk_first(array $w, array $keys): mixed
{
    foreach ($keys as $k) {
        if (isset($w[$k]) && $w[$k] !== '' && $w[$k] !== []) {
            return $w[$k];
        }
    }
    return null;
}

/** Ημερομηνία/ώρα όπως γράφτηκαν (τοπική ώρα του κινητού), χωρίς μετατροπή ζώνης ώρας. */
function hk_parse_datetime(mixed $v): ?array
{
    if (!is_string($v) || $v === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2})(?::(\d{2}))?)?/', $v, $m)) {
        return [$m[1], isset($m[2]) ? $m[2] . ':' . ($m[3] ?? '00') : null];
    }
    $ts = strtotime($v);
    return $ts ? [date('Y-m-d', $ts), date('H:i:s', $ts)] : null;
}

/**
 * Μία προπόνηση από το JSON -> κανονικοποιημένη μορφή ή null αν δεν αναγνωρίζεται.
 * Δέχεται τη μορφή του Health Auto Export και μια απλή «δική σου» μορφή (βλ. README).
 */
function parse_workout(array $w): ?array
{
    $start = hk_parse_datetime(hk_first($w, ['start', 'startDate', 'start_date', 'date']));
    if ($start === null) {
        return null;
    }
    [$date, $time] = $start;

    $type = normalize_workout_type((string)(hk_first($w, ['name', 'type', 'workoutActivityType', 'workout_type', 'activity']) ?? 'Workout'));

    // Διάρκεια -> λεπτά
    $minutes = null;
    if (($v = hk_first($w, ['duration_min', 'durationMinutes'])) !== null && ($q = hk_qty($v))) {
        $minutes = $q[0];
    } elseif (($v = hk_first($w, ['duration_sec', 'duration'])) !== null && ($q = hk_qty($v))) {
        $unit = strtolower((string)($q[1] ?? 's'));
        $minutes = match (true) {
            in_array($unit, ['min', 'mins', 'minute', 'minutes'], true) => $q[0],
            in_array($unit, ['h', 'hr', 'hrs', 'hour', 'hours'], true) => $q[0] * 60,
            default => $q[0] / 60,
        };
    } else {
        $endTs = strtotime((string)hk_first($w, ['end', 'endDate', 'end_date']));
        $startTs = strtotime((string)hk_first($w, ['start', 'startDate', 'start_date', 'date']));
        if ($endTs && $startTs && $endTs > $startTs) {
            $minutes = ($endTs - $startTs) / 60;
        }
    }

    // Απόσταση -> χλμ
    $km = null;
    if (($v = hk_first($w, ['distance_km'])) !== null && ($q = hk_qty($v))) {
        $km = $q[0];
    } elseif (($v = hk_first($w, ['distance_m'])) !== null && ($q = hk_qty($v))) {
        $km = $q[0] / 1000;
    } elseif (($v = hk_first($w, ['distance', 'totalDistance'])) !== null && ($q = hk_qty($v))) {
        $km = match (strtolower((string)($q[1] ?? 'km'))) {
            'm', 'meter', 'meters', 'metre', 'metres' => $q[0] / 1000,
            'mi', 'mile', 'miles' => $q[0] * 1.609344,
            'yd', 'yard', 'yards' => $q[0] * 0.0009144,
            default => $q[0],
        };
    }

    // Ενέργεια -> kcal
    $kcal = null;
    if (($v = hk_first($w, ['calories', 'activeEnergyBurned', 'activeEnergy', 'totalEnergyBurned', 'energy'])) !== null && ($q = hk_qty($v))) {
        $kcal = strtolower((string)($q[1] ?? 'kcal')) === 'kj' ? $q[0] / 4.184 : $q[0];
    }

    // Μέσοι παλμοί
    $hr = null;
    if (($v = hk_first($w, ['avg_hr', 'avgHeartRate', 'averageHeartRate', 'heartRate'])) !== null && ($q = hk_qty($v))) {
        $hr = $q[0];
    }

    $externalId = (string)(hk_first($w, ['id', 'uuid', 'external_id']) ?? '');
    if ($externalId === '') {
        $externalId = sha1($date . '|' . ($time ?? '') . '|' . $type . '|' . round((float)$minutes));
    }

    return [
        'external_id' => $externalId,
        'date' => $date,
        'time' => $time,
        'type' => $type,
        'duration_min' => $minutes !== null ? round($minutes, 2) : 0.0,
        'distance_km' => $km !== null ? round($km, 3) : null,
        'calories' => $kcal !== null ? round($kcal, 1) : null,
        'avg_hr' => $hr !== null ? round($hr, 1) : null,
    ];
}

/** Βρίσκει τη λίστα προπονήσεων μέσα σε οποιοδήποτε από τα υποστηριζόμενα σχήματα JSON. */
function parse_health_payload(mixed $json): array
{
    if (!is_array($json)) {
        return [];
    }
    $list = $json['data']['workouts'] ?? $json['workouts'] ?? null;
    if ($list === null) {
        // Είτε λίστα προπονήσεων, είτε μία μόνη προπόνηση.
        $list = array_is_list($json) ? $json : [$json];
    }
    $out = [];
    foreach ($list as $w) {
        if (is_array($w) && ($parsed = parse_workout($w)) !== null) {
            $out[] = $parsed;
        }
    }
    return $out;
}

/** Βασική διεύθυνση της εφαρμογής (για να εμφανίσουμε το URL του API στις ρυθμίσεις). */
function app_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
