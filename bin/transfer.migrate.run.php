#!/usr/bin/env php
<?php
declare(strict_types=1);

use Queue\Lightspeed\Web;

require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';

// Simple CLI args parser
$opts = [
    'limit' => 200,
    'dry_run' => false,
    'only_type' => null,
    'since_id' => null,
    'since_date' => null,
    'force_legacy' => null,
];
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $opts['limit'] = max(1, min(10000, (int)$m[1])); }
    elseif ($arg === '--dry-run' || $arg === '--dry_run') { $opts['dry_run'] = true; }
    elseif (preg_match('/^--dry(?:_|-)run=(.+)$/i', $arg, $m)) { $val = strtolower(trim($m[1])); $opts['dry_run'] = ($val === '1' || $val === 'true' || $val === 'yes'); }
    elseif (preg_match('/^--dry(?:=(.+))?$/i', $arg, $m)) { $val = isset($m[1]) ? strtolower(trim($m[1])) : '1'; $opts['dry_run'] = ($val === '1' || $val === 'true' || $val === 'yes'); }
    elseif (preg_match('/^--only_type=(.+)$/', $arg, $m)) { $opts['only_type'] = $m[1]; }
    elseif (preg_match('/^--since_id=(\d+)$/', $arg, $m)) { $opts['since_id'] = (int)$m[1]; }
    elseif (preg_match('/^--since_date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) { $opts['since_date'] = $m[1]; }
    elseif ($arg === '--force_legacy' || $arg === '--legacy') { $opts['force_legacy'] = true; }
    elseif (preg_match('/^--force_legacy=(.+)$/', $arg, $m)) { $val = strtolower(trim($m[1])); $opts['force_legacy'] = ($val === '1' || $val === 'true' || $val === 'yes'); }
}

// Simulate HTTP POST body for Web::transferMigrate()
$_SERVER['REQUEST_METHOD'] = 'POST';
$payload = [ 'limit' => $opts['limit'] ];
if ($opts['dry_run']) { $payload['dry_run'] = true; }
if ($opts['only_type']) { $payload['only_type'] = $opts['only_type']; }
if ($opts['since_id'] !== null) { $payload['since_id'] = $opts['since_id']; }
if ($opts['since_date'] !== null) { $payload['since_date'] = $opts['since_date']; }
if ($opts['force_legacy'] !== null) { $payload['force_legacy'] = (bool)$opts['force_legacy']; }

// Inject JSON body for handler
$stdinJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
// php://input is read-only; Web::transferMigrate() already supports $_POST fallback when JSON is empty.
$_POST = $payload;

// Capture output
ob_start();
Web::transferMigrate();
$out = ob_get_clean();

// Print JSON response to stdout
if ($out !== null && $out !== '') {
    echo $out, "\n";
} else {
    fwrite(STDERR, "No output from transferMigrate()\n");
    exit(1);
}
