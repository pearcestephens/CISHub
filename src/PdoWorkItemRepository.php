<?php
declare(strict_types=1);

namespace Queue;

use PDO;

/**
 * PdoWorkItemRepository
 *
 * Public contract (unchanged):
 *   - addJob(string $type, array $payload, ?string $idempotencyKey = null): int
 *   - heartbeat(int $id): void
 *   - claimBatch(int $limit = 50, ?string $type = null): array<WorkItem>
 *   - complete(int $id): void
 *   - fail(int $id, string $error): void
 *
 * Notes
 * -----
 * - Supports both "modern" schema (numeric PK `id`) and "legacy" schema (uuid PK `job_id`)
 * - Where columns exist, we use:
 *     next_run_at, leased_until, heartbeat_at, priority, updated_at, finished_at/completed_at, last_error
 * - Idempotency:
 *     Uses a short advisory lock keyed on idempotency_key to avoid duplicate inserts across callers.
 * - Claiming:
 *     Prefers SKIP LOCKED; falls back to FOR UPDATE; final fallback is an UPDATE+SELECT pattern.
 * - Logging:
 *     Tries modern `ls_job_logs(job_id, level, message, correlation_id)` first, then legacy variants.
 */
final class PdoWorkItemRepository
{
    /** Cached detected schema capabilities */
    private static array $schema;

    /** One-time detection of table/column capabilities (cached) */
    private static function detectSchema(PDO $pdo): void
    {
        if (isset(self::$schema)) return;

        $cols = $pdo->query('SHOW COLUMNS FROM ls_jobs')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn($r) => (string)$r['Field'], $cols);

        $legacy       = in_array('job_id', $names, true) && !in_array('id', $names, true);
        $hasNext      = in_array('next_run_at',  $names, true);
        $hasLease     = in_array('leased_until', $names, true);
        $hasHeartbeat = in_array('heartbeat_at', $names, true);
        $hasPriority  = in_array('priority',     $names, true);
        $hasUpdated   = in_array('updated_at',   $names, true);
        $hasFinished  = in_array('finished_at',  $names, true);
        $hasCompleted = in_array('completed_at', $names, true);
        $hasLastErr   = in_array('last_error',   $names, true);
        $hasStarted   = in_array('started_at',   $names, true);

        $statusWorking = $legacy ? 'running'  : 'working';
        $statusDone    = $legacy ? 'completed': 'done';
        $statusFailed  = 'failed';

        // Legacy numeric mapping table present?
        $legacyMap = false;
        try {
            $legacyMap = (bool)$pdo->query("SHOW TABLES LIKE 'ls_jobs_map'")->fetchColumn();
        } catch (\Throwable $e) {}

        self::$schema = [
            'legacy'            => $legacy,
            'legacy_map'        => $legacyMap,
            'pk'                => $legacy ? 'job_id' : 'id',
            'status_working'    => $statusWorking,
            'status_done'       => $statusDone,
            'status_failed'     => $statusFailed,
            'has_next_run_at'   => $hasNext,
            'has_lease'         => $hasLease,
            'has_heartbeat'     => $hasHeartbeat,
            'has_priority'      => $hasPriority,
            'has_updated'       => $hasUpdated,
            'has_finished_at'   => $hasFinished,
            'has_completed_at'  => $hasCompleted,
            'has_last_error'    => $hasLastErr,
            'has_started_at'    => $hasStarted,
        ];

        // Ensure legacy numeric map table (id <-> job_id) exists if needed
        if ($legacy && !$legacyMap) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ls_jobs_map(
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    job_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                    PRIMARY KEY(id),
                    UNIQUE KEY uniq_job(job_id)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$schema['legacy_map'] = true;
        }
    }

    /** RFC4122-ish UUID v4 for legacy job_id/log_id */
    private static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        $hex = bin2hex($d);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4),
            substr($hex, 12, 4), substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /** Safe JSON encode for payload/meta */
    private static function jenc($v): string
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Insert a job if not present (idempotency aware) */
    public static function addJob(string $type, array $payload, ?string $idempotencyKey = null): int
    {
        $priority = isset($payload['priority']) ? (int)$payload['priority'] : 5;
        if ($priority < 1) $priority = 1;
        if ($priority > 9) $priority = 9;

        return PdoConnection::transaction(static function (PDO $pdo) use ($type, $payload, $idempotencyKey, $priority): int {
            self::detectSchema($pdo);

            // Advisory lock scoped by hashed idempotency key to avoid dup inserts under concurrency
            $lockKey = null; $gotLock = false;
            if ($idempotencyKey) {
                $lockKey = 'ls_jobs:idk:' . hash('sha256', $idempotencyKey);
                try {
                    $st = $pdo->prepare('SELECT GET_LOCK(:lk, 5) AS got');
                    $st->execute([':lk' => $lockKey]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    $gotLock = $row && (int)$row['got'] === 1;
                } catch (\Throwable $e) { /* best-effort */ }
            }

            try {
                // Preflight: same idempotency_key?
                if ($idempotencyKey) {
                    if (!self::$schema['legacy']) {
                        $s = $pdo->prepare('SELECT id FROM ls_jobs WHERE idempotency_key = :k LIMIT 1');
                        $s->execute([':k' => $idempotencyKey]);
                        $r = $s->fetch(PDO::FETCH_ASSOC);
                        if ($r) return (int)$r['id'];
                    } else {
                        $s = $pdo->prepare('SELECT job_id FROM ls_jobs WHERE idempotency_key = :k LIMIT 1');
                        $s->execute([':k' => $idempotencyKey]);
                        $jid = (string)($s->fetchColumn() ?: '');
                        if ($jid !== '') {
                            // Ensure numeric map exists
                            $pdo->prepare('INSERT IGNORE INTO ls_jobs_map(job_id) VALUES(:j)')->execute([':j' => $jid]);
                            $sel = $pdo->prepare('SELECT id FROM ls_jobs_map WHERE job_id = :j LIMIT 1');
                            $sel->execute([':j' => $jid]);
                            $mid = (int)($sel->fetchColumn() ?: 0);
                            if ($mid > 0) return $mid;
                        }
                    }
                }

                // Insert
                $trace = is_array($payload) && isset($payload['trace_id']) && is_string($payload['trace_id'])
                    ? (string)$payload['trace_id'] : Http::requestId();

                if (!self::$schema['legacy']) {
                    $pdo->prepare(
                        'INSERT INTO ls_jobs (type, priority, payload, idempotency_key)
                         VALUES (:t, :pr, :p, :k)'
                    )->execute([
                        ':t'  => $type,
                        ':pr' => $priority,
                        ':p'  => self::jenc($payload),
                        ':k'  => $idempotencyKey,
                    ]);

                    $id = (int)$pdo->lastInsertId();
                    self::log($pdo, $id, 'info', 'job.created', $trace);
                    return $id;
                }

                // Legacy path
                $jobId = self::uuid();
                $pdo->prepare(
                    "INSERT INTO ls_jobs (job_id, type, payload, idempotency_key, status, priority, attempts, max_attempts, created_at, updated_at)
                     VALUES (:id, :t, :p, :k, 'pending', :pr, 0, 6, NOW(), NOW())"
                )->execute([
                    ':id' => $jobId,
                    ':t'  => $type,
                    ':p'  => self::jenc($payload),
                    ':k'  => $idempotencyKey,
                    ':pr' => $priority,
                ]);

                $pdo->prepare('INSERT INTO ls_jobs_map(job_id) VALUES(:j)
                               ON DUPLICATE KEY UPDATE job_id = VALUES(job_id)')
                    ->execute([':j' => $jobId]);

                $id = (int)$pdo->lastInsertId();
                if ($id === 0) {
                    $id = (int)$pdo->query("SELECT id FROM ls_jobs_map WHERE job_id = '" . str_replace("'", "''", $jobId) . "'")->fetchColumn();
                }
                self::log($pdo, $id, 'info', 'job.created', $trace);
                return $id;
            } finally {
                if ($idempotencyKey && $gotLock && $lockKey) {
                    try { $pdo->prepare('SELECT RELEASE_LOCK(:lk)')->execute([':lk' => $lockKey]); } catch (\Throwable $e) {}
                }
            }
        });
    }

    /** Update heartbeat and extend lease where available */
    public static function heartbeat(int $id): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id): void {
            self::detectSchema($pdo);
            if (!self::$schema['legacy'] && (self::$schema['has_heartbeat'] || self::$schema['has_lease'])) {
                $sql = "UPDATE ls_jobs
                        SET " .
                       (self::$schema['has_heartbeat'] ? "heartbeat_at = NOW()," : "") .
                       (self::$schema['has_lease']     ? "leased_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)," : "") .
                       (self::$schema['has_updated']   ? "updated_at = NOW()," : "") .
                       " status = status
                        WHERE id = :id AND status = :st";
                $pdo->prepare($sql)->execute([':id'=>$id, ':st'=>self::$schema['status_working']]);
            }
        });
    }

    /**
     * Claim a batch of jobs (pending) optionally filtered by type.
     * Returns normalized WorkItem objects with numeric id (legacy mapped via ls_jobs_map).
     */
    public static function claimBatch(int $limit = 50, ?string $type = null): array
    {
        return PdoConnection::transaction(static function (PDO $pdo) use ($limit, $type): array {
            self::detectSchema($pdo);

            $limit = max(1, min(200, $limit));
            $rows = [];

            // Base SELECT
            if (!self::$schema['legacy']) {
                $base = "SELECT id, type, payload, attempts
                         FROM ls_jobs
                         WHERE status = 'pending' " .
                        (self::$schema['has_next_run_at'] ? " AND (next_run_at IS NULL OR next_run_at <= NOW())" : "") .
                        ($type ? " AND type = :type" : "") .
                        " ORDER BY " . (self::$schema['has_priority'] ? "priority" : "created_at") . " ASC" .
                        (self::$schema['has_updated'] ? ", updated_at ASC" : "") .
                        " LIMIT :lim";
                $rows = self::selectForClaim($pdo, $base, $type, $limit);
            } else {
                // Legacy path — multi-stage fallback
                $base = "SELECT job_id, type, payload, attempts
                         FROM ls_jobs
                         WHERE status = 'pending' " .
                        ($type ? " AND type = :type" : "") .
                        " ORDER BY " . (self::$schema['has_priority'] ? "priority" : "created_at") .
                        (self::$schema['has_updated'] ? ", updated_at" : "") .
                        " LIMIT :lim";
                $rows = self::selectForClaim($pdo, $base, $type, $limit, true);
            }

            if (!$rows) return [];

            // Transition to working
            if (!self::$schema['legacy']) {
                $ids = array_map(static fn($r) => (int)$r['id'], $rows);
                if ($ids) {
                    $place = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "UPDATE ls_jobs
                            SET status = '" . self::$schema['status_working'] . "',
                                started_at = NOW() " .
                                (self::$schema['has_lease'] ? ", leased_until = DATE_ADD(NOW(), INTERVAL 2 MINUTE)" : "") .
                                (self::$schema['has_updated'] ? ", updated_at = NOW()" : "") .
                            " WHERE id IN($place)";
                    $pdo->prepare($sql)->execute($ids);

                    // Log claims
                    foreach ($ids as $nid) {
                        $trace = null;
                        try {
                            $s = $pdo->prepare('SELECT payload FROM ls_jobs WHERE id = :i');
                            $s->execute([':i' => $nid]);
                            $p = json_decode((string)($s->fetchColumn() ?: ''), true) ?: [];
                            if (isset($p['trace_id']) && is_string($p['trace_id'])) $trace = (string)$p['trace_id'];
                        } catch (\Throwable $e) {}
                        self::log($pdo, $nid, 'info', 'job.claimed', $trace ?? Http::requestId());
                    }
                }

                // Normalize rows to WorkItem[]
                $out = [];
                foreach ($rows as $r) {
                    $j = new WorkItem();
                    $j->id          = (int)$r['id'];
                    $j->type        = (string)$r['type'];
                    $j->payload     = json_decode((string)$r['payload'], true) ?: [];
                    $j->status      = self::$schema['status_working'];
                    $j->attempts    = (int)$r['attempts'];
                    $j->started_at  = null;
                    $j->finished_at = null;
                    $out[] = $j;
                }
                return $out;
            }

            // Legacy: map job_id -> numeric id and update to running
            $jobIds = array_values(array_unique(array_map(static fn($r) => (string)$r['job_id'], $rows)));
            if ($jobIds) {
                $place = implode(',', array_fill(0, count($jobIds), '?'));
                $pdo->prepare("INSERT IGNORE INTO ls_jobs_map(job_id) VALUES " .
                    implode(',', array_fill(0, count($jobIds), '(?)')))->execute($jobIds);

                $map = $pdo->prepare("SELECT job_id, id FROM ls_jobs_map WHERE job_id IN ($place)");
                $map->execute($jobIds);
                $mRows = $map->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $toId = [];
                foreach ($mRows as $mr) $toId[(string)$mr['job_id']] = (int)$mr['id'];

                // mark running
                $pdo->prepare(
                    "UPDATE ls_jobs SET status = '" . self::$schema['status_working'] . "', started_at = NOW()
                     WHERE job_id IN ($place)"
                )->execute($jobIds);

                $out = [];
                foreach ($rows as $r) {
                    $jid = (string)$r['job_id'];
                    $nid = $toId[$jid] ?? 0;
                    if ($nid > 0) {
                        $j = new WorkItem();
                        $j->id          = $nid;
                        $j->type        = (string)$r['type'];
                        $j->payload     = json_decode((string)$r['payload'], true) ?: [];
                        $j->status      = self::$schema['status_working'];
                        $j->attempts    = (int)$r['attempts'];
                        $j->started_at  = null;
                        $j->finished_at = null;
                        $out[] = $j;

                        self::log($pdo, $nid, 'info', 'job.claimed', $j->payload['trace_id'] ?? Http::requestId());
                    }
                }
                return $out;
            }

            return [];
        });
    }

    /**
     * Try claim SELECT with best path first, then fallbacks.
     * @return array<int, array<string,mixed>>
     */
    private static function selectForClaim(PDO $pdo, string $baseSql, ?string $type, int $limit, bool $isLegacy = false): array
    {
        $rows = [];

        // 1) SKIP LOCKED
        $sql1 = preg_replace('/\s+LIMIT\s+:lim$/', ' FOR UPDATE SKIP LOCKED LIMIT :lim', $baseSql) ?: $baseSql;
        $rows = self::trySelect($pdo, $sql1, $type, $limit);
        if ($rows) return $rows;

        // 2) FOR UPDATE without SKIP LOCKED
        $sql2 = preg_replace('/\s+LIMIT\s+:lim$/', ' FOR UPDATE LIMIT :lim', $baseSql) ?: $baseSql;
        $rows = self::trySelect($pdo, $sql2, $type, $limit);
        if ($rows) return $rows;

        // 3) Plain LIMIT (race-prone but last resort)
        $rows = self::trySelect($pdo, $baseSql, $type, $limit);
        if ($rows) return $rows;

        // 4) Legacy specific: UPDATE+SELECT fallback to force status flip
        if ($isLegacy) {
            $whereType = $type ? ' AND type = :t' : '';
            $params    = [];
            if ($type) $params[':t'] = $type;

            $claim = $pdo->prepare(
                "UPDATE ls_jobs j
                 JOIN (
                    SELECT job_id FROM ls_jobs
                    WHERE status = 'pending' $whereType
                    ORDER BY " . (self::$schema['has_priority'] ? "priority" : "created_at") . "
                    LIMIT :lim
                 ) x ON j.job_id = x.job_id
                 SET j.status = '" . self::$schema['status_working'] . "', j.started_at = NOW()"
            );
            foreach ($params as $k=>$v) $claim->bindValue($k, $v);
            $claim->bindValue(':lim', $limit, PDO::PARAM_INT);
            try { $claim->execute(); } catch (\Throwable $e) {}

            $sel = $pdo->prepare(
                "SELECT job_id, type, payload, attempts
                 FROM ls_jobs
                 WHERE status = '" . self::$schema['status_working'] . "' $whereType
                 ORDER BY started_at DESC
                 LIMIT :lim"
            );
            foreach ($params as $k=>$v) $sel->bindValue($k, $v);
            $sel->bindValue(':lim', $limit, PDO::PARAM_INT);
            try { $sel->execute(); $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (\Throwable $e) {}
        }

        return $rows;
    }

    /** Small PDO helper for select paths with :type/:lim binding */
    private static function trySelect(PDO $pdo, string $sql, ?string $type, int $limit): array
    {
        try {
            $st = $pdo->prepare($sql);
            if ($type) $st->bindValue(':type', $type, PDO::PARAM_STR);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Mark job as completed/done */
    public static function complete(int $id): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id): void {
            self::detectSchema($pdo);

            if (!self::$schema['legacy']) {
                $sql = "UPDATE ls_jobs
                        SET status = '" . self::$schema['status_done'] . "'" .
                        (self::$schema['has_finished_at'] ? ", finished_at = NOW()" : "") .
                        (self::$schema['has_updated']     ? ", updated_at = NOW()" : "") .
                        " WHERE id = :id";
                $pdo->prepare($sql)->execute([':id' => $id]);
            } else {
                // find legacy job_id
                $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id = :i LIMIT 1');
                $sel->execute([':i' => $id]);
                $jobId = (string)($sel->fetchColumn() ?: '');
                if ($jobId !== '') {
                    $sql = "UPDATE ls_jobs
                            SET status = '" . self::$schema['status_done'] . "'" .
                            (self::$schema['has_completed_at'] ? ", completed_at = NOW()" : "") .
                            (self::$schema['has_updated']      ? ", updated_at = NOW()" : "") .
                            " WHERE job_id = :j";
                    $pdo->prepare($sql)->execute([':j' => $jobId]);
                }
            }

            // Log
            $cid = null;
            try {
                if (!self::$schema['legacy']) {
                    $s = $pdo->prepare('SELECT payload FROM ls_jobs WHERE id = :i');
                    $s->execute([':i' => $id]);
                    $p = json_decode((string)($s->fetchColumn() ?: ''), true) ?: [];
                    if (isset($p['trace_id']) && is_string($p['trace_id'])) $cid = (string)$p['trace_id'];
                }
            } catch (\Throwable $e) {}
            self::log($pdo, $id, 'info', 'job.completed', $cid ?? Http::requestId());
        });
    }

    /**
     * Mark job as failed or reschedule with backoff.
     * - Exponential backoff with jitter
     * - Writes last_error when column exists
     * - On final failure mirrors to ls_jobs_dlq in both schemas
     */
    public static function fail(int $id, string $error): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id, $error): void {
            self::detectSchema($pdo);

            // Current attempts
            $attempts = 0;
            if (!self::$schema['legacy']) {
                $row = $pdo->query('SELECT attempts FROM ls_jobs WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
                $attempts = $row ? ((int)$row['attempts'] + 1) : 1;
            } else {
                $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id = :i');
                $sel->execute([':i' => $id]);
                $jobId = (string)($sel->fetchColumn() ?: '');
                if ($jobId !== '') {
                    $row = $pdo->prepare('SELECT attempts FROM ls_jobs WHERE job_id = :j');
                    $row->execute([':j' => $jobId]);
                    $r = $row->fetch(PDO::FETCH_ASSOC);
                    $attempts = $r ? ((int)$r['attempts'] + 1) : 1;
                } else {
                    $attempts = 1;
                }
            }

            $max = (int)(Config::get('vend.retry_attempts', 3) ?? 3);
            if ($attempts >= $max) {
                // FINAL FAILURE -> DLQ mirror + mark failed
                try {
                    if (!self::$schema['legacy']) {
                        try {
                            $pdo->prepare(
                                "INSERT INTO ls_jobs_dlq (id, created_at, type, payload, idempotency_key, fail_code, fail_message, attempts)
                                 SELECT id, created_at, type, payload, idempotency_key, 'error', :err, attempts
                                 FROM ls_jobs WHERE id = :id"
                            )->execute([':id' => $id, ':err' => $error]);
                        } catch (\Throwable $eIns) {}
                        $sql = "UPDATE ls_jobs
                                SET attempts = :a, status = '" . self::$schema['status_failed'] . "'" .
                               (self::$schema['has_last_error'] ? ", last_error = :e" : "") .
                               (self::$schema['has_updated']    ? ", updated_at = NOW()" : "") .
                               " WHERE id = :id";
                        $params = [':a' => $attempts, ':id' => $id];
                        if (self::$schema['has_last_error']) $params[':e'] = $error;
                        $pdo->prepare($sql)->execute($params);
                    } else {
                        $sel = $pdo->prepare('SELECT j.job_id, j.created_at, j.type, j.payload, j.idempotency_key, j.attempts
                                              FROM ls_jobs_map m JOIN ls_jobs j ON j.job_id = m.job_id WHERE m.id = :i');
                        $sel->execute([':i' => $id]);
                        $r = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($r) {
                            $pdo->prepare(
                                "INSERT INTO ls_jobs_dlq (id, created_at, type, payload, idempotency_key, fail_code, fail_message, attempts)
                                 VALUES (:id, :c, :t, :p, :k, 'legacy', :e, :a)
                                 ON DUPLICATE KEY UPDATE fail_message = VALUES(fail_message), attempts = VALUES(attempts)"
                            )->execute([
                                ':id' => $id,
                                ':c'  => $r['created_at'] ?? date('Y-m-d H:i:s'),
                                ':t'  => $r['type'] ?? 'unknown',
                                ':p'  => $r['payload'] ?? '{}',
                                ':k'  => $r['idempotency_key'] ?? null,
                                ':e'  => $error,
                                ':a'  => $attempts,
                            ]);

                            $sql = "UPDATE ls_jobs
                                    SET attempts = :a, status = '" . self::$schema['status_failed'] . "'" .
                                   (self::$schema['has_updated'] ? ", updated_at = NOW()" : "") .
                                   " WHERE job_id = :j";
                            $pdo->prepare($sql)->execute([':a' => $attempts, ':j' => (string)$r['job_id']]);
                        }
                    }
                } catch (\Throwable $e) {}
                self::log($pdo, $id, 'error', 'job.failed.final:' . $error, Http::requestId());
                return;
            }

            // RESCHEDULE with backoff
            $backoffMin = (int)max(1, pow(2, $attempts));  // 2,4,8..
            $jitterSec  = random_int(0, 30);
            $params     = [':a' => $attempts, ':id' => $id];

            if (!self::$schema['legacy']) {
                $sql = "UPDATE ls_jobs
                        SET attempts = :a,
                            status   = 'pending' " .
                           (self::$schema['has_last_error'] ? ", last_error = :e" : "") .
                           (self::$schema['has_next_run_at'] ? ", next_run_at = DATE_ADD(NOW(), INTERVAL :mins MINUTE) + INTERVAL :jit SECOND" : "") .
                           (self::$schema['has_updated']     ? ", updated_at = NOW()" : "") .
                        " WHERE id = :id";
                if (self::$schema['has_last_error'])  $params[':e']   = $error;
                if (self::$schema['has_next_run_at']) { $params[':mins'] = $backoffMin; $params[':jit'] = $jitterSec; }
                $pdo->prepare($sql)->execute($params);
            } else {
                // legacy: no next_run_at; just flip to pending and rely on external pacing
                $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id = :i');
                $sel->execute([':i' => $id]);
                $jobId = (string)($sel->fetchColumn() ?: '');
                if ($jobId !== '') {
                    $sql = "UPDATE ls_jobs
                            SET attempts = :a, status = 'pending' " .
                           (self::$schema['has_updated'] ? ", updated_at = NOW()" : "") .
                           " WHERE job_id = :j";
                    $pdo->prepare($sql)->execute([':a' => $attempts, ':j' => $jobId]);
                }
            }

            self::log($pdo, $id, 'warning', 'job.retry:' . $error, Http::requestId());
        });
    }

    /**
     * Write a log row resiliently across schema variants.
     * Normalizes level to one of: debug|info|warning|error.
     */
    private static function log(PDO $pdo, int $jobId, string $level, string $message, ?string $correlationId = null): void
    {
        $lvl = strtolower($level);
        if ($lvl === 'warn') $lvl = 'warning';
        if (!in_array($lvl, ['debug','info','warning','error'], true)) $lvl = 'info';

        // Modern
        try {
            $pdo->prepare('INSERT INTO ls_job_logs (job_id, level, message, correlation_id) VALUES (:j,:l,:m,:c)')
                ->execute([
                    ':j' => $jobId,
                    ':l' => $lvl,
                    ':m' => $message,
                    ':c' => $correlationId,
                ]);
            return;
        } catch (\PDOException $e) {
            $errno = (int)($e->errorInfo[1] ?? 0);

            // Fallback: no correlation_id
            if ($errno === 1054) {
                try {
                    $pdo->prepare('INSERT INTO ls_job_logs (job_id, level, message) VALUES (:j,:l,:m)')
                        ->execute([':j'=>$jobId, ':l'=>$lvl, ':m'=>$message]);
                    return;
                } catch (\PDOException $e2) {
                    // Fallback further -> legacy
                }
            }
        } catch (\Throwable $e) {}

        // Legacy: map numeric id -> uuid and insert minimal columns
        try {
            $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id = :i');
            $sel->execute([':i' => $jobId]);
            $uuid = (string)($sel->fetchColumn() ?: '');
            $logId = self::uuid();

            // Try legacy with explicit log_id column first
            try {
                $pdo->prepare('INSERT INTO ls_job_logs (log_id, job_id, level, message) VALUES (:lid, :jid, :lvl, :msg)')
                    ->execute([':lid'=>$logId, ':jid'=>$uuid !== '' ? $uuid : (string)$jobId, ':lvl'=>$lvl, ':msg'=>$message]);
                return;
            } catch (\PDOException $e3) {
                // last fallback: (job_id, level, message)
                $pdo->prepare('INSERT INTO ls_job_logs (job_id, level, message) VALUES (:jid, :lvl, :msg)')
                    ->execute([':jid'=>$uuid !== '' ? $uuid : (string)$jobId, ':lvl'=>$lvl, ':msg'=>$message]);
            }
        } catch (\Throwable $e) {
            // swallow; logging is best-effort
        }
    }
}
