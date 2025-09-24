<?php
declare(strict_types=1);

/**
 * assets/services/queue/public/webhook.test.php
 *
 * Local test driver for webhook intake.
 * Requires ADMIN bearer if your other endpoints do (this one does NOT enforce it).
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Config;
use Queue\Http;

Http::commonJsonHeaders();

// Inputs (GET or POST)
$target   = (string)($_GET['target']   ?? $_POST['target']   ?? 'https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php');
$type     = (string)($_GET['type']     ?? $_POST['type']     ?? 'inventory.update'); // vendor-ish naming
$encode   = (string)($_GET['encoding'] ?? $_POST['encoding'] ?? 'json');             // "json" or "form"
$useTs    = (int)   ($_GET['ts']       ?? $_POST['ts']       ?? 1);                  // include timestamp
$custom   = (string)($_GET['payload']  ?? $_POST['payload']  ?? '');

// Get secret
$secret = (string)(Config::get('vend_webhook_secret', '') ?? '');
if ($secret === '') {
  $secret = (string)(Config::get('vend.client_secret', '') ?? '');
}
if ($secret === '') {
  echo json_encode(['ok'=>false, 'error'=>['code'=>'missing_secret','message'=>'No vend_webhook_secret or vend.client_secret configured']]);
  exit;
}

function samplePayload(string $t): array {
  switch ($t) {
    case 'consignment.send':
      return [
        'type' => $t,
        'data' => [
          'consignment_id' => 123456,
          'lines' => [['product_id'=>101, 'qty'=>2], ['product_id'=>202, 'qty'=>1]],
        ],
      ];
    case 'consignment.receive':
      return [
        'type' => $t,
        'data' => [
          'consignment_id' => 123456,
          'received' => [['product_id'=>101, 'qty'=>2]],
        ],
      ];
    case 'inventory.update':
    default:
      return [
        'type' => 'inventory.update',
        'data' => ['product_id'=>1001, 'outlet_id'=>1, 'count'=>10],
      ];
  }
}

$payloadArr = $custom !== '' ? (json_decode($custom, true) ?: samplePayload($type)) : samplePayload($type);
$payloadStr = json_encode($payloadArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$ts = (string)time();

// Encode body
if (strtolower($encode) === 'form') {
  $raw = 'payload=' . rawurlencode($payloadStr);
  $ct  = 'application/x-www-form-urlencoded; charset=utf-8';
} else {
  $raw = $payloadStr;
  $ct  = 'application/json; charset=utf-8';
}

// Signatures
$rawSig   = base64_encode(hash_hmac('sha256', $raw, $secret, true));
$comboSig = base64_encode(hash_hmac('sha256', $ts . '.' . $raw, $secret, true));

// Build headers
$hdrs = [
  'User-Agent: CIS-TestWebhook/1.1',
  'Content-Type: ' . $ct,
  'X-LS-Event-Type: ' . $payloadArr['type'],
  'X-LS-Webhook-Id: TEST-' . substr(sha1((string)microtime(true)), 0, 12),
];
if ($useTs) {
  $hdrs[] = 'X-LS-Timestamp: ' . $ts;
  $hdrs[] = 'X-Signature: signature=' . $comboSig . ', algorithm=HMAC-SHA256';
  $hdrs[] = 'X-LS-Signature: ' . $comboSig;
} else {
  $hdrs[] = 'X-Signature: signature=' . $rawSig . ', algorithm=HMAC-SHA256';
  $hdrs[] = 'X-LS-Signature: ' . $rawSig;
}

// Send
$ctx = stream_context_create([
  'http' => [
    'method'        => 'POST',
    'header'        => implode("\r\n", $hdrs),
    'content'       => $raw,
    'timeout'       => 8,
    'ignore_errors' => true,
  ],
  'ssl'  => ['verify_peer'=>true, 'verify_peer_name'=>true],
]);

$respRaw  = @file_get_contents($target, false, $ctx);
$httpCode = 0;
$respHdrs = $http_response_header ?? [];
foreach ($respHdrs as $line) {
  if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $line, $m)) {
    $httpCode = (int)$m[1];
    break;
  }
}

$resp = null;
if (is_string($respRaw) && $respRaw !== '') {
  $tmp = json_decode($respRaw, true);
  if (json_last_error() === JSON_ERROR_NONE) $resp = $tmp;
}

echo json_encode([
  'ok'            => $httpCode >= 200 && $httpCode < 300,
  'http_code'     => $httpCode,
  'posted_to'     => $target,
  'request'       => [
    'headers'     => $hdrs,
    'encoding'    => strtolower($encode),
    'timestamped' => (bool)$useTs,
    'len'         => strlen($raw),
  ],
  'receiver_reply'=> $resp,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
