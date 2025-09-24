<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Config.php';

use Queue\PdoConnection;
use Queue\Http;

Http::commonJsonHeaders();
if (!Http::ensurePost()) { return; }
if (!Http::ensureAuth()) { return; }
if (!Http::rateLimit('transfer_cleanup_cache', 10)) { return; }

try {
    $pdo = PdoConnection::instance();
    // Optional JSON payload: { "limit": 500, "dry_run": true }
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $limit = isset($in['limit']) ? max(10, min(5000, (int)$in['limit'])) : 1000;
    $dry = isset($in['dry_run']) ? (bool)$in['dry_run'] : false;

    $has = (bool) $pdo->query("SHOW TABLES LIKE 'transfer_validation_cache'")->fetchColumn();
    if (!$has) { echo json_encode(['ok' => false, 'error' => ['code' => 'missing_table']]); return; }

    $sel = $pdo->prepare("SELECT id FROM transfer_validation_cache WHERE expires_at <= NOW() ORDER BY id ASC LIMIT :lim");
    $sel->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $sel->execute();
    $ids = $sel->fetchAll(\PDO::FETCH_COLUMN) ?: [];

    if ($dry) {
        echo json_encode(['ok' => true, 'data' => ['expired_count' => count($ids), 'deleted' => 0, 'dry_run' => true]]);
        return;
    }

    $deleted = 0;
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM transfer_validation_cache WHERE id IN ($place)");
        $del->execute(array_map('intval', $ids));
        $deleted = $del->rowCount();
    }

    echo json_encode(['ok' => true, 'data' => ['expired_count' => count($ids), 'deleted' => $deleted]]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'cleanup_failed', 'message' => $e->getMessage()]]);
}
