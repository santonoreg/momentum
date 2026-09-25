<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// Αν ο κωδικός υπάρχει ήδη, δεν υπάρχει τίποτα να ρυθμιστεί.
if (varos_has_entry_code()) {
    header('Location: reports.php');
    exit;
}

$pageTitle = t('setup.title');
$activeTab = 'setup';
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<div class="section-title"><?= te('setup.title') ?></div>
<div class="form-card">
  <p class="para"><?= te('setup.intro') ?></p>
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="setup_code">
    <input type="hidden" name="redirect" value="setup.php">
    <div class="field">
      <label for="new_code"><?= te('code.new') ?></label>
      <input type="password" id="new_code" name="new_code" autocomplete="new-password" minlength="4" maxlength="64" required autofocus>
    </div>
    <div class="field">
      <label for="confirm_code"><?= te('setup.confirm') ?></label>
      <input type="password" id="confirm_code" name="confirm_code" autocomplete="new-password" minlength="4" maxlength="64" required>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn"><?= te('setup.submit') ?></button>
    </div>
  </form>
  <p class="hint"><?= te('setup.hint') ?></p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
