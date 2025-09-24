<?php declare(strict_types=1);
$title='Lightspeed Queue — Queue'; $active='queue'; require __DIR__.'/header.php';
$base = SVC_BASE;
?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header">Queue Status</div><div class="card-body">
      <p>
        <a class="btn btn-outline-primary btn-sm" href="<?php echo h($base.'/queue.status.php'); ?>" target="_blank" rel="noopener">Open Queue Status</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/queue_dashboard_complete.php'); ?>" target="_blank" rel="noopener">Complete Dashboard</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo h($base.'/stats.pipeline.php'); ?>" target="_blank" rel="noopener">Pipeline Lights</a>
      </p>
      <iframe src="<?php echo h($base.'/queue.status.php'); ?>" style="width:100%;height:360px;border:1px solid #dee2e6;border-radius:4px"></iframe>
    </div></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Runner Controls</span>
      <a class="btn btn-link btn-sm" href="<?php echo h($base.'/worker.status.php'); ?>" target="_blank" rel="noopener">Raw Status</a>
    </div><div class="card-body">
      <div class="mb-2">
        <label class="form-label">Admin Token (optional)</label>
        <input type="password" class="form-control form-control-sm" id="adm-token" placeholder="Bearer token for privileged actions">
      </div>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-outline-primary btn-sm" id="btn-cont-on">Enable Continuous</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-cont-off">Disable Continuous</button>
        <button type="button" class="btn btn-outline-warning btn-sm" id="btn-kick">Kick Runner</button>
        <button type="button" class="btn btn-outline-danger btn-sm" id="btn-watchdog">Run Watchdog</button>
      </div>
      <div id="runner-status" class="small text-muted">Loading status…</div>
      <hr />
      <div class="alert alert-info mb-2 p-2">
        Dead Letter actions below may purge or requeue failed jobs. Use with care.
      </div>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-danger btn-sm" href="<?php echo h($base.'/dlq.purge.php'); ?>" target="_blank" rel="noopener">Purge DLQ</a>
        <a class="btn btn-outline-warning btn-sm" href="<?php echo h($base.'/dlq.redrive.php'); ?>" target="_blank" rel="noopener">Redrive DLQ</a>
      </div>
      <p class="text-muted mt-2">Actions above may require admin token.</p>
      <script>
      (function(){
        const statusEl=document.getElementById('runner-status');
        const tokenInput=document.getElementById('adm-token');
        const base='<?php echo h($base); ?>';
        async function getStatus(){
          try{ const r=await fetch(base+'/worker.status.php',{cache:'no-store'}); const j=await r.json(); if(!r.ok){ throw new Error('HTTP '+r.status); }
            const f=j&&j.flags?j.flags:{}; const db=j&&j.db?j.db:{}; const w=j&&j.worker?j.worker:{};
            statusEl.innerHTML = '<div>queue.runner.enabled: <span class="kv">'+(f['queue.runner.enabled']? 'true':'false')+'</span></div>'+
              '<div>vend.queue.continuous.enabled: <span class="kv">'+(f['vend.queue.continuous.enabled']? 'true':'false')+'</span></div>'+
              '<div>pending: <span class="kv">'+(db.pending||0)+'</span>, working: <span class="kv">'+(db.working||0)+'</span>, done_last_minute: <span class="kv">'+(db.done_last_minute||0)+'</span></div>'+
              '<div>lock_age: <span class="kv">'+(w.lock_age_sec??'n/a')+'s</span>, log_age: <span class="kv">'+(w.log_age_sec??'n/a')+'s</span></div>';
          }catch(e){ statusEl.textContent='Failed to load status: '+(e&&e.message||e); }
        }
        function hdr(){ const t=tokenInput.value.trim(); const h={ 'Accept':'application/json','Content-Type':'application/json' }; if(t) h['Authorization']='Bearer '+t; return h; }
        async function post(url, body){ const r=await fetch(url,{ method:'POST', headers: hdr(), body: JSON.stringify(body||{}) }); const j=await r.json().catch(()=>null); if(!r.ok) throw new Error((j&&j.error&&j.error.message)||('HTTP '+r.status)); return j; }
        document.getElementById('btn-cont-on').addEventListener('click', ()=>{ post(base+'/public/runner.continuous.php',{on:true}).then(()=>getStatus()).catch(e=>alert('Enable failed: '+e.message)); });
        document.getElementById('btn-cont-off').addEventListener('click', ()=>{ post(base+'/public/runner.continuous.php',{on:false}).then(()=>getStatus()).catch(e=>alert('Disable failed: '+e.message)); });
        document.getElementById('btn-kick').addEventListener('click', ()=>{ post(base+'/public/runner.kick.php',{}).then(()=>{ alert('Runner kicked'); getStatus(); }).catch(e=>alert('Kick failed: '+e.message)); });
        document.getElementById('btn-watchdog').addEventListener('click', ()=>{ post(base+'/public/watchdog.php',{}).then(j=>{ alert('Watchdog: '+(j&&j.data?JSON.stringify(j.data):'ok')); getStatus(); }).catch(e=>alert('Watchdog failed: '+e.message)); });
        getStatus();
      })();
      </script>
    </div></div>
  </div>
</div>
<?php require __DIR__.'/footer.php';
