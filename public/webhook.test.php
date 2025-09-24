<?php
declare(strict_types=1);

/**
 * File: assets/services/queue/public/webhook.test.php
 * Purpose: Admin-only tool to send a signed test webhook to our receiver
 * Link: https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.test.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_test', 10)) return;

// Support both form posts from the widget and raw JSON body
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$in = [];
if (stripos($contentType, 'application/json') !== false) {
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
} else {
    $in = $_POST ?: [];
}

$type = isset($in['type']) ? (string)$in['type'] : 'inventory.update';
$encoding = isset($in['encoding']) ? (string)$in['encoding'] : 'form'; // 'form' or 'json'
$customJson = isset($in['payload_json']) ? (string)$in['payload_json'] : '';
$targetUrl = 'https://staff.vapeshed.co.nz/assets/services/queue/public/webhook.php';

// Resolve secret (prefer vend_webhook_secret per receiver expectations)
$secret = (string) (Config::get('vend_webhook_secret', '') ?? '');
if ($secret === '') {
    // Fallback to OAuth client_secret if provided in config
    $secret = (string) (Config::get('vend.client_secret', '') ?? '');
}
if ($secret === '') {
    Http::error('missing_secret', 'No vend_webhook_secret or client_secret configured');
    return;
}

// Minimal sample payloads (compact to keep request small)
function samplePayloadFor(string $t): array {
    switch ($t) {
        case 'consignment.send':
            return [
                'id' => '0afa8de1-1441-11e7-edec-da436c01fae6',
                'consignment_id' => '0afa8de1-1441-11e7-edec-da43672aaf45',
                'product_id' => '0624dbcd-ef13-11e6-e986-fcca33dee6e6',
                'count' => '1.00000',
                'received' => null,
                'cost' => 0.3,
                'sequence_number' => 2,
            ];
        case 'consignment.receive':
            return [
                'id' => '0afa8de1-1441-11e7-edec-da42913731c8',
                'consignment_id' => '0afa8de1-1441-11e7-edec-da427ee4bec7',
                'product_id' => '0624dbcd-ef13-11e6-e986-fcc1c366e772',
                'count' => '8.00000',
                'received' => '8.00000',
                'cost' => 4,
                'sequence_number' => 5,
            ];
        case 'inventory.update':
        default:
            return [
                'attributed_cost' => '15.57',
                'count' => 10,
                'id' => '180e2ad4-1c18-5517-e110-9f927920a8cf',
                'outlet' => [
                    'id' => '0924dbcd-ef11-11e9-e989-ed89c9e5529a',
                    'name' => 'Shop 1',
                    'tax_id' => '0924dbcd-ef95-11e9-e989-ecd5a945118e',
                    'time_zone' => 'Pacific/Auckland',
                ],
                'outlet_id' => '0924dbcd-ef11-11e9-e989-ed89c9e5529a',
                'product' => [
                    'active' => true,
                    'base_name' => 'Post Shave Balm',
                    'id' => '0924dbcd-ef11-11e9-e989-fcc1c199e772',
                    'name' => 'Post Shave Balm',
                    'sku' => '10107',
                ],
                'product_id' => '0924dbcd-ef11-11e9-e989-fcc1c199e772',
                'reorder_point' => '0',
                'restock_level' => '0',
                'version' => 5105100802,
            ];
    }
}

// Determine payload
$payloadArr = [];
if ($customJson !== '') {
    $tmp = json_decode($customJson, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
        $payloadArr = $tmp;
    } else {
        Http::error('bad_payload_json', 'payload_json is not valid JSON');
        return;
    }
} else {
    $payloadArr = samplePayloadFor($type);
}

$payloadStr = json_encode($payloadArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($payloadStr) || $payloadStr === '') {
    Http::error('encode_failed', 'Failed to encode payload');
    return;
}

// Build raw body and headers akin to Lightspeed delivery
$isJson = strtolower($encoding) === 'json';
$rawBody = $isJson ? $payloadStr : ('payload=' . rawurlencode($payloadStr));
$hdr = [
    'Content-Type' => $isJson ? 'application/json; charset=utf-8' : 'application/x-www-form-urlencoded; charset=utf-8',
    'User-Agent' => 'CIS-TestWebhook/1.0',
    'X-LS-Timestamp' => (string) time(),
    'X-LS-Webhook-Id' => 'TEST-' . substr(sha1((string)microtime(true)), 0, 12),
    'X-LS-Event-Type' => $type,
];

// Compute signature per docs: HMAC-SHA256 over raw body, base64
$sigB64 = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
$signatureHeader = 'signature=' . $sigB64 . ',algorithm=HMAC-SHA256';
$hdr['X-Signature'] = $signatureHeader;
$hdr['X-LS-Signature'] = $sigB64; // compatible alt header supported by receiver

// Send HTTP POST
$headerLines = [];
foreach ($hdr as $k => $v) { $headerLines[] = $k . ': ' . $v; }
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headerLines),
        'content' => $rawBody,
        'timeout' => 5,
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$respRaw = @file_get_contents($targetUrl, false, $ctx);
$httpCode = 0; $respHeaders = [];
foreach ($http_response_header ?? [] as $line) {
    if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $line, $m)) { $httpCode = (int)$m[1]; }
    $respHeaders[] = $line;
}
$resp = null; if (is_string($respRaw) && $respRaw !== '') { $resp = json_decode($respRaw, true); }

Http::respond(($httpCode >= 200 && $httpCode < 300), [
    'posted_to' => $targetUrl,
    'http_code' => $httpCode,
    'event_type' => $type,
    'encoding' => $isJson ? 'json' : 'form',
    'request_headers' => $hdr,
    'request_body_len' => strlen($rawBody),
    'receiver_reply' => $resp,
]);
