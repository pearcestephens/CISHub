<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core;

use RuntimeException;

/**
 * OAuthClient for Lightspeed X-Series
 * - Token endpoint: https://{domain}.retail.lightspeed.app/api/1.0/token
 * - Persists vend_access_token, vend_refresh_token, vend_expires_at
 * @link https://staff.vapeshed.co.nz
 */
final class OAuthClient
{
    public static function tokenEndpoint(): string
    {
        $prefix = (string) cfg('vend_domain_prefix', '');
        if ($prefix === '') {
            throw new RuntimeException('Missing configuration: vend_domain_prefix');
        }
        return sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $prefix);
    }

    public static function ensureValidToken(): string
    {
        $access = (string) (cfg('vend_access_token', '') ?? '');
        $expiresAt = (int) (cfg('vend_expires_at', 0) ?? 0);
        $now = time();
        // Accept permanent tokens (expires_at = 0) or non-expired tokens
        if ($access !== '' && ($expiresAt === 0 || $expiresAt > ($now + 120))) {
            return $access;
        }

        // If we don't have a valid token and no domain prefix is configured,
        // avoid attempting refresh/exchange which requires the token endpoint.
        $prefix = (string) (cfg('vend_domain_prefix', '') ?? '');

        // Try refresh first if present and we have a domain prefix
        $refresh = (string) (cfg('vend_refresh_token', '') ?? '');
        if ($refresh !== '' && $prefix !== '') {
            $ok = self::refresh($refresh);
            if ($ok !== '') {
                return $ok;
            }
        }

        // Fallback to authorization_code flow only if prefix is available
        $authCode = (string) (cfg('vend_auth_code', '') ?? '');
        if ($authCode !== '' && $prefix !== '') {
            return self::exchangeAuthCode($authCode);
        }

        // As a last resort, if we still have an access token value but it appears expired
        // and we cannot refresh/exchange due to missing prefix, return it to allow
        // downstream calls to proceed. Many environments use permanent tokens without expiry.
        if ($access !== '') {
            return $access;
        }

        throw new RuntimeException('No valid vend_access_token and token refresh/exchange unavailable (missing vend_domain_prefix).');
    }

    public static function exchangeAuthCode(string $code): string
    {
        $clientId = (string) cfg('vend_client_id', '');
        $clientSecret = (string) cfg('vend_client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Missing vend_client_id or vend_client_secret');
        }
        $url = self::tokenEndpoint();
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $resp = self::curlJson($url, $body);
        self::persistTokens($resp);
        return (string) $resp['access_token'];
    }

    public static function refresh(string $refreshToken): string
    {
        $clientId = (string) cfg('vend_client_id', '');
        $clientSecret = (string) cfg('vend_client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('Missing vend_client_id or vend_client_secret');
        }
        $url = self::tokenEndpoint();
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        try {
            $resp = self::curlJson($url, $body);
            self::persistTokens($resp);
            return (string) $resp['access_token'];
        } catch (\Throwable $e) {
            Logger::warn('Token refresh failed, will require auth_code exchange', ['meta' => ['err' => $e->getMessage()]]);
            return '';
        }
    }

    /**
     * @param array{access_token:string,refresh_token?:string,expires_in?:int} $resp
     */
    private static function persistTokens(array $resp): void
    {
        $access = (string) ($resp['access_token'] ?? '');
        $refresh = (string) ($resp['refresh_token'] ?? (cfg('vend_refresh_token', '') ?? ''));
        $expiresIn = (int) ($resp['expires_in'] ?? 3600);
        $now = time();
        Config::set('vend_access_token', $access);
        if ($refresh !== '') {
            Config::set('vend_refresh_token', $refresh);
        }
        Config::set('vend_expires_at', $now + $expiresIn);
    }

    /**
     * Perform a x-www-form-urlencoded POST and decode JSON response.
     * @return array<string,mixed>
     */
    private static function curlJson(string $url, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => (int) cfg('vend.timeout_seconds', 30),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid token response: ' . $raw);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Token endpoint HTTP ' . $status . ' ' . ($data['error'] ?? ''));
        }
        return $data;
    }
}
