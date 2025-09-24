<?php
declare(strict_types=1);
/**
 * assets/services/queue/monitor.php
 *
 * JSON health snapshot across key endpoints; triggers a synthetic trace when unhealthy.
 */

header('Content-Type: application/json');

$base = 'https://staff.vapeshed.co.nz/assets/services/queue';
$checks = [
  'health'  => $base . '/health.php',
  'metrics' => $base . '/metrics.php',
  'webhook' => $base . '/webhook.health.php',
  'queue'   => $base . '/queue.status.php',
];

function fetch_json(string $url, int $timeout=5): array {
  $ctx = stream_context_create([
    'http' => ['method' => 'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"],
    'ssl'  => ['verify_peer'=>true,'verify_peer_name'=>true],
  ]);
  $start = microtime(true);
  $body = @file_get_contents($url, false, $ctx);
  $code = 0; $hdrs = $GLOBALS['http_response_header'] ?? [];
  foreach ($hdrs as $h) if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/',$h,$m)) { $code=(int)$m[1]; break; }
  $ms = (int)round((microtime(true)-$start)*1000);
  $ok = $code>=200 && $code<400 && $body!==false;
  $data = null;
  if ($ok) {
    $json = json_decode((string)$body, true);
    if (is_array($json)) $data = $json;
  }
  return ['ok'=>$ok,'code'=>$code,'ms'=>$ms,'data'=>$data,'url'=>$url];
}

$results = [];
$overallOk = true;
foreach ($checks as $name=>$url) {
  $r = fetch_json($url);
  $results[$name] = $r;
  if (!$r['ok']) $overallOk = false;
}

$traceUrl = null;
if (!$overallOk) {
  $sim = fetch_json($base . '/simulate.php');
  if ($sim['ok'] && isset($sim['data']['trace_url'])) $traceUrl = $sim['data']['trace_url'];
}

http_response_code($overallOk ? 200 : 500);
echo json_encode([
  'ok'     => $overallOk,
  'checks' => $results,
  'trace'  => $traceUrl,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
