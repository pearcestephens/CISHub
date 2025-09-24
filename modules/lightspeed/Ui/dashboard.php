<?php
declare(strict_types=1);

/**
 * File: assets/services/queue/modules/lightspeed/Ui/dashboard.php
 * Purpose: Operational dashboard for Lightspeed queue/webhook service (standalone, read-only by default)
 * Author: GitHub Copilot
 * Last Modified: 2025-09-21
 * Links:
 * - Dashboard (this): https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php
 * - Top-level dashboard: https://staff.vapeshed.co.nz/assets/services/queue/dashboard.php
 * - Migrations: https://staff.vapeshed.co.nz/assets/services/queue/migrations.php
 */
//
// Do NOT start a PHP session here; this UI is standalone and must not share session with staff login

use Queue\Config;
use Queue\FeatureFlags;
require_once __DIR__ . '/../../../src/Config.php';
require_once __DIR__ . '/../../../src/FeatureFlags.php';

// ------------------------ Helpers ------------------------
function h(?string $v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function mask_tail(?string $v, int $tail = 4): string {
    if ($v === null || $v === '') return '';
    $len = strlen($v); if ($len <= $tail) return str_repeat('•', $len);
    return str_repeat('•', max(0, $len-$tail)) . substr($v, -$tail);
}
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

// Safe Config getter
$cfg_db_error = null;
function cfg(string $key, $default = null) {
  global $cfg_db_error;
  try { return Config::get($key, $default); }
  catch (\Throwable $e) { if ($cfg_db_error === null) { $cfg_db_error = $e->getMessage(); } return $default; }
}

// Base URL for links/embeds
$base = 'https://staff.vapeshed.co.nz/assets/services/queue';

// Authorization: keep read-only for now (no session, no token gating for edit)
$can_edit = false;

// ------------------------ Read settings ------------------------
$cfg_admin_token        = (string)(cfg('ADMIN_BEARER_TOKEN','') ?? '');
$cfg_webhook_secret     = (string)(cfg('vend_webhook_secret','') ?? '');
$cfg_wh_tol             = (int)(cfg('vend.webhook.tolerance_s', 300) ?? 300);
$cfg_wh_hmac_req        = (bool)(cfg('vend.webhook.hmac_required', true) ?? true);
$cfg_wh_replay_m        = (int)(cfg('vend.webhook.replay_window_m', 60) ?? 60);
$cfg_rate_per_min       = (int)(cfg('vend.http.rate_limit_per_min', 120) ?? 120);
$cfg_q_default_cap      = (int)(cfg('vend.queue.max_concurrency.default', 1) ?? 1);
$cfg_dash_autorefresh   = (int)(cfg('dash.autorefresh.default_s', 0) ?? 0);
$cfg_dash_wh_limit      = (int)(cfg('dash.webhooks.limit', 25) ?? 25);
$cfg_mig_allow_apply    = (bool)(cfg('migrations.allow_apply', false) ?? false);
$cfg_prefix_dry_default = (bool)(cfg('prefix_migrate.dry_default', true) ?? true);
// Feature flags
$cfg_trace_viewer_enabled = (bool)(cfg('queue.ui.trace_viewer_enabled', false) ?? false);
// Core feature flags
$ff_kill_all            = FeatureFlags::killAll();
$ff_webhook_enabled     = FeatureFlags::webhookEnabled();
$ff_runner_enabled      = FeatureFlags::runnerEnabled();
$ff_http_enabled        = FeatureFlags::httpEnabled();
$ff_fanout_enabled      = FeatureFlags::fanoutEnabled();

// POST save (disabled in read-only)
$notice=null; $error=null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'save_settings') {
    $error = 'Read-only mode. Settings disabled.';
  } elseif ($action === 'toggle_feature') {
    // Secure token-gated feature toggle (no session). Requires ADMIN_BEARER_TOKEN to be configured.
    $token = (string)($_POST['admin_token'] ?? '');
    if (!$cfg_admin_token) {
      $error = 'Admin token not configured. Cannot change feature toggles.';
    } elseif (!hash_equals($cfg_admin_token, $token)) {
      $error = 'Invalid admin token.';
    } else {
      $feature = (string)($_POST['feature'] ?? '');
      $enabled = isset($_POST['enabled']) && (string)$_POST['enabled'] === '1';
      try {
        if ($feature === 'trace_viewer') {
          \Queue\Config::set('queue.ui.trace_viewer_enabled', $enabled);
          $cfg_trace_viewer_enabled = $enabled;
          $notice = 'Trace Viewer visibility updated.';
        } elseif ($feature === 'kill_all') {
          \Queue\Config::set('queue.kill_all', $enabled);
          $ff_kill_all = $enabled; $notice = 'Kill switch updated.';
        } elseif ($feature === 'webhook.enabled') {
          \Queue\Config::set('webhook.enabled', $enabled);
          $ff_webhook_enabled = $enabled; $notice = 'Webhook enable updated.';
        } elseif ($feature === 'queue.runner.enabled') {
          \Queue\Config::set('queue.runner.enabled', $enabled);
          $ff_runner_enabled = $enabled; $notice = 'Runner enable updated.';
        } elseif ($feature === 'vend.http.enabled') {
          \Queue\Config::set('vend.http.enabled', $enabled);
          $ff_http_enabled = $enabled; $notice = 'HTTP enable updated.';
        } elseif ($feature === 'webhook.fanout.enabled') {
          \Queue\Config::set('webhook.fanout.enabled', $enabled);
          $ff_fanout_enabled = $enabled; $notice = 'Fanout enable updated.';
        } else {
          $error = 'Unknown feature.';
        }
      } catch (\Throwable $e) {
        $error = 'Failed to update setting: ' . $e->getMessage();
      }
    }
  }
}
?>
<?php $pageTitle = 'Lightspeed Queue Dashboard'; require __DIR__ . '/../../../partials/header.php'; ?>
<div class="container" data-autorefresh="<?= (int)$cfg_dash_autorefresh ?>">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
      <h1 class="h3 m-0">Lightspeed Queue Dashboard</h1>
  <a class="btn btn-outline-primary btn-sm" href="https://staff.vapeshed.co.nz/assets/services/queue/dashboard.php">Full Dashboard</a>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="https://staff.vapeshed.co.nz/assets/services/queue/migrations.php">SQL Migrations</a>
    </div>
  </div>

  <?php if ($notice): ?><div class="alert alert-success" role="alert"><?=h($notice)?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger" role="alert"><?=h($error)?></div><?php endif; ?>
  <?php if (!empty($cfg_db_error)): ?><div class="alert alert-warning" role="alert">Config backend warning: <?=h($cfg_db_error)?></div><?php endif; ?>

  <ul class="nav nav-tabs" id="tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="webhooks-tab" data-bs-toggle="tab" data-bs-target="#webhooks" type="button" role="tab">Webhooks</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="queue-tab" data-bs-toggle="tab" data-bs-target="#queue" type="button" role="tab">Queue</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tools-tab" data-bs-toggle="tab" data-bs-target="#tools" type="button" role="tab">Tools</button>
    </li>
    <?php if ($can_edit): ?>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="settings-tab" data-bs-toggle="tab" data-bs-target="#settings" type="button" role="tab">Settings</button>
      </li>
    <?php endif; ?>
  </ul>
  <div class="tab-content border border-top-0 p-3" id="tabsContent">
    <div class="tab-pane fade show active" id="overview" role="tabpanel" aria-labelledby="overview-tab">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="card">
            <div class="card-header">Service Health</div>
            <div class="card-body">
              <?php $endpoints = ['Health' => $base.'/health.php','Metrics' => $base.'/metrics.php','Webhook Health' => $base.'/webhook.health.php']; ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>Endpoint</th><th>Status</th><th>Code</th><th>Latency</th></tr></thead>
                  <tbody>
                  <?php foreach ($endpoints as $name => $url): $p = probe_url($url); ?>
                  <tr>
                    <td><a href="<?=h($url)?>" target="_blank" rel="noopener"><?=h($name)?></a></td>
                    <td><?= $p['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Fail</span>' ?></td>
                    <td class="kv"><?= (int)$p['code'] ?></td>
                    <td class="kv"><?= (int)$p['ms'] ?> ms</td>
                  </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card">
            <div class="card-header">Current Config Snapshot</div>
            <div class="card-body">
              <dl class="row mb-0 kv">
                <dt class="col-sm-6">Admin Token</dt><dd class="col-sm-6"><?= $cfg_admin_token ? 'set ('.h(mask_tail($cfg_admin_token)).')' : '<span class="text-muted">not set</span>' ?></dd>
                <dt class="col-sm-6">Webhook Secret</dt><dd class="col-sm-6"><?= $cfg_webhook_secret ? 'set ('.h(mask_tail($cfg_webhook_secret)).')' : '<span class="text-muted">not set</span>' ?></dd>
                <dt class="col-sm-6">Webhook Tolerance</dt><dd class="col-sm-6"><?= (int)$cfg_wh_tol ?> s</dd>
                <dt class="col-sm-6">HMAC Required</dt><dd class="col-sm-6"><?= $cfg_wh_hmac_req ? 'true' : 'false' ?></dd>
                <dt class="col-sm-6">Replay Window</dt><dd class="col-sm-6"><?= (int)$cfg_wh_replay_m ?> min</dd>
                <dt class="col-sm-6">HTTP Rate Limit</dt><dd class="col-sm-6"><?= (int)$cfg_rate_per_min ?>/min</dd>
                <dt class="col-sm-6">Queue Default Concurrency</dt><dd class="col-sm-6"><?= (int)$cfg_q_default_cap ?></dd>
                <dt class="col-sm-6">Auto-Refresh</dt><dd class="col-sm-6"><?= (int)$cfg_dash_autorefresh ?> s</dd>
                <dt class="col-sm-6">Webhooks Limit</dt><dd class="col-sm-6"><?= (int)$cfg_dash_wh_limit ?></dd>
                <dt class="col-sm-6">Allow SQL Apply</dt><dd class="col-sm-6"><?= $cfg_mig_allow_apply ? '<span class="text-danger">ENABLED</span>' : 'disabled' ?></dd>
                <dt class="col-sm-6">Prefix Migrate Default</dt><dd class="col-sm-6"><?= $cfg_prefix_dry_default ? 'dry-run' : 'apply' ?></dd>
              </dl>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="webhooks" role="tabpanel" aria-labelledby="webhooks-tab">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Webhook Statistics</span>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/webhook.stats.php'); ?>" target="_blank" rel="noopener" title="View raw JSON">Raw</a>
              <button class="btn btn-outline-primary btn-sm" type="button" data-refresh="#wh-stats">Refresh</button>
            </div>
          </div><div class="card-body">
            <div id="wh-stats" class="kv" data-endpoint="<?php echo h($base.'/webhook.stats.php'); ?>">
              <div class="text-muted">Loading…</div>
            </div>
          </div></div>
        </div>
        <div class="col-lg-6">
          <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Recent Webhook Events</span>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/webhook.events.php'); ?>" target="_blank" rel="noopener" title="View raw JSON">Raw</a>
              <button class="btn btn-outline-primary btn-sm" type="button" data-refresh="#wh-events">Refresh</button>
            </div>
          </div><div class="card-body">
            <div id="wh-events" class="kv" data-endpoint="<?php echo h($base.'/webhook.events.php'); ?>">
              <div class="text-muted">Loading…</div>
            </div>
          </div></div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="queue" role="tabpanel" aria-labelledby="queue-tab">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Queue Status</span>
            <div class="d-flex gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/queue.status.php'); ?>" target="_blank" rel="noopener" title="View raw JSON">Raw</a>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.pipeline.php'); ?>" target="_blank" rel="noopener">Pipeline</a>
              <button class="btn btn-outline-primary btn-sm" type="button" data-refresh="#queue-status">Refresh</button>
            </div>
          </div><div class="card-body">
            <div id="queue-status" class="kv" data-endpoint="<?php echo h($base.'/queue.status.php'); ?>">
              <div class="text-muted">Loading…</div>
            </div>
          </div></div>
        </div>
        <div class="col-lg-6">
          <div class="card"><div class="card-header">Dead Letter Queue</div><div class="card-body">
            <p>
              <a class="btn btn-outline-danger btn-sm" href="<?php echo h($base.'/dlq.purge.php'); ?>" target="_blank" rel="noopener">Purge DLQ</a>
              <a class="btn btn-outline-warning btn-sm" href="<?php echo h($base.'/dlq.redrive.php'); ?>" target="_blank" rel="noopener">Redrive DLQ</a>
            </p>
            <p class="text-muted">Use with care; actions may require admin token.</p>
          </div></div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tools" role="tabpanel" aria-labelledby="tools-tab">
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card"><div class="card-header">SQL Migrations</div><div class="card-body">
            <p><a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/migrations.php'); ?>" target="_blank" rel="noopener">Open Migrations Tool</a></p>
            <p class="text-muted">Place .sql files in the pending folder shown in the tool. Apply is gated for safety.</p>
          </div></div>
        </div>
        <div class="col-lg-6">
          <div class="card"><div class="card-header">Utilities & Stats</div><div class="card-body">
            <div class="mb-2 d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/verify.php'); ?>" target="_blank" rel="noopener">Verify (raw)</a>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/output.php'); ?>" target="_blank" rel="noopener">Last Output (raw)</a>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.pipeline.php'); ?>" target="_blank" rel="noopener">Pipeline (html)</a>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/monitor.php'); ?>" target="_blank" rel="noopener">Monitor</a>
              <a class="btn btn-outline-success btn-sm" href="<?php echo h($base.'/simulate.php'); ?>" target="_blank" rel="noopener" title="Generate a synthetic trace">Simulate Trace</a>
              <?php if ($cfg_trace_viewer_enabled): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/trace.php'); ?>" target="_blank" rel="noopener">Trace Viewer</a>
              <?php endif; ?>
              <button class="btn btn-outline-primary btn-sm" type="button" data-refresh="#stats-events">Refresh Events</button>
              <button class="btn btn-outline-primary btn-sm" type="button" data-refresh="#stats-transfers">Refresh Transfers</button>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <div class="border rounded p-2">
                  <div class="d-flex justify-content-between align-items-center mb-2"><strong>Event Counts</strong>
                    <a class="btn btn-link btn-sm" href="<?php echo h($base.'/stats.events.php'); ?>" target="_blank" rel="noopener" title="Raw JSON">Raw</a>
                  </div>
                  <div id="stats-events" class="kv" data-endpoint="<?php echo h($base.'/stats.events.php'); ?>">
                    <div class="text-muted">Loading…</div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="border rounded p-2">
                  <div class="d-flex justify-content-between align-items-center mb-2"><strong>Recent Transfers</strong>
                    <a class="btn btn-link btn-sm" href="<?php echo h($base.'/stats.transfers.php'); ?>" target="_blank" rel="noopener" title="Raw JSON">Raw</a>
                  </div>
                  <div id="stats-transfers" class="kv" data-endpoint="<?php echo h($base.'/stats.transfers.php'); ?>">
                    <div class="text-muted">Loading…</div>
                  </div>
                </div>
              </div>
            </div>
          </div></div>
        </div>
      </div>
      <div class="row g-3 mt-1">
        <div class="col-lg-6">
          <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Feature Toggles</span>
            <span class="text-muted small">Token-gated</span>
          </div><div class="card-body">
            <form method="post" class="row gy-2 align-items-center" autocomplete="off">
              <input type="hidden" name="action" value="toggle_feature" />
              <div class="col-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="ff-kill" name="kill_all" value="1" <?= $ff_kill_all ? 'checked' : '' ?> disabled />
                  <label class="form-check-label" for="ff-kill">Global Kill Switch (read-only here)</label>
                </div>
              </div>
              <div class="col-md-6">
                <label class="form-label">Toggle</label>
                <select class="form-select" name="feature">
                  <option value="trace_viewer">UI: Show Trace Viewer</option>
                  <option value="kill_all" <?= $ff_kill_all ? 'selected' : '' ?>>Global Kill Switch</option>
                  <option value="webhook.enabled" <?= $ff_webhook_enabled ? 'selected' : '' ?>>Webhook Enabled</option>
                  <option value="queue.runner.enabled" <?= $ff_runner_enabled ? 'selected' : '' ?>>Runner Enabled</option>
                  <option value="vend.http.enabled" <?= $ff_http_enabled ? 'selected' : '' ?>>Outbound HTTP Enabled</option>
                  <option value="webhook.fanout.enabled" <?= $ff_fanout_enabled ? 'selected' : '' ?>>Webhook Fanout Enabled</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Admin Token</label>
                <input type="password" class="form-control" name="admin_token" placeholder="required" />
              </div>
              <div class="col-12">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="ff-enabled" name="enabled" value="1" checked />
                  <label class="form-check-label" for="ff-enabled">Set selected feature to Enabled</label>
                </div>
              </div>
              <div class="col-md-6 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">Save</button>
              </div>
              <div class="col-12"><div class="form-text">Configure token via ADMIN_BEARER_TOKEN. Changes persist in configuration.</div></div>
            </form>
          </div></div>
        </div>
      </div>
    </div>

    <?php if ($can_edit): ?>
    <div class="tab-pane fade" id="settings" role="tabpanel" aria-labelledby="settings-tab">
      <form method="post" autocomplete="off">
        <input type="hidden" name="action" value="save_settings" />
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card"><div class="card-header">Secrets</div><div class="card-body">
              <div class="mb-3"><label class="form-label">Admin Bearer Token</label><input type="password" class="form-control" name="ADMIN_BEARER_TOKEN" value="<?=h($cfg_admin_token)?>" placeholder="leave blank to clear" /><div class="form-text mono">Used for admin endpoints. @ https://staff.vapeshed.co.nz</div></div>
              <div class="mb-3"><label class="form-label">Vend Webhook Secret</label><input type="password" class="form-control" name="vend_webhook_secret" value="<?=h($cfg_webhook_secret)?>" /><div class="form-text mono">HMAC secret for webhook verification. @ https://staff.vapeshed.co.nz</div></div>
            </div></div>
          </div>
          <div class="col-lg-6">
            <div class="card"><div class="card-header">Webhook</div><div class="card-body">
              <div class="row g-2">
                <div class="col-sm-6"><label class="form-label">Tolerance (seconds)</label><input type="number" min="0" class="form-control" name="vend_webhook_tolerance_s" value="<?= (int)$cfg_wh_tol ?>" /></div>
                <div class="col-sm-6"><label class="form-label">Replay Window (minutes)</label><input type="number" min="0" class="form-control" name="vend_webhook_replay_window_m" value="<?= (int)$cfg_wh_replay_m ?>" /></div>
              </div>
              <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="whhmac" name="vend_webhook_hmac_required" <?= $cfg_wh_hmac_req ? 'checked' : '' ?> /><label class="form-check-label" for="whhmac">Require HMAC</label></div>
            </div></div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-lg-6">
            <div class="card"><div class="card-header">HTTP & Queue</div><div class="card-body">
              <div class="row g-2">
                <div class="col-sm-6"><label class="form-label">HTTP Rate Limit (per minute)</label><input type="number" min="1" class="form-control" name="vend_http_rate_limit_per_min" value="<?= (int)$cfg_rate_per_min ?>" /></div>
                <div class="col-sm-6"><label class="form-label">Default Queue Concurrency</label><input type="number" min="0" class="form-control" name="vend_queue_max_concurrency_default" value="<?= (int)$cfg_q_default_cap ?>" /></div>
              </div>
            </div></div>
          </div>
          <div class="col-lg-6">
            <div class="card"><div class="card-header">Dashboard Defaults</div><div class="card-body">
              <div class="row g-2">
                <div class="col-sm-6"><label class="form-label">Auto-Refresh (seconds)</label><input type="number" min="0" class="form-control" name="dash_autorefresh_default_s" value="<?= (int)$cfg_dash_autorefresh ?>" /></div>
                <div class="col-sm-6"><label class="form-label">Webhooks List Limit</label><input type="number" min="1" class="form-control" name="dash_webhooks_limit" value="<?= (int)$cfg_dash_wh_limit ?>" /></div>
              </div>
            </div></div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-lg-6">
            <div class="card"><div class="card-header">Safety Toggles</div><div class="card-body">
              <div class="form-check"><input class="form-check-input" type="checkbox" id="sqlapply" name="migrations_allow_apply" <?= $cfg_mig_allow_apply ? 'checked' : '' ?> /><label class="form-check-label" for="sqlapply">Allow SQL migrations apply (risky)</label></div>
              <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="prefdr" name="prefix_migrate_dry_default" <?= $cfg_prefix_dry_default ? 'checked' : '' ?> /><label class="form-check-label" for="prefdr">Prefix migrate defaults to dry-run</label></div>
            </div></div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-2"><button type="submit" class="btn btn-primary">Save Settings</button><a class="btn btn-outline-secondary" href="https://staff.vapeshed.co.nz/assets/services/queue/modules/lightspeed/Ui/dashboard.php">Reload</a></div>
      </form>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$can_edit): ?>
    <div class="alert alert-info mt-3" role="alert">
      Read-only mode. Settings hidden for now.
    </div>
  <?php endif; ?>
  <p class="text-muted mt-4">@ https://staff.vapeshed.co.nz</p>
</div>
<?php require __DIR__ . '/../../../partials/footer.php'; ?>