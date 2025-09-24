<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core {
    use PDO;

    /**
     * Config loader backed by `configuration` table with in-memory cache.
     * @link https://staff.vapeshed.co.nz
     */
    final class Config
    {
        /** @var array<string,mixed> */
        private static array $cache = [];

        /**
         * Retrieve configuration value by label.
         * @param string $label
         * @param mixed|null $default
         * @return mixed|null
         */
        public static function get(string $label, $default = null)
        {
            if (array_key_exists($label, self::$cache)) {
                return self::$cache[$label];
            }
            $pdo = DB::connection();
            $stmt = $pdo->prepare('SELECT config_value FROM configuration WHERE config_label = :label LIMIT 1');
            $stmt->execute([':label' => $label]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['config_value'] !== null && $row['config_value'] !== '') {
                $val = self::decodeValue((string)$row['config_value']);
                self::$cache[$label] = $val;
                return $val;
            }
            self::$cache[$label] = $default;
            return $default;
        }

        /**
         * Persist a configuration value.
         */
        public static function set(string $label, $value): void
        {
            $encoded = self::encodeValue($value);
            DB::transaction(static function (PDO $pdo) use ($label, $encoded): void {
                $pdo->prepare(
                    'INSERT INTO configuration (config_label, config_value) VALUES (:l, :v)
                     ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
                )->execute([':l' => $label, ':v' => $encoded]);
            });
            self::$cache[$label] = $value;
        }

        private static function decodeValue(string $value)
        {
            // Try JSON first
            $j = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $j;
            }
            // Fallback to scalar
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            if (is_numeric($value)) {
                return $value + 0; // cast to int/float
            }
            return $value;
        }

        private static function encodeValue($value): string
        {
            if (is_array($value) || is_object($value)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            return (string) $value;
        }
    }
}

// Expose a root-namespace helper for convenience across the codebase
namespace {
    if (!function_exists('cfg')) {
        /**
         * Global helper wrapper for Config::get
         * @param string $label
         * @param mixed|null $default
         * @return mixed|null
         */
        function cfg(string $label, $default = null)
        {
            return \Modules\Lightspeed\Core\Config::get($label, $default);
        }
    }
}
