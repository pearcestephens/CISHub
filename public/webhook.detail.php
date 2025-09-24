<?php
declare(strict_types=1);
/**
 * File: public/webhook.detail.php
 * Purpose: Return JSON detail for a webhook event for payload explorer modal
 * Author: GitHub Copilot
 * Last Modified: 2025-09-22
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../src/PdoConnection.php';
    $pdo = Queue\PdoConnection::instance();

    $idParam = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $widParam = isset($_GET['wid']) ? trim((string)$_GET['wid']) : '';
    if ($idParam <= 0 && $widParam === '') {
        echo json_encode(['success'=>false,'error'=>['code'=>'bad_request','message'=>'Missing id or wid']]);
        exit;
    }

    if ($idParam > 0) {
        $stmt = $pdo->prepare('SELECT id, webhook_id, webhook_type, status, received_at, processed_at, error_message, payload, raw_payload, headers, source_ip, user_agent FROM webhook_events WHERE id=:id');
        $stmt->execute([':id'=>$idParam]);
    } else {
        $stmt = $pdo->prepare('SELECT id, webhook_id, webhook_type, status, received_at, processed_at, error_message, payload, raw_payload, headers, source_ip, user_agent FROM webhook_events WHERE webhook_id=:wid');
        $stmt->execute([':wid'=>$widParam]);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['success'=>true,'data'=>['webhook'=>$row]]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>['code'=>'server_error','message'=>$e->getMessage()]]);
}
