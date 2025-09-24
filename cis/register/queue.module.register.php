<?php
declare(strict_types=1);

/**
 * queue.module.register.php
 * Purpose: Register the Lightspeed Queue module into CIS Config registry (namespaced: system.queue.lightspeed).
 * Author: GitHub Copilot
 * Last Modified: 2025-09-20
 * Dependencies: app.php (DB constants), cis-config/ConfigV2.php (if installed)
 * @link https://staff.vapeshed.co.nz
 */

// Lightweight registrar that only registers the queue module in cis-config.
// It does NOT modify or depend on queue internals. Safe to run multiple times (idempotent).

// Resolve app.php relative to public_html
$root = dirname(__DIR__, 5); // .../public_html
require_once $root . '/app.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Local inline ConfigV2 shim: prefer loading if cis-config module is present
    $configV2Path = $_SERVER['DOCUMENT_ROOT'] . '/cis-config/ConfigV2.php';
    if (!is_file($configV2Path)) {
        throw new RuntimeException('cis-config module missing. Please deploy cis-config and run /cis-config/install.php');
    }
    require_once $configV2Path;

    // Register namespace: system.queue.lightspeed
    ConfigV2::registerNamespace(
        'system.queue.lightspeed',
        'Data & AI',
        'pearce.stephens@ecigdis.co.nz',
        'Vend X-Series queue and HTTP client configuration'
    );

    // Optionally seed definitions for visibility (no values written here)
    // Keys are documentation-only until set via /cis-config/public/config.set.php
    $defs = [
        ['vend_domain_prefix', 'string', null, false, 'Your Lightspeed domain prefix (subdomain)'],
        ['vend_client_id', 'string', null, true, 'OAuth client id'],
        ['vend_client_secret', 'string', null, true, 'OAuth client secret'],
        ['vend_auth_code', 'string', null, true, 'Initial auth code (one-time exchange)'],
        ['vend_access_token', 'string', null, true, 'Access token (managed)'],
        ['vend_refresh_token', 'string', null, true, 'Refresh token (managed)'],
        ['vend_token_expires_at', 'int', 0, false, 'Epoch expiry (managed)'],
        ['vend.api_base', 'string', 'https://x-series-api.lightspeedhq.com', false, 'Base API URL'],
        ['vend.timeout_seconds', 'int', 30, false, 'HTTP timeout seconds'],
        ['vend.retry_attempts', 'int', 3, false, 'HTTP retry attempts'],
        ['vend.http_mock', 'bool', false, false, 'Enable HTTP mock mode for safe tests'],
    ['vend_webhook_secret', 'string', null, true, 'Webhook HMAC shared secret'],
    ['vend_webhook_secret_prev', 'string', null, true, 'Previous webhook secret (grace overlap)'],
    ['vend_webhook_secret_prev_expires_at', 'int', 0, false, 'Epoch seconds when previous webhook secret expires'],
        ['vend.cb', 'json', ['tripped'=>false,'until'=>0], false, 'Circuit breaker state (managed)'],
        ['vend_queue_runtime_business', 'int', 120, false, 'Runner time budget seconds'],
        ['vend_queue_kill_switch', 'bool', false, false, 'Global kill switch'],
        ['vend_queue_disable_singleflight', 'bool', false, false, 'Disable advisory lock single-flight'],
        ['vend.queue.max_concurrency.default', 'int', 1, false, 'Default per-type concurrency'],
        ['vend.queue.max_concurrency.create_consignment', 'int', 1, false, 'Cap for create_consignment'],
        ['vend.queue.max_concurrency.update_consignment', 'int', 1, false, 'Cap for update_consignment'],
        ['vend.queue.max_concurrency.cancel_consignment', 'int', 1, false, 'Cap for cancel_consignment'],
        ['vend.queue.max_concurrency.mark_transfer_partial', 'int', 1, false, 'Cap for mark_transfer_partial'],
        ['vend.queue.max_concurrency.edit_consignment_lines', 'int', 1, false, 'Cap for edit_consignment_lines'],
        ['vend_queue_pause.create_consignment', 'bool', false, false, 'Pause create_consignment'],
        ['vend_queue_pause.update_consignment', 'bool', false, false, 'Pause update_consignment'],
        ['vend_queue_pause.cancel_consignment', 'bool', false, false, 'Pause cancel_consignment'],
        ['vend_queue_pause.mark_transfer_partial', 'bool', false, false, 'Pause mark_transfer_partial'],
        ['vend_queue_pause.edit_consignment_lines', 'bool', false, false, 'Pause edit_consignment_lines'],
    ['ADMIN_BEARER_TOKEN', 'string', null, true, 'Bearer token for admin endpoints'],
    ['ADMIN_BEARER_TOKEN_PREV', 'string', null, true, 'Previous admin bearer token (grace overlap)'],
    ['ADMIN_BEARER_TOKEN_PREV_EXPIRES_AT', 'int', 0, false, 'Epoch seconds when previous admin token expires'],
    ['ADMIN_BEARER_TOKENS', 'json', [], true, 'Permanent admin tokens (JSON array)'],
    // Optional: cis-config’s own admin bearer key under this namespace for protecting config.list/set
    ['auth.admin_bearer_token', 'string', null, true, 'Admin bearer for cis-config endpoints (optional)'],
    ];

    foreach ($defs as [$key, $type, $default, $sensitive, $description]) {
        try {
            ConfigV2::define('system.queue.lightspeed', $key, $type, $default, (bool)$sensitive, $description);
        } catch (Throwable $e) {
            // ignore duplicates or definition errors
        }
    }

    echo json_encode([
        'ok'=>true,
        'message'=>'Queue module registered in cis-config',
        'namespace'=>'system.queue.lightspeed',
        'links' => [
            'cis_config_dashboard' => 'https://staff.vapeshed.co.nz/cis-config/dashboard.php',
            'cis_config_set_api' => 'https://staff.vapeshed.co.nz/cis-config/public/config.set.php',
            'queue_dashboard' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php'
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'=>false,
        'error'=>[
            'code'=>'register_failed',
            'message'=>$e->getMessage()
        ],
        'links' => [
            'cis_config_install' => 'https://staff.vapeshed.co.nz/cis-config/install.php',
            'cis_config_dashboard' => 'https://staff.vapeshed.co.nz/cis-config/dashboard.php'
        ]
    ]);
}
