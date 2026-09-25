<?php
declare(strict_types=1);

// Σε servers με περιορισμούς ασφαλείας το PCRE JIT αποτυγχάνει και βγάζει warning μέσα στην απάντηση.
@ini_set('pcre.jit', '0');

// Ζώνη ώρας της εφαρμογής (μπορεί να αλλάξει με τη μεταβλητή περιβάλλοντος VAROS_TZ).
date_default_timezone_set(getenv('VAROS_TZ') ?: 'Europe/Athens');

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/workouts.php';

/** Μορφοποίηση βάρους σε κιλά, 1 δεκαδικό. */
function fmt_kg(?float $w): string
{
    if ($w === null) {
        return '—';
    }
    return fmt_num($w) . ' ' . t('unit.kg');
}

function fmt_num(?float $w, int $dec = 1): string
{
    if ($w === null) {
        return '—';
    }
    [$decSep, $thouSep] = num_separators();
    return number_format($w, $dec, $decSep, $thouSep);
}

function fmt_signed(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    $sign = $v > 0.0001 ? '−' : ($v < -0.0001 ? '+' : '');
    return $sign . fmt_num(abs($v));
}

function fmt_date(string $isoDate): string
{
    $ts = strtotime($isoDate);
    if (!$ts) {
        return $isoDate;
    }
    return (int)date('j', $ts) . ' ' . t('month.' . (int)date('n', $ts)) . ' ' . date('Y', $ts);
}

/** «Σεπ '26» — για τις ετικέτες μακροχρόνιων γραφημάτων. */
function fmt_month_year(string $isoDate): string
{
    $ts = strtotime($isoDate);
    return t('month.' . (int)date('n', $ts)) . " '" . date('y', $ts);
}

/** Διάρκεια σε λεπτά -> «1ώ 05λ». */
function fmt_duration(float $minutes): string
{
    $total = (int)round($minutes);
    $h = intdiv($total, 60);
    $m = $total % 60;
    if ($h === 0) {
        return $m . t('unit.min_short');
    }
    return $h . t('unit.h_short') . ' ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . t('unit.min_short');
}

/** Μήνυμα flash (saved / deleted / token / error) από την παράμετρο ?flash=. */
function render_flash(): string
{
    $flash = $_GET['flash'] ?? null;
    if ($flash === null) {
        return '';
    }
    $known = ['saved', 'deleted', 'token', 'error', 'badcode'];
    $key = in_array($flash, $known, true) ? $flash : 'saved';
    return '<div class="flash ' . (in_array($key, ['error', 'badcode'], true) ? 'error' : '') . '">' . te('flash.' . $key) . '</div>';
}

/** Όλες οι καταχωρήσεις, ταξινομημένες κατά ημερομηνία (αύξουσα). */
function get_entries(): array
{
    return varos_db()->query('SELECT * FROM entries ORDER BY entry_date ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function get_latest_entry(): ?array
{
    $row = varos_db()->query('SELECT * FROM entries ORDER BY entry_date DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function get_first_entry(): ?array
{
    $row = varos_db()->query('SELECT * FROM entries ORDER BY entry_date ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Η πιο πρόσφατη καταχώρηση με ημερομηνία <= $isoDate. */
function get_entry_on_or_before(array $entries, string $isoDate): ?array
{
    $best = null;
    foreach ($entries as $e) {
        if ($e['entry_date'] <= $isoDate) {
            $best = $e;
        } else {
            break;
        }
    }
    return $best;
}

function upsert_entry(string $date, float $weight, ?string $note): void
{
    $stmt = varos_db()->prepare('
        INSERT INTO entries (entry_date, weight, note) VALUES (:d, :w, :n)
        ON CONFLICT(entry_date) DO UPDATE SET weight = excluded.weight, note = excluded.note
    ');
    $stmt->execute([':d' => $date, ':w' => $weight, ':n' => $note]);
}

function delete_entry(int $id): void
{
    $stmt = varos_db()->prepare('DELETE FROM entries WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

/**
 * Στατιστικά προόδου προς τον στόχο: τρέχον βάρος, ποσοστό, ορόσημα.
 * Λειτουργεί είτε ο στόχος είναι απώλεια είτε πρόσληψη βάρους.
 */
function compute_progress(array $settings, array $entries): array
{
    $startWeight = $settings['start_weight'] !== null ? (float)$settings['start_weight'] : null;
    $goalWeight = $settings['goal_weight'] !== null ? (float)$settings['goal_weight'] : null;
    $milestoneCount = max(1, (int)($settings['milestone_count'] ?? 8));

    $latest = end($entries) ?: null;
    $currentWeight = $latest ? (float)$latest['weight'] : $startWeight;

    if ($startWeight === null && $entries) {
        $startWeight = (float)$entries[0]['weight'];
    }

    $result = [
        'current_weight' => $currentWeight,
        'start_weight' => $startWeight,
        'goal_weight' => $goalWeight,
        'has_goal' => $startWeight !== null && $goalWeight !== null && $currentWeight !== null,
        'percent_complete' => 0.0,
        'to_go' => null,
        'total_change' => null,
        'milestone_count' => $milestoneCount,
        'completed_milestones' => 0,
        'current_milestone' => 1,
        'current_milestone_target' => null,
        'to_go_next_milestone' => null,
        'direction' => 0, // -1 απώλεια, +1 πρόσληψη, 0 σταθερό
    ];

    if (!$result['has_goal']) {
        return $result;
    }

    $totalChange = $goalWeight - $startWeight; // αρνητικό = θέλει απώλεια
    $result['total_change'] = $totalChange;
    $result['direction'] = $totalChange <=> 0;
    $result['to_go'] = abs($goalWeight - $currentWeight);

    if (abs($totalChange) < 0.0001) {
        $result['percent_complete'] = 100.0;
        return $result;
    }

    $progressAmount = $currentWeight - $startWeight;
    $percent = ($progressAmount / $totalChange) * 100;
    $result['percent_complete'] = max(0.0, min(100.0, $percent));

    // Στόχοι ανά ορόσημο
    $step = $totalChange / $milestoneCount;
    $completed = 0;
    $currentTarget = $goalWeight;
    for ($i = 1; $i <= $milestoneCount; $i++) {
        $target = $startWeight + $step * $i;
        $reached = $totalChange < 0
            ? $currentWeight <= $target + 0.0001
            : $currentWeight >= $target - 0.0001;
        if ($reached) {
            $completed = $i;
        } else {
            $currentTarget = $target;
            break;
        }
        $currentTarget = $target;
    }
    $result['completed_milestones'] = $completed;
    $result['current_milestone'] = min($completed + 1, $milestoneCount);
    $result['current_milestone_target'] = $currentTarget;
    $result['to_go_next_milestone'] = abs($currentTarget - $currentWeight);

    return $result;
}

/** Πόσο χάθηκε/κερδήθηκε τις τελευταίες N ημέρες (θετικό = απώλεια). */
function weight_change_over_days(array $entries, ?float $currentWeight, int $days): ?float
{
    if ($currentWeight === null || !$entries) {
        return null;
    }
    $targetDate = date('Y-m-d', strtotime("-{$days} days"));
    $ref = get_entry_on_or_before($entries, $targetDate);
    if (!$ref) {
        return null;
    }
    return (float)$ref['weight'] - $currentWeight;
}

/** Απλός κινητός μέσος όρος (τάση) πάνω σε λίστα καταχωρήσεων. */
function moving_average(array $entries, int $window = 7): array
{
    $out = [];
    $vals = [];
    foreach ($entries as $e) {
        $vals[] = (float)$e['weight'];
        if (count($vals) > $window) {
            array_shift($vals);
        }
        $out[] = array_sum($vals) / count($vals);
    }
    return $out;
}

/** BMI = κιλά / (μέτρα)^2 */
function calc_bmi(float $weightKg, float $heightCm): float
{
    $h = $heightCm / 100;
    if ($h <= 0) {
        return 0.0;
    }
    return $weightKg / ($h * $h);
}

function bmi_categories(): array
{
    // [label, από (αποκλειστικά), έως (συμπεριλαμβανομένου), χρώμα]
    return [
        ['label' => t('bmi.under'), 'min' => 0, 'max' => 18.5, 'color' => 'var(--cat-under)'],
        ['label' => t('bmi.normal'), 'min' => 18.5, 'max' => 25, 'color' => 'var(--cat-normal)'],
        ['label' => t('bmi.over'), 'min' => 25, 'max' => 30, 'color' => 'var(--cat-over)'],
        ['label' => t('bmi.obese1'), 'min' => 30, 'max' => 35, 'color' => 'var(--cat-obese1)'],
        ['label' => t('bmi.obese2'), 'min' => 35, 'max' => 40, 'color' => 'var(--cat-obese2)'],
        ['label' => t('bmi.obese3'), 'min' => 40, 'max' => 999, 'color' => 'var(--cat-obese3)'],
    ];
}

function bmi_category_for(float $bmi): array
{
    foreach (bmi_categories() as $cat) {
        if ($bmi < $cat['max']) {
            return $cat;
        }
    }
    $cats = bmi_categories();
    return end($cats);
}

/**
 * Υψηλό / τελευταίο / τάση / χαμηλό μέσα σε παράθυρο N ημερών.
 * Επιστρέφει null αν δεν υπάρχουν καταχωρήσεις στο παράθυρο.
 */
function recent_stats_window(array $entries, int $days): ?array
{
    if (!$entries) {
        return null;
    }
    $cutoff = date('Y-m-d', strtotime("-{$days} days"));
    $windowEntries = array_values(array_filter($entries, fn($e) => $e['entry_date'] >= $cutoff));
    if (!$windowEntries) {
        $windowEntries = [end($entries)];
    }

    $high = $windowEntries[0];
    $low = $windowEntries[0];
    foreach ($windowEntries as $e) {
        if ((float)$e['weight'] > (float)$high['weight']) $high = $e;
        if ((float)$e['weight'] < (float)$low['weight']) $low = $e;
    }
    $latest = end($entries);

    $avgWindow = array_slice($entries, -7);
    $trend = array_sum(array_map(fn($e) => (float)$e['weight'], $avgWindow)) / count($avgWindow);

    $daysAgo = fn(string $d) => (int)round((strtotime('today') - strtotime($d)) / 86400);

    return [
        'high' => ['value' => (float)$high['weight'], 'days_ago' => $daysAgo($high['entry_date'])],
        'low' => ['value' => (float)$low['weight'], 'days_ago' => $daysAgo($low['entry_date'])],
        'latest' => ['value' => (float)$latest['weight'], 'days_ago' => $daysAgo($latest['entry_date'])],
        'trend' => $trend,
    ];
}

function days_ago_label(int $n): string
{
    if ($n <= 0) return t('days.today');
    if ($n === 1) return t('days.yesterday');
    return t('days.ago', ['n' => $n]);
}

/**
 * Σημείο πάνω σε ημικύκλιο (t=0 αριστερά, t=1 δεξιά, περνώντας από την κορυφή).
 * @return array{0:float,1:float}
 */
function _gauge_point(float $cx, float $cy, float $r, float $t): array
{
    $phi = M_PI * (1 - $t);
    return [$cx + $r * cos($phi), $cy - $r * sin($phi)];
}

function _gauge_arc_path(float $cx, float $cy, float $r, float $t1, float $t2): string
{
    [$x1, $y1] = _gauge_point($cx, $cy, $r, $t1);
    [$x2, $y2] = _gauge_point($cx, $cy, $r, $t2);
    $large = (($t2 - $t1) * 180) > 180 ? 1 : 0;
    return sprintf('M %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f', $x1, $y1, $r, $r, $large, $x2, $y2);
}

/** Ημικυκλικός δείκτης BMI με έγχρωμες ζώνες κατηγοριών + δείκτη θέσης. */
function bmi_gauge_svg(?float $bmi, float $rangeMin = 15, float $rangeMax = 42): string
{
    $cx = 110; $cy = 100; $r = 88; $sw = 16;
    $toT = fn(float $v) => max(0, min(1, ($v - $rangeMin) / ($rangeMax - $rangeMin)));

    $svg = '<svg viewBox="0 0 220 118" class="gauge-svg" xmlns="http://www.w3.org/2000/svg">';
    foreach (bmi_categories() as $cat) {
        $t1 = $toT((float)$cat['min']);
        $t2 = $toT(min((float)$cat['max'], $rangeMax));
        if ($t2 <= $t1) continue;
        $gap = 0.006;
        $svg .= '<path d="' . _gauge_arc_path($cx, $cy, $r, $t1 + $gap, $t2 - $gap) . '" stroke="' . $cat['color'] . '" stroke-width="' . $sw . '" stroke-linecap="round" fill="none"/>';
    }
    if ($bmi !== null) {
        $t = $toT($bmi);
        [$mx, $my] = _gauge_point($cx, $cy, $r, $t);
        $svg .= '<circle cx="' . round($mx, 2) . '" cy="' . round($my, 2) . '" r="8" fill="var(--surface)" stroke="var(--ink)" stroke-width="3"/>';
    }
    $svg .= '</svg>';
    return $svg;
}

/** Χαμηλότερη τιμή βάρους στις τελευταίες N ημέρες (σταθεροποιημένη αναφορά). */
function n_day_low(array $entries, int $days = 10): ?float
{
    if (!$entries) {
        return null;
    }
    $cutoff = date('Y-m-d', strtotime("-{$days} days"));
    $low = null;
    foreach ($entries as $e) {
        if ($e['entry_date'] >= $cutoff) {
            $w = (float)$e['weight'];
            if ($low === null || $w < $low) {
                $low = $w;
            }
        }
    }
    return $low ?? (float)end($entries)['weight'];
}

/** Κλάση χρώματος για μεταβολή βάρους: πράσινο όταν πάει προς τον στόχο, κόκκινο όταν απομακρύνεται. */
function chip_class(?float $v, int $direction): string
{
    if ($v === null || $direction === 0) return '';
    $favorable = $direction < 0 ? $v > 0.0001 : $v < -0.0001;
    if (abs($v) < 0.0001) return '';
    return $favorable ? 'pos' : 'neg';
}
