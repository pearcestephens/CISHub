#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';

$pdo = Queue\PdoConnection::instance();
$rows = $pdo->query("SELECT type, COUNT(*) c FROM ls_jobs WHERE status='pending' GROUP BY type ORDER BY c DESC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok'=>true,'pending_by_type'=>$rows], JSON_PRETTY_PRINT), "\n";
