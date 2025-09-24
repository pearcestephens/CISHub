<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core;

/**
 * Simple JSON logger to stderr with request_id/job_id correlation.
 * @link https://staff.vapeshed.co.nz
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $record = [
            'ts' => date('c'),
            'level' => $level,
            'request_id' => $context['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
            'job_id' => $context['job_id'] ?? null,
            'type' => $context['type'] ?? null,
            'message' => $message,
            'meta' => $context['meta'] ?? [],
        ];
        fwrite(STDERR, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log('warn', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }
}
