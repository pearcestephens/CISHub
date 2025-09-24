<?php declare(strict_types=1);
/**
 * LEGACY — DO NOT USE
 * This endpoint has been retired in favor of
 * https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'error' => 'legacy_endpoint',
    'goto' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/dashboard.php'
]);
exit;
