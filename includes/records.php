<?php
declare(strict_types=1);

/**
 * Γενικές καταγραφές Υγείας (ECG, συμπτώματα, state of mind, φάρμακα κ.λπ.).
 * Ό,τι υπάρχει στο JSON κάτω από data.<κλειδί> (εκτός από metrics/workouts) και είναι λίστα εγγραφών
 * αποθηκεύεται ως: σύνοψη (βαθμωτά πεδία) + προαιρετική σειρά (π.χ. κυματομορφή ECG).
 */

/** @return array<int,array{kind:string,id:string,ts:string,day:string,summary:array,series:?array}> */
function parse_health_records(mixed $json): array
{
    if (!is_array($json) || !is_array($json['data'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($json['data'] as $key => $list) {
        $kind = metric_norm_name((string)$key);
        if (in_array($kind, ['metrics', 'workouts', ''], true) || !is_array($list) || !$list || !array_is_list($list)) {
            continue;
        }
        foreach ($list as $rec) {
            if (!is_array($rec) || array_is_list($rec)) {
                continue;
            }
            $startRaw = hk_first($rec, ['start', 'startDate', 'date', 'time', 'timestamp']);
            $dt = hk_parse_datetime($startRaw);
            if ($dt === null) {
                continue;
            }
            $ts = $dt[0] . ' ' . ($dt[1] ?? '00:00:00');

            $summary = [];
            $series = null;
            foreach ($rec as $k => $v) {
                $lk = strtolower((string)$k);
                if (in_array($lk, ['start', 'startdate', 'date', 'time', 'timestamp', 'id', 'uuid', 'source'], true)) {
                    continue;
                }
                if (is_scalar($v) && $v !== '') {
                    $summary[(string)$k] = is_bool($v) ? ($v ? 'yes' : 'no') : (string)$v;
                } elseif (is_array($v) && !array_is_list($v) && ($q = hk_qty($v)) !== null) {
                    $summary[(string)$k] = rtrim(rtrim(number_format($q[0], 2, '.', ''), '0'), '.') . ($q[1] ? ' ' . $q[1] : '');
                } elseif (is_array($v) && array_is_list($v) && count($v) >= 20) {
                    $s = record_series($v);
                    if ($s !== null && ($series === null || count($s) > count($series))) {
                        $series = $s;
                    }
                }
            }
            $id = (string)(hk_first($rec, ['id', 'uuid']) ?? '');
            if ($id === '') {
                $id = sha1($kind . '|' . $ts . '|' . json_encode($summary));
            }
            $out[] = ['kind' => $kind, 'id' => $id, 'ts' => $ts, 'day' => $dt[0], 'summary' => $summary, 'series' => $series];
        }
    }
    return $out;
}

/** Αριθμητική σειρά από λίστα: αριθμοί, ή εγγραφές με voltage/value/qty (+ time). Επιστρέφει ≤1500 σημεία. */
function record_series(array $list): ?array
{
    $ys = [];
    foreach ($list as $el) {
        if (is_int($el) || is_float($el) || (is_string($el) && is_numeric($el))) {
            $ys[] = (float)$el;
        } elseif (is_array($el)) {
            $y = null;
            if (array_is_list($el)) {
                $y = isset($el[1]) && is_numeric($el[1]) ? (float)$el[1] : (isset($el[0]) && is_numeric($el[0]) ? (float)$el[0] : null);
            } else {
                foreach (['voltage', 'microvolts', 'value', 'qty', 'v', 'y'] as $k) {
                    if (isset($el[$k]) && is_numeric($el[$k])) {
                        $y = (float)$el[$k];
                        break;
                    }
                }
            }
            if ($y === null) {
                return null;
            }
            $ys[] = $y;
        } else {
            return null;
        }
    }
    $n = count($ys);
    if ($n > 1500) {
        $step = $n / 1500;
        $ds = [];
        for ($i = 0; $i < 1500; $i++) {
            $ds[] = $ys[(int)floor($i * $step)];
        }
        $ys = $ds;
    }
    return array_map(fn($y) => round($y, 2), $ys);
}

function upsert_health_records(array $records): void
{
    $stmt = varos_db()->prepare('
        INSERT INTO health_records (kind, external_id, ts, day, summary, series) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(kind, external_id) DO UPDATE SET ts = excluded.ts, day = excluded.day, summary = excluded.summary, series = excluded.series
    ');
    foreach ($records as $r) {
        $series = null;
        if ($r['series'] !== null) {
            $raw = json_encode($r['series']);
            $series = function_exists('gzcompress') ? gzcompress($raw, 6) : $raw;
        }
        $stmt->bindValue(1, $r['kind']);
        $stmt->bindValue(2, $r['id']);
        $stmt->bindValue(3, $r['ts']);
        $stmt->bindValue(4, $r['day']);
        $stmt->bindValue(5, json_encode($r['summary'], JSON_UNESCAPED_UNICODE));
        $stmt->bindValue(6, $series, $series === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->execute();
    }
}

function count_health_records(): int
{
    return (int)varos_db()->query('SELECT COUNT(*) FROM health_records')->fetchColumn();
}

/** Οι τελευταίες $perKind εγγραφές ανά είδος: [ kind => [ rows... ] ] (με αποσυμπιεσμένη σειρά). */
function get_health_records(int $perKind = 15): array
{
    $kinds = varos_db()->query('SELECT DISTINCT kind FROM health_records ORDER BY kind')->fetchAll(PDO::FETCH_COLUMN);
    $stmt = varos_db()->prepare('SELECT kind, ts, summary, series FROM health_records WHERE kind = ? ORDER BY ts DESC LIMIT ' . (int)$perKind);
    $out = [];
    foreach ($kinds as $kind) {
        $stmt->execute([$kind]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $series = null;
            if ($r['series'] !== null && $r['series'] !== '') {
                $raw = is_resource($r['series']) ? stream_get_contents($r['series']) : (string)$r['series'];
                $json = function_exists('gzuncompress') ? @gzuncompress($raw) : false;
                $series = json_decode($json !== false ? $json : $raw, true);
            }
            $out[$kind][] = [
                'ts' => $r['ts'],
                'summary' => json_decode((string)$r['summary'], true) ?: [],
                'series' => is_array($series) ? $series : null,
            ];
        }
    }
    return $out;
}

function record_kind_label(string $kind): string
{
    if (t_has('rk.' . $kind)) {
        return t('rk.' . $kind);
    }
    return str_starts_with($kind, 'ecg') ? t('rk.ecg') : ucfirst(str_replace('_', ' ', $kind));
}

/** Πεδία σύνοψης προς εμφάνιση (έως 4, με προτεραιότητα στα πιο ενδεικτικά). */
function record_summary_items(array $summary): array
{
    $prio = fn(string $k) => preg_match('/classification|rhythm|result|name|type|label|kind/i', $k) ? 0 : (preg_match('/severity|heart|bpm|value|valence/i', $k) ? 1 : 2);
    $keys = array_keys($summary);
    usort($keys, fn($a, $b) => [$prio($a), $a] <=> [$prio($b), $b]);
    $items = [];
    foreach (array_slice($keys, 0, 4) as $k) {
        $label = ucfirst(strtolower(trim((string)preg_replace('/(?<=[a-z])(?=[A-Z])|_/', ' ', $k))));
        $items[] = [$label, $summary[$k]];
    }
    return $items;
}
