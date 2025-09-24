<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Config;
use Queue\Logger;
use Queue\PdoConnection;

final class OAuthClient
{
    private static function tokenEndpoint(): string
    {
        $prefix = (string) Config::get('vend_domain_prefix', '');
        if ($prefix === '') throw new \RuntimeException('Missing vend_domain_prefix');
        return sprintf('https://%s.retail.lightspeed.app/api/1.0/token', $prefix);
    }

    public static function ensureValid(): string
    {
        $access = (string) (Config::get('vend_access_token', '') ?? '');
        $exp = (int) (Config::get('vend_token_expires_at', 0) ?? 0);
        if ($access !== '' && $exp > time() + 120) return $access;
        $refresh = (string) (Config::get('vend_refresh_token', '') ?? '');
        if ($refresh !== '') {
            $n = PdoConnection::withAdvisoryLock('ls_oauth_refresh', 10, static function () use ($refresh) {
                // Double-check inside lock
                $access2 = (string) (Config::get('vend_access_token', '') ?? '');
                $exp2 = (int) (Config::get('vend_token_expires_at', 0) ?? 0);
                if ($access2 !== '' && $exp2 > time() + 120) return $access2;
                return self::refresh($refresh);
            });
            if ($n !== '') return $n;
        }
        $code = (string) (Config::get('vend_auth_code', '') ?? '');
        if ($code === '') throw new \RuntimeException('No valid token and missing vend_auth_code');
        return PdoConnection::withAdvisoryLock('ls_oauth_exchange', 10, static function () use ($code) {
            // Another double-check before exchanging
            $access3 = (string) (Config::get('vend_access_token', '') ?? '');
            $exp3 = (int) (Config::get('vend_token_expires_at', 0) ?? 0);
            if ($access3 !== '' && $exp3 > time() + 120) return $access3;
            return self::exchange($code);
        });
    }

    public static function exchange(string $code): string
    {
        $url = self::tokenEndpoint();
        $clientId = (string) Config::get('vend_client_id', '');
        $clientSecret = (string) Config::get('vend_client_secret', '');
        if ($clientId === '' || $clientSecret === '') throw new \RuntimeException('Missing client id/secret');
        $body = http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code,
            'client_id' => $clientId, 'client_secret' => $clientSecret,
        ]);
        $resp = self::curl($url, $body);
        self::persist($resp);
        return (string) $resp['access_token'];
    }

    public static function refresh(string $refresh): string
    {
        $url = self::tokenEndpoint();
        $clientId = (string) Config::get('vend_client_id', '');
        $clientSecret = (string) Config::get('vend_client_secret', '');
        $body = http_build_query([
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh,
            'client_id' => $clientId, 'client_secret' => $clientSecret,
        ]);
        try {
            $resp = self::curl($url, $body);
            self::persist($resp);
            return (string) $resp['access_token'];
        } catch (\Throwable $e) {
            Logger::warn('token refresh failed', ['meta' => ['err' => $e->getMessage()]]);
            return '';
        }
    }

    /** @param array{access_token:string,refresh_token?:string,expires_in?:int} $d */
    private static function persist(array $d): void
    {
        $access = (string) ($d['access_token'] ?? '');
        $refresh = (string) ($d['refresh_token'] ?? (Config::get('vend_refresh_token', '') ?? ''));
        $expIn = (int) ($d['expires_in'] ?? 3600);
        Config::set('vend_access_token', $access);
        if ($refresh !== '') Config::set('vend_refresh_token', $refresh);
        Config::set('vend_token_expires_at', time() + $expIn);
    }

    /** @return array<string,mixed> */
    private static function curl(string $url, string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT => (int) Config::get('vend.timeout_seconds', 30),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new \RuntimeException($e); }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('Invalid token response: ' . $raw);
        if ($status < 200 || $status >= 300) throw new \RuntimeException('Token HTTP ' . $status . ' ' . ($data['error'] ?? ''));
        return $data;
    }
}
