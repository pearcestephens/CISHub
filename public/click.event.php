<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/PdoConnection.php';
require_once __DIR__ . '/../src/Http.php';
\Queue\Http::commonJsonHeaders();
$in = [];
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') { $tmp = json_decode($raw, true); if (is_array($tmp)) $in = $tmp; }
if (!$in) $in = $_POST ?: [];
$event = (string)($in['event'] ?? '');
if ($event==='') { \Queue\Http::error('bad_request','event required'); return; }
try {
  $pdo = \Queue\PdoConnection::instance();
  $stmt = $pdo->prepare("INSERT INTO client_events (request_id,page,event,target,data,ip,user_agent)
    VALUES (:rid,:page,:event,:target,:data,:ip,:ua)");
  $stmt->execute([
    ':rid'   => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ':page'  => (string)($in['page'] ?? ''),
    ':event' => $event,
    ':target'=> (string)($in['target'] ?? ''),
    ':data'  => isset($in['data']) ? json_encode($in['data'], JSON_UNESCAPED_SLASHES) : null,
    ':ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    ':ua'    => $_SERVER['HTTP_USER_AGENT'] ?? '',
  ]);
  echo json_encode(['ok'=>true]);
} catch (\Throwable $e) {
  \Queue\Http::error('client_event_failed',$e->getMessage());
}
