  </main>

  <nav class="tabbar">
    <a href="reports.php" class="tab <?= $activeTab === 'reports' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" class="tab-icon"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span><?= te('nav.stats') ?></span>
    </a>
    <a href="exercise.php" class="tab <?= $activeTab === 'exercise' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" class="tab-icon"><path d="M2 12h4l3-7 5 14 3-7h5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span><?= te('nav.exercise') ?></span>
    </a>
    <a href="logbook.php" class="tab <?= $activeTab === 'logbook' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" class="tab-icon"><rect x="4" y="3" width="16" height="18" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <span><?= te('nav.log') ?></span>
    </a>
    <a href="settings.php" class="tab <?= $activeTab === 'settings' ? 'is-active' : '' ?>">
      <svg viewBox="0 0 24 24" class="tab-icon"><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M19.4 13a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V19a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.56 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H4a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.56-1 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H10a1.7 1.7 0 0 0 1-1.55V4a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V10a1.7 1.7 0 0 0 1.55 1H20a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
      <span><?= te('nav.settings') ?></span>
    </a>
  </nav>
</div>
<?php $__jsV = @filemtime(__DIR__ . '/../assets/app.js') ?: time(); ?>
<script src="assets/app.js?v=<?= $__jsV ?>"></script>
</body>
</html>
