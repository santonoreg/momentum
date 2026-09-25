<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/functions.php';

$entries = array_reverse(get_entries());
$today = date('Y-m-d');
$needsCode = varos_has_entry_code();
$yesterday = date('Y-m-d', strtotime('-1 day'));

$pageTitle = t('log.title');
$activeTab = 'logbook';
require __DIR__ . '/includes/header.php';
?>

<?= render_flash() ?>

<?php if (!$entries): ?>
  <div class="card empty-state">
    <div class="big"><?= te('log.empty_title') ?></div>
    <p><?= te('log.empty_text') ?></p>
  </div>
<?php else: ?>
  <div class="section-title"><?= te('log.count', ['n' => count($entries)]) ?></div>
  <div class="log-list">
    <?php foreach ($entries as $e):
      $dateLabel = $e['entry_date'] === $today ? t('days.today') : ($e['entry_date'] === $yesterday ? t('days.yesterday') : fmt_date($e['entry_date']));
    ?>
    <div class="log-row">
      <div>
        <div class="log-weight"><?= fmt_num((float)$e['weight']) ?> <?= te('unit.kg') ?></div>
        <div class="log-date"><?= e($dateLabel) ?><?php if ($e['note']): ?> · <span class="log-note"><?= e($e['note']) ?></span><?php endif; ?></div>
      </div>
      <div class="log-actions">
        <button type="button" class="icon-btn" data-open-modal="entry-modal"
                data-edit-date="<?= e($e['entry_date']) ?>"
                data-edit-weight="<?= e((string)$e['weight']) ?>"
                data-edit-note="<?= e((string)($e['note'] ?? '')) ?>"
                aria-label="<?= te('entry.edit_aria') ?>">
          <svg viewBox="0 0 24 24" fill="none"><path d="M4 20l4.4-.9L19.5 8a2 2 0 0 0 0-2.8l-.7-.7a2 2 0 0 0-2.8 0L5 15.6 4 20Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        </button>
        <form method="post" action="actions.php" data-confirm="<?= te('log.confirm_delete', ['date' => $dateLabel]) ?>"<?= $needsCode ? ' data-needs-code="' . te('code.prompt') . '"' : '' ?>>
          <input type="hidden" name="action" value="delete_entry">
          <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          <input type="hidden" name="redirect" value="logbook.php">
          <input type="hidden" name="code" value="">
          <button type="submit" class="icon-btn" aria-label="<?= te('entry.delete_aria') ?>">
            <svg viewBox="0 0 24 24" fill="none"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php $modalRedirect = 'logbook.php'; require __DIR__ . '/includes/entry_modal.php'; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
