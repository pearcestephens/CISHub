<?php
declare(strict_types=1);

/**
 * webhook.history.php
 * JSON timeline of webhook events enriched with processing outcomes.
 *
 * Query params (all optional):
 *   ?since_minutes=1440   window to look back (default 1440 = 24h)
 *   ?type=inventory.update  filter by webhook_type (substring or exact)
 *   ?limit=100            max events (default 100, max 1000)
 *
 * Auth: requires ADMIN bearer (same as other /public/* admin endpoints)
 * Rate limit: modest to keep it safe
 *
 * Output shape:
 * {
 *   ok: true,
 *   data: {
 *     since_minutes: 60,
 *     type: "inventory.update",
 *     events: [
 *       {
 *         id: 1234,
 *         webhook_id: "TEST-ABC",
 *         webhook_type: "inventory.update",
 *         received_at: "2025-09-24 18:12:34",
 *         status: "completed",
 *         processed_at: "2025-09-24 18:12:40",
 *         queue_job_id: 721367,
 *         result: {
 *           verified: true,
 *           expected: 6,
 *           observed: 6,
 *           attempts: 2
 *         },
 *         logs: [
 *           {time:"...",level:"info",msg:"inventory.command.verify",json:{...}},
 *           ...
 *         ]
 *       }
 *     ]
 *   }
 * }
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('webhook_history', 6)) { return; }

$sinceMin = isset($_GET['since_minutes']) ? max(1, min(10080, (int)$_GET['since_minutes'])) : 1440; // up to 7 days
$type     = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$limit    = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 100;

try {
    $pdo = \Queue\PdoConnection::instance();

    // 1) Pull webhook events window
    $where = ["received_at >= DATE_SUB(NOW(), INTERVAL :m MINUTE)"];
    $params = [':m' => $sinceMin];

    if ($type !== '') {
        // allow both exact and substring match
        $where[] = "(webhook_type = :t OR webhook_type LIKE :tl)";
        $params[':t']  = $type;
        $params[':tl'] = '%' . $type . '%';
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
        echo json_encode(['ok' => true, 'data' => ['since_minutes' => $sinceMin, 'type' => $type, 'events' => []]]);
        return;
    }

    // 2) Collect queue_job_ids and fetch logs in one go
    $jobIds = array_values(array_unique(array_map(static function ($r) {
        $v = $r['queue_job_id'] ?? null;
        return is_numeric($v) ? (int)$v : null;
    }, $events)));
    $jobIds = array_values(array_filter($jobIds, static fn($v) => $v !== null && $v > 0));

    $logsByJob = [];
    if ($jobIds) {
        $place = implode(',', array_fill(0, count($jobIds), '?'));
        $lsql = "SELECT job_id, created_at, level,
                    COALESCE(message, log_message) AS msg
                 FROM ls_job_logs
                 WHERE job_id IN ($place)
                 ORDER BY id ASC";
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

    // 3) Enrich each event with parsed outcomes
    $out = [];
    foreach ($events as $ev) {
        $jid = (int)($ev['queue_job_id'] ?? 0);
        $rawLogs = $jid && isset($logsByJob[$jid]) ? $logsByJob[$jid] : [];

        $parsedLogs = [];
        $result = [
            'verified' => null,
            'expected' => null,
            'observed' => null,
            'attempts' => null,
        ];

        foreach ($rawLogs as $L) {
            $j = null;
            $raw = (string)($L['msg'] ?? '');
            if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
                try { $j = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable $e) { $j = null; }
            }

            // Save a short view of logs (don’t explode payload)
            $row = [
                'time'  => (string)$L['created_at'],
                'level' => (string)$L['level'],
                'msg'   => $j['event'] ?? (is_string($raw) ? (strlen($raw) > 160 ? substr($raw, 0, 157) . '…' : $raw) : '[log]')
            ];
            if (is_array($j)) {
                $row['json'] = array_intersect_key($j, array_flip(['event','product_id','outlet_id','expected','observed','attempts','status','target','op','verified']));
            }
            $parsedLogs[] = $row;

            // Extract outcomes for inventory.command verification we log in Runner
            if (is_array($j) && isset($j['event'])) {
                $evName = (string)$j['event'];
                // Runner logs:
                //  - inventory.command.verify  { expected, observed, attempts, verified }
                //  - inventory.command.vend_confirmed
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

    echo json_encode([
        'ok'   => true,
        'data' => [
            'since_minutes' => $sinceMin,
            'type'          => $type,
            'events'        => $out,
        ],
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    Http::error('webhook_history_failed', $e->getMessage());
}
