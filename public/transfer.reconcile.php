<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoWorkItemRepository as Repo;

Http::commonJsonHeaders();
if (!Http::ensurePost() || !Http::ensureAuth() || !Http::rateLimit('transfer_reconcile', 10)) { return; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

$tid  = isset($in['transfer_pk']) ? (int)$in['transfer_pk'] : 0;
$mode = isset($in['strategy']) ? (string)$in['strategy'] : 'auto';
$idk  = isset($in['idempotency_key']) && $in['idempotency_key'] !== '' ? (string)$in['idempotency_key'] : ("tr:$tid:recon:" . date('Ymdd'));

if ($tid <= 0) { Http::error('bad_request', 'transfer_pk required'); return; }

$payload = [
  'transfer_pk' => $tid,
  'strategy'    => $mode,   // 'auto' | 'manual' (freeform)
  'trace_id'    => Http::requestId(),
];

$jobId = Repo::addJob('reconcile_discrepancies', $payload, $idk);
Http::respond(true, ['job_id' => $jobId, 'idempotency_key' => $idk]);
