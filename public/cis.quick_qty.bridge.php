<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/cis.quick_qty.bridge.php
 * Purpose: CIS UI bridge for Quick Product Qty Change (no bearer required).
 * Accepts POST from the modal and enqueues an inventory.command job.
 * Input (form POST):
 *   _vendID    (int)  Lightspeed product ID
 *   _outletID  (int)  Lightspeed outlet ID
 *   _newQty    (int)  New on_hand qty target
 *   _staffID   (int)  Optional staff/user id for audit
 * Output (JSON): { ok: boolean, data|error, request_id }
 */

// Bootstrap CIS app session (if available)
try {
    if (!isset($_SERVER['DOCUMENT_ROOT'])) { $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4); }
    $appPath = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/app.php';
    if (is_file($appPath)) { require_once $appPath; }
} catch (\Throwable $e) { /* best-effort include */ }

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Lightspeed/Runner.php';
require_once __DIR__ . '/../src/Degrade.php';

use Queue\Http;
use Queue\Config;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Lightspeed\Runner;
use Queue\Degrade;

Http::commonJsonHeaders();

// Lightweight CIS session guard: allow if a session is present or if running via CLI for smoke tests
try {
    if (PHP_SAPI !== 'cli') {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        // If your app exposes a specific session key, validate it here. We fall back to accepting the request in staff portal context.
    }
} catch (\Throwable $e) { /* ignore */ }

/** Increment analytics counters in ls_rate_limits */
function cis_invq_increment(string $key, int $val = 1): void {
    try {
        $pdo = \Queue\PdoConnection::instance();
        $w = date('Y-m-d H:i:00');
        $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,:c,NOW()) ON DUPLICATE KEY UPDATE counter=counter+:c, updated_at=NOW()')
            ->execute([':k'=>$key, ':w'=>$w, ':c'=>$val]);
    } catch (\Throwable $e) { /* swallow */ }
}

try {
    // Degrade guards: block quick qty bridge when system is degraded
    if (Degrade::isReadOnly() || Degrade::isFeatureDisabled('quick_qty')) {
        $banner = Degrade::banner();
        Http::error('service_degraded', $banner['message'] !== '' ? $banner['message'] : 'Quick inventory changes are temporarily disabled while we stabilize the system.', null, 503);
        return;
    }

    // Accept only POST for safety
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { Http::error('method_not_allowed', 'POST required', null, 405); return; }
    // Gentle per-IP rate limit (UI safety)
    if (!Http::rateLimit('inventory_quick_bridge', 120)) { return; }

    $pidRaw = isset($_POST['_vendID']) ? (string)$_POST['_vendID'] : '';
    $pid    = trim($pidRaw);
    $oid   = isset($_POST['_outletID']) ? (int)$_POST['_outletID'] : 0;
    $oid2  = isset($_POST['_outletID_confirm']) ? (int)$_POST['_outletID_confirm'] : $oid;
    $qtyIn = $_POST['_newQty'] ?? null;
    $qty   = is_numeric($qtyIn) ? (int)$qtyIn : null;
    $staff = isset($_POST['_staffID'])  ? (int)$_POST['_staffID']  : null;
    $csrf  = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';

    // CSRF (best-effort): if session exposes a token, require it to match
    try {
        if (PHP_SAPI !== 'cli' && isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '') {
            if (!is_string($csrf) || $csrf === '' || !hash_equals($_SESSION['csrf_token'], $csrf)) {
                cis_invq_increment('inventory_quick:fail_csrf', 1);
                \Queue\Logger::warn('cis.quick_qty.csrf_invalid', ['meta' => ['ip'=>($_SERVER['REMOTE_ADDR'] ?? ''), 'ua'=>($_SERVER['HTTP_USER_AGENT'] ?? '')], 'request_id' => Http::requestId()]);
                // best-effort DB log
                try {
                    $pdo = \Queue\PdoConnection::instance();
                    $hasLogs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_job_logs'")->fetchColumn();
                    if ($hasLogs) {
                        $msg = json_encode(['event'=>'csrf_invalid','source'=>'cis.quick_qty.bridge'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                        $pdo->prepare('INSERT INTO ls_job_logs (job_id, log_message, created_at) VALUES (0, :msg, NOW())')->execute([':msg'=>$msg]);
                    }
                } catch (\Throwable $e2) { /* ignore */ }
                Http::error('forbidden', 'CSRF token invalid', null, 403); return;
            }
        }
    } catch (\Throwable $e) { /* ignore */ }

    // Validate inputs
    // Validate pid as non-empty string (Vend uses UUIDs). Allow alnum and dashes
    $pidValid = ($pid !== '' && (bool)preg_match('/^[A-Za-z0-9\-]+$/', $pid));
    if (!$pidValid || $oid <= 0 || $qty === null) {
        header('X-Debug-Vars: pid=' . rawurlencode($pid) . '; oid=' . $oid . '; oid2=' . $oid2 . '; qty_in=' . rawurlencode((string)$qtyIn));
        \Queue\Logger::warn('cis.quick_qty.bad_params', ['meta' => ['pid'=>$pid,'oid'=>$oid,'oid2'=>$oid2,'qty_in'=>$qtyIn,'staff'=>$staff,'ip'=>($_SERVER['REMOTE_ADDR'] ?? '')], 'request_id' => Http::requestId()]);
        cis_invq_increment('inventory_quick:fail_bad_params', 1);
        try {
            $pdo = \Queue\PdoConnection::instance();
            $hasLogs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_job_logs'")->fetchColumn();
            if ($hasLogs) {
                $msg = json_encode(['event'=>'validation_failed','reason'=>'bad_params','source'=>'cis.quick_qty.bridge','pid'=>$pid,'oid'=>$oid,'oid2'=>$oid2,'qty_in'=>$qtyIn], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                $pdo->prepare('INSERT INTO ls_job_logs (job_id, log_message, created_at) VALUES (0, :msg, NOW())')->execute([':msg'=>$msg]);
            }
        } catch (\Throwable $e2) { /* ignore */ }
        Http::error('bad_request', 'Missing or invalid parameters', ['pid'=>$pid,'oid'=>$oid,'oid2'=>$oid2,'qty_in'=>$qtyIn]);
        return;
    }
    if ($oid2 !== $oid) {
        header('X-Debug-Vars: oid=' . $oid . '; oid2=' . $oid2);
        \Queue\Logger::warn('cis.quick_qty.outlet_mismatch', ['meta' => ['oid'=>$oid,'oid2'=>$oid2,'pid'=>$pid,'staff'=>$staff], 'request_id' => Http::requestId()]);
        cis_invq_increment('inventory_quick:fail_outlet_mismatch', 1);
        try {
            $pdo = \Queue\PdoConnection::instance();
            $hasLogs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_job_logs'")->fetchColumn();
            if ($hasLogs) {
                $msg = json_encode(['event'=>'validation_failed','reason'=>'outlet_mismatch','source'=>'cis.quick_qty.bridge','pid'=>$pid,'oid'=>$oid,'oid2'=>$oid2], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                $pdo->prepare('INSERT INTO ls_job_logs (job_id, log_message, created_at) VALUES (0, :msg, NOW())')->execute([':msg'=>$msg]);
            }
        } catch (\Throwable $e2) { /* ignore */ }
        Http::error('bad_request', 'Outlet confirmation mismatch', ['outlet'=>$oid,'confirm'=>$oid2]);
        return;
    }
    if ($qty < 0 || $qty > 1000000) {
        header('X-Debug-Vars: qty=' . $qty);
        \Queue\Logger::warn('cis.quick_qty.qty_out_of_bounds', ['meta' => ['qty'=>$qty,'pid'=>$pid,'oid'=>$oid], 'request_id' => Http::requestId()]);
        cis_invq_increment('inventory_quick:fail_qty_bounds', 1);
        try {
            $pdo = \Queue\PdoConnection::instance();
            $hasLogs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_job_logs'")->fetchColumn();
            if ($hasLogs) {
                $msg = json_encode(['event'=>'validation_failed','reason'=>'qty_bounds','source'=>'cis.quick_qty.bridge','pid'=>$pid,'oid'=>$oid,'qty'=>$qty], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                $pdo->prepare('INSERT INTO ls_job_logs (job_id, log_message, created_at) VALUES (0, :msg, NOW())')->execute([':msg'=>$msg]);
            }
        } catch (\Throwable $e2) { /* ignore */ }
        Http::error('bad_request', 'new_qty out of bounds (0..1000000)');
        return;
    }

    // Counters
    cis_invq_increment('inventory_quick:requests_total', 1);
    cis_invq_increment('inventory_quick:mode:queue', 1);

    // Correlate this submission end-to-end
    $traceId = Http::requestId();

    $payload = [
        'op' => 'set',
        'product_id' => $pid,
        'outlet_id' => $oid,
        'target' => $qty,
        'actor_staff_id' => $staff,
        'source' => 'quick_qty',
        'trace_id' => $traceId,
    ];

    $idk = 'invq:' . $pid . ':' . $oid . ':' . $qty;
    $jobId = Repo::addJob('inventory.command', $payload, $idk);
    cis_invq_increment('inventory_quick:queued_total', 1);

    // Structured enqueue log (best-effort) for audit/trace
    try {
        $pdo = \Queue\PdoConnection::instance();
        $hasLogs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_job_logs'")->fetchColumn();
        if ($hasLogs) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $msg = json_encode([
                'event' => 'enqueue',
                'source' => 'cis.quick_qty.bridge',
                'product_id' => $pid,
                'outlet_id' => $oid,
                'qty' => $qty,
                'staff_id' => $staff,
                'idempotency_key' => $idk,
                'trace_id' => $traceId,
                'ip' => $ip,
                'ua' => $ua,
            ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            // Try modern schema first (message/correlation_id), then fall back
            try {
                $stmt = $pdo->prepare('INSERT INTO ls_job_logs (job_id, level, message, correlation_id, created_at) VALUES (:jid, "info", :msg, :cid, NOW())');
                $stmt->execute([':jid' => (int)$jobId, ':msg' => (string)$msg, ':cid' => (string)$traceId]);
            } catch (\Throwable $eModern) {
                // Fallback legacy column names
                $stmt = $pdo->prepare('INSERT INTO ls_job_logs (job_id, log_message, created_at) VALUES (:jid, :msg, NOW())');
                $stmt->execute([':jid' => (int)$jobId, ':msg' => (string)$msg]);
            }
        }
    } catch (\Throwable $e) { /* ignore */ }

    // Optional tiny kick to improve responsiveness (bounded)
    if (Config::getBool('inventory.quick.sync_kick', false)) {
        try { Runner::run(['--limit' => 3, '--type' => 'inventory.command']); } catch (\Throwable $e) { /* ignore */ }
    }

    header('X-Queue-Job-ID: ' . (string)$jobId);
    header('X-Idempotency-Key: ' . $idk);
    header('X-Trace-Id: ' . $traceId);
    if (isset($staff)) { header('X-Actor-Staff-ID: ' . (string)$staff); }
    Http::respond(true, ['job_id' => $jobId, 'mode' => 'queue', 'idempotency_key' => $idk, 'trace_id' => $traceId]);
} catch (\Throwable $e) {
    Http::error('cis_quick_qty_failed', $e->getMessage());
}
