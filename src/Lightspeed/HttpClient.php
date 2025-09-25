<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Config;
use Queue\PdoConnection;
use Queue\Lightspeed\OAuthClient;

/**
 * HttpClient (Lightspeed Retail X-Series)
 *
 * Public contract (unchanged):
 *   - get(string $path, array $extraHeaders = []): array
 *   - postJson(string $path, array $json, array $extraHeaders = []): array
 *   - putJson(string $path, array $json, array $extraHeaders = []): array
 *   - patchJson(string $path, array $json, array $extraHeaders = []): array
 *   - paginate(string $path, array $query, callable $onPage): void
 *
 * Response shape (unchanged):
 *   ['status'=>int, 'headers'=>array, 'body'=>array|string]
 *
 * Improvements:
 *   - Unified retry policy with jitter and Retry-After support
 *   - Circuit-breaker with decay (vend.cb: tripped/until/failures/window_started)
 *   - Stable metrics via ls_rate_limits buckets
 *   - Mock mode returns deterministic success with idempotency echoes
 */
final class HttpClient
{
    public static function get(string $path, array $extraHeaders = []): array  { return self::req('GET',   $path, null, $extraHeaders); }
    public static function postJson(string $path, array $json, array $extraHeaders = []): array { return self::req('POST',  $path, $json, $extraHeaders); }
    public static function putJson(string $path, array $json, array $extraHeaders = []): array  { return self::req('PUT',   $path, $json, $extraHeaders); }
    public static function patchJson(string $path, array $json, array $extraHeaders = []): array{ return self::req('PATCH', $path, $json, $extraHeaders); }

    private static function vendorBase(): string
    {
        // Prefer explicit API base if configured (should be the origin without trailing API path)
        $base = (string)(Config::get('vend.api_base', '') ?? '');
        if ($base !== '') return rtrim($base, '/');

        // Environment fallbacks (no DB change needed)
        $env = getenv('VEND_API_BASE') ?: getenv('LS_API_BASE') ?: '';
        if (is_string($env) && $env !== '') return rtrim($env, '/');

        // If vend_host provided, normalize to origin
        $host = (string)((Config::get('vend_host', '') ?? '') ?: (getenv('VEND_HOST') ?: getenv('LS_HOST') ?: getenv('LIGHTSPEED_HOST')));
        if ($host !== '') {
            if (stripos($host, 'http') === 0) { $host = parse_url($host, PHP_URL_HOST) ?: $host; }
            return 'https://' . rtrim((string)$host, '/');
        }

        // Fallback to vend_domain_prefix → {prefix}.retail.lightspeed.app
        $prefix = (string)(Config::get('vend_domain_prefix', '') ?? (getenv('VEND_DOMAIN_PREFIX') ?: getenv('LS_DOMAIN_PREFIX') ?: ''));
        if ($prefix !== '') {
            return 'https://' . $prefix . '.retail.lightspeed.app';
        }

        // Default legacy API base (should rarely be used for Retail X tenant-scoped endpoints)
        return 'https://x-series-api.lightspeedhq.com';
    }

    /** Core request with retry, CB, metrics, and mock. */
    private static function req(string $method, string $path, ?array $json, array $extraHeaders)
    {
        if (\Queue\FeatureFlags::isDisabled(\Queue\FeatureFlags::httpEnabled())) {
            throw new \RuntimeException('vend_http_disabled');
        }

        // Mock path — returns synthetic success responses; respects idempotency headers.
        if ((bool)(Config::get('vend.http_mock', false) ?? false)) {
            static $seen = [];
            $idk = $extraHeaders['Idempotency-Key'] ?? $extraHeaders['X-Request-Id'] ?? ($json['idempotency_key'] ?? null);
            $dup = $idk && isset($seen[$idk]);
            if ($idk && !$dup) $seen[$idk] = true;

            $status = $dup ? 409 : 200;
            $body   = ['ok'=>true, 'mock'=>true, 'method'=>strtoupper($method), 'path'=>$path, 'echo'=>$json, 'dup'=>$dup];
            // Treat 409 as success (idempotent repeat)
            return ['status'=>200, 'headers'=>['Content-Type'=>'application/json'], 'body'=>$body];
        }

        // Normalize known misplaced API endpoints (e.g., 2.1 -> 2.0 registers) before building URL
        $originalPath = $path;
        $path = self::normalizeEndpointPath($path, $didRewrite);
        if (!empty($didRewrite)) {
            try { \Queue\Logger::info('vend.http.rewrite', ['meta' => ['from' => $originalPath, 'to' => $path]]); } catch (\Throwable $e) { /* best-effort */ }
        }

        $url = (stripos($path, 'http') === 0) ? $path : (rtrim(self::vendorBase(), '/') . '/' . ltrim($path, '/'));

        // Circuit breaker
        $cb = Config::get('vend.cb', ['tripped'=>false,'until'=>0,'failures'=>0,'window_started'=>0]);
        $cb = is_array($cb) ? $cb : ['tripped'=>false,'until'=>0,'failures'=>0,'window_started'=>0];
        $now = time();
        if (!empty($cb['tripped']) && $now < (int)($cb['until'] ?? 0)) {
            throw new \RuntimeException('vend_circuit_open');
        }

        $attempt   = 0;
        $max       = (int)(Config::get('vend.retry_attempts', 3) ?? 3);
        $timeout   = (int)(Config::get('vend.timeout_seconds', 30) ?? 30);
        $token     = OAuthClient::ensureValid();
        $t0        = microtime(true);

        retry:
        $attempt++;

        $hdr = array_merge([
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ], $extraHeaders);

        $curlHeaders = [];
        foreach ($hdr as $k => $v) if ($k !== '' && $v !== '') $curlHeaders[] = $k . ': ' . $v;

        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('curl_init failed');

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HEADER         => true,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            // Transport-level error: capture errno and error string for observability
            $errno = curl_errno($ch);
            $errstr = curl_error($ch);
            try { \Queue\Logger::error('vend.http.transport', ['meta' => [
                'errno' => $errno,
                'error' => $errstr,
                'url' => $url,
                'method' => strtoupper($method),
            ]]); } catch (\Throwable $logE) { /* best-effort */ }
            curl_close($ch);
            throw new \RuntimeException($errstr !== '' ? $errstr : ('curl_error #' . $errno));
        }

    $status     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHead    = substr($raw, 0, $headerSize);
        $rawBody    = substr($raw, $headerSize);
        curl_close($ch);

        $respHeaders = self::parseHeaders($rawHead);
        $decoded     = json_decode($rawBody, true);
        $body        = is_array($decoded) ? $decoded : $rawBody;

        // Non-2xx immediate log (with headers) to surface 401/429/timeouts etc.
        if ($status < 200 || $status >= 300) {
            try {
                \Queue\Logger::error('vend.http.error', ['meta' => [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'status' => $status,
                    'retry_attempt' => $attempt,
                    'x_rate_limit_remaining' => $respHeaders['X-RateLimit-Remaining'] ?? ($respHeaders['x-ratelimit-remaining'] ?? null),
                    'retry_after' => $respHeaders['Retry-After'] ?? ($respHeaders['retry-after'] ?? null),
                    'x_request_id' => $respHeaders['X-Request-ID'] ?? ($respHeaders['X-Correlation-ID'] ?? ($respHeaders['x-request-id'] ?? ($respHeaders['x-correlation-id'] ?? null))),
                ]]);
            } catch (\Throwable $logE) { /* best-effort */ }
        }

        // 404 fallback: if we hit the known bad 2.1 registers path and rewriting is enabled, retry with 2.0 once
        if ($status === 404 && empty($didRewrite)) {
            $enable = (bool)(Config::get('vend.http_rewrite.fix_registers_21_to_20', true) ?? true);
            if ($enable && preg_match('#/api/2\.1/(registers)(?:/|\?|$)#', $url)) {
                $newUrl = preg_replace('#/api/2\.1/#', '/api/2.0/', $url, 1);
                if (is_string($newUrl) && $newUrl !== $url) {
                    try { \Queue\Logger::info('vend.http.rewrite.retry', ['meta' => ['from' => $url, 'to' => $newUrl]]); } catch (\Throwable $e) {}
                    $url = $newUrl;
                    $didRewrite = true; // guard against loops
                    goto retry;
                }
            }
        }

        // 401: refresh token once then retry
        if ($status === 401 && $attempt <= $max + 1) {
            $new = OAuthClient::refresh((string)(Config::get('vend_refresh_token', '') ?? ''));
            if ($new !== '') { $token = $new; goto retry; }
        }

        // Transient?
        $transient = ($status === 429 || $status >= 500);
        if ($transient && $attempt < $max) {
            $retryAfter = 0;
            foreach (['Retry-After','retry-after','X-RateLimit-Reset'] as $k) {
                if (isset($respHeaders[$k]) && is_numeric($respHeaders[$k])) { $retryAfter = (int)$respHeaders[$k]; break; }
            }
            if ($retryAfter <= 0) {
                // simple expo backoff with jitter
                $retryAfter = min(60 * $attempt, 240);
            }
            $retryAfter += random_int(0, 2);
            sleep($retryAfter);
            goto retry;
        }

        // Metrics + CB bookkeeping
        self::recordMetrics($method, $status, (int)round((microtime(true) - $t0) * 1000));

        // CB update
        try {
            $cbNow = Config::get('vend.cb', ['tripped'=>false,'until'=>0,'failures'=>0,'window_started'=>0]);
            $cbNow = is_array($cbNow) ? $cbNow : ['tripped'=>false,'until'=>0,'failures'=>0,'window_started'=>0];

            $isTransient = ($status === 429 || $status >= 500);
            $window  = 120;
            $thresh  = 8;
            $cool    = 180;

            if ($isTransient) {
                if (empty($cbNow['window_started']) || ($now - (int)$cbNow['window_started']) > $window) {
                    $cbNow['window_started'] = $now;
                    $cbNow['failures'] = 1;
                } else {
                    $cbNow['failures'] = ((int)$cbNow['failures']) + 1;
                }
                if ((int)$cbNow['failures'] >= $thresh) {
                    $cbNow['tripped'] = true;
                    $cbNow['until']   = $now + $cool;
                    $cbNow['failures']= 0;
                    $cbNow['window_started'] = 0;
                }
            } else {
                // decay / close breaker
                $cbNow['tripped'] = (!empty($cbNow['tripped']) && $now < (int)($cbNow['until'] ?? 0)) ? $cbNow['tripped'] : false;
                $cbNow['failures']= 0;
                $cbNow['window_started'] = 0;
            }
            Config::set('vend.cb', $cbNow);
        } catch (\Throwable $e) {}

        // Treat 409 idempotent duplicates as success (common LS behavior)
        if ($status === 409) return ['status'=>200,'headers'=>$respHeaders,'body'=>$body];

        return ['status'=>$status, 'headers'=>$respHeaders, 'body'=>$body];
    }

    /**
     * Rewrites known incorrect API versions to their correct counterparts.
     * Currently: ensures registers endpoint uses /api/2.0/ to avoid 404 churn from /api/2.1/.
     *
     * Accepts either a path (e.g., "/api/2.1/registers") or a full URL.
     * Returns the rewritten string. Sets $didRewrite=true if a change occurred.
     */
    private static function normalizeEndpointPath(string $input, ?bool &$didRewrite = null): string
    {
        $didRewrite = false;
        // Targeted fix: registers endpoint exists under 2.0, not 2.1 for our tenant usage
        // Apply replacement for any occurrence, preserving query/fragment automatically with string replacement.
        // Only replace exact segment "/api/2.1/registers" (optionally followed by /, ?, or end of string)
        $enable = (bool)(Config::get('vend.http_rewrite.fix_registers_21_to_20', true) ?? true);
        if ($enable) {
            $pattern = '#/api/2\.1/(registers)(?=\b|/|\?|$)#';
            if (preg_match($pattern, $input)) {
                $output = preg_replace('#/api/2\.1/#', '/api/2.0/', $input, 1);
                if (is_string($output) && $output !== $input) {
                    $didRewrite = true;
                    return $output;
                }
            }
        }
        return $input;
    }

    private static function parseHeaders(string $raw): array
    {
        $lines = preg_split('/\r?\n/', trim($raw)) ?: [];
        $out   = [];
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $out[trim($k)] = trim($v);
            }
        }
        return $out;
    }

    private static function recordMetrics(string $method, int $status, int $latencyMs): void
    {
        try {
            $m = strtoupper($method);
            $class = ($status >= 200 && $status < 300) ? '2xx' :
                     (($status >= 300 && $status < 400) ? '3xx' :
                     (($status == 429) ? '429' :
                     (($status >= 400 && $status < 500) ? '4xx' : '5xx')));

            self::bump('vend_http:requests_total:' . $m . ':' . $class, 1);
            self::bump('vend_http:latency_sum_ms:' . $m, $latencyMs);
            self::bump('vend_http:latency_count:' . $m, 1);

            // bucket distribution
            foreach ([50,100,200,400,800,1600,3200,10000] as $th) {
                if ($latencyMs <= $th) {
                    self::bump('vend_http:latency_bucket_ms:' . $m . ':le:' . $th, 1);
                    return;
                }
            }
            self::bump('vend_http:latency_bucket_ms:' . $m . ':le:inf', 1);
        } catch (\Throwable $e) {}
    }

    private static function bump(string $key, int $inc): void
    {
        try {
            $pdo    = PdoConnection::instance();
            $window = date('Y-m-d H:i:00');
            $stmt = $pdo->prepare(
                'INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at)
                 VALUES (:k, :w, :c, NOW())
                 ON DUPLICATE KEY UPDATE
                   counter = IF(window_start=:w, counter + :c, :c),
                   window_start = IF(window_start=:w, window_start, :w),
                   updated_at = NOW()'
            );
            $stmt->execute([':k'=>$key, ':w'=>$window, ':c'=>$inc]);
        } catch (\Throwable $e) {}
    }

    /**
     * Paginates vendor endpoints, calling $onPage($items, $meta) per page.
     * Tries common structures: {data:[...]}, {items:[...]}, or array lists.
     * $meta contains 'next' and 'page'.
     */
    public static function paginate(string $path, array $query, callable $onPage): void
    {
        $maxPages = 1000;
        $page  = isset($query['page']) ? (int)$query['page'] : 1;
        $after = isset($query['after']) ? (string)$query['after'] : (string)($query['page_info'] ?? '');

        for ($i=0; $i<$maxPages; $i++) {
            $qs = http_build_query(array_filter([
                'page'      => $after === '' ? $page : null,
                'after'     => $after !== '' ? $after : null,
                'page_info' => $after !== '' ? $after : null,
            ]) + $query);

            $p = (strpos($path, '?') === false) ? ($path . '?' . $qs) : ($path . '&' . $qs);
            $resp = self::get($p);
            if (($resp['status'] ?? 0) < 200 || ($resp['status'] ?? 0) >= 300) {
                throw new \RuntimeException('HTTP ' . ($resp['status'] ?? 0));
            }

            $body  = $resp['body'];
            $items = [];
            $meta  = ['next'=>null, 'page'=>$page];

            if (is_array($body)) {
                if (isset($body['data'])   && is_array($body['data']))   $items = $body['data'];
                elseif (isset($body['items']) && is_array($body['items'])) $items = $body['items'];
                else $items = array_values(array_filter($body, 'is_array'));

                if (isset($body['links']['next']))     $meta['next'] = (string)$body['links']['next'];
                elseif (isset($body['meta']['next']))  $meta['next'] = (string)$body['meta']['next'];
                elseif (isset($body['page_info']))     $meta['next'] = (string)$body['page_info'];
            }

            $onPage(is_array($items) ? $items : [], $meta);

            if (!empty($meta['next'])) { $after = (string)$meta['next']; continue; }
            if ($after !== '') break; // reached the end for "after" style

            if (!is_array($items) || count($items) === 0) break;
            $page++;
        }
    }
}
