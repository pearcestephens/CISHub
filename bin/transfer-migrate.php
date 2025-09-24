#!/usr/bin/env php
<?php
declare(strict_types=1);

// CLI wrapper to run Queue\Lightspeed\Web::transferMigrate() without HTTP auth, with POST semantics.
// Usage examples:
//   php bin/transfer-migrate.php --apply --limit=1000 --only-type=stock
//   php bin/transfer-migrate.php --dry-run --since-date=2025-01-01 --only-types=ST,JT,IT

use Queue\Lightspeed\Web;

// Bootstrap autoload or minimal deps
$base = dirname(__DIR__);
$autoload = $base . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
} else {
    require $base . '/src/Config.php';
    require $base . '/src/PdoConnection.php';
    require $base . '/src/Http.php';
    require $base . '/src/Lightspeed/Web.php';
}

function print_help(): void {
    fwrite(STDERR, "\ntransfer-migrate.php options:\n" .
        "  --apply                 Apply (default).\n" .
        "  --dry-run               Do not modify data; print counters only.\n" .
        "  --limit=N               Batch size (1..2000). Default 500.\n" .
        "  --since-id=ID           Start from legacy ID > ID.\n" .
        "  --since-date=YYYY-MM-DD Only rows updated/created since this date.\n" .
        "  --only-type=T           One of: stock|juice|staff (synonyms allowed).\n" .
        "  --only-types=A,B,C      CSV list of types/synonyms.\n" .
        "\n");
}

$long = [
    'apply',
    'dry-run',
    'limit:',
    'since-id:',
    'since-date:',
    'only-type:',
    'only-types:',
    'help',
];
$opts = getopt('', $long);
if (isset($opts['help'])) { print_help(); exit(0); }

$in = [];
$in['dry_run'] = isset($opts['dry-run']) ? true : false;
$in['limit'] = isset($opts['limit']) ? max(1, min(2000, (int)$opts['limit'])) : 500;
if (isset($opts['since-id'])) { $in['since_id'] = (int)$opts['since-id']; }
if (isset($opts['since-date'])) { $in['since_date'] = (string)$opts['since-date']; }
if (isset($opts['only-type'])) { $in['only_type'] = (string)$opts['only-type']; }
if (isset($opts['only-types'])) { $in['only_types'] = (string)$opts['only-types']; }

// Ensure POST semantics and CLI auth bypass for Http::ensurePost/ensureAuth
$_SERVER['REQUEST_METHOD'] = 'POST';
// Populate POST body fallback for Web::transferMigrate()
$_POST = $in;

// Run it
Web::transferMigrate();
