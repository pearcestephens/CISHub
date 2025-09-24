<?php
declare(strict_types=1);
/**
 * File: setup.vend.php
 * Purpose: Secure admin setup endpoint to configure Lightspeed (Vend) domain prefix and OAuth credentials.
 * Author: CIS Automation
 * Last Modified: 2025-09-24
 * Dependencies: Config, PdoConnection, Http, OAuthClient
 * URL: https://staff.vapeshed.co.nz/assets/services/queue/public/setup.vend.php
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/OAuthClient.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';

use Queue\Http;
use Queue\Config;
use Queue\Lightspeed\OAuthClient;
use Queue\Lightspeed\Web as LsWeb;

Http::commonJsonHeaders();
if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('setup_vend', 3)) { return; }

// Parse JSON or form body
$raw = file_get_contents('php://input') ?: '';
$in = [];
if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) { $in = $tmp; }
}
if (!$in) { $in = $_POST ?: []; }

/** Basic validation helpers */
$out = [ 'updated' => [], 'warnings' => [], 'tested' => false, 'token_expires_in' => null, 'kicked' => false ];
$mask = function (?string $s): ?string { if ($s === null || $s === '') return $s; $len = strlen($s); return $len <= 8 ? str_repeat('*', max(0,$len-2)) . substr($s, -2) : str_repeat('*', $len-8) . substr($s, -8); };
$getBool = function($v, bool $def = false): bool {
    if (is_bool($v)) return $v; if (is_string($v)) { $l = strtolower(trim($v)); if (in_array($l, ['1','true','yes','on'], true)) return true; if (in_array($l, ['0','false','no','off'], true)) return false; } return (bool)$v ?: $def;
};

try {
    // 1) Required: vend_domain_prefix (accept URL/host and normalize to first label)
    if (isset($in['vend_domain_prefix'])) {
        $rawPrefix = (string)$in[ 'vend_domain_prefix' ];
        // Config::set will normalize and validate
        Config::set('vend_domain_prefix', $rawPrefix);
        $prefix = (string)(Config::get('vend_domain_prefix','') ?? '');
        $out['updated']['vend_domain_prefix'] = $prefix;
        $out['token_endpoint_preview'] = sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $prefix);
    }

    // Optional: API base override (rare)
    if (isset($in['vend.api_base'])) {
        $base = trim((string)$in['vend.api_base']);
        if ($base !== '' && stripos($base, 'http') === 0) { Config::set('vend.api_base', $base); $out['updated']['vend.api_base'] = $base; }
    }

    // 2) Client ID/Secret
    if (isset($in['vend_client_id'])) { $cid = (string)$in['vend_client_id']; if ($cid === '') { Http::error('bad_request','vend_client_id required when provided'); return; } Config::set('vend_client_id', $cid); $out['updated']['vend_client_id'] = $mask($cid); }
    if (isset($in['vend_client_secret'])) { $cs = (string)$in['vend_client_secret']; if ($cs === '') { Http::error('bad_request','vend_client_secret required when provided'); return; } Config::set('vend_client_secret', $cs); $out['updated']['vend_client_secret'] = $mask($cs); }

    // 3) Token material: prefer refresh_token; else authorization_code
    $didTokenOp = false; $tokenNote = null;
    if (isset($in['vend_refresh_token']) && (string)$in['vend_refresh_token'] !== '') {
        $rt = (string)$in['vend_refresh_token'];
        Config::set('vend_refresh_token', $rt);
        $out['updated']['vend_refresh_token'] = $mask($rt);
        try {
            $tok = OAuthClient::refresh($rt);
            if ($tok !== '') { $didTokenOp = true; $tokenNote = 'refreshed'; }
        } catch (\Throwable $e) { $out['warnings'][] = 'refresh_failed:' . $e->getMessage(); }
    }
    if (!$didTokenOp && isset($in['vend_auth_code']) && (string)$in['vend_auth_code'] !== '') {
        $code = (string)$in['vend_auth_code'];
        Config::set('vend_auth_code', $code);
        $out['updated']['vend_auth_code'] = $mask($code);
        try { OAuthClient::exchange($code); $didTokenOp = true; $tokenNote = 'exchanged'; } catch (\Throwable $e) { $out['warnings'][] = 'exchange_failed:' . $e->getMessage(); }
    }

    // 4) Quality-of-life flags
    if (array_key_exists('vend.queue.auto_kick.enabled', $in)) { Config::set('vend.queue.auto_kick.enabled', $getBool($in['vend.queue.auto_kick.enabled'], true)); $out['updated']['vend.queue.auto_kick.enabled'] = (bool)Config::get('vend.queue.auto_kick.enabled', true); }
    if (array_key_exists('vend.queue.continuous.enabled', $in)) { Config::set('vend.queue.continuous.enabled', $getBool($in['vend.queue.continuous.enabled'], true)); $out['updated']['vend.queue.continuous.enabled'] = (bool)Config::get('vend.queue.continuous.enabled', true); }
    if (array_key_exists('queue.runner.enabled', $in)) { Config::set('queue.runner.enabled', $getBool($in['queue.runner.enabled'], true)); $out['updated']['queue.runner.enabled'] = (bool)Config::get('queue.runner.enabled', true); }
    if (array_key_exists('vend.webhook.open_mode', $in)) { Config::set('vend.webhook.open_mode', $getBool($in['vend.webhook.open_mode'], false)); $out['updated']['vend.webhook.open_mode'] = (bool)Config::get('vend.webhook.open_mode', false); }
    if (array_key_exists('vend.webhook.open_mode_until', $in)) { $until = (int)$in['vend.webhook.open_mode_until']; Config::set('vend.webhook.open_mode_until', $until); $out['updated']['vend.webhook.open_mode_until'] = $until; }

    // 5) Validate OAuth availability if possible (tokens may be permanent; expires_at may be 0)
    try {
        $tok = OAuthClient::ensureValid();
        $exp = (int)(Config::get('vend_token_expires_at', 0) ?? 0);
        $out['tested'] = $tok !== '';
        $out['token_expires_in'] = $exp > 0 ? ($exp - time()) : null; // null denotes non-expiring/permanent
        if ($tokenNote !== null) { $out['token_note'] = $tokenNote; }
    } catch (\Throwable $e) {
        $out['tested'] = false;
        $out['warnings'][] = 'token_check_failed:' . $e->getMessage();
    }

    // 6) Auto-kick runner to start draining immediately (opt-out via kick=false)
    $shouldKick = $getBool($in['kick'] ?? true, true);
    if ($shouldKick) {
        try { LsWeb::kick(null); $out['kicked'] = true; } catch (\Throwable $e) { $out['warnings'][] = 'kick_failed:' . $e->getMessage(); }
    }

    // Response with masked secrets and next steps
    Http::respond(true, $out + [
        'next' => [
            'health' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/health.php',
            'worker_status' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/worker.status.php',
            'queue_status' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/queue.status.php',
            'kick_runner' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/runner.kick.php',
        ]
    ]);
} catch (\Throwable $e) {
    Http::error('setup_failed', $e->getMessage());
}
