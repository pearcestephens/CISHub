<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/pipeline.trace.data.php
 * Purpose: JSON data endpoint for Pipeline Trace (by trace_id or job_id)
 */

use Queue\PdoConnection;

require_once __DIR__ . '/../src/PdoConnection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$trace = isset($_GET['trace']) ? trim((string)$_GET['trace']) : '';
$jobId = isset($_GET['job']) ? (int)$_GET['job'] : 0;
$sinceMin = isset($_GET['since']) ? max(0, (int)$_GET['since']) : 240; // 4h default window

$out = [ 'ok' => true, 'trace' => $trace, 'job' => $jobId, 'events' => [] ];

try {
    $pdo = PdoConnection::instance();
    $where = [];$params = [];
    if ($jobId > 0) { $where[] = 'job_id = :jid'; $params[':jid'] = $jobId; }
    if ($trace !== '') {
        // Prefer correlation_id column if present
        $hasCorr = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM ls_job_logs LIKE 'correlation_id'");
            $hasCorr = (bool)$chk->fetchColumn();
        } catch (\Throwable $e) { $hasCorr = false; }
        if ($hasCorr) {
            $where[] = '(correlation_id = :cid OR COALESCE(message, log_message) LIKE :like)';
            $params[':cid'] = $trace; $params[':like'] = '%' . $trace . '%';
        } else {
            $where[] = 'COALESCE(message, log_message) LIKE :like';
            $params[':like'] = '%' . $trace . '%';
        }
    }
    if ($sinceMin > 0) { $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE)"; $params[':m'] = $sinceMin; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $sql = "SELECT id, job_id, level, created_at, COALESCE(message, log_message) AS msg, NULL AS src FROM ls_job_logs $whereSql ORDER BY id ASC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) { $type = ($k === ':m' || $k === ':jid') ? \PDO::PARAM_INT : \PDO::PARAM_STR; $stmt->bindValue($k, $v, $type); }
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    // Also include webhook_events when trace id matches its payload or headers
    if ($trace !== '') {
        try {
            $chk = $pdo->prepare("SHOW TABLES LIKE 'webhook_events'"); $chk->execute();
            if ($chk->fetchColumn()) {
                $w = $pdo->prepare("SELECT id, webhook_id, webhook_type, received_at, payload, headers FROM webhook_events WHERE (payload LIKE :like OR headers LIKE :like) AND received_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE) ORDER BY id ASC LIMIT 200");
                $w->bindValue(':like', '%' . $trace . '%', \PDO::PARAM_STR);
                $w->bindValue(':m', $sinceMin, \PDO::PARAM_INT);
                $w->execute();
                $wh = $w->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                foreach ($wh as $r) {
                    $rows[] = [
                        'id' => (int)$r['id'],
                        'job_id' => null,
                        'level' => 'info',
                        'created_at' => (string)$r['received_at'],
                        'msg' => json_encode(['event' => 'webhook.received', 'webhook_id' => $r['webhook_id'], 'webhook_type' => $r['webhook_type']]),
                        'src' => 'webhook_events',
                    ];
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    // Normalize into checkpoints
    $events = [];
    foreach ($rows as $r) {
        $ts = (string)$r['created_at'];
        $msg = (string)$r['msg'];
        $obj = null;
        if ($msg !== '' && ($msg[0] === '{' || $msg[0] === '[')) {
            $tmp = json_decode($msg, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $obj = $tmp; }
        }
        $stage = 'Queue';
        $source = $r['src'] ?? 'ls_job_logs';
        // Infer stage from event/source
        if (is_array($obj) && isset($obj['source'])) {
            if ($obj['source'] === 'cis.quick_qty.bridge') { $stage = 'Submit Handler'; }
        }
        if (is_string($msg) && strpos($msg, 'runner.start') !== false) { $stage = 'Runner'; }
        if (is_string($msg) && strpos($msg, 'job.process') !== false) { $stage = 'Worker'; }
        if ($source === 'webhook_events') { $stage = 'Webhook Intake'; }
        $events[] = [
            'time' => $ts,
            'stage' => $stage,
            'message' => $msg,
            'source' => $source,
        ];
    }
    $out['events'] = $events;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    return;
}

echo json_encode($out);
