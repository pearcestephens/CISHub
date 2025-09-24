<?php
declare(strict_types=1);

namespace Queue;

/**
 * JSON stderr logger with request_id/job_id/type and ms timings. Redacts secrets.
 * @link https://staff.vapeshed.co.nz
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $redact = static function ($k, $v) {
            $sensitive = ['access_token','refresh_token','authorization','password','secret'];
            foreach ($sensitive as $needle) {
                if (stripos((string)$k, $needle) !== false) return '***';
            }
            return $v;
        };
        $meta = $context['meta'] ?? [];
        foreach ($meta as $k => $v) $meta[$k] = $redact($k, $v);
        $record = [
            'ts' => date('c'),
            'level' => $level,
            'request_id' => $context['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
            'job_id' => $context['job_id'] ?? null,
            'type' => $context['type'] ?? null,
            'message' => $message,
            'meta' => $meta,
        ];
        fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
    }
    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function warn(string $m, array $c = []): void { self::log('warn', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
}
