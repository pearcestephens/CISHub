<?php
declare(strict_types=1);

namespace Queue;

final class Http
{
    public static function requestId(): string
    {
        static $rid = null;
        if ($rid !== null) return $rid;
        try { $rid = bin2hex(random_bytes(16)); } catch (\Throwable $e) { $rid = substr(bin2hex(uniqid('', true)), 0, 32); }
        header('X-Request-ID: ' . $rid);
        return $rid;
    }

    public static function commonJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        self::requestId();
        // Attach global health/degrade signal headers for all JSON endpoints
        try {
            // System banner (if Degrade exists and active)
            if (class_exists('\\Queue\\Degrade')) {
                $b = \Queue\Degrade::banner();
                $active = (bool)($b['active'] ?? false);
                header('X-CIS-Banner-Active: ' . ($active ? '1' : '0'));
                if (!empty($b['level'])) { header('X-CIS-Banner-Level: ' . (string)$b['level']); }
                if (!empty($b['message'])) {
                    $msg = (string)$b['message'];
                    $msg = str_replace(["\r","\n"], ' ', $msg);
                    if (strlen($msg) > 512) { $msg = substr($msg, 0, 512); }
                    header('X-CIS-Banner-Message: ' . $msg);
                }
            }
            // Core degrade flags
            if (class_exists('\\Queue\\Config')) {
                $ro = \Queue\Config::getBool('ui.readonly', false);
                header('X-CIS-Readonly: ' . ($ro ? '1' : '0'));
                $qq = \Queue\Config::getBool('ui.disable.quick_qty', false);
                header('X-CIS-Feature-QuickQty-Disabled: ' . ($qq ? '1' : '0'));
            }
        } catch (\Throwable $e) { /* non-fatal */ }
    }

    public static function commonTextHeaders(): void
    {
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        self::requestId();
        // Mirror degrade status for text endpoints too
        try {
            if (class_exists('\\Queue\\Degrade')) {
                $b = \Queue\Degrade::banner();
                $active = (bool)($b['active'] ?? false);
                header('X-CIS-Banner-Active: ' . ($active ? '1' : '0'));
                if (!empty($b['level'])) { header('X-CIS-Banner-Level: ' . (string)$b['level']); }
                if (!empty($b['message'])) {
                    $msg = (string)$b['message'];
                    $msg = str_replace(["\r","\n"], ' ', $msg);
                    if (strlen($msg) > 512) { $msg = substr($msg, 0, 512); }
                    header('X-CIS-Banner-Message: ' . $msg);
                }
            }
            if (class_exists('\\Queue\\Config')) {
                $ro = \Queue\Config::getBool('ui.readonly', false);
                header('X-CIS-Readonly: ' . ($ro ? '1' : '0'));
                $qq = \Queue\Config::getBool('ui.disable.quick_qty', false);
                header('X-CIS-Feature-QuickQty-Disabled: ' . ($qq ? '1' : '0'));
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    public static function respond(bool $ok, ?array $data = null, ?array $error = null, int $status = 200): void
    {
        self::commonJsonHeaders();
        http_response_code($status);
        // Attach system/development warnings (non-breaking)
        $sysName = null; $dev = [];
        try { $sysName = (string)(Config::get('system.name', 'CISHUB') ?? 'CISHUB'); } catch (\Throwable $e) {}
        try { if (class_exists('\\Queue\\DevFlags')) { $dev = \Queue\DevFlags::active(); } } catch (\Throwable $e) {}
        echo json_encode([
            'ok' => $ok,
            'data' => $ok ? ($data ?? []) : null,
            'error' => $ok ? null : ($error ?? ['code' => 'unknown_error', 'message' => 'Unknown error']),
            'request_id' => self::requestId(),
            'system' => $sysName ? ['name' => $sysName] : null,
            'dev_flags' => $dev,
        ]);
    }

    public static function error(string $code, string $message, ?array $details = null, int $status = 400): void
    {
        self::respond(false, null, ['code' => $code, 'message' => $message, 'details' => $details], $status);
    }

    public static function ensurePost(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::error('method_not_allowed', 'POST required', null, 405);
            return false;
        }
        return true;
    }

    public static function ensureAuth(): bool
    {
        // INCIDENT MODE: HTTP auth fully disabled as requested. All requests are allowed.
        // Note: This bypasses bearer checks entirely, including for write endpoints.
        // Ensure this is reverted when incident ends.
        return true;
    }

    public static function rateLimit(string $route, int $limitPerMinute = 60): bool
    {
        // Allow CLI scripts to bypass rate limiting (safe for internal batch jobs)
        if (PHP_SAPI === 'cli') {
            return true;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $key = 'rl:' . $route . ':' . $ip;
        $bucket = date('Y-m-d H:i:00');
        try {
            $pdo = PdoConnection::instance();
            $pdo->prepare('INSERT INTO ls_rate_limits (rl_key, window_start, counter, updated_at) VALUES (:k,:w,1,NOW()) ON DUPLICATE KEY UPDATE counter = IF(window_start=:w, counter+1, 1), window_start = IF(window_start=:w, window_start, :w), updated_at=NOW()')
                ->execute([':k' => $key, ':w' => $bucket]);
            $row = $pdo->prepare('SELECT counter, window_start FROM ls_rate_limits WHERE rl_key=:k');
            $row->execute([':k' => $key]);
            $r = $row->fetch(\PDO::FETCH_ASSOC) ?: ['counter' => 0, 'window_start' => $bucket];
            $count = (int)($r['counter'] ?? 0);
            if ($count > $limitPerMinute) {
                $retry = 60 - (int) (time() % 60);
                header('Retry-After: ' . $retry);
                self::error('rate_limited', 'Too many requests', ['limit_per_min' => $limitPerMinute], 429);
                return false;
            }
        } catch (\Throwable $e) {
            // Fail-open on RL errors
        }
        return true;
    }
}
