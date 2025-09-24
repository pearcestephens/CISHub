<?php
declare(strict_types=1);
/**
 * File: public/transfer.receive.enqueue.php
 * Purpose: Enqueue a Lightspeed consignment RECEIVED update based on a stock transfer receive action
 * Author: Queue Service
 * Last Modified: 2025-09-21
 * Dependencies: PdoConnection, Config, Http, PdoWorkItemRepository
 * Usage: POST JSON or form-encoded
 *   {
 *     "transfer_pk": 123,                // optional if transfer_public_id provided
 *     "transfer_public_id": "TR-001",   // optional if transfer_pk provided
 *     "consignment_id": 456789,          // required
 *     "lines": [                         // required: list of { product_id, qty, sku? }
 *       { "product_id": 1001, "qty": 2 },
 *       { "product_id": 1002, "qty": 1, "sku": "ABC-123" }
 *     ],
 *     "idempotency_key": "optional-key" // optional
 *   }
 */
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';

use Queue\Http;
use Queue\PdoWorkItemRepository as Repo;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('transfer_receive_enqueue', 30)) { return; }

try {
    $raw = file_get_contents('php://input') ?: '';
    $in = [];
    if ($raw !== '') {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
    }
    if (!$in) { $in = $_POST ?: []; }

    $transferPk = isset($in['transfer_pk']) && is_numeric($in['transfer_pk']) ? (int)$in['transfer_pk'] : null;
    $transferPublicId = isset($in['transfer_public_id']) ? (string)$in['transfer_public_id'] : null;
    $consignmentId = isset($in['consignment_id']) ? (int)$in['consignment_id'] : 0;
    $lines = (array)($in['lines'] ?? []);
    $idk = isset($in['idempotency_key']) ? (string)$in['idempotency_key'] : null;

    if ($consignmentId <= 0) { Http::error('bad_request', 'consignment_id is required'); return; }
    if (!$lines) { Http::error('bad_request', 'lines array is required'); return; }

    // Normalize lines to expected shape for update_consignment RECEIVED
    $norm = [];
    foreach ($lines as $l) {
        if (!is_array($l)) continue;
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['qty']) ? (int)$l['qty'] : 0;
        if ($pid <= 0 || $qty < 0) continue;
        $row = ['product_id' => $pid, 'qty' => $qty];
        if (isset($l['sku']) && is_string($l['sku']) && $l['sku'] !== '') { $row['sku'] = (string)$l['sku']; }
        $norm[] = $row;
    }
    if (!$norm) { Http::error('bad_request', 'no valid lines provided'); return; }

    $payload = [
        'consignment_id' => $consignmentId,
        'status' => 'RECEIVED',
        'lines' => $norm,
    ];
    if ($transferPk !== null) { $payload['transfer_pk'] = $transferPk; }
    if ($transferPublicId !== null && $transferPublicId !== '') { $payload['transfer_public_id'] = $transferPublicId; }
    if ($idk !== null && $idk !== '') { $payload['idempotency_key'] = $idk; }

    $jobId = Repo::addJob('update_consignment', $payload, $idk);

    Http::respond(true, ['job_id' => $jobId, 'queued' => true]);
} catch (\Throwable $e) {
    Http::error('enqueue_failed', $e->getMessage());
}
