<?php
declare(strict_types=1);

/**
 * assets/services/queue/public/transfer.receive.enqueue.php
 *
 * Enqueue a "consignment received" update into the queue.
 * Requires admin auth + rate limit. Idempotency-friendly when a key is provided.
 *
 * Body (JSON):
 *   consignment_id: int (required)
 *   transfer_pk: int (optional)
 *   transfer_public_id: string (optional)
 *   lines: [{product_id:int, qty:int, sku?:string}] (required, non-empty, qty>=0)
 *   idempotency_key: string (optional)
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';

use Queue\Http;
use Queue\PdoWorkItemRepository as Repo;

Http::commonJsonHeaders();
if (!Http::ensureAuth()) return;
if (!Http::ensurePost()) return;
if (!Http::rateLimit('transfer_receive_enqueue', 30)) return;

try {
    $raw = file_get_contents('php://input') ?: '';
    $in  = json_decode($raw, true) ?: $_POST;

    $consignmentId = isset($in['consignment_id']) ? (int)$in['consignment_id'] : 0;
    $transferPk    = isset($in['transfer_pk']) && is_numeric($in['transfer_pk']) ? (int)$in['transfer_pk'] : null;
    $transferPid   = isset($in['transfer_public_id']) ? (string)$in['transfer_public_id'] : null;
    $linesRaw      = $in['lines'] ?? [];
    $idk           = isset($in['idempotency_key']) ? (string)$in['idempotency_key'] : null;

    if ($consignmentId <= 0) { Http::error('bad_request','consignment_id is required and must be > 0'); return; }
    if (!is_array($linesRaw) || count($linesRaw) === 0) { Http::error('bad_request','lines array is required and must be non-empty'); return; }

    $norm = [];
    foreach ($linesRaw as $l) {
        if (!is_array($l)) continue;
        $pid = isset($l['product_id']) ? (int)$l['product_id'] : 0;
        $qty = isset($l['qty'])        ? (int)$l['qty']        : -1;
        if ($pid <= 0 || $qty < 0) continue;
        $row = ['product_id'=>$pid, 'qty'=>$qty];
        if (isset($l['sku']) && is_string($l['sku']) && $l['sku'] !== '') $row['sku'] = (string)$l['sku'];
        $norm[] = $row;
    }
    if (!$norm) { Http::error('bad_request','no valid lines provided'); return; }

    $payload = [
        'consignment_id'   => $consignmentId,
        'status'           => 'RECEIVED',
        'lines'            => $norm,
    ];
    if ($transferPk !== null)  $payload['transfer_pk']      = $transferPk;
    if ($transferPid !== null) $payload['transfer_public_id']= $transferPid;
    if ($idk !== null && $idk !== '') $payload['idempotency_key'] = $idk;

    $jobId = Repo::addJob('update_consignment', $payload, $idk);

    Http::respond(true, ['job_id'=>$jobId, 'queued'=>true]);
} catch (\Throwable $e) {
    Http::error('enqueue_failed', $e->getMessage(), null, 500);
}
