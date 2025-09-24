<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';

use Queue\PdoConnection;
use Queue\Config;
use Queue\Http;
use Queue\Lightspeed\Runner;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('tests_demo', 10)) { return; }

$mock = (bool)(Config::get('vend.http_mock', false) ?? false);
if (!$mock) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'mock_required', 'message' => 'Enable vend.http_mock to run demo tests safely.']]);
    return;
}

try {
    $pdo = PdoConnection::instance();
    $publicId = 'DEMO-' . substr(sha1((string)microtime(true)), 0, 8);
    $transferPk = null;
    $report = ['public_id' => $publicId];

    // Seed a demo transfer
    try {
        $hasTransfers = (bool)$pdo->query("SHOW TABLES LIKE 'transfers'")->fetchColumn();
        if ($hasTransfers) {
            $pdo->prepare("INSERT INTO transfers (public_id, type, status, outlet_from, outlet_to, created_by) VALUES (:pid,'stock','draft','OF-DEMO','OT-DEMO',0)")->execute([':pid'=>$publicId]);
            $transferPk = (int)$pdo->lastInsertId();
        }
    } catch (\Throwable $e) {}

    // Enqueue and run jobs (mock mode)
    $jobs = [
        ['type' => 'create_consignment', 'payload' => [
            'transfer_pk' => $transferPk, 'source_outlet_id' => 'OF-DEMO', 'dest_outlet_id' => 'OT-DEMO',
            'idempotency_key' => $publicId . '-create', 'lines' => [['product_id'=>101,'qty'=>2],['product_id'=>202,'qty'=>1]]
        ]],
        ['type' => 'edit_consignment_lines', 'payload' => [
            'transfer_pk' => $transferPk, 'consignment_id' => 123456, 'idempotency_key' => $publicId . '-edit',
            'add' => [['product_id'=>303,'qty'=>1]], 'remove' => [['product_id'=>202]]
        ]],
        ['type' => 'mark_transfer_partial', 'payload' => [
            'transfer_pk' => $transferPk, 'outstanding_lines' => 1
        ]],
        ['type' => 'cancel_consignment', 'payload' => [
            'transfer_pk' => $transferPk, 'consignment_id' => 123456, 'idempotency_key' => $publicId . '-cancel'
        ]],
    ];

    // Insert into ls_jobs directly for speed
    foreach ($jobs as $j) {
        $pdo->prepare('INSERT INTO ls_jobs (type, priority, payload, idempotency_key, status, attempts, next_run_at, created_at, updated_at) VALUES (:t, 5, :p, :k, \"pending\", 0, NOW(), NOW(), NOW())')
            ->execute([':t'=>$j['type'], ':p'=>json_encode($j['payload']), ':k'=>$j['payload']['idempotency_key'] ?? null]);
    }

    // Run the runner with generous limit
    Runner::run(['--limit' => 50]);

    // Summarize effects
    $summary = [];
    if ($transferPk) {
        try { $summary['transfer'] = $pdo->query('SELECT * FROM transfers WHERE id = ' . (int)$transferPk)->fetch(\PDO::FETCH_ASSOC) ?: null; } catch (\Throwable $e) {}
        try { $summary['audit_count'] = (int)($pdo->query('SELECT COUNT(*) FROM transfer_audit_log WHERE transfer_pk = ' . (int)$transferPk)->fetchColumn() ?: 0); } catch (\Throwable $e) {}
        try { $summary['logs_count'] = (int)($pdo->query('SELECT COUNT(*) FROM transfer_logs WHERE transfer_id = ' . (int)$transferPk)->fetchColumn() ?: 0); } catch (\Throwable $e) {}
    }

    echo json_encode(['ok' => true, 'data' => ['report' => $report, 'summary' => $summary]]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'demo_failed', 'message' => $e->getMessage()]]);
}
