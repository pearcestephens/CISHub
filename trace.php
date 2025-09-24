<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/trace.php
 * Purpose: End-to-end trace viewer (from UI action through queue to Vend API) by trace_id
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 * Notes:
 * - Looks for a trace ID across known log files under this service's logs/ directory.
 * - Supports JSONL logs and plain-text; renders a timeline with severity badges.
 */

// Config
$serviceRoot = __DIR__;
$logDir = $serviceRoot . '/logs';
$logFiles = [
    'trace.jsonl',
    'trace.log',
    'request.log',
    'vend-api.log',
    'run-jobs.log',
    'schedule-pulls.log',
    'reap-stale.log',
    'worker.log',
    'reconciler.log',
];

// Helpers
function h(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function parseTime(?string $s): ?int {
    if (!$s) return null;
    // Try ISO 8601
    $ts = strtotime($s);
    return $ts !== false ? $ts : null;
}
function classify(string $line, array $obj = null): string {
    $s = strtolower($line);
    if ($obj && isset($obj['level'])) {
        $lv = strtolower((string)$obj['level']);
        if (in_array($lv, ['error','err','fatal','critical'], true)) return 'danger';
        if (in_array($lv, ['warn','warning'], true)) return 'warning';
        if (in_array($lv, ['info','notice','debug','ok','success'], true)) return 'success';
    }
    if (strpos($s, 'error') !== false || strpos($s, 'exception') !== false || strpos($s, 'fatal') !== false) return 'danger';
    if (strpos($s, 'warn') !== false || strpos($s, 'retry') !== false) return 'warning';
    if (strpos($s, 'success') !== false || strpos($s, 'ok') !== false || strpos($s, '200') !== false) return 'success';
    return 'secondary';
}
function stageFromFile(string $fname): string {
    $map = [
        'request' => 'UI/HTTP Intake',
        'trace' => 'Trace',
        'run-jobs' => 'Worker: Run Jobs',
        'schedule-pulls' => 'Scheduler',
        'reap-stale' => 'Reaper',
        'vend-api' => 'Vend API',
        'worker' => 'Legacy Worker',
        'reconciler' => 'Legacy Reconciler',
    ];
    foreach ($map as $k => $v) { if (stripos($fname, $k) !== false) return $v; }
    return $fname;
}

$trace = trim((string)($_GET['trace'] ?? ''));
$limitKB = max(64, (int)($_GET['limit_kb'] ?? 512)); // per file read cap
$sinceMin = max(0, (int)($_GET['since_min'] ?? 0));
$sinceTs = $sinceMin > 0 ? time() - ($sinceMin * 60) : null;

$pageTitle = 'Trace Viewer';
require __DIR__ . '/partials/header.php';
?>
<div class="container" data-autorefresh="0">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 m-0">Trace Viewer</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php">Lightspeed UI</a>
      <a class="btn btn-outline-secondary btn-sm" href="https://staff.vapeshed.co.nz/assets/services/queue/dashboard.php">Cron Dashboard</a>
    </div>
  </div>

  <form method="get" class="row g-2 align-items-end mb-3" autocomplete="off">
    <div class="col-md-5">
      <label class="form-label">Trace ID</label>
      <input type="text" class="form-control" name="trace" value="<?= h($trace) ?>" placeholder="enter trace id e.g. 9f2c..." />
    </div>
    <div class="col-md-3">
      <label class="form-label">Since (minutes)</label>
      <input type="number" min="0" class="form-control" name="since_min" value="<?= (int)$sinceMin ?>" />
    </div>
    <div class="col-md-2">
      <label class="form-label">Per-file cap (KB)</label>
      <input type="number" min="64" class="form-control" name="limit_kb" value="<?= (int)$limitKB ?>" />
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-primary w-100">Search</button>
    </div>
  </form>

  <?php if ($trace === ''): ?>
    <div class="alert alert-info">Enter a trace ID to search logs for an end-to-end timeline.</div>
  <?php else: ?>
    <?php
    $results = [];
    foreach ($logFiles as $name) {
        $path = $logDir . '/' . $name;
        if (!is_file($path) || !is_readable($path)) continue;
        $size = filesize($path) ?: 0;
        $fh = fopen($path, 'r'); if (!$fh) continue;
        // Seek near end to limit read volume
        $cap = $limitKB * 1024; $start = max(0, $size - $cap);
        if ($start > 0) fseek($fh, $start);
        if ($start > 0) fgets($fh); // align to next line
        while (($line = fgets($fh)) !== false) {
            if ($trace !== '' && stripos($line, $trace) === false) continue;
            $obj = null; $ts = null; $msg = null; $stage = stageFromFile($name);
            $lineTrim = trim($line);
            if ($lineTrim === '') continue;
            $first = $lineTrim[0] ?? '';
            if ($first === '{' || $first === '[') {
                $obj = json_decode($lineTrim, true);
                if (is_array($obj)) {
                    $ts = isset($obj['ts']) ? parseTime((string)$obj['ts']) : ($obj['time'] ?? $obj['timestamp'] ?? null);
                    if (is_numeric($ts)) $ts = (int)$ts; else $ts = parseTime((string)$ts);
                    $msg = (string)($obj['msg'] ?? $obj['message'] ?? $obj['event'] ?? '[json]');
                    $stage = (string)($obj['stage'] ?? $stage);
                }
            }
            if ($ts === null) {
                // Try to parse timestamp-like prefix
                if (preg_match('/^(\d{4}-\d{2}-\d{2}[^ ]*)/', $lineTrim, $m)) { $ts = parseTime($m[1]); }
            }
            $severity = classify($lineTrim, $obj);
            $results[] = [
                'file' => $name,
                'stage'=> $stage,
                'ts'   => $ts,
                'line' => $lineTrim,
                'obj'  => $obj,
                'sev'  => $severity,
            ];
        }
        fclose($fh);
    }
    usort($results, function($a,$b){ $ta=$a['ts']??0; $tb=$b['ts']??0; if($ta===$tb) return 0; return $ta<$tb?-1:1; });
    ?>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Trace Timeline</span>
        <span class="text-muted small">Matches: <?= count($results) ?></span>
      </div>
      <div class="card-body">
        <?php if (empty($results)): ?>
          <div class="alert alert-warning mb-0">No matches found in logs/ for trace: <strong class="kv"><?= h($trace) ?></strong>. Try increasing the per-file cap or time window.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr><th style="width:16%">Time (UTC)</th><th style="width:18%">Stage</th><th>Message</th><th style="width:22%">Source</th></tr>
              </thead>
              <tbody>
              <?php foreach ($results as $r): $badge = $r['sev']; $t = $r['ts'] ? gmdate('Y-m-d H:i:s', (int)$r['ts']) : '—'; ?>
                <tr>
                  <td class="kv"><span class="badge bg-<?= h($badge) ?>">&nbsp;</span> <?= h($t) ?></td>
                  <td class="kv"><?= h($r['stage']) ?></td>
                  <td class="kv" style="white-space:pre-wrap;">
                    <?php if (is_array($r['obj'])): ?>
                      <?php
                      $msg = (string)($r['obj']['msg'] ?? $r['obj']['message'] ?? '');
                      $code = $r['obj']['code'] ?? null; $status = $r['obj']['status'] ?? null;
                      if ($msg !== '') echo h($msg) . ' ';
                      if ($status !== null) echo '<span class="text-muted">status=' . h((string)$status) . '</span> ';
                      if ($code !== null) echo '<span class="text-muted">code=' . h((string)$code) . '</span>';
                      ?>
                      <details class="mt-1">
                        <summary class="small">JSON</summary>
                        <pre class="kv small"><?= h(json_encode($r['obj'], JSON_PRETTY_PRINT)) ?></pre>
                      </details>
                    <?php else: ?>
                      <?= h($r['line']) ?>
                    <?php endif; ?>
                  </td>
                  <td class="kv">logs/<?= h($r['file']) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <p class="text-muted mt-3">@ https://staff.vapeshed.co.nz</p>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
