#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';

$pdo = \Queue\PdoConnection::instance();

// Detect schema details
$cols = [];
try { $cols = $pdo->query('SHOW COLUMNS FROM ls_jobs')->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { $cols = []; }
$names = array_map(static fn($r) => (string)$r['Field'], $cols);
$hasStartedAt = in_array('started_at', $names, true);
$hasUpdatedAt = in_array('updated_at', $names, true);
$hasHeartbeat = in_array('heartbeat_at', $names, true);

// Choose threshold (seconds) via env/Config later; default 15 minutes
$thresholdSec = 900;

$conditions = [];
if ($hasStartedAt) { $conditions[] = "(started_at IS NULL OR started_at < NOW() - INTERVAL $thresholdSec SECOND)"; }
if ($hasUpdatedAt) { $conditions[] = "(updated_at IS NULL OR updated_at < NOW() - INTERVAL $thresholdSec SECOND)"; }
if ($hasHeartbeat) { $conditions[] = "(heartbeat_at IS NULL OR heartbeat_at < NOW() - INTERVAL $thresholdSec SECOND)"; }
if (!$conditions) { $conditions[] = "1=1"; }
$where = implode(' AND ', $conditions);

$released = 0;
try {
    // Move stuck working/running back to pending for retry
    $sql = "UPDATE ls_jobs SET status='pending', updated_at=NOW() WHERE (status='working' OR status='running') AND $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $released = (int)$stmt->rowCount();
} catch (\Throwable $e) {
    $released = 0;
}

echo json_encode([
    'ok' => true,
    'released' => $released,
    'note' => 'jobs in working/running considered stale were reset to pending',
    'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/reap-working.php'
]) . "\n";
