<?php
declare(strict_types=1);

/**
 * cis-config.install.bootstrap.php
 * Purpose: Run cis-config migrations using Queue's robust DB resolver, then register system.queue.lightspeed.
 * Author: GitHub Copilot
 * Last Modified: 2025-09-20
 * Dependencies: Queue\PdoConnection, cis-config/migrations.sql, cis-config/ConfigV2.php
 * @link https://staff.vapeshed.co.nz
 */

header('Content-Type: application/json; charset=utf-8');

// Load Queue PDO resolver and DB credentials
require_once __DIR__ . '/../../src/PdoConnection.php';

// Attempt to load app.php to define DB_* constants
$root = dirname(__DIR__, 5); // .../public_html
if (is_file($root . '/app.php')) {
    require_once $root . '/app.php';
}
// Also try config.php if present
if (is_file($root . '/config.php')) {
    require_once $root . '/config.php';
}
// Optional: load .env into environment for PdoConnection getenv() resolution
$envFile = $root . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        if ($line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k !== '' && $v !== '' && !getenv($k)) { putenv($k . '=' . $v); $_ENV[$k] = $v; }
    }
}

// Map common keys to those expected by PdoConnection fallback chain
$map = [
    'DB_USERNAME' => 'DB_USER',
    'DB_PASSWORD' => 'DB_PASS',
    'DB_DATABASE' => 'DB_NAME',
];
foreach ($map as $src => $dst) {
    $srcVal = getenv($src) ?: ($_ENV[$src] ?? null);
    if ($srcVal && !getenv($dst)) { putenv($dst . '=' . $srcVal); $_ENV[$dst] = $srcVal; }
}

try {
    // Resolve cis-config paths under public_html
    $migrations = $root . '/cis-config/migrations.sql';
    $configV2 = $root . '/cis-config/ConfigV2.php';

    if (!is_file($migrations)) {
        throw new RuntimeException('cis-config/migrations.sql not found. Ensure cis-config is deployed under /public_html/cis-config');
    }

    // Apply migrations
    $sql = (string) file_get_contents($migrations);
    if ($sql === '') throw new RuntimeException('migrations.sql empty');

    $pdo = \Queue\PdoConnection::instance();
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $stmts = array_filter(array_map('trim', preg_split('/;\s*\n/m', $sql)));
    $applied = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $applied++;
    }

    // Register namespace if ConfigV2 present
    $registered = false;
    if (is_file($configV2)) {
        require_once $configV2;
        ConfigV2::registerNamespace('system.queue.lightspeed', 'Data & AI', 'pearce.stephens@ecigdis.co.nz', 'Vend X-Series queue and HTTP client configuration');
        $registered = true;
    }

    echo json_encode([
        'ok' => true,
        'message' => 'cis-config migrations applied' . ($registered ? ' and namespace registered' : ''),
        'applied_statements' => $applied,
        'registered' => $registered,
        'links' => [
            'cis_config_dashboard' => 'https://staff.vapeshed.co.nz/cis-config/dashboard.php',
            'cis_config_install' => 'https://staff.vapeshed.co.nz/cis-config/install.php',
            'queue_register' => 'https://staff.vapeshed.co.nz/assets/services/queue/cis/register/queue.module.register.php'
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => [
            'code' => 'cis_config_bootstrap_failed',
            'message' => $e->getMessage()
        ],
        'links' => [
            'cis_config_readme' => 'https://staff.vapeshed.co.nz/cis-config/dashboard.php'
        ]
    ]);
}
