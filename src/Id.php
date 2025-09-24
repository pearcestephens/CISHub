<?php
declare(strict_types=1);

namespace Queue;

use PDO;

/**
 * Canonical Public ID generator with per-type, per-period sequences and a check digit.
 * Format: PREFIX-YYYYMM-SEQ6-C2 (e.g., TX-202509-000123-57)
 */
final class Id
{
    /** Map logical type => prefix */
    private static array $prefix = [
        'transfer_exec' => 'TX',
        'purchase_order' => 'PO',
        'return' => 'RT',
        'website_order' => 'WO',
        'consignment' => 'CX',
        'generic' => 'ID',
    ];

    /** Ensure sequences table exists (idempotent). */
    private static function ensureTable(PDO $pdo): void
    {
        static $done = false; if ($done) return; $done = true;
        $sql = "CREATE TABLE IF NOT EXISTS ls_id_sequences (
  seq_type VARCHAR(32) NOT NULL,
  period VARCHAR(10) DEFAULT NULL,
  next_value BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (seq_type, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try { $pdo->exec($sql); } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * Generate a new public ID for the given logical type.
     * @param string $type logical type key (see $prefix)
     * @param string|null $period custom period token (default YYYYMM)
     */
    public static function generate(string $type, ?string $period = null): string
    {
        $type = $type !== '' ? $type : 'generic';
        $pfx = self::$prefix[$type] ?? self::$prefix['generic'];
        $period = $period ?? date('Ym');
        $pdo = PdoConnection::instance();
        self::ensureTable($pdo);
        // Allocate next sequence using LAST_INSERT_ID trick to get atomic increment value
        $stmt = $pdo->prepare("INSERT INTO ls_id_sequences (seq_type, period, next_value) VALUES (:t,:p,2)
ON DUPLICATE KEY UPDATE next_value = LAST_INSERT_ID(next_value + 1), updated_at = NOW()");
        $stmt->execute([':t' => $type, ':p' => $period]);
        $next = (int) $pdo->lastInsertId(); // this is the incremented value
        if ($next <= 1) { $next = 1; }
        $assigned = $next - 1; // consumed sequence number for this call
        $seq = str_pad((string)$assigned, 6, '0', STR_PAD_LEFT);
        $numeric = (int) ($period . $seq); // up to 12 digits
        $check = self::check97($numeric);
        return $pfx . '-' . $period . '-' . $seq . '-' . $check;
    }

    /** Simple ISO 7064 mod97-10 two-digit check. */
    private static function check97(int $num): string
    {
        $rem = $num % 97; $cd = 98 - $rem; if ($cd === 98) $cd = 0; return str_pad((string)$cd, 2, '0', STR_PAD_LEFT);
    }
}
