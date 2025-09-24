<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoConnection;

if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_event_detail', 20)) return;

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$wid = isset($_GET['webhook_id']) ? (string)$_GET['webhook_id'] : '';
if ($id <= 0 && $wid === '') { Http::error('bad_request','id or webhook_id required'); return; }

try {
    $pdo = PdoConnection::instance();
    if ($id > 0) { $stmt = $pdo->prepare('SELECT * FROM webhook_events WHERE id = :id'); $stmt->execute([':id'=>$id]); }
    else { $stmt = $pdo->prepare('SELECT * FROM webhook_events WHERE webhook_id = :wid'); $stmt->execute([':wid'=>$wid]); }
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    Http::respond($row !== null, ['row' => $row]);
} catch (\Throwable $e) { Http::error('webhook_event_detail_failed', $e->getMessage()); }
