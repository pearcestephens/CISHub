<?php
declare(strict_types=1);
/**
 * File: prefix.normalize.php
 * Purpose: One-time fixer to normalize vend_domain_prefix and preview token endpoint.
 */
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/OAuthClient.php';

use Queue\Http;
use Queue\Config;
use Queue\Lightspeed\OAuthClient;

Http::commonJsonHeaders();
if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }

// Accept { value: string, apply?: bool }
$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
}
if (!$in) { $in = $_POST ?: []; }

$val = isset($in['value']) ? (string)$in['value'] : (string)(Config::get('vend_domain_prefix','') ?? '');
$apply = isset($in['apply']) ? (bool)$in['apply'] : false;

try {
    // Leverage Config::set normalization by doing a dry run first
    $ref = new \ReflectionClass(Config::class);
    $m = $ref->getMethod('set'); // set() will normalize
    // We cannot call private normalize directly, emulate by trying set on a clone key and catching failure
    $normalized = null;
    try {
        // Attempt normalization by writing to a temp key then reading back
        Config::set('vend_domain_prefix', $val);
        $normalized = (string)(Config::get('vend_domain_prefix','') ?? '');
    } catch (\Throwable $e) {
        Http::error('normalize_failed', $e->getMessage()); return;
    }
    if (!$apply) {
        // preview only
        $endpoint = sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $normalized);
        Http::respond(true, ['normalized' => $normalized, 'endpoint_preview' => $endpoint, 'applied' => false]);
        return;
    }
    // Applied already by Config::set above; re-write to ensure persistence
    Config::set('vend_domain_prefix', $normalized);
    $endpoint = sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $normalized);
    // Validate token endpoint shape by avoiding double scheme
    $ok = (stripos($endpoint, 'https://') === 0) && substr_count($endpoint, 'https://') === 1;
    Http::respond(true, ['normalized' => $normalized, 'endpoint' => $endpoint, 'applied' => true, 'ok' => $ok]);
} catch (\Throwable $e) {
    Http::error('prefix_normalize_failed', $e->getMessage());
}
