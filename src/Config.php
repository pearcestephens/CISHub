<?php
declare(strict_types=1);

namespace Queue;

use PDO;

/**
 * Config loader backed by CIS `configuration` table, with in-memory cache.
 * @link https://staff.vapeshed.co.nz
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $cache = [];

    public static function get(string $label, $default = null)
    {
        if (array_key_exists($label, self::$cache)) {
            return self::$cache[$label];
        }
        try {
            $pdo = PdoConnection::instance();
            $stmt = $pdo->prepare('SELECT config_value FROM configuration WHERE config_label = :l LIMIT 1');
            $stmt->execute([':l' => $label]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['config_value'] !== null && $row['config_value'] !== '') {
                $val = self::decode((string)$row['config_value']);
                self::$cache[$label] = $val;
                return $val;
            }
        } catch (\Throwable $e) {
            // DB not available or credentials missing: fall back to default without throwing
        }
        self::$cache[$label] = $default;
        return $default;
    }

    public static function getBool(string $label, bool $default = false): bool
    {
        $v = self::get($label, $default);
        if (is_bool($v)) return $v;
        if (is_string($v)) {
            $l = strtolower($v);
            if ($l === 'true' || $l === '1' || $l === 'yes' || $l === 'on') return true;
            if ($l === 'false' || $l === '0' || $l === 'no' || $l === 'off') return false;
        }
        return (bool)$v;
    }

    public static function set(string $label, $value): void
    {
        $encoded = self::encode($value);
        $pdo = PdoConnection::instance();
        $pdo->prepare(
            'INSERT INTO configuration (config_label, config_value) VALUES (:l,:v)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute([':l' => $label, ':v' => $encoded]);
        self::$cache[$label] = $value;
    }

    private static function decode(string $raw)
    {
        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $j;
        if ($raw === 'true') return true;
        if ($raw === 'false') return false;
        if (is_numeric($raw)) return $raw + 0;
        return $raw;
    }
    private static function encode($v): string
    {
        if (is_array($v) || is_object($v)) return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_bool($v)) return $v ? 'true' : 'false';
        return (string)$v;
    }
}
