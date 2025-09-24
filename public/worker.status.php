<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http; use Queue\Config; use Queue\FeatureFlags;

Http::commonJsonHeaders();

$appRoot = realpath(__DIR__ . '/..');
$logsDir = $appRoot . '/logs';
$lockPath = $logsDir . '/worker.lock';
$logPath  = $logsDir . '/worker.log';

$now = time();
$lockExists = is_file($lockPath);
$lockMtime = $lockExists ? @filemtime($lockPath) : null;
$logExists  = is_file($logPath);
$logMtime  = $logExists ? @filemtime($logPath) : null;
$logSize   = $logExists ? @filesize($logPath) : null;

// DB activity snapshot
$db = \Queue\PdoConnection::instance();
$pending = 0; $working = 0; $done1m = 0; $lastDoneAt = null; $lastStartedAt = null;
try { $pending = (int)$db->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn(); } catch (\Throwable $e) {}
try { $working = (int)$db->query("SELECT COUNT(*) FROM ls_jobs WHERE status='working' OR status='running'")->fetchColumn(); } catch (\Throwable $e) {}
try { $done1m = (int)$db->query("SELECT COUNT(*) FROM ls_jobs WHERE (status='done' OR status='completed') AND (finished_at >= NOW() - INTERVAL 1 MINUTE OR completed_at >= NOW() - INTERVAL 1 MINUTE)")->fetchColumn(); } catch (\Throwable $e) {}
try {
  $lastDoneAt = (string)($db->query("SELECT DATE_FORMAT(GREATEST(IFNULL(finished_at,'0000-00-00 00:00:00'), IFNULL(completed_at,'0000-00-00 00:00:00')), '%Y-%m-%d %H:%i:%s') FROM ls_jobs WHERE status IN ('done','completed') ORDER BY GREATEST(IFNULL(finished_at,'0000-00-00 00:00:00'), IFNULL(completed_at,'0000-00-00 00:00:00')) DESC LIMIT 1")->fetchColumn() ?: '');
} catch (\Throwable $e) { $lastDoneAt = null; }
try {
  $lastStartedAt = (string)($db->query("SELECT DATE_FORMAT(IFNULL(started_at,'0000-00-00 00:00:00'), '%Y-%m-%d %H:%i:%s') FROM ls_jobs WHERE (status='working' OR status='running') ORDER BY started_at DESC LIMIT 1")->fetchColumn() ?: '');
} catch (\Throwable $e) { $lastStartedAt = null; }

$enabled = FeatureFlags::runnerEnabled();
$cont = Config::getBool('vend.queue.continuous.enabled', false);

echo json_encode([
  'ok' => true,
  'flags' => [
    'queue.runner.enabled' => $enabled,
    'vend.queue.continuous.enabled' => $cont,
  ],
  'worker' => [
    'lock_exists' => $lockExists,
    'lock_mtime' => $lockMtime,
    'log_exists' => $logExists,
    'log_mtime' => $logMtime,
    'log_size' => $logSize,
    'lock_age_sec' => ($lockMtime ? ($now - (int)$lockMtime) : null),
    'log_age_sec' => ($logMtime ? ($now - (int)$logMtime) : null),
  ],
  'db' => [
    'pending' => $pending,
    'working' => $working,
    'done_last_minute' => $done1m,
    'last_completed_at' => $lastDoneAt ?: null,
    'last_started_at' => $lastStartedAt ?: null,
  ],
]);
