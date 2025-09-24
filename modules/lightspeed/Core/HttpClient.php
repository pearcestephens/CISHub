<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core;

use RuntimeException;

/**
 * HTTP Client wrapper for Lightspeed APIs with retry/jitter and auto-refresh on 401.
 * @link https://staff.vapeshed.co.nz
 */
final class HttpClient
{
    /**
     * @param string $method
     * @param string $pathOrUrl e.g. "/api/2.0/inventory" or full URL
     * @param array|string|null $body
     * @param array<string,string> $headers
     * @return array{status:int, headers:array<string,string>, body:mixed}
     */
    public static function request(string $method, string $pathOrUrl, $body = null, array $headers = []): array
    {
        $url = self::normalizeUrl($pathOrUrl);
        $attempts = 0;
        $max = (int) cfg('vend.retry_attempts', 3);
        $token = OAuthClient::ensureValidToken();

        retry:
        $attempts++;
        $reqHeaders = array_merge([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ], $headers);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $curlHeaders = [];
        foreach ($reqHeaders as $k => $v) {
            $curlHeaders[] = $k . ': ' . $v;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => (int) cfg('vend.timeout_seconds', 30),
            CURLOPT_HEADER => true,
        ];
        if ($body !== null) {
            if (is_array($body)) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                // Ensure content-type
                $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            } else {
                $opts[CURLOPT_POSTFIELDS] = (string) $body;
            }
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $err);
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, (int)$headerSize);
        $rawBody = substr($raw, (int)$headerSize);
        curl_close($ch);

        $respHeaders = self::parseHeaders($rawHeaders);
        $decoded = json_decode($rawBody, true);
        $bodyOut = is_array($decoded) ? $decoded : $rawBody;

        if ($status === 401 && $attempts <= $max + 1) {
            // One refresh retry on 401
            $new = OAuthClient::refresh((string) cfg('vend_refresh_token', ''));
            if ($new !== '') {
                $token = $new;
                goto retry;
            }
        }

        if (($status === 429 || $status >= 500) && $attempts < $max) {
            $sleepMs = min(250 * $attempts + random_int(0, 250), 1200);
            usleep($sleepMs * 1000);
            goto retry;
        }

        return [
            'status' => (int) $status,
            'headers' => $respHeaders,
            'body' => $bodyOut,
        ];
    }

    private static function normalizeUrl(string $pathOrUrl): string
    {
        if (stripos($pathOrUrl, 'http') === 0) {
            return $pathOrUrl;
        }
        // Default API host for X-Series
        $host = 'https://x-series-api.lightspeedhq.com';
        return rtrim($host, '/') . '/' . ltrim($pathOrUrl, '/');
    }

    /**
     * @return array<string,string>
     */
    private static function parseHeaders(string $raw): array
    {
        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        $out = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $out[trim($k)] = trim($v);
            }
        }
        return $out;
    }
}
