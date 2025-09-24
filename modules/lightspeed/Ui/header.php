<?php
declare(strict_types=1);
require_once __DIR__ . '/common.php';
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
$title = $title ?? 'Lightspeed Queue Dashboard';
?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="/assets/services/queue/assets/css/dashboard.css">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚡</text></svg>">
  <style>.kv{font-family:ui-monospace,monospace}.form-text.mono{font-family:ui-monospace,monospace}</style>
</head>
<body>
<div class="container my-3" data-autorefresh="<?= (int)cfg('dash.autorefresh.default_s',0) ?>">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h3 m-0">Lightspeed Queue</h1>
      <span class="badge-soft">v2</span>
      <a class="btn btn-outline-primary btn-sm" href="<?= SVC_BASE . '/public/dashboard.php' ?>">Full Dashboard</a>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?= SVC_BASE . '/migrations.php' ?>">SQL Migrations</a>
    </div>
  </div>

  <?php global $__cfg_db_error;
  if (!empty($__cfg_db_error)): ?>
    <div class="alert alert-warning" role="alert">
      Config backend warning: <?= h($__cfg_db_error) ?>
    </div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3">
    <?php $active = $active ?? 'overview'; ?>
    <li class="nav-item"><a class="nav-link <?= $active==='overview'?'active':'' ?>"   href="<?= UI_BASE.'/overview.php'   ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link <?= $active==='webhooks'?'active':'' ?>"   href="<?= UI_BASE.'/webhooks.php'   ?>">Webhooks</a></li>
    <li class="nav-item"><a class="nav-link <?= $active==='queue'?'active':'' ?>"      href="<?= UI_BASE.'/queue.php'      ?>">Queue</a></li>
    <li class="nav-item"><a class="nav-link <?= $active==='transfers'?'active':'' ?>"  href="<?= UI_BASE.'/transfers.php'  ?>">Transfers</a></li>
    <li class="nav-item"><a class="nav-link <?= $active==='tools'?'active':'' ?>"      href="<?= UI_BASE.'/tools.php'      ?>">Tools</a></li>
  </ul>
