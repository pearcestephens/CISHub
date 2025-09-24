<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/queue.status.php
 * Purpose: Human-friendly queue dashboard: interprets metrics, shows live counts/ages, recent failures, and a safe kick-runner action.
 * Author: CIS Queue
 * Last Modified: 2025-09-22
 * Dependencies: app.php, PdoConnection, Config, Runner
 */

// Bootstrap CIS app/session safely
try {
    if (!isset($_SERVER['DOCUMENT_ROOT'])) { $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4); }
    $appPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/app.php';
    if (is_file($appPath)) { require_once $appPath; }
} catch (Throwable $e) { /* best-effort include */ }

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';

use Queue\PdoConnection;
use Queue\Lightspeed\Runner;

// Start session and gate to staff context
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$isStaff = isset($_SESSION['userID']) && (int)$_SESSION['userID'] > 0;
if (!$isStaff) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Forbidden</title><p>Staff session required.</p>';
    exit;
}

// Simple CSRF for actions
if (empty($_SESSION['qs_csrf'])) { $_SESSION['qs_csrf'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['qs_csrf'];

// Optional kick-runner action (bounded)
$actionMsg = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $tok = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, (string)$tok)) {
        $actionMsg = 'CSRF check failed.';
    } else {
        try {
            // Process a small batch to nudge the queue
            Runner::run(['--limit' => 5]);
            $actionMsg = 'Runner kicked (processed up to 5 jobs).';
        } catch (Throwable $e) {
            $actionMsg = 'Runner error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

$pdo = PdoConnection::instance();

// Query counts
$pendingTotal = (int)($pdo->query("SELECT COUNT(*) FROM ls_work_items WHERE status='pending'")->fetchColumn() ?: 0);
$workingTotal = (int)($pdo->query("SELECT COUNT(*) FROM ls_work_items WHERE status='working'")->fetchColumn() ?: 0);
$failedTotal  = (int)($pdo->query("SELECT COUNT(*) FROM ls_work_items WHERE status='failed'")->fetchColumn() ?: 0);
$dlqTotal     = (int)($pdo->query("SELECT COUNT(*) FROM ls_work_items WHERE status='dlq'")->fetchColumn() ?: 0);

// Ages
$oldestPendingAge = (int)($pdo->query("SELECT TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()) FROM ls_work_items WHERE status='pending'")->fetchColumn() ?: 0);
$longestWorkingAge = (int)($pdo->query("SELECT TIMESTAMPDIFF(SECOND, MIN(locked_at), NOW()) FROM ls_work_items WHERE status='working'")->fetchColumn() ?: 0);

// Pending by type (top 10)
$stmt = $pdo->query("SELECT type, COUNT(*) c FROM ls_work_items WHERE status='pending' GROUP BY type ORDER BY c DESC LIMIT 10");
$byType = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

// Recent validation failures (job_id=0)
$stmt2 = $pdo->query("SELECT id, created_at, log_message FROM ls_job_logs WHERE job_id=0 ORDER BY id DESC LIMIT 20");
$recentFails = [];
if ($stmt2) {
    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $payload = $r['log_message'];
        $j = null; try { $j = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable $e) { $j = null; }
        $recentFails[] = [
            'id' => (int)$r['id'],
            'ts' => (string)$r['created_at'],
            'event' => (string)($j['event'] ?? 'log'),
            'reason' => (string)($j['reason'] ?? ''),
            'pid' => (string)($j['pid'] ?? ''),
            'oid' => (string)($j['oid'] ?? ''),
            'oid2' => (string)($j['oid2'] ?? ''),
            'qty' => (string)($j['qty'] ?? ($j['qty_in'] ?? '')),
        ];
    }
}

// Derive status
$status = 'OK'; $note = '';
if ($pendingTotal > 0 && $workingTotal === 0) { $status = 'ATTENTION'; $note = 'Jobs pending but no workers active — cron/runner may be idle.'; }
if ($oldestPendingAge > 1800) { $status = 'DEGRADED'; $note = 'Oldest pending job is older than 30 minutes; backlog likely.'; }

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>CIS Queue Status</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.2.0/dist/css/bootstrap.min.css">
  <style>
    .kv { font-weight: 600; }
    .muted { color: #6c757d; font-size: 0.9rem; }
    .card + .card { margin-top: 1rem; }
    .status-ok { background: #e6ffed; }
    .status-attn { background: #fff4e5; }
    .status-deg { background: #ffe6e6; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="p-3">
  <div class="container-fluid">
    <div class="d-flex align-items-center mb-3">
      <h4 class="mb-0">Queue Status</h4>
      <a class="btn btn-link ml-3" href="<?php echo h(dirname($_SERVER['REQUEST_URI']) . '/metrics.php'); ?>" target="_blank">Raw metrics</a>
    </div>

    <?php if ($actionMsg): ?>
      <div class="alert alert-info py-2"><?php echo h($actionMsg); ?></div>
    <?php endif; ?>

    <?php
      $cls = 'status-ok';
      if ($status === 'ATTENTION') $cls = 'status-attn';
      if ($status === 'DEGRADED') $cls = 'status-deg';
    ?>
    <div class="card <?php echo $cls; ?>">
      <div class="card-body">
        <div class="row">
          <div class="col-md-8">
            <div><span class="kv">Status:</span> <?php echo h($status); ?><?php if ($note): ?> <span class="muted">— <?php echo h($note); ?></span><?php endif; ?></div>
            <div><span class="kv">Pending:</span> <?php echo $pendingTotal; ?> <span class="muted">(oldest <?php echo $oldestPendingAge; ?>s)</span></div>
            <div><span class="kv">Working:</span> <?php echo $workingTotal; ?> <span class="muted">(longest <?php echo $longestWorkingAge; ?>s)</span></div>
            <div><span class="kv">Failed:</span> <?php echo $failedTotal; ?>, <span class="kv">DLQ:</span> <?php echo $dlqTotal; ?></div>
          </div>
          <div class="col-md-4 text-md-right mt-3 mt-md-0">
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
              <button class="btn btn-sm btn-primary" type="submit">Kick runner (limit 5)</button>
            </form>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Top pending by type</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Type</th><th>Count</th></tr></thead>
          <tbody>
            <?php if (!$byType): ?>
              <tr><td colspan="2" class="text-center text-muted">No pending items</td></tr>
            <?php else: foreach ($byType as $row): ?>
              <tr><td class="mono"><?php echo h((string)$row['type']); ?></td><td><?php echo (int)$row['c']; ?></td></tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Recent validation failures (Quick Qty etc.)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Time</th><th>Event</th><th>Reason</th><th>PID</th><th>Outlet</th><th>Confirm</th><th>Qty</th></tr></thead>
          <tbody>
            <?php if (!$recentFails): ?>
              <tr><td colspan="7" class="text-center text-muted">No recent failures</td></tr>
            <?php else: foreach ($recentFails as $f): ?>
              <tr>
                <td class="mono"><?php echo h($f['ts']); ?></td>
                <td class="mono"><?php echo h($f['event']); ?></td>
                <td class="mono"><?php echo h($f['reason']); ?></td>
                <td class="mono"><?php echo h($f['pid']); ?></td>
                <td class="mono"><?php echo h($f['oid']); ?></td>
                <td class="mono"><?php echo h($f['oid2']); ?></td>
                <td class="mono"><?php echo h($f['qty']); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <p class="mt-3 text-muted">Tip: If Pending > 0 and Working = 0, cron/runner may be idle. Use the Kick button above or check the cron wrapper.</p>
  </div>
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.2.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Http.php';
\Queue\Lightspeed\Web::status();
