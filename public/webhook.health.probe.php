<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Config.php';

use Queue\Http; use Queue\Config;

Http::commonJsonHeaders();
// No auth required: used by external monitors/Lightspeed ping.
$ok = true; $err = null;
try {
    $pdo = \Queue\PdoConnection::instance();
    // Use 'healthy' to match ENUM('healthy','warning','critical','unknown')
    $pdo->prepare("INSERT INTO webhook_health (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details) VALUES (NOW(), 'vend.webhook', 'healthy', 0, 0, JSON_OBJECT('reason','probe'))")->execute();
} catch (\Throwable $e) { $ok=false; $err=$e->getMessage(); }
Http::respond($ok, $ok ? ['ok'=>true] : null, $ok ? null : ['code'=>'health_write_failed','message'=>$err]);
