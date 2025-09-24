#!/usr/bin/env php
<?php
declare(strict_types=1);

use Queue\PdoConnection;
use Queue\Config;

// Prefer Composer autoload if available; otherwise include minimal deps directly
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    require __DIR__ . '/../src/PdoConnection.php';
    require __DIR__ . '/../src/Config.php';
}

function main(): int {
    $pdo = PdoConnection::instance();

    // Apply core schema first to ensure base tables (ls_jobs, logs, dlq, webhooks) exist
    $schemaFile = __DIR__ . '/../sql/schema.sql';
    if (file_exists($schemaFile)) {
        $schema = file_get_contents($schemaFile);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/m', $schema))) as $stmt) {
            if ($stmt === '') { continue; }
            try { $pdo->exec($stmt); } catch (\Throwable $e) {
                fwrite(STDERR, "[schema] WARN: " . $e->getMessage() . "\n");
            }
        }
    }

    // Apply forward-only migrations (idempotent)
    $sqlFile = __DIR__ . '/../sql/migrations.sql';
    if (!file_exists($sqlFile)) {
        fwrite(STDERR, "migrations.sql not found at $sqlFile\n");
        return 1;
    }
    $sql = file_get_contents($sqlFile);
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/m', $sql))) as $stmt) {
        if ($stmt === '') { continue; }
        $isView = false; $viewName = null;
        // Detect CREATE VIEW anywhere in the statement (comments may precede)
        if (stripos($stmt, 'CREATE OR REPLACE VIEW') !== false) {
            if (preg_match('/CREATE\s+OR\s+REPLACE\s+VIEW\s+`?([a-zA-Z0-9_]+)`?/i', $stmt, $m)) {
                $isView = true; $viewName = $m[1];
            } else {
                $isView = true; // conservative: treat as view
            }
        }
        if ($isView) {
            // If known name and vend_* prefix, skip by policy
            if ($viewName && stripos($viewName, 'vend_') === 0) {
                fwrite(STDERR, "[migrate] SKIP vend-alias view '" . $viewName . "' by policy\n");
                continue;
            }
            // Skip if an existing object with same name is not a VIEW
            if ($viewName) {
                try {
                    $chk = $pdo->prepare('SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1');
                    $chk->execute([':t' => $viewName]);
                    $type = (string)($chk->fetchColumn() ?: '');
                    if ($type !== '' && strcasecmp($type, 'VIEW') !== 0) {
                        fwrite(STDERR, "[migrate] SKIP view '{$viewName}' — object exists as {$type}\n");
                        continue;
                    }
                } catch (\Throwable $e) { /* ignore and attempt exec (may still work) */ }
            }
        }
        try {
            $pdo->exec($stmt);
        } catch (\Throwable $e) {
            // Non-fatal for views on some hosts; log and continue
            if ($isView) {
                fwrite(STDERR, "[migrate] WARN view '{$viewName}': " . $e->getMessage() . "\n");
                continue;
            }
            // Otherwise, rethrow
            throw $e;
        }
    }

    // Ensure ls_jobs.priority column exists
    try {
        $col = $pdo->query("SHOW COLUMNS FROM ls_jobs LIKE 'priority'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE ls_jobs ADD COLUMN priority TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER type");
        }
    } catch (\Throwable $e) {}
    // Ensure ls_jobs scheduling/lease columns exist when upgrading from older schemas
    try {
        $col = $pdo->query("SHOW COLUMNS FROM ls_jobs LIKE 'next_run_at'")->fetch();
        if (!$col) { $pdo->exec("ALTER TABLE ls_jobs ADD COLUMN next_run_at DATETIME NULL AFTER heartbeat_at"); }
    } catch (\Throwable $e) {}
    try {
        $col = $pdo->query("SHOW COLUMNS FROM ls_jobs LIKE 'leased_until'")->fetch();
        if (!$col) { $pdo->exec("ALTER TABLE ls_jobs ADD COLUMN leased_until DATETIME NULL AFTER last_error"); }
    } catch (\Throwable $e) {}
    try {
        $col = $pdo->query("SHOW COLUMNS FROM ls_jobs LIKE 'heartbeat_at'")->fetch();
        if (!$col) { $pdo->exec("ALTER TABLE ls_jobs ADD COLUMN heartbeat_at DATETIME NULL AFTER leased_until"); }
    } catch (\Throwable $e) {}
    // Ensure status+priority index exists
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_jobs WHERE Key_name='idx_jobs_status_priority'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_jobs_status_priority ON ls_jobs (status, priority, updated_at)");
        }
    } catch (\Throwable $e) {}

    // Ensure recommended ls_jobs indexes exist even if table pre-existed
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_jobs WHERE Key_name='idx_status_type'")->fetch();
        if (!$idx) {
            // Fallback name used by db.sanity: idx_jobs_status_type | idx_status_type
            $pdo->exec("CREATE INDEX idx_status_type ON ls_jobs (status, type, updated_at)");
        }
    } catch (\Throwable $e) {}
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_jobs WHERE Key_name='idx_status_next'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_status_next ON ls_jobs (status, next_run_at)");
        }
    } catch (\Throwable $e) {}
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_jobs WHERE Key_name='idx_leased'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_leased ON ls_jobs (status, leased_until)");
        }
    } catch (\Throwable $e) {}

    // Ensure ls_job_logs indexes exist (older installs may be missing)
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_job_logs WHERE Key_name='idx_job'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_job ON ls_job_logs (job_id)");
        }
    } catch (\Throwable $e) {}
    try {
        $idx = $pdo->query("SHOW INDEX FROM ls_job_logs WHERE Key_name='idx_created'")->fetch();
        if (!$idx) {
            $pdo->exec("CREATE INDEX idx_created ON ls_job_logs (created_at)");
        }
    } catch (\Throwable $e) {}

    // Apply optional compatibility shim for legacy heartbeat (transfer_queue)
    $compatFile = __DIR__ . '/../sql/compat_transfer_queue.sql';
    if (file_exists($compatFile)) {
        $compat = file_get_contents($compatFile);
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/m', $compat))) as $stmt) {
            if ($stmt === '') { continue; }
            $pdo->exec($stmt);
        }
    }

    // Normalize token expiry key: vend_expires_at -> vend_token_expires_at
    $old = Config::get('vend_expires_at');
    $new = Config::get('vend_token_expires_at');
    if ($old && !$new) {
        Config::set('vend_token_expires_at', $old);
    }

    // Ensure required defaults
    $defaults = [
        'vend.retry_attempts' => '5',
        'vend.timeout_seconds' => '30',
        'vend_queue_runtime_business' => '1',
        'vend_queue_kill_switch' => '0',
    ];
    foreach ($defaults as $k => $v) {
        if (Config::get($k) === null) {
            Config::set($k, $v);
        }
    }

    echo json_encode(['ok' => true, 'message' => 'Migrations applied (incl. compat shim) and config normalized', 'url' => 'https://staff.vapeshed.co.nz/assets/services/queue/bin/migrate.php']) . "\n";
    return 0;
}

exit(main());
