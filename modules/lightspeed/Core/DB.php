<?php
declare(strict_types=1);

namespace Modules\Lightspeed\Core;

use PDO;
use PDOException;
use Throwable;

/**
 * DB: Persistent PDO connection and transaction helper.
 * @link https://staff.vapeshed.co.nz
 */
final class DB
{
    private static ?PDO $pdo = null;
    private static bool $envLoaded = false;

    /**
     * Obtain a singleton persistent PDO connection.
     */
    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // Best-effort .env loader so credentials can be provided without code edits
        if (!self::$envLoaded) {
            self::$envLoaded = true;
            try {
                $candidates = [];
                $doc = $_SERVER['DOCUMENT_ROOT'] ?? null;
                if (is_string($doc) && $doc !== '') { $candidates[] = rtrim($doc, '/\\') . '/.env'; }
                $maybeRoot = dirname(__DIR__, 5); // .../public_html
                if (is_string($maybeRoot) && $maybeRoot !== '' && is_dir($maybeRoot)) { $candidates[] = $maybeRoot . '/.env'; }
                $candidates[] = dirname(__DIR__, 3) . '/.env'; // queue root
                foreach (array_unique($candidates) as $envPath) {
                    if (@is_file($envPath) && @is_readable($envPath)) {
                        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                        foreach ($lines as $line) {
                            if ($line === '' || $line[0] === '#') continue;
                            if (strpos($line, '=') === false) continue;
                            [$k, $v] = array_map('trim', explode('=', $line, 2));
                            if ($k === '') continue;
                            if (!getenv($k)) { @putenv($k . '=' . $v); $_ENV[$k] = $v; }
                        }
                        break;
                    }
                }
            } catch (\Throwable $e) { /* ignore */ }
        }

        // Resolve credentials from constants or environment only (no repo secrets)
    $host = \defined('DB_HOST') ? (string) DB_HOST : ((string) getenv('DB_HOST') ?: '127.0.0.1');
    $port = \defined('DB_PORT') ? (string) DB_PORT : ((string) getenv('DB_PORT') ?: '3306');
    $db   = \defined('DB_NAME') ? (string) DB_NAME : ((string) getenv('DB_NAME') ?: '');
    $user = \defined('DB_USER') ? (string) DB_USER : ((string) getenv('DB_USER') ?: '');
    $pass = \defined('DB_PASS') ? (string) DB_PASS : ((string) getenv('DB_PASS') ?: '');

        if ($db === '' || $user === '') {
            throw new \RuntimeException('Database credentials not set. Define DB_NAME/DB_USER (and DB_PASS) as constants or environment variables.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        self::$pdo = new PDO($dsn, $user, $pass, $options);
        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        return self::$pdo;
    }

    /**
     * Execute a closure within a transaction with deadlock-aware retry.
     * Retries on SQLSTATE 40001 or 1213 up to 3 times with capped backoff.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::connection();
        $attempts = 0;
        $max = 3;
        begin:
        try {
            $attempts++;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e->getCode();
            $sqlState = $e->errorInfo[0] ?? null;
            if ($attempts <= $max && ($sqlState === '40001' || $code === '1213')) {
                $delayMs = min(500 * $attempts + random_int(0, 250), 1200);
                usleep($delayMs * 1000);
                goto begin;
            }
            throw $e;
        } catch (Throwable $t) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $t;
        }
    }
}
