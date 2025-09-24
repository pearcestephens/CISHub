<?php
declare(strict_types=1);
header('Content-Type: application/json;charset=utf-8');

try {
    require_once __DIR__ . '/../src/PdoConnection.php';
    $pdo = Queue\PdoConnection::instance();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success'=>false,'error'=>['code'=>'bad_request','message'=>'Missing or invalid id']]);
        exit;
    }

    // Detect schema
    $cols = [];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM ls_jobs");
        $st->execute();
        $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {}
    $hasId  = in_array('id', $cols, true);
    $hasJid = in_array('job_id', $cols, true);

    $jobRow = null;
    $mapJobId = null;

    if ($hasId) {
        // Modern: numeric PK on ls_jobs.id
        try {
            $s = $pdo->prepare('SELECT id,type,status,priority,attempts,idempotency_key,created_at,started_at,finished_at,payload FROM ls_jobs WHERE id=:id');
            $s->execute([':id'=>$id]);
            $jobRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {}
    }

    if ($jobRow === null && $hasJid) {
        // Legacy: resolve numeric id via mapping table, then fetch by job_id
        try {
            $m = $pdo->prepare('SHOW TABLES LIKE :t'); $m->execute([':t'=>'ls_jobs_map']);
            $hasMap = (bool)$m->fetchColumn();

            if ($hasMap) {
                $m2 = $pdo->prepare('SELECT job_id FROM ls_jobs_map WHERE id=:i LIMIT 1');
                $m2->execute([':i'=>$id]);
                $mapJobId = (string)($m2->fetchColumn() ?: '');
            }
        } catch (Throwable $e) {}

        if ($mapJobId !== '') {
            try {
                $s = $pdo->prepare('SELECT job_id AS id,type,status,priority,attempts,idempotency_key,created_at,started_at,completed_at AS finished_at,payload FROM ls_jobs WHERE job_id=:jid');
                $s->execute([':jid'=>$mapJobId]);
                $jobRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
    }

    // Logs: try modern correlation first, then legacy shapes
    $logs = [];
    try {
        if ($jobRow) {
            // Try modern ls_job_logs.job_id = numeric id; if legacy, try correlation via mapping UUID
            if ($hasId && isset($jobRow['id']) && is_numeric($jobRow['id'])) {
                $l = $pdo->prepare('SELECT id,created_at,level,COALESCE(message,log_message) AS message FROM ls_job_logs WHERE job_id=:id ORDER BY id DESC LIMIT 50');
                $l->execute([':id'=>(int)$jobRow['id']]);
                $logs = $l->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } elseif ($mapJobId !== '') {
                $l = $pdo->prepare('SELECT id,created_at,level,COALESCE(message,log_message) AS message FROM ls_job_logs WHERE job_id=:jid OR correlation_id=:cid ORDER BY id DESC LIMIT 50');
                $l->execute([':jid'=>$mapJobId, ':cid'=>$jobRow['id'] ?? $mapJobId]);
                $logs = $l->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }
    } catch (Throwable $e) {}

    echo json_encode(['success'=>true,'data'=>['job'=>$jobRow,'logs'=>$logs]]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>['code'=>'server_error','message'=>$e->getMessage()]]);
}
