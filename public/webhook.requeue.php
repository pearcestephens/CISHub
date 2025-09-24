<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/PdoWorkItemRepository.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/FeatureFlags.php';

use Queue\Http;
use Queue\PdoConnection;
use Queue\PdoWorkItemRepository as Repo;

if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_requeue', 20)) return;
if (\Queue\FeatureFlags::isDisabled(\Queue\FeatureFlags::webhookEnabled())) { Http::error('webhook_requeue_disabled', 'Webhook processing disabled'); return; }

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$ids = isset($in['ids']) && is_array($in['ids']) ? array_values(array_filter($in['ids'], 'is_numeric')) : [];
$since = isset($in['since']) ? (string)$in['since'] : '';
$limit = isset($in['limit']) ? max(1, min(500, (int)$in['limit'])) : 100;

try {
    $pdo = PdoConnection::instance();
    if (!$ids && $since === '') { Http::error('bad_request','ids or since required'); return; }
    if ($ids) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT webhook_id, webhook_type FROM webhook_events WHERE id IN ($place) LIMIT $limit");
        $stmt->execute($ids);
    } else {
        $stmt = $pdo->prepare("SELECT webhook_id, webhook_type FROM webhook_events WHERE received_at >= :since ORDER BY received_at ASC LIMIT :lim");
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $enq = 0;
    foreach ($rows as $r) {
        $wid = (string)$r['webhook_id']; $t = (string)$r['webhook_type'];
        $jobId = Repo::addJob('webhook.event', ['webhook_id' => $wid, 'webhook_type' => $t], 'webhook:' . $wid);
        $up = $pdo->prepare("UPDATE webhook_events SET status='processing', queue_job_id=:jid, updated_at=NOW() WHERE webhook_id=:wid");
        $up->execute([':jid' => (string)$jobId, ':wid' => $wid]);
        $enq++;
    }
    Http::respond(true, ['enqueued' => $enq]);
} catch (\Throwable $e) { Http::error('requeue_failed', $e->getMessage()); }
