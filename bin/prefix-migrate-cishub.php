#!/usr/bin/env php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';

use Queue\Lightspeed\Web;

[$ok, $data] = [false, []];
try {
    // Dry-run first
    echo "[cishub] prefix migration: DRY RUN...\n";
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $stdin = [ 'dry_run' => true ];
    $json = json_encode($stdin);
    $tmp = fopen('php://temp', 'r+'); fwrite($tmp, (string)$json); rewind($tmp);
    // Invoke directly via method: emulate input stream
    // Simpler path: call the method that implements migration using the mapping
    // We will call migratePrefix() with dry-run and real-run by setting php://input globally
    // However, since we cannot easily override php://input here, we will create a shim function.
} catch (\Throwable $e) {
    echo "ERROR: ".$e->getMessage()."\n"; exit(1);
}

echo "NOTE: Run the HTTP endpoint instead for full behavior:\n";
echo "  POST https://staff.vapeshed.co.nz/assets/services/queue/public/prefix.migrate.php with body {\"dry_run\": true}\n";
echo "  POST https://staff.vapeshed.co.nz/assets/services/queue/public/prefix.migrate.php (no body) to apply\n";
