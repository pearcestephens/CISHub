<?php
declare(strict_types=1);

namespace Queue;

final class ConfigFacade
{
    /** Change if you prefer a different namespace label */
    private const NS = 'system.queue.lightspeed';

    /** return [found(bool), value(mixed)] */
    private static function v2GetRaw(string $key): array
    {
        // Try to include cis-config if deployed
        $paths = [
            $_SERVER['DOCUMENT_ROOT'] . '/cis-config/ConfigV2.php',
            dirname(__DIR__, 3) . '/cis-config/ConfigV2.php',
        ];
        foreach ($paths as $p) {
            if (is_string($p) && is_file($p)) {
                require_once $p;
                break;
            }
        }
        if (!class_exists('\\ConfigV2')) return [false, null];
        try {
            // ConfigV2::get(string $namespace, string $key, $default=null)
            $val = \ConfigV2::get(self::NS, $key, null);
            if ($val === null || $val === '') return [false, null];
            return [true, $val];
        } catch (\Throwable $e) { return [false, null]; }
    }

    public static function get(string $key, $default = null)
    {
        [$found, $val] = self::v2GetRaw($key);
        if ($found) return $val;
        // Fallback to legacy store
        try { return \Queue\Config::get($key, $default); }
        catch (\Throwable $e) { return $default; }
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) return $v;
        if (is_string($v)) {
            $l = strtolower($v);
            if (in_array($l, ['true','1','yes','on'], true)) return true;
            if (in_array($l, ['false','0','no','off'], true)) return false;
        }
        return (bool)$v;
    }

    public static function set(string $key, $value): void
    {
        // If ConfigV2 exists, write there; otherwise, write to legacy.
        if (class_exists('\\ConfigV2')) {
            try { \ConfigV2::set(self::NS, $key, $value); return; } catch (\Throwable $e) {}
        }
        try { \Queue\Config::set($key, $value); } catch (\Throwable $e) {}
    }
}
