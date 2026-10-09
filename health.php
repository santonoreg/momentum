<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();

$rangeOptions = [7, 30, 90, 365];
$range = (int)($_GET['range'] ?? 30);
if (!in_array($range, $rangeOptions, true)) {
    $range = 30;
}
$dayList = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $dayList[] = date('Y-m-d', strtotime("-{$i} days"));
}
$labels = array_map(fn($d) => date('j/n', strtotime($d)), $dayList);

[$metricData, $metricUnits] = metrics_daily($range);
$cards = build_metric_cards($metricData, $metricUnits, $dayList);

// Βασικές μετρικές στην κορυφή (μόνο όσες υπάρχουν)
$highlightNames = ['step_count', 'active_energy', 'apple_exercise_time', 'resting_heart_rate', 'heart_rate_variability', 'sleep_analysis', 'vo2_max', 'weight_body_mass'];
$highlights = [];
foreach ($highlightNames as $hn) {
    foreach ($cards as $c) {
        if ($c['metric'] === $hn && $c['latest_text'] !== null) {
            $highlights[] = $c;
        }
    }
}

$byGroup = [];
foreach ($cards as $i => $c) {
    $cards[$i]['id'] = 'mc-' . $i;
    $byGroup[$c['group']][] = $cards[$i];
}

$recordGroups = get_health_records(15);
$recordCfg = [];

// Προπονήσεις
$workouts = get_workouts();
$week = workouts_summary($workouts, 7);
[$weekLabels, $weekValues] = weekly_minutes($workouts, 12);
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$listLimit = 30;
$shown = array_slice($workouts, 0, $listLimit);

$groupColor = ['activity' => 'primary', 'heart' => 'rose', 'sleep' => 'steel', 'body' => 'gold', 'mobility' => 'primary', 'environment' => 'steel', 'other' => 'steel'];
$chartCfg = [];
foreach ($cards as $c) {
    $chartCfg[] = ['id' => $c['id'], 'kind' => $c['kind'], 'color' => $groupColor[$c['group']] ?? 'primary', 'series' => $c['series']];
}

$qs = fn(int $r) => '?range=' . $r;
$lastSync = !empty($settings['last_sync_at'])
    ? fmt_date(substr($settings['last_sync_at'], 0, 10)) . ' ' . substr($settings['last_sync_at'], 11, 5)
    : null;

$pageTitle = t('dash.title');
$activeTab = 'health';
$needsChart = true;
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<?php if (!$cards && !$workouts && !$recordGroups): ?>
  <div class="card empty-state">
    <div class="big"><?= te('dash.empty_title') ?></div>
    <p><?= te('dash.empty_text') ?></p>
    <a class="btn" href="settings.php#health"><?= te('exercise.go_setup') ?></a>
  </div>
<?php else: ?>

<div class="range-switch">
  <?php foreach ($rangeOptions as $r): ?>
    <a href="<?= e($qs($r)) ?>" class="<?= $range === $r ? 'is-active' : '' ?>"><?= te('range.days', ['n' => $r]) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($highlights): ?>
  <div class="section-title"><?= te('dash.highlights') ?></div>
  <div class="chips">
    <?php foreach ($highlights as $h): ?>
    <div class="chip">
      <div class="chip-value"><?= e($h['latest_text']) ?></div>
      <div class="chip-label"><?= e($h['label']) ?></div>
      <?php if ($h['latest_day']): ?>
      <div class="chip-sub"><?= e($h['latest_day'] === $today ? t('days.today') : ($h['latest_day'] === $yesterday ? t('days.yesterday') : fmt_date($h['latest_day']))) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php foreach (METRIC_GROUPS as $g): if (empty($byGroup[$g])) continue; ?>
  <div class="section-title"><?= e(metric_group_label($g)) ?></div>
  <div class="metric-grid">
    <?php foreach ($byGroup[$g] as $c): ?>
    <div class="metric-card">
      <div class="mc-head">
        <div class="mc-title"><?= e($c['label']) ?></div>
        <div class="mc-latest"><?= $c['latest_text'] !== null ? e($c['latest_text']) : '—' ?></div>
      </div>
      <div class="mc-sub">
        <?php if ($c['avg_text'] !== null): ?>
          <?= te('dash.avg') ?> <b><?= e($c['avg_text']) ?></b> · <?= te('dash.min') ?>–<?= te('dash.max') ?> <b><?= e($c['min_text']) ?>–<?= e($c['max_text']) ?></b>
          <?php if ($c['latest_day'] && $c['latest_day'] !== $today): ?> · <?= e(fmt_date($c['latest_day'])) ?><?php endif; ?>
        <?php else: ?>&nbsp;<?php endif; ?>
      </div>
      <div class="mc-chart"><canvas id="<?= e($c['id']) ?>"></canvas></div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<?php if ($lastSync): ?>
  <p class="hint"><?= te('exercise.last_sync', ['when' => $lastSync]) ?></p>
<?php endif; ?>

<?php foreach ($recordGroups as $kind => $recs): ?>
  <div class="section-title"><?= e(record_kind_label($kind)) ?></div>
  <div class="log-list">
    <?php foreach ($recs as $ri => $rec):
      $rid = 'rec-' . md5($kind . $ri);
      $d = substr($rec['ts'], 0, 10);
      $dl = $d === $today ? t('days.today') : ($d === $yesterday ? t('days.yesterday') : fmt_date($d));
      $items = record_summary_items($rec['summary']);
      if ($rec['series']) { $recordCfg[] = ['id' => $rid, 'values' => $rec['series']]; }
    ?>
    <details class="rec-row"<?= $rec['series'] ? ' data-wave="' . e($rid) . '"' : '' ?>>
      <summary>
        <div>
          <div class="log-weight"><?= e($items ? $items[0][1] : record_kind_label($kind)) ?></div>
          <div class="log-date"><?= e($dl) ?> · <?= e(substr($rec['ts'], 11, 5)) ?></div>
        </div>
        <div class="wk-sub"><?= e(implode(' · ', array_map(fn($i) => $i[0] . ': ' . $i[1], array_slice($items, 1)))) ?></div>
      </summary>
      <?php if ($rec['series']): ?>
        <div class="rec-chart"><canvas id="<?= e($rid) ?>"></canvas></div>
        <div class="hint"><?= te('rec.samples', ['n' => count($rec['series'])]) ?></div>
      <?php endif; ?>
    </details>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>

<div class="section-title"><?= te('dash.workouts_title') ?></div>
<?php if (!$workouts): ?>
  <div class="card empty-state" style="padding:22px 16px;">
    <p style="margin:0;"><?= te('dash.no_workouts') ?></p>
  </div>
<?php else: ?>
  <div class="chips">
    <div class="chip">
      <div class="chip-value"><?= (int)$week['count'] ?></div>
      <div class="chip-label"><?= te('exercise.sessions') ?> · <?= te('exercise.last7') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= e(fmt_duration($week['minutes'])) ?></div>
      <div class="chip-label"><?= te('exercise.time') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($week['calories'], 0) ?></div>
      <div class="chip-label"><?= te('exercise.calories') ?> (<?= te('unit.kcal') ?>)</div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($week['distance']) ?></div>
      <div class="chip-label"><?= te('exercise.distance') ?> (<?= te('unit.km') ?>)</div>
    </div>
  </div>

  <div class="card" style="margin-top:12px;">
    <div class="card-head"><h3><?= te('exercise.weekly_title') ?></h3></div>
    <div class="chart-holder"><canvas id="weeklyChart"></canvas></div>
  </div>

  <div class="section-title"><?= te('exercise.recent_title') ?></div>
  <div class="log-list">
    <?php foreach ($shown as $w):
      $dateLabel = $w['workout_date'] === $today ? t('days.today') : ($w['workout_date'] === $yesterday ? t('days.yesterday') : fmt_date($w['workout_date']));
      $time = $w['start_time'] ? substr($w['start_time'], 0, 5) : null;
      $metrics = [];
      if ($w['distance_km'] !== null && (float)$w['distance_km'] > 0) {
          $metrics[] = fmt_num((float)$w['distance_km']) . ' ' . t('unit.km');
      }
      if ($w['calories'] !== null && (float)$w['calories'] > 0) {
          $metrics[] = fmt_num((float)$w['calories'], 0) . ' ' . t('unit.kcal');
      }
      if ($w['avg_hr'] !== null && (float)$w['avg_hr'] > 0) {
          $metrics[] = t('exercise.avg_hr', ['n' => fmt_num((float)$w['avg_hr'], 0)]);
      }
    ?>
    <div class="log-row">
      <div>
        <div class="log-weight"><?= e(workout_type_label($w['type'])) ?></div>
        <div class="log-date"><?= e($dateLabel) ?><?= $time ? ' · ' . e($time) : '' ?></div>
      </div>
      <div class="wk-metrics">
        <div class="wk-duration"><?= e(fmt_duration((float)$w['duration_min'])) ?></div>
        <?php if ($metrics): ?><div class="wk-sub"><?= e(implode(' · ', $metrics)) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($workouts) > $listLimit): ?>
    <p class="hint"><?= te('exercise.showing', ['n' => $listLimit]) ?></p>
  <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  var cfgs = <?= json_encode($chartCfg, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
  cfgs.forEach(function (c) { varosMetricChart(c.id, labels, c); });
  var waves = <?= json_encode($recordCfg, JSON_PARTIAL_OUTPUT_ON_ERROR) ?>;
  waves.forEach(function (w) { varosWaveOnOpen(w.id, w.values); });
<?php if ($workouts): ?>
  varosBarChart('weeklyChart', <?= json_encode($weekLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($weekValues) ?>);
<?php endif; ?>
});
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
