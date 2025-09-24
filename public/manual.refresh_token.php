<?php
declare(strict_types=1);

/**
 * assets/services/queue/public/manual.refresh_token.php
 *
 * Admin-triggered token refresh. Requires admin auth and rate-limit.
 * Returns new expiry info; does not expose the token.
 */

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/OAuthClient.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\Config;
use Queue\Lightspeed\OAuthClient;

Http::commonJsonHeaders();
if (!Http::ensurePost()) return;
if (!Http::ensureAuth()) return;
if (!Http::rateLimit('manual_refresh_token', 6)) return;

try {
    $new = OAuthClient::refresh((string)(Config::get('vend_refresh_token', '') ?? ''));
    $exp = (int)(Config::get('vend_token_expires_at', 0) ?? 0);

    Http::respond($new !== '', [
        'refreshed'  => $new !== '',
        'expires_at' => $exp ?: null,
        'expires_in' => $exp > 0 ? max(0, $exp - time()) : null,
    ], $new !== '' ? null : ['code'=>'refresh_failed','message'=>'Unable to refresh token']);
} catch (\Throwable $e) {
    Http::error('manual_refresh_error', $e->getMessage(), null, 500);
}
