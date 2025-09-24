#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';

$pdo = \Queue\PdoConnection::instance();

// Release jobs stuck in leased state with expired lease and no recent heartbeat (if columns exist)
$cols = [];
try { $cols = $pdo->query('SHOW COLUMNS FROM ls_jobs')->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { $cols = []; }
$names = array_map(static fn($r) => (string)$r['Field'], $cols);
$hasLeased = in_array('leased_until', $names, true);
$hasHeartbeat = in_array('heartbeat_at', $names, true);

$released = 0;
if ($hasLeased || $hasHeartbeat) {
	$where = [];
	if ($hasLeased) { $where[] = '(leased_until IS NULL OR leased_until < NOW())'; }
	if ($hasHeartbeat) { $where[] = '(heartbeat_at IS NULL OR heartbeat_at < NOW() - INTERVAL 10 MINUTE)'; }
	$cond = $where ? implode(' OR ', $where) : '1=1';
	$sql = "UPDATE ls_jobs SET status='pending', leased_until=" . ($hasLeased ? 'NULL' : 'leased_until') . ", heartbeat_at=" . ($hasHeartbeat ? 'NULL' : 'heartbeat_at') . ", updated_at=NOW() WHERE status='leased' AND (" . $cond . ")";
	try { $stmt = $pdo->prepare($sql); $stmt->execute(); $released = (int)$stmt->rowCount(); } catch (\Throwable $e) { $released = 0; }
}

echo json_encode([
	'ok' => true,
	'released' => $released,
	'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/reap-stale.php'
]) . "\n";
