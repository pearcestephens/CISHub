<?php declare(strict_types=1); require_once __DIR__ . '/common.php';
if (!headers_sent()) { header('Content-Type: text/html; charset=utf-8'); }
$title = $title ?? 'Lightspeed Queue Dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo h($title); ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <style>
    body { padding: 20px; }
    .kv { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .form-text.mono { font-family: ui-monospace, monospace; }
  </style>
</head>
<body>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h3 m-0">Lightspeed Queue</h1>
      <a class="btn btn-outline-primary btn-sm" href="<?php echo SVC_BASE.'/public/dashboard.php'; ?>">Full Dashboard</a>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="<?php echo SVC_BASE.'/migrations.php'; ?>">SQL Migrations</a>
    </div>
  </div>
  <?php global $__cfg_db_error; if (!empty($__cfg_db_error)): ?><div class="alert alert-warning" role="alert">Config backend warning: <?php echo h($__cfg_db_error); ?></div><?php endif; ?>
  <ul class="nav nav-tabs mb-3">
    <?php $active = $active ?? 'overview'; ?>
    <li class="nav-item"><a class="nav-link <?php echo $active==='overview'?'active':''; ?>" href="<?php echo UI_BASE.'/overview.php'; ?>">Overview</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $active==='webhooks'?'active':''; ?>" href="<?php echo UI_BASE.'/webhooks.php'; ?>">Webhooks</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $active==='queue'?'active':''; ?>" href="<?php echo UI_BASE.'/queue.php'; ?>">Queue</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $active==='transfers'?'active':''; ?>" href="<?php echo UI_BASE.'/transfers.php'; ?>">Transfers</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $active==='tools'?'active':''; ?>" href="<?php echo UI_BASE.'/tools.php'; ?>">Tools</a></li>
  </ul>