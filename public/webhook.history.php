<?php
declare(strict_types=1);

/**
 * webhook.history.php  — schema-tolerant history of webhooks with queue/job outcomes
 *
 * Supports both schemas for ls_job_logs:
 *   - legacy:   (id, job_id, level, message, created_at, ...)
 *   - modern:   (id, job_id, level, message, log_message, created_at, ...)
 *
 * Query:
 *   ?since_minutes=1440
 *   ?type=inventory.update
 *   ?limit=100
 *   ?status=processed|failed|received (optional)
 *   ?webhook_id=<id> (optional)
 *   ?has_job=1|0 (optional)
 *   Cursor pagination (optional):
 *     ?cursor_received_at=YYYY-mm-dd HH:ii:ss
 *     ?cursor_id=<last_id>
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('webhook_history', 6)) { return; }

$sinceMin = isset($_GET['since_minutes']) ? max(1, min(10080, (int)$_GET['since_minutes'])) : 1440;
$type     = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$limit    = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 100;
$status   = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$whId     = isset($_GET['webhook_id']) ? trim((string)$_GET['webhook_id']) : '';
$hasJob   = isset($_GET['has_job']) ? (($_GET['has_job'] === '1' || $_GET['has_job'] === 'true') ? 1 : 0) : null;
$curTs    = isset($_GET['cursor_received_at']) ? trim((string)$_GET['cursor_received_at']) : '';
$curId    = isset($_GET['cursor_id']) ? max(0, (int)$_GET['cursor_id']) : 0;

try {
    $pdo = \Queue\PdoConnection::instance();

    // --- helpers ------------------------------------------------------------
    $tableHas = static function(string $table, string $column) use ($pdo): bool {
        try {
            $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE :c");
            $st->execute([':c' => $column]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    };
    $tableExists = static function(string $table) use ($pdo): bool {
        try {
            $st = $pdo->prepare("SHOW TABLES LIKE :t");
            $st->execute([':t' => $table]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    };

    // Pick a safe message expression depending on available columns
    $hasMessage     = $tableHas('ls_job_logs', 'message');
    $hasLogMessage  = $tableHas('ls_job_logs', 'log_message');
    $msgExpr = $hasMessage && $hasLogMessage
        ? "COALESCE(message, log_message)"
        : ($hasMessage ? "message" : ($hasLogMessage ? "log_message" : "''"));

    // --- 1) fetch webhook events in window ---------------------------------
    $where = ["received_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE)"];
    $params = [':m' => $sinceMin];

    if ($type !== '') {
        $where[] = "(webhook_type = :t OR webhook_type LIKE :tl)";
        $params[':t']  = $type;
        $params[':tl'] = '%' . $type . '%';
    }
    if ($status !== '') {
        $where[] = "status = :st";
        $params[':st'] = $status;
    }
    if ($whId !== '') {
        $where[] = "webhook_id = :wid";
        $params[':wid'] = $whId;
    }
    if ($hasJob !== null) {
        $where[] = $hasJob === 1 ? "(queue_job_id IS NOT NULL AND queue_job_id > 0)" : "(queue_job_id IS NULL OR queue_job_id = 0)";
    }
    // Cursor pagination (received_at DESC, id DESC) — return older-than-cursor page if provided
    if ($curTs !== '' && $curId > 0) {
        $where[] = "(received_at < :cursor_ts OR (received_at = :cursor_ts AND id < :cursor_id))";
        $params[':cursor_ts'] = $curTs;
        $params[':cursor_id'] = $curId;
    }

    $sql = "SELECT id, webhook_id, webhook_type, status, received_at, processed_at, error_message, queue_job_id
            FROM webhook_events
            " . ($where ? "WHERE " . implode(' AND ', $where) : "") . "
            ORDER BY received_at DESC
            LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$events) {
        echo json_encode(['ok' => true, 'data' => [
            'since_minutes' => $sinceMin,
            'type'          => $type,
            'events'        => []
        ]]);
        return;
    }

    // --- 2) gather logs for involved job_ids --------------------------------
    $jobIds = array_values(array_unique(array_filter(array_map(static function($r){
        $v = $r['queue_job_id'] ?? null;
        return is_numeric($v) ? (int)$v : null;
    }, $events), static fn($v)=>$v!==null && $v>0)));

    $logsByJob = [];
    if ($jobIds && $tableExists('ls_job_logs')) {
        $place = implode(',', array_fill(0, count($jobIds), '?'));
        $orderCol = $tableHas('ls_job_logs','created_at') ? 'created_at' : ($tableHas('ls_job_logs','id') ? 'id' : 'job_id');
        $lsql = "SELECT job_id, " . ($tableHas('ls_job_logs','created_at') ? 'created_at' : "NOW() AS created_at") . ", level, {$msgExpr} AS msg
                 FROM ls_job_logs
                 WHERE job_id IN ($place)
                 ORDER BY $orderCol ASC";
        $ls = $pdo->prepare($lsql);
        foreach ($jobIds as $i => $jid) {
            $ls->bindValue($i + 1, $jid, PDO::PARAM_INT);
        }
        $ls->execute();
        while ($row = $ls->fetch(PDO::FETCH_ASSOC)) {
            $jid = (int)$row['job_id'];
            $logsByJob[$jid] ??= [];
            $logsByJob[$jid][] = $row;
        }
    }

    // --- 3) enrich with outcomes parsed from Runner logs --------------------
    $out = [];
    foreach ($events as $ev) {
        $jid = (int)($ev['queue_job_id'] ?? 0);
        $rawLogs = $jid && isset($logsByJob[$jid]) ? $logsByJob[$jid] : [];

        $parsedLogs = [];
        $result = ['verified'=>null,'expected'=>null,'observed'=>null,'attempts'=>null];

        foreach ($rawLogs as $L) {
            $raw = (string)($L['msg'] ?? '');
            $j = null;
            if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
                $tmp = json_decode($raw, true);
                if (is_array($tmp)) { $j = $tmp; }
            }

            $row = [
                'time'  => (string)$L['created_at'],
                'level' => (string)$L['level'],
                'msg'   => is_array($j) && isset($j['event'])
                            ? (string)$j['event']
                            : (strlen($raw) > 160 ? substr($raw,0,157) . '…' : $raw),
            ];
            if (is_array($j)) {
                $row['json'] = array_intersect_key($j, array_flip([
                    'event','product_id','outlet_id','expected','observed','attempts','status','target','op','verified'
                ]));
            }
            $parsedLogs[] = $row;

            if (is_array($j) && isset($j['event'])) {
                $evName = (string)$j['event'];
                if ($evName === 'inventory.command.verify') {
                    $result['expected'] = isset($j['expected']) ? (int)$j['expected'] : $result['expected'];
                    $result['observed'] = isset($j['observed']) ? (int)$j['observed'] : $result['observed'];
                    $result['attempts'] = isset($j['attempts']) ? (int)$j['attempts'] : $result['attempts'];
                    if (isset($j['verified'])) { $result['verified'] = (bool)$j['verified']; }
                }
                if ($evName === 'inventory.command.vend_confirmed') {
                    $result['verified'] = true;
                }
            }
        }

        $out[] = [
            'id'            => (int)$ev['id'],
            'webhook_id'    => (string)$ev['webhook_id'],
            'webhook_type'  => (string)$ev['webhook_type'],
            'received_at'   => (string)$ev['received_at'],
            'status'        => (string)$ev['status'],
            'processed_at'  => $ev['processed_at'] ?? null,
            'queue_job_id'  => $jid ?: null,
            'error'         => $ev['error_message'] ?? null,
            'result'        => $result,
            'logs'          => $parsedLogs,
        ];
    }

    // Summary & pagination cursor
    $failedCount = 0; $withJob = 0; $verifiedTrue = 0;
    foreach ($out as $row) {
        $withJob += ($row['queue_job_id'] !== null) ? 1 : 0;
        $isFailed = ($row['error'] !== null) || (isset($row['status']) && in_array(strtolower((string)$row['status']), ['failed','error'], true));
        if ($isFailed) { $failedCount++; }
        if (isset($row['result']['verified']) && $row['result']['verified'] === true) { $verifiedTrue++; }
    }

    $nextCursor = null;
    if (!empty($events)) {
        $last = end($events);
        $nextCursor = [
            'cursor_received_at' => (string)$last['received_at'],
            'cursor_id' => (int)$last['id'],
        ];
        // reset internal pointer
        reset($events);
    }

    echo json_encode([
        'ok' => true,
        'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.history.php',
        'data' => [
            'since_minutes' => $sinceMin,
            'type' => $type,
            'status' => $status,
            'webhook_id' => $whId,
            'has_job' => $hasJob,
            'count' => count($out),
            'failed' => $failedCount,
            'with_job' => $withJob,
            'verified_true' => $verifiedTrue,
            'events' => $out,
            'next_cursor' => $nextCursor,
        ],
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    Http::error('webhook_history_failed', $e->getMessage());
}
