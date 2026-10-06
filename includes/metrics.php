<?php
declare(strict_types=1);

/**
 * Γενικές μετρήσεις Υγείας (Health Auto Export → Health Metrics).
 * Κάθε μετρική αποθηκεύεται ως γραμμές (metric, ts, field, value) — δεν χρειάζεται κώδικας ανά μετρική.
 * Το «μητρώο» παρακάτω ορίζει μόνο ομάδα εμφάνισης και τρόπο ημερήσιου υπολογισμού (sum / avg).
 */

const METRIC_GROUPS = ['activity', 'heart', 'sleep', 'body', 'mobility', 'environment', 'other'];

/** name => [group, agg]  (αγνωστες μετρικές: other/avg) */
function metric_registry(): array
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    $r = [];
    $add = function (string $group, string $agg, array $names) use (&$r) {
        foreach ($names as $n) {
            $r[$n] = [$group, $agg];
        }
    };
    $add('activity', 'sum', ['step_count', 'active_energy', 'basal_energy_burned', 'apple_exercise_time', 'apple_stand_time',
        'apple_stand_hour', 'flights_climbed', 'walking_running_distance', 'cycling_distance', 'swimming_distance',
        'time_in_daylight', 'push_count', 'swimming_stroke_count']);
    $add('activity', 'avg', ['physical_effort']);
    $add('heart', 'avg', ['heart_rate', 'resting_heart_rate', 'walking_heart_rate_average', 'heart_rate_variability',
        'cardio_recovery', 'vo2_max', 'blood_oxygen_saturation', 'respiratory_rate', 'blood_pressure']);
    $add('sleep', 'sum', ['sleep_analysis']);
    $add('body', 'avg', ['weight_body_mass', 'body_mass_index', 'body_fat_percentage', 'lean_body_mass',
        'waist_circumference', 'body_temperature', 'apple_sleeping_wrist_temperature']);
    $add('mobility', 'avg', ['walking_speed', 'walking_step_length', 'walking_asymmetry_percentage',
        'walking_double_support_percentage', 'apple_walking_steadiness', 'stair_speed_up', 'stair_speed_down',
        'six_minute_walking_test_distance']);
    $add('environment', 'avg', ['environmental_audio_exposure', 'headphone_audio_exposure']);
    return $r;
}

function metric_info(string $name): array
{
    return metric_registry()[$name] ?? ['other', 'avg'];
}

/** «stepCount» / «Step Count» / «step_count» -> step_count */
function metric_norm_name(string $n): string
{
    $n = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', trim($n));
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower((string)$n)), '_');
}

function metric_label(string $name): string
{
    return t_has('hm.' . $name) ? t('hm.' . $name) : ucfirst(str_replace('_', ' ', $name));
}

function metric_group_label(string $group): string
{
    return t('hg.' . $group);
}

// ---------------------------------------------------------------- αποθήκευση

/**
 * Γραμμές μετρήσεων από το JSON: Health Auto Export { data: { metrics: [ { name, units, data: [...] } ] } }
 * ή απλή μορφή { "steps": [ { "date": "...", "qty": 8234 } ] }.
 * @return array<int,array{0:string,1:string,2:string,3:string,4:float,5:string}> [metric, ts, day, field, value, units]
 */
function parse_health_metrics(mixed $json): array
{
    if (!is_array($json)) {
        return [];
    }
    $groups = [];
    $metrics = $json['data']['metrics'] ?? $json['metrics'] ?? null;
    if (is_array($metrics)) {
        foreach ($metrics as $m) {
            if (is_array($m) && !empty($m['name']) && is_array($m['data'] ?? null)) {
                $groups[] = [metric_norm_name((string)$m['name']), (string)($m['units'] ?? ''), $m['data']];
            }
        }
    } elseif (is_array($json['steps'] ?? null)) {
        $groups[] = ['step_count', 'count', $json['steps']];
    }

    $skip = ['date', 'startdate', 'enddate', 'start', 'end', 'source', 'id', 'uuid',
        'sleepstart', 'sleepend', 'inbedstart', 'inbedend', 'name', 'units'];
    $bucket = [];

    foreach ($groups as [$name, $units, $points]) {
        $isSum = metric_info($name)[1] === 'sum';
        $isSleep = $name === 'sleep_analysis';
        $factor = 1.0;
        if ($isSleep) {
            $u = strtolower($units);
            $factor = in_array($u, ['min', 'mins', 'minute', 'minutes'], true) ? 1 / 60 : (in_array($u, ['s', 'sec', 'secs', 'second', 'seconds'], true) ? 1 / 3600 : 1.0);
            $units = 'hr';
        }
        foreach ($points as $p) {
            if (!is_array($p)) {
                continue;
            }
            $startRaw = hk_first($p, ['date', 'startDate', 'start']);
            $start = hk_parse_datetime($startRaw);
            if ($start === null) {
                continue;
            }
            $ts = $start[0] . ' ' . ($start[1] ?? '00:00:00');
            $day = $start[0];
            $fields = [];

            if ($isSleep && isset($p['value']) && is_string($p['value']) && !is_numeric($p['value'])) {
                // Μη συγκεντρωμένο στάδιο ύπνου: διάρκεια από start/end, η νύχτα ανήκει στην ημέρα που τελειώνει
                $endRaw = hk_first($p, ['endDate', 'end']);
                $a = strtotime((string)$startRaw);
                $b = strtotime((string)$endRaw);
                $endDt = hk_parse_datetime($endRaw);
                if (!$a || !$b || $b <= $a || $endDt === null) {
                    continue;
                }
                $stage = strtolower((string)preg_replace('/[^a-z]/i', '', $p['value']));
                $stage = str_replace('asleep', '', $stage);
                $fields[$stage === '' || $stage === 'unspecified' ? 'asleep' : $stage] = ($b - $a) / 3600;
                $day = $endDt[0];
            } else {
                foreach ($p as $k => $v) {
                    $lk = strtolower((string)$k);
                    if (in_array($lk, $skip, true)) {
                        continue;
                    }
                    if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
                        $fields[$lk === 'value' ? 'qty' : $lk] = (float)$v * $factor;
                    }
                }
            }

            foreach ($fields as $f => $v) {
                if (!is_finite($v)) {
                    continue;
                }
                $key = $name . '|' . $ts . '|' . $f;
                $additive = $isSum && !in_array($f, ['min', 'max', 'avg'], true);
                if ($additive && isset($bucket[$key])) {
                    $bucket[$key][4] += $v;
                } else {
                    $bucket[$key] = [$name, $ts, $day, (string)$f, $v, $units];
                }
            }
        }
    }
    return array_values($bucket);
}

function upsert_health_metrics(array $rows): void
{
    $stmt = varos_db()->prepare('
        INSERT INTO health_metrics (metric, ts, day, field, value, units) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(metric, ts, field) DO UPDATE SET day = excluded.day, value = excluded.value, units = excluded.units
    ');
    foreach ($rows as $r) {
        $stmt->execute($r);
    }
}

/** Ονόματα μετρικών που περιέχει το payload (για διάγνωση). */
function payload_metric_names(mixed $json): array
{
    $metrics = is_array($json) ? ($json['data']['metrics'] ?? $json['metrics'] ?? []) : [];
    $names = [];
    foreach (is_array($metrics) ? $metrics : [] as $m) {
        if (is_array($m) && isset($m['name'])) {
            $names[] = (string)$m['name'];
        }
    }
    return $names;
}

function count_metric_types(): int
{
    return (int)varos_db()->query('SELECT COUNT(DISTINCT metric) FROM health_metrics')->fetchColumn();
}

/** Ημερήσια βήματα για τις τελευταίες $days ημέρες: [ 'Y-m-d' => steps ], μόνο ημέρες με δεδομένα. */
function get_daily_steps(int $days = 30): array
{
    $stmt = varos_db()->prepare("SELECT day, SUM(value) AS v FROM health_metrics WHERE metric = 'step_count' AND field = 'qty' AND day >= :since GROUP BY day ORDER BY day ASC");
    $stmt->execute([':since' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['day']] = (float)$r['v'];
    }
    return $out;
}

// ---------------------------------------------------------------- ανάγνωση / dashboard

/**
 * Ημερήσιες τιμές ανά μετρική/πεδίο για τις τελευταίες $days ημέρες.
 * @return array{0:array,1:array} [ metric => field => day => value ], [ metric => units ]
 */
function metrics_daily(int $days): array
{
    $stmt = varos_db()->prepare('
        SELECT metric, field, day, SUM(value) AS s, AVG(value) AS a, MIN(value) AS mn, MAX(value) AS mx, MAX(units) AS u
        FROM health_metrics WHERE day >= :since GROUP BY metric, field, day
    ');
    $stmt->execute([':since' => date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))]);
    $data = [];
    $units = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $isSum = metric_info($r['metric'])[1] === 'sum';
        $v = $r['field'] === 'min' ? $r['mn'] : ($r['field'] === 'max' ? $r['mx'] : ($isSum ? $r['s'] : $r['a']));
        $data[$r['metric']][$r['field']][$r['day']] = (float)$v;
        if (($r['u'] ?? '') !== '') {
            $units[$r['metric']] = (string)$r['u'];
        }
    }
    return [$data, $units];
}

function metric_fmt(float $v): string
{
    $a = abs($v);
    $dec = abs($v - round($v)) < 1e-9 ? 0 : ($a >= 100 ? 0 : ($a >= 10 ? 1 : 2));
    return fmt_num($v, $dec);
}

/** Κείμενο τιμής με μονάδα (το «count» δεν εμφανίζεται, ο ύπνος σε ώρες/λεπτά). */
function metric_value_text(float $v, string $units, bool $isSleep): string
{
    if ($isSleep) {
        return fmt_duration($v * 60);
    }
    $unit = in_array(strtolower($units), ['', 'count'], true) ? '' : ' ' . $units;
    return metric_fmt($v) . $unit;
}

function _series_stats(array $values, array $dayList): array
{
    $present = [];
    foreach ($dayList as $i => $d) {
        if (isset($values[$i]) && $values[$i] !== null) {
            $present[$d] = (float)$values[$i];
        }
    }
    if (!$present) {
        return ['latest' => null, 'day' => null, 'avg' => null, 'min' => null, 'max' => null];
    }
    $lastDay = array_key_last($present);
    return [
        'latest' => $present[$lastDay], 'day' => $lastDay,
        'avg' => array_sum($present) / count($present),
        'min' => min($present), 'max' => max($present),
    ];
}

/**
 * Κάρτες dashboard. Κάθε κάρτα: label, group, kind (bar|line|sleep), series, stats (έτοιμα κείμενα).
 * @param array $dayList λίστα ημερομηνιών Y-m-d (παλαιότερη -> σημερινή)
 */
function build_metric_cards(array $data, array $units, array $dayList): array
{
    $order = array_flip(array_keys(metric_registry()));
    $cards = [];

    foreach ($data as $name => $fields) {
        [$group, $agg] = metric_info($name);
        $isSleep = $name === 'sleep_analysis';
        $u = $units[$name] ?? '';
        $col = fn(string $f) => array_map(fn($d) => $fields[$f][$d] ?? null, $dayList);
        $series = [];
        $kind = $agg === 'sum' ? 'bar' : 'line';
        $keys = array_keys($fields);
        sort($keys);
        // Αρτηριακή πίεση: πρώτα systolic, μετά diastolic
        usort($keys, fn($x, $y) => [$x === 'systolic' ? 0 : 1, $x] <=> [$y === 'systolic' ? 0 : 1, $y]);

        if ($isSleep) {
            $kind = 'sleep';
            $stages = array_values(array_intersect(['deep', 'core', 'rem', 'awake'], $keys));
            foreach ($stages as $f) {
                $series[] = ['field' => $f, 'label' => t_has('hs.' . $f) ? t('hs.' . $f) : $f, 'values' => $col($f), 'role' => 'main'];
            }
            if (!$series) {
                $f = in_array('totalsleep', $keys, true) ? 'totalsleep' : (in_array('asleep', $keys, true) ? 'asleep' : $keys[0]);
                $series[] = ['field' => $f, 'label' => t_has('hs.' . $f) ? t('hs.' . $f) : $f, 'values' => $col($f), 'role' => 'main'];
            }
            // Σύνολο ύπνου ανά νύχτα
            $total = [];
            foreach ($dayList as $i => $d) {
                if (isset($fields['totalsleep'][$d])) {
                    $total[$i] = $fields['totalsleep'][$d];
                } elseif (isset($fields['asleep'][$d])) {
                    $total[$i] = $fields['asleep'][$d];
                } else {
                    $sum = 0.0;
                    $any = false;
                    foreach (['deep', 'core', 'rem'] as $f) {
                        if (isset($fields[$f][$d])) {
                            $sum += $fields[$f][$d];
                            $any = true;
                        }
                    }
                    $total[$i] = $any ? $sum : null;
                }
            }
            $primary = $total;
        } elseif (isset($fields['qty'])) {
            $series[] = ['field' => 'qty', 'label' => metric_label($name), 'values' => $col('qty'), 'role' => 'main'];
            $primary = $series[0]['values'];
        } elseif (isset($fields['avg'])) {
            $series[] = ['field' => 'avg', 'label' => t('dash.avg'), 'values' => $col('avg'), 'role' => 'main'];
            foreach (['min', 'max'] as $f) {
                if (isset($fields[$f])) {
                    $series[] = ['field' => $f, 'label' => t('dash.' . $f), 'values' => $col($f), 'role' => 'band'];
                }
            }
            $primary = $series[0]['values'];
        } else {
            foreach (array_slice($keys, 0, 4) as $f) {
                $series[] = ['field' => $f, 'label' => ucfirst($f), 'values' => $col($f), 'role' => 'main'];
            }
            $primary = $series[0]['values'];
        }

        $st = _series_stats($primary, $dayList);
        $latestText = null;
        if ($st['latest'] !== null) {
            $mains = array_values(array_filter($series, fn($s) => $s['role'] === 'main'));
            if (!$isSleep && count($mains) > 1 && !isset($fields['qty'])) {
                // π.χ. αρτηριακή πίεση: systolic/diastolic
                $parts = [];
                foreach ($mains as $s) {
                    $i = array_search($st['day'], $dayList, true);
                    $parts[] = isset($s['values'][$i]) ? metric_fmt((float)$s['values'][$i]) : '—';
                }
                $latestText = implode(' / ', $parts) . (in_array(strtolower($u), ['', 'count'], true) ? '' : ' ' . $u);
            } else {
                $latestText = metric_value_text($st['latest'], $u, $isSleep);
            }
        }

        $cards[] = [
            'metric' => $name, 'group' => $group, 'label' => metric_label($name), 'kind' => $kind,
            'units' => $u, 'series' => $series, 'order' => $order[$name] ?? 9999,
            'latest_text' => $latestText, 'latest_day' => $st['day'],
            'avg_text' => $st['avg'] !== null ? metric_value_text($st['avg'], $u, $isSleep) : null,
            'min_text' => $st['min'] !== null ? metric_value_text($st['min'], $u, $isSleep) : null,
            'max_text' => $st['max'] !== null ? metric_value_text($st['max'], $u, $isSleep) : null,
        ];
    }

    $groupOrder = array_flip(METRIC_GROUPS);
    usort($cards, function ($a, $b) use ($groupOrder) {
        return [$groupOrder[$a['group']], $a['order'], $a['label']] <=> [$groupOrder[$b['group']], $b['order'], $b['label']];
    });
    return $cards;
}
