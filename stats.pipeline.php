<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/stats.pipeline.php
 * Purpose: Traffic-light status for endpoints and databases; quick pipeline observability
 * Author: GitHub Copilot
 * Links:
 * - Dashboard: https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php
 */

use Queue\PdoConnection;

// Do not start session or include app.php (avoid cross-system redirects)

require_once __DIR__ . '/src/PdoConnection.php';

function h(?string $s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function probe_url(string $url, int $timeoutSec = 3): array {
    $start = microtime(true); $code = 0; $ok = false; $err = '';
    try {
        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $timeoutSec, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        @file_get_contents($url, false, $ctx);
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $line, $m)) { $code = (int)$m[1]; break; }
        }
        $ok = $code >= 200 && $code < 400;
    } catch (\Throwable $e) { $err = $e->getMessage(); }
    $ms = (int)round((microtime(true)-$start)*1000);
    return ['ok'=>$ok,'code'=>$code,'ms'=>$ms,'err'=>$err];
}

function db_check(string $which): array {
    try {
        $pdo = PdoConnection::instance($which === 'default' ? 'default' : $which);
        $v = $pdo->query('SELECT VERSION() as v')->fetchColumn();
        $now = $pdo->query('SELECT NOW()')->fetchColumn();
        return ['ok'=>true,'info'=> (string)$v, 'now'=>(string)$now, 'err'=>''];
    } catch (\Throwable $e) {
        return ['ok'=>false,'info'=>null,'now'=>null,'err'=>$e->getMessage()];
    }
}

$base = 'https://staff.vapeshed.co.nz/assets/services/queue';
$eps = [
  'Health' => $base.'/health.php',
  'Metrics' => $base.'/metrics.php',
  'Webhook Health' => $base.'/webhook.health.php',
];

$dbs = [
  'default' => 'CIS Core (default)',
  'db2'     => 'Website (db2)',
  'db3'     => 'Wiki (db3)'
];

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pipeline Status</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;line-height:1.4;margin:0;padding:8px}
    table{width:100%;border-collapse:collapse}
    th,td{padding:6px 8px;border-bottom:1px solid #eaeaea;font-size:13px;text-align:left}
    .ok{color:#2e7d32}
    .fail{color:#c62828}
    .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #ddd;font-size:12px}
    .muted{color:#6b7280}
  </style>
</head>
<body>
  <h3 style="margin:8px 0">Endpoint Probes</h3>
  <table>
    <thead><tr><th>Endpoint</th><th>Status</th><th>Code</th><th>Latency</th></tr></thead>
    <tbody>
      <?php foreach ($eps as $name => $url): $p = probe_url($url); ?>
        <tr>
          <td><a href="<?php echo h($url); ?>" target="_blank" rel="noopener"><?php echo h($name); ?></a></td>
          <td><?php echo $p['ok'] ? '<span class="badge ok">OK</span>' : '<span class="badge fail">FAIL</span>'; ?></td>
          <td><?php echo (int)$p['code']; ?></td>
          <td><?php echo (int)$p['ms']; ?> ms</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3 style="margin:16px 0 8px">Database Connections</h3>
  <table>
    <thead><tr><th>DB</th><th>Status</th><th>Version</th><th>NOW()</th><th>Error</th></tr></thead>
    <tbody>
      <?php foreach ($dbs as $key => $label): $d = db_check($key); ?>
        <tr>
          <td><?php echo h($label); ?></td>
          <td><?php echo $d['ok'] ? '<span class="badge ok">UP</span>' : '<span class="badge fail">DOWN</span>'; ?></td>
          <td class="muted"><?php echo h((string)($d['info'] ?? '')); ?></td>
          <td class="muted"><?php echo h((string)($d['now'] ?? '')); ?></td>
          <td class="muted"><?php echo h($d['ok'] ? '' : (string)$d['err']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p class="muted" style="margin-top:12px">@ https://staff.vapeshed.co.nz</p>
</body>
</html>
