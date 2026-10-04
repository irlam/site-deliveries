<?php
// /admin/metrics.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

admin_require($pdo);

// Time window info for header
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int]

// Escape helper (guarded)
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// UK time for print header timestamp
date_default_timezone_set('Europe/London');
$printedAt = date('d/m/Y H:i');
?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Metrics · Deliveries Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>

<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9;
    --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444; --border:#1f2937;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  a{color:#93c5fd}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border);z-index:3}
  .top{max-width:1200px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;gap:10px}
  .top .title{font-weight:800}
  .top .spacer{flex:1 1 auto}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);
       padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .btn.ghost{background:transparent}
  .btn.icon{padding:6px 10px}
  .wrap{max-width:1200px;margin:24px auto;padding:0 16px}

  /* stat cards */
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:14px}
  .card:hover{border-color:#334155}
  .stat-title{color:var(--muted);font-size:12px}
  .stat-value{font-size:28px;font-weight:800;margin-top:6px}
  .stat-sub{color:var(--muted);font-size:12px;margin-top:6px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0b1528;color:#cbd5e1;font-size:12px}

  /* chart grid */
  .grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px;margin-top:16px}
  .col-6{grid-column:span 6}
  .col-4{grid-column:span 4}
  .col-8{grid-column:span 8}
  .col-12{grid-column:span 12}
  @media (max-width: 960px){
    .col-6,.col-4,.col-8{grid-column:span 12}
  }

  .panel-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
  .panel-title{font-weight:700}

  .legend-row{display:flex;gap:10px;flex-wrap:wrap;color:var(--muted);font-size:12px}
  .legend-chip{display:inline-flex;align-items:center;gap:6px}
  .legend-dot{width:10px;height:10px;border-radius:2px;background:#4b5563;display:inline-block}

  .footer{color:var(--muted);font-size:12px;margin:24px 0;text-align:center}
  .kbd{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;background:#0f172a;border:1px solid var(--border);padding:1px 6px;border-radius:6px}

  /* PRINT STYLES */
  .no-print{ /* items hidden in print */ }
  .print-only{ display:none; }

  @media print {
    @page { size: A4 landscape; margin: 12mm; }
    html, body { background:#fff !important; color:#111 !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    header { position: static; background: #fff !important; border: none !important; }
    .top, .btn, .pill, .legend-row, .footer { display: none !important; }
    .wrap { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
    .card { background: #fff !important; border: 1px solid #aaa !important; box-shadow: none !important; break-inside: avoid; }
    .stats { gap:10px }
    .grid { gap:12px }
    .panel-title { color:#000 !important; }
    .print-only { display: block !important; }
    canvas { max-width: 100% !important; height: auto !important; }
  }

  /* Print header (hidden on screen) */
  .print-header{
    display:none;
    padding:0 16px 12px 16px;
    border-bottom:1px solid #e5e7eb;
    margin-bottom:12px;
  }
  .print-header .title{
    font-weight:800;
    font-size:20px;
    margin-bottom:2px;
    color:#111;
  }
  .print-header .meta{
    font-size:12px;
    color:#444;
  }
</style>
</head>
<body>
<header>
  <div class="top">
    <a class="btn ghost no-print" href="/admin/">&larr; Dashboard</a>
    <div class="title">Metrics</div>
    <span class="spacer"></span>
    <span class="pill no-print">Grid: <?= h($ts['start']) ?> → <?= h($ts['end']) ?> · <?= (int)$ts['interval'] ?> min</span>
    <button id="refreshBtn" class="btn icon no-print" title="Refresh">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M21 12a9 9 0 1 1-2.64-6.36" stroke="#93c5fd" stroke-width="2" stroke-linecap="round"/><path d="M21 3v6h-6" stroke="#93c5fd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Refresh
    </button>
    <button id="printBtn" class="btn icon no-print" title="Print / Save as PDF" style="margin-left:6px">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M6 9V3h12v6M6 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-1M16 14H8M16 18H8" stroke="#93c5fd" stroke-width="2" stroke-linecap="round"/></svg>
      Print / PDF
    </button>
  </div>
</header>

<!-- PRINT-ONLY HEADER -->
<div class="print-header print-only">
  <div class="title">Deliveries — Metrics Report</div>
  <div class="meta">
    Generated: <?= h($printedAt) ?> · Grid <?= h($ts['start']) ?> – <?= h($ts['end']) ?> (<?= (int)$ts['interval'] ?> min)
  </div>
</div>

<div class="wrap">
  <!-- Stat Cards -->
  <div class="stats" id="statCards">
    <div class="card">
      <div class="stat-title">Total deliveries</div>
      <div class="stat-value" id="st_all">—</div>
      <div class="stat-sub">All-time</div>
    </div>
    <div class="card">
      <div class="stat-title">Last 7 days</div>
      <div class="stat-value" id="st_last7">—</div>
      <div class="stat-sub">Created within last week</div>
    </div>
    <div class="card">
      <div class="stat-title">Last 30 days</div>
      <div class="stat-value" id="st_last30">—</div>
      <div class="stat-sub">Created within last 30 days</div>
    </div>
    <div class="card">
      <div class="stat-title">Upcoming 7 days</div>
      <div class="stat-value" id="st_up7">—</div>
      <div class="stat-sub">Due in next week</div>
    </div>
    <div class="card">
      <div class="stat-title">Off-grid (last 30d)</div>
      <div class="stat-value" id="st_offgrid">—</div>
      <div class="stat-sub">Not aligned to <?= (int)$ts['interval'] ?>-min grid</div>
    </div>
  </div>

  <!-- Charts -->
  <div class="grid">
    <div class="card col-8">
      <div class="panel-head">
        <div class="panel-title">Deliveries per day (last 8 weeks)</div>
      </div>
      <canvas id="perDayChart" height="110"></canvas>
    </div>

    <div class="card col-4">
      <div class="panel-head">
        <div class="panel-title">Status mix (last 30d)</div>
      </div>
      <canvas id="statusChart" height="110"></canvas>
      <div class="legend-row no-print" id="statusLegend"></div>
    </div>

    <div class="card col-6">
      <div class="panel-head">
        <div class="panel-title">Top contractors (last 30d)</div>
      </div>
      <canvas id="suppliersChart" height="120"></canvas>
    </div>

    <div class="card col-6">
      <div class="panel-head">
        <div class="panel-title">Unloading methods (last 30d)</div>
      </div>
      <canvas id="unloadingChart" height="120"></canvas>
    </div>

    <div class="card col-12">
      <div class="panel-head">
        <div class="panel-title">Capacity vs. booked (last 14 days)</div>
        <span class="pill no-print" id="capBadge">Grid: —</span>
      </div>
      <canvas id="capacityChart" height="110"></canvas>
    </div>

    <div class="card col-12">
      <div class="panel-head">
        <div class="panel-title">Hourly distribution (last 30d)</div>
        <span class="pill no-print">Local time</span>
      </div>
      <canvas id="hourlyChart" height="110"></canvas>
    </div>
  </div>

  <div class="footer no-print">
    Tip: press <span class="kbd">R</span> to refresh.
  </div>
</div>

<script>
(() => {
  const $ = sel => document.querySelector(sel);

  // Charts registry
  const charts = {};

  // Convert YYYY-MM-DD -> DD/MM (UK)
  function fmtDateUK(d){
    // defensive: ensure shape yyyy-mm-dd
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || '');
    if (!m) return d || '';
    return `${m[3]}/${m[2]}`; // DD/MM (compact)
  }

  function setStats(totals){
    $('#st_all').textContent    = (totals.all ?? 0).toLocaleString('en-GB');
    $('#st_last7').textContent  = (totals.last7 ?? 0).toLocaleString('en-GB');
    $('#st_last30').textContent = (totals.last30 ?? 0).toLocaleString('en-GB');
    $('#st_up7').textContent    = (totals.upcoming7 ?? 0).toLocaleString('en-GB');
    $('#st_offgrid').textContent= (totals.offgrid_last30 ?? 0).toLocaleString('en-GB');
  }

  function mkChart(ctxId, cfg){
    const ctx = document.getElementById(ctxId).getContext('2d');
    if (charts[ctxId]) { charts[ctxId].destroy(); }
    charts[ctxId] = new Chart(ctx, cfg);
    return charts[ctxId];
  }

  function palette(n){
    const base = [
      '#60a5fa','#34d399','#fbbf24','#f87171','#a78bfa',
      '#f472b6','#22c55e','#f59e0b','#38bdf8','#e879f9',
      '#10b981','#ef4444','#93c5fd'
    ];
    const out = [];
    for (let i=0;i<n;i++) out.push(base[i % base.length]);
    return out;
  }

  function renderStatus(statusObj){
    const labels = Object.keys(statusObj);
    const data = labels.map(k => statusObj[k]);
    mkChart('statusChart', {
      type:'doughnut',
      data:{ labels, datasets:[{ data, backgroundColor: palette(labels.length) }] },
      options:{ plugins:{ legend:{ display:false }}, cutout:'58%' }
    });
    const leg = $('#statusLegend');
    if (!leg) return;
    leg.innerHTML = '';
    const cols = palette(labels.length);
    labels.forEach((l,i)=>{
      const chip = document.createElement('div');
      chip.className = 'legend-chip';
      chip.innerHTML = `<span class="legend-dot" style="background:${cols[i]}"></span> ${l}: <b>${data[i]}</b>`;
      leg.appendChild(chip);
    });
  }

  function renderPerDay(series){
    const labels = series.map(r => fmtDateUK(r.date));
    const data = series.map(r => r.count);
    mkChart('perDayChart', {
      type:'line',
      data:{ labels, datasets:[{
        label:'Deliveries',
        data,
        tension:0.25,
        fill:true,
        backgroundColor: 'rgba(96,165,250,0.25)',
        borderColor: '#60a5fa',
        pointRadius: 0
      }]},
      options:{
        interaction:{mode:'index', intersect:false},
        plugins:{
          legend:{ display:false },
          tooltip:{
            callbacks:{
              // Show full UK date in tooltip (DD/MM/YYYY)
              title: (items) => {
                const i = items[0].dataIndex;
                const raw = series[i]?.date || '';
                const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
                return m ? `${m[3]}/${m[2]}/${m[1]}` : raw;
              }
            }
          }
        },
        scales:{
          x:{ grid:{ color:'#1f2937'} },
          y:{ grid:{ color:'#1f2937'}, beginAtZero:true, ticks:{ precision:0 } }
        }
      }
    });
  }

  function renderSuppliers(rows){
    const labels = rows.map(r => r.supplier);
    const data = rows.map(r => r.count);
    mkChart('suppliersChart', {
      type:'bar',
      data:{ labels, datasets:[{ label:'Deliveries', data, backgroundColor: palette(labels.length) }]},
      options:{
        plugins:{ legend:{ display:false }},
        scales:{
          x:{ grid:{ display:false }},
          y:{ beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'#1f2937' } }
        }
      }
    });
  }

  function renderUnloading(obj){
    const labels = Object.keys(obj);
    const data = labels.map(k => obj[k]);
    mkChart('unloadingChart', {
      type:'bar',
      data:{ labels, datasets:[{ label:'Count', data, backgroundColor: palette(labels.length) }]},
      options:{
        plugins:{ legend:{ display:false }},
        scales:{ y:{ beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'#1f2937' }}, x:{ grid:{ display:false }} }
      }
    });
  }

  function renderCapacity(series, grid){
    const badge = document.getElementById('capBadge');
    if (badge) badge.textContent = `Grid ${grid.start} → ${grid.end} · ${grid.interval} min · ${grid.slotsPerDay} slots/day`;
    const labels = series.map(r => fmtDateUK(r.date));
    const booked = series.map(r => r.booked);
    const capacity = series.map(r => r.capacity);
    mkChart('capacityChart', {
      type:'bar',
      data:{
        labels,
        datasets:[
          { type:'bar', label:'Booked', data: booked, backgroundColor:'#34d399' },
          { type:'line', label:'Capacity', data: capacity, tension:0, borderColor:'#60a5fa', pointRadius:0 }
        ]
      },
      options:{
        plugins:{
          legend:{ position:'bottom' },
          tooltip:{
            callbacks:{
              title: (items) => {
                const i = items[0].dataIndex;
                const raw = series[i]?.date || '';
                const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
                return m ? `${m[3]}/${m[2]}/${m[1]}` : raw;
              }
            }
          }
        },
        scales:{
          x:{ grid:{ display:false }},
          y:{ beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'#1f2937' } }
        }
      }
    });
  }

  function renderHourly(arr){
    const labels = Array.from({length:24}, (_,h)=> String(h).padStart(2,'0') + ':00'); // UK-style HH:00
    mkChart('hourlyChart', {
      type:'bar',
      data:{ labels, datasets:[{ label:'Deliveries', data: arr, backgroundColor:'#a78bfa' }]},
      options:{
        plugins:{ legend:{ display:false }},
        scales:{ x:{ grid:{ display:false }}, y:{ beginAtZero:true, ticks:{ precision:0 }, grid:{ color:'#1f2937' }} }
      }
    });
  }

  async function loadAll(){
    const res = await fetch('/admin/metrics_data.php', { cache:'no-store' });
    const j = await res.json();
    if (!j || !j.ok) throw new Error('Failed to load metrics');

    setStats(j.totals || {});
    renderPerDay(j.per_day_last56 || []);
    renderStatus(j.status_last30 || {});
    renderSuppliers(j.top_suppliers_last30 || []);
    renderUnloading(j.unloading_last30 || {});
    renderCapacity(j.capacity_last14 || [], j.grid || {start:'—',end:'—',interval:0,slotsPerDay:0});
    renderHourly(j.per_hour_last30 || []);
  }

  // Wire buttons
  const refresh = () => loadAll().catch(err => console.error(err));
  document.getElementById('refreshBtn').addEventListener('click', refresh);
  document.addEventListener('keydown', (e)=>{ if (e.key.toLowerCase()==='r') refresh(); });

  // Print helper: UK-ish filename
  document.getElementById('printBtn').addEventListener('click', () => {
    const oldTitle = document.title;
    const now = new Date();
    const dd = String(now.getDate()).padStart(2,'0');
    const mm = String(now.getMonth()+1).padStart(2,'0');
    const yyyy = now.getFullYear();
    const hh = String(now.getHours()).padStart(2,'0');
    const mi = String(now.getMinutes()).padStart(2,'0');
    document.title = `Deliveries Metrics ${dd}-${mm}-${yyyy} ${hh}${mi}`;
    window.print();
    setTimeout(()=>{ document.title = oldTitle; }, 500);
  });

  // Initial load
  refresh();
})();
</script>
</body>
</html>
