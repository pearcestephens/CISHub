<?php
declare(strict_types=1);

namespace Queue;

use PDO;

final class PdoWorkItemRepository
{
    /** @var array{legacy:bool, pk:string, status_working:string, status_done:string, status_failed:string, has_next_run_at:bool, has_lease:bool, has_heartbeat:bool, has_priority:bool, has_updated:bool} */
    private static array $schema;

    private static function detectSchema(\PDO $pdo): void
    {
        if (isset(self::$schema)) return;
        $cols = $pdo->query('SHOW COLUMNS FROM ls_jobs')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = array_map(static fn($r) => (string)$r['Field'], $cols);
        $legacy = in_array('job_id', $names, true) && !in_array('id', $names, true);
    $hasNext = in_array('next_run_at', $names, true);
        $hasLease = in_array('leased_until', $names, true);
        $hasHb = in_array('heartbeat_at', $names, true);
    $hasPriority = in_array('priority', $names, true);
    $hasUpdated = in_array('updated_at', $names, true);
        // Determine status values
        $statusWorking = $legacy ? 'running' : 'working';
        $statusDone = $legacy ? 'completed' : 'done';
        $statusFailed = 'failed';
        self::$schema = [
            'legacy' => $legacy,
            'pk' => $legacy ? 'job_id' : 'id',
            'status_working' => $statusWorking,
            'status_done' => $statusDone,
            'status_failed' => $statusFailed,
            'has_next_run_at' => $hasNext,
            'has_lease' => $hasLease,
            'has_heartbeat' => $hasHb,
            'has_priority' => $hasPriority,
            'has_updated' => $hasUpdated,
        ];
        if ($legacy) {
            // Ensure mapping table exists: numeric id <-> job_id, match collation to ls_jobs.job_id
            $pdo->exec("CREATE TABLE IF NOT EXISTS ls_jobs_map ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, job_id CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL, PRIMARY KEY(id), UNIQUE KEY uniq_job (job_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    private static function uuid(): string
    {
        $d = random_bytes(16);
        // Set version and variant bits for v4
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
        $hex = bin2hex($d);
        return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8), substr($hex,8,4), substr($hex,12,4), substr($hex,16,4), substr($hex,20));
    }
    public static function addJob(string $type, array $payload, ?string $idempotencyKey = null): int
    {
        $priority = isset($payload['priority']) ? (int)$payload['priority'] : 5;
        if ($priority < 1) { $priority = 1; }
        if ($priority > 9) { $priority = 9; }
        return PdoConnection::transaction(static function (PDO $pdo) use ($type, $payload, $idempotencyKey, $priority): int {
            self::detectSchema($pdo);
            $lockKey = null; $gotLock = false;
            try {
                if ($idempotencyKey) {
                    $lockKey = 'ls_jobs:' . hash('sha256', $idempotencyKey);
                    $stmt = $pdo->prepare('SELECT GET_LOCK(:lk, 5) AS got');
                    $stmt->execute([':lk' => $lockKey]);
                    $got = $stmt->fetch(PDO::FETCH_ASSOC);
                    $gotLock = isset($got['got']) && (int)$got['got'] === 1;
                }
                if ($idempotencyKey) {
                    if (!self::$schema['legacy']) {
                        $s = $pdo->prepare('SELECT id FROM ls_jobs WHERE idempotency_key=:k LIMIT 1');
                        $s->execute([':k' => $idempotencyKey]);
                        $r = $s->fetch(PDO::FETCH_ASSOC);
                        if ($r) return (int)$r['id'];
                    } else {
                        // Two-step: find existing legacy job, ensure mapping, return numeric id
                        $s = $pdo->prepare('SELECT job_id FROM ls_jobs WHERE idempotency_key=:k LIMIT 1');
                        $s->execute([':k' => $idempotencyKey]);
                        $jid = (string)($s->fetchColumn() ?: '');
                        if ($jid !== '') {
                            $pdo->prepare('INSERT IGNORE INTO ls_jobs_map (job_id) VALUES (:j)')->execute([':j' => $jid]);
                            $sel = $pdo->prepare('SELECT id FROM ls_jobs_map WHERE job_id=:j LIMIT 1');
                            $sel->execute([':j' => $jid]);
                            $mid = (int)($sel->fetchColumn() ?: 0);
                            if ($mid > 0) return $mid;
                        }
                    }
                }
                $trace = is_array($payload) && isset($payload['trace_id']) && is_string($payload['trace_id']) ? (string)$payload['trace_id'] : null;
                if (!self::$schema['legacy']) {
                    $pdo->prepare('INSERT INTO ls_jobs (type,priority,payload,idempotency_key) VALUES (:t,:pr,:p,:k)')
                        ->execute([':t' => $type, ':pr' => $priority, ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ':k' => $idempotencyKey]);
                    $id = (int)$pdo->lastInsertId();
                    self::log($pdo, $id, 'info', 'job.created', $trace ?? \Queue\Http::requestId());
                    return $id;
                } else {
                    $jobId = self::uuid();
                    $pdo->prepare('INSERT INTO ls_jobs (job_id,type,payload,idempotency_key,status,priority,attempts,max_attempts,created_at,updated_at) VALUES (:id,:t,:p,:k,\'pending\',:pr,0,6,NOW(),NOW())')
                        ->execute([':id' => $jobId, ':t' => $type, ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ':k' => $idempotencyKey, ':pr' => $priority]);
                    // map numeric id
                    $pdo->prepare('INSERT INTO ls_jobs_map (job_id) VALUES (:j) ON DUPLICATE KEY UPDATE job_id = VALUES(job_id)')->execute([':j' => $jobId]);
                    $id = (int)$pdo->lastInsertId();
                    if ($id === 0) {
                        // fetch existing
                        $id = (int)$pdo->query("SELECT id FROM ls_jobs_map WHERE job_id='" . str_replace("'", "''", $jobId) . "'")->fetchColumn();
                    }
                    self::log($pdo, $id, 'info', 'job.created', $trace ?? \Queue\Http::requestId());
                    return $id;
                }
            } finally {
                if ($idempotencyKey && $gotLock && $lockKey) {
                    try { $pdo->prepare('SELECT RELEASE_LOCK(:lk)')->execute([':lk' => $lockKey]); } catch (\Throwable $e) { /* ignore */ }
                }
            }
        });
    }

    public static function heartbeat(int $id): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id): void {
            self::detectSchema($pdo);
            if (!self::$schema['legacy'] && self::$schema['has_heartbeat'] && self::$schema['has_lease']) {
                $pdo->prepare("UPDATE ls_jobs SET heartbeat_at=NOW(), leased_until=DATE_ADD(NOW(), INTERVAL 2 MINUTE) WHERE id=:id AND status='" . self::$schema['status_working'] . "'")
                    ->execute([':id' => $id]);
            }
        });
    }

    /**
     * @return list<WorkItem>
     */
    public static function claimBatch(int $limit = 50, ?string $type = null): array
    {
        return PdoConnection::transaction(static function (PDO $pdo) use ($limit, $type): array {
            self::detectSchema($pdo);
            if (!self::$schema['legacy']) {
                $base = "SELECT id,type,payload,attempts FROM ls_jobs WHERE status='pending' AND (" . (self::$schema['has_next_run_at'] ? "(next_run_at IS NULL OR next_run_at <= NOW())" : '1=1') . ")";
            } else {
                // Legacy schema: perform an atomic claim using UPDATE..JOIN on a deterministic subselect
                // to avoid locking feature differences. We'll first compute the candidate keys subquery.
                $base = "SELECT job_id, type, payload, attempts FROM ls_jobs WHERE status='pending'";
            }
            $params = [];
            if ($type) { $base .= ' AND type=:type'; $params[':type'] = $type; }
            $order = ' ORDER BY ' . (self::$schema['has_priority'] ? 'priority' : 'created_at') . ' ASC' . (self::$schema['has_updated'] ? ', updated_at ASC' : '');
            // Preferred (MySQL 8+, MariaDB 10.6+): FOR UPDATE SKIP LOCKED then LIMIT
            $sql1 = $base . $order . ' FOR UPDATE SKIP LOCKED LIMIT :lim';
            // Fallback (no SKIP LOCKED): FOR UPDATE then LIMIT — may block under contention
            $sql2 = $base . $order . ' FOR UPDATE LIMIT :lim';
            // Final fallback: no locking (risk of double-claim under high contention, acceptable as last resort)
            $sql3 = $base . $order . ' LIMIT :lim';

            $rows = [];
            foreach ([$sql1, $sql2, $sql3] as $sql) {
                try {
                    $stmt = $pdo->prepare($sql);
                } catch (\Throwable $e) { $stmt = null; }
                if ($stmt === null) { continue; }
                try {
                    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
                    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    // Only accept this variant if it actually returned rows; otherwise
                    // continue to try the next (less strict) variant to maximize compatibility.
                    if ($rows) { break; }
                } catch (\PDOException $e) {
                    // try next variant on syntax/feature error
                    $rows = [];
                    continue;
                }
            }
            if (!$rows && self::$schema['legacy']) {
                // As a fallback for legacy, try an atomic claim via UPDATE..JOIN LIMIT approach
                $whereType = '';
                $params2 = [];
                if ($type) { $whereType = ' AND type = :t'; $params2[':t'] = $type; }
                $orderBy = ' ORDER BY ' . (self::$schema['has_priority'] ? 'priority' : 'created_at') . (self::$schema['has_updated'] ? ', updated_at' : '');
                $claimSql = "UPDATE ls_jobs j JOIN (SELECT job_id FROM ls_jobs WHERE status='pending" . "'" . $whereType . $orderBy . " LIMIT :lim) AS sel ON j.job_id = sel.job_id SET j.status='" . self::$schema['status_working'] . "', j.started_at = NOW()";
                try {
                    $st = $pdo->prepare($claimSql);
                } catch (\Throwable $e) { $st = null; }
                if ($st) {
                    try {
                        foreach ($params2 as $k=>$v) { $st->bindValue($k, $v); }
                        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
                        $st->execute();
                        // Fetch the claimed rows
                        $selSql = "SELECT job_id, type, payload, attempts FROM ls_jobs WHERE status='" . self::$schema['status_working'] . "'" . ($type ? ' AND type = :t' : '') . " ORDER BY started_at DESC LIMIT :lim";
                        $st2 = $pdo->prepare($selSql);
                        if ($type) { $st2->bindValue(':t', $type); }
                        $st2->bindValue(':lim', $limit, PDO::PARAM_INT);
                        $st2->execute();
                        $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    } catch (\Throwable $e) { $rows = []; }
                }
            }
            if (!$rows) return [];
            if (self::$schema['legacy']) {
                // Map job_id -> numeric id via ls_jobs_map, create missing rows on the fly
                $jobIds = array_values(array_unique(array_map(static fn($r) => (string)$r['job_id'], $rows)));
                foreach ($jobIds as $jid) { $pdo->prepare('INSERT IGNORE INTO ls_jobs_map (job_id) VALUES (:j)')->execute([':j' => $jid]); }
                $place = implode(',', array_fill(0, count($jobIds), '?'));
                $map = $pdo->prepare("SELECT job_id, id FROM ls_jobs_map WHERE job_id IN ($place)");
                $map->execute($jobIds);
                $mRows = $map->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $toId = [];
                foreach ($mRows as $mr) { $toId[(string)$mr['job_id']] = (int)$mr['id']; }
                // Build normalized rows with numeric id
                $norm = [];
                foreach ($rows as $r) {
                    $jid = (string)$r['job_id'];
                    $nid = $toId[$jid] ?? 0;
                    if ($nid > 0) {
                        $norm[] = ['id' => $nid, 'type' => $r['type'], 'payload' => $r['payload'], 'attempts' => $r['attempts'], 'job_id' => $jid];
                    }
                }
                $rows = $norm;
            }
            $ids = array_map(static fn($r) => (int)$r['id'], $rows);
            $place = implode(',', array_fill(0, count($ids), '?'));
            if (!self::$schema['legacy']) {
                $sql = "UPDATE ls_jobs SET status='" . self::$schema['status_working'] . "', started_at=NOW()" . (self::$schema['has_lease'] ? ", leased_until=DATE_ADD(NOW(), INTERVAL 2 MINUTE)" : '') . " WHERE id IN ($place)";
                $pdo->prepare($sql)->execute($ids);
            } else {
                // Update legacy by job_id list present in $rows
                $jobIds = array_values(array_unique(array_map(static fn($r) => (string)$r['job_id'], $rows)));
                if ($jobIds) {
                    $place2 = implode(',', array_fill(0, count($jobIds), '?'));
                    $upd = $pdo->prepare("UPDATE ls_jobs SET status='" . self::$schema['status_working'] . "', started_at=NOW() WHERE job_id IN ($place2)");
                    $upd->execute($jobIds);
                }
            }
            foreach ($rows as $r) {
                $cid = null;
                $pay = json_decode((string)$r['payload'], true) ?: [];
                if (is_array($pay) && isset($pay['trace_id']) && is_string($pay['trace_id'])) { $cid = (string)$pay['trace_id']; }
                $nid = (int)$r['id'];
                self::log($pdo, $nid, 'info', 'job.claimed', $cid ?? \Queue\Http::requestId());
            }
            $out = [];
            foreach ($rows as $r) {
                $j = new WorkItem();
                $j->id = (int)$r['id'];
                $j->type = (string)$r['type'];
                $j->payload = json_decode((string)$r['payload'], true) ?: [];
                $j->status = self::$schema['status_working'];
                $j->attempts = (int)$r['attempts'];
                $j->started_at = null; $j->finished_at = null;
                $out[] = $j;
            }
            return $out;
        });
    }

    public static function complete(int $id): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id): void {
            self::detectSchema($pdo);
            if (!self::$schema['legacy']) {
                $pdo->prepare("UPDATE ls_jobs SET status='" . self::$schema['status_done'] . "', finished_at=NOW() WHERE id=:id")
                    ->execute([':id' => $id]);
            } else {
                $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id=:i LIMIT 1'); $sel->execute([':i'=>$id]); $jobId = (string)($sel->fetchColumn() ?: '');
                if ($jobId !== '') {
                    $pdo->prepare("UPDATE ls_jobs SET status='" . self::$schema['status_done'] . "', completed_at=NOW() WHERE job_id=:j")
                        ->execute([':j' => $jobId]);
                }
            }
            // Try fetch payload to extract trace_id for correlation
            $cid = null;
            try {
                if (!self::$schema['legacy']) {
                    $s = $pdo->prepare('SELECT payload FROM ls_jobs WHERE id=:i'); $s->execute([':i'=>$id]);
                    $p = json_decode((string)($s->fetchColumn() ?: ''), true) ?: [];
                    if (isset($p['trace_id']) && is_string($p['trace_id'])) { $cid = (string)$p['trace_id']; }
                }
            } catch (\Throwable $e) { /* ignore */ }
            self::log($pdo, $id, 'info', 'job.completed', $cid ?? \Queue\Http::requestId());
        });
    }

    public static function fail(int $id, string $error): void
    {
        PdoConnection::transaction(static function (PDO $pdo) use ($id, $error): void {
            self::detectSchema($pdo);
            $attempts = 0;
            if (!self::$schema['legacy']) {
                $row = $pdo->query('SELECT attempts FROM ls_jobs WHERE id=' . (int)$id)->fetch(PDO::FETCH_ASSOC);
                $attempts = $row ? ((int)$row['attempts'] + 1) : 1;
            } else {
                $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id=:i'); $sel->execute([':i' => $id]); $jobId = (string)($sel->fetchColumn() ?: '');
                if ($jobId !== '') {
                    $row = $pdo->prepare('SELECT attempts FROM ls_jobs WHERE job_id=:j'); $row->execute([':j' => $jobId]); $r = $row->fetch(PDO::FETCH_ASSOC);
                    $attempts = $r ? ((int)$r['attempts'] + 1) : 1;
                } else { $attempts = 1; }
            }
            $max = (int) Config::get('vend.retry_attempts', 3);
            if ($attempts >= $max) {
                // Move to DLQ
                try {
                    if (!self::$schema['legacy']) {
                        $pdo->prepare('INSERT INTO ls_jobs_dlq (id, created_at, type, payload, idempotency_key, fail_code, fail_message, attempts) SELECT id, created_at, type, payload, idempotency_key, SUBSTRING_INDEX(last_error,":",1), :err, attempts FROM ls_jobs WHERE id=:id')
                            ->execute([':id' => $id, ':err' => $error]);
                        $pdo->prepare('UPDATE ls_jobs SET attempts=:a,status=\'' . self::$schema['status_failed'] . '\',last_error=:e,updated_at=NOW() WHERE id=:id')
                            ->execute([':a' => $attempts, ':e' => $error, ':id' => $id]);
                    } else {
                        $sel = $pdo->prepare('SELECT j.job_id, j.created_at, j.type, j.payload, j.idempotency_key, j.attempts FROM ls_jobs j JOIN ls_jobs_map m ON m.job_id=j.job_id WHERE m.id=:i');
                        $sel->execute([':i' => $id]); $r = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
                        if ($r) {
                            $pdo->prepare('INSERT INTO ls_jobs_dlq (id, created_at, type, payload, idempotency_key, fail_code, fail_message, attempts) VALUES (:id,:c,:t,:p,:k,\'legacy\',:e,:a) ON DUPLICATE KEY UPDATE fail_message=VALUES(fail_message), attempts=VALUES(attempts)')
                                ->execute([':id' => $id, ':c' => $r['created_at'] ?? date('Y-m-d H:i:s'), ':t' => $r['type'] ?? 'unknown', ':p' => $r['payload'] ?? '{}', ':k' => $r['idempotency_key'] ?? null, ':e' => $error, ':a' => $attempts]);
                            $pdo->prepare('UPDATE ls_jobs SET attempts=:a,status=\'' . self::$schema['status_failed'] . '\',updated_at=NOW() WHERE job_id=:j')
                                ->execute([':a' => $attempts, ':j' => (string)$r['job_id']]);
                        }
                    }
                } catch (\Throwable $e) { /* swallow */ }
                $cid = null;
                try { if (!self::$schema['legacy']) { $s = $pdo->prepare('SELECT payload FROM ls_jobs WHERE id=:i'); $s->execute([':i'=>$id]); $p = json_decode((string)($s->fetchColumn() ?: ''), true) ?: []; if (isset($p['trace_id']) && is_string($p['trace_id'])) { $cid = (string)$p['trace_id']; } } } catch (\Throwable $e) {}
                self::log($pdo, $id, 'error', 'job.failed.final: ' . $error, $cid ?? \Queue\Http::requestId());
            } else {
                $backoffMin = (int) pow(2, $attempts); // 2,4,8...
                $jitterSec = random_int(0, 30);
                if (!self::$schema['legacy']) {
                    $pdo->prepare('UPDATE ls_jobs SET attempts=:a,status=\'pending\',last_error=:e,' . (self::$schema['has_next_run_at'] ? 'next_run_at=DATE_ADD(NOW(), INTERVAL :mins MINUTE) + INTERVAL :jit SECOND,' : '') . ' updated_at=NOW() WHERE id=:id')
                        ->execute([':a' => $attempts, ':e' => $error, ':mins' => $backoffMin, ':jit' => $jitterSec, ':id' => $id]);
                } else {
                    $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id=:i'); $sel->execute([':i'=>$id]); $jobId = (string)($sel->fetchColumn() ?: '');
                    if ($jobId !== '') {
                        $pdo->prepare('UPDATE ls_jobs SET attempts=:a,status=\'pending\',updated_at=NOW() WHERE job_id=:j')
                            ->execute([':a' => $attempts, ':j' => $jobId]);
                    }
                }
                $cid = null;
                try { if (!self::$schema['legacy']) { $s = $pdo->prepare('SELECT payload FROM ls_jobs WHERE id=:i'); $s->execute([':i'=>$id]); $p = json_decode((string)($s->fetchColumn() ?: ''), true) ?: []; if (isset($p['trace_id']) && is_string($p['trace_id'])) { $cid = (string)$p['trace_id']; } } } catch (\Throwable $e) {}
                self::log($pdo, $id, 'warn', 'job.retry: ' . $error, $cid ?? \Queue\Http::requestId());
            }
        });
    }

    private static function log(PDO $pdo, int $jobId, string $level, string $message, ?string $correlationId = null): void
    {
        // Normalize level to schema enum values
        $lvl = strtolower($level);
        if ($lvl === 'warn') { $lvl = 'warning'; }
        if (!in_array($lvl, ['debug','info','warning','error'], true)) { $lvl = 'info'; }

        // Prefer legacy-safe insert if legacy schema is detected (char(36) keys)
        if (isset(self::$schema) && (self::$schema['legacy'] ?? false)) {
            try {
                $jid = (string)$jobId;
                try {
                    $sel = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id=:i LIMIT 1');
                    $sel->execute([':i' => $jobId]);
                    $v = $sel->fetchColumn();
                    if ($v !== false && $v !== null && $v !== '') { $jid = (string)$v; }
                } catch (\Throwable $e) { /* ignore, use numeric as best-effort */ }
                $lid = self::uuid();
                $stmt = $pdo->prepare('INSERT INTO ls_job_logs (log_id, job_id, level, message) VALUES (:lid,:jid,:lvl,:msg)');
                $stmt->execute([':lid' => $lid, ':jid' => $jid, ':lvl' => $lvl, ':msg' => $message]);
                return;
            } catch (\Throwable $e) {
                // fall through to generic paths below
            }
        }

        // Generic (new-schema) path with graceful fallbacks
        try {
            $pdo->prepare('INSERT INTO ls_job_logs (job_id,level,message,correlation_id) VALUES (:j,:l,:m,:c)')
                ->execute([':j' => $jobId, ':l' => $lvl, ':m' => $message, ':c' => $correlationId]);
            return;
        } catch (\PDOException $e) {
            $errno = (int)($e->errorInfo[1] ?? 0);
            if ($errno === 1054) {
                // Unknown column (likely correlation_id). Try without it.
                try {
                    $stmt = $pdo->prepare('INSERT INTO ls_job_logs (job_id,level,message) VALUES (:j,:l,:m)');
                    $stmt->execute([':j' => $jobId, ':l' => $lvl, ':m' => $message]);
                    return;
                } catch (\PDOException $e2) {
                    $errno2 = (int)($e2->errorInfo[1] ?? 0);
                    if ($errno2 === 1364) {
                        // Field 'log_id' doesn't have a default value — try explicit surrogate key (UUID string)
                        $tries = 0;
                        while (true) {
                            $tries++;
                            $lid = self::uuid();
                            try {
                                $stmt3 = $pdo->prepare('INSERT INTO ls_job_logs (log_id, job_id, level, message) VALUES (:lid,:j,:l,:m)');
                                $stmt3->execute([':lid' => $lid, ':j' => $jobId, ':l' => $lvl, ':m' => $message]);
                                return;
                            } catch (\PDOException $eDup) {
                                $dup = (int)($eDup->errorInfo[1] ?? 0);
                                if ($dup === 1062 && $tries < 5) { usleep(20000); continue; }
                                throw $eDup;
                            }
                        }
                    }
                    throw $e2;
                }
            }
            if ($errno === 1364) {
                // Missing default (e.g., log_id). Try explicit log_id UUID with correlation id if present
                $tries = 0;
                while (true) {
                    $tries++;
                    $lid = self::uuid();
                    try {
                        $stmt3 = $pdo->prepare('INSERT INTO ls_job_logs (log_id, job_id, level, message, correlation_id) VALUES (:lid,:j,:l,:m,:c)');
                        $stmt3->execute([':lid' => $lid, ':j' => $jobId, ':l' => $lvl, ':m' => $message, ':c' => $correlationId]);
                        return;
                    } catch (\PDOException $e3) {
                        $dup = (int)($e3->errorInfo[1] ?? 0);
                        if ($dup === 1062 && $tries < 5) { usleep(20000); continue; }
                        // Try without correlation_id as well
                        try {
                            $stmt4 = $pdo->prepare('INSERT INTO ls_job_logs (log_id, job_id, level, message) VALUES (:lid,:j,:l,:m)');
                            $stmt4->execute([':lid' => $lid, ':j' => $jobId, ':l' => $lvl, ':m' => $message]);
                            return;
                        } catch (\PDOException $e4) {
                            $dup2 = (int)($e4->errorInfo[1] ?? 0);
                            if ($dup2 === 1062 && $tries < 5) { usleep(20000); continue; }
                            throw $e4;
                        }
                    }
                }
            }
            throw $e;
        }
    }
}
