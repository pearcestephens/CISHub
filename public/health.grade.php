<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Degrade.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\PdoConnection as DB;
use Queue\Config;
use Queue\Degrade;
use Queue\Http;

Http::commonJsonHeaders();

$now = time();
$pdo = DB::instance();

// ---------- collect metrics ----------
$metrics = [
  'queue' => [
    'pending'      => (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0),
    'working'      => (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE status IN('working','running')")->fetchColumn() ?: 0),
    'done_1m'      => (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE (status IN('done','completed') OR finished_at>=NOW()-INTERVAL 1 MINUTE OR completed_at>=NOW()-INTERVAL 1 MINUTE)")->fetchColumn() ?: 0),
    'oldest_pending_age_s' => (int)($pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND,MIN(created_at),NOW()),0) FROM ls_jobs WHERE status='pending'")->fetchColumn() ?: 0),
    'stuck_working_15m'    => (int)($pdo->query("SELECT COUNT(*) FROM ls_jobs WHERE (status IN('working','running')) AND (IFNULL(started_at,'1970-01-01') < NOW()-INTERVAL 15 MINUTE OR IFNULL(updated_at,'1970-01-01') < NOW()-INTERVAL 15 MINUTE)")->fetchColumn() ?: 0),
  ],
  'webhooks' => [
    'last_event_age_s'    => (int)($pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND,MAX(received_at),NOW()),999999) FROM webhook_events")->fetchColumn() ?: 999999),
    'last_processed_age_s'=> (int)($pdo->query("SELECT IFNULL(TIMESTAMPDIFF(SECOND,MAX(processed_at),NOW()),999999) FROM webhook_events")->fetchColumn() ?: 999999),
  ],
  'vendor' => [
    'cb_open'  => (int)(Config::getBool('vend.cb.tripped', false) ? 1 : 0), // optional
  ],
];

// recent http error rates (last 5m)
try {
  $stmt = $pdo->prepare("
    SELECT
      SUM(status BETWEEN 500 AND 599) AS s5xx,
      SUM(status = 429)              AS s429,
      COUNT(*)                       AS total
    FROM vend_http_log
    WHERE ts >= NOW() - INTERVAL 5 MINUTE
  ");
  $stmt->execute();
  $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['s5xx'=>0,'s429'=>0,'total'=>0];
  $total = max(1, (int)$r['total']);
  $metrics['vendor']['rate_5xx'] = ((int)$r['s5xx'] / $total) * 100.0;
  $metrics['vendor']['rate_429'] = ((int)$r['s429'] / $total) * 100.0;
} catch (\Throwable $e) {
  $metrics['vendor']['rate_5xx'] = 0.0;
  $metrics['vendor']['rate_429'] = 0.0;
}

// ---------- grade ----------
$reasons = [];
$grade = 'GREEN';

// RED triggers
if ($metrics['queue']['pending'] > 5000) $reasons[] = 'pending_gt_5000';
if ($metrics['queue']['oldest_pending_age_s'] > 1800) $reasons[] = 'oldest_pending_gt_30m';
if ($metrics['queue']['done_1m'] == 0 && $metrics['queue']['pending'] > 0 && $metrics['queue']['oldest_pending_age_s'] > 600) $reasons[] = 'no_completions_10m_with_backlog';
if ($metrics['vendor']['rate_5xx'] > 15.0) $reasons[] = 'vendor_5xx_gt_15pct';
if ($metrics['vendor']['rate_429'] > 20.0) $reasons[] = 'vendor_429_gt_20pct';
if ($metrics['webhooks']['last_event_age_s'] > 900) $reasons[] = 'webhooks_stale_gt_15m';
if (Config::get('vend_domain_prefix','')==='' || stripos((string)Config::get('vend.api_base',''), 'lightspeed')===false) $reasons[] = 'vend_config_invalid';
if (!empty($reasons)) $grade = 'RED';

// AMBER triggers (only if still green)
if ($grade === 'GREEN') {
  if ($metrics['queue']['pending'] > 1000) $reasons[] = 'pending_gt_1000';
  if ($metrics['queue']['oldest_pending_age_s'] > 600) $reasons[] = 'oldest_pending_gt_10m';
  if ($metrics['vendor']['rate_5xx'] > 5.0) $reasons[] = 'vendor_5xx_gt_5pct';
  if ($metrics['vendor']['rate_429'] > 5.0) $reasons[] = 'vendor_429_gt_5pct';
  if ($metrics['webhooks']['last_event_age_s'] > 300) $reasons[] = 'webhooks_stale_gt_5m';
  if (!empty($reasons)) $grade = 'AMBER';
}

// ---------- actions ----------
$actions = [];
if ($grade === 'GREEN') {
  // restore
  if (Config::getBool('ui.readonly', false)) { Degrade::setReadOnly(false); $actions[]='readonly.off'; }
  Degrade::setBanner(false, 'info', '');
  Config::set('queue.kill_all', false);
  Config::set('webhook.fanout.enabled', true);
}
elseif ($grade === 'AMBER') {
  Degrade::setBanner(true, 'warning', 'System is catching up. Some actions slowed for safety.');
  // reduce high-risk concurrency (inventory.command)
  $cap = (int)(Config::get('vend.queue.max_concurrency.inventory.command', 1) ?? 1);
  if ($cap > 2) { Config::set('vend.queue.max_concurrency.inventory.command', 2); $actions[]='cap.inventory.command=2'; }
  Config::set('queue.kill_all', false);
  $actions[]='banner.warning';
}
else { // RED
  Degrade::setReadOnly(true);
  Degrade::setBanner(true, 'danger', 'CIS ↔ Vend degraded — writes paused to protect data.');
  Config::set('queue.kill_all', true);
  Config::set('webhook.fanout.enabled', false); // still ingest events
  $actions[]='readonly.on';
  $actions[]='kill_all.on';
  $actions[]='fanout.off';
}

// ---------- audit ----------
try {
  $stmt = $pdo->prepare("
    INSERT INTO system_health_log (graded_at, grade, score, reasons, metrics, actions)
    VALUES (NOW(), :grade, :score, :reasons, :metrics, :actions)
  ");
  $stmt->execute([
    ':grade'   => $grade,
    ':score'   => $grade==='GREEN'?100:($grade==='AMBER'?70:30),
    ':reasons' => json_encode($reasons, JSON_UNESCAPED_SLASHES),
    ':metrics' => json_encode($metrics, JSON_UNESCAPED_SLASHES),
    ':actions' => json_encode($actions, JSON_UNESCAPED_SLASHES),
  ]);
} catch (\Throwable $e) { /* best effort */ }

// ---------- reply ----------
echo json_encode(['ok'=>true,'data'=>['grade'=>$grade,'reasons'=>$reasons,'actions'=>$actions,'metrics'=>$metrics]], JSON_UNESCAPED_SLASHES);
