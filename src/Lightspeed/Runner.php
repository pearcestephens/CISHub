<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Logger;
use Queue\Config;
use Queue\PdoWorkItemRepository as Repo;
use Queue\Degrade;

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
            case 'inventory.command':
                // payload: { op:'adjust'|'set', product_id:int, outlet_id:int, delta?:int, target?:int, reason?:string, external_ref?:string, correlation_id?:string, dry_run?:bool, group_id?:string }
                $op = (string)($payload['op'] ?? '');
                $pidRaw = (string)($payload['product_id'] ?? '');
                $oid = (int)($payload['outlet_id'] ?? 0);
                $dry = (bool)($payload['dry_run'] ?? false);
                // Inventory flags: global/pipeline/component
                $enabled = (\Queue\FeatureFlags::inventoryPipelineEnabled() && \Queue\FeatureFlags::inventoryCommandEnabled());
                if (\Queue\FeatureFlags::inventoryKillAll()) {
                    self::recordTransferQueueMetric('job_duration_ms', 'inventory.command', 0, [
                        'job_id' => $jobId,
                        'result' => 'noop',
                        'reason' => 'inventory_kill',
                        'op' => $op,
                    ], null, null);
                    \Queue\Logger::info('inventory.command.noop.kill', ['job_id' => $jobId, 'meta' => [ 'product_id' => $pid, 'outlet_id' => $oid, 'op' => $op ]]);
                    break;
                }
                if ($op === '' || $pidRaw === '' || $oid <= 0) {
                    throw new \InvalidArgumentException('inventory.command missing required fields (op, product_id, outlet_id)');
                }
                // Guard: if product does not support inventory, no-op
                try {
                    $pdo = \Queue\PdoConnection::instance();
                    $hasInv = null;
                    // Prefer vend_products.has_inventory
                    $chk = $pdo->prepare('SHOW TABLES LIKE :t'); $chk->execute([':t' => 'vend_products']);
                    if ($chk->fetchColumn()) {
                        $col = $pdo->prepare("SHOW COLUMNS FROM vend_products LIKE 'has_inventory'");
                        $col->execute();
                        if ($col->fetchColumn()) {
                            // If product_id is numeric, query by numeric id; otherwise, skip vend_products check (schema may store numeric ids)
                            if (ctype_digit($pidRaw)) {
                                $s = $pdo->prepare('SELECT has_inventory FROM vend_products WHERE product_id=:pid LIMIT 1');
                                $s->execute([':pid' => (int)$pidRaw]);
                                $v = $s->fetchColumn();
                                if ($v !== false && $v !== null) { $hasInv = ((string)$v === '1' || (string)$v === 'true' || (int)$v === 1); }
                            }
                        }
                    }
                    if ($hasInv === null) {
                        $chk = $pdo->prepare('SHOW TABLES LIKE :t'); $chk->execute([':t' => 'ls_products']);
                        if ($chk->fetchColumn()) {
                            $col = $pdo->prepare("SHOW COLUMNS FROM ls_products LIKE 'has_inventory'");
                            $col->execute();
                            if ($col->fetchColumn()) {
                                // Try both numeric and string forms
                                if (ctype_digit($pidRaw)) {
                                    $s = $pdo->prepare('SELECT has_inventory FROM ls_products WHERE product_id=:pid LIMIT 1');
                                    $s->execute([':pid' => (int)$pidRaw]);
                                    $v = $s->fetchColumn();
                                    if ($v !== false && $v !== null) { $hasInv = ((string)$v === '1' || (string)$v === 'true' || (int)$v === 1); }
                                } else {
                                    try {
                                        $s = $pdo->prepare('SELECT has_inventory FROM ls_products WHERE product_id=:pid LIMIT 1');
                                        $s->execute([':pid' => $pidRaw]);
                                        $v = $s->fetchColumn();
                                        if ($v !== false && $v !== null) { $hasInv = ((string)$v === '1' || (string)$v === 'true' || (int)$v === 1); }
                                    } catch (\Throwable $e2) { /* skip if column type incompatible */ }
                                }
                            }
                        }
                    }
                    if ($hasInv === false) {
                        self::recordTransferQueueMetric('job_duration_ms', 'inventory.command', 0, [
                            'job_id' => $jobId,
                            'result' => 'noop',
                            'reason' => 'not-inventory-product',
                            'op' => $op,
                        ], null, null);
                        \Queue\Logger::info('inventory.command.skip.not_inventory', ['job_id' => $jobId, 'meta' => [
                            'product_id' => $pidRaw,
                            'outlet_id' => $oid,
                            'op' => $op,
                        ]]);
                        break;
                    }
                } catch (\Throwable $e) { /* ignore guard errors */ }
                if ($dry || !$enabled) {
                    // Safe no-op until explicitly enabled and wired for v2.1 semantics
                    self::recordTransferQueueMetric('job_duration_ms', 'inventory.command', 0, [
                        'job_id' => $jobId,
                        'result' => 'noop',
                        'reason' => $dry ? 'dry_run' : 'disabled',
                        'op' => $op,
                    ], null, null);
                    \Queue\Logger::info('inventory.command.noop', ['job_id' => $jobId, 'meta' => [
                        'product_id' => $pidRaw,
                        'outlet_id' => $oid,
                        'op' => $op,
                        'dry_run' => $dry,
                        'enabled' => $enabled,
                    ]]);
                    break;
                }
                // Execute write path via Products v2.1 updateproduct
                $target = isset($payload['target']) ? (int)$payload['target'] : null;
                $delta  = isset($payload['delta']) ? (int)$payload['delta'] : null;
                if ($op === 'set') {
                    if ($target === null) {
                        throw new \InvalidArgumentException('inventory.command set requires target');
                    }
                    $idk = (string)($payload['idempotency_key'] ?? ('invq:' . $pidRaw . ':' . $oid . ':' . $target));
                    $body = [
                        'inventory_update' => [
                            'outlet_id' => (string)$oid,
                            'on_hand' => (int)$target,
                            'source' => 'inventory.command',
                        ],
                        'idempotency_key' => $idk,
                    ];
                    $resp = \Queue\Lightspeed\ProductsV21::update($pidRaw, $body);
                    $st = (int)($resp['status'] ?? 0);
                    if ($st < 200 || $st >= 300) {
                        $msg = is_array($resp['body'] ?? null) ? json_encode($resp['body']) : (string)($resp['body'] ?? '');
                        throw new \RuntimeException('vend_update_failed HTTP ' . $st . ' ' . substr($msg, 0, 300));
                    }
                    // Post-write verification: confirm on_hand reflects the target before reporting success
                    $verify = \Queue\Lightspeed\ProductsV21::verifyOnHand($pidRaw, $oid, (int)$target, (int)(\Queue\Config::get('vend.verify_timeout_sec', 12) ?? 12));
                    \Queue\Logger::info('inventory.command.verify', ['job_id' => $jobId, 'meta' => [
                        'product_id' => $pidRaw, 'outlet_id' => $oid, 'expected' => (int)$target, 'verified' => $verify['ok'] ?? false, 'observed' => $verify['observed'] ?? null, 'attempts' => $verify['attempts'] ?? 0
                    ]]);
                    if (!($verify['ok'] ?? false)) {
                        $obs = $verify['observed'] ?? null;
                        $attempts = (int)($verify['attempts'] ?? 0);
                        throw new \RuntimeException('vend_update_unconfirmed (observed=' . (is_null($obs) ? 'null' : (string)$obs) . ', expected=' . (int)$target . ', attempts=' . $attempts . ')');
                    }
                    \Queue\Logger::info('inventory.command.vend_confirmed', ['job_id' => $jobId, 'meta' => [
                        'product_id' => $pidRaw, 'outlet_id' => $oid, 'op' => $op, 'target' => $target, 'status' => $st, 'verified' => true
                    ]]);
                    break;
                } elseif ($op === 'adjust') {
                    // Not yet supported safely without a reliable current on_hand source; prevent ambiguous writes
                    throw new \InvalidArgumentException('inventory.command adjust not supported; use op=set with target');
                } else {
                    throw new \InvalidArgumentException('inventory.command unknown op');
                }
                break;
            case 'webhook.event':
                // payload: { event_db_id?:int, webhook_id:string, webhook_type:string }
                $wid = (string)($payload['webhook_id'] ?? '');
                $wtype = (string)($payload['webhook_type'] ?? 'vend.generic');
                try {
                    $pdo = \Queue\PdoConnection::instance();
                    // Load event payload and received time for metrics
                    $st = $pdo->prepare('SELECT webhook_type, payload, received_at FROM webhook_events WHERE webhook_id = :wid LIMIT 1');
                    $st->execute([':wid' => $wid]);
                    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
                    $etype = $wtype !== '' ? $wtype : ($row ? (string)$row['webhook_type'] : 'vend.webhook');
                    $eventPayload = $row ? json_decode((string)$row['payload'], true) ?: [] : [];
                    // Fan-out routing (guarded by config)
                    if (\Queue\Config::getBool('webhook.fanout.enabled', true)) {
                        $routes = [
                            'product.update' => 'sync_product',
                            'inventory.update' => 'sync_inventory',
                            'customer.update' => 'sync_customer',
                            'sale.update' => 'sync_sale',
                        ];
                        $target = $routes[$etype] ?? null;
                        if ($target !== null && \Queue\Config::getBool('webhook.fanout.enable.' . str_replace('sync_','',$target), true)) {
                            // Try to extract a primary id; best-effort from common shapes
                            $primaryId = $eventPayload['id']
                                ?? ($eventPayload['product']['id'] ?? null)
                                ?? ($eventPayload['customer']['id'] ?? null)
                                ?? ($eventPayload['sale']['id'] ?? null)
                                ?? ($eventPayload['inventory']['product_id'] ?? null);
                            $childPayload = [
                                'webhook_id' => $wid,
                                'webhook_type' => $etype,
                                'entity_id' => $primaryId,
                                'full' => $eventPayload,
                            ];
                            $idk = 'fanout:' . $etype . ':' . $wid;
                            \Queue\PdoWorkItemRepository::addJob($target, $childPayload, $idk);
                        }
                    }
                    // Mark event completed (idempotent)
                    $stmt = $pdo->prepare("UPDATE webhook_events SET status='completed', processed_at = IFNULL(processed_at, NOW()), processing_attempts = processing_attempts + 1, updated_at = NOW() WHERE webhook_id = :wid");
                    $stmt->execute([':wid' => $wid]);
                    // Metrics: processed_count +1 and processing_time accumulators
                    try {
                        $m = $pdo->prepare("INSERT INTO webhook_stats (recorded_at, webhook_type, metric_name, metric_value, time_period) VALUES (FROM_UNIXTIME(UNIX_TIMESTAMP() - MOD(UNIX_TIMESTAMP(),60)), :t, 'processed_count', 1, '1min') ON DUPLICATE KEY UPDATE metric_value = metric_value + 1");
                        $m->execute([':t' => $etype]);
                        // processing time
                        if ($row && !empty($row['received_at'])) {
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
                } catch (\Throwable $e) {
                    throw new \RuntimeException('webhook.event update failed: ' . $e->getMessage());
                }
                break;
            case 'create_consignment':
                // Canonical envelope -> Vend create body
                // payload: { status:"OPEN", source_outlet_id, dest_outlet_id, lines:[{product_id, sku?, qty}], idempotency_key? }
                $lines = array_map(static function(array $l): array {
                    $row = [
                        'product_id' => (int)$l['product_id'],
                        'quantity'   => (int)$l['qty'],
                    ];
                    if (isset($l['sku']) && $l['sku'] !== '') { $row['sku'] = (string)$l['sku']; }
                    return $row;
                }, (array)($payload['lines'] ?? []));
                $body = [
                    'type' => 'TRANSFER',
                    'outlet_id' => (string)($payload['source_outlet_id'] ?? ''),
                    'outlet_id_destination' => (string)($payload['dest_outlet_id'] ?? ''),
                    'status' => 'OPEN',
                    'products' => $lines,
                ];
                // Forward idempotency header if present (ConsignmentsV20 uses payload.idempotency_key)
                if (isset($payload['idempotency_key'])) { $body['idempotency_key'] = (string)$payload['idempotency_key']; }
                $t0 = microtime(true);
                $resp = ConsignmentsV20::create($body);
                $durMs = (int) round((microtime(true) - $t0) * 1000);

                // Extract vendor IDs/details if available
                $vendId = null; $vendNumber = null; $vendUrl = null;
                $respBody = is_array($resp['body']) ? $resp['body'] : [];
                if (is_array($respBody)) {
                    if (isset($respBody['id'])) { $vendId = (string)$respBody['id']; }
                    if (isset($respBody['consignment']['id'])) { $vendId = (string)$respBody['consignment']['id']; }
                    if (isset($respBody['number'])) { $vendNumber = (string)$respBody['number']; }
                    if (isset($respBody['links']['self'])) { $vendUrl = (string)$respBody['links']['self']; }
                }

                // Optional mapping update into transfers if identifiers provided
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;
                self::updateTransfersMapping($transferPk, $transferPublicId, $vendId, 'open', $vendUrl, $vendNumber, (string)($payload['source_outlet_id'] ?? ''), (string)($payload['dest_outlet_id'] ?? ''));

                // Audit log (best-effort)
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => $vendId,
                    'vend_transfer_id' => $vendId,
                    'action' => 'consignment.create',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'outlet_from' => (string)($payload['source_outlet_id'] ?? ''),
                    'outlet_to' => (string)($payload['dest_outlet_id'] ?? ''),
                    'data_before' => null,
                    'data_after' => $respBody,
                    'metadata' => ['job_id' => $jobId],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => $respBody,
                ]);
                // Transfer logs (best-effort)
                self::insertTransferLog($transferPk, 'CONSIGNMENT_CREATE', [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'status' => 'success',
                    'vend_id' => $vendId,
                    'vend_number' => $vendNumber,
                ], 'info', 'QueueService');
                break;
            case 'sync_product':
                // Minimal stub: accept payload and succeed
                self::insertTransferLog(null, 'SYNC_PRODUCT', [ 'job_id' => $jobId, 'entity_id' => $payload['entity_id'] ?? null ], 'info', 'WebhookFanout');
                break;
            case 'sync_inventory':
                self::insertTransferLog(null, 'SYNC_INVENTORY', [ 'job_id' => $jobId, 'entity_id' => $payload['entity_id'] ?? null ], 'info', 'WebhookFanout');
                break;
            case 'sync_customer':
                self::insertTransferLog(null, 'SYNC_CUSTOMER', [ 'job_id' => $jobId, 'entity_id' => $payload['entity_id'] ?? null ], 'info', 'WebhookFanout');
                break;
            case 'sync_sale':
                self::insertTransferLog(null, 'SYNC_SALE', [ 'job_id' => $jobId, 'entity_id' => $payload['entity_id'] ?? null ], 'info', 'WebhookFanout');
                break;
            case 'push_product_update':
                // payload: { product_id:int, data:array, idempotency_key?:string }
                $pid = (int)($payload['product_id'] ?? 0);
                $data = (array)($payload['data'] ?? []);
                if ($pid <= 0 || !$data) { throw new \InvalidArgumentException('push_product_update requires product_id and data'); }
                if (isset($payload['idempotency_key']) && is_string($payload['idempotency_key'])) {
                    $data['idempotency_key'] = (string)$payload['idempotency_key'];
                }
                $t0 = microtime(true);
                $resp = ProductsV21::update($pid, $data);
                $durMs = (int) round((microtime(true) - $t0) * 1000);
                // Best-effort metric
                self::recordTransferQueueMetric('job_duration_ms', 'push_product_update', $durMs, [ 'job_id' => $jobId, 'status' => $resp['status'] ?? null ], null, null);
                // Log entry
                self::insertTransferLog(null, 'PRODUCT_UPDATE', [ 'job_id' => $jobId, 'product_id' => $pid, 'duration_ms' => $durMs, 'status' => $resp['status'] ?? null ], 'info', 'QueueService');
                break;
            case 'pull_products':
            case 'pull_inventory':
            case 'pull_consignments':
                // Placeholder handlers: acknowledge and succeed to avoid Unknown job type
                // Future: implement cursor-driven pulls updating ls_* mirrors
                \Queue\Logger::info('pull_task.noop', [
                    'job_id' => $jobId,
                    'meta' => [ 'type' => $type, 'note' => 'stub handler - no-op' ],
                ]);
                break;
            case 'update_consignment':
                // payload: { consignment_id, status:"SENT|RECEIVED", lines:[{product_id, sku?, qty}], idempotency_key? }
                $status = strtoupper((string)($payload['status'] ?? ''));
                if (!in_array($status, ['SENT','RECEIVED'], true)) {
                    throw new \InvalidArgumentException('update_consignment requires status SENT or RECEIVED');
                }
                $products = array_map(static function(array $l) use ($status): array {
                    $row = [
                        'product_id' => (int)$l['product_id'],
                        'quantity' => $status === 'SENT' ? (int)$l['qty'] : null,
                        'quantity_received' => $status === 'RECEIVED' ? (int)$l['qty'] : null,
                    ];
                    // drop nulls
                    return array_filter($row, static fn($v) => $v !== null);
                }, (array)($payload['lines'] ?? []));
                $body = [
                    'status' => $status,
                    'products' => $products,
                ];
                if (isset($payload['idempotency_key'])) { $body['idempotency_key'] = (string)$payload['idempotency_key']; }
                $t0 = microtime(true);
                $resp = ConsignmentsV20::updateFull((int)$payload['consignment_id'], $body);
                $durMs = (int) round((microtime(true) - $t0) * 1000);

                $respBody = is_array($resp['body']) ? $resp['body'] : [];
                $vendId = null; if (is_array($respBody)) { if (isset($respBody['id'])) { $vendId = (string)$respBody['id']; } elseif (isset($payload['consignment_id'])) { $vendId = (string)$payload['consignment_id']; } }
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;

                // Map status to transfers table equivalent (lowercase per schema)
                $map = ['SENT' => 'sent', 'RECEIVED' => 'received'];
                $toStatus = $map[$status] ?? null;
                self::updateTransfersMapping($transferPk, $transferPublicId, $vendId, $toStatus, null, null, (string)($payload['source_outlet_id'] ?? ''), (string)($payload['dest_outlet_id'] ?? ''));

                // Audit log
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => $vendId,
                    'vend_transfer_id' => $vendId,
                    'action' => 'consignment.update',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'outlet_from' => (string)($payload['source_outlet_id'] ?? ''),
                    'outlet_to' => (string)($payload['dest_outlet_id'] ?? ''),
                    'data_before' => null,
                    'data_after' => $respBody,
                    'metadata' => ['job_id' => $jobId, 'status' => $status],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => $respBody,
                ]);
                self::insertTransferLog($transferPk, 'CONSIGNMENT_UPDATE_' . $status, [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'status' => 'success',
                    'vend_id' => $vendId,
                    'to_status' => $toStatus,
                ], 'info', 'QueueService');
                break;
            case 'edit_consignment_lines':
                // payload: { consignment_id, add:[{product_id, qty, sku?}]?, remove:[{product_id}]?, replace:[{product_id, qty, sku?}]?, idempotency_key?, transfer_pk? | transfer_public_id? }
                // Approach: PATCH with products array representing desired delta or replacement depending on LS support. Here we send provided 'replace' if present; else apply 'add' and imply removing by setting 0 quantities if allowed.
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;
                $products = [];
                if (!empty($payload['replace'])) {
                    foreach ((array)$payload['replace'] as $l) {
                        $row = ['product_id' => (int)$l['product_id'], 'quantity' => (int)$l['qty']];
                        if (isset($l['sku']) && $l['sku'] !== '') { $row['sku'] = (string)$l['sku']; }
                        $products[] = $row;
                    }
                } else {
                    foreach ((array)($payload['add'] ?? []) as $l) {
                        $row = ['product_id' => (int)$l['product_id'], 'quantity' => (int)$l['qty']];
                        if (isset($l['sku']) && $l['sku'] !== '') { $row['sku'] = (string)$l['sku']; }
                        $products[] = $row;
                    }
                    foreach ((array)($payload['remove'] ?? []) as $l) {
                        // Some APIs support removing lines via zero quantity or an explicit remove op; we encode as quantity=0 here.
                        $products[] = ['product_id' => (int)$l['product_id'], 'quantity' => 0];
                    }
                }
                $body = ['products' => $products];
                if (isset($payload['idempotency_key'])) { $body['idempotency_key'] = (string)$payload['idempotency_key']; }
                $t0 = microtime(true);
                $resp = ConsignmentsV20::updatePartial((int)$payload['consignment_id'], $body);
                $durMs = (int) round((microtime(true) - $t0) * 1000);
                $respBody = is_array($resp['body']) ? $resp['body'] : [];
                $vendId = isset($payload['consignment_id']) ? (string)$payload['consignment_id'] : null;
                // Local mapping: no status change, but ensure vend_transfer_id present.
                self::updateTransfersMapping($transferPk, $transferPublicId, $vendId, null, null, null, null, null);
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => $vendId,
                    'vend_transfer_id' => $vendId,
                    'action' => 'consignment.edit_lines',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'data_before' => null,
                    'data_after' => $respBody,
                    'metadata' => ['job_id' => $jobId, 'ops' => [
                        'add' => isset($payload['add']) ? count((array)$payload['add']) : 0,
                        'remove' => isset($payload['remove']) ? count((array)$payload['remove']) : 0,
                        'replace' => isset($payload['replace']) ? count((array)$payload['replace']) : 0,
                    ]],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => $respBody,
                ]);
                self::insertTransferLog($transferPk, 'CONSIGNMENT_EDIT_LINES', [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'vend_id' => $vendId,
                ], 'info', 'QueueService');
                break;
            case 'add_consignment_products':
                // payload: { consignment_id:int, lines:[{product_id:int, qty:int, sku?:string}], idempotency_key?:string, transfer_pk?:int, transfer_public_id?:string }
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;
                $products = array_map(static function(array $l): array {
                    $row = [
                        'product_id' => (int)$l['product_id'],
                        'quantity' => (int)$l['qty'],
                    ];
                    if (isset($l['sku']) && $l['sku'] !== '') { $row['sku'] = (string)$l['sku']; }
                    return $row;
                }, (array)($payload['lines'] ?? []));
                $body = ['products' => $products];
                if (isset($payload['idempotency_key'])) { $body['idempotency_key'] = (string)$payload['idempotency_key']; }
                $t0 = microtime(true);
                $resp = ConsignmentsV20::addProducts((int)$payload['consignment_id'], $body);
                $durMs = (int) round((microtime(true) - $t0) * 1000);
                $respBody = is_array($resp['body']) ? $resp['body'] : [];
                $vendId = isset($payload['consignment_id']) ? (string)$payload['consignment_id'] : null;
                // Ensure mapping exists
                self::updateTransfersMapping($transferPk, $transferPublicId, $vendId, null, null, null, null, null);
                // Audit
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => $vendId,
                    'vend_transfer_id' => $vendId,
                    'action' => 'consignment.add_products',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'data_before' => null,
                    'data_after' => $respBody,
                    'metadata' => ['job_id' => $jobId, 'lines' => count($products)],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => $respBody,
                ]);
                // Log
                self::insertTransferLog($transferPk, 'CONSIGNMENT_ADD_PRODUCTS', [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'vend_id' => $vendId,
                    'lines' => count($products),
                ], 'info', 'QueueService');
                break;
            case 'cancel_consignment':
                // payload: { consignment_id, idempotency_key?, transfer_pk?, transfer_public_id? }
                // Some LS tenants may support cancelling via PATCH/PUT; we send status=CANCELLED where supported.
                $body = ['status' => 'CANCELLED'];
                if (isset($payload['idempotency_key'])) { $body['idempotency_key'] = (string)$payload['idempotency_key']; }
                $t0 = microtime(true);
                $resp = ConsignmentsV20::updatePartial((int)$payload['consignment_id'], $body);
                $durMs = (int) round((microtime(true) - $t0) * 1000);
                $respBody = is_array($resp['body']) ? $resp['body'] : [];
                $vendId = isset($payload['consignment_id']) ? (string)$payload['consignment_id'] : null;
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;
                self::updateTransfersMapping($transferPk, $transferPublicId, $vendId, 'cancelled', null, null, null, null);
                $outstanding = self::computeOutstanding($transferPk);
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => $vendId,
                    'vend_transfer_id' => $vendId,
                    'action' => 'consignment.cancel',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'data_before' => null,
                    'data_after' => $respBody,
                    'metadata' => ['job_id' => $jobId, 'outstanding' => $outstanding],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => $respBody,
                ]);
                self::insertTransferLog($transferPk, 'CONSIGNMENT_CANCEL', [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'status' => 'success',
                    'vend_id' => $vendId,
                    'outstanding' => $outstanding,
                ], 'info', 'QueueService');
                break;
            case 'mark_transfer_partial':
                // payload: { transfer_pk? or transfer_public_id?, outstanding_lines:int? (advisory) }
                $transferPk = isset($payload['transfer_pk']) && is_numeric($payload['transfer_pk']) ? (int)$payload['transfer_pk'] : null;
                $transferPublicId = isset($payload['transfer_public_id']) ? (string)$payload['transfer_public_id'] : null;
                // No external API call; purely internal state to reflect partial progress.
                $t0 = microtime(true);
                self::updateTransfersMapping($transferPk, $transferPublicId, null, 'partial', null, null, null, null);
                $durMs = (int) round((microtime(true) - $t0) * 1000);
                self::insertTransferAudit([
                    'entity_type' => 'transfer',
                    'entity_pk' => $transferPk,
                    'transfer_pk' => $transferPk,
                    'transfer_id' => $transferPublicId,
                    'vend_consignment_id' => null,
                    'vend_transfer_id' => null,
                    'action' => 'transfer.partial_mark',
                    'status' => 'success',
                    'actor_type' => 'system',
                    'actor_id' => 'queue.lightspeed',
                    'data_before' => null,
                    'data_after' => ['outstanding_lines' => $payload['outstanding_lines'] ?? null],
                    'metadata' => ['job_id' => $jobId],
                    'error_details' => null,
                    'processing_time_ms' => $durMs,
                    'api_response' => null,
                ]);
                self::insertTransferLog($transferPk, 'TRANSFER_MARK_PARTIAL', [
                    'job_id' => $jobId,
                    'duration_ms' => $durMs,
                    'outstanding_lines' => $payload['outstanding_lines'] ?? null,
                ], 'info', 'QueueService');
                break;
            default:
                throw new \InvalidArgumentException('Unknown job type: ' . $type);
        }
    }

    /** Guarded metrics insert into transfer_queue_metrics */
    private static function recordTransferQueueMetric(string $metricType, string $jobType, int $valueMs, array $meta = [], ?string $outletFrom = null, ?string $outletTo = null): void
    {
        try {
            $pdo = \Queue\PdoConnection::instance();
            if (!self::tableExists($pdo, 'transfer_queue_metrics')) { return; }
            $stmt = $pdo->prepare('INSERT INTO transfer_queue_metrics (metric_type, queue_name, job_type, value, unit, metadata, outlet_from, outlet_to, worker_id, recorded_at) VALUES (:mt, :qn, :jt, :val, :unit, :md, :of, :ot, :wid, NOW())');
            $stmt->execute([
                ':mt' => $metricType,
                ':qn' => 'lightspeed',
                ':jt' => $jobType,
                ':val' => $valueMs,
                ':unit' => 'ms',
                ':md' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':of' => $outletFrom,
                ':ot' => $outletTo,
                ':wid' => null,
            ]);
        } catch (\Throwable $e) { /* swallow */ }
    }

    /** Guarded audit insert into transfer_audit_log */
    private static function insertTransferAudit(array $data): void
    {
        try {
            $pdo = \Queue\PdoConnection::instance();
            if (!self::tableExists($pdo, 'transfer_audit_log')) { return; }
            $stmt = $pdo->prepare('INSERT INTO transfer_audit_log (entity_type, entity_pk, transfer_pk, transfer_id, vend_consignment_id, vend_transfer_id, action, status, actor_type, actor_id, outlet_from, outlet_to, data_before, data_after, metadata, error_details, processing_time_ms, api_response, session_id, ip_address, user_agent, created_at) VALUES (:entity_type, :entity_pk, :transfer_pk, :transfer_id, :vend_consignment_id, :vend_transfer_id, :action, :status, :actor_type, :actor_id, :outlet_from, :outlet_to, :data_before, :data_after, :metadata, :error_details, :processing_time_ms, :api_response, NULL, NULL, NULL, NOW())');
            $stmt->execute([
                ':entity_type' => (string)($data['entity_type'] ?? 'transfer'),
                ':entity_pk' => $data['entity_pk'] ?? null,
                ':transfer_pk' => $data['transfer_pk'] ?? null,
                ':transfer_id' => $data['transfer_id'] ?? null,
                ':vend_consignment_id' => $data['vend_consignment_id'] ?? null,
                ':vend_transfer_id' => $data['vend_transfer_id'] ?? null,
                ':action' => (string)($data['action'] ?? 'unknown'),
                ':status' => (string)($data['status'] ?? 'info'),
                ':actor_type' => (string)($data['actor_type'] ?? 'system'),
                ':actor_id' => (string)($data['actor_id'] ?? 'queue'),
                ':outlet_from' => $data['outlet_from'] ?? null,
                ':outlet_to' => $data['outlet_to'] ?? null,
                ':data_before' => isset($data['data_before']) ? json_encode($data['data_before'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':data_after' => isset($data['data_after']) ? json_encode($data['data_after'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':metadata' => isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':error_details' => isset($data['error_details']) ? json_encode($data['error_details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':processing_time_ms' => isset($data['processing_time_ms']) ? (int)$data['processing_time_ms'] : null,
                ':api_response' => isset($data['api_response']) ? json_encode($data['api_response'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (\Throwable $e) { /* swallow */ }
    }

    /** Guarded update into transfers mapping */
    private static function updateTransfersMapping(?int $transferPk, ?string $transferPublicId, ?string $vendTransferId, ?string $toStatus, ?string $vendUrl, ?string $vendNumber, ?string $outletFrom, ?string $outletTo): void
    {
        if ($transferPk === null && ($transferPublicId === null || $transferPublicId === '')) { return; }
        try {
            $pdo = \Queue\PdoConnection::instance();
            if (!self::tableExists($pdo, 'transfers')) { return; }
            $sets = [];$params = [];
            if ($vendTransferId !== null && $vendTransferId !== '') { $sets[] = 'vend_transfer_id = :vtid'; $params[':vtid'] = $vendTransferId; $sets[] = "vend_resource = 'consignment'"; }
            if ($vendUrl !== null && $vendUrl !== '') { $sets[] = 'vend_url = :vurl'; $params[':vurl'] = $vendUrl; }
            if ($vendNumber !== null && $vendNumber !== '') { $sets[] = 'vend_number = :vnum'; $params[':vnum'] = $vendNumber; }
            if ($toStatus !== null && $toStatus !== '') { $sets[] = 'status = :st'; $params[':st'] = $toStatus; }
            if ($outletFrom !== null && $outletFrom !== '') { $sets[] = 'outlet_from = :of'; $params[':of'] = $outletFrom; }
            if ($outletTo !== null && $outletTo !== '') { $sets[] = 'outlet_to = :ot'; $params[':ot'] = $outletTo; }
            if (!$sets) { return; }
            $sql = 'UPDATE transfers SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE ';
            if ($transferPk !== null) { $sql .= 'id = :id'; $params[':id'] = $transferPk; }
            elseif ($transferPublicId !== null) { $sql .= 'public_id = :pid'; $params[':pid'] = $transferPublicId; }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } catch (\Throwable $e) { /* swallow */ }
    }

    private static function tableExists(\PDO $pdo, string $table): bool
    {
        try { $stmt = $pdo->prepare("SHOW TABLES LIKE :t"); $stmt->execute([':t' => $table]); return (bool)$stmt->fetchColumn(); } catch (\Throwable $e) { return false; }
    }

    /** Guarded insert into transfer_logs (accepts optional shipment/item/parcel/staff/customer/actor fields) */
    private static function insertTransferLog(?int $transferPk, string $eventType, array $eventData, string $severity = 'info', string $source = 'Queue', array $opt = []) : void
    {
        if ($transferPk === null || $transferPk <= 0) { return; }
        try {
            $pdo = \Queue\PdoConnection::instance();
            if (!self::tableExists($pdo, 'transfer_logs')) { return; }
            $columns = ['transfer_id','event_type','event_data','actor_user_id','actor_role','severity','source_system','trace_id','shipment_id','item_id','parcel_id','staff_transfer_id','customer_id','created_at'];
            $sql = 'INSERT INTO transfer_logs (transfer_id, event_type, event_data, actor_user_id, actor_role, severity, source_system, trace_id, shipment_id, item_id, parcel_id, staff_transfer_id, customer_id, created_at) VALUES (:tid, :evt, :edata, :actor_user_id, :actor_role, :sev, :src, :trace, :shipment_id, :item_id, :parcel_id, :staff_transfer_id, :customer_id, NOW())';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tid' => $transferPk,
                ':evt' => $eventType,
                ':edata' => json_encode($eventData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':actor_user_id' => $opt['actor_user_id'] ?? null,
                ':actor_role' => $opt['actor_role'] ?? null,
                ':sev' => $severity,
                ':src' => $source,
                ':trace' => $opt['trace_id'] ?? ('job:' . uniqid('', true)),
                ':shipment_id' => $opt['shipment_id'] ?? null,
                ':item_id' => $opt['item_id'] ?? null,
                ':parcel_id' => $opt['parcel_id'] ?? null,
                ':staff_transfer_id' => $opt['staff_transfer_id'] ?? null,
                ':customer_id' => $opt['customer_id'] ?? null,
            ]);
        } catch (\Throwable $e) { /* swallow */ }
    }

    /** Compute outstanding quantities per item for transfer, best-effort. */
    private static function computeOutstanding(?int $transferPk): array
    {
        $result = ['total_outstanding' => 0, 'items' => []];
        if ($transferPk === null || $transferPk <= 0) { return $result; }
        try {
            $pdo = \Queue\PdoConnection::instance();
            if (!self::tableExists($pdo, 'transfer_items')) { return $result; }
            $st = $pdo->prepare('SELECT product_id, qty_requested, qty_sent_total, qty_received_total FROM transfer_items WHERE transfer_id = :id');
            $st->execute([':id' => $transferPk]);
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $total = 0; $items = [];
            foreach ($rows as $r) {
                $req = (int)$r['qty_requested'];
                $sent = (int)$r['qty_sent_total'];
                $recv = (int)$r['qty_received_total'];
                $out = max(0, $req - max($sent, $recv));
                if ($out > 0) {
                    $items[] = [ 'product_id' => (string)$r['product_id'], 'outstanding' => $out, 'requested' => $req, 'sent_total' => $sent, 'received_total' => $recv ];
                    $total += $out;
                }
            }
            $result['total_outstanding'] = $total;
            $result['items'] = $items;
        } catch (\Throwable $e) { /* best-effort */ }
        return $result;
    }
}
