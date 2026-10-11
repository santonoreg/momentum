<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// Ανάλυση με AI
$isAdmin = varos_is_admin();
$aiHasKey = ai_get_key() !== '';
$aiReports = ai_all_reports();
$aiPeriod = ai_period();
$aiSched = $aiHasKey ? ai_schedule() : null;
$aiEarly = $aiSched !== null && !$aiSched['is_due'];
$aiWhen = '';
if ($aiSched !== null) {
    $aiWhen = $aiSched['age'] <= 0 ? t('ai.when_today') : ($aiSched['age'] === 1 ? t('ai.when_yesterday') : t('ai.when_ago', ['n' => $aiSched['age']]));
}
$aiErrorKey = null;
if (($_GET['flash'] ?? '') === 'aierror' && $isAdmin) {
    $aiErrorKey = (string)($_SESSION['ai_error'] ?? 'api');
    unset($_SESSION['ai_error']);
}

$pageTitle = t('nav.analysis');
$activeTab = 'analysis';
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<div class="ai-card">
  <div class="ai-head">
    <span class="ai-spark" aria-hidden="true">✦</span>
    <div>
      <div class="ai-title"><?= te('ai.title') ?></div>
      <div class="ai-sub"><?= te('ai.sub', ['model' => 'Claude Opus 5.5', 'n' => $aiPeriod]) ?></div>
    </div>
  </div>

  <div class="ai-freq">
    <span class="ai-freq-label"><?= te('ai.freq_label') ?></span>
    <form method="post" action="actions.php" class="ai-seg">
      <input type="hidden" name="action" value="set_ai_period">
      <input type="hidden" name="redirect" value="analysis.php">
      <?php foreach (AI_PERIODS as $pd): ?>
        <button type="submit" name="period" value="<?= (int)$pd ?>" class="<?= $aiPeriod === $pd ? 'is-active' : '' ?>"<?= $isAdmin ? '' : ' disabled' ?>><?= te('ai.freq_' . $pd) ?></button>
      <?php endforeach; ?>
    </form>
  </div>

  <?php if ($aiErrorKey !== null): ?>
    <div class="flash error"><?= te('ai.err.' . $aiErrorKey) ?></div>
  <?php endif; ?>

  <?php if ($aiReports): ?>
    <div class="ai-legend" aria-hidden="true">
      <?php foreach ([92 => 'excellent', 77 => 'good', 60 => 'mixed', 40 => 'attention', 10 => 'concern'] as $sc => $k): $bd = ai_score_band($sc); ?>
        <span class="ai-lg" data-band="<?= e($bd[0]) ?>"><i></i><?= te($bd[2]) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="ai-reports">
      <?php foreach ($aiReports as $i => $rep):
        $score = $rep['score'] !== null ? (int)$rep['score'] : null;
        $band = ai_score_band($score);
        $older = null;
        for ($j = $i + 1; $j < count($aiReports); $j++) {
            if ((int)$aiReports[$j]['days'] === (int)$rep['days']) {
                $older = $aiReports[$j];
                break;
            }
        }
        $delta = ($score !== null && $older && $older['score'] !== null) ? $score - (int)$older['score'] : null;
      ?>
      <details class="ai-report" data-band="<?= e($band[0]) ?>"<?= $i === 0 ? ' open' : '' ?>>
        <summary>
          <span class="ai-score"><?= $score !== null ? $score : '–' ?></span>
          <span class="ai-sum">
            <span class="ai-line1">
              <span class="ai-date"><?= e(fmt_date(substr($rep['created_at'], 0, 10))) ?></span>
              <span class="ai-badge"><?= te('ai.period_badge', ['n' => (int)$rep['days']]) ?></span>
              <span class="ai-verdict"><?= te($band[2]) ?></span>
              <?php if ($delta !== null && $delta !== 0): ?><span class="ai-delta <?= $delta > 0 ? 'up' : 'down' ?>"><?= $delta > 0 ? '▲ +' : '▼ −' ?><?= abs($delta) ?></span><?php endif; ?>
            </span>
            <?php if (!empty($rep['headline'])): ?><span class="ai-headline"><?= e($rep['headline']) ?></span><?php endif; ?>
          </span>
        </summary>
        <div class="ai-body"><?= ai_render_markdown($rep['content']) ?></div>
        <div class="ai-meta"><?= te('ai.meta', [
            'when' => fmt_date(substr($rep['created_at'], 0, 10)) . ' ' . substr($rep['created_at'], 11, 5),
            'cost' => number_format(ai_cost_usd($rep), 2, '.', ''),
        ]) ?></div>
        <?php if ($isAdmin): ?>
        <form method="post" action="actions.php" class="ai-del" data-confirm="<?= te('ai.delete_confirm', ['date' => fmt_date(substr($rep['created_at'], 0, 10))]) ?>">
          <input type="hidden" name="action" value="delete_ai_report">
          <input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
          <input type="hidden" name="redirect" value="analysis.php">
          <button type="submit" class="ai-del-btn"><?= te('ai.delete') ?></button>
        </form>
        <?php endif; ?>
      </details>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="ai-empty"><?= te('ai.intro') ?></p>
  <?php endif; ?>

  <?php if ($aiSched !== null): ?>
    <div class="ai-next <?= $aiSched['is_due'] ? 'is-due' : '' ?>">
      <?php if ($aiSched['is_due']): ?>
        <?= te('ai.due_now', ['when' => $aiWhen]) ?>
      <?php else: ?>
        <?= te('ai.next', ['date' => fmt_date($aiSched['due']), 'n' => $aiSched['days_left']]) ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$aiHasKey): ?>
    <?php if ($isAdmin): ?><a class="btn" href="settings.php#ai"><?= te('ai.set_key') ?></a><?php endif; ?>
  <?php elseif ($isAdmin && !ai_sdk_loaded()): ?>
    <div class="flash error"><?= te('ai.err.no_sdk') ?></div>
  <?php else: ?>
    <form method="post" action="actions.php" data-busy="<?= te('ai.busy') ?>"<?= $aiEarly ? ' data-confirm="' . te('ai.early_confirm', ['when' => $aiWhen, 'period' => $aiSched['period'], 'date' => fmt_date($aiSched['due'])]) . '"' : '' ?>>
      <input type="hidden" name="action" value="ai_report">
      <input type="hidden" name="redirect" value="analysis.php">
      <button type="submit" class="btn <?= $aiEarly ? 'secondary' : 'ai-btn' ?>"><?= te($aiSched ? 'ai.btn_new' : 'ai.btn_first_n', ['n' => $aiPeriod]) ?></button>
    </form>
  <?php endif; ?>
  <p class="hint"><?= te('ai.sends') ?> <?= te('ai.disclaimer') ?></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
