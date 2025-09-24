<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core;

use PDO;
use RuntimeException;

/**
 * WorkItems: add/claim/complete/fail with idempotency and logging.
 * @link https://staff.vapeshed.co.nz
 */
final class WorkItems
{
    /**
     * @param string $type
     * @param array<string,mixed> $payload
     * @param string|null $idempotencyKey
     * @return int job id (existing if duplicate idempotency key)
     */
    public static function add(string $type, array $payload, ?string $idempotencyKey = null): int
    {
        return DB::transaction(static function (PDO $pdo) use ($type, $payload, $idempotencyKey): int {
            if ($idempotencyKey !== null) {
                $stmt = $pdo->prepare('SELECT id FROM ls_jobs WHERE idempotency_key = :k LIMIT 1');
                $stmt->execute([':k' => $idempotencyKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return (int) $row['id'];
                }
            }
            $stmt = $pdo->prepare('INSERT INTO ls_jobs (type, payload, idempotency_key) VALUES (:t, :p, :k)');
            $stmt->execute([
                ':t' => $type,
                ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ':k' => $idempotencyKey,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::log($pdo, $id, 'info', 'job.created');
            return $id;
        });
    }

    /**
     * Claim a batch of jobs using SKIP LOCKED, mark as working.
     * @param int $limit
     * @param string|null $type
     * @return array<int,array<string,mixed>>
     */
    public static function claim(int $limit = 50, ?string $type = null): array
    {
        return DB::transaction(static function (PDO $pdo) use ($limit, $type): array {
            $sql = "SELECT id, type, payload, attempts FROM ls_jobs WHERE status='pending'";
            $params = [];
            if ($type !== null) {
                $sql .= ' AND type = :type';
                $params[':type'] = $type;
            }
            // FOR UPDATE SKIP LOCKED must come before LIMIT
            $sql .= ' ORDER BY updated_at ASC FOR UPDATE SKIP LOCKED LIMIT :lim';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            if ($ids === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE ls_jobs SET status='working', started_at=NOW() WHERE id IN ($in)")
                ->execute($ids);
            foreach ($ids as $id) {
                self::log($pdo, $id, 'info', 'job.claimed');
            }
            foreach ($rows as &$r) {
                $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
            }
            return $rows;
        });
    }

    public static function complete(int $id): void
    {
        DB::transaction(static function (PDO $pdo) use ($id): void {
            $pdo->prepare("UPDATE ls_jobs SET status='done', finished_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);
            self::log($pdo, $id, 'info', 'job.completed');
        });
    }

    public static function fail(int $id, string $error, int $maxAttempts = 5): void
    {
        DB::transaction(static function (PDO $pdo) use ($id, $error, $maxAttempts): void {
            $row = $pdo->query('SELECT attempts FROM ls_jobs WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Job not found: ' . $id);
            }
            $attempts = (int) $row['attempts'] + 1;
            $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
            $pdo->prepare('UPDATE ls_jobs SET attempts=:a, status=:s, last_error=:e, updated_at=NOW() WHERE id=:id')
                ->execute([':a' => $attempts, ':s' => $status, ':e' => $error, ':id' => $id]);
            self::log($pdo, $id, $status === 'failed' ? 'error' : 'warn', 'job.failed: ' . $error);
        });
    }

    private static function log(PDO $pdo, int $jobId, string $level, string $message): void
    {
        $pdo->prepare('INSERT INTO ls_job_logs (job_id, level, message) VALUES (:j,:l,:m)')
            ->execute([':j' => $jobId, ':l' => $level, ':m' => $message]);
    }
}
