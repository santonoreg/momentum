<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

// Χωρίς κωδικό πρέπει πρώτα να οριστεί (αρχική ρύθμιση)· αν είναι ήδη συνδεδεμένος, δεν χρειάζεται σύνδεση.
if (!varos_has_entry_code()) {
    header('Location: setup.php');
    exit;
}
$redirect = $_GET['redirect'] ?? 'settings.php';
if (!preg_match('/^[a-z_]+\.php$/', $redirect)) {
    $redirect = 'settings.php';
}
if (varos_is_admin()) {
    header('Location: ' . $redirect);
    exit;
}

$pageTitle = t('login.title');
$activeTab = 'settings';
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<div class="section-title"><?= te('login.title') ?></div>
<div class="form-card">
  <p class="para"><?= te('login.intro') ?></p>
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div class="field">
      <label for="password"><?= te('login.password') ?></label>
      <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
    </div>
    <div class="btn-row">
      <button type="submit" class="btn"><?= te('login.submit') ?></button>
    </div>
  </form>
</div>

<?php $prefsRedirect = 'login.php'; require __DIR__ . '/includes/prefs_form.php'; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
