<?php
declare(strict_types=1);

/**
 * Αναφορά βελτίωσης από LLM (Claude): στέλνει ΣΥΝΟΛΑ και ΜΕΣΟΥΣ ΟΡΟΥΣ των στατιστικών (όχι τα δείγματα ανά λεπτό,
 * ούτε το ECG) και αποθηκεύει την αναφορά στη βάση για να μη χρεώνεται η ίδια ημέρα ξανά.
 */

const AI_MODEL = 'claude-opus-5-5';
const AI_MAX_TOKENS = 6000;
const AI_DAILY_LIMIT = 10;        // ασφάλεια κόστους: μέγιστες αναφορές ανά ημέρα
const AI_PRICE_IN = 4.0;          // $ ανά 1M tokens εισόδου (εκτίμηση για την ένδειξη κόστους)
const AI_PRICE_OUT = 20.0;        // $ ανά 1M tokens εξόδου

/** Φορτώνει τη βιβλιοθήκη (composer). false αν δεν έχει εγκατασταθεί. */
function ai_sdk_loaded(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    return $loaded = class_exists('Anthropic\\Client');
}

function ai_get_key(): string
{
    return (string)(varos_get_settings()['anthropic_key'] ?? '');
}

function ai_set_key(?string $key): void
{
    varos_db()->prepare('UPDATE settings SET anthropic_key = :k WHERE id = 1')->execute([':k' => $key === '' ? null : $key]);
}

/** «sk-ant-…ab12» — ποτέ ολόκληρο το κλειδί. */
function ai_key_hint(): string
{
    $k = ai_get_key();
    return $k === '' ? '' : 'sk-ant-…' . substr($k, -4);
}

// ---------------------------------------------------------------- στατιστικά προς το μοντέλο

function _ai_round(float $v): float
{
    $a = abs($v);
    return round($v, $a >= 100 ? 0 : ($a >= 10 ? 1 : 2));
}

/** [τιμή πρώτου μισού, τιμή δεύτερου μισού] ως μέσοι όροι ημερών με δεδομένα. */
function _ai_halves(array $byDay): array
{
    ksort($byDay);
    $vals = array_values($byDay);
    $n = count($vals);
    if ($n < 4) {
        return [null, null];
    }
    $h = intdiv($n, 2);
    $first = array_slice($vals, 0, $h);
    $second = array_slice($vals, $n - $h);
    return [_ai_round(array_sum($first) / count($first)), _ai_round(array_sum($second) / count($second))];
}

function _ai_series_stats(array $byDay): array
{
    ksort($byDay);
    $vals = array_values($byDay);
    $lastDay = array_key_last($byDay);
    [$a, $b] = _ai_halves($byDay);
    return [
        'days_with_data' => count($vals),
        'avg' => _ai_round(array_sum($vals) / count($vals)),
        'min' => _ai_round(min($vals)),
        'max' => _ai_round(max($vals)),
        'last' => _ai_round((float)$byDay[$lastDay]),
        'last_day' => $lastDay,
        'first_half_avg' => $a,
        'second_half_avg' => $b,
    ];
}

function ai_collect_stats(int $days): array
{
    $today = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $settings = varos_get_settings();
    $entries = get_entries();
    $progress = compute_progress($settings, $entries);
    $height = $settings['height_cm'] !== null ? (float)$settings['height_cm'] : null;
    $current = $progress['current_weight'];

    $out = [
        'period' => ['from' => $from, 'to' => $today, 'days' => $days,
            'note' => 'Cumulative metrics (steps, energy, distance...) exclude today because the day is incomplete.'],
        'profile' => [
            'height_cm' => $height,
            'start_weight_kg' => $settings['start_weight'] !== null ? (float)$settings['start_weight'] : null,
            'goal_weight_kg' => $settings['goal_weight'] !== null ? (float)$settings['goal_weight'] : null,
            'current_weight_kg' => $current,
            'bmi' => ($current !== null && $height) ? round(calc_bmi((float)$current, $height), 1) : null,
        ],
    ];

    $w = [];
    foreach ($entries as $e) {
        if ($e['entry_date'] >= $from) {
            $w[] = ['d' => $e['entry_date'], 'kg' => (float)$e['weight']];
        }
    }
    $out['weight_entries'] = array_slice($w, -40);

    // --- μετρήσεις υγείας
    [$data, $units] = metrics_daily($days);
    $metrics = [];
    foreach ($data as $name => $fields) {
        [, $agg] = metric_info($name);
        $isSum = $agg === 'sum';
        $clean = function (array $byDay) use ($isSum, $today): array {
            if ($isSum) {
                unset($byDay[$today]);
            }
            return $byDay;
        };
        if ($name === 'sleep_analysis') {
            continue; // χειρισμός παρακάτω
        }
        $unit = str_replace('count/min', 'bpm', (string)($units[$name] ?? ''));
        $m = ['label' => metric_label($name), 'unit' => $unit, 'kind' => $isSum ? 'daily_total' : 'daily_average'];
        if (isset($fields['qty'])) {
            $s = $clean($fields['qty']);
            if (!$s) {
                continue;
            }
            $m += _ai_series_stats($s);
        } elseif (isset($fields['avg'])) {
            $s = $clean($fields['avg']);
            if (!$s) {
                continue;
            }
            $m += _ai_series_stats($s);
            if (!empty($fields['min'])) {
                $m['lowest_daily_min'] = _ai_round(min($fields['min']));
            }
            if (!empty($fields['max'])) {
                $m['highest_daily_max'] = _ai_round(max($fields['max']));
            }
        } else {
            $m['fields'] = [];
            foreach (array_slice(array_keys($fields), 0, 4) as $f) {
                $s = $clean($fields[$f]);
                if ($s) {
                    $m['fields'][$f] = _ai_series_stats($s);
                }
            }
            if (!$m['fields']) {
                continue;
            }
        }
        $metrics[$name] = $m;
    }
    $out['metrics'] = $metrics;

    // --- ύπνος (ώρες)
    if (!empty($data['sleep_analysis'])) {
        $f = $data['sleep_analysis'];
        $nights = [];
        $allDays = [];
        foreach ($f as $series) {
            $allDays += array_fill_keys(array_keys($series), true);
        }
        foreach (array_keys($allDays) as $d) {
            if (isset($f['totalsleep'][$d])) {
                $nights[$d] = $f['totalsleep'][$d];
            } elseif (isset($f['asleep'][$d]) && $f['asleep'][$d] > 0) {
                $nights[$d] = $f['asleep'][$d];
            } else {
                $s = ($f['deep'][$d] ?? 0) + ($f['core'][$d] ?? 0) + ($f['rem'][$d] ?? 0);
                if ($s > 0) {
                    $nights[$d] = $s;
                }
            }
        }
        if ($nights) {
            $sleep = ['unit' => 'hours'] + _ai_series_stats($nights);
            foreach (['deep', 'core', 'rem', 'awake'] as $stage) {
                if (!empty($f[$stage])) {
                    $sleep['avg_' . $stage] = _ai_round(array_sum($f[$stage]) / count($f[$stage]));
                }
            }
            $out['sleep'] = $sleep;
        }
    }

    // --- προπονήσεις
    $types = [];
    $count = 0;
    $minutes = 0.0;
    $min7 = 0.0;
    $since7 = date('Y-m-d', strtotime('-6 days'));
    foreach (get_workouts() as $wk) {
        if ($wk['workout_date'] < $from) {
            continue;
        }
        $count++;
        $minutes += (float)$wk['duration_min'];
        if ($wk['workout_date'] >= $since7) {
            $min7 += (float)$wk['duration_min'];
        }
        $label = workout_type_label($wk['type']);
        $types[$label] = ($types[$label] ?? 0) + 1;
    }
    $out['workouts'] = ['count' => $count, 'total_minutes' => round($minutes), 'minutes_last_7_days' => round($min7), 'by_type' => $types];

    // --- ECG και άλλες καταγραφές (μόνο πλήθη/ταξινομήσεις, όχι κυματομορφές)
    $records = [];
    foreach (get_health_records(60) as $kind => $recs) {
        $n = 0;
        $classes = [];
        foreach ($recs as $r) {
            if (substr($r['ts'], 0, 10) < $from) {
                continue;
            }
            $n++;
            foreach ($r['summary'] as $k => $v) {
                if (preg_match('/classification|rhythm|result/i', (string)$k)) {
                    $classes[(string)$v] = ($classes[(string)$v] ?? 0) + 1;
                }
            }
        }
        if ($n) {
            $records[$kind] = ['count' => $n] + ($classes ? ['classifications' => $classes] : []);
        }
    }
    if ($records) {
        $out['records'] = $records;
    }
    return $out;
}

// ---------------------------------------------------------------- κλήση στο Claude

function ai_system_prompt(string $lang): string
{
    $language = $lang === 'el' ? 'Greek' : 'English';
    return <<<TXT
You are a careful wellness coach analysing ONE person's own Apple Health statistics, exported from their personal tracking app.

Rules:
- Use ONLY the data provided. Never invent numbers. If a metric has few days of data, say the evidence is weak.
- Compare the person with their OWN baseline (first half vs second half of the period, latest vs average), not with other people. Use general population ranges only as loose context.
- This is general wellness information, not a diagnosis or medical advice. Do not name diseases as conclusions. If something is persistently outside typical ranges (resting heart rate, blood oxygen, blood pressure, an irregular ECG classification, breathing disturbances, very short sleep) say it is worth discussing with a doctor, without alarming the reader.
- Be specific and practical: for every improvement give a concrete action, how to do it, and how to tell it worked.
- Take the person's weight goal into account when it is provided.

Format (Markdown, roughly 450-650 words), with these sections in this order:
## Summary  (3-4 sentences)
## What is going well
## What to improve  (prioritised; for each: finding, why it matters, what to do)
## Plan for the next 7 days  (3-5 bullets)
## When to talk to a doctor  (only if something warrants it)
End with one short line about data gaps, if any.

Write the whole answer in {$language}, including the section headings.
TXT;
}

function ai_user_message(array $stats): string
{
    return "Here are my statistics as JSON. Please write my improvement report.\n\n"
        . json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

function ai_reports_today(): int
{
    $stmt = varos_db()->prepare("SELECT COUNT(*) FROM ai_reports WHERE substr(created_at, 1, 10) = :d");
    $stmt->execute([':d' => date('Y-m-d')]);
    return (int)$stmt->fetchColumn();
}

/** @return array{ok:bool,error?:string} */
function ai_generate_report(int $days, string $lang): array
{
    $key = ai_get_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'no_key'];
    }
    if (!ai_sdk_loaded()) {
        return ['ok' => false, 'error' => 'no_sdk'];
    }
    if (ai_reports_today() >= AI_DAILY_LIMIT) {
        return ['ok' => false, 'error' => 'limit'];
    }

    $stats = ai_collect_stats($days);
    try {
        $client = new \Anthropic\Client(apiKey: $key, requestOptions: ['timeout' => 200.0, 'maxRetries' => 1]);
        // Opus 5.5: η σκέψη είναι πάντα ενεργή (δεν στέλνουμε thinking)· το βάθος ελέγχεται με το effort.
        // fallbacks "default": αν το μοντέλο αρνηθεί (safeguards), ο server δοκιμάζει εναλλακτικό.
        $resp = $client->beta->messages->create(
            maxTokens: AI_MAX_TOKENS,
            model: AI_MODEL,
            system: ai_system_prompt($lang),
            messages: [['role' => 'user', 'content' => ai_user_message($stats)]],
            outputConfig: ['effort' => 'medium'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );
    } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
        $status = (int)($e->status ?? 0);
        $code = $status === 401 ? 'auth' : ($status === 429 ? 'rate' : ($status === 402 || $status === 403 ? 'billing' : 'api'));
        error_log('varos ai: API status ' . $status . ' ' . substr($e->getMessage(), 0, 300));
        return ['ok' => false, 'error' => $code];
    } catch (\Throwable $e) {
        error_log('varos ai: ' . get_class($e) . ' ' . substr($e->getMessage(), 0, 300));
        return ['ok' => false, 'error' => 'net'];
    }

    if ($resp->stopReason === 'refusal') {
        return ['ok' => false, 'error' => 'refusal'];
    }
    $text = '';
    foreach ($resp->content as $block) {
        if (($block->type ?? '') === 'text') {
            $text .= $block->text;
        }
    }
    $text = trim($text);
    if ($text === '') {
        return ['ok' => false, 'error' => 'empty'];
    }
    if ($resp->stopReason === 'max_tokens') {
        $text .= "\n\n_(…)_";
    }

    varos_db()->prepare('
        INSERT INTO ai_reports (created_at, days, lang, model, input_tokens, output_tokens, content)
        VALUES (:t, :d, :l, :m, :i, :o, :c)
    ')->execute([
        ':t' => date('Y-m-d H:i:s'), ':d' => $days, ':l' => $lang, ':m' => AI_MODEL,
        ':i' => (int)$resp->usage->inputTokens, ':o' => (int)$resp->usage->outputTokens, ':c' => $text,
    ]);
    return ['ok' => true];
}

function ai_latest_report(int $days): ?array
{
    $stmt = varos_db()->prepare('SELECT * FROM ai_reports WHERE days = :d ORDER BY id DESC LIMIT 1');
    $stmt->execute([':d' => $days]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function ai_cost_usd(array $report): float
{
    return ((int)$report['input_tokens'] * AI_PRICE_IN + (int)$report['output_tokens'] * AI_PRICE_OUT) / 1_000_000;
}

// ---------------------------------------------------------------- εμφάνιση (ασφαλές μίνι Markdown)

function _ai_inline(string $s): string
{
    $s = e($s);
    $s = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<em>$1</em>', $s);
    $s = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/u', '<em>$1</em>', $s);
    return (string)$s;
}

/** Μετατρέπει την αναφορά (headings, λίστες, έντονα) σε HTML. Όλο το κείμενο περνά από escape. */
function ai_render_markdown(string $md): string
{
    $html = '';
    $list = null; // 'ul' | 'ol'
    $para = [];
    $flushPara = function () use (&$html, &$para) {
        if ($para) {
            $html .= '<p>' . _ai_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };
    $closeList = function () use (&$html, &$list) {
        if ($list) {
            $html .= '</' . $list . '>';
            $list = null;
        }
    };
    foreach (preg_split('/\R/u', $md) as $line) {
        $t = trim($line);
        if ($t === '') {
            $flushPara();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.+)$/u', $t, $m)) {
            $flushPara();
            $closeList();
            $html .= '<h4 class="ai-h">' . _ai_inline(trim($m[2], " #\t")) . '</h4>';
        } elseif (preg_match('/^[-*•]\s+(.+)$/u', $t, $m)) {
            $flushPara();
            if ($list !== 'ul') {
                $closeList();
                $html .= '<ul>';
                $list = 'ul';
            }
            $html .= '<li>' . _ai_inline($m[1]) . '</li>';
        } elseif (preg_match('/^\d+[.)]\s+(.+)$/u', $t, $m)) {
            $flushPara();
            if ($list !== 'ol') {
                $closeList();
                $html .= '<ol>';
                $list = 'ol';
            }
            $html .= '<li>' . _ai_inline($m[1]) . '</li>';
        } elseif (preg_match('/^(-{3,}|\*{3,})$/', $t)) {
            $flushPara();
            $closeList();
        } else {
            $closeList();
            $para[] = $t;
        }
    }
    $flushPara();
    $closeList();
    return $html;
}
