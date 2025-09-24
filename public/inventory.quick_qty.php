<?php
declare(strict_types=1);
/**
 * File: public/inventory.quick_qty.php
 * Purpose: First-class pipeline entry for Quick Product Qty Change
 * Contract (POST JSON or form):
 *   {
 *     "product_id": 12345,
 *     "outlet_id": 678,
 *     "new_qty": 42,
 *     "staff_id": 1001,         // optional, for audit
 *     "mode": "queue|sync",    // optional; queue (default) enqueues inventory.command; sync attempts immediate (mock-only by default)
 *     "idempotency_key": "..." // optional
 *   }
 */
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Lightspeed/ProductsV21.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Degrade.php';

use Queue\Http;
use Queue\Config;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Lightspeed\ProductsV21;
use Queue\Lightspeed\Runner;
use Queue\Lightspeed\Web;
use Queue\Degrade;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('inventory_quick_qty', 10)) { return; }

/** Increment a lightweight counter in ls_rate_limits for analytics */
function invq_increment(string $key, int $val = 1): void {
    try {
        $pdo = \Queue\PdoConnection::instance();
        $w = date('Y-m-d H:i:00');
        $stmt = $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,:c,NOW()) ON DUPLICATE KEY UPDATE counter = counter + :c, updated_at = NOW()');
        $stmt->execute([':k' => $key, ':w' => $w, ':c' => $val]);
    } catch (\Throwable $e) { /* swallow */ }
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $in = [];
    if ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
    }
    if (!$in) { $in = $_POST ?: []; }

    $pid  = isset($in['product_id']) ? (int)$in['product_id'] : 0;
    $oid  = isset($in['outlet_id']) ? (int)$in['outlet_id'] : 0;
    $qty  = isset($in['new_qty']) ? (int)$in['new_qty'] : null;
    $staff= isset($in['staff_id']) ? (int)$in['staff_id'] : null;
    $mode = isset($in['mode']) ? (string)$in['mode'] : 'queue';
    
        // Degrade guards: block quick qty changes when system is degraded
        if (Degrade::isReadOnly() || Degrade::isFeatureDisabled('quick_qty')) {
            $banner = Degrade::banner();
            Http::error('service_degraded', $banner['message'] !== '' ? $banner['message'] : 'Quick inventory changes are temporarily disabled while we stabilize the system.', null, 503);
            return;
        }
    $idk  = isset($in['idempotency_key']) ? (string)$in['idempotency_key'] : null;

    if ($pid <= 0 || $oid <= 0 || $qty === null) { Http::error('bad_request','product_id, outlet_id, new_qty required'); return; }
    if ($qty < 0) { Http::error('bad_request','new_qty must be >= 0'); return; }

    // Analytics: track request intent
    invq_increment('inventory_quick:requests_total', 1);
    invq_increment('inventory_quick:mode:' . $mode, 1);

    // Build job payload
    $payload = [
        'op' => 'set',
        'product_id' => $pid,
        'outlet_id' => $oid,
        'target' => $qty,
        'actor_staff_id' => $staff,
        'source' => 'quick_qty',
    ];

    if ($mode === 'sync') {
        $mock = (bool)(Config::get('vend.http_mock', false) ?? false);
        $allowSync = (bool) Config::getBool('inventory.quick.allow_sync', false);
        $idkEff = $idk ?: ('invq:' . $pid . ':' . $oid . ':' . $qty);
        $data = [ 'inventory_update' => [ 'outlet_id' => (string)$oid, 'on_hand' => (int)$qty, 'source' => 'quick_qty' ], 'idempotency_key' => $idkEff ];
        if ($mock || $allowSync) {
            // Execute immediate update (mock always allowed; real only when allowSync=true)
            $resp = ProductsV21::update($pid, $data);
            $st = (int)($resp['status'] ?? 0);
            if ($st < 200 || $st >= 300) {
                Http::error('vend_update_failed', 'HTTP ' . $st, ['status' => $st, 'body' => $resp['body'] ?? null], 502);
                return;
            }
            // If real path, verify on_hand; in mock we short-circuit as ok
            $ok = true; $observed = null; $attempts = 0;
            if (!$mock) {
                $ver = \Queue\Lightspeed\ProductsV21::verifyOnHand($pid, $oid, (int)$qty, (int)(Config::get('vend.verify_timeout_sec', 10) ?? 10));
                $ok = (bool)($ver['ok'] ?? false); $observed = $ver['observed'] ?? null; $attempts = (int)($ver['attempts'] ?? 0);
                if (!$ok) { Http::error('vend_update_unconfirmed', 'Inventory not confirmed by vendor', ['observed' => $observed, 'expected' => (int)$qty, 'attempts' => $attempts], 502); return; }
            }
            invq_increment('inventory_quick:sync_exec', 1);
            echo json_encode(['ok' => true, 'mode' => 'sync', 'mock' => $mock, 'verified' => $ok, 'observed' => $observed, 'attempts' => $attempts, 'status' => $st, 'request_id' => Http::requestId(), 'idempotency_key' => $idkEff]);
            return;
        }
        // Not allowed: fall back to queue
        $mode = 'queue';
    }

    // Queue mode: enqueue inventory.command and optionally auto-kick runner
    $idk = $idk ?: ('invq:' . $pid . ':' . $oid . ':' . $qty);
    $jobId = Repo::addJob('inventory.command', $payload, $idk);
    invq_increment('inventory_quick:queued_total', 1);

    // Best-effort: tiny in-process kick (bounded) if allowed
    // Background kick (safe, respects advisory lock) — gated internally by vend.queue.auto_kick.enabled
    try { Web::kick('inventory.command'); } catch (\Throwable $e) { /* ignore */ }
    if (Config::getBool('inventory.quick.sync_kick', false)) {
        Runner::run(['--limit' => 3, '--type' => 'inventory.command']);
    }

    echo json_encode(['ok' => true, 'mode' => 'queue', 'job_id' => $jobId, 'request_id' => Http::requestId(), 'idempotency_key' => $idk]);
} catch (\Throwable $e) {
    Http::error('inventory_quick_failed', $e->getMessage());
}
