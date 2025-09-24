<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/stats.transfers.php
 * Purpose: Recent transfer_logs view and 24h summary (graceful if table missing)
 */

require_once __DIR__ . '/src/PdoConnection.php';
use Queue\PdoConnection;

function h(?string $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$rows = [];
$counts = [];
$err = '';
try {
    $pdo = PdoConnection::instance();
    // Summary counts 24h
    $q1 = $pdo->query("SELECT event_type, COUNT(*) c FROM transfer_logs WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY event_type ORDER BY c DESC");
    $counts = $q1 ? $q1->fetchAll(\PDO::FETCH_ASSOC) : [];
    // Recent rows
    $stmt = $pdo->query("SELECT id, event_type, source_system, trace_id, created_at FROM transfer_logs ORDER BY id DESC LIMIT 20");
    $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
} catch (\Throwable $e) { $err = $e->getMessage(); }

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Recent Transfers</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;line-height:1.4;margin:0;padding:8px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:6px 8px;border-bottom:1px solid #eaeaea;font-size:13px;text-align:left}
    .muted{color:#6b7280}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ddd;font-size:12px}
  </style>
</head>
<body>
  <h3 style="margin:8px 0">24h Summary</h3>
  <?php if ($err): ?><div class="muted">Error: <?php echo h($err); ?></div><?php endif; ?>
  <?php if ($counts): ?>
    <table><thead><tr><th>Event Type</th><th>Count</th></tr></thead><tbody>
      <?php foreach ($counts as $c): ?>
        <tr><td><?php echo h($c['event_type']); ?></td><td><?php echo (int)$c['c']; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <div class="muted">No events in the last 24 hours or table unavailable.</div>
  <?php endif; ?>

  <h3 style="margin:16px 0 8px">Recent 20</h3>
  <?php if ($rows): ?>
    <table><thead><tr><th>ID</th><th>Type</th><th>Source</th><th>Trace</th><th>When</th></tr></thead><tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="muted"><?php echo (int)$r['id']; ?></td>
          <td><span class="badge"><?php echo h($r['event_type']); ?></span></td>
          <td class="muted"><?php echo h($r['source_system']); ?></td>
          <td class="muted"><?php echo h($r['trace_id']); ?></td>
          <td class="muted"><?php echo h($r['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <div class="muted">No recent transfers.</div>
  <?php endif; ?>
  <p class="muted" style="margin-top:12px">@ https://staff.vapeshed.co.nz</p>
</body></html>
