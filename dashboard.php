<?php
declare(strict_types=1);

/**
 * File: assets/services/queue/dashboard.php
 * Purpose: Standalone dashboard with Original Cloudways Cron Scheduler reference
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 * Links:
 * - Full Lightspeed Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php
 */

// No session/app includes here to avoid coupling and redirect loops

// Canonical paths and commands for Cloudways Cron Job Manager
$serviceRoot = '/home/master/applications/jcepnzzkmj/public_html/assets/services/queue';
$phpBinary   = 'php'; // Use platform PHP; adjust to absolute path in Cloudways if required

// Minimal HTML escape helper used throughout this page
if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

require_once __DIR__ . '/src/PdoConnection.php';
require_once __DIR__ . '/src/Lightspeed/Runner.php';
require_once __DIR__ . '/src/PdoWorkItemRepository.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
if (empty($_SESSION['qs_csrf'])) { $_SESSION['qs_csrf'] = bin2hex(random_bytes(16)); }
$qs_csrf = $_SESSION['qs_csrf'];

	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
		$act = (string)($_POST['qs_action'] ?? '');
		$tok = (string)($_POST['csrf'] ?? '');
		if (!hash_equals($qs_csrf, $tok)) {
			$kickMsg = 'CSRF check failed.';
		} else {
			if ($act === 'kick') {
				try { Queue\Lightspeed\Runner::run(['--limit' => 5]); $kickMsg = 'Runner kicked (processed up to 5 jobs).'; }
				catch (Throwable $e) { $kickMsg = 'Runner error: ' . h($e->getMessage()); }
			}
			if ($act === 'test_inv_dry') {
				try {
					$pid = isset($_POST['test_pid']) ? (int)$_POST['test_pid'] : 0;
					$oid = isset($_POST['test_oid']) ? (int)$_POST['test_oid'] : 0;
					$delta = isset($_POST['test_delta']) ? (int)$_POST['test_delta'] : 1;
					if ($pid>0 && $oid>0) {
						$idk = 'dryinv:' . $pid . ':' . $oid . ':' . time();
						$payload = ['op'=>'adjust','product_id'=>$pid,'outlet_id'=>$oid,'delta'=>$delta,'dry_run'=>true,'reason'=>'dashboard.test'];
						$jobId = Queue\PdoWorkItemRepository::addJob('inventory.command', $payload, $idk);
						$testMsg = 'Enqueued dry-run inventory.command job #' . (int)$jobId;
					} else { $testMsg = 'Provide product_id and outlet_id for dry-run test.'; }
				} catch (Throwable $e) { $testMsg = 'Test enqueue failed: ' . h($e->getMessage()); }
			}
			if ($act === 'test_pull_inventory') {
				try {
					$idk = 'pullinv:' . time();
					$jobId = Queue\PdoWorkItemRepository::addJob('pull_inventory', ['reason'=>'dashboard.test'], $idk);
					$testMsg = 'Enqueued pull_inventory job #' . (int)$jobId;
				} catch (Throwable $e) { $testMsg = 'Test enqueue failed: ' . h($e->getMessage()); }
			}
			if ($act === 'test_fanout') {
				try {
					$idk = 'fanout:' . time();
					$jobId = Queue\PdoWorkItemRepository::addJob('webhook.event', ['webhook_id'=>'DASH_TEST_' . time(),'webhook_type'=>'product.update'], $idk);
					$testMsg = 'Enqueued webhook.event (fanout) job #' . (int)$jobId;
				} catch (Throwable $e) { $testMsg = 'Test enqueue failed: ' . h($e->getMessage()); }
			}
			if ($act === 'playbook') {
				try {
					$pid = isset($_POST['test_pid']) ? (int)$_POST['test_pid'] : 1001;
					$oid = isset($_POST['test_oid']) ? (int)$_POST['test_oid'] : 1;
					$delta = isset($_POST['test_delta']) ? (int)$_POST['test_delta'] : 1;
					$jid1 = Queue\PdoWorkItemRepository::addJob('inventory.command', ['op'=>'adjust','product_id'=>$pid,'outlet_id'=>$oid,'delta'=>$delta,'dry_run'=>true,'reason'=>'dashboard.test'], 'play:inv:'.time());
					$jid2 = Queue\PdoWorkItemRepository::addJob('pull_inventory', ['reason'=>'dashboard.test'], 'play:pull:'.time());
					$jid3 = Queue\PdoWorkItemRepository::addJob('webhook.event', ['webhook_id'=>'DASH_TEST_' . time(),'webhook_type'=>'product.update'], 'play:fanout:'.time());
					$testMsg = 'Playbook enqueued: inv#'.$jid1.', pull#'.$jid2.', fanout#'.$jid3;
				} catch (Throwable $e) { $testMsg = 'Playbook failed: ' . h($e->getMessage()); }
			}
		}
	}

// DB-backed metrics — degrade gracefully if DB is unavailable
$pendingTotal = $workingTotal = $failedTotal = 0;
$dlqTotal = 0; $oldestPendingAge = 0; $longestWorkingAge = 0;
$byType = []; $backlogSeries = []; $throughputSeries = [];
$recentFails = []; $lastActivity = null; $lockState = 'unknown';
$quickQtyCount = 0; $webhookStatus = 'unknown'; $webhookNote = '';
try {
	$pdo = Queue\PdoConnection::instance();
	// topline
	$pendingTotal = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0);
	$workingTotal = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status IN ('working','running')")->fetchColumn() ?: 0);
	$failedTotal  = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='failed'")->fetchColumn() ?: 0);
	try { $dlqTotal = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs_dlq")->fetchColumn() ?: 0); } catch (\Throwable $e) { $dlqTotal = 0; }
	// ages
	try { $oldestPendingAge  = (int)($pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()),0) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0); } catch (\Throwable $e) {}
	try { $longestWorkingAge = (int)($pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(started_at), NOW()),0) FROM ls_jobs WHERE status IN ('working','running')")->fetchColumn() ?: 0); } catch (\Throwable $e) {}
	// by type
	try { if ($st = $pdo->query("SELECT type, COUNT(*) c FROM ls_jobs WHERE status='pending' GROUP BY type ORDER BY c DESC LIMIT 10")) { $byType = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } } catch (\Throwable $e) {}
	// backlog sparkline
	try {
		$rows = $pdo->query("SELECT DATE_FORMAT(created_at,'%Y-%m-%d %H:%i') m, COUNT(*) c FROM ls_jobs WHERE status='pending' AND created_at >= NOW() - INTERVAL 12 MINUTE GROUP BY m ORDER BY m ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$map = []; foreach ($rows as $r) { $map[(string)$r['m']] = (int)$r['c']; }
		for ($i=11; $i>=0; $i--) { $k = date('Y-m-d H:i', time() - $i*60); $backlogSeries[] = (int)($map[$k] ?? 0); }
	} catch (\Throwable $e) { $backlogSeries = []; }
	// throughput sparkline (done per minute)
	try {
		$rows = $pdo->query("SELECT DATE_FORMAT(finished_at,'%Y-%m-%d %H:%i') m, COUNT(*) c FROM ls_jobs WHERE (status IN ('done','completed') OR finished_at IS NOT NULL) AND finished_at >= NOW() - INTERVAL 12 MINUTE GROUP BY m ORDER BY m ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
		$map = []; foreach ($rows as $r) { $map[(string)$r['m']] = (int)$r['c']; }
		for ($i=11; $i>=0; $i--) { $k = date('Y-m-d H:i', time() - $i*60); $throughputSeries[] = (int)($map[$k] ?? 0); }
	} catch (\Throwable $e) { $throughputSeries = []; }
	// heartbeat and lock
	try { $lastActivity = (string)($pdo->query("SELECT DATE_FORMAT(MAX(GREATEST(IFNULL(updated_at, '1970-01-01'), IFNULL(finished_at, '1970-01-01'), IFNULL(started_at, '1970-01-01'), IFNULL(created_at, '1970-01-01'))),'%Y-%m-%d %H:%i:%s') FROM ls_jobs")->fetchColumn() ?: ''); } catch (\Throwable $e) { $lastActivity = null; }
	try { $stmt = $pdo->prepare('SELECT IS_FREE_LOCK(:k)'); $stmt->execute([':k' => 'ls_runner:all']); $v = $stmt->fetch(PDO::FETCH_NUM); $lockState = ($v && (int)$v[0] === 1) ? 'idle' : 'busy'; } catch (\Throwable $e) { $lockState = 'unknown'; }
	// recent validation failures
	try {
		$st2 = $pdo->query("SELECT id, created_at, COALESCE(message, log_message) AS msg FROM ls_job_logs WHERE job_id=0 ORDER BY id DESC LIMIT 10");
		while ($st2 && ($r = $st2->fetch(PDO::FETCH_ASSOC))) {
			$j = null; try { $j = json_decode((string)$r['msg'], true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) {}
			$recentFails[] = [ 'ts' => (string)($r['created_at'] ?? ''), 'event' => (string)($j['event'] ?? 'log'), 'reason' => (string)($j['reason'] ?? ''), ];
		}
	} catch (\Throwable $e) {}
	// Quick Qty recent activity count (last 60 minutes)
	try { $quickQtyCount = (int)($pdo->query("SELECT COUNT(*) FROM ls_job_logs WHERE (message LIKE '%cis.quick_qty.bridge%' OR log_message LIKE '%cis.quick_qty.bridge%') AND created_at >= NOW() - INTERVAL 60 MINUTE")->fetchColumn() ?: 0); } catch (\Throwable $e) { $quickQtyCount = 0; }
	// Webhook health summary
	try {
		$lastRecvAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(received_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
		$failRecent = 0;
		try { $failRecent = (int)$pdo->query("SELECT COUNT(*) FROM webhook_health WHERE check_time >= NOW() - INTERVAL 30 MINUTE AND health_status IN ('warning','fail','failed')")->fetchColumn(); } catch (\Throwable $e) {}
		if ($lastRecvAge <= 600 && $failRecent === 0) { $webhookStatus = 'HEALTHY'; }
		elseif ($lastRecvAge <= 1800) { $webhookStatus = 'WARNING'; $webhookNote = 'Last event >10m or recent warnings'; }
		else { $webhookStatus = 'STALE'; $webhookNote = 'No recent webhooks received'; }
	} catch (\Throwable $e) { $webhookStatus = 'unknown'; }
} catch (\Throwable $e) {
	// Dashboard can still render without DB context
}

// Traffic light status derived from queue conditions
$status = 'OK'; $note = '';
if ($pendingTotal > 0 && $workingTotal === 0) { $status = 'ATTENTION'; $note = 'Jobs pending but no workers active — runner may be idle.'; }
if ($oldestPendingAge > 1800) { $status = 'DEGRADED'; $note = 'Oldest pending job > 30 minutes.'; }

// Cloudways Cron job definitions for display/copy
$cron = [
	[
		'name' => 'Run Jobs',
		'schedule' => '* * * * *',
	'command' => $phpBinary . ' ' . $serviceRoot . '/bin/run-jobs.php --continuous --limit=200 >> ' . $serviceRoot . '/logs/run-jobs.log 2>&1',
		'desc' => 'Main worker loop. Safe to run every minute; internal locks prevent overlap.'
	],
	[
		'name' => 'Schedule Pulls',
		'schedule' => '*/5 * * * *',
		'command' => $phpBinary . ' ' . $serviceRoot . '/bin/schedule-pulls.php >> ' . $serviceRoot . '/logs/schedule-pulls.log 2>&1',
		'desc' => 'Enqueue periodic data pull jobs (products/inventory)'
	],
	[
		'name' => 'Reap Stale',
		'schedule' => '*/2 * * * *',
		'command' => $phpBinary . ' ' . $serviceRoot . '/bin/reap-stale.php >> ' . $serviceRoot . '/logs/reap-stale.log 2>&1',
		'desc' => 'Reclaim orphaned leases and retry stuck jobs'
	],
];

$pageTitle = 'Queue Service Dashboard';
require __DIR__ . '/partials/header.php';
?>
<div class="container" data-autorefresh="0">
	<?php
	// Emergency banner: show if any kill/pause flags are active
	$flags = [
		'inventory_kill' => \Queue\FeatureFlags::inventoryKillAll() ?? false,
		'queue_runner_kill' => \Queue\FeatureFlags::killAll() ?? false,
		'pause_inventory_command' => \Queue\Config::getBool('vend_queue_pause.inventory.command', false) ?? false,
		'webhook_disabled' => !\Queue\Config::getBool('LS_WEBHOOKS_ENABLED', true),
	];
	$anyKill = (bool)array_sum(array_map(fn($v)=>$v?1:0, $flags));
	if ($anyKill): ?>
	<div class="alert alert-danger d-flex align-items-center" role="alert">
		<div>
			<strong>EMERGENCY MODE ACTIVE</strong> — automated inventory/webhook processing has been halted for safety.
			<ul class="mb-0 small">
				<?php if (!empty($flags['inventory_kill'])): ?><li>inventory.command kill switch engaged</li><?php endif; ?>
				<?php if (!empty($flags['queue_runner_kill'])): ?><li>global runner kill switch engaged</li><?php endif; ?>
				<?php if (!empty($flags['pause_inventory_command'])): ?><li>inventory.command paused via config</li><?php endif; ?>
				<?php if (!empty($flags['webhook_disabled'])): ?><li>webhooks disabled (LS_WEBHOOKS_ENABLED=false)</li><?php endif; ?>
			</ul>
		</div>
	</div>
	<?php endif; ?>
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h1 class="h3 m-0">Queue Service Dashboard</h1>
		<div class="d-flex gap-2">
			<a class="btn btn-outline-primary btn-sm" href="https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php">Full Lightspeed Dashboard</a>
		</div>
	</div>

	<ul class="nav nav-pills mb-3">
		<li class="nav-item"><a class="nav-link" href="#health">Health<?php if (isset($pendingTotal)): ?><span class="badge bg-secondary ms-1"><?= (int)$pendingTotal ?></span><?php endif; ?><?php if (!empty($dlqTotal)): ?><span class="badge bg-danger ms-1"><?= (int)$dlqTotal ?></span><?php endif; ?></a></li>
		<li class="nav-item"><a class="nav-link" href="#webhooks">Webhooks</a></li>
		<li class="nav-item"><a class="nav-link" href="#transfers">Transfers/Inventory</a></li>
		<li class="nav-item"><a class="nav-link" href="#quickqty">Quick Qty<?php if (isset($quickQtyCount)): ?><span class="badge bg-info text-dark ms-1"><?= (int)$quickQtyCount ?></span><?php endif; ?></a></li>
		<li class="nav-item"><a class="nav-link" href="#api">API Logs</a></li>
		<li class="nav-item"><a class="nav-link" href="#tests">E2E Tests</a></li>
	</ul>

	<?php if (isset($pendingTotal)): ?>
	<div class="card mb-3" id="health">
		<div class="card-header">Queue Health</div>
		<div class="card-body">
			<?php if (!empty($kickMsg)): ?>
			<div class="alert alert-info py-2 mb-3"><?= h($kickMsg) ?></div>
			<?php endif; ?>
			<?php if (!empty($testMsg)): ?>
			<div class="alert alert-success py-2 mb-3"><?= h($testMsg) ?></div>
			<?php endif; ?>
			<div class="row">
				<div class="col-md-8">
					<div><strong>Status:</strong> <?= h($status ?? 'n/a') ?><?php if (!empty($note)): ?> <span class="text-muted">— <?= h($note) ?></span><?php endif; ?></div>
					<div><strong>Pending:</strong> <?= (int)($pendingTotal ?? 0) ?> <span class="text-muted small">(oldest <?= (int)($oldestPendingAge ?? 0) ?>s)</span></div>
					<div><strong>Working:</strong> <?= (int)($workingTotal ?? 0) ?> <span class="text-muted small">(longest <?= (int)($longestWorkingAge ?? 0) ?>s)</span></div>
					<div><strong>Failed:</strong> <?= (int)($failedTotal ?? 0) ?>, <strong>DLQ:</strong> <?= (int)($dlqTotal ?? 0) ?></div>
					<div class="mt-2"><strong>Backlog (last 12m):</strong>
						<div id="spark-backlog" class="spark" data-points="<?= h(implode(',', array_map('strval', $backlogSeries ?? []))) ?>"></div>
					</div>
					<div class="mt-2"><strong>Throughput (last 12m):</strong>
						<div id="spark-throughput" class="spark" data-points="<?= h(implode(',', array_map('strval', $throughputSeries ?? []))) ?>"></div>
					</div>
					<div class="mt-2"><strong>Webhook:</strong> <?= h($webhookStatus) ?><?php if (!empty($webhookNote)): ?> <span class="text-muted small">— <?= h($webhookNote) ?></span><?php endif; ?></div>
				</div>
				<div class="col-md-4 text-md-right mt-3 mt-md-0">
					<form method="post" class="d-inline">
						<input type="hidden" name="csrf" value="<?= h($qs_csrf ?? '') ?>">
						<input type="hidden" name="qs_action" value="kick">
						<button class="btn btn-sm btn-primary" type="submit">Kick runner (limit 5)</button>
					</form>
					<a class="btn btn-sm btn-link" href="https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php" target="_blank">Open status page</a>
					<a class="btn btn-sm btn-link" href="https://staff.vapeshed.co.nz/assets/services/queue/metrics.php" target="_blank">Raw metrics</a>
					<div class="small mt-2 text-muted">Heartbeat: <span class="mono"><?= h($lastActivity ?? 'n/a') ?></span> — Runner is <strong><?= h($lockState ?? 'unknown') ?></strong></div>
				</div>
			</div>
			<hr>
			<div class="row">
				<div class="col-md-6">
					<h6>Top pending types</h6>
					<div class="table-responsive">
						<table class="table table-sm mb-0">
							<thead><tr><th>Type</th><th>Count</th></tr></thead>
							<tbody>
							<?php if (empty($byType)): ?>
								<tr><td colspan="2" class="text-muted text-center">No pending items</td></tr>
							<?php else: foreach ($byType as $r): ?>
								<tr><td class="mono"><?= h((string)$r['type']) ?></td><td><?= (int)$r['c'] ?></td></tr>
							<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="col-md-6">
					<h6>Recent validation failures</h6>
					<div class="table-responsive">
						<table class="table table-sm mb-0">
							<thead><tr><th>Time</th><th>Event</th><th>Reason</th></tr></thead>
							<tbody>
							<?php if (empty($recentFails)): ?>
								<tr><td colspan="3" class="text-muted text-center">None</td></tr>
							<?php else: foreach ($recentFails as $f): ?>
								<tr><td class="mono"><?= h($f['ts']) ?></td><td class="mono"><?= h($f['event']) ?></td><td class="mono"><?= h($f['reason']) ?></td></tr>
							<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<div class="card mb-3" id="webhooks">
		<div class="card-header">Webhooks — last 10 received</div>
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-sm mb-0" id="webhooks-last10">
					<thead><tr><th>ID</th><th>Received</th><th>Type</th><th>Webhook ID</th><th>Status</th></tr></thead>
					<tbody>
					<?php
					try {
						$wh = $pdo->query("SELECT id, received_at, webhook_type, webhook_id, status FROM webhook_events ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
						if (!$wh) echo '<tr><td colspan="4" class="text-muted text-center">None</td></tr>';
						foreach ($wh as $row) {
							$id=(int)$row['id']; $rcv = h((string)$row['received_at']); $t = h((string)$row['webhook_type']); $wid = h((string)$row['webhook_id']); $st = h((string)$row['status']);
							echo "<tr data-webhook-id=\"$id\"><td class=\"mono\">$id</td><td class=\"mono\">$rcv</td><td class=\"mono\">$t</td><td class=\"mono\">$wid</td><td>$st</td></tr>";
						}
					} catch (Throwable $e) { echo '<tr><td colspan="4" class="text-muted">Webhook events not available</td></tr>'; }
					?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card mb-3" id="transfers">
		<div class="card-header">Transfers/Inventory</div>
		<div class="card-body">
			<div class="row">
				<div class="col-md-6">
					<h6>Queue by type (pending)</h6>
					<div class="table-responsive">
						<table class="table table-sm mb-0">
							<thead><tr><th>Type</th><th>Pending</th></tr></thead>
							<tbody>
							<?php foreach ($byType as $r): ?>
							<tr><td class="mono"><?= h((string)$r['type']) ?></td><td><?= (int)$r['c'] ?></td></tr>
							<?php endforeach; if (empty($byType)): ?>
							<tr><td colspan="2" class="text-muted text-center">No data</td></tr>
							<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>
				<div class="col-md-6">
					<h6 class="d-flex justify-content-between align-items-center">Recent jobs (last 10)
						<span class="ms-2 flex-grow-1"></span>
						<input type="search" class="form-control form-control-sm max-w-200" id="job-search" placeholder="Filter…">
					</h6>
					<div class="table-responsive">
							<table class="table table-sm mb-0" id="recent-jobs">
								<thead><tr><th>ID</th><th>Created</th><th>Type</th><th>Status</th><th>Trace</th></tr></thead>
							<tbody>
							<?php
							try {
									$rows = $pdo->query("SELECT id, created_at, type, status FROM ls_jobs ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
								if (!$rows) echo '<tr><td colspan="3" class="text-muted text-center">None</td></tr>';
								foreach ($rows as $row) {
										$jid = (int)($row['id'] ?? 0);
									$ca = h((string)($row['created_at'] ?? ''));
									$ty = h((string)$row['type']); $st = h((string)$row['status']);
										$traceUrl = 'https://staff.vapeshed.co.nz/assets/services/queue/public/pipeline.trace.php?job=' . rawurlencode((string)$jid);
										echo "<tr data-job-id=\"$jid\"><td class=\"mono\">$jid</td><td class=\"mono\">$ca</td><td class=\"mono\">$ty</td><td>$st</td><td><a class=\"btn btn-sm btn-outline-primary\" href=\"$traceUrl\" target=\"_blank\" rel=\"noopener\">View Trace</a></td></tr>";
								}
							} catch (Throwable $e) { echo '<tr><td colspan="3" class="text-muted">Jobs not available</td></tr>'; }
							?>
							</tbody>
						</table>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="card mb-3" id="api">
		<div class="card-header">API Calls — last 10</div>
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead>
					<tbody>
					<?php
					try {
						$logs = $pdo->query("SELECT created_at, level, COALESCE(message, log_message) AS message FROM ls_job_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
						if (!$logs) echo '<tr><td colspan="3" class="text-muted text-center">None</td></tr>';
						foreach ($logs as $row) {
							$ts = h((string)($row['created_at'] ?? ''));
							$lv = h((string)($row['level'] ?? ''));
							$msgRaw = (string)($row['message'] ?? '');
							// show compact JSON if possible
							$short = $msgRaw;
							if (strlen($short) > 200) { $short = substr($short, 0, 197) . '…'; }
							$msg = h($short);
							echo "<tr><td class=\"mono\">$ts</td><td>$lv</td><td class=\"mono\">$msg</td></tr>";
						}
					} catch (Throwable $e) { echo '<tr><td colspan="3" class="text-muted">Logs not available</td></tr>'; }
					?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card mb-3" id="quickqty">
		<div class="card-header">Quick Qty — recent activity</div>
		<div class="card-body">
			<div class="table-responsive">
				<table class="table table-sm mb-0">
					<thead><tr><th>Time</th><th>Product</th><th>Outlet</th><th>Delta</th><th>Staff</th><th>Status</th><th>Trace</th></tr></thead>
					<tbody>
					<?php
					try {
						$q = "SELECT created_at, COALESCE(message, log_message) AS msg FROM ls_job_logs WHERE (message LIKE '%cis.quick_qty.bridge%' OR log_message LIKE '%cis.quick_qty.bridge%') ORDER BY id DESC LIMIT 10";
						$rows = $pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) ?: [];
						if (!$rows) echo '<tr><td colspan="7" class="text-muted text-center">No recent Quick Qty activity</td></tr>';
						foreach ($rows as $r) {
							$ts = h((string)($r['created_at'] ?? ''));
							$meta = [];
							try { $meta = json_decode((string)$r['msg'], true, 512, JSON_THROW_ON_ERROR) ?: []; } catch (Throwable $e) { $meta = []; }
							$pid = isset($meta['product_id']) ? (string)$meta['product_id'] : '';
							$oid = isset($meta['outlet_id']) ? (string)$meta['outlet_id'] : '';
							$delta = isset($meta['delta']) ? (string)$meta['delta'] : '';
							$staff = isset($meta['staff_id']) ? (string)$meta['staff_id'] : (isset($meta['staff']) ? (string)$meta['staff'] : '');
							$status = isset($meta['event']) ? (string)$meta['event'] : 'enqueue';
							$trace = isset($meta['trace_id']) && is_string($meta['trace_id']) ? (string)$meta['trace_id'] : '';
							$traceCell = $trace !== ''
								? '<a class="btn btn-sm btn-outline-primary" href="https://staff.vapeshed.co.nz/assets/services/queue/public/pipeline.trace.php?trace=' . rawurlencode($trace) . '" target="_blank" rel="noopener">View Trace</a>'
								: '<span class="text-muted">—</span>';
							echo '<tr>'
								. '<td class="mono">' . $ts . '</td>'
								. '<td class="mono">' . h($pid) . '</td>'
								. '<td class="mono">' . h($oid) . '</td>'
								. '<td class="mono">' . h($delta) . '</td>'
								. '<td class="mono">' . h($staff) . '</td>'
								. '<td>' . h($status) . '</td>'
								. '<td>' . $traceCell . '</td>'
							. '</tr>';
						}
					} catch (Throwable $e) { echo '<tr><td colspan="6" class="text-muted">Quick Qty logs not available</td></tr>'; }
					?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card mb-3" id="tests">
		<div class="card-header">End-to-End Smoke Tests</div>
		<div class="card-body">
			<form method="post" class="row g-2 align-items-end">
				<input type="hidden" name="csrf" value="<?= h($qs_csrf ?? '') ?>">
				<div class="col-sm-2">
					<label class="form-label">Product ID</label>
					<input type="number" class="form-control form-control-sm" name="test_pid" placeholder="e.g., 1001">
				</div>
				<div class="col-sm-2">
					<label class="form-label">Outlet ID</label>
					<input type="number" class="form-control form-control-sm" name="test_oid" placeholder="e.g., 1">
				</div>
				<div class="col-sm-2">
					<label class="form-label">Delta</label>
					<input type="number" class="form-control form-control-sm" name="test_delta" value="1">
				</div>
				<div class="col-sm-6">
					<button class="btn btn-sm btn-outline-primary" name="qs_action" value="test_inv_dry" type="submit">Enqueue Dry-run Inventory Adjust</button>
					<button class="btn btn-sm btn-outline-secondary" name="qs_action" value="test_pull_inventory" type="submit">Enqueue Pull Inventory</button>
					<button class="btn btn-sm btn-outline-success" name="qs_action" value="test_fanout" type="submit">Enqueue Webhook Fanout</button>
					<button class="btn btn-sm btn-outline-dark" name="qs_action" value="playbook" type="submit">Run E2E Playbook</button>
				</div>
			</form>
		</div>
	</div>

	<div class="card mb-3">
		<div class="card-header">Original Cloudways Cron Scheduler</div>
		<div class="card-body">
			<p class="mb-2">Use the following entries in the Cloudways Cron Job Manager. Copy each command into the <strong>Command</strong> field and set the <strong>Schedule</strong> accordingly.</p>

			<div class="table-responsive">
				<table class="table table-sm align-middle">
					<thead>
							<tr>
								<th class="col-w-18">Name</th>
								<th class="col-w-12">Schedule</th>
								<th>Command</th>
								<th class="col-w-18">Notes</th>
								<th class="col-w-8"></th>
							</tr>
					</thead>
					<tbody>
					<?php foreach ($cron as $row): ?>
						<tr>
							<td><?= h($row['name']) ?></td>
							<td><span class="badge bg-secondary mono"><?= h($row['schedule']) ?></span></td>
							<td class="mono small"><?= h($row['command']) ?></td>
							<td class="text-muted small"><?= h($row['desc']) ?></td>
									<td><button class="btn btn-outline-secondary btn-sm" data-cmd="<?= h($row['command']) ?>">Copy</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="alert alert-info mt-3" role="alert">
				Logs are written to <span class="mono"><?= h($serviceRoot) ?>/logs/</span>. Ensure the directory exists and is writable.
			</div>
			<pre class="mono mb-0"><?php echo h($serviceRoot); ?>/logs/
		- worker.log
		- run-jobs.log
		- schedule-pulls.log
		- reap-stale.log</pre>
		</div>
	</div>

	<div class="card border-warning mb-3">
		<div class="card-header bg-warning-subtle">Fallback (use only if bin fails)</div>
		<div class="card-body">
			<div class="alert alert-warning" role="alert">
				Use these only if the primary bin runners above fail or are unavailable. Do not run both primary and fallback at the same time — risk of duplicate processing.
			</div>
			<div class="table-responsive">
				<table class="table table-sm align-middle">
					<thead>
						<tr>
							<th class="col-w-18">Name</th>
							<th class="col-w-12">Schedule</th>
							<th>Command</th>
							<th class="col-w-18">Notes</th>
							<th class="col-w-8"></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>Legacy Worker (fallback)</td>
							<td><span class="badge bg-secondary mono">* * * * *</span></td>
							<td class="mono small">php <?= h($serviceRoot) ?>/worker_new.php >> <?= h($serviceRoot) ?>/logs/worker.log 2>&1</td>
							<td class="text-muted small">Fallback worker if bin/ not available.</td>
							<td><button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="php <?= h($serviceRoot) ?>/worker_new.php >> <?= h($serviceRoot) ?>/logs/worker.log 2>&1">Copy</button></td>
						</tr>
						<tr>
							<td>Legacy Reconciler (fallback)</td>
							<td><span class="badge bg-secondary mono">*/2 * * * *</span></td>
							<td class="mono small">php <?= h($serviceRoot) ?>/reconciler_new.php >> <?= h($serviceRoot) ?>/logs/reconciler.log 2>&1</td>
							<td class="text-muted small">Fallback reconciler every 2 minutes.</td>
							<td><button class="btn btn-outline-secondary btn-sm" type="button" data-cmd="php <?= h($serviceRoot) ?>/reconciler_new.php >> <?= h($serviceRoot) ?>/logs/reconciler.log 2>&1">Copy</button></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<div class="card">
		<div class="card-header">Environment</div>
		<div class="card-body">
			<dl class="row mb-0 mono">
				<dt class="col-sm-3">Service Root</dt><dd class="col-sm-9"><?= h($serviceRoot) ?></dd>
				<dt class="col-sm-3">PHP Binary</dt><dd class="col-sm-9"><?= h($phpBinary) ?></dd>
				<dt class="col-sm-3">Host</dt><dd class="col-sm-9"><?= h($_SERVER['HTTP_HOST'] ?? 'localhost') ?></dd>
				<dt class="col-sm-3">Generated</dt><dd class="col-sm-9"><?= h(gmdate('Y-m-d\TH:i:s\Z')) ?></dd>
			</dl>
		</div>
	</div>

	<p class="text-muted mt-4 mb-0">@ https://staff.vapeshed.co.nz</p>
</div>
<!-- Job Detail Modal -->
<div class="modal fade" id="jobDetailModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Job <span class="mono" id="jobDetailTitle"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="jobDetailBody"><div class="text-muted">Select a job to view details.</div></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<!-- Webhook Payload Modal -->
<div class="modal fade" id="whDetailModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg modal-dialog-scrollable">
		<div class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Webhook <span class="mono" id="whDetailTitle"></span></h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<div class="modal-body">
				<div id="whDetailBody"><div class="text-muted">Select an event to view payload.</div></div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
			</div>
		</div>
	</div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>