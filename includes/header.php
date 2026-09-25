<?php
/**
 * @var string $pageTitle
 * @var string $activeTab  one of: summary, reports, exercise, logbook, settings
 * @var bool $needsChart   φόρτωσε Chart.js
 */
$pageTitle = $pageTitle ?? t('app.name');
$activeTab = $activeTab ?? 'summary';
$needsChart = $needsChart ?? false;

$__headerToday = new DateTime('now');
$__dayName = t('day.' . (int)$__headerToday->format('w'));
?>
<!DOCTYPE html>
<html lang="<?= e(varos_lang()) ?>" data-width="<?= e(varos_width()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> · <?= te('app.name') ?></title>
<meta name="theme-color" content="#EFF3F0">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Serif:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
<?php $__cssV = @filemtime(__DIR__ . '/../assets/style.css') ?: time(); ?>
<link rel="stylesheet" href="assets/style.css?v=<?= $__cssV ?>">
<?php if ($needsChart): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.5.0/chart.umd.min.js"></script>
<?php endif; ?>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="topbar-title"><?= te('app.name') ?></div>
    <div class="topbar-date"><?= e($__dayName) ?>, <?= e(fmt_date($__headerToday->format('Y-m-d'))) ?></div>
  </header>

  <main class="content">
