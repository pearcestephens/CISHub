#!/usr/bin/env php
<?php
declare(strict_types=1);
// CLI helper to run prefix migration without HTTP
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';

// Emulate POST + Authorization so Web::migratePrefix() passes guards
$_SERVER['REQUEST_METHOD'] = 'POST';
$bearer = (string)(\Queue\Config::get('ADMIN_BEARER_TOKEN', '') ?? '');
if ($bearer !== '') {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $bearer;
}

// Parse args: --dry-run (default) or --apply
$dry = true;
foreach ($argv as $arg) {
    if ($arg === '--apply') { $dry = false; }
    if ($arg === '--dry-run' || $arg === '--dryrun') { $dry = true; }
}

// Provide body as JSON
$payload = json_encode(['dry_run' => $dry]);
// Populate php://input emulation for CLI
// Note: php://input is read-only, so we pass via $_POST fallback used by migratePrefix()
$_POST = ['dry_run' => $dry ? 1 : 0];

\Queue\Lightspeed\Web::migratePrefix();
