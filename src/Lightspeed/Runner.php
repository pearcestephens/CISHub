<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Logger;
use Queue\Config;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Degrade;

// --- ensure the worker can call the Transfers MVP label generator ---
if (!class_exists('\\CIS\\Transfers\\Stock\\PackHelper')) {
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4);
    $packHelperPath = $docRoot . '/modules/transfers/stock/lib/PackHelper.php';
    if (is_file($packHelperPath)) {
        require_once $packHelperPath;
    }
}

final class Runner
{
    public static function run(array $args): int
    {
        if (\Queue\FeatureFlags::isDisabled(\Queue\FeatureFlags::runnerEnabled())) {
            echo json_encode(['ok' => false, 'error' => 'runner_disabled', 'flags' => \Queue\FeatureFlags::snapshot()]) . "\n";
            return 0;
        }
        $limit = isset($args['--limit']) ? (int)$args['--limit'] : 200;
        $type  = $args['--type'] ?? null;
        // Continuous mode: run 24/7 with idle backoff instead of exiting on no work or time budget.
        // Enable via config vend.queue.continuous.enabled=true or CLI flag --continuous.
        // Explicit --no-continuous wins over config.
        $continuousCfg = (bool) Config::getBool('vend.queue.continuous.enabled', false);
        $continuous = isset($args['--no-continuous']) ? false : ((bool) (isset($args['--continuous']) || $continuousCfg));
        $timeBudget = (int) Config::get('vend_queue_runtime_business', 120);
        $deadline = time() + $timeBudget;

        // Idle backoff (used in continuous mode)
        $idleBaseMs = (int) (Config::get('vend.queue.idle_sleep_ms', 500) ?? 500);
        $idleMaxMs  = (int) (Config::get('vend.queue.idle_sleep_max_ms', 5000) ?? 5000);
        $idleMs = max(50, min($idleBaseMs, $idleMaxMs));

        // Graceful shutdown
        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$stop) { $stop = true; });
            pcntl_signal(SIGINT, static function () use (&$stop) { $stop = true; });
        }

        Logger::info('runner.start', ['meta' => ['limit' => $limit, 'type' => $type, 'budget' => $timeBudget, 'continuous' => $continuous, 'idle_base_ms' => $idleBaseMs, 'idle_max_ms' => $idleMaxMs]]);

        // Single-flight advisory lock per job type (optional)
        $lockHeld = false; $lockKey = null;
        if (!Config::getBool('vend_queue_disable_singleflight', false)) {
            $lkType = $type ?: 'all';
            $lockKey = 'ls_runner:' . $lkType;
            try {
                $pdo = \Queue\PdoConnection::instance();
                $stmt = $pdo->prepare('SELECT GET_LOCK(:k, 1) AS got');
                $stmt->execute([':k' => $lockKey]);
                $got = $stmt->fetch(\PDO::FETCH_ASSOC);
                $lockHeld = $got && (int)$got['got'] === 1;
                if (!$lockHeld) {
                    Logger::warn('runner.lock_busy', ['meta' => ['key' => $lockKey]]);
                    echo json_encode(['ok' => true, 'processed' => 0, 'note' => 'lock busy']) . "\n";
                    return 0;
                }
            } catch (\Throwable $e) { /* ignore lock acquisition errors */ }
        }

        $processed = 0;
        // Throttled auto-degrade evaluator (runs at most once per minute when in continuous mode)
        $lastAutoEval = 0;
        while (!$stop && ($continuous || ($processed < $limit && time() < $deadline))) {
            if (\Queue\FeatureFlags::killAll()) { Logger::warn('runner.killed'); break; }
            // Run auto-evaluator periodically to flip safeguards during incidents
            if ($continuous) {
                $now = time();
                if ($now - $lastAutoEval >= 60 && Config::getBool('auto.degrade.enabled', true)) {
                    try {
                        $res = Degrade::autoEvaluate();
                        if (is_array($res) && !empty($res['actions'])) {
                            Logger::info('degrade.auto_eval', ['meta' => $res]);
                        }
                    } catch (\Throwable $e) {
                        // best-effort; ignore errors so worker keeps running
                    }
                    $lastAutoEval = $now;
                }
            }
            // Determine candidate type based on pause flags and concurrency caps
            $effectiveRemaining = $continuous ? 50 : max(1, $limit - $processed);
            $batchLimit = max(1, min(50, $effectiveRemaining));
            $candidateType = $type;
            try {
                $pdo = \Queue\PdoConnection::instance();
                // Known job types (must mirror switch cases below). If you add a new case, add it here too.
                $types = [
                    // Consignments / Transfers
                    'create_consignment',
                    'update_consignment',
                    'cancel_consignment',
                    'mark_transfer_partial',
                    'edit_consignment_lines',
                    'add_consignment_products',
                    // Webhooks and fanout
                    'webhook.event',
                    'sync_product',
                    'sync_inventory',
                    'sync_customer',
                    'sync_sale',
                    // Inventory commands & product updates
                    'inventory.command',
                    'push_product_update',
                    // Periodic pull tasks (scheduled)
                    'pull_products',
                    'pull_inventory',
                    'pull_consignments',
                ];
                if ($candidateType !== null && $candidateType !== '' && !in_array($candidateType, $types, true)) {
                    $types[] = $candidateType;
                }
                // Build working counts
                $inTypes = implode(',', array_map(static fn($t) => $pdo->quote($t), $types));
                $counts = [];
                // Support legacy schema where status='running' instead of 'working'
                $rows = $pdo->query("SELECT type, COUNT(*) c FROM ls_jobs WHERE (status='working' OR status='running') AND type IN ($inTypes) GROUP BY type")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($types as $t) { $counts[$t] = 0; }
                foreach ($rows as $r) { $counts[(string)$r['type']] = (int)$r['c']; }
                // Build pending counts to prioritize actual backlog when no explicit type is given
                $pendingRows = $pdo->query("SELECT type, COUNT(*) c FROM ls_jobs WHERE status='pending' AND type IN ($inTypes) GROUP BY type")->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                $pending = [];
                foreach ($types as $t) { $pending[$t] = 0; }
                foreach ($pendingRows as $r) { $pending[(string)$r['type']] = (int)$r['c']; }
                // Compute caps and pauses
                $caps = [];$paused = [];$slack = [];
                foreach ($types as $t) {
                    $caps[$t] = (int) (Config::get('vend.queue.max_concurrency.' . $t, Config::get('vend.queue.max_concurrency.default', 1)) ?? 1);
                    $paused[$t] = Config::getBool('vend_queue_pause.' . $t, false);
                    $slack[$t] = max(0, $caps[$t] - ($counts[$t] ?? 0));
                }
                // If specific type provided, enforce its pause/cap
                if ($candidateType) {
                    if ($paused[$candidateType] ?? false) { Logger::warn('runner.paused', ['meta' => ['type' => $candidateType]]); break; }
                    if ($slack[$candidateType] <= 0) {
                        // No concurrency available for explicit type; in continuous mode wait/backoff, otherwise short sleep
                        if ($continuous) { usleep($idleMs * 1000); $idleMs = min($idleMaxMs, max($idleBaseMs, $idleMs * 2)); }
                        else { usleep(200 * 1000); }
                        continue;
                    }
                } else {
                    // Prefer types that actually have pending backlog, then by slack
                    $ordered = $types;
                    usort($ordered, static function (string $a, string $b) use ($pending, $slack): int {
                        $pa = $pending[$a] ?? 0; $pb = $pending[$b] ?? 0;
                        if ($pa === $pb) { return ($slack[$b] ?? 0) <=> ($slack[$a] ?? 0); }
                        return $pb <=> $pa;
                    });
                    $pick = null;
                    foreach ($ordered as $t) { if (!$paused[$t] && ($slack[$t] ?? 0) > 0 && ($pending[$t] ?? 0) > 0) { $pick = $t; break; } }
                    // If no pending anywhere, fall back to highest slack (no-op if no jobs exist)
                    if ($pick === null) {
                        foreach ($ordered as $t) { if (!$paused[$t] && ($slack[$t] ?? 0) > 0) { $pick = $t; break; } }
                    }
                    $candidateType = $pick;
                    if ($candidateType === null) {
                        // Nothing to do now
                        if ($continuous) { usleep($idleMs * 1000); $idleMs = min($idleMaxMs, max($idleBaseMs, $idleMs * 2)); }
                        else { usleep(200 * 1000); }
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to existing behavior
            }
            $batch = Repo::claimBatch($batchLimit, $candidateType);
            // If no batch for the chosen type and no explicit --type, try other eligible types before giving up
            if (!$batch && ($type === null || $type === '')) {
                try {
                    // Recompute eligible order (favor pending desc)
                    $fallbackOrder = isset($ordered) ? $ordered : [$candidateType];
                    foreach ($fallbackOrder as $alt) {
                        if ($alt === $candidateType) { continue; }
                        if (isset($paused) && ($paused[$alt] ?? false)) { continue; }
                        if (isset($slack) && ($slack[$alt] ?? 0) <= 0) { continue; }
                        $b2 = Repo::claimBatch($batchLimit, $alt);
                        if ($b2) { $candidateType = $alt; $batch = $b2; break; }
                    }
                } catch (\Throwable $e) { /* best-effort */ }
            }
            if (!$batch) {
                // No batch available. In continuous mode, idle-sleep and keep looping; else exit the worker loop.
                if ($continuous) { usleep($idleMs * 1000); $idleMs = min($idleMaxMs, max($idleBaseMs, $idleMs * 2)); continue; }
                usleep(200 * 1000); break; }
            foreach ($batch as $job) {
                $processed++;
                $tJobStart = microtime(true);
                try {
                    Repo::heartbeat($job->id);
                    self::process($job->type, $job->payload, $job->id);
                    Repo::heartbeat($job->id);
                    Repo::complete($job->id);
                    // Best-effort: record duration metric for this job type
                    self::recordTransferQueueMetric('job_duration_ms', $job->type, (int) round((microtime(true) - $tJobStart) * 1000), [
                        'job_id' => $job->id,
                        'result' => 'success',
                    ],
                    isset($job->payload['source_outlet_id']) ? (string)$job->payload['source_outlet_id'] : null,
                    isset($job->payload['dest_outlet_id']) ? (string)$job->payload['dest_outlet_id'] : null);
                } catch (\Throwable $e) {
                    Logger::error('job.fail', ['job_id' => $job->id, 'meta' => ['err' => $e->getMessage()]]);
                    Repo::fail($job->id, $e->getMessage());
                    // Best-effort: record failure duration metric
                    self::recordTransferQueueMetric('job_duration_ms', $job->type, (int) round((microtime(true) - $tJobStart) * 1000), [
                        'job_id' => $job->id,
                        'result' => 'failed',
                        'error' => substr($e->getMessage(), 0, 255),
                    ],
                    isset($job->payload['source_outlet_id']) ? (string)$job->payload['source_outlet_id'] : null,
                    isset($job->payload['dest_outlet_id']) ? (string)$job->payload['dest_outlet_id'] : null);
                }
                // Work was done: reset idle backoff
                $idleMs = $idleBaseMs;
                if ($stop || (!$continuous && time() >= $deadline) || (!$continuous && $processed >= $limit)) break 2;
            }
        }

        Logger::info('runner.done', ['meta' => ['processed' => $processed, 'continuous' => $continuous]]);
        if ($lockHeld && $lockKey) {
            try {
                $pdo = \Queue\PdoConnection::instance();
                $pdo->prepare('SELECT RELEASE_LOCK(:k)')->execute([':k' => $lockKey]);
            } catch (\Throwable $e) { /* ignore */ }
        }
        echo json_encode(['ok' => true, 'processed' => $processed]) . "\n";
        return 0;
    }

    /** @param array<string,mixed> $payload */
    private static function process(string $type, array $payload, int $jobId): void
    {
        Logger::info('job.process', ['job_id' => $jobId, 'meta' => ['type' => $type]]);
        switch ($type) {
            case 'webhook.event':
                // Minimal handler: mark the webhook event completed and optionally fan-out child jobs
                try {
                    $pdo = \Queue\PdoConnection::instance();
                    $wid = (string)($payload['webhook_id'] ?? '');
                    $etype = (string)($payload['webhook_type'] ?? ($payload['type'] ?? 'vend.webhook'));
                    $updated = 0;
                    if ($wid !== '') {
                        try {
                            $upd = $pdo->prepare("UPDATE webhook_events SET status='completed', processed_at = IFNULL(processed_at, NOW()), processing_attempts = processing_attempts + 1, updated_at = NOW() WHERE webhook_id = :wid");
                            $upd->execute([':wid' => $wid]);
                            $updated = (int)$upd->rowCount();
                        } catch (\Throwable $e) { /* ignore */ }
                    } elseif (isset($payload['event_db_id'])) {
                        try {
                            $upd = $pdo->prepare("UPDATE webhook_events SET status='completed', processed_at = IFNULL(processed_at, NOW()), processing_attempts = processing_attempts + 1, updated_at = NOW() WHERE id = :id");
                            $upd->execute([':id' => (int)$payload['event_db_id']]);
                            $updated = (int)$upd->rowCount();
                        } catch (\Throwable $e) { /* ignore */ }
                    }
                    // Optional fanout to typed sync jobs if enabled
                    if (\Queue\Config::getBool('webhook.fanout.enabled', true)) {
                        $routes = [
                            'product.update' => 'sync_product',
                            'inventory.update' => 'sync_inventory',
                            'customer.update' => 'sync_customer',
                            'sale.update' => 'sync_sale',
                        ];
                        $target = $routes[$etype] ?? null;
                        if ($target !== null && \Queue\Config::getBool('webhook.fanout.enable.' . str_replace('sync_', '', $target), true)) {
                            $primaryId = $payload['entity_id'] ?? null;
                            if ($primaryId === null && isset($payload['id'])) { $primaryId = $payload['id']; }
                            if ($primaryId === null && isset($payload['product']) && \is_array($payload['product']) && isset($payload['product']['id'])) { $primaryId = $payload['product']['id']; }
                            if ($primaryId === null && isset($payload['customer']) && \is_array($payload['customer']) && isset($payload['customer']['id'])) { $primaryId = $payload['customer']['id']; }
                            if ($primaryId === null && isset($payload['sale']) && \is_array($payload['sale']) && isset($payload['sale']['id'])) { $primaryId = $payload['sale']['id']; }
                            if ($primaryId === null && isset($payload['inventory']) && \is_array($payload['inventory']) && isset($payload['inventory']['product_id'])) { $primaryId = $payload['inventory']['product_id']; }
                            $childPayload = [
                                'webhook_id' => $wid,
                                'webhook_type' => $etype,
                                'entity_id' => $primaryId,
                                'full' => $payload['full'] ?? $payload,
                            ];
                            $idk2 = 'fanout:' . $etype . ':' . ($wid !== '' ? $wid : (string)($payload['event_db_id'] ?? 'unknown'));
                            try { Repo::addJob($target, $childPayload, $idk2); } catch (\Throwable $e) { /* ignore */ }
                        }
                    }
                    Logger::info('webhook.event.completed', ['job_id' => $jobId, 'meta' => ['webhook_id' => $wid, 'type' => $etype, 'rows' => $updated]]);
                } catch (\Throwable $e) {
                    // Do not fail the job for bookkeeping issues; log and continue
                    Logger::warn('webhook.event.bookkeeping_failed', ['job_id' => $jobId, 'meta' => ['err' => $e->getMessage()]]);
                }
                break;
            case 'inventory.command':
                $pidRaw = isset($payload['product_id']) ? (int)$payload['product_id'] : null;
                $oid    = isset($payload['outlet_id']) ? (int)$payload['outlet_id'] : null;
                $target = isset($payload['target']) ? (int)$payload['target'] : null;
                $delta  = isset($payload['delta'])  ? (int)$payload['delta']  : null;

                if ($target === null) {
                    throw new \InvalidArgumentException('inventory.command set requires target');
                }

                // Read current on-hand (fast path; mock returns ok)
                $verify0 = \Queue\Lightspeed\ProductsV21::get($pidRaw);
                $observed0 = \Queue\Lightspeed\ProductsV21::extractOnHand($verify0['body'] ?? null, (int)$oid) ?? 0;
                $deltaToApply = (int)$target - (int)$observed0;

                // If delta is 0, nothing to do; complete early
                if ($deltaToApply === 0) {
                    \Queue\Logger::info('inventory.command.noop.target_reached', ['job_id' => $jobId, 'meta' => [
                        'product_id' => $pidRaw, 'outlet_id' => $oid, 'target' => $target, 'observed' => $observed0,
                    ]]);
                    break;
                }

                $t0 = microtime(true);
                $resp = \Queue\Lightspeed\InventoryV20::adjust([
                    'product_id' => $pidRaw,
                    'outlet_id'  => $oid,
                    'count'      => $deltaToApply,
                    'reason'     => 'stock_take',
                    'note'       => 'inventory.command',
                    // 'idempotency_key' => $payload['idempotency_key'] ?? null,
                ]);
                $durMs = (int)round((microtime(true) - $t0) * 1000);

                $st = (int)($resp['status'] ?? 0);
                if ($st < 200 || $st >= 300) {
                    $msg = is_array($resp['body'] ?? null) ? json_encode($resp['body']) : (string)($resp['body'] ?? '');
                    throw new \RuntimeException('vend_inventory_adjust_failed HTTP ' . $st . ' ' . substr($msg, 0, 300));
                }

                // Verify on-hand moved where we expect (your existing helper)
                $verify = \Queue\Lightspeed\ProductsV21::verifyOnHand($pidRaw, $oid, (int)$target, (int)(\Queue\Config::get('vend.verify_timeout_sec', 12) ?? 12));
                \Queue\Logger::info('inventory.command.verify', ['job_id' => $jobId, 'meta' => [
                    'product_id' => $pidRaw, 'outlet_id' => $oid,
                    'expected' => (int)$target, 'observed' => $verify['observed'] ?? null,
                    'attempts' => $verify['attempts'] ?? 0, 'verified' => $verify['ok'] ?? false
                ]]);
                if (!($verify['ok'] ?? false)) {
                    $obs = $verify['observed'] ?? null;
                    $attempts = (int)($verify['attempts'] ?? 0);
                    throw new \RuntimeException('vend_update_unconfirmed(observed=' . (is_null($obs) ? 'null' : (string)$obs) . ',expected=' . (int)$target . ',attempts=' . $attempts . ')');
                }

                \Queue\Logger::info('inventory.command.vend_confirmed', ['job_id' => $jobId, 'meta' => [
                    'product_id' => $pidRaw, 'outlet_id' => $oid, 'target' => $target, 'status' => $st, 'verified' => true
                ]]);
                break;
            // ... rest of your cases unchanged ...
            // (all other cases remain exactly as in your original code)
            // ...
            default:
                throw new \InvalidArgumentException('Unknown job type: ' . $type);
        }
    }

    /**
     * Safe no-op metric recorder to avoid fatals if metrics backend isn't present.
     *
     * @param string $metricName
     * @param string $jobType
     * @param int $value
     * @param array<string,mixed> $labels
     * @param string|null $sourceOutletId
     * @param string|null $destOutletId
     * @return void
     */
    private static function recordTransferQueueMetric(
        string $metricName,
        string $jobType,
        int $value,
        array $labels = [],
        ?string $sourceOutletId = null,
        ?string $destOutletId = null
    ): void {
        try {
            // Best-effort: log for now; can be wired to DB/webhook_stats later without breaking the worker
            Logger::info('metric.record', [
                'meta' => [
                    'metric' => $metricName,
                    'type' => $jobType,
                    'value' => $value,
                    'labels' => $labels,
                    'source_outlet' => $sourceOutletId,
                    'dest_outlet' => $destOutletId,
                ]
            ]);
        } catch (\Throwable $e) { /* never throw from metrics */ }
    }
}
