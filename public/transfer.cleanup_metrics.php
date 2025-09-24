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
if (!Http::rateLimit('transfer_cleanup_metrics', 10)) { return; }

try {
    $pdo = PdoConnection::instance();
    // { "older_than_days": 30, "limit": 50000, "dry_run": true }
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    $days = isset($in['older_than_days']) ? max(1, min(365, (int)$in['older_than_days'])) : 90;
    $limit = isset($in['limit']) ? max(100, min(200000, (int)$in['limit'])) : 50000;
    $dry = isset($in['dry_run']) ? (bool)$in['dry_run'] : true;

    $has = (bool) $pdo->query("SHOW TABLES LIKE 'transfer_queue_metrics'")->fetchColumn();
    if (!$has) { echo json_encode(['ok' => false, 'error' => ['code' => 'missing_table']]); return; }

    $sel = $pdo->prepare("SELECT id FROM transfer_queue_metrics WHERE recorded_at < DATE_SUB(NOW(), INTERVAL :d DAY) ORDER BY id ASC LIMIT :lim");
    $sel->bindValue(':d', $days, \PDO::PARAM_INT);
    $sel->bindValue(':lim', $limit, \PDO::PARAM_INT);
    $sel->execute();
    $ids = $sel->fetchAll(\PDO::FETCH_COLUMN) ?: [];

    if ($dry) {
        echo json_encode(['ok' => true, 'data' => ['candidates' => count($ids), 'deleted' => 0, 'dry_run' => true, 'older_than_days' => $days]]);
        return;
    }

    $deleted = 0;
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $del = $pdo->prepare("DELETE FROM transfer_queue_metrics WHERE id IN ($place)");
        $del->execute(array_map('intval', $ids));
        $deleted = $del->rowCount();
    }

    echo json_encode(['ok' => true, 'data' => ['candidates' => count($ids), 'deleted' => $deleted, 'older_than_days' => $days]]);
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => ['code' => 'cleanup_failed', 'message' => $e->getMessage()]]);
}
