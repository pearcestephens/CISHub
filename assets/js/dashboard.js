// File: assets/services/queue/assets/js/dashboard.js
// Purpose: Shared client-side helpers (JSON render, copy command)
// Author: GitHub Copilot
// Last Modified: 2025-09-21

(function(){
  const MAX_ROWS = 50, MAX_COLS = 10, MAX_STR = 120;
  function esc(s){ const d=document.createElement('div'); d.textContent=String(s); return d.innerHTML; }
  function truncate(v){ const s=String(v ?? ''); return s.length>MAX_STR ? s.slice(0,MAX_STR)+'…' : s; }
  function isPlainObject(v){ return v && typeof v === 'object' && !Array.isArray(v); }
  function pickColumns(arr){ const cols=new Set(); for(let i=0;i<Math.min(arr.length,10);i++){ const row=arr[i]; if(isPlainObject(row)) Object.keys(row).forEach(k=>cols.add(k)); } return Array.from(cols).slice(0,MAX_COLS); }
  function renderKV(obj){ const dl=['<dl class="row mb-0 kv">']; Object.keys(obj).forEach(k=>{ const val=obj[k]; const display=isPlainObject(val)||Array.isArray(val)?JSON.stringify(val):val; dl.push(`<dt class="col-sm-5">${esc(k)}</dt><dd class="col-sm-7">${esc(truncate(display))}</dd>`); }); dl.push('</dl>'); return dl.join(''); }
  function renderTable(arr){ if(!Array.isArray(arr)||arr.length===0) return '<div class="text-muted">No data</div>'; const cols=pickColumns(arr); const out=[]; out.push('<div class="table-responsive"><table class="table table-sm table-striped align-middle"><thead><tr>'); cols.forEach(c=>out.push(`<th>${esc(c)}</th>`)); out.push('</tr></thead><tbody>'); for(let i=0;i<Math.min(arr.length,MAX_ROWS);i++){ const row=arr[i]; out.push('<tr>'); cols.forEach(c=>{ const v=row&&typeof row==='object'?row[c]:''; out.push(`<td class="kv">${esc(truncate(v))}</td>`); }); out.push('</tr>'); } out.push('</tbody></table></div>'); if(arr.length>MAX_ROWS) out.push(`<div class="text-muted small">Showing first ${MAX_ROWS} of ${arr.length} rows</div>`); return out.join(''); }
  async function load(el){ const url=el.getAttribute('data-endpoint'); if(!url) return; el.innerHTML='<div class="text-muted">Loading…</div>'; try { const res=await fetch(url,{ headers:{'Accept':'application/json'}, cache:'no-store' }); const text=await res.text(); let data; try{ data=JSON.parse(text); }catch(e){ data=null; } if(!res.ok) throw new Error(`HTTP ${res.status}`); if(data==null){ el.innerHTML=`<pre class="kv">${esc(text)}</pre>`; return; } if(Array.isArray(data)){ el.innerHTML=renderTable(data); } else if(isPlainObject(data)){ if(Array.isArray(data.items)) el.innerHTML=renderTable(data.items); else el.innerHTML=renderKV(data); } else { el.innerHTML=`<pre class="kv">${esc(String(data))}</pre>`; } } catch(err){ el.innerHTML=`<div class="alert alert-danger mb-0">Failed to load: ${esc(err.message||err)}</div>`; } }
  function init(){ const targets=document.querySelectorAll('[data-endpoint]'); targets.forEach(load); document.querySelectorAll('[data-refresh]').forEach(btn=>{ btn.addEventListener('click', ()=>{ const sel=btn.getAttribute('data-refresh'); const el=sel?document.querySelector(sel):null; if(el) load(el); }); }); const refreshS=Number(document.body.getAttribute('data-autorefresh'))||0; if(refreshS>0) setInterval(()=>targets.forEach(load), refreshS*1000); document.querySelectorAll('[data-cmd]').forEach(btn=>{ btn.addEventListener('click', ()=>{ const cmd=btn.getAttribute('data-cmd'); navigator.clipboard.writeText(cmd).then(()=>{ btn.textContent='Copied'; setTimeout(()=>btn.textContent='Copy',1200); }); }); }); }
  document.addEventListener('DOMContentLoaded', init);
})();

// Lightweight sparkline renderer (no deps)
(function(){
  function drawSpark(el){
    const dataAttr=el.getAttribute('data-points'); if(!dataAttr) return;
    const pts=dataAttr.split(',').map(v=>Number(v)||0); if(!pts.length) return;
    const max=Math.max(...pts,1), min=Math.min(...pts,0);
    const w=el.clientWidth||240, h=el.clientHeight||36, p=2;
    const svgNs='http://www.w3.org/2000/svg';
    const svg=document.createElementNS(svgNs,'svg'); svg.setAttribute('width',String(w)); svg.setAttribute('height',String(h));
    const scaleX=(i)=> p + i*(w-2*p)/Math.max(pts.length-1,1);
    const scaleY=(v)=> {
      if(max===min) return h/2; // flat line
      return h - p - (v-min)*(h-2*p)/(max-min);
    };
    let d='';
    pts.forEach((v,i)=>{ const x=scaleX(i), y=scaleY(v); d += (i===0?`M${x},${y}`:` L${x},${y}`); });
    const path=document.createElementNS(svgNs,'path'); path.setAttribute('d',d); path.setAttribute('fill','none'); path.setAttribute('stroke','#0d6efd'); path.setAttribute('stroke-width','2');
    svg.appendChild(path);
    // baseline
    const base=document.createElementNS(svgNs,'line'); base.setAttribute('x1','0'); base.setAttribute('x2',String(w)); base.setAttribute('y1',String(h-1)); base.setAttribute('y2',String(h-1)); base.setAttribute('stroke','#e9ecef'); base.setAttribute('stroke-width','1'); svg.appendChild(base);
    el.innerHTML=''; el.appendChild(svg);
  }
  function anchors(){
    document.querySelectorAll('a.nav-link[href^="#"]').forEach(a=>{
      a.addEventListener('click', (e)=>{ e.preventDefault(); const id=a.getAttribute('href').slice(1); const t=document.getElementById(id); if(t) t.scrollIntoView({behavior:'smooth', block:'start'}); });
    });
  }
  function modal(){
    const table=document.getElementById('recent-jobs'); if(!table) return;
    table.addEventListener('click', (e)=>{
      const tr=e.target.closest('tr[data-job-id]'); if(!tr) return;
      const id=tr.getAttribute('data-job-id'); if(!id) return;
      const title=document.getElementById('jobDetailTitle'); const body=document.getElementById('jobDetailBody');
      if(title) title.textContent = String(id);
      if(body) body.innerHTML = '<div class="text-muted">Loading…</div>';
      fetch(`public/job.detail.php?id=${encodeURIComponent(id)}`, { headers:{'Accept':'application/json'}, cache:'no-store' })
        .then(r=>r.json()).then(j=>{
          if(!j||!j.success){ body.innerHTML = '<div class="alert alert-danger mb-0">Failed to load job</div>'; return; }
          const job=j.data&&j.data.job?j.data.job:null; const logs=Array.isArray(j.data&&j.data.logs)?j.data.logs:[];
          const parts=[];
          if(job){
            parts.push('<h6>Job</h6>');
            parts.push('<div class="table-responsive"><table class="table table-sm mb-3"><tbody>');
            Object.keys(job).forEach(k=>{ parts.push(`<tr><th>${k}</th><td class="mono">${String(job[k] ?? '')}</td></tr>`); });
            parts.push('</tbody></table></div>');
          }
          parts.push('<h6>Logs (last 50)</h6>');
          if(logs.length===0){ parts.push('<div class="text-muted">No logs</div>'); }
          else {
            parts.push('<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead><tbody>');
            logs.forEach(r=>{ const ts=r.created_at||''; const lv=r.level||''; const msg=(r.message||''); parts.push(`<tr><td class="mono">${ts}</td><td>${lv||''}</td><td class="mono">${msg.length>300?msg.slice(0,297)+'…':msg}</td></tr>`); });
            parts.push('</tbody></table></div>');
          }
          body.innerHTML = parts.join('');
        }).catch(()=>{ body.innerHTML = '<div class="alert alert-danger mb-0">Failed to load job</div>'; });
      // Show modal using Bootstrap if available
      if(window.bootstrap && bootstrap.Modal){ const m=new bootstrap.Modal(document.getElementById('jobDetailModal')); m.show(); }
      else { document.getElementById('jobDetailModal').style.display='block'; }
    });
  }
  function filter(){
    const input=document.getElementById('job-search');
    const table=document.getElementById('recent-jobs');
    if(!input||!table) return;
    input.addEventListener('input', ()=>{
      const q=(input.value||'').toLowerCase();
      const rows=table.querySelectorAll('tbody tr');
      rows.forEach(tr=>{
        const text=tr.textContent.toLowerCase();
        tr.style.display = q && !text.includes(q) ? 'none' : '';
      });
    });
  }
  function whModal(){
    const table=document.getElementById('webhooks-last10'); if(!table) return;
    table.addEventListener('click', (e)=>{
      const tr=e.target.closest('tr[data-webhook-id]'); if(!tr) return;
      const id=tr.getAttribute('data-webhook-id'); if(!id) return;
      const title=document.getElementById('whDetailTitle'); const body=document.getElementById('whDetailBody');
      if(title) title.textContent = String(id);
      if(body) body.innerHTML = '<div class="text-muted">Loading…</div>';
      fetch(`public/webhook.detail.php?id=${encodeURIComponent(id)}`, { headers:{'Accept':'application/json'}, cache:'no-store' })
        .then(r=>r.json()).then(j=>{
          if(!j||!j.success){ body.innerHTML = '<div class="alert alert-danger mb-0">Failed to load webhook</div>'; return; }
          const wh=j.data&&j.data.webhook?j.data.webhook:null;
          if(!wh){ body.innerHTML = '<div class="text-muted">Not found</div>'; return; }
          // Redact sensitive tokens in headers/payload
          function redact(str){ try{ const o=JSON.parse(str); const walk=(obj)=>{ if(!obj||typeof obj!=='object') return; Object.keys(obj).forEach(k=>{ const v=obj[k]; const key=k.toLowerCase(); if(key.includes('secret')||key.includes('token')||key.includes('authorization')) obj[k]='[REDACTED]'; else walk(v); }); }; walk(o); return JSON.stringify(o,null,2); }catch(e){ return String(str||''); }
          }
          const parts=[];
          parts.push('<h6>Event</h6>');
          parts.push('<div class="table-responsive"><table class="table table-sm mb-3"><tbody>');
          ['id','webhook_id','webhook_type','status','received_at','processed_at','error_message','source_ip','user_agent'].forEach(k=>{ const v=wh[k]??''; parts.push(`<tr><th>${k}</th><td class="mono">${String(v)}</td></tr>`); });
          parts.push('</tbody></table></div>');
          parts.push('<h6>Payload</h6>');
          parts.push(`<pre class="kv">${esc(redact(wh.payload||''))}</pre>`);
          parts.push('<h6>Headers</h6>');
          parts.push(`<pre class="kv">${esc(redact(wh.headers||''))}</pre>`);
          body.innerHTML = parts.join('');
        }).catch(()=>{ body.innerHTML = '<div class="alert alert-danger mb-0">Failed to load webhook</div>'; });
      if(window.bootstrap && bootstrap.Modal){ const m=new bootstrap.Modal(document.getElementById('whDetailModal')); m.show(); }
      else { document.getElementById('whDetailModal').style.display='block'; }
    });
  }
  function init(){ document.querySelectorAll('#spark-backlog, #spark-throughput').forEach(drawSpark); anchors(); modal(); whModal(); filter(); }
  document.addEventListener('DOMContentLoaded', init);
})();
