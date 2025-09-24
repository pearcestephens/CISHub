<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/FeatureFlags.php';

use Queue\Http;
use Queue\PdoConnection;
use Queue\FeatureFlags;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;
if (!Http::rateLimit('reap')) return;
if (FeatureFlags::killAll() || !FeatureFlags::runnerEnabled()) {
    Http::error('reap_disabled','Reap disabled');
    return;
}

$in    = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$older = isset($in['older_than_sec']) ? max(60, (int)$in['older_than_sec']) : 900;

try {
    $pdo = PdoConnection::instance();

    // Column existence checks
    $has = static function(string $col) use ($pdo): bool {
        try {
            $c = $pdo->prepare("SHOW COLUMNS FROM ls_jobs LIKE :c");
            $c->execute([':c'=>$col]);
            return (bool)$c->fetchColumn();
        } catch (\Throwable $e) { return false; }
    };

    $hasLeased    = $has('leased_until');
    $hasStarted   = $has('started_at');
    $hasHeartbeat = $has('heartbeat_at');
    $hasUpdated   = $has('updated_at');

    $details = ['leased_expired'=>0,'started_old'=>0,'heartbeat_old'=>0,'updated_old'=>0];
    $total   = 0;

    // 1) Expired leases (only if leased_until exists)
    if ($hasLeased) {
        $sql = "UPDATE ls_jobs
                SET status='pending',
                    leased_until=NULL,
                    ".($hasHeartbeat ? "heartbeat_at=NULL," : "")."
                    ".($hasUpdated ? "updated_at=NOW()," : "")."
                    status=status
                WHERE status IN('working','running')
                  AND (leased_until IS NULL OR leased_until < NOW())";
        try {
            $n = $pdo->exec($sql);
            $details['leased_expired'] = (int)$n;
            $total += (int)$n;
        } catch (\Throwable $e) {}
    }

    // 2) Old started_at (if present)
    if ($hasStarted) {
        $sql = "UPDATE ls_jobs
                SET status='pending',
                    ".($hasLeased ? "leased_until=NULL," : "")."
                    ".($hasHeartbeat ? "heartbeat_at=NULL," : "")."
                    ".($hasUpdated ? "updated_at=NOW()," : "")."
                    status=status
                WHERE status IN('working','running')
                  AND started_at IS NOT NULL
                  AND TIMESTAMPDIFF(SECOND, started_at, NOW()) > :s";
        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':s', $older, PDO::PARAM_INT);
            $st->execute();
            $details['started_old'] = (int)$st->rowCount();
            $total += (int)$st->rowCount();
        } catch (\Throwable $e) {}
    }

    // 3) Old heartbeat_at (if present)
    if ($hasHeartbeat) {
        $sql = "UPDATE ls_jobs
                SET status='pending',
                    ".($hasLeased ? "leased_until=NULL," : "")."
                    heartbeat_at=NULL,
                    ".($hasUpdated ? "updated_at=NOW()," : "")."
                    status=status
                WHERE status IN('working','running')
                  AND heartbeat_at IS NOT NULL
                  AND TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) > :s";
        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':s', $older, PDO::PARAM_INT);
            $st->execute();
            $details['heartbeat_old'] = (int)$st->rowCount();
            $total += (int)$st->rowCount();
        } catch (\Throwable $e) {}
    }

    // 4) Fallback: very old updated_at (if present) — last resort
    if ($hasUpdated) {
        $sql = "UPDATE ls_jobs
                SET status='pending',
                    ".($hasLeased ? "leased_until=NULL," : "")."
                    ".($hasHeartbeat ? "heartbeat_at=NULL," : "")."
                    updated_at = NOW()
                WHERE status IN('working','running')
                  AND TIMESTAMPDIFF(SECOND, updated_at, NOW()) > :s";
        try {
            $st = $pdo->prepare($sql);
            $st->bindValue(':s', $older, PDO::PARAM_INT);
            $st->execute();
            $details['updated_old'] = (int)$st->rowCount();
            $total += (int)$st->rowCount();
        } catch (\Throwable $e) {}
    }

    Http::respond(true, ['reaped'=>$total,'details'=>$details,'older_than_sec'=>$older]);
} catch (\Throwable $e) {
    Http::error('server_error','Failed to reap',['message'=>$e->getMessage()],500);
}
