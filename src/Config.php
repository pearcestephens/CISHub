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
    /** @var bool|null cached detection for new config backend */
    private static ?bool $hasNew = null;
    /** @var array<string,int> namespace name -> id cache */
    private static array $nsId = [];

    /** Detect whether config_* tables exist */
    private static function hasNewBackend(): bool
    {
        if (self::$hasNew !== null) return self::$hasNew;
        try {
            $pdo = PdoConnection::instance();
            $chk = $pdo->query("SHOW TABLES LIKE 'config_items'")->fetchColumn();
            self::$hasNew = $chk ? true : false;
        } catch (\Throwable $e) { self::$hasNew = false; }
        return (bool)self::$hasNew;
    }

    /** Resolve namespace id (create if missing) */
    private static function nsId(string $name, bool $create = true): ?int
    {
        if (isset(self::$nsId[$name])) return self::$nsId[$name];
        try {
            $pdo = PdoConnection::instance();
            // Try fetch existing
            $sel = $pdo->prepare('SELECT id FROM config_namespaces WHERE name = :n LIMIT 1');
            $sel->execute([':n' => $name]);
            $id = $sel->fetchColumn();
            if ($id !== false && $id !== null) { self::$nsId[$name] = (int)$id; return (int)$id; }
            if (!$create) return null;
            $ins = $pdo->prepare('INSERT INTO config_namespaces(name) VALUES(:n)');
            $ins->execute([':n' => $name]);
            $nid = (int)$pdo->lastInsertId();
            if ($nid <= 0) {
                $sel->execute([':n' => $name]);
                $nid = (int)($sel->fetchColumn() ?: 0);
            }
            if ($nid > 0) { self::$nsId[$name] = $nid; return $nid; }
        } catch (\Throwable $e) {}
        return null;
    }

    /** Normalize vend_domain_prefix to the left-most DNS label, lowercase, strict charset. */
    private static function normalizeVendDomainPrefix(string $input): string
    {
        $v = trim($input);
        // If starts with http(s), parse host
        if (stripos($v, 'http://') === 0 || stripos($v, 'https://') === 0) {
            $host = parse_url($v, PHP_URL_HOST) ?: $v;
        } else {
            // Maybe they pasted a host directly
            $host = $v;
        }
        // If it looks like a host like "vapeshed.retail.lightspeed.app", take left-most label
        if (strpos((string)$host, '.') !== false) {
            $parts = explode('.', (string)$host);
            $parts = array_values(array_filter($parts, static fn($p) => $p !== ''));
            $prefix = $parts[0] ?? (string)$host;
        } else {
            $prefix = (string)$host;
        }
        $prefix = strtolower($prefix);
        if (!preg_match('/^[a-z0-9-]{2,50}$/', $prefix)) {
            throw new \RuntimeException('vend_domain_prefix invalid after normalization: ' . $prefix);
        }
        return $prefix;
    }

    public static function get(string $label, $default = null)
    {
        if (array_key_exists($label, self::$cache)) {
            return self::$cache[$label];
        }
        // New backend path: expect labels in dotted form "namespace.key"; default to 'queue'
        if (self::hasNewBackend()) {
            $ns = 'queue'; $key = $label;
            if (strpos($label, '.') !== false) { $parts = explode('.', $label, 2); $ns = $parts[0]; $key = $parts[1]; }
            try {
                $pdo = PdoConnection::instance();
                $nid = self::nsId($ns, false);
                if ($nid) {
                    $st = $pdo->prepare('SELECT value FROM config_items WHERE namespace_id = :n AND `key` = :k LIMIT 1');
                    $st->execute([':n' => $nid, ':k' => $key]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row && $row['value'] !== null && $row['value'] !== '') {
                        $val = self::decode((string)$row['value']);
                        self::$cache[$label] = $val; return $val;
                    }
                }
            } catch (\Throwable $e) { /* fallback to legacy */ }
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
        // Normalize vend_domain_prefix on write (accept URLs/hosts, store the first label only)
        $labelNorm = $label;
        // Support common aliases
        $isVendPrefix = in_array($labelNorm, ['vend_domain_prefix','vend.domain_prefix','vend.domain','ls.domain_prefix'], true);
        if ($isVendPrefix) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('vend_domain_prefix must be a string');
            }
            $value = self::normalizeVendDomainPrefix($value);
            // Always write to canonical key and mirror to alias key if different
            $labelNorm = 'vend_domain_prefix';
        }
        $encoded = self::encode($value);
        $pdo = PdoConnection::instance();

        if (self::hasNewBackend()) {
            // Expect dotted label; default namespace 'queue'
            $ns = 'queue'; $key = $labelNorm;
            if (strpos($labelNorm, '.') !== false) { $parts = explode('.', $labelNorm, 2); $ns = $parts[0]; $key = $parts[1]; }
            $nid = self::nsId($ns, true) ?? 0;
            if ($nid > 0) {
                // Read old for audit
                $old = null; try { $s=$pdo->prepare('SELECT value FROM config_items WHERE namespace_id=:n AND `key`=:k'); $s->execute([':n'=>$nid, ':k'=>$key]); $r=$s->fetch(PDO::FETCH_ASSOC); if ($r) $old=(string)$r['value']; } catch (\Throwable $e) {}
                // Upsert
                $pdo->prepare('INSERT INTO config_items(namespace_id, `key`, `value`) VALUES(:n,:k,:v)
                               ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                    ->execute([':n'=>$nid, ':k'=>$key, ':v'=>$encoded]);
                // Audit
                try {
                    $pdo->prepare('INSERT INTO config_audit_log(namespace_id, `key`, old_value, new_value, actor, actor_ip, request_id) VALUES(:n,:k,:ov,:nv,:a,:ip,:rid)')
                        ->execute([
                            ':n' => $nid, ':k' => $key, ':ov' => $old, ':nv' => $encoded,
                            ':a' => (string)($_SESSION['username'] ?? $_SESSION['userID'] ?? 'system'),
                            ':ip'=> (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                            ':rid'=> Http::requestId(),
                        ]);
                } catch (\Throwable $e) { /* best-effort */ }
                self::$cache[$labelNorm] = $value; if ($label !== $labelNorm) self::$cache[$label] = $value; return;
            }
        }

        // Legacy fallback
        $pdo->prepare(
            'INSERT INTO configuration (config_label, config_value) VALUES (:l,:v)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute([':l' => $labelNorm, ':v' => $encoded]);
        self::$cache[$labelNorm] = $value; if ($label !== $labelNorm) self::$cache[$label] = $value;
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
