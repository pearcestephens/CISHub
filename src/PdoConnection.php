<?php
declare(strict_types=1);

namespace Queue;

use PDO;
use PDOException;

/**
 * PdoConnection — Permanent PDO singleton (utf8mb4, persistent)
 * with deadlock-aware transactions and optional advisory locks.
 *
 * Purpose
 * - Provide safe, cached PDO connections for three logical databases:
 *   default (CIS core), db2 (website), db3 (wiki), resolved from env.
 * - Offer helper utilities for transactions with retry on deadlocks and
 *   best-effort advisory locks.
 *
 * Usage
 *   // Default (CIS core)
 *   $pdo = PdoConnection::instance();
 *   $stmt = $pdo->prepare('SELECT 1');
 *   $stmt->execute();
 *
 *   // Website (db2)
 *   $pdo2 = PdoConnection::instance('db2');
 *
 *   // Wiki (db3)
 *   $pdo3 = PdoConnection::instance('db3');
 *
 *   // Transaction with automatic deadlock retry
 *   $result = PdoConnection::transaction(function(PDO $pdo) {
 *       $pdo->prepare('INSERT INTO t(x) VALUES(1)')->execute();
 *       return 'ok';
 *   });
 *
 *   // Advisory lock (best-effort)
 *   $out = PdoConnection::withAdvisoryLock('my-lock', 5, function(){
 *       // critical section
 *       return true;
 *   });
 *
 * Environment variables (.env)
 *   Default: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *   DB2:     DB2_HOST, DB2_PORT, DB2_NAME, DB2_USER, DB2_PASS (or VS_DB_*)
 *   DB3:     DB3_HOST, DB3_PORT, DB3_NAME, DB3_USER, DB3_PASS (or WIKI_DB_*)
 *
 * @link https://staff.vapeshed.co.nz
 */
final class PdoConnection
{
    private static ?PDO $pdo = null;
    private static ?PDO $pdo2 = null;
    private static ?PDO $pdo3 = null;
    private static bool $envLoaded = false;
    /** @var string|null Cached DB server version string (e.g., '10.5.21-MariaDB') */
    private static ?string $serverVersion = null;
    /** @var string|null Cached DB flavor ('mariadb' or 'mysql') */
    private static ?string $serverFlavor = null;
    /** @var array<string,bool> */
    private static array $heldLocks = [];

    public static function instance(string $which = 'default'): PDO
    {
        // Return cached if exists
        if ($which === 'default' && self::$pdo) return self::$pdo;
        if ($which === 'db2' && self::$pdo2) return self::$pdo2;
        if ($which === 'db3' && self::$pdo3) return self::$pdo3;

        // Best-effort .env loader (once per process). This allows ops to drop a .env file
        // in common locations without changing code or web server env. No secrets are stored
        // in code; this only reads from disk if present.
        if (!self::$envLoaded) {
            self::$envLoaded = true;
            try {
                $candidates = [];
                $doc = $_SERVER['DOCUMENT_ROOT'] ?? null;
                if (is_string($doc) && $doc !== '') { $candidates[] = rtrim($doc, '/\\') . '/.env'; }
                // Compute likely public_html from current file path (../../../../)
                $maybeRoot = dirname(__DIR__, 4);
                if (is_string($maybeRoot) && $maybeRoot !== '' && is_dir($maybeRoot)) { $candidates[] = $maybeRoot . '/.env'; }
                // Also allow module-local .env files (not recommended for prod, but useful for dev)
                $candidates[] = dirname(__DIR__, 3) . '/.env'; // queue root
                $candidates[] = dirname(__DIR__, 2) . '/.env'; // services root
                $candidates[] = dirname(__DIR__) . '/.env';    // src dir
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
                        break; // stop at first found
                    }
                }
            } catch (\Throwable $e) {
                // Ignore .env load errors; environment may be provided by FPM/server
            }
        }

        // Helper to read first non-empty value from env key list
        $envFirst = function(array $keys, string $default='') {
            foreach ($keys as $k) {
                $v = getenv($k);
                if ($v !== false && $v !== '') return (string)$v;
            }
            return $default;
        };

        // Use robust fallback chain; support 3 named connections via env prefixes
        // Plus alias support for historical names: VS_DB_* for db2, WIKI_DB_* for db3
        if ($which === 'db2') {
            $host = $envFirst(['DB2_HOST','VS_DB_HOST'], '127.0.0.1');
            $port = $envFirst(['DB2_PORT','VS_DB_PORT'], '3306');
            $user = $envFirst(['DB2_USER','VS_DB_USER'], '');
            $pass = $envFirst(['DB2_PASS','VS_DB_PASS'], '');
            $db   = $envFirst(['DB2_NAME','VS_DB_NAME'], '');
        } elseif ($which === 'db3') {
            $host = $envFirst(['DB3_HOST','WIKI_DB_HOST'], '127.0.0.1');
            $port = $envFirst(['DB3_PORT','WIKI_DB_PORT'], '3306');
            $user = $envFirst(['DB3_USER','WIKI_DB_USER'], '');
            $pass = $envFirst(['DB3_PASS','WIKI_DB_PASS'], '');
            $db   = $envFirst(['DB3_NAME','WIKI_DB_NAME'], '');
        } else {
            // Prefer QUEUE_DB_* overrides if present, else fall back to DB_*/legacy constants
            $get = function(string $key, array $consts, string $default='') {
                $envKeys = ['QUEUE_DB_' . $key, 'DB_' . $key, $key];
                foreach ($envKeys as $k) { $v = getenv($k); if ($v !== false && $v !== '') return (string)$v; }
                foreach ($consts as $c) { if (defined($c)) { $val = constant($c); if ($val !== null && $val !== '') return (string)$val; } }
                return $default;
            };
            $host = $get('HOST', ['QUEUE_DB_HOST','DB_HOST'], '127.0.0.1');
            $port = $get('PORT', ['QUEUE_DB_PORT','DB_PORT'], '3306');
            $user = $get('USER', ['QUEUE_DB_USERNAME','QUEUE_DB_USER','DB_USERNAME','DB_USER'], '');
            $pass = $get('PASS', ['QUEUE_DB_PASSWORD','QUEUE_DB_PASS','DB_PASSWORD','DB_PASS'], '');
            $db   = $get('NAME', ['QUEUE_DB_DATABASE','QUEUE_DB_NAME','DB_DATABASE','DB_NAME'], '');
        }
        
        // Ensure all credentials are strings, never bool
        $host = (string)$host;
        $port = (string)$port;
        $user = (string)$user;
        $pass = (string)$pass;
        $db   = (string)$db;
        
        if ($db === '' || $user === '') {
            throw new \RuntimeException('DB credentials missing. Define DB_NAME/DB_USER (and DB_PASS) via env or constants.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Ensure utf8mb4 everywhere (MariaDB 10.5 compatible)
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        // Record server version/flavor for observability and conditional behaviors
        try {
            $v = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            self::$serverVersion = $v;
            self::$serverFlavor = (stripos($v, 'mariadb') !== false) ? 'mariadb' : 'mysql';
            // Optional: session tweaks safe for MariaDB 10.5
            if (self::$serverFlavor === 'mariadb') {
                // Enable stricter InnoDB checks without changing sql_mode which might break legacy queries
                try { $pdo->exec('SET SESSION innodb_strict_mode=ON'); } catch (\Throwable $e) { /* ignore if unavailable */ }
            }
        } catch (\Throwable $e) {
            // Non-fatal: leave server info unknown
        }
        if ($which === 'db2') { self::$pdo2 = $pdo; return self::$pdo2; }
        if ($which === 'db3') { self::$pdo3 = $pdo; return self::$pdo3; }
        self::$pdo = $pdo; return self::$pdo;
    }

    /**
     * Transaction wrapper with retry on deadlocks (40001/1213)
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::instance();
        $attempt = 0; $max = 3;
        begin:
        try {
            $attempt++;
            if (!$pdo->inTransaction()) $pdo->beginTransaction();
            $r = $fn($pdo);
            if ($pdo->inTransaction()) { $pdo->commit(); }
            return $r;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $sqlState = $e->errorInfo[0] ?? null; $code = $e->getCode();
            if ($attempt < $max && ($sqlState === '40001' || $code === '1213')) {
                usleep(min(250 * $attempt + random_int(0, 250), 1200) * 1000);
                goto begin;
            }
            throw $e;
        } catch (\Throwable $t) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $t;
        }
    }

    /**
     * Acquire an advisory lock, run the callable, then release. Safe if lock unsupported.
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withAdvisoryLock(string $name, int $timeoutSeconds, callable $fn)
    {
        $pdo = self::instance();
        $locked = false;
        try {
            $stmt = $pdo->prepare('SELECT GET_LOCK(:n, :t) AS got');
            $stmt->execute([':n' => $name, ':t' => $timeoutSeconds]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $locked = isset($row['got']) ? ((string)$row['got'] === '1' || (int)$row['got'] === 1) : false;
        } catch (\Throwable $e) {
            // Ignore lock errors; proceed without lock
        }
        try {
            return $fn();
        } finally {
            if ($locked) {
                try { $pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute([':n' => $name]); } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * Return database server info detected at first connection.
     * @return array{version: string|null, flavor: string|null}
     */
    public static function serverInfo(): array
    {
        // Initialize if not yet connected
        try { if (!self::$pdo) { self::instance(); } } catch (\Throwable $e) { /* ignore */ }
        return [
            'version' => self::$serverVersion,
            'flavor'  => self::$serverFlavor,
        ];
    }
}
