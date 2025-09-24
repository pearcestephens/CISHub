<?php
declare(strict_types=1);

/**
 * Pipeline Trace (Data API)
 *
 * Query params:
 *   ?trace=<string>      Optional correlation/trace id to search in logs (message/correlation_id) and webhook payloads/headers
 *   ?job=<int>           Optional job id (numeric). Works for modern (ls_jobs.id) and legacy (ls_jobs_map.id -> ls_jobs.job_id).
 *   ?since=<int>         Optional minutes back for logs/webhook search (default 240)
 *   ?limit=<int>         Optional max rows per source (default 1000, cap 5000)
 *
 * Response shape:
 *  {
 *    "ok": true,
 *    "trace": "...",
 *    "job": 721383,
 *    "events": [
 *      {"time":"2025-09-24 18:52:14","stage":"Worker","message":"job.claimed","source":"ls_job_logs"},
 *      {"time":"2025-09-24 18:52:15","stage":"Vend API","message":"vend.http.error ...","source":"ls_job_logs"},
 *      {"time":"2025-09-24 18:52:16","stage":"Webhook Intake","message":"webhook.received type=inventory.update","source":"webhook_events"},
 *      ...
 *    ]
 *  }
 */

use Queue\PdoConnection;

require_once __DIR__ . '/../src/PdoConnection.php';

header('Content-Type: application/json;charset=utf-8');
header('Cache-Control: no-store, private');

$trace    = isset($_GET['trace']) ? trim((string)$_GET['trace']) : '';
$jobId    = isset($_GET['job'])   ? (int)$_GET['job'] : 0;
$sinceMin = isset($_GET['since']) ? max(0, (int)$_GET['since']) : 240;
$limit    = isset($_GET['limit']) ? max(1, min(5000, (int)$_GET['limit'])) : 1000;

$out = ['ok'=>true,'trace'=>$trace,'job'=>$jobId,'events'=>[]];

try {
    $pdo = PdoConnection::instance();

    // ---------- schema detection ----------
    $jobsTbl = null; $jobsCols = [];
    foreach (['ls_jobs','cishub_jobs','cisq_jobs','queue_jobs','jobs'] as $t) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$t`");
            $st->execute();
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($cols && in_array('status', array_map('strtolower',$cols), true)) {
                $jobsTbl  = $t;
                $jobsCols = array_map('strtolower', $cols);
                break;
            }
        } catch (\Throwable $e) {}
    }
    if (!$jobsTbl) {
        echo json_encode(['ok'=>false,'error'=>'no_jobs_table_detected']); return;
    }
    $hasJobIdCol = in_array('job_id', $jobsCols, true);
    $hasIdCol    = in_array('id',     $jobsCols, true);

    // mapping table(s)
    $maps = [];
    foreach (['ls_jobs_map','cishub_jobs_map','cisq_jobs_map','jobs_map'] as $mt) {
        try { $m = $pdo->prepare("SHOW TABLES LIKE :t"); $m->execute([':t'=>$mt]); if ($m->fetchColumn()) $maps[] = $mt; } catch (\Throwable $e) {}
    }

    // logs table detection
    $logsTbl = null; $logsCols = [];
    foreach (['ls_job_logs','cishub_job_logs','cisq_job_logs','job_logs'] as $lt) {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `$lt`");
            $st->execute();
            $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($cols) { $logsTbl = $lt; $logsCols = array_map('strtolower',$cols); break; }
        } catch (\Throwable $e) {}
    }

    // webhook events table?
    $hasWebhook = false;
    try { $st = $pdo->prepare("SHOW TABLES LIKE 'webhook_events'"); $st->execute(); $hasWebhook = (bool)$st->fetchColumn(); } catch (\Throwable $e) {}

    // ---------- resolve legacy mapping if job id was given ----------
    $resolvedNumericId = null;   // ls_jobs.id (modern)
    $resolvedLegacyId  = null;   // ls_jobs.job_id (legacy UUID)

    if ($jobId > 0) {
        if ($hasIdCol) {
            // try modern id directly
            try {
                $s = $pdo->prepare("SELECT id".($hasJobIdCol?", job_id":"")." FROM `$jobsTbl` WHERE id=:i LIMIT 1");
                $s->execute([':i'=>$jobId]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $resolvedNumericId = (int)$row['id'];
                    if ($hasJobIdCol && !empty($row['job_id'])) $resolvedLegacyId = (string)$row['job_id'];
                }
            } catch (\Throwable $e) {}
        }
        if (!$resolvedLegacyId && !$resolvedNumericId && $hasJobIdCol && $maps) {
            // legacy path: map numeric -> job_id via known maps
            foreach ($maps as $mt) {
                try {
                    $m = $pdo->prepare("SELECT job_id FROM `$mt` WHERE id=:i LIMIT 1");
                    $m->execute([':i'=>$jobId]);
                    $jid = (string)($m->fetchColumn() ?: '');
                    if ($jid !== '') { $resolvedLegacyId = $jid; break; }
                } catch (\Throwable $e) {}
            }
        }
        if ($resolvedLegacyId && !$resolvedNumericId && $hasIdCol) {
            // try to recover modern numeric id from legacy UUID (when both exist)
            try {
                $s2 = $pdo->prepare("SELECT id FROM `$jobsTbl` WHERE job_id=:jid LIMIT 1");
                $s2->execute([':jid'=>$resolvedLegacyId]);
                $nid = $s2->fetchColumn();
                if ($nid !== false && $nid !== null) $resolvedNumericId = (int)$nid;
            } catch (\Throwable $e) {}
        }
    }

    // ---------- time filter ----------
    $sinceClause = '';
    if ($sinceMin > 0) {
        $sinceClause = " AND created_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE) ";
    }

    // ---------- gather events from logs ----------
    $events = [];

    if ($logsTbl) {
        // Build WHERE for logs:
        // priority 1: explicit job id
        // priority 2: correlation_id or message contains trace
        $params = [];
        $where = "1=0";
        if ($resolvedNumericId) {
            $where = "job_id = :jid_num";
            $params[':jid_num'] = $resolvedNumericId;
        }
        if ($resolvedLegacyId) {
            // cover legacy numeric logs missing modern id, and correlation_id
            $where = $where === "1=0" ? "(job_id = :jid_str OR correlation_id = :jid_str)" : "($where OR job_id = :jid_str OR correlation_id = :jid_str)";
            $params[':jid_str'] = $resolvedLegacyId;
        }
        if ($trace !== '') {
            $where = $where === "1=0"
                ? "(correlation_id = :cid OR COALESCE(message,log_message) LIKE :like)"
                : "($where OR correlation_id = :cid OR COALESCE(message,log_message) LIKE :like)";
            $params[':cid']  = $trace;
            $params[':like'] = '%'.$trace.'%';
        }

        if ($where !== "1=0") {
            $sql = "SELECT id, job_id, level, created_at, COALESCE(message,log_message) AS msg
                    FROM `$logsTbl`
                    WHERE $where ".
                    ($sinceClause ? $sinceClause : '') .
                   "ORDER BY id ASC LIMIT :lim";
            $st = $pdo->prepare($sql);
            foreach ($params as $k=>$v) {
                $type = ($k === ':jid_num' || $k === ':m') ? PDO::PARAM_INT : PDO::PARAM_STR;
                $st->bindValue($k, $v, $type);
            }
            if ($sinceClause) $st->bindValue(':m', $sinceMin, PDO::PARAM_INT);
            $st->bindValue(':lim', $limit, PDO::PARAM_INT);
            try {
                $st->execute();
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $msg = (string)($row['msg'] ?? '');
                    $stage = 'Worker';
                    $lower = strtolower($msg);
                    if (strpos($lower, 'runner.start') !== false) $stage = 'Runner';
                    elseif (strpos($lower, 'vend') !== false)     $stage = 'Vend API';
                    elseif (strpos($lower, 'webhook') !== false)  $stage = 'Webhook';

                    // Try JSON decode to extract 'event'/'source'
                    $pretty = $msg;
                    if ($msg !== '' && ($msg[0] === '{' || $msg[0] === '[')) {
                        $obj = json_decode($msg, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($obj)) {
                            if (!empty($obj['event']))  $pretty = (string)$obj['event'];
                            if (!empty($obj['source'])) $stage  = (string)$obj['source']==='cis.quick_qty.bridge' ? 'Submit Handler' : $stage;
                            if (!empty($obj['webhook_type']) && $stage === 'Webhook') $pretty = 'webhook.received type='.$obj['webhook_type'];
                        }
                    }

                    $events[] = [
                        'time'   => (string)($row['created_at'] ?? ''),
                        'stage'  => $stage,
                        'message'=> $pretty,
                        'source' => $logsTbl,
                    ];
                }
            } catch (\Throwable $e) {}
        }
    }

    // ---------- optionally pull webhook events that reference the trace ----------
    if ($hasWebhook && $trace !== '') {
        try {
            $w = $pdo->prepare(
                "SELECT id, webhook_id, webhook_type, received_at
                 FROM webhook_events
                 WHERE (payload LIKE :like OR headers LIKE :like) ".
                 ($sinceClause ? " AND received_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE) " : '' ) .
                "ORDER BY id ASC LIMIT :lim"
            );
            $w->bindValue(':like', '%'.$trace.'%', PDO::PARAM_STR);
            if ($sinceClause) $w->bindValue(':m', $sinceMin, PDO::PARAM_INT);
            $w->bindValue(':lim', $limit, PDO::PARAM_INT);
            $w->execute();
            while ($r = $w->fetch(PDO::FETCH_ASSOC)) {
                $events[] = [
                    'time'   => (string)($r['received_at'] ?? ''),
                    'stage'  => 'Webhook Intake',
                    'message'=> 'webhook.received type='.((string)$r['webhook_type'] ?? '').' id='.((string)$r['webhook_id'] ?? ''),
                    'source' => 'webhook_events',
                ];
            }
        } catch (\Throwable $e) {}
    }

    // ---------- sort and trim ----------
    usort($events, static function(array $a, array $b): int {
        $ta = strtotime((string)($a['time'] ?? '')); $tb = strtotime((string)($b['time'] ?? ''));
        if ($ta === $tb) return 0;
        return $ta <=> $tb;
    });

    // (Optionally) cap final events list
    if (count($events) > $limit) $events = array_slice($events, -$limit);

    $out['events'] = $events;
    echo json_encode($out);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
