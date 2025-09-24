<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Config;
use Queue\Logger;
use Queue\PdoConnection;

/**
 * OAuthClient (Lightspeed Retail X-Series)
 *
 * Public contract (unchanged):
 *   - ensureValid(): string           // returns a non-expired access token (may refresh/exchange)
 *   - refresh(string $refresh): string
 *   - exchange(string $authCode): string
 *
 * Storage/aliases (unchanged but hardened):
 *   Primary keys (underscore):
 *     vend_access_token
 *     vend_token_expires_at   (epoch seconds; 0 means "no expiry provided")
 *     vend_refresh_token
 *     vend_auth_code
 *     vend_client_id
 *     vend_client_secret
 *     vend_domain_prefix
 *
 *   Aliases (dot/camel legacy) are auto-resolved both read & write.
 */
final class OAuthClient
{
    /** Resolve config value with alias fallbacks. */
    private static function cfg(string $keyUnderscored, $default = null)
    {
        $v = Config::get($keyUnderscored, null);
        if ($v !== null && $v !== '') return $v;

        // Common aliases for migration/legacy
        static $aliases = [
            'vend_access_token'       => [
                'vend.access_token','vend.api_token','vend_api_token','vend.permanent_token','vend.permanent.access_token',
                'lightspeed.access_token','lightspeed.api_token','ls.access_token','ls.api_token'
            ],
            'vend_token_expires_at'   => ['vend.token_expires_at','vend.token.expires_at'],
            'vend_refresh_token'      => ['vend.refresh_token'],
            'vend_auth_code'          => ['vend.auth_code'],
            'vend_client_id'          => ['vend.client_id'],
            'vend_client_secret'      => ['vend.client_secret'],
            'vend_domain_prefix'      => ['vend.domain_prefix','vend.domain','lightspeed.domain','ls.domain_prefix'],
            // Optional: allow full host override for token endpoint construction
            'vend_host'               => ['vend.host','vend.full_domain','lightspeed.host','ls.host'],
            'vend_token_endpoint'     => ['vend.token_endpoint','lightspeed.token_endpoint','ls.token_endpoint'],
        ];

        if (isset($aliases[$keyUnderscored])) {
            foreach ($aliases[$keyUnderscored] as $k) {
                $v2 = Config::get($k, null);
                if ($v2 !== null && $v2 !== '') return $v2;
            }
            // token bundle object (legacy)
            if (in_array($keyUnderscored, ['vend_access_token','vend_token_expires_at','vend_refresh_token'], true)) {
                $obj = Config::get('vend.token', null);
                if (is_array($obj)) {
                    if ($keyUnderscored === 'vend_access_token'     && !empty($obj['access_token'])) return (string)$obj['access_token'];
                    if ($keyUnderscored === 'vend_token_expires_at' && isset($obj['expires_at']))     return $obj['expires_at'];
                    if ($keyUnderscored === 'vend_refresh_token'    && !empty($obj['refresh_token']))return (string)$obj['refresh_token'];
                }
                // Last resort env for access token (no refresh path)
                if ($keyUnderscored === 'vend_access_token') {
                    $env = getenv('VEND_ACCESS_TOKEN') ?: getenv('LS_ACCESS_TOKEN') ?: getenv('LIGHTSPEED_ACCESS_TOKEN');
                    if (is_string($env) && $env !== '') return $env;
                }
            }
        }

        // Try dot form
        $dot = str_replace('_', '.', $keyUnderscored);
        $v3  = Config::get($dot, null);
        if ($v3 !== null && $v3 !== '') return $v3;

        // Environment fallbacks for certain keys (no DB/config write required)
        if ($keyUnderscored === 'vend_domain_prefix') {
            $env = getenv('VEND_DOMAIN_PREFIX') ?: getenv('LS_DOMAIN_PREFIX');
            if (is_string($env) && $env !== '') return $env;
        }
        if ($keyUnderscored === 'vend_host') {
            $env = getenv('VEND_HOST') ?: getenv('LS_HOST') ?: getenv('LIGHTSPEED_HOST');
            if (is_string($env) && $env !== '') return $env;
        }
        if ($keyUnderscored === 'vend_token_endpoint') {
            $env = getenv('VEND_TOKEN_ENDPOINT') ?: getenv('LS_TOKEN_ENDPOINT');
            if (is_string($env) && $env !== '') return $env;
        }

        return $default;
    }

    private static function tokenEndpoint(): string
    {
        // Highest priority: explicit full token endpoint URL
        $explicit = (string)(self::cfg('vend_token_endpoint', '') ?? '');
        if ($explicit !== '') {
            // Accept either full URL or host; normalize to full URL
            if (stripos($explicit, 'http') === 0) return rtrim($explicit, '/');
            return sprintf('https://%s', rtrim($explicit, '/'));
        }

        // Next: full host override (e.g., vapeshed.retail.lightspeed.app). If a full URL is pasted, strip scheme.
        $host = (string)(self::cfg('vend_host', '') ?? '');
        if ($host !== '') {
            if (stripos($host, 'http') === 0) { $host = parse_url($host, PHP_URL_HOST) ?: $host; }
            $host = rtrim((string)$host, '/');
            if ($host === '') { throw new \RuntimeException('vend_host invalid'); }
            return sprintf('https://%s/api/1.0/token', rtrim($host, '/'));
        }

        // Classic: domain prefix composing modern Lightspeed Retail host. vend_domain_prefix must be the first DNS label only.
        $prefix = (string)(self::cfg('vend_domain_prefix', '') ?? '');
        if ($prefix !== '') {
            return sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $prefix);
        }

        throw new \RuntimeException('Missing vend_domain_prefix or vend_host for token endpoint');
    }

    /**
     * Ensure a valid (non-stale) access token is returned.
     * - Refreshes if expiring within ~120s
     * - Exchanges auth code if no refresh token yet
     * - Uses advisory locks to avoid thundering herd
     */
    public static function ensureValid(): string
    {
        $access = (string)(self::cfg('vend_access_token', '') ?? '');
        $expRaw = self::cfg('vend_token_expires_at', 0);
        $exp    = is_numeric($expRaw) ? (int)$expRaw : (int)($expRaw ?? 0);
        $refresh= (string)(self::cfg('vend_refresh_token', '') ?? '');
        $prefix = (string)(self::cfg('vend_domain_prefix','') ?? '');

        if ($access !== '') {
            if ($exp === 0 || $exp > time() + 120) return $access;
            // token near expiry; try refresh if we can
            if ($refresh === '' || $prefix === '') return $access;
        }

        if ($refresh !== '') {
            // Single-flight refresh
            $new = PdoConnection::withAdvisoryLock('ls_oauth_refresh', 10, static function() use ($refresh) {
                $a2  = (string)(self::cfg('vend_access_token', '') ?? '');
                $e2r = self::cfg('vend_token_expires_at', 0);
                $e2  = is_numeric($e2r) ? (int)$e2r : (int)($e2r ?? 0);
                if ($a2 !== '' && ($e2 === 0 || $e2 > time() + 120)) return $a2;
                return self::refresh($refresh);
            });
            if ($new !== '') return $new;
        }

        $code = (string)(self::cfg('vend_auth_code', '') ?? '');
        if ($code === '') {
            // Last leg: accept a still set dot-token w/o expiry if present
            $fallbackAccess = (string)(Config::get('vend.access_token','') ?? '');
            $fallbackExp    = (int)(Config::get('vend.token.expires_at', 0) ?? 0);
            if ($fallbackAccess !== '' && ($fallbackExp === 0 || $fallbackExp > time() + 120)) {
                return $fallbackAccess;
            }
            throw new \RuntimeException('No valid token and missing vend_auth_code');
        }

        $ex = PdoConnection::withAdvisoryLock('ls_oauth_exchange', 10, static function() use ($code) {
            $a3  = (string)(self::cfg('vend_access_token','') ?? '');
            $e3r = self::cfg('vend_token_expires_at', 0);
            $e3  = is_numeric($e3r) ? (int)$e3r : (int)($e3r ?? 0);
            if ($a3 !== '' && ($e3 === 0 || $e3 > time() + 120)) return $a3;
            return self::exchange($code);
        });
        return $ex;
    }

    public static function exchange(string $code): string
    {
        $url = self::tokenEndpoint();
        $cid = (string)(self::cfg('vend_client_id','') ?? '');
        $sec = (string)(self::cfg('vend_client_secret','') ?? '');
        if ($cid === '' || $sec === '') throw new \RuntimeException('Missing client id/secret');

        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $cid,
            'client_secret' => $sec,
        ]);

        $resp = self::curl($url, $body);
        self::persist($resp);
        return (string)$resp['access_token'];
    }

    public static function refresh(string $refresh): string
    {
        $url = self::tokenEndpoint();
        $cid = (string)(self::cfg('vend_client_id','') ?? '');
        $sec = (string)(self::cfg('vend_client_secret','') ?? '');

        $body = http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $cid,
            'client_secret' => $sec,
        ]);

        try {
            $resp = self::curl($url, $body);
            self::persist($resp);
            return (string)$resp['access_token'];
        } catch (\Throwable $e) {
            Logger::warn('token refresh failed', ['meta' => ['err' => $e->getMessage()]]);
            return '';
        }
    }

    /** Persist token fields back to config (underscore + dot bundle). */
    private static function persist(array $d): void
    {
        $access  = (string)($d['access_token']  ?? '');
        $refresh = (string)($d['refresh_token'] ?? (Config::get('vend_refresh_token','') ?? ''));
        $expIn   = (int)   ($d['expires_in']    ?? 3600);
        $expAt   = time() + max(60, $expIn);

        Config::set('vend_access_token',      $access);
        if ($refresh !== '') Config::set('vend_refresh_token', $refresh);
        Config::set('vend_token_expires_at',  $expAt);

        // Also maintain a bundle for legacy readers
        Config::set('vend.token', [
            'access_token' => $access,
            'refresh_token'=> $refresh,
            'expires_at'   => $expAt,
        ]);
    }

    /** x-www-form-urlencoded POST with JSON response. */
    private static function curl(string $url, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => (int)(Config::get('vend.timeout_seconds', 30) ?? 30),
        ]);
        $raw    = curl_exec($ch);
        if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new \RuntimeException($e); }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new \RuntimeException('Invalid token response: ' . substr((string)$raw, 0, 400));
        if ($status < 200 || $status >= 300) throw new \RuntimeException('Token HTTP ' . $status . ' ' . ($data['error'] ?? ''));
        return $data;
    }
}
