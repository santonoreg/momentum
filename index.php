<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();
$entries = get_entries();
$progress = compute_progress($settings, $entries);
$recent = recent_stats_window($entries, 14);
$today = date('Y-m-d');

$changes = [
    '7' => weight_change_over_days($entries, $progress['current_weight'], 7),
    '30' => weight_change_over_days($entries, $progress['current_weight'], 30),
    '90' => weight_change_over_days($entries, $progress['current_weight'], 90),
];
$allTimeChange = ($progress['current_weight'] !== null && $entries)
    ? (float)$entries[0]['weight'] - $progress['current_weight']
    : null;

function chip_class(?float $v, int $direction): string
{
    if ($v === null || $direction === 0) return '';
    $favorable = $direction < 0 ? $v > 0.0001 : $v < -0.0001;
    if (abs($v) < 0.0001) return '';
    return $favorable ? 'pos' : 'neg';
}

$pageTitle = t('summary.title');
$activeTab = 'summary';
$needsChart = true;
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<?php if (!$entries): ?>
  <div class="card empty-state">
    <div class="big"><?= te('summary.empty_title') ?></div>
    <p><?= te('summary.empty_text') ?></p>
  </div>

<?php elseif (!$progress['has_goal']): ?>
  <div class="hero no-goal">
    <div>
      <div class="hero-label"><?= te('hero.current') ?></div>
      <div class="ring-value" style="font-size:34px;"><?= fmt_num($progress['current_weight']) ?> <span style="font-size:15px;font-family:var(--font-body);color:var(--ink-soft);"><?= te('unit.kg') ?></span></div>
    </div>
    <p style="margin:0;color:var(--ink-soft);font-size:13.5px;"><?= te('hero.nogoal_text') ?></p>
    <a class="btn" href="settings.php"><?= te('hero.set_goal') ?></a>
  </div>

<?php else:
  $radius = 54; $circumference = 2 * M_PI * $radius;
  $pct = $progress['percent_complete'];
  $offset = $circumference * (1 - $pct / 100);
  $goalLabel = $progress['direction'] < 0 ? t('hero.goal_loss') : ($progress['direction'] > 0 ? t('hero.goal_gain') : t('hero.goal'));
?>
  <div class="hero">
    <div class="ring-wrap">
      <svg viewBox="0 0 128 128" width="128" height="128">
        <circle class="ring-track" cx="64" cy="64" r="<?= $radius ?>" fill="none" stroke-width="12"/>
        <circle class="ring-progress" cx="64" cy="64" r="<?= $radius ?>" fill="none" stroke-width="12"
                stroke-dasharray="<?= $circumference ?>" stroke-dashoffset="<?= $offset ?>"/>
      </svg>
      <div class="ring-center">
        <div class="ring-value"><?= fmt_num($progress['current_weight']) ?></div>
        <div class="ring-unit"><?= te('unit.kg') ?> · <?= (int)round($pct) ?>%</div>
      </div>
    </div>
    <div class="hero-side">
      <div class="hero-label"><?= e($goalLabel) ?></div>
      <div class="hero-goal"><?= fmt_num($progress['goal_weight']) ?> <?= te('unit.kg') ?></div>
      <div class="hero-milestone"><?= te('hero.milestone', ['n' => $progress['current_milestone'], 'total' => $progress['milestone_count']]) ?></div>
      <div class="hero-togo"><?= t_html('hero.togo', ['value' => '<b>' . e(fmt_num($progress['to_go']) . ' ' . t('unit.kg')) . '</b>']) ?></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($entries): ?>
  <div class="section-title"><?= te('section.change') ?></div>
  <div class="chips">
    <?php foreach (['7', '30', '90'] as $d): ?>
    <div class="chip">
      <div class="chip-value <?= chip_class($changes[$d], $progress['direction']) ?>"><?= fmt_signed($changes[$d]) ?></div>
      <div class="chip-label"><?= te('chip.days', ['n' => $d]) ?></div>
    </div>
    <?php endforeach; ?>
    <div class="chip">
      <div class="chip-value <?= chip_class($allTimeChange, $progress['direction']) ?>"><?= fmt_signed($allTimeChange) ?></div>
      <div class="chip-label"><?= te('chip.total') ?></div>
    </div>
  </div>

  <div class="section-title"><?= te('section.recent') ?></div>
  <div class="card">
    <?php if ($recent): ?>
    <div class="trend-stats">
      <div class="ts">
        <div class="ts-value hi"><?= fmt_num($recent['high']['value']) ?></div>
        <div class="ts-label"><?= te('ts.high') ?></div>
        <div class="ts-sub"><?= e(days_ago_label($recent['high']['days_ago'])) ?></div>
      </div>
      <div class="ts">
        <div class="ts-value"><?= fmt_num($recent['latest']['value']) ?></div>
        <div class="ts-label"><?= te('ts.latest') ?></div>
        <div class="ts-sub"><?= e(days_ago_label($recent['latest']['days_ago'])) ?></div>
      </div>
      <div class="ts">
        <div class="ts-value"><?= fmt_num($recent['trend']) ?></div>
        <div class="ts-label"><?= te('ts.trend') ?></div>
        <div class="ts-sub"><?= te('ts.trend_sub') ?></div>
      </div>
      <div class="ts">
        <div class="ts-value lo"><?= fmt_num($recent['low']['value']) ?></div>
        <div class="ts-label"><?= te('ts.low') ?></div>
        <div class="ts-sub"><?= e(days_ago_label($recent['low']['days_ago'])) ?></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="chart-holder"><canvas id="miniChart"></canvas></div>
  </div>
<?php endif; ?>

<?php $modalRedirect = 'index.php'; require __DIR__ . '/includes/entry_modal.php'; ?>

<?php if ($entries):
  $sparkEntries = array_slice($entries, -30);
  $labels = array_map(fn($e) => date('j/n', strtotime($e['entry_date'])), $sparkEntries);
  $values = array_map(fn($e) => (float)$e['weight'], $sparkEntries);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  varosLineChart('miniChart', <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($values) ?>, { showAxes: false, points: false });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
