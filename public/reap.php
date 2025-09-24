<?php declare(strict_types=1);
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/FeatureFlags.php';

use Queue\Http;
use Queue\PdoConnection;

if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('reap')) { return; }
if (\Queue\FeatureFlags::killAll() || !\Queue\FeatureFlags::runnerEnabled()) { Http::error('reap_disabled', 'Reap disabled'); return; }

$pdo = PdoConnection::instance();
try {
	$stmt = $pdo->prepare("UPDATE ls_jobs SET status='pending', leased_until=NULL, heartbeat_at=NULL, updated_at=NOW() WHERE status='working' AND (leased_until IS NULL OR leased_until < NOW())");
	$stmt->execute();
	Http::respond(true, ['reaped' => (int)$stmt->rowCount()]);
} catch (\Throwable $e) {
	Http::error('server_error', 'Failed to reap', ['message' => $e->getMessage()], 500);
}
