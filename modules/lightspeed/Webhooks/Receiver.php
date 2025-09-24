<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Webhooks;

use Modules\Lightspeed\Core\WorkItems;
use Modules\Lightspeed\Core\Logger;

require_once __DIR__ . '/../Core/bootstrap.php';

/**
 * Webhook Receiver for Lightspeed events
 * @link https://staff.vapeshed.co.nz
 */
header('Content-Type: application/json');

if (!cfg('LS_WEBHOOKS_ENABLED', true)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => ['code' => 'disabled', 'message' => 'Webhooks disabled']]);
    exit;
}

// Simple shared-secret verification
$shared = (string) (cfg('vend_webhook_secret', '') ?? '');
$got = $_SERVER['HTTP_X_LIGHTSPEED_SIGNATURE'] ?? '';
if ($shared !== '' && $got !== $shared) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => ['code' => 'unauthorized', 'message' => 'Invalid signature']]);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$event = json_decode($raw, true) ?: [];
$type = (string) ($event['type'] ?? '');

// Map webhook to work items (expand as needed)
switch ($type) {
    case 'inventory.updated':
        $pid = (int) ($event['data']['product_id'] ?? 0);
        $oid = (int) ($event['data']['outlet_id'] ?? 0);
        if ($pid && $oid) {
            WorkItems::add('pull_inventory', ['product_id' => $pid, 'outlet_id' => $oid], 'pull_inventory:' . $pid . ':' . $oid);
        }
        break;
    default:
        // Swallow unknowns
        Logger::warn('Unknown webhook type', ['meta' => ['type' => $type]]);
}

echo json_encode(['ok' => true]);
