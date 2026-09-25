<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$settings = varos_get_settings();
$entries = get_entries();
$workoutCount = count_workouts();
$stepDayCount = count_step_days();
$hasCode = varos_has_entry_code();

$endpoint = app_base_url() . '/api/health.php';
$token = (string)($settings['api_token'] ?? '');
$isHttps = str_starts_with($endpoint, 'https://');
$lastSync = !empty($settings['last_sync_at'])
    ? fmt_date(substr($settings['last_sync_at'], 0, 10)) . ' ' . substr($settings['last_sync_at'], 11, 5)
    : null;

$pageTitle = t('settings.title');
$activeTab = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<div class="section-title"><?= te('settings.goal_title') ?></div>
<div class="form-card">
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="redirect" value="settings.php">

    <div class="field-row">
      <div class="field">
        <label for="start_weight"><?= te('settings.start_weight') ?></label>
        <input type="text" inputmode="decimal" id="start_weight" name="start_weight"
               value="<?= $settings['start_weight'] !== null ? e((string)$settings['start_weight']) : '' ?>"
               placeholder="<?= te('settings.start_weight_ph') ?>">
      </div>
      <div class="field">
        <label for="goal_weight"><?= te('settings.goal_weight') ?></label>
        <input type="text" inputmode="decimal" id="goal_weight" name="goal_weight"
               value="<?= $settings['goal_weight'] !== null ? e((string)$settings['goal_weight']) : '' ?>"
               placeholder="<?= te('settings.goal_weight_ph') ?>">
      </div>
    </div>

    <div class="field-row">
      <div class="field">
        <label for="height_cm"><?= te('settings.height') ?></label>
        <input type="text" inputmode="decimal" id="height_cm" name="height_cm"
               value="<?= $settings['height_cm'] !== null ? e((string)$settings['height_cm']) : '' ?>"
               placeholder="<?= te('settings.height_ph') ?>">
      </div>
      <div class="field">
        <label for="milestone_count"><?= te('settings.milestones') ?></label>
        <input type="number" min="1" max="20" id="milestone_count" name="milestone_count"
               value="<?= e((string)($settings['milestone_count'] ?? 8)) ?>">
      </div>
    </div>

    <div class="field">
      <label for="start_date"><?= te('settings.start_date') ?></label>
      <input type="date" id="start_date" name="start_date"
             value="<?= e($settings['start_date'] ?? ($entries ? $entries[0]['entry_date'] : date('Y-m-d'))) ?>">
    </div>

    <div class="btn-row">
      <button type="submit" class="btn"><?= te('settings.save') ?></button>
    </div>
  </form>
  <p class="hint"><?= te('settings.hint') ?></p>
</div>

<div class="section-title"><?= te('prefs.title') ?></div>
<div class="form-card">
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="save_prefs">
    <input type="hidden" name="redirect" value="settings.php">
    <div class="field-row">
      <div class="field">
        <label for="lang"><?= te('prefs.language') ?></label>
        <select id="lang" name="lang">
          <?php foreach (VAROS_LANGS as $code => $name): ?>
            <option value="<?= e($code) ?>" <?= varos_lang() === $code ? 'selected' : '' ?>><?= e($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="width"><?= te('prefs.width') ?></label>
        <select id="width" name="width">
          <?php foreach (VAROS_WIDTHS as $w): ?>
            <option value="<?= e($w) ?>" <?= varos_width() === $w ? 'selected' : '' ?>><?= te('prefs.width_' . $w) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn"><?= te('prefs.save') ?></button>
    </div>
  </form>
  <p class="hint"><?= te('prefs.hint') ?></p>
</div>

<div class="section-title"><?= te('code.title') ?></div>
<div class="form-card">
  <p class="para"><?= te($hasCode ? 'code.intro_on' : 'code.intro_off') ?></p>
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="save_code">
    <input type="hidden" name="redirect" value="settings.php">
    <?php if ($hasCode): ?>
    <div class="field">
      <label for="current_code"><?= te('code.current') ?></label>
      <input type="password" id="current_code" name="current_code" autocomplete="off" required>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="new_code"><?= te('code.new') ?></label>
      <input type="password" id="new_code" name="new_code" autocomplete="new-password" minlength="4" maxlength="64">
    </div>
    <div class="btn-row">
      <button type="submit" class="btn"><?= te('code.save') ?></button>
      <?php if ($hasCode): ?><button type="submit" name="remove" value="1" class="btn danger" formnovalidate><?= te('code.remove') ?></button><?php endif; ?>
    </div>
  </form>
  <p class="hint"><?= te('code.hint') ?></p>
</div>

<div class="section-title" id="health"><?= te('health.title') ?></div>
<div class="form-card">
  <p class="para"><?= te('health.intro') ?></p>

  <div class="field">
    <label><?= te('health.endpoint') ?></label>
    <div class="copy-row">
      <code><?= e($endpoint) ?></code>
      <button type="button" class="btn secondary" data-copy="<?= e($endpoint) ?>" data-copied="<?= te('btn.copied') ?>"><?= te('btn.copy') ?></button>
    </div>
  </div>
  <div class="field">
    <label><?= te('health.token') ?></label>
    <div class="copy-row">
      <code><?= e($token) ?></code>
      <button type="button" class="btn secondary" data-copy="<?= e($token) ?>" data-copied="<?= te('btn.copied') ?>"><?= te('btn.copy') ?></button>
    </div>
  </div>
  <?php if (!$isHttps): ?><p class="hint"><?= te('health.https_warn') ?></p><?php endif; ?>

  <form method="post" action="actions.php" data-confirm="<?= te('health.confirm_regen') ?>">
    <input type="hidden" name="action" value="regen_token">
    <input type="hidden" name="redirect" value="settings.php">
    <div class="btn-row">
      <button type="submit" class="btn danger"><?= te('health.regen') ?></button>
    </div>
  </form>

  <div class="bmi-meta" style="margin-top:16px;padding-bottom:0;">
    <div>
      <div class="l"><?= te('health.last_sync') ?></div>
      <div class="v" style="font-size:14px;"><?= $lastSync ? e($lastSync) : te('health.never') ?></div>
    </div>
    <div>
      <div class="l"><?= te('health.workouts') ?></div>
      <div class="v"><?= (int)$workoutCount ?></div>
    </div>
    <div>
      <div class="l"><?= te('health.steps_imported') ?></div>
      <div class="v"><?= (int)$stepDayCount ?></div>
    </div>
  </div>

  <p class="para" style="margin-top:16px;"><b><?= te('health.steps_title') ?></b></p>
  <ol class="steps">
    <li><?= te('health.step1') ?></li>
    <li><?= te('health.step2') ?></li>
    <li><?= te('health.step3') ?></li>
    <li><?= te('health.step4') ?></li>
  </ol>
  <p class="hint"><?= te('health.metrics_hint') ?></p>
  <p class="hint"><?= te('health.alt') ?></p>
</div>

<div class="section-title"><?= te('data.title') ?></div>
<div class="form-card">
  <p style="margin:0 0 4px;font-size:13px;color:var(--ink-soft);"><?= te('data.weight_count', ['n' => count($entries)]) ?></p>
  <p style="margin:0 0 4px;font-size:13px;color:var(--ink-soft);"><?= te('data.workout_count', ['n' => $workoutCount]) ?></p>
  <p style="margin:0 0 10px;font-size:13px;color:var(--ink-soft);"><?= te('data.step_days', ['n' => $stepDayCount]) ?></p>
  <a class="btn secondary" href="logbook.php"><?= te('data.open_log') ?></a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
