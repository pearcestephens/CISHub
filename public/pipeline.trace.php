<?php
declare(strict_types=1);
/**
 * File: assets/services/queue/public/pipeline.trace.php
 * Purpose: Visual Pipeline Trace viewer for Quick Qty and other jobs by trace_id or job_id
 * Author: GitHub Copilot
 * Last Modified: 2025-09-22
 * Dependencies: partials/header.php, partials/footer.php, assets/js/pipeline-trace.js, assets/css/dashboard.css
 */

$pageTitle = 'Pipeline Trace';
require __DIR__ . '/../partials/header.php';

$trace = isset($_GET['trace']) ? trim((string)$_GET['trace']) : '';
$jobId = isset($_GET['job']) ? (int)$_GET['job'] : 0;
?>
<div class="py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Pipeline Trace</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="/assets/services/queue/dashboard.php">Queue Dashboard</a>
      <a class="btn btn-outline-secondary btn-sm" href="/assets/services/queue/trace.php">Raw Trace Logs</a>
    </div>
  </div>

  <form class="row g-2 align-items-end mb-3" method="get" id="traceForm">
    <div class="col-md-5">
      <label class="form-label">Trace ID</label>
      <input class="form-control" type="text" name="trace" value="<?= htmlspecialchars($trace, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="auto-filled after submit" />
    </div>
    <div class="col-md-3">
      <label class="form-label">Job ID</label>
      <input class="form-control" type="number" name="job" value="<?= (int)$jobId ?>" />
    </div>
    <div class="col-md-2">
      <label class="form-label">Auto-refresh</label>
      <select class="form-select" name="refresh">
        <option value="0">Off</option>
        <option value="2">2s</option>
        <option value="5" selected>5s</option>
        <option value="10">10s</option>
      </select>
    </div>
    <div class="col-md-2">
      <button class="btn btn-primary w-100" type="submit">Load</button>
    </div>
  </form>

  <div id="traceSummary" class="mb-2 text-muted small"></div>

  <div id="pipelineViz" class="mb-3"></div>

  <div class="card">
    <div class="card-header">Checkpoints</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" id="traceTable">
          <thead>
            <tr><th style="width:18%">Time</th><th style="width:18%">Stage</th><th>Message</th><th style="width:14%">Source</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="/assets/services/queue/assets/js/pipeline-trace.js" defer></script>
<?php require __DIR__ . '/../partials/footer.php'; ?>
