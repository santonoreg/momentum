<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();
$workouts = get_workouts();
$week = workouts_summary($workouts, 7);
[$weekLabels, $weekValues] = weekly_minutes($workouts, 12);
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$steps = get_daily_steps(30);
$hasSteps = count($steps) > 0;
$stepDays = [];
for ($i = 13; $i >= 0; $i--) {
    $stepDays[date('Y-m-d', strtotime("-{$i} days"))] = 0;
}
foreach ($steps as $d => $v) {
    if (isset($stepDays[$d])) {
        $stepDays[$d] = (int)round($v);
    }
}
$stepLabels = array_map(fn($d) => date('j/n', strtotime($d)), array_keys($stepDays));
$stepValues = array_values($stepDays);
$activeStepDays = array_filter(array_slice($stepDays, -7, 7, true), fn($v) => $v > 0);
$stepAvg7 = $activeStepDays ? array_sum($activeStepDays) / count($activeStepDays) : null;
$stepToday = $steps[$today] ?? null;
$stepBest = $steps ? max($steps) : null;
$listLimit = 60;
$shown = array_slice($workouts, 0, $listLimit);

$pageTitle = t('exercise.title');
$activeTab = 'exercise';
$needsChart = true;
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<?php if ($hasSteps): ?>
  <div class="section-title"><?= te('exercise.steps_title') ?></div>
  <div class="chips">
    <div class="chip">
      <div class="chip-value"><?= $stepToday !== null ? fmt_num($stepToday, 0) : '—' ?></div>
      <div class="chip-label"><?= te('exercise.steps_today') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= $stepAvg7 !== null ? fmt_num($stepAvg7, 0) : '—' ?></div>
      <div class="chip-label"><?= te('exercise.steps_avg7') ?></div>
    </div>
    <div class="chip">
      <div class="chip-value"><?= fmt_num($stepBest, 0) ?></div>
      <div class="chip-label"><?= te('exercise.steps_best') ?></div>
    </div>
  </div>
  <div class="card" style="margin-top:12px;">
    <div class="chart-holder"><canvas id="stepsChart"></canvas></div>
  </div>
<?php endif; ?>

<?php if (!$workouts && !$hasSteps): ?>
  <div class="card empty-state">
    <div class="big"><?= te('exercise.empty_title') ?></div>
    <p><?= te('exercise.empty_text') ?></p>
    <a class="btn" href="settings.php#health"><?= te('exercise.go_setup') ?></a>
  </div>
<?php elseif ($workouts): ?>
  <div class="section-title"><?= te('exercise.last7') ?></div>
  <div class="chips">
    <div class="chip">
      <div class="chip-value"><?= (int)$week['count'] ?></div>
      <div class="chip-label"><?= te('exercise.sessions') ?></div>
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

  <div class="section-title"><?= te('exercise.weekly_title') ?></div>
  <div class="card">
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
  <?php if (!empty($settings['last_sync_at'])): ?>
    <p class="hint"><?= te('exercise.last_sync', ['when' => fmt_date(substr($settings['last_sync_at'], 0, 10)) . ' ' . substr($settings['last_sync_at'], 11, 5)]) ?></p>
  <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  varosBarChart('weeklyChart', <?= json_encode($weekLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($weekValues) ?>);
});
</script>
<?php endif; ?>

<?php if ($hasSteps): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  varosBarChart('stepsChart', <?= json_encode($stepLabels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($stepValues) ?>);
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
