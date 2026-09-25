<?php
/**
 * Κουμπί «+» και φόρμα καταχώρησης βάρους.
 * @var string $modalRedirect  σελίδα επιστροφής μετά την αποθήκευση
 * @var string $today          σημερινή ημερομηνία (Y-m-d)
 */
?>
<?php if (varos_is_admin()): ?>
<button class="fab" data-open-modal="entry-modal" aria-label="<?= te('entry.new') ?>">
  <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
</button>

<div class="modal-backdrop" id="entry-modal">
  <div class="modal-sheet" data-title-new="<?= te('entry.new') ?>" data-title-edit="<?= te('entry.edit') ?>">
    <h3><?= te('entry.new') ?></h3>
    <form method="post" action="actions.php">
      <input type="hidden" name="action" value="save_entry">
      <input type="hidden" name="redirect" value="<?= e($modalRedirect) ?>">
      <div class="field-row">
        <div class="field">
          <label for="entry_date"><?= te('entry.date') ?></label>
          <input type="date" id="entry_date" name="entry_date" value="<?= e($today) ?>" max="<?= e($today) ?>" required>
        </div>
        <div class="field">
          <label for="weight"><?= te('entry.weight') ?></label>
          <input type="text" inputmode="decimal" id="weight" name="weight" placeholder="<?= te('entry.weight_ph') ?>" required>
        </div>
      </div>
      <div class="field">
        <label for="note"><?= te('entry.note') ?></label>
        <input type="text" id="note" name="note" placeholder="<?= te('entry.note_ph') ?>">
      </div>
      <div class="btn-row">
        <button type="button" class="btn secondary" data-close-modal><?= te('btn.cancel') ?></button>
        <button type="submit" class="btn"><?= te('btn.save') ?></button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
