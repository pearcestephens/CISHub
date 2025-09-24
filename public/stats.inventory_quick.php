<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;

// Auth-protected quick stats for Inventory Quick Qty clicks/processing
if (!Http::ensureAuth()) { return; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$now = time();
$since = date('Y-m-d H:i:00', $now - 60 * 60);

$rows = [];
try {
    $pdo = \Queue\PdoConnection::instance();
    $stmt = $pdo->prepare("SELECT window_start, rl_key, counter FROM ls_rate_limits WHERE window_start >= :since AND rl_key LIKE 'inventory_quick:%' ORDER BY window_start ASC");
    $stmt->execute([':since' => $since]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error loading stats: ' . $e->getMessage();
    exit;
}

// Aggregate by minute
$byMin = []; $latest = ['requests_total'=>0,'queued_total'=>0,'sync_exec'=>0,'mode'=>['queue'=>0,'sync'=>0]];
foreach ($rows as $r) {
    $min = (string)$r['window_start'];
    $key = (string)$r['rl_key'];
    $val = (int)$r['counter'];
    if (!isset($byMin[$min])) { $byMin[$min] = ['requests_total'=>0,'queued_total'=>0,'sync_exec'=>0,'mode'=>['queue'=>0,'sync'=>0]]; }
    if ($key === 'inventory_quick:requests_total') { $byMin[$min]['requests_total'] += $val; }
    elseif ($key === 'inventory_quick:queued_total') { $byMin[$min]['queued_total'] += $val; }
    elseif ($key === 'inventory_quick:sync_exec') { $byMin[$min]['sync_exec'] += $val; }
    elseif (strpos($key, 'inventory_quick:mode:') === 0) {
        $mode = substr($key, strlen('inventory_quick:mode:')) ?: 'unknown';
        if (!isset($byMin[$min]['mode'][$mode])) { $byMin[$min]['mode'][$mode] = 0; }
        $byMin[$min]['mode'][$mode] += $val;
    }
}
// Latest minute snapshot
$mins = array_keys($byMin); sort($mins);
if ($mins) { $latest = $byMin[end($mins)]; }

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventory Quick Qty — Live Stats</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 12px; color:#212529; }
    .card { border:1px solid #dee2e6; border-radius:6px; margin-bottom:12px; }
    .card-header { background:#f8f9fa; padding:8px 12px; font-weight:600; }
    .card-body { padding:12px; }
    table { width:100%; border-collapse: collapse; }
    th, td { border:1px solid #e9ecef; padding:6px 8px; font-size:13px; }
    th { background:#f1f3f5; text-align:left; }
    .muted { color:#6c757d; font-size:12px; }
  </style>
  <meta http-equiv="refresh" content="30">
  <link rel="preconnect" href="https://staff.vapeshed.co.nz">
  <link rel="dns-prefetch" href="https://staff.vapeshed.co.nz">
 </head>
<body>
  <div class="card">
    <div class="card-header">Quick Qty — Current Minute</div>
    <div class="card-body">
      <div>Requests: <strong><?php echo (int)$latest['requests_total']; ?></strong></div>
      <div>By Mode: queue=<strong><?php echo (int)($latest['mode']['queue'] ?? 0); ?></strong>, sync=<strong><?php echo (int)($latest['mode']['sync'] ?? 0); ?></strong></div>
      <div>Queued: <strong><?php echo (int)$latest['queued_total']; ?></strong> &nbsp; | &nbsp; Sync Exec: <strong><?php echo (int)$latest['sync_exec']; ?></strong></div>
      <div class="muted">Auto-refreshes every 30s</div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">Last 60 Minutes (per-minute)</div>
    <div class="card-body">
      <table>
        <thead><tr><th>Minute</th><th>Requests</th><th>Queue</th><th>Sync</th><th>Queued</th><th>Sync Exec</th></tr></thead>
        <tbody>
        <?php foreach ($byMin as $minute => $vals): ?>
          <tr>
            <td><?php echo h($minute); ?></td>
            <td><?php echo (int)$vals['requests_total']; ?></td>
            <td><?php echo (int)($vals['mode']['queue'] ?? 0); ?></td>
            <td><?php echo (int)($vals['mode']['sync'] ?? 0); ?></td>
            <td><?php echo (int)$vals['queued_total']; ?></td>
            <td><?php echo (int)$vals['sync_exec']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="muted">Source: ls_rate_limits (keys inventory_quick:*) — window_start >= <?php echo h($since); ?></div>
</body>
</html>
