<?php declare(strict_types=1);
$title='Lightspeed Queue — Overview'; $active='overview'; require __DIR__.'/header.php';
$base = SVC_BASE;
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
?>
<div class="row g-3">
  <div class="col-md-6">
    <div class="card"><div class="card-header">Service Health</div><div class="card-body">
      <?php $endpoints = ['Health' => $base.'/health.php','Metrics' => $base.'/metrics.php','Webhook Health' => $base.'/webhook.health.php']; ?>
      <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Endpoint</th><th>Status</th><th>Code</th><th>Latency</th></tr></thead><tbody>
      <?php foreach ($endpoints as $name => $url): $p = probe_url($url); ?>
        <tr>
          <td><a href="<?php echo h($url); ?>" target="_blank" rel="noopener"><?php echo h($name); ?></a></td>
          <td><?php echo $p['ok'] ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-danger">Fail</span>'; ?></td>
          <td class="kv"><?php echo (int)$p['code']; ?></td>
          <td class="kv"><?php echo (int)$p['ms']; ?> ms</td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-header">Current Config Snapshot</div><div class="card-body">
      <dl class="row mb-0 kv">
        <dt class="col-sm-6">Admin Token</dt><dd class="col-sm-6"><?php echo $cfg_admin_token ? 'set ('.h(mask_tail($cfg_admin_token)).')' : '<span class="text-muted">not set</span>'; ?></dd>
        <dt class="col-sm-6">Webhook Secret</dt><dd class="col-sm-6"><?php echo $cfg_webhook_secret ? 'set ('.h(mask_tail($cfg_webhook_secret)).')' : '<span class="text-muted">not set</span>'; ?></dd>
        <dt class="col-sm-6">Webhook Tolerance</dt><dd class="col-sm-6"><?php echo (int)$cfg_wh_tol; ?> s</dd>
        <dt class="col-sm-6">HMAC Required</dt><dd class="col-sm-6"><?php echo $cfg_wh_hmac_req ? 'true' : 'false'; ?></dd>
        <dt class="col-sm-6">Replay Window</dt><dd class="col-sm-6"><?php echo (int)$cfg_wh_replay_m; ?> min</dd>
        <dt class="col-sm-6">HTTP Rate Limit</dt><dd class="col-sm-6"><?php echo (int)$cfg_rate_per_min; ?>/min</dd>
        <dt class="col-sm-6">Queue Default Concurrency</dt><dd class="col-sm-6"><?php echo (int)$cfg_q_default_cap; ?></dd>
        <dt class="col-sm-6">Auto-Refresh</dt><dd class="col-sm-6"><?php echo (int)$cfg_dash_autorefresh; ?> s</dd>
        <dt class="col-sm-6">Webhooks Limit</dt><dd class="col-sm-6"><?php echo (int)$cfg_dash_wh_limit; ?></dd>
        <dt class="col-sm-6">Allow SQL Apply</dt><dd class="col-sm-6"><?php echo $cfg_mig_allow_apply ? '<span class="text-danger">ENABLED</span>' : 'disabled'; ?></dd>
        <dt class="col-sm-6">Prefix Migrate Default</dt><dd class="col-sm-6"><?php echo $cfg_prefix_dry_default ? 'dry-run' : 'apply'; ?></dd>
      </dl>
    </div></div>
  </div>
</div>
<div class="row g-3 mt-1">
  <div class="col-md-6">
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Runner & Watchdog</span>
      <a class="btn btn-link btn-sm" href="<?php echo h($base.'/worker.status.php'); ?>" target="_blank" rel="noopener">Raw Status</a>
    </div><div class="card-body">
      <div id="ov-runner" class="mb-2 small text-muted">Loading…</div>
      <div class="input-group input-group-sm mb-2">
        <span class="input-group-text">Token</span>
        <input type="password" class="form-control" id="ov-token" placeholder="Admin token (optional)">
        <button class="btn btn-outline-danger" type="button" id="ov-watchdog">Run Watchdog</button>
      </div>
      <div id="ov-watchdog-res" class="small text-muted"></div>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-header">Quick Links</div><div class="card-body">
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/queue.status.php'); ?>" target="_blank" rel="noopener">Queue Status</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.pipeline.php'); ?>" target="_blank" rel="noopener">Pipeline</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/flags.php'); ?>" target="_blank" rel="noopener">Flags</a>
      </div>
    </div></div>
  </div>
</div>
<script>
(function(){
  const base='<?php echo h($base); ?>';
  const runnerEl=document.getElementById('ov-runner');
  const tok=document.getElementById('ov-token');
  const resEl=document.getElementById('ov-watchdog-res');
  async function refresh(){
    try{ const r=await fetch(base+'/worker.status.php',{cache:'no-store'}); const j=await r.json(); if(!r.ok) throw new Error('HTTP '+r.status);
      const f=j&&j.flags?j.flags:{}; const db=j&&j.db?j.db:{}; const ok=f['vend.queue.continuous.enabled'];
      runnerEl.innerHTML = 'Continuous: '+(ok?'<span class="badge bg-success">ON</span>':'<span class="badge bg-secondary">OFF</span>')+
        ' · pending <span class="kv">'+(db.pending||0)+'</span>, working <span class="kv">'+(db.working||0)+'</span>, done1m <span class="kv">'+(db.done_last_minute||0)+'</span>';
    }catch(e){ runnerEl.textContent='Failed to load: '+(e&&e.message||e); }
  }
  function hdr(){ const t=tok.value.trim(); const h={'Accept':'application/json','Content-Type':'application/json'}; if(t) h['Authorization']='Bearer '+t; return h; }
  document.getElementById('ov-watchdog').addEventListener('click', async ()=>{
    resEl.textContent='Running…';
    try{ const r=await fetch(base+'/public/watchdog.php',{method:'POST', headers: hdr(), body: '{}' }); const j=await r.json(); if(!r.ok) throw new Error((j&&j.error&&j.error.message)||('HTTP '+r.status)); resEl.textContent='OK '+JSON.stringify(j.data||{}); refresh(); }catch(e){ resEl.textContent='Failed: '+(e&&e.message||e); }
  });
  refresh();
})();
</script>
<?php require __DIR__.'/footer.php';
