<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoWorkItemRepository as Repo;

Http::commonJsonHeaders();
if (!Http::ensurePost() || !Http::ensureAuth() || !Http::rateLimit('transfer_send', 15)) { return; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

$tid   = isset($in['transfer_pk']) ? (int)$in['transfer_pk'] : 0;
$lines = is_array($in['lines'] ?? null) ? array_values($in['lines']) : [];
$ver   = isset($in['ver']) ? (string)$in['ver'] : date('YmdHi');
$idk   = isset($in['idempotency_key']) && $in['idempotency_key'] !== '' ? (string)$in['idempotency_key'] : "tr:$tid:sent:$ver";

if ($tid <= 0)      { Http::error('bad_request', 'transfer_pk required'); return; }
if (empty($lines))  { Http::error('bad_request', 'lines[] required');     return; }

$payload = [
  'transfer_pk' => $tid,
  'status'      => 'SENT',
  'lines'       => $lines,         // [{product_id, qty, (optional) sku}]
  'trace_id'    => Http::requestId(),
];

$jobId = Repo::addJob('update_consignment', $payload, $idk);
Http::respond(true, ['job_id' => $jobId, 'idempotency_key' => $idk]);
