<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/FeatureFlags.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoConnection;

if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('dlq_purge', 30)) return;
if (\Queue\FeatureFlags::killAll() || !\Queue\FeatureFlags::runnerEnabled()) { Http::error('dlq_purge_disabled', 'DLQ purge disabled'); return; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$olderThan = isset($in['older_than']) ? (int)$in['older_than'] : 7; // days
$limit = isset($in['limit']) ? max(1, min(5000, (int)$in['limit'])) : 1000;

try {
    $pdo = PdoConnection::instance();
    $stmt = $pdo->prepare("DELETE FROM ls_jobs_dlq WHERE moved_at < DATE_SUB(NOW(), INTERVAL :d DAY) ORDER BY moved_at ASC LIMIT :l");
    $stmt->bindValue(':d', $olderThan, \PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
    $stmt->execute();
    Http::respond(true, ['deleted' => $stmt->rowCount()]);
} catch (\Throwable $e) { Http::error('dlq_purge_failed', $e->getMessage()); }
