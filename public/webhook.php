<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Lightspeed/Web.php';
if (\Queue\FeatureFlags::isDisabled(\Queue\FeatureFlags::webhookEnabled())) {
	header('Content-Type: application/json; charset=utf-8');
	http_response_code(503);
	echo json_encode([
		'success' => false,
		'error' => [ 'code' => 'webhook_disabled', 'message' => 'Webhook intake is currently disabled.' ],
		'meta' => [ 'flags' => \Queue\FeatureFlags::snapshot() ],
	]);
	exit;
}
\Queue\Lightspeed\Web::webhook();
