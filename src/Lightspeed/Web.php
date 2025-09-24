<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\PdoConnection;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Config;
use Queue\Http;

final class Web
{
    /** Public helper: spawn a background runner best-effort for given type (or null for any). */
    public static function kick(?string $type = null): void
    {
        self::kickRunnerIfNeeded($type);
    }

    /**
     * Watchdog: detect silent stalls (no jobs completing, stale logs) and auto-recover.
     * Returns [ok, data] for callers to render.
     */
    public static function watchdogRun(): array
    {
        $actions = [];
        $result = [ 'checked_at' => date('c'), 'actions' => &$actions, 'anomalies' => [] ];
        try {
            if (!Config::getBool('queue.watchdog.enabled', true)) { $result['disabled'] = true; return [true, $result]; }
            $pdo = PdoConnection::instance();
            // Snapshot queue
            $pending = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0);
            $working = (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='working' OR status='running'")->fetchColumn() ?: 0);
            // Determine available timestamp columns safely
            $hasCol = static function(string $name) use ($pdo): bool {
                try { $s=$pdo->prepare("SHOW COLUMNS FROM ls_jobs LIKE :c"); $s->execute([':c'=>$name]); return (bool)$s->fetchColumn(); } catch (\Throwable $e) { return false; }
            };
            $hasFinished = $hasCol('finished_at');
            $hasCompleted = $hasCol('completed_at');
            $hasUpdated = $hasCol('updated_at');
            // done in last minute: prefer finished/completed, else fallback to updated_at for done/completed statuses
            $done1m = 0;
            try {
                if ($hasFinished || $hasCompleted) {
                    $parts = [];
                    if ($hasFinished) { $parts[] = "finished_at >= NOW() - INTERVAL 1 MINUTE"; }
                    if ($hasCompleted) { $parts[] = "completed_at >= NOW() - INTERVAL 1 MINUTE"; }
                    $cond = implode(' OR ', $parts);
                    $done1m = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE (status='done' OR status='completed') AND (".$cond.")")->fetchColumn();
                } elseif ($hasUpdated) {
                    $done1m = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE (status='done' OR status='completed') AND updated_at >= NOW() - INTERVAL 1 MINUTE")->fetchColumn();
                }
            } catch (\Throwable $e) { $done1m = 0; }
            // last started at
            $lastStartedAt = null;
            try { $lastStartedAt = (string)($pdo->query("SELECT DATE_FORMAT(IFNULL(MAX(started_at),'0000-00-00 00:00:00'), '%Y-%m-%d %H:%i:%s') FROM ls_jobs WHERE (status='working' OR status='running')")->fetchColumn() ?: ''); } catch (\Throwable $e) { $lastStartedAt = null; }
            // last completed at
            $lastCompletedAt = null;
            try {
                if ($hasFinished || $hasCompleted) {
                    $parts = [];
                    if ($hasFinished) { $parts[] = "IFNULL(MAX(finished_at),'0000-00-00 00:00:00')"; }
                    if ($hasCompleted) { $parts[] = "IFNULL(MAX(completed_at),'0000-00-00 00:00:00')"; }
                    $expr = count($parts) > 1 ? ('GREATEST(' . implode(',', $parts) . ')') : $parts[0];
                    $sql = "SELECT DATE_FORMAT(".$expr.", '%Y-%m-%d %H:%i:%s') FROM ls_jobs WHERE status IN ('done','completed')";
                    $lastCompletedAt = (string)($pdo->query($sql)->fetchColumn() ?: '');
                } elseif ($hasUpdated) {
                    $sql = "SELECT DATE_FORMAT(IFNULL(MAX(updated_at),'0000-00-00 00:00:00'), '%Y-%m-%d %H:%i:%s') FROM ls_jobs WHERE status IN ('done','completed')";
                    $lastCompletedAt = (string)($pdo->query($sql)->fetchColumn() ?: '');
                }
            } catch (\Throwable $e) { $lastCompletedAt = null; }
            // Snapshot webhooks
            $webhookProcAge = null; $webhookRecvAge = null;
            try {
                $webhookProcAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(processed_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
                $webhookRecvAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(received_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
            } catch (\Throwable $e) {}
            // Filesystem indicators
            $base = dirname(__DIR__, 2); $logsDir = $base . '/logs';
            $lockPath = $logsDir . '/worker.lock'; $logPath = $logsDir . '/worker.log';
            $now = time();
            $logMtime = is_file($logPath) ? @filemtime($logPath) : null; $logAge = $logMtime ? ($now - (int)$logMtime) : null;
            $lockMtime = is_file($lockPath) ? @filemtime($lockPath) : null; $lockAge = $lockMtime ? ($now - (int)$lockMtime) : null;

            // Thresholds (configurable)
            $staleLog = (int) (Config::get('queue.watchdog.stale_log_sec', 300) ?? 300);
            $staleStart = (int) (Config::get('queue.watchdog.stale_started_sec', 600) ?? 600);
            $staleWebhook = (int) (Config::get('queue.watchdog.webhook_delay_sec', 900) ?? 900);
            $autoFix = Config::getBool('queue.watchdog.auto_fix', true);

            $anomaly = false;
            // Condition A: backlog with zero completions recently and stale worker signals
            if ($pending > 0 && $done1m === 0) {
                if (($logAge !== null && $logAge >= $staleLog) || ($lockAge !== null && $lockAge >= $staleLog)) {
                    $anomaly = true; $result['anomalies'][] = 'stale_worker_signals';
                }
                // If nothing is currently working recently
                if ($lastStartedAt !== '' && strtotime($lastStartedAt) > 0) {
                    $sinceStart = $now - (int)strtotime($lastStartedAt);
                    if ($sinceStart >= $staleStart) { $anomaly = true; $result['anomalies'][] = 'stale_started_at'; }
                } else {
                    // No started_at seen; still anomalous if pending exists
                    $anomaly = true; $result['anomalies'][] = 'no_started_seen';
                }
            }
            // Condition B: webhook processing stalled
            if ($webhookProcAge !== null && $webhookRecvAge !== null) {
                if ($webhookRecvAge < 86400 && $webhookProcAge >= $staleWebhook) {
                    $anomaly = true; $result['anomalies'][] = 'webhook_processing_stalled';
                }
            }

            // Heartbeat counter (for external monitors to detect stall of the watchdog itself)
            try {
                $bucket = date('Y-m-d H:i:00');
                $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,1,NOW()) ON DUPLICATE KEY UPDATE counter=counter+1, updated_at=NOW()')
                    ->execute([':k' => 'queue_watchdog:heartbeat', ':w' => $bucket]);
            } catch (\Throwable $e) { /* ignore */ }

            if ($anomaly) {
                // Record a health warning row
                try {
                    $detail = [ 'pending' => $pending, 'working' => $working, 'done_last_minute' => $done1m, 'log_age_sec' => $logAge, 'lock_age_sec' => $lockAge, 'webhook_processed_age_sec' => $webhookProcAge, 'webhook_received_age_sec' => $webhookRecvAge ];
                    $pdo->prepare("INSERT INTO webhook_health (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details) VALUES (NOW(), 'queue.worker', 'warning', 0, 1, :d)")
                        ->execute([':d' => json_encode($detail, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
                } catch (\Throwable $e) { /* best-effort */ }
                if ($autoFix) {
                    // Ensure runner+continuous enabled
                    try { Config::set('queue.runner.enabled', true); $actions[] = 'runner_enabled'; } catch (\Throwable $e) {}
                    try { Config::set('vend.queue.continuous.enabled', true); $actions[] = 'continuous_enabled'; } catch (\Throwable $e) {}
                    // Kick runner now
                    try { self::kickRunnerIfNeeded(null); $actions[] = 'runner_kicked'; } catch (\Throwable $e) {}
                    // Raise banner
                    try { \Queue\Degrade::setBanner(true, (string)(Config::get('queue.watchdog.banner_level','warning') ?? 'warning'), 'Queue watchdog auto-recovered the worker. System is catching up.'); $actions[] = 'banner_set'; } catch (\Throwable $e) {}
                }
            }

            $result['queue'] = [ 'pending' => $pending, 'working' => $working, 'done_last_minute' => $done1m, 'last_started_at' => $lastStartedAt ?: null, 'last_completed_at' => $lastCompletedAt ?: null ];
            $result['worker'] = [ 'log_age_sec' => $logAge, 'lock_age_sec' => $lockAge ];
            $result['webhooks'] = [ 'received_age_sec' => $webhookRecvAge, 'processed_age_sec' => $webhookProcAge ];
            return [true, $result];
        } catch (\Throwable $e) {
            return [false, [ 'error' => $e->getMessage(), 'actions' => $actions ]];
        }
    }

    /**
     * Best-effort worker auto-kick: if enabled, and no runner is currently holding the advisory lock,
     * spawn a short-lived runner in the background. Safe guardrails:
     * - gated by vend.queue.auto_kick.enabled (default: false)
     * - checks IS_FREE_LOCK('ls_runner:<type|all>') to avoid overlap with an existing runner
     * - respects vend_queue_runtime_business in Runner (time-budget)
     * - uses --limit to keep each kick bounded
     */
    private static function kickRunnerIfNeeded(?string $type = null): void
    {
        try {
            // Default-on: enable auto-kick unless explicitly disabled in configuration
            if (!Config::getBool('vend.queue.auto_kick.enabled', true)) { return; }
            // If a runner is already active (lock held), skip kicking
            try {
                $pdo = PdoConnection::instance();
                $lkType = $type ?: 'all';
                $key = 'ls_runner:' . $lkType;
                $stmt = $pdo->prepare('SELECT IS_FREE_LOCK(:k) AS free');
                $stmt->execute([':k' => $key]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
                $free = isset($row['free']) ? ((int)$row['free'] === 1) : true;
                if (!$free) { return; }
            } catch (\Throwable $e) {
                // If lock check fails, proceed cautiously — singleflight in Runner will still prevent overlap
            }

            // Resolve runner path
            $base = dirname(__DIR__, 2); // .../assets/services/queue
            $runner = $base . '/bin/run-jobs.php';
            if (!is_file($runner)) { return; }
            $php = (string) (Config::get('php.bin', 'php') ?? 'php');
            $limit = (int) (Config::get('vend.queue.auto_kick.limit', 200) ?? 200);
            if ($limit <= 0) { $limit = 200; }
            $cmd = $php . ' ' . escapeshellarg($runner) . ' --limit=' . $limit;
            if ($type !== null && $type !== '') { $cmd .= ' --type=' . escapeshellarg($type); }

            // Fire-and-forget background spawn; prefer proc_open, fall back to popen/exec
            if (\function_exists('proc_open')) {
                @proc_close(@proc_open($cmd . ' >/dev/null 2>&1 &', [], $pipes));
                return;
            }
            if (\function_exists('popen')) {
                @pclose(@popen($cmd . ' >/dev/null 2>&1 &', 'r'));
                return;
            }
            if (\function_exists('exec')) {
                @exec($cmd . ' >/dev/null 2>&1 &');
                return;
            }
        } catch (\Throwable $e) { /* swallow */ }
    }
    
    /** Pause specific queue type or all by setting vend_queue_pause.* flags */
    public static function pause(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('queue_pause', 10)) return;
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') { $tmp = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; } }
        if (!$in) { $in = $_POST ?: []; }
        $type = isset($in["type"]) ? (string)$in["type"] : '';
        $on = isset($in['on']) ? (bool)$in['on'] : true;
        try {
            if ($type === '' || $type === 'all') {
                // Pause all known types
                $types = ['create_consignment','update_consignment','cancel_consignment','mark_transfer_partial','edit_consignment_lines','add_consignment_products','webhook.event','inventory.command','push_product_update','pull_products','pull_inventory','pull_consignments'];
                foreach ($types as $t) { Config::set('vend_queue_pause.' . $t, $on); }
                Http::respond(true, ['paused_all' => $on]);
            } else {
                Config::set('vend_queue_pause.' . $type, $on);
                Http::respond(true, ['type' => $type, 'paused' => $on]);
            }
        } catch (\Throwable $e) { Http::error('pause_failed', $e->getMessage()); }
    }

    /** Resume queue type(s) by clearing vend_queue_pause.* flags */
    public static function resume(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('queue_resume', 10)) return;
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') { $tmp = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; } }
        if (!$in) { $in = $_POST ?: []; }
        $type = isset($in["type"]) ? (string)$in["type"] : '';
        try {
            if ($type === '' || $type === 'all') {
                $types = ['create_consignment','update_consignment','cancel_consignment','mark_transfer_partial','edit_consignment_lines','add_consignment_products','webhook.event','inventory.command','push_product_update','pull_products','pull_inventory','pull_consignments'];
                foreach ($types as $t) { Config::set('vend_queue_pause.' . $t, false); }
                Http::respond(true, ['resumed_all' => true]);
            } else {
                Config::set('vend_queue_pause.' . $type, false);
                Http::respond(true, ['type' => $type, 'paused' => false]);
            }
        } catch (\Throwable $e) { Http::error('resume_failed', $e->getMessage()); }
    }

    /** Update per-type concurrency caps via vend.queue.max_concurrency.* */
    public static function concurrencyUpdate(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('queue_concurrency_update', 10)) return;
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') { $tmp = json_decode($raw, true); if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; } }
        if (!$in) { $in = $_POST ?: []; }
        $type = isset($in['type']) ? (string)$in['type'] : '';
        $val = isset($in['value']) ? (int)$in['value'] : null;
        if ($type === '' || $val === null || $val < 0) { Http::error('bad_request', 'type and value>=0 required'); return; }
        try {
            Config::set('vend.queue.max_concurrency.' . $type, $val);
            Http::respond(true, ['type' => $type, 'max_concurrency' => $val]);
        } catch (\Throwable $e) { Http::error('concurrency_update_failed', $e->getMessage()); }
    }
    /**
     * Return the canonical list of tables to verify for this service.
     * Includes ls_* (source), cishub_* (standard), and cisq_* (legacy visibility).
     */
    public static function dbTablePlan(): array
    {
        return [
            // Core queue (ls_*)
            ['name' => 'ls_jobs',           'activity' => ['updated_at','created_at','started_at'], 'indexes_any' => [ ['idx_jobs_status_type','idx_status_type'], ['idx_jobs_status_priority'], ['idx_jobs_next','idx_status_next'], ['idx_jobs_lease','idx_leased'] ]],
            ['name' => 'ls_job_logs',       'activity' => ['created_at'], 'indexes_any' => [ ['idx_job','idx_created'] ]],
            ['name' => 'ls_jobs_dlq',       'activity' => ['moved_at','created_at'], 'indexes_any' => [ ['idx_dlq_moved','idx_created'] ]],
            ['name' => 'ls_rate_limits',    'activity' => ['updated_at','window_start'], 'indexes_any' => [ ['PRIMARY'] ]],
            ['name' => 'ls_sync_cursors',   'activity' => ['updated_at'], 'indexes_any' => [ ['PRIMARY'] ]],
            // Webhooks (base)
            ['name' => 'webhook_subscriptions', 'activity' => ['updated_at','last_event_received'], 'indexes_any' => [ ['unique_subscription'] ]],
            ['name' => 'webhook_events',        'activity' => ['received_at','processed_at','updated_at'], 'indexes_any' => [ ['webhook_id'], ['idx_webhook_type_status'], ['idx_received_at'] ]],
            ['name' => 'webhook_stats',         'activity' => ['recorded_at'], 'indexes_any' => [ ['unique_metric_period'], ['idx_time_lookup'] ]],
            ['name' => 'webhook_health',        'activity' => ['check_time'], 'indexes_any' => [ ['idx_type_time'], ['idx_health_status'] ]],
            // Extended (optional)
            ['name' => 'ls_suppliers',              'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_vend_supplier','idx_active'] ]],
            ['name' => 'ls_purchase_orders',        'activity' => ['updated_at','created_at','received_at','ordered_at'], 'indexes_any' => [ ['uniq_vend_po','idx_supplier','idx_outlet_status'] ]],
            ['name' => 'ls_purchase_order_lines',   'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_po_product','idx_po'] ]],
            ['name' => 'ls_stocktakes',             'activity' => ['updated_at','created_at','completed_at','started_at'], 'indexes_any' => [ ['idx_outlet_status'] ]],
            ['name' => 'ls_stocktake_lines',        'activity' => [], 'indexes_any' => [ ['uniq_stocktake_product','idx_stocktake'] ]],
            ['name' => 'ls_returns',                'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['idx_scope_status','idx_supplier','idx_outlets'] ]],
            ['name' => 'ls_return_lines',           'activity' => [], 'indexes_any' => [ ['uniq_return_product','idx_return'] ]],
            // New standard prefix: cishub_* (optional views)
            ['name' => 'cishub_jobs',           'activity' => ['updated_at','created_at','started_at'], 'indexes_any' => [ ['idx_jobs_status_type','idx_status_type'], ['idx_jobs_status_priority'], ['idx_jobs_next','idx_status_next'], ['idx_jobs_lease','idx_leased'] ], 'optional' => true],
            ['name' => 'cishub_job_logs',       'activity' => ['created_at'], 'indexes_any' => [ ['idx_job','idx_created'] ], 'optional' => true],
            ['name' => 'cishub_jobs_dlq',       'activity' => ['moved_at','created_at'], 'indexes_any' => [ ['idx_dlq_moved','idx_created'] ], 'optional' => true],
            ['name' => 'cishub_rate_limits',    'activity' => ['updated_at','window_start'], 'indexes_any' => [ ['PRIMARY'] ], 'optional' => true],
            ['name' => 'cishub_sync_cursors',   'activity' => ['updated_at'], 'indexes_any' => [ ['PRIMARY'] ], 'optional' => true],
            ['name' => 'cishub_webhook_subscriptions', 'activity' => ['updated_at','last_event_received'], 'indexes_any' => [ ['unique_subscription'] ], 'optional' => true],
            ['name' => 'cishub_webhook_events',        'activity' => ['received_at','processed_at','updated_at'], 'indexes_any' => [ ['webhook_id'], ['idx_webhook_type_status'], ['idx_received_at'] ], 'optional' => true],
            ['name' => 'cishub_webhook_stats',         'activity' => ['recorded_at'], 'indexes_any' => [ ['unique_metric_period'], ['idx_time_lookup'] ], 'optional' => true],
            ['name' => 'cishub_webhook_health',        'activity' => ['check_time'], 'indexes_any' => [ ['idx_type_time'], ['idx_health_status'] ], 'optional' => true],
            ['name' => 'cishub_suppliers',              'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_vend_supplier','idx_active'] ], 'optional' => true],
            ['name' => 'cishub_purchase_orders',        'activity' => ['updated_at','created_at','received_at','ordered_at'], 'indexes_any' => [ ['uniq_vend_po','idx_supplier','idx_outlet_status'] ], 'optional' => true],
            ['name' => 'cishub_purchase_order_lines',   'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_po_product','idx_po'] ], 'optional' => true],
            ['name' => 'cishub_stocktakes',             'activity' => ['updated_at','created_at','completed_at','started_at'], 'indexes_any' => [ ['idx_outlet_status'] ], 'optional' => true],
            ['name' => 'cishub_stocktake_lines',        'activity' => [], 'indexes_any' => [ ['uniq_stocktake_product','idx_stocktake'] ], 'optional' => true],
            ['name' => 'cishub_returns',                'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['idx_scope_status','idx_supplier','idx_outlets'] ], 'optional' => true],
            ['name' => 'cishub_return_lines',           'activity' => [], 'indexes_any' => [ ['uniq_return_product','idx_return'] ], 'optional' => true],
            // Legacy prefix: cisq_* (compatibility visibility; optional)
            ['name' => 'cisq_jobs',           'activity' => ['updated_at','created_at','started_at'], 'indexes_any' => [ ['idx_jobs_status_type','idx_status_type'], ['idx_jobs_status_priority'], ['idx_jobs_next','idx_status_next'], ['idx_jobs_lease','idx_leased'] ], 'optional' => true],
            ['name' => 'cisq_job_logs',       'activity' => ['created_at'], 'indexes_any' => [ ['idx_job','idx_created'] ], 'optional' => true],
            ['name' => 'cisq_jobs_dlq',       'activity' => ['moved_at','created_at'], 'indexes_any' => [ ['idx_dlq_moved','idx_created'] ], 'optional' => true],
            ['name' => 'cisq_rate_limits',    'activity' => ['updated_at','window_start'], 'indexes_any' => [ ['PRIMARY'] ], 'optional' => true],
            ['name' => 'cisq_sync_cursors',   'activity' => ['updated_at'], 'indexes_any' => [ ['PRIMARY'] ], 'optional' => true],
            ['name' => 'cisq_webhook_subscriptions', 'activity' => ['updated_at','last_event_received'], 'indexes_any' => [ ['unique_subscription'] ], 'optional' => true],
            ['name' => 'cisq_webhook_events',        'activity' => ['received_at','processed_at','updated_at'], 'indexes_any' => [ ['webhook_id'], ['idx_webhook_type_status'], ['idx_received_at'] ], 'optional' => true],
            ['name' => 'cisq_webhook_stats',         'activity' => ['recorded_at'], 'indexes_any' => [ ['unique_metric_period'], ['idx_time_lookup'] ], 'optional' => true],
            ['name' => 'cisq_webhook_health',        'activity' => ['check_time'], 'indexes_any' => [ ['idx_type_time'], ['idx_health_status'] ], 'optional' => true],
            ['name' => 'cisq_suppliers',              'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_vend_supplier','idx_active'] ], 'optional' => true],
            ['name' => 'cisq_purchase_orders',        'activity' => ['updated_at','created_at','received_at','ordered_at'], 'indexes_any' => [ ['uniq_vend_po','idx_supplier','idx_outlet_status'] ], 'optional' => true],
            ['name' => 'cisq_purchase_order_lines',   'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_po_product','idx_po'] ], 'optional' => true],
            ['name' => 'cisq_stocktakes',             'activity' => ['updated_at','created_at','completed_at','started_at'], 'indexes_any' => [ ['idx_outlet_status'] ], 'optional' => true],
            ['name' => 'cisq_stocktake_lines',        'activity' => [], 'indexes_any' => [ ['uniq_stocktake_product','idx_stocktake'] ], 'optional' => true],
            ['name' => 'cisq_returns',                'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['idx_scope_status','idx_supplier','idx_outlets'] ], 'optional' => true],
            ['name' => 'cisq_return_lines',           'activity' => [], 'indexes_any' => [ ['uniq_return_product','idx_return'] ], 'optional' => true],
            // Transfers domain (optional visibility)
            ['name' => 'transfers',                    'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uniq_transfers_public_id'], ['uniq_transfers_vend_uuid'], ['idx_transfers_status'], ['idx_transfers_type_status'], ['idx_transfers_from_status_date'], ['idx_transfers_to_status_date'], ['idx_transfers_staff'], ['idx_transfers_created'], ['idx_transfers_type_created'], ['idx_transfers_to_created'], ['idx_transfers_vend'], ['idx_transfers_customer'] ], 'optional' => true],
            ['name' => 'transfer_items',               'activity' => ['updated_at'], 'indexes_any' => [ ['uniq_item_transfer_product'], ['idx_item_transfer'], ['idx_item_product'], ['idx_item_confirm'], ['idx_items_outstanding'] ], 'optional' => true],
            ['name' => 'transfer_shipments',           'activity' => ['updated_at','packed_at','received_at'], 'indexes_any' => [ ['idx_shipments_transfer'], ['idx_shipments_status'], ['idx_shipments_mode'], ['idx_shipments_packed_at'], ['idx_shipments_received_at'] ], 'optional' => true],
            ['name' => 'transfer_parcels',             'activity' => ['updated_at','received_at'], 'indexes_any' => [ ['uniq_parcel_boxnum'], ['idx_parcel_shipment','idx_shipment_id'], ['idx_parcel_tracking','idx_tracking'] ], 'optional' => true],
            ['name' => 'transfer_parcel_items',        'activity' => ['created_at','locked_at'], 'indexes_any' => [ ['uniq_parcel_item'], ['idx_tpi_parcel','idx_parcel_id'], ['idx_tpi_item','idx_item_id'] ], 'optional' => true],
            ['name' => 'transfer_shipment_items',      'activity' => [], 'indexes_any' => [ ['uniq_shipment_item'], ['idx_tsi_shipment'], ['idx_tsi_item'] ], 'optional' => true],
            ['name' => 'transfer_shipment_notes',      'activity' => ['created_at'], 'indexes_any' => [ ['idx_shipment'] ], 'optional' => true],
            ['name' => 'transfer_notes',               'activity' => ['created_at'], 'indexes_any' => [ ['transfer_id'] ], 'optional' => true],
            ['name' => 'transfer_logs',                'activity' => ['created_at'], 'indexes_any' => [ ['idx_logs_transfer','idx_transfer_time','idx_logs_transfer_created'], ['idx_logs_shipment'], ['idx_logs_item'], ['idx_logs_parcel'], ['idx_logs_staff'], ['idx_logs_event'], ['idx_logs_source'], ['idx_logs_customer'] ], 'optional' => true],
            ['name' => 'transfer_audit_log',           'activity' => ['created_at'], 'indexes_any' => [ ['idx_transfer_id'], ['idx_vend_consignment'], ['idx_action_status'], ['idx_actor'], ['idx_outlet_from_to'], ['idx_created_at'], ['idx_error_tracking'], ['idx_transfer_pk'], ['idx_vend_transfer'], ['idx_entity'], ['idx_audit_errors'] ], 'optional' => true],
            ['name' => 'transfer_queue_metrics',       'activity' => ['recorded_at'], 'indexes_any' => [ ['idx_metric_type_recorded'], ['idx_queue_job_type'], ['idx_outlet_metrics'], ['idx_worker_metrics'], ['idx_cleanup_old_metrics'] ], 'optional' => true],
            ['name' => 'transfer_validation_cache',    'activity' => ['updated_at','created_at','expires_at'], 'indexes_any' => [ ['uniq_cache_key'], ['idx_outlet_from_to'], ['idx_status_expires'], ['idx_requires_approval'], ['idx_cleanup_expired'] ], 'optional' => true],
            ['name' => 'transfer_configurations',      'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['uk_name'], ['idx_preset'], ['idx_active'] ], 'optional' => true],
            ['name' => 'transfer_executions',          'activity' => ['created_at','completed_at'], 'indexes_any' => [ ['fk_config'], ['idx_status'], ['idx_created'] ], 'optional' => true],
            ['name' => 'transfer_allocations',         'activity' => ['created_at'], 'indexes_any' => [ ['fk_execution'], ['idx_product'], ['idx_allocation_product_date'] ], 'optional' => true],
            ['name' => 'transfer_discrepancies',       'activity' => ['updated_at','created_at'], 'indexes_any' => [ ['idx_td_transfer'], ['idx_td_item'], ['idx_td_status'], ['idx_td_product'] ], 'optional' => true],
        ];
    }

    /**
     * Compute DB sanity data without emitting a response. Returns [ok, data].
     */
    public static function dbSanityData(): array
    {
        $ok = true; $out = [ 'checked_at' => date('c'), 'db' => 'unknown', 'tables' => [] ];
        try {
            $pdo = PdoConnection::instance();
            $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            $out['db'] = $dbName;

            $exists = function(string $t) use ($pdo): bool {
                try { return (bool)$pdo->query("SHOW TABLES LIKE '" . str_replace("'", "''", $t) . "'")->fetchColumn(); } catch (\Throwable $e) { return false; }
            };
            $rowCount = function(string $t) use ($pdo, $dbName) {
                try {
                    $stmt = $pdo->prepare('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t');
                    $stmt->execute([':db'=>$dbName, ':t'=>$t]);
                    $val = $stmt->fetchColumn();
                    if ($val !== false && $val !== null) { return (int)$val; }
                } catch (\Throwable $e) {}
                try { return (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(); } catch (\Throwable $e) { return null; }
            };
            $lastActivity = function(string $t, array $cols) use ($pdo) {
                foreach ($cols as $c) {
                    try { $val = $pdo->query("SELECT MAX(`{$c}`) FROM `{$t}`")->fetchColumn(); if ($val) return (string)$val; } catch (\Throwable $e) {}
                }
                return null;
            };
            $hasAnyIndex = function(string $t, array $names) use ($pdo): bool {
                try {
                    $idx = $pdo->prepare('SHOW INDEX FROM `' . $t . '`');
                    $idx->execute();
                    $rows = $idx->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    $present = array_unique(array_map(fn($r) => (string)($r['Key_name'] ?? ''), $rows));
                    foreach ($names as $n) { if (in_array($n, $present, true)) return true; }
                } catch (\Throwable $e) {}
                return false;
            };
            $getColumns = function(string $t) use ($pdo): array {
                try {
                    $cols = $pdo->prepare('SHOW COLUMNS FROM `' . $t . '`');
                    $cols->execute();
                    $rows = $cols->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    return array_map(fn($r) => (string)($r['Field'] ?? ''), $rows);
                } catch (\Throwable $e) { return []; }
            };

            foreach (self::dbTablePlan() as $item) {
                $t = (string)$item['name'];
                $optional = (bool)($item['optional'] ?? false);
                $entry = [ 'exists' => false, 'row_count' => null, 'last_activity' => null, 'indexes' => [], 'optional' => $optional ];
                $present = $exists($t);
                $entry['exists'] = $present;
                if (!$present) { if (!$optional) { $ok = false; } $out['tables'][$t] = $entry; continue; }
                $entry['row_count'] = $rowCount($t);
                $entry['last_activity'] = $lastActivity($t, (array)($item['activity'] ?? []));
                $idxSets = (array)($item['indexes_any'] ?? []);
                $legacyLsJobs = false;
                if ($t === 'ls_jobs') {
                    $colNames = $getColumns($t);
                    $legacyLsJobs = in_array('job_id', $colNames, true) && !in_array('id', $colNames, true);
                }
                foreach ($idxSets as $set) {
                    $names = (array)$set; $has = $hasAnyIndex($t, $names);
                    $key = implode('|', $names);
                    // Legacy compatibility: if ls_jobs is legacy schema without next/lease columns, do not fail these checks
                    if (!$has && $legacyLsJobs && ($key === 'idx_jobs_next|idx_status_next' || $key === 'idx_jobs_lease|idx_leased')) {
                        $entry['indexes'][$key] = 'ok';
                        // do not mark $ok=false for this legacy case
                        continue;
                    }
                    $entry['indexes'][$key] = $has ? 'ok' : 'missing';
                    if (!$has && !$optional) { $ok = false; }
                }
                $out['tables'][$t] = $entry;
            }

            // Quick write-probe on ls_rate_limits to ensure writes are possible without risk
            try {
                $bucket = date('Y-m-d H:i:00');
                $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,1,NOW()) ON DUPLICATE KEY UPDATE counter=counter+1, updated_at=NOW()')->execute([':k'=>'db_sanity_probe',':w'=>$bucket]);
                $out['write_probe'] = 'ok';
            } catch (\Throwable $e) { $ok = false; $out['write_probe'] = 'failed'; $out['write_probe_error'] = $e->getMessage(); }

            // Webhook freshness summary (if tables exist)
            try {
                if ($exists('webhook_events')) {
                    $lastRecvAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(received_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
                    $lastProcAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(processed_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
                    $out['webhooks'] = [ 'last_event_age_seconds' => $lastRecvAge, 'last_processed_age_seconds' => $lastProcAge ];
                }
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            $ok = false; $out['error'] = $e->getMessage();
        }
        return [$ok, $out];
    }
    /**
     * DB Sanity Checker: verifies presence of core tables, key indexes, and recent activity.
     * Auth-protected; safe for production. Avoids heavy COUNT(*) by using information_schema when possible.
     */
    public static function dbSanity(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('db_sanity', 10)) return;
        [$ok, $out] = self::dbSanityData();
        Http::respond($ok, $out + [ 'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/db.sanity.php' ]);
    }

    /**
     * Prefix Migration: create standard cishub_* views pointing to existing tables.
     * Strategy: non-disruptive (views). Does not rename physical tables or alter FKs.
     * Body JSON optional: { "dry_run": bool }
     */
    public static function migratePrefix(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('prefix_migrate', 3)) return;
        // Safety guard: disabled by default. Enable only with explicit config flag.
        if (!\Queue\Config::getBool('db.allow_prefix_migration', false)) {
            Http::error('forbidden', 'prefix_migration_disabled', null, 403);
            return;
        }
        // Accept JSON body or form POST fallback (dry_run=1)
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
        }
        if (!$in) { $in = $_POST ?: []; }
        $dry = (bool)($in['dry_run'] ?? ($in['dryrun'] ?? ($in['simulate'] ?? false)));
        $plan = [
            // core
            'ls_jobs' => 'cishub_jobs',
            'ls_job_logs' => 'cishub_job_logs',
            'ls_jobs_dlq' => 'cishub_jobs_dlq',
            'ls_rate_limits' => 'cishub_rate_limits',
            'ls_sync_cursors' => 'cishub_sync_cursors',
            // webhooks
            'webhook_subscriptions' => 'cishub_webhook_subscriptions',
            'webhook_events' => 'cishub_webhook_events',
            'webhook_stats' => 'cishub_webhook_stats',
            'webhook_health' => 'cishub_webhook_health',
            // extended
            'ls_suppliers' => 'cishub_suppliers',
            'ls_purchase_orders' => 'cishub_purchase_orders',
            'ls_purchase_order_lines' => 'cishub_purchase_order_lines',
            'ls_stocktakes' => 'cishub_stocktakes',
            'ls_stocktake_lines' => 'cishub_stocktake_lines',
            'ls_returns' => 'cishub_returns',
            'ls_return_lines' => 'cishub_return_lines',
        ];
        $results = [];
        try {
            $pdo = PdoConnection::instance();
            $dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            $hasObj = function(string $name) use ($pdo, $dbName): bool {
                try {
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t');
                    $stmt->execute([':db'=>$dbName, ':t'=>$name]);
                    return ((int)$stmt->fetchColumn()) > 0;
                } catch (\Throwable $e) { return false; }
            };
            foreach ($plan as $old => $new) {
                $status = ['old' => $old, 'new' => $new, 'action' => null, 'ok' => false, 'message' => ''];
                $oldExists = $hasObj($old);
                $newExists = $hasObj($new);
                if (!$oldExists) { $status['action'] = 'skip'; $status['message'] = 'source_missing'; $results[] = $status; continue; }
                if ($newExists) { $status['action'] = 'noop'; $status['ok'] = true; $status['message'] = 'new_exists'; $results[] = $status; continue; }
                $status['action'] = 'create_view';
                $sql = "CREATE OR REPLACE VIEW `{$new}` AS SELECT * FROM `{$old}`";
                if ($dry) { $status['ok'] = true; $status['message'] = 'dry_run'; $results[] = $status; continue; }
                try { $pdo->exec($sql); $status['ok'] = true; $status['message'] = 'created'; }
                catch (\Throwable $e) { $status['ok'] = false; $status['message'] = 'error:' . $e->getMessage(); }
                $results[] = $status;
            }
            Http::respond(true, ['dry_run' => $dry, 'results' => $results]);
        } catch (\Throwable $e) {
            Http::error('prefix_migration_failed', $e->getMessage(), ['results' => $results]);
        }
    }
    /** Health: DB, token, queue counts, cursors, webhooks summary */
    public static function health(): void
    {
        $db = 'down';
        try { PdoConnection::instance()->query('SELECT 1'); $db = 'ok'; } catch (\Throwable $e) {}
        $exp = (int) (Config::get('vend_token_expires_at', 0) ?? 0);
        $left = $exp - time();
        $counts = ['pending'=>0,'working'=>0,'failed'=>0];
        $dlq = 0; $oldest = 0; $longest = 0; $cursorStatus = [];
        try {
            $pdo = PdoConnection::instance();
            $counts['pending'] = (int)$pdo->query("SELECT COUNT(*) c FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $counts['working'] = (int)$pdo->query("SELECT COUNT(*) c FROM ls_jobs WHERE status='working'")->fetchColumn();
            $counts['failed']  = (int)$pdo->query("SELECT COUNT(*) c FROM ls_jobs WHERE status='failed'")->fetchColumn();
            $dlq = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs_dlq")->fetchColumn();
            $oldest = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()),0) FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $longest = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(started_at), NOW()),0) FROM ls_jobs WHERE status='working'")->fetchColumn();

            $entities = [ 'products' => 'ls_products', 'inventory' => 'ls_inventory', 'consignments' => 'ls_consignments' ];
            foreach ($entities as $entity => $table) {
                try {
                    $age = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(updated_at), NOW()),0) FROM {$table}")->fetchColumn();
                    $rows15 = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
                    $cursorStatus[$entity] = [ 'age_seconds' => $age, 'rows_15m' => $rows15 ];
                } catch (\Throwable $e) {}
            }
            try {
                $subsActive = (int)$pdo->query("SELECT COUNT(*) FROM webhook_subscriptions WHERE is_active=1")->fetchColumn();
                $lastEventAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(received_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
                $eventsToday = (int)$pdo->query("SELECT COUNT(*) FROM webhook_events WHERE received_at >= CURRENT_DATE")->fetchColumn();
                $processedToday = (int)$pdo->query("SELECT COUNT(*) FROM webhook_events WHERE processed_at >= CURRENT_DATE")->fetchColumn();
                $lastProcessedAge = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(processed_at), NOW()), 999999) FROM webhook_events")->fetchColumn();
                $cursorStatus['webhooks'] = [ 'subscriptions_active' => $subsActive, 'last_event_age_seconds' => $lastEventAge, 'events_today' => $eventsToday, 'events_processed_today' => $processedToday, 'last_processed_age_seconds' => $lastProcessedAge ];
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) {}
        // Minimal flags snapshot for ops visibility
        $flags = [];
        try {
            $flags = [
                'vend.queue.kill_all' => (bool) Config::getBool('vend.queue.kill_all', false),
                'inventory.kill_all' => (bool) Config::getBool('inventory.kill_all', false),
                'vend_queue_pause.inventory.command' => (bool) Config::getBool('vend_queue_pause.inventory.command', false),
                'LS_WEBHOOKS_ENABLED' => (bool) Config::getBool('LS_WEBHOOKS_ENABLED', true),
                // Webhook auth bypass/open-mode (effective state considering optional until)
                'vend.webhook.open_mode' => (bool) Config::getBool('vend.webhook.open_mode', false),
                'vend.webhook.open_mode_until' => (int) (Config::get('vend.webhook.open_mode_until', 0) ?? 0),
                'webhook.auth.disabled' => (bool) Config::getBool('webhook.auth.disabled', false),
                // Realtime + auto-kick quality-of-life toggles
                'vend.webhook.realtime' => (bool) Config::getBool('vend.webhook.realtime', true),
                'vend.queue.auto_kick.enabled' => (bool) Config::getBool('vend.queue.auto_kick.enabled', true),
            ];
        } catch (\Throwable $e) { /* ignore flag fetch errors */ }
        Http::respond(true, [ 'db'=>$db, 'token_expires_in'=>$left, 'jobs'=>$counts, 'dlq_count'=>$dlq, 'oldest_pending_age_sec'=>$oldest, 'longest_working_age_sec'=>$longest, 'cursor_status'=>$cursorStatus, 'flags'=>$flags ]);
    }

    /** Enqueue a job (create_consignment|update_consignment|push_product_update|...) */
    public static function job(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('job')) return;
        // Accept JSON body or form POST fallback (payload may be JSON string)
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
        }
        if (!$in) { $in = $_POST ?: []; }
        $type = isset($in['type']) ? (string)$in['type'] : '';
        $payload = [];
        if (isset($in['payload'])) {
            if (is_array($in['payload'])) { $payload = $in['payload']; }
            elseif (is_string($in['payload']) && $in['payload'] !== '') { $dec = json_decode($in['payload'], true); if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) { $payload = $dec; } }
        }
        $idk = isset($in['idempotency_key']) ? (string)$in['idempotency_key'] : null;
    $allowed = ['create_consignment','update_consignment','cancel_consignment','mark_transfer_partial','edit_consignment_lines','add_consignment_products','push_product_update','inventory.command'];
        if ($type === '' || !in_array($type, $allowed, true)) { Http::error('bad_request','type invalid or missing',[ 'allowed' => $allowed ]); return; }
        if ($idk !== null && strlen($idk) > 128) { Http::error('bad_request', 'idempotency_key too long', ['max' => 128]); return; }
        $id = Repo::addJob($type, $payload, $idk);
        Http::respond(true, ['id'=>$id]);
    }

    /** Transfer log endpoint: write a log row into transfer_logs table (if present). Auth required. */
    public static function transferLog(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('transfer_log', 60)) return;
        $raw = file_get_contents('php://input') ?: '';
        $in = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
        }
        if (!$in) { $in = $_POST ?: []; }
        $tid = (int)($in['transfer_id'] ?? 0);
        $event = trim((string)($in['event_code'] ?? ''));
        $message = (string)($in['message'] ?? '');
        $source = (string)($in['source'] ?? 'ui');
        $actor = isset($in['actor_user_id']) ? (int)$in['actor_user_id'] : null;
        $role = isset($in['actor_role']) ? (string)$in['actor_role'] : null;
        $shipmentId = isset($in['shipment_id']) ? (int)$in['shipment_id'] : null;
        $itemId = isset($in['item_id']) ? (int)$in['item_id'] : null;
        $parcelId = isset($in['parcel_id']) ? (int)$in['parcel_id'] : null;
        $customerId = isset($in['customer_id']) ? (int)$in['customer_id'] : null;
        $traceId = isset($in['trace_id']) ? (string)$in['trace_id'] : null;
        $extras = $in['extras'] ?? null; // array or JSON string
        if ($tid <= 0 || $event === '') { Http::error('bad_request','transfer_id and event_code required'); return; }
        try {
            $pdo = PdoConnection::instance();
            $has = (bool)$pdo->query("SHOW TABLES LIKE 'transfer_logs'")->fetchColumn();
            if (!$has) { Http::respond(true, ['noop' => true, 'reason' => 'table_missing']); return; }
            // Discover available columns and build a dynamic insert
            $cols = $pdo->query('SHOW COLUMNS FROM transfer_logs')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $allowed = [
                'transfer_id' => $tid,
                'event_code' => $event,
                'message' => $message,
                'source' => $source,
                'actor_user_id' => $actor,
                'actor_role' => $role,
                'shipment_id' => $shipmentId,
                'item_id' => $itemId,
                'parcel_id' => $parcelId,
                'customer_id' => $customerId,
                'trace_id' => $traceId,
                'extras' => is_array($extras) ? json_encode($extras, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : (is_string($extras) ? $extras : null),
            ];
            $use = [];$params = [];
            foreach ($allowed as $k => $v) {
                if (in_array($k, $cols, true)) { $use[$k] = $v; }
            }
            // created_at optional
            $sqlCols = array_keys($use);
            $place = array_map(fn($c) => ':' . $c, $sqlCols);
            $sql = 'INSERT INTO transfer_logs (' . implode(',', $sqlCols) . ', created_at) VALUES (' . implode(',', $place) . ', NOW())';
            $stmt = $pdo->prepare($sql);
            foreach ($use as $k => $v) { $params[':' . $k] = $v; }
            $stmt->execute($params);
            Http::respond(true, ['inserted' => 1]);
        } catch (\Throwable $e) {
            Http::error('transfer_log_failed', $e->getMessage());
        }
    }

    /** Force OAuth refresh using refresh_token */
    public static function manualRefresh(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('manual_refresh_token')) return;
        $new = OAuthClient::refresh((string)(Config::get('vend_refresh_token','') ?? ''));
        $exp = (int) (Config::get('vend_token_expires_at', 0) ?? 0);
        Http::respond($new !== '', ['expires_at'=>$exp, 'expires_in'=>$exp - time()]);
    }

    /** Vend webhook receiver with HMAC verification and metric updates */
    public static function webhook(): void
    {
        Http::commonJsonHeaders();
        if (!Config::getBool('LS_WEBHOOKS_ENABLED', true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>['code'=>'disabled']]); return; }
    // Per Lightspeed docs, the signature uses the application's client_secret as HMAC key.
    // Support either an explicit vend_webhook_secret or fall back to vend.client_secret.
    $shared = (string) (Config::get('vend_webhook_secret','') ?? '');
    if ($shared === '') { $shared = (string) (Config::get('vend.client_secret','') ?? ''); }
        $sharedPrev = (string) (Config::get('vend_webhook_secret_prev','') ?? '');
        $sharedPrevExp = (int) (Config::get('vend_webhook_secret_prev_expires_at', 0) ?? 0);
        // Optional open/bypass mode: allow all requests without signature verification when enabled
        // Safe by default (disabled). You can enable via configuration: vend.webhook.open_mode=true
        // Optionally time-limit with vend.webhook.open_mode_until (epoch seconds)
        $openMode = Config::getBool('vend.webhook.open_mode', false) || Config::getBool('webhook.auth.disabled', false);
        $openModeUntil = (int) (Config::get('vend.webhook.open_mode_until', 0) ?? 0);
        $openActive = $openMode && ($openModeUntil === 0 || time() <= $openModeUntil);
    $timestamp = $_SERVER['HTTP_X_LS_TIMESTAMP'] ?? '';
    $authState = 'none'; // none|verified|mismatch|stale|open
        // Support multiple signature header variants per docs/history
        $hdrSignature = $_SERVER['HTTP_X_SIGNATURE'] 
            ?? $_SERVER['HTTP_X_LS_SIGNATURE'] 
            ?? $_SERVER['HTTP_X_VEND_SIGNATURE'] 
            ?? '';
        $body = file_get_contents('php://input') ?: '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        // Parse form-encoded bodies (per docs: application/x-www-form-urlencoded with 'payload' JSON)
        $in = [];
        $rawPayload = $body; // store as received
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
            $form = [];
            parse_str($body, $form);
            $payloadStr = isset($form['payload']) ? (string)$form['payload'] : '';
            if ($payloadStr !== '') {
                $decoded = json_decode($payloadStr, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) { $in = $decoded; }
            }
            // keep full form as headers metadata if needed
        } else {
            // Try JSON
            $maybe = json_decode($body ?: '[]', true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($maybe)) { $in = $maybe; }
        }

        $webhookIdHeader = $_SERVER['HTTP_X_LS_WEBHOOK_ID'] ?? null;
        // Signature verification: prefer X-Signature (signature=...,algorithm=HMAC-SHA256) using body-only; fallback to legacy timestamp.body
        if (!$openActive && $shared !== '') {
            if ($timestamp !== '' && abs(time() - (int)$timestamp) > 300) {
                // Soft-fail: record but continue processing
                $authState = 'stale';
                try {
                    $pdo = PdoConnection::instance(); $wid = $webhookIdHeader ?: sha1(((string)$timestamp) . '.' . $body);
                    $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    $pdo->prepare("INSERT INTO webhook_health (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details) VALUES (NOW(), 'vend.webhook', 'warning', 0, 1, JSON_OBJECT('reason','stale_timestamp'))")->execute();
                    $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), 'vend.webhook', 'failed_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1")->execute();
                } catch (\Throwable $e) {}
            }
            // Parse X-Signature: may be "signature=...,algorithm=HMAC-SHA256" or raw value
            $sigVal = $hdrSignature;
            $algo = 'HMAC-SHA256';
            if (strpos($hdrSignature, 'signature=') !== false) {
                $parts = [];
                foreach (explode(',', $hdrSignature) as $kv) {
                    $kvp = array_map('trim', explode('=', $kv, 2));
                    if (count($kvp) === 2) { $parts[strtolower($kvp[0])] = trim($kvp[1], " \"'"); }
                }
                $sigVal = $parts['signature'] ?? '';
                $algo = strtoupper($parts['algorithm'] ?? 'HMAC-SHA256');
            }
            $candidates = [];
            if ($algo === 'HMAC-SHA256') {
                // Body-only per docs
                $candidates[] = base64_encode(hash_hmac('sha256', $body, $shared, true));
                $candidates[] = hash_hmac('sha256', $body, $shared, false); // hex
                // Legacy variant: timestamp.body used in older implementations
                if ($timestamp !== '') {
                    $candidates[] = base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $shared, true));
                    $candidates[] = hash_hmac('sha256', $timestamp . '.' . $body, $shared, false);
                }
                // Previous secret during rotation
                if ($sharedPrev !== '' && $sharedPrevExp > 0 && time() <= $sharedPrevExp) {
                    $candidates[] = base64_encode(hash_hmac('sha256', $body, $sharedPrev, true));
                    $candidates[] = hash_hmac('sha256', $body, $sharedPrev, false);
                    if ($timestamp !== '') {
                        $candidates[] = base64_encode(hash_hmac('sha256', $timestamp . '.' . $body, $sharedPrev, true));
                        $candidates[] = hash_hmac('sha256', $timestamp . '.' . $body, $sharedPrev, false);
                    }
                }
            }
            $ok = false;
            foreach ($candidates as $cand) { if (is_string($sigVal) && $sigVal !== '' && hash_equals($sigVal, $cand)) { $ok = true; break; } }
            if (!$ok) {
                // Soft-fail: note mismatch but continue processing
                $authState = $authState === 'stale' ? 'stale' : 'mismatch';
                try {
                    $pdo = PdoConnection::instance();
                    $pdo->prepare("INSERT INTO webhook_health (check_time, webhook_type, health_status, response_time_ms, consecutive_failures, health_details) VALUES (NOW(), 'vend.webhook', 'warning', 0, 1, JSON_OBJECT('reason','signature_mismatch'))")->execute();
                    $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), 'vend.webhook', 'failed_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1")->execute();
                } catch (\Throwable $e) {}
            } else {
                $authState = 'verified';
            }
        }
        if ($openActive) {
            // Signal in response and captured headers that auth was bypassed intentionally (open mode)
            $authState = 'open';
        }
        if ($authState !== 'none') {
            header('X-Webhook-Auth: ' . $authState);
        }
        $type = (string) ($in['type'] ?? '');
        if ($type === '') {
            $hdrType = $_SERVER['HTTP_X_LS_EVENT_TYPE'] ?? ($_SERVER['HTTP_X_LS_TOPIC'] ?? ($_SERVER['HTTP_X_VEND_TOPIC'] ?? ''));
            $type = (string)$hdrType;
        }
        try {
            $pdo = PdoConnection::instance();
            $headers = [];
            foreach ($_SERVER as $k=>$v) { if (strpos($k, 'HTTP_') === 0 || in_array($k, ['CONTENT_TYPE','CONTENT_LENGTH'], true)) { $headers[$k] = is_string($v) ? $v : json_encode($v); } }
            $webhookId = $_SERVER['HTTP_X_LS_WEBHOOK_ID'] ?? sha1(((string)$timestamp) . '.' . $body);
            $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $payloadJson = json_encode($in, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headersJson = json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $ins = $pdo->prepare('INSERT INTO webhook_events (webhook_id, webhook_type, payload, raw_payload, source_ip, user_agent, headers, status, received_at, created_at, updated_at) VALUES (:id,:type,:pl,:raw,:ip,:ua,:hd,\'received\', NOW(), NOW(), NOW())');
            $eventDbId = null;
            try {
                $ins->execute([':id'=>$webhookId, ':type'=>$type, ':pl'=>$payloadJson, ':raw'=>$rawPayload, ':ip'=>$ip, ':ua'=>$ua, ':hd'=>$headersJson]);
                $eventDbId = (int)$pdo->lastInsertId();
            } catch (\Throwable $e) { /* ignore */ }
            try {
                $endpointUrl = 'https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php';
                $upd = $pdo->prepare("UPDATE webhook_subscriptions SET events_received_today = IF(DATE(IFNULL(last_event_received, NOW()))=CURRENT_DATE, events_received_today+1, 1), events_received_total = events_received_total+1, last_event_received=NOW(), updated_at=NOW(), health_status='healthy', health_message=NULL WHERE is_active=1 AND source_system='vend' AND (event_type = :t OR :t LIKE REPLACE(event_type,'*','%')) AND endpoint_url=:u");
                $upd->execute([':t'=>$type, ':u'=>$endpointUrl]);
            } catch (\Throwable $e) {}
            try {
                $stmt = $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), :t, 'received_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1");
                $stmt->execute([':t'=>$type]);
            } catch (\Throwable $e) {}
            // Optional queue handoff for async processing
            try {
                if (Config::getBool('vend.webhook.enqueue', true)) {
                    $payload = [
                        'event_db_id' => $eventDbId,
                        'webhook_id' => $webhookId,
                        'webhook_type' => $type,
                    ];
                    $idk = 'webhook:' . $webhookId;
                    $jobId = Repo::addJob('webhook.event', $payload, $idk);
                    // mark event as processing and store queue_job_id
                    try {
                        $upd = $pdo->prepare("UPDATE webhook_events SET status='processing', queue_job_id=:jid, updated_at=NOW() WHERE webhook_id=:wid");
                        $upd->execute([':jid' => (string)$jobId, ':wid' => $webhookId]);
                    } catch (\Throwable $e) { /* ignore */ }
                    // Optional: auto-kick a background worker to process webhook jobs immediately
                    self::kickRunnerIfNeeded('webhook.event');
                }
            } catch (\Throwable $e) { /* ignore enqueue errors */ }

            // Real-time inline processing (default ON). Mirrors Runner::process('webhook.event') fast path.
            try {
                if (Config::getBool('vend.webhook.realtime', true)) {
                    // Determine effective event type and payload as Runner would
                    $pdo = PdoConnection::instance();
                    $etype = $type !== '' ? $type : 'vend.webhook';
                    $row = null; $recvTs = null;
                    try {
                        $st = $pdo->prepare('SELECT webhook_type, payload, received_at FROM webhook_events WHERE webhook_id = :wid LIMIT 1');
                        $st->execute([':wid' => $webhookId]);
                        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                    } catch (\Throwable $e) { /* swallow */ }
                    if ($row) {
                        $etype = $etype !== '' ? $etype : (string)($row['webhook_type'] ?? $etype);
                        $eventPayload = [];
                        try { $eventPayload = json_decode((string)($row['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { $eventPayload = []; }
                        // Fan-out routing (match Runner mapping) if enabled
                        if (\Queue\Config::getBool('webhook.fanout.enabled', true)) {
                            $routes = [
                                'product.update' => 'sync_product',
                                'inventory.update' => 'sync_inventory',
                                'customer.update' => 'sync_customer',
                                'sale.update' => 'sync_sale',
                            ];
                            $target = $routes[$etype] ?? null;
                            if ($target !== null && \Queue\Config::getBool('webhook.fanout.enable.' . str_replace('sync_','',$target), true)) {
                                $primaryId = $eventPayload['id']
                                    ?? ($eventPayload['product']['id'] ?? null)
                                    ?? ($eventPayload['customer']['id'] ?? null)
                                    ?? ($eventPayload['sale']['id'] ?? null)
                                    ?? ($eventPayload['inventory']['product_id'] ?? null);
                                $childPayload = [
                                    'webhook_id' => $webhookId,
                                    'webhook_type' => $etype,
                                    'entity_id' => $primaryId,
                                    'full' => $eventPayload,
                                ];
                                $idk2 = 'fanout:' . $etype . ':' . $webhookId;
                                try { Repo::addJob($target, $childPayload, $idk2); } catch (\Throwable $e) { /* ignore */ }
                            }
                        }
                        // Mark the event as completed right now
                        try {
                            $upd = $pdo->prepare("UPDATE webhook_events SET status='completed', processed_at = IFNULL(processed_at, NOW()), processing_attempts = processing_attempts + 1, updated_at = NOW() WHERE webhook_id = :wid");
                            $upd->execute([':wid' => $webhookId]);
                        } catch (\Throwable $e) { /* ignore */ }
                        // Metrics: processed_count + processing_time
                        try {
                            $m = $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), :t, 'processed_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1");
                            $m->execute([':t' => $etype]);
                            if (!empty($row['received_at'])) {
                                $recvTs = strtotime((string)$row['received_at']);
                                if ($recvTs) {
                                    $durMs = (int) round((microtime(true) - $recvTs) * 1000);
                                    $s1 = $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), :t, 'processing_time_sum_ms', :v, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + VALUES(metric_value)");
                                    $s1->execute([':t' => $etype, ':v' => $durMs]);
                                    $s2 = $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), :t, 'processing_time_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1");
                                    $s2->execute([':t' => $etype]);
                                }
                            }
                        } catch (\Throwable $e) { /* ignore */ }
                    }
                }
            } catch (\Throwable $e) { /* ignore realtime path errors */ }
        } catch (\Throwable $e) {}
        // Optionally return 204 No Content to minimize response size and meet 5s ack guidance
        if (Config::getBool('webhook.respond_204', false)) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(204);
            echo '';
            return;
        }
        Http::respond(true, ['received' => true, 'type' => $type]);
    }

    /** Metrics: Prometheus-style text output with guards */
    public static function metrics(): void
    {
        Http::commonTextHeaders(); header('Content-Type: text/plain; charset=utf-8');
        try {
            $pdo = PdoConnection::instance();
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $working = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='working'")->fetchColumn();
            $failed  = (int)$pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='failed'")->fetchColumn();
            $dlq     = (int)$pdo->query('SELECT COUNT(*) FROM ls_jobs_dlq')->fetchColumn();
            $oldest  = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()),0) FROM ls_jobs WHERE status='pending'")->fetchColumn();
            $longest = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(started_at), NOW()),0) FROM ls_jobs WHERE status='working'")->fetchColumn();
            echo "ls_jobs_pending_total {$pending}\n";
            echo "ls_jobs_working_total {$working}\n";
            echo "ls_jobs_failed_total {$failed}\n";
            echo "ls_jobs_dlq_total {$dlq}\n";
            echo "ls_oldest_pending_age_seconds {$oldest}\n";
            echo "ls_longest_working_age_seconds {$longest}\n";

            $entities = [ 'products' => 'ls_products', 'inventory' => 'ls_inventory', 'consignments' => 'ls_consignments' ];
            foreach ($entities as $entity => $table) {
                try {
                    $age = (int)$pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND, MAX(updated_at), NOW()),0) FROM {$table}")->fetchColumn();
                    $rows15 = (int)$pdo->query("SELECT COUNT(*) FROM {$table} WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn();
                    $rpm = (int) floor($rows15 / 15);
                    echo "ls_cursor_age_seconds{entity=\"{$entity}\"} {$age}\n";
                    echo "ls_pull_rows_per_min{entity=\"{$entity}\"} {$rpm}\n";
                } catch (\Throwable $e) {}
            }

            $cb = Config::get('vend.cb', ['tripped' => false, 'until' => 0]);
            $tripped = (is_array($cb) && !empty($cb['tripped'])) ? 1 : 0;
            $until = is_array($cb) ? (int)($cb['until'] ?? 0) : 0;
            echo "vend_circuit_breaker_open {$tripped}\n";
            echo "vend_circuit_breaker_until_epoch {$until}\n";

            $types = [
                'create_consignment',
                'update_consignment',
                'cancel_consignment',
                'mark_transfer_partial',
                'edit_consignment_lines',
                'add_consignment_products',
                'webhook.event',
                'inventory.command',
                'push_product_update',
                'pull_products',
                'pull_inventory',
                'pull_consignments',
            ];
            foreach ($types as $t) { $paused = Config::getBool('vend_queue_pause.' . $t, false) ? 1 : 0; echo "ls_queue_paused{type=\"$t\"} {$paused}\n"; }

            // Webhook counters (last minute)
            try {
                $has = (bool)$pdo->query("SHOW TABLES LIKE 'webhook_stats'")->fetchColumn();
                if ($has) {
                    $st = $pdo->query("SELECT webhook_type, metric_name, SUM(metric_value) v FROM webhook_stats WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) GROUP BY webhook_type, metric_name")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    foreach ($st as $r) {
                        $t = (string)$r['webhook_type']; $m = (string)$r['metric_name']; $v = (float)$r['v'];
                        echo "webhook_metric{type=\"$t\",name=\"$m\"} $v\n";
                    }
                }
            } catch (\Throwable $e) {}

            // Vend HTTP counters (optional; guarded by table existence)
            try {
                $hasRl = (bool)$pdo->query("SHOW TABLES LIKE 'ls_rate_limits'")->fetchColumn();
                if ($hasRl) {
                    $w = date('Y-m-d H:i:00');
                    $stmt = $pdo->prepare("SELECT rl_key, counter FROM ls_rate_limits WHERE window_start = :w AND (rl_key LIKE 'vend_http:%')");
                    $stmt->execute([':w' => $w]);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    $reqs = []; $latSum = []; $latCnt = []; $latBuckets = [];
                    foreach ($rows as $r) {
                        $k = (string)$r['rl_key']; $v = (int)$r['counter'];
                        if (strpos($k, 'vend_http:requests_total:') === 0) {
                            $parts = explode(':', $k); $method = $parts[3] ?? 'GET'; $class = $parts[4] ?? '2xx';
                            $reqs[$method][$class] = ($reqs[$method][$class] ?? 0) + $v;
                        } elseif (strpos($k, 'vend_http:latency_sum_ms:') === 0) {
                            $method = substr($k, strlen('vend_http:latency_sum_ms:')); $latSum[$method] = ($latSum[$method] ?? 0) + $v;
                        } elseif (strpos($k, 'vend_http:latency_count:') === 0) {
                            $method = substr($k, strlen('vend_http:latency_count:')); $latCnt[$method] = ($latCnt[$method] ?? 0) + $v;
                        } elseif (strpos($k, 'vend_http:latency_bucket_ms:') === 0) {
                            // key: vend_http:latency_bucket_ms:METHOD:le:TH
                            $parts = explode(':', $k);
                            $method = $parts[3] ?? 'GET';
                            $le = $parts[5] ?? 'inf';
                            $latBuckets[$method][$le] = ($latBuckets[$method][$le] ?? 0) + $v;
                        }
                    }
                    foreach ($reqs as $m => $byClass) { foreach ($byClass as $cls => $val) { echo "vend_http_requests_total{method=\"$m\",class=\"$cls\"} $val\n"; } }
                    foreach ($latSum as $m => $sum) { $cnt = max(1, (int)($latCnt[$m] ?? 1)); $avg = (int) floor($sum / $cnt); echo "vend_http_latency_avg_ms{method=\"$m\"} $avg\n"; }
                    // Emit histogram buckets cumulatively and approximate P95/P99
                    $bucketOrder = ['50','100','200','400','800','1600','3200','10000','inf'];
                    foreach ($latBuckets as $m => $byLe) {
                        $cum = 0;
                        foreach ($bucketOrder as $le) { $cum += (int)($byLe[$le] ?? 0); echo "vend_http_latency_bucket_ms{method=\"$m\",le=\"$le\"} $cum\n"; }
                        $total = (int)($latCnt[$m] ?? array_sum($byLe));
                        if ($total > 0) {
                            $p95Target = (int)ceil($total * 0.95); $p99Target = (int)ceil($total * 0.99);
                            $running = 0; $p95 = 'inf'; $p99 = 'inf';
                            foreach ($bucketOrder as $le) { $running += (int)($byLe[$le] ?? 0); if ($p95 === 'inf' && $running >= $p95Target) { $p95 = $le; } if ($p99 === 'inf' && $running >= $p99Target) { $p99 = $le; break; } }
                            echo "vend_http_latency_p95_ms{method=\"$m\"} $p95\n";
                            echo "vend_http_latency_p99_ms{method=\"$m\"} $p99\n";
                        }
                    }

                    // Inventory Quick Qty counters
                    $stmt2 = $pdo->prepare("SELECT rl_key, counter FROM ls_rate_limits WHERE window_start = :w AND rl_key LIKE 'inventory_quick:%'");
                    $stmt2->execute([':w' => $w]);
                    $qr = $stmt2->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    foreach ($qr as $r) {
                        $k = (string)$r['rl_key']; $v = (int)$r['counter'];
                        if ($k === 'inventory_quick:requests_total') { echo "inventory_quick_requests_total $v\n"; }
                        elseif (strpos($k, 'inventory_quick:mode:') === 0) {
                            $mode = substr($k, strlen('inventory_quick:mode:'));
                            echo "inventory_quick_requests_mode_total{mode=\"$mode\"} $v\n";
                        } elseif ($k === 'inventory_quick:queued_total') { echo "inventory_quick_queued_total $v\n"; }
                        elseif ($k === 'inventory_quick:sync_exec') { echo "inventory_quick_sync_exec_total $v\n"; }
                    }
                }
            } catch (\Throwable $e) {}

            // Job duration buckets (basic; guarded)
            try {
                $hasJobs = (bool)$pdo->query("SHOW TABLES LIKE 'ls_jobs'")->fetchColumn();
                if ($hasJobs) {
                    // Processing duration: started_at -> finished_at when status='done' (if available), fallback to started_at->NOW() for working
                    $rows = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, started_at, IFNULL(finished_at, NOW())) AS s FROM ls_jobs WHERE started_at IS NOT NULL AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 5000")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                    $buckets = [1,5,30,120,600,3600];
                    $counts = array_fill_keys(array_map('strval', $buckets + [PHP_INT_MAX]), 0);
                    foreach ($rows as $s) { $sec = max(0, (int)$s); $le = 'inf'; foreach ($buckets as $th) { if ($sec <= $th) { $le = (string)$th; break; } } $counts[$le] = ($counts[$le] ?? 0) + 1; }
                    $cum = 0; foreach (array_map('strval', $buckets) + ['inf'] as $le) { $cum += (int)($counts[$le] ?? 0); echo "ls_job_processing_duration_bucket_seconds{le=\"$le\"} $cum\n"; }
                }
            } catch (\Throwable $e) {}
        } catch (\Throwable $e) { echo "ls_metrics_error 1\n"; }
    }

    /** DLQ Redrive: move failed jobs back to pending with next_run_at and attempt cap */
    public static function dlqRedrive(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('dlq_redrive', 10)) return;
        try {
            $pdo = PdoConnection::instance();
            $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
            $ids = isset($in['ids']) && is_array($in['ids']) ? array_values(array_filter($in['ids'], 'is_numeric')) : [];
            $limit = isset($in['limit']) ? max(1, min(500, (int)$in['limit'])) : 100;
            $requeued = 0;
            if ($ids) { $place = implode(',', array_fill(0, count($ids), '?')); $sel = $pdo->prepare("SELECT id,type,payload,idempotency_key,attempts FROM ls_jobs_dlq WHERE id IN ($place) LIMIT $limit"); $sel->execute($ids); }
            else { $sel = $pdo->query("SELECT id,type,payload,idempotency_key,attempts FROM ls_jobs_dlq ORDER BY moved_at ASC LIMIT " . (int)$limit); }
            $rows = $sel->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            if ($rows) {
                $ins = $pdo->prepare('INSERT INTO ls_jobs (id, type, priority, payload, idempotency_key, status, attempts, next_run_at, created_at, updated_at) VALUES (:id,:t,5,:p,:k,\'pending\',:a,DATE_ADD(NOW(), INTERVAL 1 MINUTE), NOW(), NOW()) ON DUPLICATE KEY UPDATE status=VALUES(status), attempts=VALUES(attempts), next_run_at=VALUES(next_run_at), updated_at=VALUES(updated_at)');
                $del = $pdo->prepare('DELETE FROM ls_jobs_dlq WHERE id=:id');
                foreach ($rows as $r) {
                    $attempts = max(0, (int)$r['attempts'] - 1);
                    $ins->execute([':id' => (int)$r['id'], ':t'  => (string)$r['type'], ':p'  => (string)$r['payload'], ':k'  => $r['idempotency_key'] !== null ? (string)$r['idempotency_key'] : null, ':a'  => $attempts,]);
                    $del->execute([':id' => (int)$r['id']]);
                    $requeued++;
                }
            }
            Http::respond(true, ['requeued' => $requeued]);
        } catch (\Throwable $e) { Http::error('dlq_redrive_failed', $e->getMessage()); }
    }

    /** Run forward-only migrations (idempotent) and optional compat shim */
    public static function migrate(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('queue_migrate', 30)) return;
        try {
            $pdo = PdoConnection::instance();
            $base = dirname(__DIR__, 2); $sqlFile = $base . '/sql/migrations.sql'; $compatFile = $base . '/sql/compat_transfer_queue.sql'; $applied = [];
            if (!is_file($sqlFile)) { Http::error('missing_file', 'migrations.sql not found'); return; }
            $sql = (string) file_get_contents($sqlFile);
            $sql = str_replace("\ncursor ", "\n`cursor` ", $sql);
            $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/m', $sql)));
            foreach ($stmts as $stmt) { if ($stmt !== '') { $pdo->exec($stmt); } }
            $applied[] = 'migrations.sql';
            try { $col = $pdo->query("SHOW COLUMNS FROM ls_jobs LIKE 'priority'")->fetch(); if (!$col) { $pdo->exec("ALTER TABLE ls_jobs ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER type"); $applied[] = 'alter:add_priority'; } } catch (\Throwable $e) {}
            try { $idx = $pdo->query("SHOW INDEX FROM ls_jobs WHERE Key_name='idx_jobs_status_priority'")->fetch(); if (!$idx) { $pdo->exec("CREATE INDEX idx_jobs_status_priority ON ls_jobs (status, priority, updated_at)"); $applied[] = 'index:idx_jobs_status_priority'; } } catch (\Throwable $e) {}
            if (is_file($compatFile)) { $compat = (string) file_get_contents($compatFile); $cstmts = array_filter(array_map('trim', preg_split('/;\s*\n/m', $compat))); foreach ($cstmts as $stmt) { if ($stmt !== '') { $pdo->exec($stmt); } } $applied[] = 'compat_transfer_queue.sql'; }
            Http::respond(true, [ 'message' => 'Migrations applied', 'files' => $applied, 'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/migrate.php' ]);
        } catch (\Throwable $e) { Http::error('migration_failed', $e->getMessage()); }
    }

    /** Transfers: create new tables if missing and begin backfill from existing transfers domain tables. */
    public static function transferMigrate(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('transfer_migrate', 10)) return;
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    if (!$in) { $in = $_POST ?: []; }
        $dry = (bool)($in['dry_run'] ?? ($in['dry'] ?? false));
    $limit = isset($in['limit']) ? max(1, min(10000, (int)$in['limit'])) : 500;
        $sinceId = isset($in['since_id']) ? (int)$in['since_id'] : 0;
        $sinceDate = isset($in['since_date']) ? (string)$in['since_date'] : '';
        // Optional type filters: only import a subset of transfer types (e.g., stock only)
        $onlyTypes = [];
        if (isset($in['only_type'])) { $onlyTypes = [ (string)$in['only_type'] ]; }
        if (isset($in['only_types']) && is_array($in['only_types'])) { $onlyTypes = array_map('strval', $in['only_types']); }
        // Support comma-separated string in form submissions
        if (!$onlyTypes && isset($in['only_types']) && is_string($in['only_types'])) {
            $onlyTypes = array_filter(array_map('trim', explode(',', $in['only_types'])));
        }
        // Normalise type names to upper for SQL comparisons
        $onlyTypes = array_values(array_unique(array_map(function($t){ return strtoupper(trim((string)$t)); }, $onlyTypes)));
        // Expand common synonyms
        $expand = function(array $types): array {
            $out = [];
            foreach ($types as $t) {
                switch ($t) {
                    case 'ST': case 'STOCK': case 'STOCKTAKE': case 'STOCK TAKE': case 'STOCK-TAKE': case 'STK':
                        $out[] = 'STOCK'; $out[] = 'ST'; $out[] = 'STOCKTAKE'; $out[] = 'STOCK TAKE'; $out[] = 'STOCK-TAKE'; $out[] = 'STK';
                        break;
                    case 'JT': case 'JUICE': case 'JUI': $out[] = 'JUICE'; $out[] = 'JUI'; $out[] = 'JT'; break;
                    case 'IT': case 'INTERNAL': case 'INTER': case 'SPECIAL': case 'STAFF': $out[] = 'INTERNAL'; $out[] = 'INTER'; $out[] = 'SPECIAL'; $out[] = 'IT'; $out[] = 'STAFF'; break;
                    case 'RT': case 'RETURN': case 'RET': $out[] = 'RETURN'; $out[] = 'RET'; $out[] = 'RT'; break;
                    default: $out[] = $t; break;
                }
            }
            return array_values(array_unique($out));
        };
        if ($onlyTypes) { $onlyTypes = $expand($onlyTypes); }
        $created = [];
        $backfilled = [
            'executions' => 0,
            'allocations' => 0,
            'discrepancies' => 0,
            'legacy_transfers' => 0,
            'legacy_items' => 0,
            'shipments' => 0,
            'parcels' => 0,
            'parcel_items' => 0,
            'carrier_normalized' => 0,
            'carrier_logs' => 0
        ];
        try {
            $pdo = PdoConnection::instance();
            $hasTable = function(string $t) use ($pdo): bool { try { return (bool)$pdo->query("SHOW TABLES LIKE '" . str_replace("'","''", $t) . "'")->fetchColumn(); } catch (\Throwable $e) { return false; } };
            $hasCol = function(string $t, string $c) use ($pdo): bool {
                try {
                    // Prefer information_schema for reliability across collations/case
                    $db = (string)($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
                    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c');
                    $stmt->execute([':db'=>$db, ':t'=>$t, ':c'=>$c]);
                    if (((int)$stmt->fetchColumn()) > 0) { return true; }
                } catch (\Throwable $e) { /* fall back below */ }
                try { $st=$pdo->prepare('SHOW COLUMNS FROM `'.$t.'` LIKE :c'); $st->execute([':c'=>$c]); return (bool)$st->fetch(); } catch (\Throwable $e) { return false; }
            };
            $hasIndex = function(string $t, string $k) use ($pdo): bool {
                try { $st=$pdo->prepare('SHOW INDEX FROM `'.$t.'` WHERE Key_name = :k'); $st->execute([':k'=>$k]); return (bool)$st->fetch(); } catch (\Throwable $e) { return false; }
            };
            // 1) Ensure new tables exist (minimal schema, idempotent)
            $ddl = [
                // Destination: core transfer tables (create if missing)
                'transfers' => "CREATE TABLE IF NOT EXISTS transfers (\n  id INT(11) NOT NULL AUTO_INCREMENT,\n  public_id VARCHAR(40) NULL,\n  vend_transfer_id CHAR(36) NULL,\n  vend_resource ENUM('consignment','purchase_order') NULL,\n  vend_number VARCHAR(64) NULL,\n  vend_url VARCHAR(255) NULL,\n  type ENUM('stock','juice','staff') NOT NULL,\n  status ENUM('draft','open','sent','partial','received','cancelled') NOT NULL,\n  outlet_from VARCHAR(100) NULL,\n  outlet_to VARCHAR(100) NULL,\n  created_by INT(11) NULL,\n  staff_transfer_id INT(10) UNSIGNED NULL,\n  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  deleted_by INT(11) NULL,\n  deleted_at TIMESTAMP NULL,\n  customer_id VARCHAR(45) NULL,\n  PRIMARY KEY (id),\n  KEY idx_type (type),\n  KEY idx_status (status),\n  KEY idx_created (created_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'transfer_items' => "CREATE TABLE IF NOT EXISTS transfer_items (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  transfer_id BIGINT UNSIGNED NOT NULL,\n  product_id BIGINT UNSIGNED NULL,\n  qty_requested INT NULL,\n  qty_sent_total INT NULL,\n  qty_received_total INT NULL,\n  confirmation_status VARCHAR(24) NULL,\n  confirmed_by_store TINYINT(1) NULL,\n  created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  deleted_by INT NULL,\n  deleted_at DATETIME NULL,\n  PRIMARY KEY (id),\n  KEY idx_ti_transfer (transfer_id),\n  KEY idx_ti_product (product_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'transfer_executions' => "CREATE TABLE IF NOT EXISTS transfer_executions (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  transfer_id BIGINT UNSIGNED NULL,\n  status VARCHAR(32) NOT NULL DEFAULT 'migrated',\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  completed_at DATETIME NULL,\n  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  KEY idx_status (status),\n  KEY idx_created (created_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'transfer_allocations' => "CREATE TABLE IF NOT EXISTS transfer_allocations (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  execution_id BIGINT UNSIGNED NOT NULL,\n  transfer_id BIGINT UNSIGNED NULL,\n  item_id BIGINT UNSIGNED NULL,\n  product_id BIGINT UNSIGNED NULL,\n  qty INT NOT NULL,\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  KEY fk_execution (execution_id),\n  KEY idx_product (product_id),\n  KEY idx_allocation_product_date (product_id, created_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                'transfer_discrepancies' => "CREATE TABLE IF NOT EXISTS transfer_discrepancies (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  transfer_id BIGINT UNSIGNED NOT NULL,\n  item_id BIGINT UNSIGNED NULL,\n  expected_qty INT NULL,\n  actual_qty INT NULL,\n  status VARCHAR(32) NOT NULL DEFAULT 'open',\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  KEY idx_td_transfer (transfer_id),\n  KEY idx_td_item (item_id),\n  KEY idx_td_status (status),\n  KEY idx_td_product (item_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                // Shipments (one-or-more per transfer; we will backfill minimum one)
                'transfer_shipments' => "CREATE TABLE IF NOT EXISTS transfer_shipments (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  transfer_id BIGINT UNSIGNED NOT NULL,\n  delivery_mode ENUM('dropoff','pickup','internal','courier','freight') NOT NULL DEFAULT 'internal',\n  status VARCHAR(24) NOT NULL DEFAULT 'created',\n  carrier_name VARCHAR(120) NULL,\n  tracking_number VARCHAR(120) NULL,\n  tracking_url VARCHAR(300) NULL,\n  packed_at DATETIME NULL,\n  dispatched_at DATETIME NULL,\n  received_at DATETIME NULL,\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  KEY idx_shipments_transfer (transfer_id),\n  KEY idx_shipments_status (status),\n  KEY idx_shipments_mode (delivery_mode),\n  KEY idx_shipments_packed_at (packed_at),\n  KEY idx_shipments_received_at (received_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                // Parcels (at least one per shipment in legacy backfill)
                'transfer_parcels' => "CREATE TABLE IF NOT EXISTS transfer_parcels (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  shipment_id BIGINT UNSIGNED NOT NULL,\n  parcel_number INT UNSIGNED NOT NULL DEFAULT 1,\n  tracking_number VARCHAR(120) NULL,\n  tracking_url VARCHAR(300) NULL,\n  weight_kg DECIMAL(8,3) NULL,\n  dimensions VARCHAR(60) NULL,\n  received_at DATETIME NULL,\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  UNIQUE KEY uniq_parcel_boxnum (shipment_id, parcel_number),\n  KEY idx_parcel_shipment (shipment_id),\n  KEY idx_parcel_tracking (tracking_number)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                // Parcel -> Items pivot
                'transfer_parcel_items' => "CREATE TABLE IF NOT EXISTS transfer_parcel_items (\n  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n  parcel_id BIGINT UNSIGNED NOT NULL,\n  item_id BIGINT UNSIGNED NOT NULL,\n  qty INT NOT NULL DEFAULT 0,\n  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n  locked_at DATETIME NULL,\n  PRIMARY KEY (id),\n  UNIQUE KEY uniq_parcel_item (parcel_id, item_id),\n  KEY idx_tpi_parcel (parcel_id),\n  KEY idx_tpi_item (item_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                // Notes for transfers
                'transfer_notes' => "CREATE TABLE IF NOT EXISTS transfer_notes (\n  id INT(11) NOT NULL AUTO_INCREMENT,\n  transfer_id INT(11) NOT NULL,\n  note_text MEDIUMTEXT NULL,\n  created_by INT(11) NULL,\n  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,\n  PRIMARY KEY (id),\n  KEY transfer_id (transfer_id),\n  KEY created_at (created_at)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                // Global sequences table for public IDs (per-type, per-period)
                'ls_id_sequences' => "CREATE TABLE IF NOT EXISTS ls_id_sequences (\n  seq_type VARCHAR(32) NOT NULL,\n  period VARCHAR(10) DEFAULT NULL,\n  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,\n  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n  PRIMARY KEY (seq_type, period)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];
            foreach ($ddl as $t => $sql) {
                if (!$hasTable($t)) { if (!$dry) { $pdo->exec($sql); } $created[] = $t; }
            }

            // If transfer_items table exists but misses critical columns, add them idempotently
            if ($hasTable('transfer_items')) {
                $tiAdded = [];
                $ensureCol = function(string $table, string $col, string $ddlSql) use ($hasCol, $pdo, $dry, &$tiAdded) {
                    if (!$hasCol($table, $col)) {
                        if (!$dry) { try { $pdo->exec($ddlSql); $tiAdded[] = "alter:$table.$col"; } catch (\Throwable $e) { /* ignore duplicate/invalid */ } }
                        else { $tiAdded[] = "alter(plan):$table.$col"; }
                    }
                };
                $ensureCol('transfer_items','id', "ALTER TABLE transfer_items ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                $ensureCol('transfer_items','transfer_id', "ALTER TABLE transfer_items ADD COLUMN transfer_id BIGINT UNSIGNED NULL AFTER id");
                $ensureCol('transfer_items','product_id', "ALTER TABLE transfer_items ADD COLUMN product_id BIGINT UNSIGNED NULL AFTER transfer_id");
                $ensureCol('transfer_items','qty_requested', "ALTER TABLE transfer_items ADD COLUMN qty_requested INT NULL AFTER product_id");
                $ensureCol('transfer_items','qty_sent_total', "ALTER TABLE transfer_items ADD COLUMN qty_sent_total INT NULL AFTER qty_requested");
                $ensureCol('transfer_items','qty_received_total', "ALTER TABLE transfer_items ADD COLUMN qty_received_total INT NULL AFTER qty_sent_total");
                $ensureCol('transfer_items','confirmation_status', "ALTER TABLE transfer_items ADD COLUMN confirmation_status VARCHAR(24) NULL AFTER qty_received_total");
                $ensureCol('transfer_items','confirmed_by_store', "ALTER TABLE transfer_items ADD COLUMN confirmed_by_store TINYINT(1) NULL AFTER confirmation_status");
                $ensureCol('transfer_items','created_at', "ALTER TABLE transfer_items ADD COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER confirmed_by_store");
                $ensureCol('transfer_items','updated_at', "ALTER TABLE transfer_items ADD COLUMN updated_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
                // Do not force deleted_by/deleted_at if schema differs; optional
                if ($tiAdded) { $created = array_merge($created, $tiAdded); }
            }

            // Ensure required timestamp columns exist on other destination tables that our backfill SELECTs/INSERTs reference
            $ensureColGeneric = function(string $table, string $col, string $ddlSql) use ($hasCol, $pdo, $dry, &$created) {
                try {
                    if (!$hasCol($table, $col)) {
                        if (!$dry) {
                            try { $pdo->exec($ddlSql); $created[] = "alter:$table.$col"; }
                            catch (\Throwable $e) { /* ignore if duplicate or permissions issues */ }
                        } else {
                            $created[] = "alter(plan):$table.$col";
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
            };
            if ($hasTable('transfers')) {
                $ensureColGeneric('transfers','created_at', "ALTER TABLE transfers ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER staff_transfer_id");
                $ensureColGeneric('transfers','updated_at', "ALTER TABLE transfers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
            }
            if ($hasTable('transfer_shipments')) {
                // Core columns used by shipment backfill
                $ensureColGeneric('transfer_shipments','transfer_id', "ALTER TABLE transfer_shipments ADD COLUMN transfer_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_shipments','delivery_mode', "ALTER TABLE transfer_shipments ADD COLUMN delivery_mode VARCHAR(24) NULL");
                $ensureColGeneric('transfer_shipments','status', "ALTER TABLE transfer_shipments ADD COLUMN status VARCHAR(24) NULL");
                $ensureColGeneric('transfer_shipments','carrier_name', "ALTER TABLE transfer_shipments ADD COLUMN carrier_name VARCHAR(120) NULL");
                $ensureColGeneric('transfer_shipments','tracking_number', "ALTER TABLE transfer_shipments ADD COLUMN tracking_number VARCHAR(120) NULL");
                $ensureColGeneric('transfer_shipments','tracking_url', "ALTER TABLE transfer_shipments ADD COLUMN tracking_url VARCHAR(300) NULL");
                $ensureColGeneric('transfer_shipments','packed_at', "ALTER TABLE transfer_shipments ADD COLUMN packed_at DATETIME NULL");
                $ensureColGeneric('transfer_shipments','dispatched_at', "ALTER TABLE transfer_shipments ADD COLUMN dispatched_at DATETIME NULL");
                $ensureColGeneric('transfer_shipments','received_at', "ALTER TABLE transfer_shipments ADD COLUMN received_at DATETIME NULL");
                $ensureColGeneric('transfer_shipments','created_at', "ALTER TABLE transfer_shipments ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                $ensureColGeneric('transfer_shipments','updated_at', "ALTER TABLE transfer_shipments ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            if ($hasTable('transfer_parcels')) {
                // Core columns used by parcel backfill
                $ensureColGeneric('transfer_parcels','shipment_id', "ALTER TABLE transfer_parcels ADD COLUMN shipment_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_parcels','parcel_number', "ALTER TABLE transfer_parcels ADD COLUMN parcel_number INT NOT NULL DEFAULT 1");
                $ensureColGeneric('transfer_parcels','created_at', "ALTER TABLE transfer_parcels ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                $ensureColGeneric('transfer_parcels','updated_at', "ALTER TABLE transfer_parcels ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            }
            if ($hasTable('transfer_parcel_items')) {
                // Core columns used by parcel_items backfill
                $ensureColGeneric('transfer_parcel_items','parcel_id', "ALTER TABLE transfer_parcel_items ADD COLUMN parcel_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_parcel_items','item_id', "ALTER TABLE transfer_parcel_items ADD COLUMN item_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_parcel_items','qty', "ALTER TABLE transfer_parcel_items ADD COLUMN qty INT NOT NULL DEFAULT 0");
                $ensureColGeneric('transfer_parcel_items','created_at', "ALTER TABLE transfer_parcel_items ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }

            // Ensure transfer_notes has required columns used by backfill (some environments may have a legacy schema)
            if ($hasTable('transfer_notes')) {
                // Keep ALTERs simple and position-agnostic (omit AFTER clauses to avoid dependency on column order)
                $ensureColGeneric('transfer_notes','transfer_id', "ALTER TABLE transfer_notes ADD COLUMN transfer_id INT(11) NOT NULL");
                $ensureColGeneric('transfer_notes','note_text', "ALTER TABLE transfer_notes ADD COLUMN note_text MEDIUMTEXT NULL");
                $ensureColGeneric('transfer_notes','created_by', "ALTER TABLE transfer_notes ADD COLUMN created_by INT(11) NULL");
                $ensureColGeneric('transfer_notes','created_at', "ALTER TABLE transfer_notes ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
            }

            // 1b) Add/ensure critical columns + indexes on transfer_executions idempotently
            if ($hasTable('transfer_executions')) {
                $addedCol = false; $addedIdx = false;
                // Ensure timestamp columns exist for inserts below
                try {
                    $colAdded = false;
                    $chk = function(string $c){ return true; };
                    if (!$hasCol('transfer_executions','created_at')) {
                        if (!$dry) { try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status"); } catch (\Throwable $e) { /* ignore */ } }
                        $addedCol = true;
                    }
                    if (!$hasCol('transfer_executions','completed_at')) {
                        if (!$dry) { try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN completed_at DATETIME NULL AFTER created_at"); } catch (\Throwable $e) { /* ignore */ } }
                        $addedCol = true;
                    }
                    if (!$hasCol('transfer_executions','updated_at')) {
                        if (!$dry) { try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER completed_at"); } catch (\Throwable $e) { /* ignore */ } }
                        $addedCol = true;
                    }
                } catch (\Throwable $e) { /* ignore */ }
                // Some environments may lack transfer_id on executions; add if missing
                if (!$hasCol('transfer_executions','transfer_id')) {
                    if (!$dry) {
                        try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN transfer_id BIGINT UNSIGNED NULL AFTER id"); $addedCol = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate column') === false) { throw $e; } }
                    } else { $addedCol = true; }
                }
                // Helpful lookup index by transfer_id for fast joins/mapping
                if (!$hasIndex('transfer_executions','idx_tx_transfer')) {
                    if (!$dry) {
                        try { $pdo->exec("CREATE INDEX idx_tx_transfer ON transfer_executions (transfer_id)"); $addedIdx = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate key name') === false) { throw $e; } }
                    } else { $addedIdx = true; }
                }
                if (!$hasCol('transfer_executions','public_id')) {
                    if (!$dry) {
                        try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN public_id VARCHAR(32) NULL AFTER id"); $addedCol = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate column') === false) { throw $e; } }
                    } else { $addedCol = true; }
                }
                if (!$hasIndex('transfer_executions','uniq_tx_pub')) {
                    if (!$dry) {
                        try { $pdo->exec("CREATE UNIQUE INDEX uniq_tx_pub ON transfer_executions (public_id)"); $addedIdx = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate key name') === false) { throw $e; } }
                    } else { $addedIdx = true; }
                }
                if ($addedCol) { $created[] = 'alter:transfer_executions.columns'; }
                if ($addedIdx) { $created[] = 'index:transfer_executions.added'; }
            }
            if ($hasTable('transfer_allocations')) {
                $addedCol = false; $addedIdx = false;
                if (!$hasCol('transfer_allocations','public_id')) {
                    if (!$dry) {
                        try { $pdo->exec("ALTER TABLE transfer_allocations ADD COLUMN public_id VARCHAR(40) NULL AFTER id"); $addedCol = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate column') === false) { throw $e; } }
                    } else { $addedCol = true; }
                }
                if (!$hasIndex('transfer_allocations','uniq_ta_pub')) {
                    if (!$dry) {
                        try { $pdo->exec("CREATE UNIQUE INDEX uniq_ta_pub ON transfer_allocations (public_id)"); $addedIdx = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate key name') === false) { throw $e; } }
                    } else { $addedIdx = true; }
                }
                // Ensure core columns used by inserts exist even if table pre-existed with a minimal schema
                $ensureColGeneric('transfer_allocations','execution_id', "ALTER TABLE transfer_allocations ADD COLUMN execution_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_allocations','transfer_id', "ALTER TABLE transfer_allocations ADD COLUMN transfer_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_allocations','item_id', "ALTER TABLE transfer_allocations ADD COLUMN item_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_allocations','product_id', "ALTER TABLE transfer_allocations ADD COLUMN product_id BIGINT UNSIGNED NULL");
                $ensureColGeneric('transfer_allocations','qty', "ALTER TABLE transfer_allocations ADD COLUMN qty INT NOT NULL DEFAULT 0");
                $ensureColGeneric('transfer_allocations','created_at', "ALTER TABLE transfer_allocations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
                if ($addedCol) { $created[] = 'alter:transfer_allocations.public_id'; }
                if ($addedIdx) { $created[] = 'index:transfer_allocations.uniq_ta_pub'; }
            }

            // 1b.2) Add alias_code (short code like ST-13204) for executions, and unique index
            if ($hasTable('transfer_executions')) {
                $addedCol = false; $addedIdx = false;
                if (!$hasCol('transfer_executions','alias_code')) {
                    if (!$dry) {
                        try { $pdo->exec("ALTER TABLE transfer_executions ADD COLUMN alias_code VARCHAR(24) NULL AFTER public_id"); $addedCol = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate column') === false) { throw $e; } }
                    } else { $addedCol = true; }
                }
                if (!$hasIndex('transfer_executions','uniq_tx_alias')) {
                    if (!$dry) {
                        try { $pdo->exec("CREATE UNIQUE INDEX uniq_tx_alias ON transfer_executions (alias_code)"); $addedIdx = true; }
                        catch (\Throwable $e) { if (strpos($e->getMessage(), 'Duplicate key name') === false) { throw $e; } }
                    } else { $addedIdx = true; }
                }
                if ($addedCol) { $created[] = 'alter:transfer_executions.alias_code'; }
                if ($addedIdx) { $created[] = 'index:transfer_executions.uniq_tx_alias'; }
            }

            // 1a) Ensure ID sequence table + critical triggers exist BEFORE any backfill
            try {
                if ($hasTable('ls_id_sequences') === false) {
                    if (!$dry) {
                        $pdo->exec("CREATE TABLE IF NOT EXISTS ls_id_sequences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  seq_type VARCHAR(64) NOT NULL,
  period VARCHAR(10) NOT NULL,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP(),
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_seq (seq_type, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                        $created[] = 'table:ls_id_sequences';
                    } else {
                        $created[] = 'plan:table:ls_id_sequences';
                    }
                }
            } catch (\Throwable $e) { /* ignore create race */ }

            // Create minimal helper to create triggers now (pre-backfill)
            $createTriggerEarly = function(string $name, string $sql) use ($pdo, $dry, &$created) {
                try { $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`"); } catch (\Throwable $e) {}
                if (!$dry) {
                    try { $pdo->exec($sql); $created[] = 'trigger:' . $name; }
                    catch (\Throwable $e) { $created[] = 'trigger_failed:' . $name; }
                } else {
                    $created[] = 'trigger(plan):' . $name;
                }
            };
            if ($hasTable('transfers')) {
                $trT = "CREATE TRIGGER `bi_transfers_public_id` BEFORE INSERT ON `transfers` FOR EACH ROW\nBEGIN\n  DECLARE v_period VARCHAR(10); DECLARE v_assigned BIGINT UNSIGNED; DECLARE v_seq BIGINT UNSIGNED; DECLARE v_num BIGINT UNSIGNED; DECLARE v_cd INT; DECLARE v_type VARCHAR(32); DECLARE v_code VARCHAR(6);\n  IF NEW.public_id IS NULL OR NEW.public_id = '' THEN\n    SET v_period = DATE_FORMAT(NOW(), '%Y%m');\n    SET v_type = UPPER(IFNULL(NEW.type, 'GENERIC'));\n    SET v_code = UPPER(REPLACE(SUBSTRING(v_type,1,3), ' ', ''));\n    INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES ('transfer', v_period, 2)\n      ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW();\n    SET v_seq = LAST_INSERT_ID();\n    SET v_assigned = IF(v_seq > 1, v_seq - 1, 1);\n    SET v_num = CAST(CONCAT(v_period, LPAD(v_assigned,6,'0')) AS UNSIGNED);\n    SET v_cd = (98 - MOD(v_num, 97)); IF v_cd = 98 THEN SET v_cd = 0; END IF;\n    SET NEW.public_id = CONCAT('TR-', v_code, '-', v_period, '-', LPAD(v_assigned,6,'0'), '-', LPAD(v_cd,2,'0'));\n  END IF;\nEND";
                $createTriggerEarly('bi_transfers_public_id', $trT);
            }

            // 1b) If legacy source tables exist, opportunistically backfill into new domain tables (transfers, transfer_items)
            $hasTransfersTbl = $hasTable('transfers');
            $hasLegacyTransfers = $hasTable('stock_transfers');
            $hasLegacyItems = $hasTable('stock_products_to_transfer');
            if ($hasTransfersTbl && ($hasLegacyTransfers || $hasLegacyItems)) {
                // Check if destination transfers has any rows; if empty, or explicitly asked via param force_legacy=1, seed from legacy
                $forceLegacy = (bool)($in['force_legacy'] ?? ($in['legacy'] ?? false));
                $destCount = 0; try { $destCount = (int)$pdo->query('SELECT COUNT(*) FROM transfers')->fetchColumn(); } catch (\Throwable $e) {}
                if ($forceLegacy || $destCount === 0) {
                    // Backfill transfers from stock_transfers
                    if ($hasLegacyTransfers) {
                        $where = [];$params = [];
                        if ($sinceId > 0) { $where[] = 'st.transfer_id > :sid'; $params[':sid'] = $sinceId; }
                        if ($sinceDate !== '') { $where[] = 'st.date_created >= :sd'; $params[':sd'] = $sinceDate; }
                        $sqlIns = "INSERT IGNORE INTO transfers (id, public_id, vend_transfer_id, vend_resource, vend_number, vend_url, type, status, outlet_from, outlet_to, created_by, staff_transfer_id, created_at, updated_at, deleted_by, deleted_at, customer_id)\nSELECT st.transfer_id AS id, NULL AS public_id, NULL AS vend_transfer_id, 'consignment' AS vend_resource, NULL AS vend_number, NULL AS vend_url,\n       'stock' AS type,\n       CASE\n         WHEN st.transfer_completed IS NOT NULL OR st.recieve_completed IS NOT NULL THEN 'received'\n         WHEN st.transfer_partially_received_timestamp IS NOT NULL THEN 'partial'\n         WHEN UPPER(st.micro_status) IN ('DRAFT') THEN 'draft'\n         WHEN UPPER(st.micro_status) IN ('SENT','DISPATCHED','IN TRANSIT') THEN 'sent'\n         WHEN UPPER(st.micro_status) IN ('PARTIAL','PARTIALLY RECEIVED','PARTLY RECEIVED') THEN 'partial'\n         WHEN UPPER(st.micro_status) IN ('RECEIVED','COMPLETED') THEN 'received'\n         WHEN UPPER(st.micro_status) IN ('CANCELLED','CANCELED') THEN 'cancelled'\n         WHEN UPPER(st.micro_status) IN ('OPEN','CREATED','PENDING') THEN 'open'\n         ELSE 'open' END AS status,\n       st.outlet_from, st.outlet_to, COALESCE(st.transfer_created_by_user, 0) AS created_by, NULL AS staff_transfer_id,\n       st.date_created AS created_at, COALESCE(st.transfer_completed, st.transfer_partially_received_timestamp, st.date_created) AS updated_at,\n       NULL AS deleted_by, st.deleted_at, NULL AS customer_id\nFROM stock_transfers st";
                        if ($where) { $sqlIns .= ' WHERE ' . implode(' AND ', $where); }
                        $sqlIns .= ' ORDER BY st.transfer_id ASC LIMIT ' . (int)$limit;
                        if (!$dry) { try { $stmt = $pdo->prepare($sqlIns); $stmt->execute($params); $backfilled['legacy_transfers'] = (int)$stmt->rowCount(); } catch (\Throwable $e) { /* ignore */ } }
                    }
                    // Backfill transfer_items from stock_products_to_transfer
                    if ($hasLegacyItems && $hasTransfersTbl) {
                        $where2 = [];$params2 = [];
                        if ($sinceId > 0) { $where2[] = 'sp.primary_key > :sid2'; $params2[':sid2'] = $sinceId; }
                        // Choose best available date column on legacy items for filtering and timestamps
                        $spCreatedExpr = $hasCol('stock_products_to_transfer','created_at') ? 'sp.created_at'
                            : ($hasCol('stock_products_to_transfer','created') ? 'sp.created' : 'NOW()');
                        $spUpdatedExpr = $hasCol('stock_products_to_transfer','updated_at') ? 'sp.updated_at'
                            : ($hasCol('stock_products_to_transfer','updated') ? 'sp.updated' : $spCreatedExpr);
                        if ($sinceDate !== '') {
                            // Apply filter using whichever column exists reliably
                            $where2[] = ($hasCol('stock_products_to_transfer','created_at') ? 'sp.created_at' : ($hasCol('stock_products_to_transfer','updated_at') ? 'sp.updated_at' : ($hasCol('stock_products_to_transfer','created') ? 'sp.created' : ($hasCol('stock_products_to_transfer','updated') ? 'sp.updated' : 'NULL')))) . ' >= :sd2';
                            $params2[':sd2'] = $sinceDate;
                        }
                        $spDeletedExpr = $hasCol('stock_products_to_transfer','deleted_at') ? 'sp.deleted_at' : 'NULL';
                        $sqlIns2 = "INSERT IGNORE INTO transfer_items (transfer_id, product_id, qty_requested, qty_sent_total, qty_received_total, confirmation_status, confirmed_by_store, updated_at, deleted_by, deleted_at)\nSELECT sp.transfer_id, sp.product_id, sp.qty_to_transfer, COALESCE(sp.qty_transferred_at_source,0), COALESCE(sp.qty_counted_at_destination,0),\n       CASE WHEN COALESCE(sp.qty_counted_at_destination,0) > 0 THEN 'accepted' ELSE 'pending' END AS confirmation_status,\n       CASE WHEN COALESCE(sp.qty_counted_at_destination,0) > 0 THEN 1 ELSE 0 END AS confirmed_by_store,\n       COALESCE(" . $spUpdatedExpr . ", " . $spCreatedExpr . ") AS updated_at, NULL AS deleted_by, " . $spDeletedExpr . "\nFROM stock_products_to_transfer sp\nJOIN transfers t ON t.id = sp.transfer_id";
                        if ($where2) { $sqlIns2 .= ' WHERE ' . implode(' AND ', $where2); }
                        $sqlIns2 .= ' ORDER BY sp.primary_key ASC LIMIT ' . (int)$limit;
                        if (!$dry) { try { $stmt2 = $pdo->prepare($sqlIns2); $stmt2->execute($params2); $backfilled['legacy_items'] = (int)$stmt2->rowCount(); } catch (\Throwable $e) { /* ignore */ } }
                    }

                    // Backfill notes from stock_transfers into transfer_notes
                    if ($hasLegacyTransfers && $hasTable('transfer_notes')) {
                        // Transfer Notes (creation context)
                        try {
                            $sqlN1 = "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)\nSELECT st.transfer_id, CONCAT('[Transfer Notes] ', st.transfer_notes) AS note_text, st.transfer_created_by_user AS created_by, st.date_created AS created_at\nFROM stock_transfers st\nWHERE st.transfer_notes IS NOT NULL AND st.transfer_notes <> ''\n  AND NOT EXISTS (SELECT 1 FROM transfer_notes tn WHERE tn.transfer_id = st.transfer_id AND tn.note_text = CONCAT('[Transfer Notes] ', st.transfer_notes))\nORDER BY st.transfer_id ASC LIMIT " . (int)$limit;
                            if (!$dry) { $pdo->exec($sqlN1); }
                        } catch (\Throwable $e) { /* ignore */ }
                        // General Notes (creation context)
                        try {
                            $sqlN2 = "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)\nSELECT st.transfer_id, CONCAT('[Notes] ', st.notes) AS note_text, st.transfer_created_by_user AS created_by, st.date_created AS created_at\nFROM stock_transfers st\nWHERE st.notes IS NOT NULL AND st.notes <> ''\n  AND NOT EXISTS (SELECT 1 FROM transfer_notes tn WHERE tn.transfer_id = st.transfer_id AND tn.note_text = CONCAT('[Notes] ', st.notes))\nORDER BY st.transfer_id ASC LIMIT " . (int)$limit;
                            if (!$dry) { $pdo->exec($sqlN2); }
                        } catch (\Throwable $e) { /* ignore */ }
                        // Completed Notes (completion context)
                        try {
                            $sqlN3 = "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)\nSELECT st.transfer_id, CONCAT('[Completed Notes] ', st.completed_notes) AS note_text, st.transfer_completed_by_user AS created_by, COALESCE(st.transfer_completed, st.date_created) AS created_at\nFROM stock_transfers st\nWHERE st.completed_notes IS NOT NULL AND st.completed_notes <> ''\n  AND NOT EXISTS (SELECT 1 FROM transfer_notes tn WHERE tn.transfer_id = st.transfer_id AND tn.note_text = CONCAT('[Completed Notes] ', st.completed_notes))\nORDER BY st.transfer_id ASC LIMIT " . (int)$limit;
                            if (!$dry) { $pdo->exec($sqlN3); }
                        } catch (\Throwable $e) { /* ignore */ }
                        // Receive Quality Notes (receiving context)
                        try {
                            $sqlN4 = "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)\nSELECT st.transfer_id, CONCAT('[Receive Quality Notes] ', st.receive_quality_notes) AS note_text, st.transfer_received_by_user AS created_by, COALESCE(st.recieve_completed, st.transfer_partially_received_timestamp, st.date_created) AS created_at\nFROM stock_transfers st\nWHERE st.receive_quality_notes IS NOT NULL AND st.receive_quality_notes <> ''\n  AND NOT EXISTS (SELECT 1 FROM transfer_notes tn WHERE tn.transfer_id = st.transfer_id AND tn.note_text = CONCAT('[Receive Quality Notes] ', st.receive_quality_notes))\nORDER BY st.transfer_id ASC LIMIT " . (int)$limit;
                            if (!$dry) { $pdo->exec($sqlN4); }
                        } catch (\Throwable $e) { /* ignore */ }
                        // Receive Summary JSON (receiving context, truncated)
                        try {
                            $sqlN5 = "INSERT INTO transfer_notes (transfer_id, note_text, created_by, created_at)\nSELECT st.transfer_id, CONCAT('[Receive Summary] ', LEFT(st.receive_summary_json, 4000)) AS note_text, st.transfer_received_by_user AS created_by, COALESCE(st.recieve_completed, st.transfer_partially_received_timestamp, st.date_created) AS created_at\nFROM stock_transfers st\nWHERE st.receive_summary_json IS NOT NULL AND st.receive_summary_json <> ''\n  AND NOT EXISTS (SELECT 1 FROM transfer_notes tn WHERE tn.transfer_id = st.transfer_id AND tn.note_text = CONCAT('[Receive Summary] ', LEFT(st.receive_summary_json, 4000)))\nORDER BY st.transfer_id ASC LIMIT " . (int)$limit;
                            if (!$dry) { $pdo->exec($sqlN5); }
                        } catch (\Throwable $e) { /* ignore */ }
                    }

                    // Backfill shipments (minimum one per transfer), parcels (one per shipment), and parcel_items
                    if ($hasLegacyTransfers && $hasTable('transfer_shipments')) {
                        try {
                            // Insert one shipment per transfer if none exists; map delivery_mode heuristically from legacy text
                            $sqlShip = "INSERT INTO transfer_shipments (transfer_id, delivery_mode, status, carrier_name, tracking_number, tracking_url, packed_at, dispatched_at, received_at, created_at, updated_at)\nSELECT t.id AS transfer_id,\n  (\n    CASE\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'courier|aramex|nz ?post|nzpost|post ?haste|dhl|fedex|toll|ups|fastway|gss|nz[- ]?couriers?|freight|mainfreight|line ?haul|metro' THEN 'courier'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'pick ?up|picked up|collected|collection' THEN 'pickup'\n      ELSE 'internal_drive'\n    END\n  ) AS delivery_mode,\n  (\n    CASE\n      WHEN st.transfer_completed IS NOT NULL OR st.recieve_completed IS NOT NULL THEN 'received'\n      WHEN UPPER(st.micro_status) IN ('PARTIAL','PARTIALLY RECEIVED','PARTLY RECEIVED') THEN 'partial'\n      WHEN st.transfer_partially_received_timestamp IS NOT NULL OR UPPER(st.micro_status) IN ('SENT','DISPATCHED','IN TRANSIT') THEN 'in_transit'\n      WHEN UPPER(st.micro_status) IN ('DRAFT','OPEN','CREATED','PENDING') THEN 'packed'\n      ELSE 'packed'\n    END\n  ) AS status,\n  (\n    CASE\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'gss|nz[- ]?couriers?' THEN 'NZ Couriers'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'nz ?post|nzpost' THEN 'NZ Post'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'post ?haste' THEN 'Post Haste'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'aramex|fastway' THEN 'Aramex'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'dhl' THEN 'DHL'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'toll' THEN 'Toll'\n      WHEN LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'ups' THEN 'UPS'\n      WHEN (LOWER(CONCAT_WS(' ', st.transfer_notes, st.notes, st.completed_notes, st.receive_quality_notes)) REGEXP 'courier') THEN 'NZ Couriers'\n      ELSE NULL\n    END\n  ) AS carrier_name,\n  NULL AS tracking_number,\n  NULL AS tracking_url,\n  st.date_created AS packed_at,\n  CASE\n    WHEN st.transfer_partially_received_timestamp IS NOT NULL OR UPPER(st.micro_status) IN ('SENT','DISPATCHED','IN TRANSIT','PARTIAL','PARTIALLY RECEIVED') THEN COALESCE(st.transfer_partially_received_timestamp, st.date_created)\n    ELSE NULL\n  END AS dispatched_at,\n  COALESCE(st.transfer_completed, st.recieve_completed) AS received_at,\n  st.date_created AS created_at,\n  COALESCE(st.transfer_completed, st.transfer_partially_received_timestamp, st.date_created) AS updated_at\nFROM stock_transfers st\nJOIN transfers t ON t.id = st.transfer_id\nLEFT JOIN transfer_shipments s ON s.transfer_id = t.id\nWHERE s.id IS NULL\nORDER BY t.id ASC\nLIMIT " . (int)$limit;
                            if (!$dry) { $stShip = $pdo->prepare($sqlShip); $stShip->execute(); $backfilled['shipments'] = (int)$stShip->rowCount(); }
                        } catch (\Throwable $e) { /* ignore shipments backfill errors */ }
                        // Retro-normalize: for existing shipments with courier mode but missing/generic carrier, set to NZ Couriers
                        try {
                            // Optional: write log entries for affected shipments, if transfer_logs table exists
                            if ($hasTable('transfer_logs')) {
                                // Guard: only run if required columns exist on transfer_logs
                                $canLog = $hasCol('transfer_logs','transfer_id') && $hasCol('transfer_logs','event_code') && $hasCol('transfer_logs','message') && $hasCol('transfer_logs','source') && $hasCol('transfer_logs','created_at');
                                if ($canLog) {
                                    $sqlLog = "INSERT INTO transfer_logs (transfer_id, event_code, message, source, created_at)\nSELECT ts.transfer_id, 'carrier_normalized', 'carrier_name set to NZ Couriers (retro)', 'system', NOW()\nFROM transfer_shipments ts\nWHERE ts.delivery_mode = 'courier'\n  AND (ts.carrier_name IS NULL OR ts.carrier_name = '' OR LOWER(ts.carrier_name) REGEXP '^(courier|couriers)$')";
                                    if (!$dry) { $stL = $pdo->prepare($sqlLog); $stL->execute(); $backfilled['carrier_logs'] = (int)$stL->rowCount(); }
                                } else {
                                    $created[] = 'skip:transfer_logs:missing_columns';
                                }
                            }
                            $sqlUpdCarrier = "UPDATE transfer_shipments ts\nLEFT JOIN stock_transfers st ON st.transfer_id = ts.transfer_id\nSET ts.carrier_name = 'NZ Couriers'\nWHERE ts.delivery_mode = 'courier'\n  AND (ts.carrier_name IS NULL OR ts.carrier_name = '' OR LOWER(ts.carrier_name) REGEXP '^(courier|couriers)$')\n";
                            if (!$dry) { $stUpd = $pdo->prepare($sqlUpdCarrier); $stUpd->execute(); $backfilled['carrier_normalized'] = (int)$stUpd->rowCount(); }
                        } catch (\Throwable $e) { /* ignore retro carrier update */ }
                    }

                    // Ensure one default parcel per shipment
                    if ($hasTable('transfer_shipments') && $hasTable('transfer_parcels')) {
                        try {
                            // Use safe timestamp expressions if columns are missing on existing schemas
                            $sCreatedExpr = $hasCol('transfer_shipments','created_at') ? 's.created_at' : 'NOW()';
                            $sUpdatedExpr = $hasCol('transfer_shipments','updated_at') ? 's.updated_at' : $sCreatedExpr;
                            $sqlParcel = "INSERT INTO transfer_parcels (shipment_id, parcel_number, created_at, updated_at)\nSELECT s.id AS shipment_id, 1 AS parcel_number, " . $sCreatedExpr . ", " . $sUpdatedExpr . "\nFROM transfer_shipments s\nLEFT JOIN transfer_parcels p ON p.shipment_id = s.id AND p.parcel_number = 1\nWHERE p.id IS NULL\nORDER BY s.id ASC\nLIMIT " . (int)$limit;
                            if (!$dry) { $stPar = $pdo->prepare($sqlParcel); $stPar->execute(); $backfilled['parcels'] = (int)$stPar->rowCount(); }
                        } catch (\Throwable $e) { /* ignore parcels backfill errors */ }
                    }

                    // Link all transfer_items to the default parcel as parcel_items (qty = sent if available else requested)
                    if ($hasTable('transfer_parcels') && $hasTable('transfer_parcel_items') && $hasTable('transfer_items')) {
                        try {
                            $qtyExpr = 'COALESCE(ti.qty_sent_total, ti.qty_requested, 0)';
                            // If transfer_items.transfer_id is missing, we cannot join items to shipments — skip gracefully
                            $tiHasTransferId = $hasCol('transfer_items','transfer_id');
                            if (!$tiHasTransferId) {
                                $created[] = 'skip:parcel_items:no_transfer_id';
                            } else {
                                $sqlPI = "INSERT IGNORE INTO transfer_parcel_items (parcel_id, item_id, qty, created_at)\nSELECT p.id AS parcel_id, ti.id AS item_id, " . $qtyExpr . " AS qty, NOW() AS created_at\nFROM transfer_parcels p\nJOIN transfer_shipments s ON s.id = p.shipment_id\nJOIN transfer_items ti ON ti.transfer_id = s.transfer_id\nLEFT JOIN transfer_parcel_items tpi ON tpi.parcel_id = p.id AND tpi.item_id = ti.id\nWHERE tpi.id IS NULL\nORDER BY p.id ASC\nLIMIT " . (int)$limit;
                                if (!$dry) { $stPI = $pdo->prepare($sqlPI); $stPI->execute(); $backfilled['parcel_items'] = (int)$stPI->rowCount(); }
                            }
                        } catch (\Throwable $e) { /* ignore parcel_items backfill errors */ }
                    }
                }
            }

            // 1c) Create robust BEFORE INSERT triggers to assign public IDs at DB layer
            $createTrigger = function(string $name, string $sql) use ($pdo, $dry, &$created) {
                try { $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`"); } catch (\Throwable $e) {}
                if (!$dry) {
                    try { $pdo->exec($sql); $created[] = 'trigger:' . $name; }
                    catch (\Throwable $e) { $created[] = 'trigger_failed:' . $name; }
                } else {
                    $created[] = 'trigger(plan):' . $name;
                }
            };
            // Ensure transfers has a public_id generator trigger (needed because public_id is NOT NULL in some schemas)
            if ($hasTable('transfers')) {
                $trT = "CREATE TRIGGER `bi_transfers_public_id` BEFORE INSERT ON `transfers` FOR EACH ROW\nBEGIN\n  DECLARE v_period VARCHAR(10); DECLARE v_assigned BIGINT UNSIGNED; DECLARE v_seq BIGINT UNSIGNED; DECLARE v_num BIGINT UNSIGNED; DECLARE v_cd INT; DECLARE v_type VARCHAR(32); DECLARE v_code VARCHAR(6);\n  IF NEW.public_id IS NULL OR NEW.public_id = '' THEN\n    SET v_period = DATE_FORMAT(NOW(), '%Y%m');\n    SET v_type = UPPER(IFNULL(NEW.type, 'GENERIC'));\n    SET v_code = UPPER(REPLACE(SUBSTRING(v_type,1,3), ' ', ''));\n    INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES ('transfer', v_period, 2)\n      ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW();\n    SET v_seq = LAST_INSERT_ID();\n    SET v_assigned = IF(v_seq > 1, v_seq - 1, 1);\n    SET v_num = CAST(CONCAT(v_period, LPAD(v_assigned,6,'0')) AS UNSIGNED);\n    SET v_cd = (98 - MOD(v_num, 97)); IF v_cd = 98 THEN SET v_cd = 0; END IF;\n    SET NEW.public_id = CONCAT('TR-', v_code, '-', v_period, '-', LPAD(v_assigned,6,'0'), '-', LPAD(v_cd,2,'0'));\n  END IF;\nEND";
                $createTrigger('bi_transfers_public_id', $trT);
            }
            if ($hasTable('transfer_executions') && $hasTable('transfers')) {
                $tr = "CREATE TRIGGER `bi_transfer_executions_public_id` BEFORE INSERT ON `transfer_executions` FOR EACH ROW\nBEGIN\n  DECLARE v_period VARCHAR(10); DECLARE v_assigned BIGINT UNSIGNED; DECLARE v_seq BIGINT UNSIGNED; DECLARE v_num BIGINT UNSIGNED; DECLARE v_cd INT; DECLARE v_type VARCHAR(32); DECLARE v_code VARCHAR(6);\n  IF NEW.public_id IS NULL OR NEW.public_id = '' THEN\n    SET v_period = DATE_FORMAT(NOW(), '%Y%m');\n    SELECT `type` INTO v_type FROM transfers WHERE id = NEW.transfer_id LIMIT 1;\n    SET v_type = IFNULL(v_type, 'generic');\n    SET v_code = UPPER(REPLACE(SUBSTRING(v_type,1,3), ' ', ''));\n    INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES ('transfer_exec', v_period, 2)\n      ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW();\n    SET v_seq = LAST_INSERT_ID();\n    SET v_assigned = IF(v_seq > 1, v_seq - 1, 1);\n    SET v_num = CAST(CONCAT(v_period, LPAD(v_assigned,6,'0')) AS UNSIGNED);\n    SET v_cd = (98 - MOD(v_num, 97)); IF v_cd = 98 THEN SET v_cd = 0; END IF;\n    SET NEW.public_id = CONCAT('TX-', v_code, '-', v_period, '-', LPAD(v_assigned,6,'0'), '-', LPAD(v_cd,2,'0'));\n  END IF;\nEND";
                $createTrigger('bi_transfer_executions_public_id', $tr);

                // Short alias trigger based on transfers.id and mapped type prefix (ST/JT/IT/RT)
                                $tr2 = "CREATE TRIGGER `bi_transfer_executions_alias` BEFORE INSERT ON `transfer_executions` FOR EACH ROW\nBEGIN\n  DECLARE v_type VARCHAR(32); DECLARE v_prefix VARCHAR(3); DECLARE v_num BIGINT UNSIGNED;\n  IF NEW.alias_code IS NULL OR NEW.alias_code = '' THEN\n    SELECT `type` INTO v_type FROM transfers WHERE id = NEW.transfer_id LIMIT 1;\n    SET v_type = UPPER(IFNULL(v_type,'GENERIC'));
        SET v_prefix = CASE 
            WHEN v_type IN ('STOCK','ST','STOCKTAKE','STOCK TAKE','STOCK-TAKE','STK') THEN 'ST'
                            WHEN v_type IN ('JUICE','JUI','JT') THEN 'JT'
                            WHEN v_type IN ('SPECIAL','INTERNAL','INTER','IT','STAFF') THEN 'IT'
            WHEN v_type IN ('RETURN','RET','RT') THEN 'RT'
            ELSE 'XX' END;\n    SELECT id INTO v_num FROM transfers WHERE id = NEW.transfer_id LIMIT 1;\n    SET NEW.alias_code = CONCAT(v_prefix,'-', v_num);\n  END IF;\nEND";
                $createTrigger('bi_transfer_executions_alias', $tr2);
            }
            if ($hasTable('transfer_allocations') && $hasTable('transfers')) {
                $tr = "CREATE TRIGGER `bi_transfer_allocations_public_id` BEFORE INSERT ON `transfer_allocations` FOR EACH ROW\nBEGIN\n  DECLARE v_period VARCHAR(10); DECLARE v_assigned BIGINT UNSIGNED; DECLARE v_seq BIGINT UNSIGNED; DECLARE v_num BIGINT UNSIGNED; DECLARE v_cd INT; DECLARE v_type VARCHAR(32); DECLARE v_code VARCHAR(6);\n  IF NEW.public_id IS NULL OR NEW.public_id = '' THEN\n    SET v_period = DATE_FORMAT(NOW(), '%Y%m');\n    SELECT `type` INTO v_type FROM transfers WHERE id = NEW.transfer_id LIMIT 1;\n    SET v_type = IFNULL(v_type, 'generic');\n    SET v_code = UPPER(REPLACE(SUBSTRING(v_type,1,3), ' ', ''));\n    INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES ('transfer_alloc', v_period, 2)\n      ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW();\n    SET v_seq = LAST_INSERT_ID();\n    SET v_assigned = IF(v_seq > 1, v_seq - 1, 1);\n    SET v_num = CAST(CONCAT(v_period, LPAD(v_assigned,6,'0')) AS UNSIGNED);\n    SET v_cd = (98 - MOD(v_num, 97)); IF v_cd = 98 THEN SET v_cd = 0; END IF;\n    SET NEW.public_id = CONCAT('TA-', v_code, '-', v_period, '-', LPAD(v_assigned,6,'0'), '-', LPAD(v_cd,2,'0'));\n  END IF;\nEND";
                $createTrigger('bi_transfer_allocations_public_id', $tr);
            }
            // 2) Backfill transfer_executions from existing transfers (only if schema supports transfer_id)
            $hasTransfers = $hasTable('transfers');
            $execHasTransferId = $hasTable('transfer_executions') && $hasCol('transfer_executions','transfer_id');
            if ($hasTransfers && $execHasTransferId) {
                $where = [];$params = [];
                if ($sinceId > 0) { $where[] = 'id > :sid'; $params[':sid'] = $sinceId; }
                if ($sinceDate !== '') { $where[] = 'created_at >= :sd'; $params[':sd'] = $sinceDate; }
                if ($onlyTypes) {
                    $inParams = [];
                    foreach ($onlyTypes as $i => $t) { $key = ':t' . $i; $inParams[] = $key; $params[$key] = $t; }
                    $where[] = 'UPPER(type) IN (' . implode(',', $inParams) . ')';
                }
                // Some legacy schemas may not have received_at; alias NULL when missing
                $selectCols = 'id, status, created_at';
                if ($hasCol('transfers','received_at')) { $selectCols .= ', received_at'; } else { $selectCols .= ', NULL AS received_at'; }
                $sql = 'SELECT ' . $selectCols . ' FROM transfers';
                if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                $sql .= ' ORDER BY id ASC LIMIT ' . (int)$limit;
                $sel = $pdo->prepare($sql); $sel->execute($params);
                $rows = $sel->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                if ($rows) {
                    // Only prepare/execute INSERT when not in dry-run to avoid schema validation errors at prepare-time
                    $ins = null;
                    if (!$dry) {
                        $ins = $pdo->prepare("INSERT INTO transfer_executions (transfer_id, status, created_at, completed_at, updated_at) VALUES (:tid,:st,:ca,:ra, NOW())");
                    }
                    foreach ($rows as $r) {
                        $tid = (int)$r['id']; $st = (string)($r['status'] ?? 'migrated'); $ca = (string)($r['created_at'] ?? date('Y-m-d H:i:s')); $ra = isset($r['received_at']) ? (string)$r['received_at'] : null;
                        if (!$dry && $ins) {
                            try { $ins->execute([':tid'=>$tid, ':st'=>$st !== '' ? $st : 'migrated', ':ca'=>$ca, ':ra'=>$ra]); }
                            catch (\Throwable $e) {
                                // If duplicates by unique constraints occur, skip silently
                            }
                        }
                        $backfilled['executions']++;
                    }
                    // Fill alias_code for any rows missing it
                    if (!$dry) {
                        try {
                            $aliasSql = "UPDATE transfer_executions e JOIN transfers t ON t.id=e.transfer_id SET e.alias_code = CONCAT(\nCASE UPPER(t.type)\n WHEN 'STOCK' THEN 'ST' WHEN 'ST' THEN 'ST' WHEN 'STOCKTAKE' THEN 'ST' WHEN 'STOCK TAKE' THEN 'ST' WHEN 'STOCK-TAKE' THEN 'ST' WHEN 'STK' THEN 'ST'\n WHEN 'JUICE' THEN 'JT' WHEN 'JUI' THEN 'JT' WHEN 'JT' THEN 'JT'\n WHEN 'SPECIAL' THEN 'IT' WHEN 'INTERNAL' THEN 'IT' WHEN 'INTER' THEN 'IT' WHEN 'IT' THEN 'IT'\n WHEN 'RETURN' THEN 'RT' WHEN 'RET' THEN 'RT' WHEN 'RT' THEN 'RT'\n ELSE 'XX' END, '-', t.id)\nWHERE (e.alias_code IS NULL OR e.alias_code='')";
                            $aliasParams = [];
                            if ($onlyTypes) {
                                $placeholders = implode(',', array_fill(0, count($onlyTypes), '?'));
                                $aliasSql .= " AND UPPER(t.type) IN (" . $placeholders . ")";
                                $aliasParams = $onlyTypes; // already upper-cased
                            }
                            $stmt = $pdo->prepare($aliasSql);
                            $stmt->execute($aliasParams);
                        } catch (\Throwable $e) { /* ignore */ }
                    }
                }
            } else if ($hasTransfers && !$execHasTransferId) { $created[] = 'skip:executions:no_transfer_id'; }
            // 3) Backfill transfer_allocations from transfer_items if present
            $hasItems = $hasTable('transfer_items');
            if ($hasItems) {
                // Require transfer_items.transfer_id to build allocations; if absent, skip this step safely
                $tiHasTransferId = $hasCol('transfer_items','transfer_id');
                if (!$tiHasTransferId) {
                    $created[] = 'skip:allocations:no_transfer_id';
                } else {
                $where = [];$params = [];
                if ($sinceId > 0) { $where[] = 'ti.id > :sid'; $params[':sid'] = $sinceId; }
                if ($sinceDate !== '') { $where[] = 'ti.created_at >= :sd'; $params[':sd'] = $sinceDate; }
                // Map columns defensively for legacy schemas
                $qtyExpr = $hasCol('transfer_items','quantity') ? 'ti.quantity' : ($hasCol('transfer_items','qty') ? 'ti.qty' : '0');
                $pidExpr = $hasCol('transfer_items','product_id') ? 'ti.product_id' : 'NULL';
                $createdExpr = $hasCol('transfer_items','created_at') ? 'ti.created_at' : ($hasCol('transfer_items','created') ? 'ti.created' : 'NOW()');
                $sql = 'SELECT ti.id, ti.transfer_id, ' . $pidExpr . ' AS product_id, ' . $qtyExpr . ' AS quantity, ' . $createdExpr . ' AS created_at FROM transfer_items ti';
                if ($onlyTypes) {
                    $sql .= ' JOIN transfers t ON t.id = ti.transfer_id';
                    $inParams = [];
                    foreach ($onlyTypes as $i => $t) { $key = ':tt' . $i; $inParams[] = $key; $params[$key] = $t; }
                    $where[] = 'UPPER(t.type) IN (' . implode(',', $inParams) . ')';
                }
                if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                $sql .= ' ORDER BY id ASC LIMIT ' . (int)$limit;
                if ($dry) {
                    // In dry-run, avoid selecting transfer_id field list entirely; just count potential rows
                    $countSql = preg_replace('/^SELECT .* FROM /is', 'SELECT COUNT(*) AS cnt FROM ', $sql, 1);
                    $stc = $pdo->prepare($countSql); $stc->execute($params);
                    $cnt = (int)($stc->fetchColumn() ?: 0);
                    if ($cnt > 0) { $backfilled['allocations'] += $cnt; }
                } else {
                    $sel = $pdo->prepare($sql); $sel->execute($params);
                    $rows = $sel->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    if ($rows) {
                        // Ensure executions table supports lookup by transfer_id; otherwise skip safely
                        $execHasTransfer = $hasCol('transfer_executions','transfer_id');
                        if (!$execHasTransfer) { $created[] = 'skip:allocations:no_exec_transfer_id'; }
                        else {
                            // Map transfer_id -> execution_id for joins
                            $execSel = $pdo->prepare('SELECT id FROM transfer_executions WHERE transfer_id = :tid LIMIT 1');
                            $ins = $pdo->prepare('INSERT INTO transfer_allocations (execution_id, transfer_id, item_id, product_id, qty, created_at) VALUES (:eid,:tid,:iid,:pid,:q,:ca)');
                            foreach ($rows as $r) {
                                $tid = (int)$r['transfer_id']; $iid = (int)$r['id']; $pid = isset($r['product_id']) ? (int)$r['product_id'] : null; $qty = (int)($r['quantity'] ?? 0); $ca = (string)($r['created_at'] ?? date('Y-m-d H:i:s'));
                                $eid = null; try { $execSel->execute([':tid'=>$tid]); $eid = (int)$execSel->fetchColumn(); } catch (\Throwable $e) { $eid = null; }
                                if ($eid) {
                                    try { $ins->execute([':eid'=>$eid, ':tid'=>$tid, ':iid'=>$iid, ':pid'=>$pid, ':q'=>$qty, ':ca'=>$ca]); }
                                    catch (\Throwable $e) { /* ignore duplicates */ }
                                    $backfilled['allocations']++;
                                }
                            }
                        }
                    }
                }
                }
            }
            Http::respond(true, ['dry_run'=>$dry, 'only_types'=>$onlyTypes, 'created_tables'=>$created, 'backfilled'=>$backfilled]);
        } catch (\Throwable $e) {
            Http::error('transfer_migrate_failed', $e->getMessage());
        }
    }

    /** Transfers: read-only preview via GET — counts rows that would be migrated, respecting filters. */
    public static function transferMigratePreview(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('transfer_migrate_preview', 5)) return;
        // Accept query params: limit, since_id, since_date, only_type, only_types (csv)
        $limit = isset($_GET['limit']) ? max(1, min(2000, (int)$_GET['limit'])) : 500;
        $sinceId = isset($_GET['since_id']) ? (int)$_GET['since_id'] : 0;
        $sinceDate = isset($_GET['since_date']) ? (string)$_GET['since_date'] : '';
        $showTypes = isset($_GET['show_types']) ? (bool)$_GET['show_types'] : false;
        $onlyTypes = [];
        if (isset($_GET['only_type']) && $_GET['only_type'] !== '') { $onlyTypes[] = (string)$_GET['only_type']; }
        if (isset($_GET['only_types']) && $_GET['only_types'] !== '') {
            if (is_array($_GET['only_types'])) { $onlyTypes = array_merge($onlyTypes, array_map('strval', $_GET['only_types'])); }
            else { $onlyTypes = array_merge($onlyTypes, array_filter(array_map('trim', explode(',', (string)$_GET['only_types'])))); }
        }
        $onlyTypes = array_values(array_unique(array_map(function($t){ return strtoupper(trim((string)$t)); }, $onlyTypes)));
        $expand = function(array $types): array {
            $out = [];
            foreach ($types as $t) {
                switch ($t) {
                    case 'ST': case 'STOCK': case 'STOCKTAKE': case 'STOCK TAKE': case 'STOCK-TAKE': case 'STK':
                        $out[] = 'STOCK'; $out[] = 'ST'; $out[] = 'STOCKTAKE'; $out[] = 'STOCK TAKE'; $out[] = 'STOCK-TAKE'; $out[] = 'STK';
                        break;
                    case 'JT': case 'JUICE': case 'JUI': $out[] = 'JUICE'; $out[] = 'JUI'; $out[] = 'JT'; break;
                    case 'IT': case 'INTERNAL': case 'INTER': case 'SPECIAL': case 'STAFF':
                        $out[] = 'INTERNAL'; $out[] = 'INTER'; $out[] = 'SPECIAL'; $out[] = 'IT'; $out[] = 'STAFF';
                        break;
                    case 'RT': case 'RETURN': case 'RET': $out[] = 'RETURN'; $out[] = 'RET'; $out[] = 'RT'; break;
                    default: $out[] = $t; break;
                }
            }
            return array_values(array_unique($out));
        };
        if ($onlyTypes) { $onlyTypes = $expand($onlyTypes); }
        $result = [ 'limit' => $limit, 'since_id' => $sinceId, 'since_date' => $sinceDate, 'only_types' => $onlyTypes, 'counts' => [ 'transfers' => 0, 'transfer_items' => 0, 'stock_transfers' => 0, 'stock_products_to_transfer' => 0 ] ];
        try {
            $pdo = PdoConnection::instance();
            $hasTable = function(string $t) use ($pdo): bool { try { return (bool)$pdo->query("SHOW TABLES LIKE '" . str_replace("'","''", $t) . "'")->fetchColumn(); } catch (\Throwable $e) { return false; } };
            // Legacy counts if present (unfiltered by type, since legacy does not have same type field)
            if ($hasTable('stock_transfers')) {
                try {
                    $where = [];$params = [];
                    if ($sinceId > 0) { $where[] = 'transfer_id > :sid'; $params[':sid'] = $sinceId; }
                    if ($sinceDate !== '') { $where[] = 'date_created >= :sd'; $params[':sd'] = $sinceDate; }
                    $sql = 'SELECT COUNT(*) FROM stock_transfers'; if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                    $stmt = $pdo->prepare($sql); $stmt->execute($params); $result['counts']['stock_transfers'] = (int)$stmt->fetchColumn();
                } catch (\Throwable $e) {}
            }
            if ($hasTable('stock_products_to_transfer')) {
                try {
                    $where = [];$params = [];
                    if ($sinceId > 0) { $where[] = 'primary_key > :sid'; $params[':sid'] = $sinceId; }
                    if ($sinceDate !== '') { $where[] = 'created_at >= :sd'; $params[':sd'] = $sinceDate; }
                    $sql = 'SELECT COUNT(*) FROM stock_products_to_transfer'; if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                    $stmt = $pdo->prepare($sql); $stmt->execute($params); $result['counts']['stock_products_to_transfer'] = (int)$stmt->fetchColumn();
                } catch (\Throwable $e) {}
            }
            if ($hasTable('transfers')) {
                // Optionally report distinct types discovered (upper-cased) for operators
                if ($showTypes) {
                    try {
                        $types = $pdo->query('SELECT DISTINCT UPPER(type) AS t FROM transfers')->fetchAll(\PDO::FETCH_COLUMN) ?: [];
                        sort($types);
                        $result['types_all'] = $types;
                    } catch (\Throwable $e) { $result['types_all'] = []; }
                }
                $where = [];$params = [];
                if ($sinceId > 0) { $where[] = 'id > :sid'; $params[':sid'] = $sinceId; }
                if ($sinceDate !== '') { $where[] = 'created_at >= :sd'; $params[':sd'] = $sinceDate; }
                if ($onlyTypes) { $in = []; foreach ($onlyTypes as $i=>$t){ $k=':t'.$i; $in[]=$k; $params[$k]=$t; } $where[] = 'UPPER(type) IN ('.implode(',', $in).')'; }
                $sql = 'SELECT COUNT(*) FROM transfers'; if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                $stmt = $pdo->prepare($sql); $stmt->execute($params); $result['counts']['transfers'] = (int)$stmt->fetchColumn();
            }
            if ($hasTable('transfer_items')) {
                $where = [];$params = [];
                if ($sinceId > 0) { $where[] = 'ti.id > :sid'; $params[':sid'] = $sinceId; }
                if ($sinceDate !== '') { $where[] = 'ti.created_at >= :sd'; $params[':sd'] = $sinceDate; }
                $sql = 'SELECT COUNT(*) FROM transfer_items ti';
                if ($onlyTypes) { $sql .= ' JOIN transfers t ON t.id = ti.transfer_id'; $in=[]; foreach ($onlyTypes as $i=>$t){ $k=':tt'.$i; $in[]=$k; $params[$k]=$t; } $where[] = 'UPPER(t.type) IN ('.implode(',', $in).')'; }
                if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
                $stmt = $pdo->prepare($sql); $stmt->execute($params); $result['counts']['transfer_items'] = (int)$stmt->fetchColumn();
            }
            Http::respond(true, $result);
        } catch (\Throwable $e) { Http::error('transfer_migrate_preview_failed', $e->getMessage()); }
    }

    // Duplicate legacy pause()/resume() methods removed to avoid redeclare; use the
    // consolidated implementations earlier in this class which support pausing all
    // types or a specific type, with proper auth, rate limiting, and JSON handling.

    /** Return status: paused flags and working counts per type */
    public static function status(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('queue_status', 10)) return;
        $pdo = PdoConnection::instance();
        $types = [
            'create_consignment',
            'update_consignment',
            'cancel_consignment',
            'mark_transfer_partial',
            'edit_consignment_lines',
            'add_consignment_products',
            'webhook.event',
            'inventory.command',
            'push_product_update',
            'pull_products',
            'pull_inventory',
            'pull_consignments',
        ];
        $out = [];
        foreach ($types as $t) {
            $paused = Config::getBool('vend_queue_pause.' . $t, false);
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM ls_jobs WHERE status='working' AND type=:t"); $stmt->execute([':t' => $t]); $count = (int)$stmt->fetchColumn();
            $cap = (int) (Config::get('vend.queue.max_concurrency.' . $t, Config::get('vend.queue.max_concurrency.default', 1)) ?? 1);
            $out[$t] = ['paused' => $paused, 'working' => $count, 'cap' => $cap];
        }
        Http::respond(true, ['types' => $out]);
    }

    // Duplicate legacy concurrencyUpdate() removed; use the consolidated implementation
    // near the top of this class which accepts { type, value } and enforces limits.

    /** Self-test: quick DB/table checks and rate-limit write */
    public static function selftest(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('selftest', 5)) return;
        $ok = true; $details = [];
        try {
            $pdo = PdoConnection::instance(); $pdo->query('SELECT 1'); $details['db'] = 'ok';
            $tables = ['ls_jobs','ls_job_logs','ls_jobs_dlq','ls_rate_limits'];
            foreach ($tables as $t) { try { $exists = (bool)$pdo->query("SHOW TABLES LIKE '" . str_replace("'","''", $t) . "'")->fetchColumn(); $details['table_'.$t] = $exists ? 'present' : 'missing'; if(!$exists) $ok=false; } catch (\Throwable $e) { $ok=false; $details['table_'.$t]='error'; } }
            $bucket = date('Y-m-d H:i:00'); $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,1,NOW()) ON DUPLICATE KEY UPDATE counter=counter+1, updated_at=NOW()')->execute([':k'=>'selftest',':w'=>$bucket]); $details['rate_limits_write'] = 'ok';
            // Demo E2E in mock mode (does not require external calls)
            $demo = ['ran' => false];
            if ((bool)(Config::get('vend.http_mock', false) ?? false)) {
                $demo['ran'] = true; $transferPk = null; $publicId = 'DEMO-' . substr(sha1((string)microtime(true)), 0, 8);
                // Insert a demo transfer row if table exists
                try {
                    $hasTransfer = (bool)$pdo->query("SHOW TABLES LIKE 'transfers'")->fetchColumn();
                    if ($hasTransfer) {
                        $pdo->prepare("INSERT INTO transfers (public_id, type, status, outlet_from, outlet_to, created_by) VALUES (:pid,'stock','draft','OF-DEMO','OT-DEMO',0)")->execute([':pid'=>$publicId]);
                        $transferPk = (int)$pdo->lastInsertId();
                    }
                } catch (\Throwable $e) {}
                // Create consignment
                \Queue\Lightspeed\Runner::run(['--limit'=>1,'--type'=>'create_consignment']);
                // Edit lines
                \Queue\Lightspeed\Runner::run(['--limit'=>1,'--type'=>'edit_consignment_lines']);
                // Mark partial
                \Queue\Lightspeed\Runner::run(['--limit'=>1,'--type'=>'mark_transfer_partial']);
                // Cancel
                \Queue\Lightspeed\Runner::run(['--limit'=>1,'--type'=>'cancel_consignment']);
                $demo['note'] = 'Mock demo executed (create/edit/partial/cancel). Verify audit/logs in transfer tables if present.';
            }
            $details['demo'] = $demo;
        } catch (\Throwable $e) { $ok=false; $details['err']=$e->getMessage(); }
        Http::respond($ok, ['details'=>$details]);
    }

    /** Admin: list webhook subscriptions */
    public static function webhookSubscriptions(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_subs', 30)) return;
        try {
            $pdo = PdoConnection::instance();
            $has = (bool)$pdo->query("SHOW TABLES LIKE 'webhook_subscriptions'")->fetchColumn();
            $rows = [];
            if ($has) {
                $rows = $pdo->query('SELECT id, source_system, event_type, endpoint_url, is_active, last_event_received, events_received_today, events_received_total, health_status, health_message, updated_at FROM webhook_subscriptions ORDER BY id ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            Http::respond(true, ['rows' => $rows]);
        } catch (\Throwable $e) {
            // Degrade gracefully if DB unavailable or table missing
            header('X-Queue-Warn: webhook_subs_unavailable');
            Http::respond(true, ['rows' => []]);
        }
    }

    /** Admin: update a webhook subscription */
    public static function webhookSubscriptionsUpdate(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_subs_update', 20)) return;
        $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $id = isset($in['id']) ? (int)$in['id'] : 0; if ($id <= 0) { Http::error('bad_request','id required'); return; }
        $fields = [];$params = [':id'=>$id];
        if (isset($in['is_active'])) { $fields[] = 'is_active = :a'; $params[':a'] = (int) (!!$in['is_active']); }
        if (isset($in['endpoint_url'])) { $fields[] = 'endpoint_url = :u'; $params[':u'] = (string)$in['endpoint_url']; }
        if (isset($in['event_type'])) { $fields[] = 'event_type = :t'; $params[':t'] = (string)$in['event_type']; }
        if (!$fields) { Http::error('bad_request','no fields to update'); return; }
        try { $pdo = PdoConnection::instance(); $sql = 'UPDATE webhook_subscriptions SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id'; $pdo->prepare($sql)->execute($params); Http::respond(true, ['updated' => $id]); } catch (\Throwable $e) { Http::error('webhook_subs_update_failed', $e->getMessage()); }
    }

    /** Admin: list webhook events */
    public static function webhookEvents(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_events', 30)) return;
        $limit = isset($_GET['limit']) ? max(1, min(500, (int)$_GET['limit'])) : 100; $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
        try {
            $pdo = PdoConnection::instance();
            $has = (bool)$pdo->query("SHOW TABLES LIKE 'webhook_events'")->fetchColumn();
            $out = [];
            if ($has) {
                $where = [];$params = [];
                if ($type !== '') { $where[]='webhook_type = :t'; $params[':t']=$type; }
                $sql = 'SELECT id, webhook_id, webhook_type, status, received_at, processed_at, error_message FROM webhook_events';
                if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
                $sql .= ' ORDER BY received_at DESC LIMIT ' . (int)$limit;
                $rows = $pdo->prepare($sql); $rows->execute($params);
                $out = $rows->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            Http::respond(true, ['rows' => $out]);
        } catch (\Throwable $e) {
            header('X-Queue-Warn: webhook_events_unavailable');
            Http::respond(true, ['rows' => []]);
        }
    }

    /** Admin: list webhook health checks */
    public static function webhookHealthList(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_health', 30)) return;
        try {
            $pdo = PdoConnection::instance();
            $has = (bool)$pdo->query("SHOW TABLES LIKE 'webhook_health'")->fetchColumn();
            $rows = [];
            if ($has) {
                $rows = $pdo->query('SELECT id, check_time, webhook_type, health_status, response_time_ms, consecutive_failures FROM webhook_health ORDER BY check_time DESC LIMIT 200')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            Http::respond(true, ['rows' => $rows]);
        } catch (\Throwable $e) {
            header('X-Queue-Warn: webhook_health_unavailable');
            Http::respond(true, ['rows' => []]);
        }
    }

    /** Admin: list webhook stats (recent buckets) */
    public static function webhookStats(): void
    {
        if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_stats', 30)) return;
        try {
            $pdo = PdoConnection::instance();
            $has = (bool)$pdo->query("SHOW TABLES LIKE 'webhook_stats'")->fetchColumn();
            $rows = [];
            if ($has) {
                $rows = $pdo->query("SELECT recorded_at, webhook_type, metric_name, metric_value, time_period FROM webhook_stats WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY recorded_at DESC LIMIT 1000")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            }
            Http::respond(true, ['rows' => $rows]);
        } catch (\Throwable $e) {
            header('X-Queue-Warn: webhook_stats_unavailable');
            Http::respond(true, ['rows' => []]);
        }
    }

    /** Admin: mark events as replayed (bookkeeping) */
    public static function webhookReplay(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_replay', 10)) return;
        $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_values(array_filter($in['ids'], 'is_numeric')) : [];
        $reason = isset($in['reason']) ? (string)$in['reason'] : 'manual';
        if (!$ids) { Http::error('bad_request','ids required'); return; }
        try { $pdo = PdoConnection::instance(); $stmt = $pdo->prepare("UPDATE webhook_events SET status='replayed', replayed_from = COALESCE(replayed_from, webhook_id), replay_reason = :r, updated_at = NOW() WHERE id=:id"); $n = 0; foreach ($ids as $id) { $stmt->execute([':r'=>$reason, ':id'=>(int)$id]); $n++; } Http::respond(true, ['updated' => $n]); } catch (\Throwable $e) { Http::error('webhook_replay_failed', $e->getMessage()); }
    }

    /** Admin: rotate admin bearer token or webhook shared secret without downtime */
    public static function keysRotate(): void
    {
        if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('keys_rotate', 5)) return;
        $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $target = isset($in['target']) ? (string)$in['target'] : '';
        $overlapMin = isset($in['overlap_minutes']) ? max(1, min(1440, (int)$in['overlap_minutes'])) : 60;
        $newSecret = isset($in['new_secret']) ? (string)$in['new_secret'] : '';
        $showSecret = (bool)($in['show_secret'] ?? false);
        if ($target === '') { Http::error('bad_request', 'target required (admin_bearer|vend_webhook)'); return; }
        $now = time(); $exp = $now + ($overlapMin * 60);

        try {
            if ($target === 'admin_bearer') {
                $current = (string)(Config::get('ADMIN_BEARER_TOKEN', '') ?? '');
                Config::set('ADMIN_BEARER_TOKEN_PREV', $current);
                Config::set('ADMIN_BEARER_TOKEN_PREV_EXPIRES_AT', $exp);
                if ($newSecret === '') { try { $newSecret = bin2hex(random_bytes(24)); } catch (\Throwable $e) { $newSecret = bin2hex(random_bytes(16)); } }
                Config::set('ADMIN_BEARER_TOKEN', $newSecret);
                Http::respond(true, [ 'rotated' => 'admin_bearer', 'overlap_minutes' => $overlapMin, 'prev_expires_at' => $exp, 'new_secret' => $showSecret ? $newSecret : null ]);
                return;
            }
            if ($target === 'vend_webhook') {
                $current = (string)(Config::get('vend_webhook_secret', '') ?? '');
                Config::set('vend_webhook_secret_prev', $current);
                Config::set('vend_webhook_secret_prev_expires_at', $exp);
                if ($newSecret === '') { try { $newSecret = bin2hex(random_bytes(32)); } catch (\Throwable $e) { $newSecret = bin2hex(random_bytes(16)); } }
                Config::set('vend_webhook_secret', $newSecret);
                Http::respond(true, [ 'rotated' => 'vend_webhook', 'overlap_minutes' => $overlapMin, 'prev_expires_at' => $exp, 'new_secret' => $showSecret ? $newSecret : null ]);
                return;
            }
            Http::error('bad_request', 'unknown target');
        } catch (\Throwable $e) { Http::error('rotation_failed', $e->getMessage()); }
    }
}
