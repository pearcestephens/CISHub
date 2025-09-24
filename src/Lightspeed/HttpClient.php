<?php
declare(strict_types=1);

namespace Queue\Lightspeed;

use Queue\Config;
use Queue\PdoConnection;
require_once __DIR__ . '/../FeatureFlags.php';

final class HttpClient
{
    /** @return array{status:int,headers:array<string,string>,body:mixed} */
    public static function get(string $path, array $extraHeaders = []): array { return self::req('GET', $path, null, $extraHeaders); }
    /** @param array<string,mixed> $json */
    public static function postJson(string $path, array $json, array $extraHeaders = []): array { return self::req('POST', $path, $json, $extraHeaders); }
    /** @param array<string,mixed> $json */
    public static function putJson(string $path, array $json, array $extraHeaders = []): array { return self::req('PUT', $path, $json, $extraHeaders); }
    /** @param array<string,mixed> $json */
    public static function patchJson(string $path, array $json, array $extraHeaders = []): array { return self::req('PATCH', $path, $json, $extraHeaders); }

    /**
     * @param array<string,mixed>|null $json
     * @return array{status:int,headers:array<string,string>,body:mixed}
     */
    private static function req(string $method, string $path, ?array $json, array $extraHeaders = [])
    {
        // Global HTTP kill switch (before any mocks)
        if (\Queue\FeatureFlags::isDisabled(\Queue\FeatureFlags::httpEnabled())) {
            throw new \RuntimeException('vend_http_disabled');
        }
        // Mock mode for offline/demo testing
        if ((bool) (Config::get('vend.http_mock', false) ?? false)) {
            static $seenKeys = [];
            $status = 200; $headersOut = ['Content-Type' => 'application/json']; $body = [];
            $idk = $extraHeaders['Idempotency-Key'] ?? $extraHeaders['X-Request-Id'] ?? ($json['idempotency_key'] ?? null);
            $isDup = $idk && isset($seenKeys[$idk]);
            if ($idk && !$isDup) { $seenKeys[$idk] = true; }
            $isConsign = preg_match('#/api/2\.0/consignments/?(\d+)?#', $path, $m) === 1;
            $cid = isset($m[1]) ? (string)$m[1] : null;
            if (strtoupper($method) === 'POST' && $isConsign) {
                // Create consignment
                if ($isDup) { $status = 409; }
                $cid = $cid ?: (string) (random_int(100000, 999999));
                $body = [
                    'id' => $cid,
                    'number' => 'C-DEMO-' . substr((string) $cid, -6),
                    'links' => ['self' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/selftest.php']
                ];
                return ['status' => $status, 'headers' => $headersOut, 'body' => $body];
            }
            if (in_array(strtoupper($method), ['PUT','PATCH'], true) && $isConsign) {
                // Update or patch consignment
                $status = 200;
                $resp = ['id' => $cid, 'applied' => array_keys((array)$json)];
                if (isset($json['status'])) { $resp['status'] = $json['status']; }
                if (isset($json['products'])) { $resp['products'] = $json['products']; }
                return ['status' => 200, 'headers' => $headersOut, 'body' => $resp];
            }
            // Default echo
            return ['status' => 200, 'headers' => $headersOut, 'body' => ['ok' => true, 'path' => $path, 'method' => $method, 'echo' => $json]];
        }
        $t0 = microtime(true);
        // Simple circuit breaker: if tripped, fail fast for a cooldown window
        $cb = Config::get('vend.cb', ['tripped' => false, 'until' => 0, 'failures' => 0, 'window_started' => 0]);
        if (is_array($cb) && !empty($cb['tripped']) && time() < (int)$cb['until']) {
            throw new \RuntimeException('vend_circuit_open');
        }
        $base = (string) (Config::get('vend.api_base', 'https://x-series-api.lightspeedhq.com') ?? 'https://x-series-api.lightspeedhq.com');
        $url = (stripos($path, 'http') === 0) ? $path : ($base . '/' . ltrim($path, '/'));
        $attempt = 0; $max = (int) Config::get('vend.retry_attempts', 3);
        $token = OAuthClient::ensureValid();
        retry:
        $attempt++;
        $ch = curl_init($url);
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        foreach ($extraHeaders as $hk => $hv) {
            if ($hk !== '' && $hv !== '') $headers[] = $hk . ': ' . $hv;
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => (int) Config::get('vend.timeout_seconds', 30),
            CURLOPT_HEADER => true,
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new \RuntimeException($e); }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rh = substr($raw, 0, (int)$hsz); $rb = substr($raw, (int)$hsz);
        curl_close($ch);

        $headersOut = [];
        foreach (preg_split('/\r?\n/', trim($rh)) ?: [] as $line) {
            if (strpos($line, ':') !== false) { [$k,$v] = explode(':', $line, 2); $headersOut[trim($k)] = trim($v); }
        }
        $decoded = json_decode($rb, true);
        $body = is_array($decoded) ? $decoded : $rb;

        if ($status === 401 && $attempt <= $max + 1) {
            $new = OAuthClient::refresh((string) (Config::get('vend_refresh_token', '') ?? ''));
            if ($new !== '') { $token = $new; goto retry; }
        }
        $isTransient = ($status === 429 || $status >= 500);
        if ($isTransient && $attempt < $max) {
            $retryAfter = 0;
            foreach (['Retry-After','retry-after','X-RateLimit-Reset'] as $hk) {
                if (isset($headersOut[$hk])) {
                    $v = $headersOut[$hk];
                    if (is_numeric($v)) { $retryAfter = (int)$v; break; }
                }
            }
            if ($retryAfter <= 0) { $retryAfter = min(60 * $attempt, 240); }
            $retryAfter += random_int(0, 2);
            sleep($retryAfter);
            goto retry;
        }
        // Record request metrics (per-minute bucket) and update breaker window
        try {
            $latencyMs = (int) round((microtime(true) - $t0) * 1000);
            $class = ($status >= 200 && $status < 300) ? '2xx'
                : (($status >= 300 && $status < 400) ? '3xx'
                : (($status == 429) ? '429'
                : (($status >= 400 && $status < 500) ? '4xx' : '5xx')));
            self::metricBump('vend_http:requests_total:' . strtoupper($method) . ':' . $class, 1);
            self::metricBump('vend_http:latency_sum_ms:' . strtoupper($method), $latencyMs);
            self::metricBump('vend_http:latency_count:' . strtoupper($method), 1);
            // New: latency buckets (non-cumulative; cumulative computed at read time)
            $buckets = [50,100,200,400,800,1600,3200,10000];
            $le = 'inf';
            foreach ($buckets as $th) { if ($latencyMs <= $th) { $le = (string)$th; break; } }
            self::metricBump('vend_http:latency_bucket_ms:' . strtoupper($method) . ':le:' . $le, 1);

            $cbNow = is_array($cb) ? $cb : ['tripped' => false, 'until' => 0, 'failures' => 0, 'window_started' => 0];
            $window = 120; // seconds
            $threshold = 8; // failures to trip
            $cooldown = 180; // seconds
            $now = time();
            if ($isTransient) {
                if ($cbNow['window_started'] === 0 || ($now - (int)$cbNow['window_started']) > $window) {
                    $cbNow['window_started'] = $now; $cbNow['failures'] = 1;
                } else {
                    $cbNow['failures'] = ((int)$cbNow['failures']) + 1;
                }
                if ((int)$cbNow['failures'] >= $threshold) {
                    $cbNow['tripped'] = true; $cbNow['until'] = $now + $cooldown; $cbNow['failures'] = 0; $cbNow['window_started'] = 0;
                }
                Config::set('vend.cb', $cbNow);
            } else {
                // on success, clear breaker slowly
                if ($cbNow['tripped'] && $now >= (int)$cbNow['until']) { $cbNow['tripped'] = false; }
                $cbNow['failures'] = 0; $cbNow['window_started'] = 0; Config::set('vend.cb', $cbNow);
            }
    } catch (\Throwable $e) { /* best-effort */ }
        // Treat duplicates as success if vendor returns 409 Conflict and body indicates already processed
        if ($status === 409) {
            return ['status' => 200, 'headers' => $headersOut, 'body' => $body];
        }
        // Emit an explicit error log on non-2xx final responses (after retries), with a short body snippet
        if (!($status >= 200 && $status < 300)) {
            try {
                $snippet = '';
                if (is_string($rb) && $rb !== '') { $snippet = substr($rb, 0, 500); }
                elseif (is_array($body)) { $snippet = substr(json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), 0, 500); }
                \Queue\Logger::error('vend.http.error', ['meta' => [
                    'method' => strtoupper($method),
                    'path' => $path,
                    'status' => $status,
                    'retry_attempt' => $attempt,
                    'x_rate_limit_remaining' => $headersOut['X-RateLimit-Remaining'] ?? null,
                    'retry_after' => $headersOut['Retry-After'] ?? null,
                    'body_snippet' => $snippet,
                ]]);
            } catch (\Throwable $e) { /* swallow logging errors */ }
        }
        return ['status' => (int)$status, 'headers' => $headersOut, 'body' => $body];
    }

    /** Best-effort metric bump using ls_rate_limits as a generic per-minute counter store. */
    private static function metricBump(string $key, int $inc): void
    {
        try {
            $pdo = PdoConnection::instance();
            $window = date('Y-m-d H:i:00');
            $stmt = $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,:c,NOW()) ON DUPLICATE KEY UPDATE counter = IF(window_start=:w, counter + :c, :c), window_start = IF(window_start=:w, window_start, :w), updated_at=NOW()');
            $stmt->execute([':k' => $key, ':w' => $window, ':c' => $inc]);
        } catch (\Throwable $e) {
            // swallow
        }
    }

    /**
     * Generic pagination helper. Tries token-based (after/page_info) and page-number styles.
     * @param string $path Base path e.g. '/api/2.1/products'
     * @param array<string,mixed> $query Initial query params
     * @param callable(array $items, array $pageMeta):void $onPage Called per page; throw to abort
     */
    public static function paginate(string $path, array $query, callable $onPage): void
    {
        $maxPages = 1000; // guard
        $page = isset($query['page']) ? (int)$query['page'] : 1;
        $after = isset($query['after']) ? (string)$query['after'] : (string)($query['page_info'] ?? '');

        for ($i = 0; $i < $maxPages; $i++) {
            $qs = http_build_query(array_filter([
                'page' => $after === '' ? $page : null,
                'after' => $after !== '' ? $after : null,
                'page_info' => $after !== '' ? $after : null,
            ]) + $query);
            $p = strpos($path, '?') === false ? $path . '?' . $qs : $path . '&' . $qs;
            $resp = self::get($p);
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                throw new \RuntimeException('HTTP ' . $resp['status']);
            }
            $body = $resp['body'];
            $items = [];
            $meta = ['next' => null, 'page' => $page];
            if (is_array($body)) {
                if (isset($body['data']) && is_array($body['data'])) { $items = $body['data']; }
                elseif (isset($body['items']) && is_array($body['items'])) { $items = $body['items']; }
                else { $items = array_values(array_filter($body, 'is_array')); }

                // Try to find next token
                if (isset($body['links']['next'])) { $meta['next'] = (string)$body['links']['next']; }
                elseif (isset($body['meta']['next'])) { $meta['next'] = (string)$body['meta']['next']; }
                elseif (isset($body['page_info'])) { $meta['next'] = (string)$body['page_info']; }
            }

            $onPage(is_array($items) ? $items : [], $meta);

            if (!empty($meta['next'])) { $after = (string)$meta['next']; continue; }
            if ($after !== '') { // token ended
                break;
            }
            if (!is_array($items) || count($items) === 0) break;
            $page++;
        }
    }
}
