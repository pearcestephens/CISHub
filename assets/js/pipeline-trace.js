(function(){
  'use strict';

  function h(s){ return String(s==null?'':s); }
  function qs(sel, el){ return (el||document).querySelector(sel); }
  function qsa(sel, el){ return Array.from((el||document).querySelectorAll(sel)); }

  function parseQuery(){
    const p = new URLSearchParams(location.search);
    return {
      trace: p.get('trace')||'',
      job: parseInt(p.get('job')||'0',10)||0,
      refresh: parseInt(p.get('refresh')||'5',10)||0
    };
  }

  async function loadData(){
    const { trace, job } = parseQuery();
    const u = new URL('/assets/services/queue/public/pipeline.trace.data.php', location.origin);
    if(trace) u.searchParams.set('trace', trace);
    if(job>0) u.searchParams.set('job', String(job));
    u.searchParams.set('since','240');
    const res = await fetch(u.toString(), { headers: { 'Accept':'application/json' }, cache: 'no-store' });
    if(!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }

  function renderSummary(data){
    const el = qs('#traceSummary'); if(!el) return;
    const parts = [];
    if(data.trace) parts.push('trace_id='+h(data.trace));
    if(data.job) parts.push('job_id='+h(data.job));
    el.textContent = parts.join(' · ');
  }

  function classify(msg){
    const s = (msg||'').toLowerCase();
    if(s.includes('failed')||s.includes('error')||s.includes('exception')) return 'danger';
    if(s.includes('retry')||s.includes('warn')) return 'warning';
    if(s.includes('completed')||s.includes('enqueue')||s.includes('success')) return 'success';
    return 'secondary';
  }

  function renderTable(data){
    const tbody = qs('#traceTable tbody'); if(!tbody) return;
    tbody.innerHTML = '';
    (data.events||[]).forEach(ev => {
      const tr = document.createElement('tr');
      const sev = classify(ev.message||'');
      tr.innerHTML = `
        <td><span class="badge bg-${sev}">&nbsp;</span> ${h(ev.time||'')}</td>
        <td>${h(ev.stage||'')}</td>
        <td style="white-space:pre-wrap">${h(ev.message||'')}</td>
        <td>${h(ev.source||'')}</td>
      `;
      tbody.appendChild(tr);
    });
  }

  function renderViz(data){
    const el = qs('#pipelineViz'); if(!el) return;
    const stages = ['Submit Handler','Queue','Runner','Worker','Webhook Intake'];
    const seen = new Set((data.events||[]).map(e=>e.stage));
    const html = stages.map(st => {
      const ok = seen.has(st);
      const cls = ok? 'bg-success' : 'bg-secondary';
      return `<div class="d-inline-flex align-items-center me-2 mb-2">
        <span class="badge ${cls} status-dot"></span>
        <span class="ms-2">${h(st)}</span>
      </div>`;
    }).join('');
    el.innerHTML = html;
  }

  async function tick(){
    try{
      const data = await loadData();
      renderSummary(data);
      renderViz(data);
      renderTable(data);
    }catch(err){
      console.error('trace load failed', err);
    }
  }

  function setupAutoRefresh(){
    const { refresh } = parseQuery();
    if(refresh>0){ setInterval(tick, refresh*1000); }
  }

  // boot
  document.addEventListener('DOMContentLoaded', () => {
    tick();
    setupAutoRefresh();
  });
})();
