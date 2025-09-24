#!/usr/bin/env php
<?php
declare(strict_types=1);

use Queue\PdoConnection;

require __DIR__ . '/../vendor/autoload.php';

$pdo = PdoConnection::instance();
$now = date('Y-m-d H:i:s');

// Release jobs stuck in leased state with expired lease and no recent heartbeat
$stmt = $pdo->prepare("UPDATE ls_jobs SET status='pending', leased_until=NULL, heartbeat_at=NULL, updated_at=NOW() WHERE status='leased' AND (leased_until IS NULL OR leased_until < NOW())");
$count = $stmt->execute() ? $stmt->rowCount() : 0;

echo json_encode(['ok' => true, 'released' => $count, 'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/reap-stale.php']) . "\n";
