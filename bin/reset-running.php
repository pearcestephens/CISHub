#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/bin/reset-running.php
 * Purpose: Force-reset jobs stuck in working/running back to pending, optionally for a specific type.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-24
 * Link: https://staff.vapeshed.co.nz/assets/services/queue/bin/reset-running.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';

$pdo = \Queue\PdoConnection::instance();
$type = $argv[1] ?? null;

// Inspect columns to clear lease/heartbeat when present
$cols = [];
try { $cols = $pdo->query('SHOW COLUMNS FROM ls_jobs')->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) { $cols = []; }
$names = array_map(static fn($r) => (string)$r['Field'], $cols);
$hasLease = in_array('leased_until', $names, true);
$hasHeartbeat = in_array('heartbeat_at', $names, true);
$hasUpdated = in_array('updated_at', $names, true);

$where = "(status='working' OR status='running')";
$params = [];
if ($type) { $where .= " AND type = :t"; $params[':t'] = $type; }
$set = "status='pending'";
if ($hasLease) { $set .= ", leased_until=NULL"; }
if ($hasHeartbeat) { $set .= ", heartbeat_at=NULL"; }
if ($hasUpdated) { $set .= ", updated_at=NOW()"; }

$released = 0;
try {
    $sql = "UPDATE ls_jobs SET $set WHERE $where";
    $st = $pdo->prepare($sql);
    foreach ($params as $k=>$v) { $st->bindValue($k, $v); }
    $st->execute();
    $released = (int)$st->rowCount();
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$e->getMessage()]) . "\n");
    exit(1);
}

echo json_encode([
    'ok' => true,
    'released' => $released,
    'type' => $type,
    'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/reset-running.php'
]) . "\n";
