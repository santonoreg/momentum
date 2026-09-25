<?php
/**
 * Φόρμα γλώσσας/πλάτους (αποθηκεύονται σε cookie στη συσκευή).
 * @var string $prefsRedirect  σελίδα επιστροφής
 */
?>
<div class="section-title"><?= te('prefs.title') ?></div>
<div class="form-card">
  <form method="post" action="actions.php">
    <input type="hidden" name="action" value="save_prefs">
    <input type="hidden" name="redirect" value="<?= e($prefsRedirect) ?>">
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
