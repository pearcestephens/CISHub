<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/stats.events.php
 * Purpose: 24h event counts across transfer_logs
 */
require_once __DIR__ . '/src/PdoConnection.php';
use Queue\PdoConnection;

function h(?string $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$data = [];$err='';
try {
  $pdo = PdoConnection::instance();
  $stmt = $pdo->query("SELECT event_type, COUNT(*) cnt FROM transfer_logs WHERE created_at >= NOW() - INTERVAL 1 DAY GROUP BY event_type ORDER BY cnt DESC");
  $data = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
} catch (\Throwable $e) { $err = $e->getMessage(); }
?><!doctype html>
<html lang="en"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Event Counts (24h)</title>
  <style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;margin:0;padding:8px}table{width:100%;border-collapse:collapse}th,td{padding:6px 8px;border-bottom:1px solid #eaeaea;font-size:13px;text-align:left}.muted{color:#6b7280}</style>
</head><body>
  <h3 style="margin:8px 0">Event Counts (24h)</h3>
  <?php if ($err): ?><div class="muted">Error: <?php echo h($err); ?></div><?php endif; ?>
  <?php if ($data): ?><table><thead><tr><th>Event</th><th>Count</th></tr></thead><tbody>
    <?php foreach ($data as $r): ?><tr><td><?php echo h($r['event_type']); ?></td><td><?php echo (int)$r['cnt']; ?></td></tr><?php endforeach; ?>
  </tbody></table><?php else: ?><div class="muted">No data.</div><?php endif; ?>
  <p class="muted" style="margin-top:12px">@ https://staff.vapeshed.co.nz</p>
</body></html>
