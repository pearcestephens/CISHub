(function(){
  function qs(name, def=''){
    const u = new URL(location.href);
    return u.searchParams.get(name) || def;
  }

  const trace = qs('trace','').trim();
  const job   = parseInt(qs('job','0'), 10) || 0;
  const since = 240; // minutes

  if (!trace && !job) return; // form shows help

  const url = `/assets/services/queue/public/pipeline.trace.data.php?trace=${encodeURIComponent(trace)}&job=${job}&since=${since}`;
  const table = document.getElementById('traceTable').querySelector('tbody');
  const summary = document.getElementById('traceSummary');
  const spark = document.getElementById('traceSpark');

  fetch(url, {cache:'no-store'})
    .then(r => r.json())
    .then(j => {
      if (!j || !j.ok) throw new Error('Failed to load trace');

      const events = j.events || [];

      // Summary
      const first = events[0]?.time || '';
      const last  = events[events.length-1]?.time || '';
      const stages = [...new Set(events.map(e => e.stage))];
      summary.innerHTML = `
        <div><span class="kv">Events:</span> ${events.length}</div>
        <div><span class="kv">Stages:</span> ${stages.join(', ') || '—'}</div>
        <div class="text-muted small mt-1">from ${first || '—'} to ${last || '—'}</div>
      `;

      // Rows
      const frag = document.createDocumentFragment();
      events.forEach(e => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="mono">${(e.time || '').replace('T',' ').replace('Z','')}</td>
          <td class="mono">${e.stage || ''}</td>
          <td class="mono" style="white-space:pre-wrap;">${(e.message || '').toString().slice(0,400)}</td>
          <td class="mono">${e.source || ''}</td>
        `;
        frag.appendChild(tr);
      });
      table.innerHTML = ''; table.appendChild(frag);

      // Sparkline (events per slice)
      if (spark && spark.getContext) {
        const ctx = spark.getContext('2d');
        const w = spark.clientWidth || 300, h = spark.height;
        spark.width = w;

        const buckets = 32;
        const counts = new Array(buckets).fill(0);
        const t0 = Date.now();
        events.forEach((e) => {
          const ts = new Date(e.time || Date.now()).getTime();
          const diff = Math.max(0, t0 - ts);
          const slot = Math.min(buckets-1, Math.floor((diff / (since*60*1000)) * buckets));
          counts[buckets-1-slot]++;
        });

        const max = Math.max.apply(null, counts) || 1;
        const step = w / (buckets-1);

        ctx.clearRect(0,0,w,h);
        ctx.lineWidth = 2;
        ctx.strokeStyle = '#6ea8fe';
        ctx.beginPath();
        counts.forEach((v,i) => {
          const x = i*step;
          const y = h - (v/max)*(h-6) - 3;
          if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
        });
        ctx.stroke();
      }
    })
    .catch(e=>{
      summary.textContent = 'Failed to load trace: ' + (e?.message||e);
    });
})();
