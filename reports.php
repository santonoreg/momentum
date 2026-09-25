<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();
$entries = get_entries();

$ranges = ['7' => t('range.days', ['n' => 7]), '30' => t('range.days', ['n' => 30]), '90' => t('range.days', ['n' => 90]), '365' => t('range.days', ['n' => 365]), 'all' => t('range.all')];
$range = $_GET['range'] ?? '90';
if (!array_key_exists($range, $ranges)) {
    $range = '90';
}

if ($range === 'all') {
    $chartEntries = $entries;
} else {
    $cutoff = date('Y-m-d', strtotime('-' . (int)$range . ' days'));
    $chartEntries = array_values(array_filter($entries, fn($e) => $e['entry_date'] >= $cutoff));
}

$longRange = $range === 'all' || (int)$range > 120;
$labels = array_map(function ($e) use ($longRange) {
    return $longRange ? fmt_month_year($e['entry_date']) : date('j/n', strtotime($e['entry_date']));
}, $chartEntries);
$values = array_map(fn($e) => (float)$e['weight'], $chartEntries);

$latest = get_latest_entry();
$currentWeight = $latest ? (float)$latest['weight'] : null;
$heightCm = $settings['height_cm'] !== null ? (float)$settings['height_cm'] : null;
$low10 = n_day_low($entries, 10);
$bmiRefWeight = $low10 ?? $currentWeight;
$bmi = ($bmiRefWeight !== null && $heightCm) ? calc_bmi($bmiRefWeight, $heightCm) : null;
$bmiCat = $bmi !== null ? bmi_category_for($bmi) : null;

$pageTitle = t('reports.title');
$activeTab = 'reports';
$needsChart = true;
require __DIR__ . '/includes/header.php';
?>

<div class="section-title"><?= te('reports.chart_title') ?></div>
<div class="range-switch">
  <?php foreach ($ranges as $key => $label): ?>
    <a href="?range=<?= e((string)$key) ?>" class="<?= $range === (string)$key ? 'is-active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <?php if (count($chartEntries) < 2): ?>
    <div class="empty-state" style="padding:26px 10px;">
      <p style="margin:0;"><?= te('reports.need_two') ?></p>
    </div>
  <?php else: ?>
    <div class="chart-holder" style="height:210px;"><canvas id="reportChart"></canvas></div>
  <?php endif; ?>
</div>

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

<?php if (count($chartEntries) >= 2): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  varosLineChart('reportChart', <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($values) ?>, { showAxes: true, points: <?= count($chartEntries) <= 60 ? 'true' : 'false' ?> });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
