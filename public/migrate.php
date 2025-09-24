<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
require_once __DIR__ . '/../src/Http.php';

header('Content-Type: application/json');

try {
    // Bootstrap
    $root = dirname(__DIR__, 3);
    $bootstrap = $root . '/bootstrap.php';
    if (file_exists($bootstrap)) {
        require_once $bootstrap;
    }

    // Include migration service
    $svc = dirname(__DIR__, 1) . '/migrate-service.php';
    if (file_exists($svc)) {
        require_once $svc;
    }

    if (!function_exists('queue_migrate')) {
        throw new RuntimeException('Migration service unavailable');
    }

    $dryRun = isset($_GET['simulate']) ? (int)$_GET['simulate'] === 1 : false;
    $result = queue_migrate(['dryRun' => $dryRun]);

    echo json_encode([
        'success' => true,
        'dryRun' => $dryRun,
        'result' => $result,
    ]);
} catch (Throwable $e) {
    error_log('queue migrate error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'MIGRATE_ERROR',
            'message' => 'Queue migration failed',
        ],
    ]);
}
