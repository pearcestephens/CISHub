<?php
declare(strict_types=1);
/**
 * File: public/job.detail.php
 * Purpose: Return JSON details for a job and recent logs for modal display
 * Author: GitHub Copilot
 * Last Modified: 2025-09-22
 * Dependencies: PDO connection via PdoConnection
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../src/PdoConnection.php';
    $pdo = Queue\PdoConnection::instance();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['success'=>false,'error'=>['code'=>'bad_request','message'=>'Missing or invalid id']]);
        exit;
    }
    $job = null;
    try {
        $stmt = $pdo->prepare('SELECT id, type, status, priority, attempts, idempotency_key, created_at, started_at, finished_at, payload FROM ls_jobs WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $job = null;
    }

    $logs = [];
    try {
        $stmt2 = $pdo->prepare('SELECT id, created_at, level, COALESCE(message, log_message) AS message FROM ls_job_logs WHERE job_id=:id ORDER BY id DESC LIMIT 50');
        $stmt2->execute([':id'=>$id]);
        $logs = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $logs = [];
    }

    echo json_encode(['success'=>true,'data'=>['job'=>$job,'logs'=>$logs]]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>['code'=>'server_error','message'=>$e->getMessage()]]);
}
