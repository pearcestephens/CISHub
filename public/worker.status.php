<?php
declare(strict_types=1);

/**
 * assets/services/queue/public/worker.status.php
 *
 * Lightweight JSON status for the runner + quick DB stats.
 * - Keeps existing shape: ok, flags{}, worker{}, db{}
 * - More defensive: all queries wrapped, null-safe fields
 * - Stable headers, no warnings, no notices
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;
use Queue\FeatureFlags;
use Queue\PdoConnection;

Http::commonJsonHeaders();

$appRoot = realpath(__DIR__ . '/..');
$logsDir = $appRoot . '/logs';

$lockPath = $logsDir . '/worker.lock';
$logPath  = $logsDir . '/worker.log';

$now       = time();
$lockExists= is_file($lockPath);
$logExists = is_file($logPath);

$lockMtime = $lockExists ? @filemtime($lockPath) : null;
$logMtime  = $logExists  ? @filemtime($logPath)  : null;
$logSize   = $logExists  ? @filesize($logPath)   : null;

$lockAge   = $lockMtime ? ($now - (int)$lockMtime) : null;
$logAge    = $logMtime  ? ($now - (int)$logMtime)  : null;

$pending = 0;
$working = 0;
$done1m = 0;
$lastDoneAt = null;
$lastStartedAt = null;
$staleWorking = null;
$stalePendingOldest = null;

try {
    $db = PdoConnection::instance();
    try { $pending = (int)($db->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0); } catch (\Throwable $e) {}
    try { $working = (int)($db->query("SELECT COUNT(*) FROM ls_jobs WHERE status IN('working','running')")->fetchColumn() ?: 0); } catch (\Throwable $e) {}
    try {
        // Detect columns
        $hasCol = static function(string $name) use ($db): bool { try { $s=$db->prepare("SHOW COLUMNS FROM ls_jobs LIKE :c"); $s->execute([':c'=>$name]); return (bool)$s->fetchColumn(); } catch (\Throwable $e) { return false; } };
        $hasFin = $hasCol('finished_at'); $hasComp = $hasCol('completed_at'); $hasUpd = $hasCol('updated_at');
        if ($hasFin || $hasComp) {
            $parts = [];
            if ($hasFin) { $parts[] = "finished_at >= NOW() - INTERVAL 1 MINUTE"; }
            if ($hasComp) { $parts[] = "completed_at >= NOW() - INTERVAL 1 MINUTE"; }
            $cond = implode(' OR ', $parts);
            $done1m = (int)($db->query("SELECT COUNT(*) FROM ls_jobs WHERE status IN('done','completed') AND (".$cond.")")->fetchColumn() ?: 0);
        } elseif ($hasUpd) {
            $done1m = (int)($db->query("SELECT COUNT(*) FROM ls_jobs WHERE status IN('done','completed') AND updated_at >= NOW() - INTERVAL 1 MINUTE")->fetchColumn() ?: 0);
        }
    } catch (\Throwable $e) {}
    try {
        $lastDoneAt = (string)($db->query(
            "SELECT DATE_FORMAT(GREATEST(
                IFNULL(finished_at,'0000-00-00 00:00:00'),
                IFNULL(completed_at,'0000-00-00 00:00:00')),
                '%Y-%m-%d %H:%i:%s')
             FROM ls_jobs
             WHERE status IN('done','completed')
             ORDER BY GREATEST(IFNULL(finished_at,'0000-00-00 00:00:00'),
                               IFNULL(completed_at,'0000-00-00 00:00:00')) DESC
             LIMIT 1"
        )->fetchColumn() ?: '') ?: null;
    } catch (\Throwable $e) {}
    try {
        $lastStartedAt = (string)($db->query(
            "SELECT DATE_FORMAT(IFNULL(started_at,'0000-00-00 00:00:00'),
                               '%Y-%m-%d %H:%i:%s')
             FROM ls_jobs
             WHERE status IN('working','running')
             ORDER BY started_at DESC
             LIMIT 1"
        )->fetchColumn() ?: '') ?: null;
    } catch (\Throwable $e) {}
    try {
        $staleWorking = (int)$db->query(
            "SELECT COUNT(*) FROM ls_jobs
             WHERE (status IN('working','running'))
               AND (
                 (started_at IS NOT NULL AND started_at < NOW() - INTERVAL 15 MINUTE)
                 OR (IFNULL(updated_at,'0000-00-00 00:00:00') < NOW() - INTERVAL 15 MINUTE)
                 OR (IFNULL(heartbeat_at,'0000-00-00 00:00:00') < NOW() - INTERVAL 15 MINUTE)
               )"
        )->fetchColumn();
    } catch (\Throwable $e) { $staleWorking = null; }
    try {
        $stalePendingOldest = (int)$db->query(
            "SELECT IFNULL(TIMESTAMPDIFF(SECOND, MIN(created_at), NOW()), 0)
             FROM ls_jobs WHERE status='pending'"
        )->fetchColumn();
    } catch (\Throwable $e) { $stalePendingOldest = null; }
} catch (\Throwable $e) {
    // DB down — still return worker info and flags
}

$enabled = FeatureFlags::runnerEnabled();
$cont    = Config::getBool('vend.queue.continuous.enabled', false);

echo json_encode([
    'ok'    => true,
    'flags' => [
        'queue.runner.enabled'      => $enabled,
        'vend.queue.continuous.enabled' => $cont,
    ],
    'worker'=> [
        'lock_exists'  => $lockExists,
        'lock_mtime'   => $lockMtime,
        'log_exists'   => $logExists,
        'log_mtime'    => $logMtime,
        'log_size'     => $logSize,
        'lock_age_sec' => $lockAge,
        'log_age_sec'  => $logAge,
    ],
    'db'    => [
        'pending'                 => $pending,
        'working'                 => $working,
        'done_last_minute'        => $done1m,
        'last_completed_at'       => $lastDoneAt ?: null,
        'last_started_at'         => $lastStartedAt ?: null,
        'stale_working_older_15m' => $staleWorking,
        'oldest_pending_age_sec'  => $stalePendingOldest,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
