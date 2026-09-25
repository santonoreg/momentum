<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();
$entries = get_entries();
$today = date('Y-m-d');

$metrics = [
    'weight' => t('reports.metric_weight'),
    'steps' => t('reports.metric_steps'),
    'both' => t('reports.metric_both'),
];
$metric = $_GET['metric'] ?? 'weight';
if (!array_key_exists($metric, $metrics)) {
    $metric = 'weight';
}

$ranges = ['7' => t('range.days', ['n' => 7]), '30' => t('range.days', ['n' => 30]), '90' => t('range.days', ['n' => 90]), '365' => t('range.days', ['n' => 365]), 'all' => t('range.all')];
$range = $_GET['range'] ?? '90';
if (!array_key_exists($range, $ranges)) {
    $range = '90';
}

// --- Δεδομένα βάρους στο διάστημα
if ($range === 'all') {
    $chartEntries = $entries;
    $rangeDays = 36500;
} else {
    $rangeDays = (int)$range;
    $cutoff = date('Y-m-d', strtotime('-' . $rangeDays . ' days'));
    $chartEntries = array_values(array_filter($entries, fn($e) => $e['entry_date'] >= $cutoff));
}
$weightByDay = [];
foreach ($chartEntries as $e) {
    $weightByDay[$e['entry_date']] = (float)$e['weight'];
}

// --- Δεδομένα βημάτων στο διάστημα
$stepsByDay = $metric !== 'weight' ? get_daily_steps($rangeDays) : [];

// --- Σειρές γραφήματος
$longRange = $range === 'all' || (int)$range > 120;
$fmtLabel = fn(string $d) => $longRange ? fmt_month_year($d) : date('j/n', strtotime($d));

if ($metric === 'weight') {
    $days = array_keys($weightByDay);
} elseif ($metric === 'steps') {
    $days = array_keys($stepsByDay);
} else {
    $days = array_unique(array_merge(array_keys($weightByDay), array_keys($stepsByDay)));
    sort($days);
}
$labels = array_map($fmtLabel, $days);
$weightSeries = array_map(fn($d) => $weightByDay[$d] ?? null, $days);
$stepSeries = array_map(fn($d) => isset($stepsByDay[$d]) ? (int)round($stepsByDay[$d]) : null, $days);

$minPoints = $metric === 'steps' ? 1 : 2;
$hasChart = count($days) >= $minPoints;
$emptyKey = $metric === 'steps' ? 'reports.need_steps' : 'reports.need_two';
$showPoints = count($days) <= 60;

// --- Στατιστικά βημάτων
$stepStats = null;
if ($stepsByDay) {
    $stepStats = [
        'today' => $stepsByDay[$today] ?? null,
        'avg' => array_sum($stepsByDay) / count($stepsByDay),
        'best' => max($stepsByDay),
        'total' => array_sum($stepsByDay),
    ];
}

// --- Μεταβολή βάρους (πάντα πάνω σε όλο το ιστορικό)
$settingsProgress = compute_progress($settings, $entries);
$direction = $settingsProgress['direction'];
$currentWeight = $settingsProgress['current_weight'];
$changes = [
    '7' => weight_change_over_days($entries, $currentWeight, 7),
    '30' => weight_change_over_days($entries, $currentWeight, 30),
    '90' => weight_change_over_days($entries, $currentWeight, 90),
];
$allTimeChange = ($currentWeight !== null && $entries) ? (float)$entries[0]['weight'] - $currentWeight : null;

// --- BMI
$latest = get_latest_entry();
$heightCm = $settings['height_cm'] !== null ? (float)$settings['height_cm'] : null;
$low10 = n_day_low($entries, 10);
$bmiRefWeight = $low10 ?? ($latest ? (float)$latest['weight'] : null);
$bmi = ($bmiRefWeight !== null && $heightCm) ? calc_bmi($bmiRefWeight, $heightCm) : null;
$bmiCat = $bmi !== null ? bmi_category_for($bmi) : null;

$qs = fn(array $over) => '?' . http_build_query(array_merge(['metric' => $metric, 'range' => $range], $over));

$pageTitle = t('reports.title');
$activeTab = 'reports';
$needsChart = true;
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<div class="section-title"><?= te('reports.chart_title') ?></div>
<div class="range-switch">
  <?php foreach ($metrics as $key => $label): ?>
    <a href="<?= e($qs(['metric' => (string)$key])) ?>" class="<?= $metric === (string)$key ? 'is-active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="range-switch">
  <?php foreach ($ranges as $key => $label): ?>
    <a href="<?= e($qs(['range' => (string)$key])) ?>" class="<?= $range === (string)$key ? 'is-active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (!$hasChart): ?>
    <div class="empty-state" style="padding:26px 10px;">
      <p style="margin:0;"><?= te($emptyKey) ?></p>
    </div>
  <?php else: ?>
    <div class="chart-holder" style="height:230px;"><canvas id="reportChart"></canvas></div>
  <?php endif; ?>
</div>

<?php if ($metric !== 'weight' && $stepStats): ?>
  <div class="section-title"><?= te('reports.steps_title') ?></div>
  <div class="chips">
    <div class="chip">
      <div class="chip-value"><?= $stepStats['today'] !== null ? fmt_num($stepStats['today'], 0) : '—' ?></div>
      <div class="chip-label"><?= te('reports.steps_today') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($stepStats['avg'], 0) ?></div>
      <div class="chip-label"><?= te('reports.steps_avg') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($stepStats['best'], 0) ?></div>
      <div class="chip-label"><?= te('reports.steps_best') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($stepStats['total'], 0) ?></div>
      <div class="chip-label"><?= te('reports.steps_total') ?></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($metric !== 'steps' && $entries): ?>
  <div class="section-title"><?= te('section.change') ?></div>
  <div class="chips">
    <?php foreach (['7', '30', '90'] as $d): ?>
    <div class="chip">
      <div class="chip-value <?= chip_class($changes[$d], $direction) ?>"><?= fmt_signed($changes[$d]) ?></div>
      <div class="chip-label"><?= te('chip.days', ['n' => $d]) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="chip">
      <div class="chip-value <?= chip_class($allTimeChange, $direction) ?>"><?= fmt_signed($allTimeChange) ?></div>
      <div class="chip-label"><?= te('chip.total') ?></div>
    </div>
  </div>
<?php endif; ?>

<div class="section-title"><?= te('reports.bmi_title') ?></div>
<div class="bmi-card">
  <?php if ($bmi === null): ?>
    <div class="empty-state" style="padding:16px 4px 22px;">
      <p style="margin:0;"><?= t_html('reports.bmi_need_height', ['link' => '<a href="settings.php" style="color:var(--primary);font-weight:600;">' . te('reports.bmi_height_link') . '</a>']) ?></p>
    </div>
  <?php else: ?>
    <div class="gauge-wrap"><?= bmi_gauge_svg($bmi) ?></div>
    <div class="gauge-value">
      <div class="gauge-number"><?= fmt_num($bmi) ?></div>
      <div class="gauge-cat" style="color:<?= $bmiCat['color'] ?>"><?= e($bmiCat['label']) ?></div>
    </div>
    <div class="bmi-meta">
      <div>
        <div class="l"><?= te('bmi.low10') ?></div>
        <div class="v"><?= fmt_num($low10) ?> <?= te('unit.kg_short') ?></div>
      </div>
      <div>
        <div class="l"><?= te('bmi.height') ?></div>
        <div class="v"><?= fmt_num($heightCm, 0) ?> <?= te('unit.cm_short') ?></div>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php if ($heightCm): ?>
<div class="card" style="margin-top:12px;">
  <div class="card-head"><h3><?= te('reports.cats_title') ?></h3></div>
  <table class="cat-table">
    <?php foreach (bmi_categories() as $cat):
      $h = $heightCm / 100;
      $minW = $cat['min'] > 0 ? $cat['min'] * $h * $h : null;
      $maxW = $cat['max'] < 900 ? $cat['max'] * $h * $h : null;
      $range_text = $minW === null ? t('reports.range_upto', ['x' => fmt_num($maxW)])
                  : ($maxW === null ? t('reports.range_from', ['x' => fmt_num($minW)])
                  : t('reports.range_between', ['a' => fmt_num($minW), 'b' => fmt_num($maxW)]));
    ?>
    <tr>
      <td><span class="cat-dot" style="background:<?= $cat['color'] ?>"></span><?= e($cat['label']) ?></td>
      <td><?= e($range_text) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php $modalRedirect = 'reports.php'; require __DIR__ . '/includes/entry_modal.php'; ?>

<?php if ($hasChart): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  var weights = <?= json_encode($weightSeries) ?>;
  var steps = <?= json_encode($stepSeries) ?>;
  var metric = <?= json_encode($metric) ?>;
  if (metric === 'weight') {
    varosLineChart('reportChart', labels, weights, { showAxes: true, points: <?= $showPoints ? 'true' : 'false' ?> });
  } else if (metric === 'steps') {
    varosBarChart('reportChart', labels, steps, { color: 'gold' });
  } else {
    varosComboChart('reportChart', labels, weights, steps, {
      weightLabel: <?= json_encode(t('reports.metric_weight'), JSON_UNESCAPED_UNICODE) ?>,
      stepsLabel: <?= json_encode(t('reports.metric_steps'), JSON_UNESCAPED_UNICODE) ?>,
      points: <?= $showPoints ? 'true' : 'false' ?>
    });
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
