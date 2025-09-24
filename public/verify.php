<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\PdoConnection;
use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }

try {
    $pdo = PdoConnection::instance();
    $checks = [
        'ls_jobs', 'ls_job_logs', 'ls_jobs_dlq', 'ls_rate_limits', 'ls_sync_cursors',
        'webhook_subscriptions', 'webhook_events', 'webhook_health', 'webhook_stats',
        'transfer_queue', 'transfer_validation_cache', 'transfer_queue_metrics',
        // Stock-Transfer schema (new)
        'transfers', 'transfer_audit_log', 'transfer_shipments', 'transfer_logs', 'transfer_items',
    ];
    $exists = [];
    foreach ($checks as $t) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $t) . "'");
            $exists[$t] = (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            $exists[$t] = false;
        }
    }
    echo json_encode(['ok' => true, 'data' => ['tables' => $exists]]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'verify_failed', 'message' => $e->getMessage()]]);
}
