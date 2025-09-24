<?php declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
	'ok' => false,
	'error' => ['code' => 'legacy_endpoint', 'message' => 'Use new /assets/services/queue/public/manual.refresh_token.php'],
	'data' => null,
	'goto' => 'https://staff.vapeshed.co.nz/assets/services/queue/public/manual.refresh_token.php'
]);
exit;
