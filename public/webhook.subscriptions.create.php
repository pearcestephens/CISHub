<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Http.php';

use Queue\Http;
use Queue\PdoConnection;

if (!Http::ensurePost()) return; if (!Http::ensureAuth()) return; if (!Http::rateLimit('webhook_subs_create', 30)) return;

$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$source = isset($in['source_system']) ? (string)$in['source_system'] : 'vend';
$event = isset($in['event_type']) ? (string)$in['event_type'] : '';
$url = isset($in['endpoint_url']) ? (string)$in['endpoint_url'] : '';
if ($event === '' || $url === '') { Http::error('bad_request', 'event_type and endpoint_url required'); return; }

try { $pdo = PdoConnection::instance(); $stmt = $pdo->prepare("INSERT INTO webhook_subscriptions (source_system, event_type, endpoint_url, is_active, events_received_today, events_received_total, health_status, updated_at) VALUES (:s, :e, :u, 1, 0, 0, 'unknown', NOW())"); $stmt->execute([':s'=>$source, ':e'=>$event, ':u'=>$url]); Http::respond(true, ['id' => (int)$pdo->lastInsertId()]); } catch (\Throwable $e) { Http::error('webhook_subs_create_failed', $e->getMessage()); }
