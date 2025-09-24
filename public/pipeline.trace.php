<?php
declare(strict_types=1);
/**
 * Pretty Trace Viewer (same endpoint/params, better UX)
 * Query: ?trace=<id>&job=<id>&refresh=0|2|5|10
 */
$pageTitle = 'Pipeline Trace';
require __DIR__ . '/../partials/header.php';

$trace = isset($_GET['trace']) ? trim((string)$_GET['trace']) : '';
$jobId = isset($_GET['job'])   ? (int)$_GET['job'] : 0;
$refresh = isset($_GET['refresh']) ? (int)$_GET['refresh'] : 5;
if (!in_array($refresh, [0,2,5,10], true)) $refresh = 5;
?>
<div class="py-3" data-autorefresh="<?= (int)$refresh ?>">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Pipeline Trace</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="/assets/services/queue/dashboard.php">Queue Dashboard</a>
      <a class="btn btn-outline-secondary btn-sm" href="/assets/services/queue/trace.php">Raw Trace Logs</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get" id="traceForm" autocomplete="off">
    <div class="col-md-5">
      <label class="form-label">Trace ID</label>
      <input class="form-control" type="text" name="trace" value="<?= htmlspecialchars($trace,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?>" placeholder="auto-filled after submit" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Job ID</label>
      <input class="form-control" type="number" name="job" value="<?= (int)$jobId ?>" />
    </div>
    <div class="col-md-2">
      <label class="form-label">Auto-refresh</label>
      <select class="form-select" name="refresh">
        <option value="0"  <?= $refresh===0?'selected':'' ?>>Off</option>
        <option value="2"  <?= $refresh===2?'selected':'' ?>>2s</option>
        <option value="5"  <?= $refresh===5?'selected':'' ?>>5s</option>
        <option value="10" <?= $refresh===10?'selected':'' ?>>10s</option>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100" type="submit">Load</button>
    </div>
  </form>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">Summary</div>
        <div class="card-body">
          <div id="traceSummary" class="text-muted small">Enter a trace id or job id and click Load.</div>
          <div class="mt-3">
            <canvas id="traceSpark" class="w-100" height="60"></canvas>
          </div>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Timeline</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="traceTable">
              <thead>
              <tr><th style="width:18%">Time (UTC)</th><th style="width:18%">Stage</th><th>Message</th><th style="width:16%">Source</th></tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="/assets/services/queue/assets/js/pipeline-trace.js" defer></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
