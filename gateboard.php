<?php
// live_gateboard.php — Live Gateboard with news-style route ticker
// ------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/db.php';

date_default_timezone_set('Europe/London');
$today = (new DateTime('today'))->format('Y-m-d');

$sql = "SELECT id, supplier, user_name, material, quantity, unloading_method, status, due_datetime
        FROM deliveries
        WHERE DATE(due_datetime) = ?
        ORDER BY due_datetime ASC, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function s($v){ return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8'); }

/** URL helpers */
function site_origin(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme.'://'.$host;
}
function abs_url(string $u): string {
  return preg_match('~^https?://~i',$u) ? $u : site_origin().$u;
}
function gate_url(int $id): string {
  return abs_url('/gate.php?id='.$id.'&src=board');
}
function qr_src_for_id(int $id, int $size=360): string {
  return abs_url('/icon.php?qr='.rawurlencode(gate_url($id)).'&s='.$size.'&fmt=svg');
}

/** normalize rows for JS */
$items = [];
foreach ($rows as $r){
  $items[] = [
    'id'      => (int)$r['id'],
    'supplier'=> (string)($r['supplier'] ?? ''),
    'user'    => (string)($r['user_name'] ?? ''),
    'material'=> (string)($r['material'] ?? ''),
    'qty'     => (string)($r['quantity'] ?? ''),
    'method'  => (string)($r['unloading_method'] ?? ''),
    'status'  => (string)($r['status'] ?? ''),
    'due'     => (string)($r['due_datetime'] ?? ''),
    'qr'      => qr_src_for_id((int)$r['id'], 360),
  ];
}
$todayPretty = (new DateTime($today))->format('d/m/Y');

/* AJAX feed for auto-refresh */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($items, JSON_UNESCAPED_SLASHES);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Live Gateboard · <?= s($todayPretty) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#0b1220; --ink:#eaf2ff; --muted:#9fb3ce; --rule:#1c2a48;
    --late:#ef4444; --arr:#ffd166; --ok:#86efac;
    --card:#0f1b3a; --shadow:0 10px 22px rgba(0,0,0,.30), 0 2px 6px rgba(0,0,0,.22);

    /* sizing tuned to avoid clipping 2 rows */
    --qrBox:176px;
    --qr:156px;
    --row:172px;
    --pad:18px;
    --gap:16px;
    --tickerH:64px;
  }
  @media (max-height: 900px){
    :root{ --qrBox:166px; --qr:146px; --row:164px; --tickerH:60px; }
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{
    margin:0; color:var(--ink);
    background:
      radial-gradient(1200px 600px at 20% -10%, #16305a 0%, rgba(22,48,90,0) 60%),
      radial-gradient(1500px 800px at 110% 10%, #0e2449 0%, rgba(14,36,73,0) 55%),
      linear-gradient(0deg,#0b1220,#0b1220);
    font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
    overflow:hidden; /* no page scroll */
  }

  .top{
    display:flex; align-items:center; gap:12px;
    padding:14px 16px 6px; user-select:none;
  }
  h1{margin:0; font-size:38px; font-weight:800; letter-spacing:.5px}
  .date{opacity:.8; font-weight:700; margin-left:.4rem}
  .btn{
    margin-left:auto; display:inline-flex; align-items:center; gap:.5rem;
    color:#dbeafe; background:#0e1a36; border:1px solid #21345e;
    padding:10px 14px; border-radius:14px; font-weight:800; cursor:pointer;
  }

  .head{
    margin:8px 16px 6px; padding:10px 14px;
    display:grid; gap:16px;
    grid-template-columns: 1.2fr 1fr .6fr .9fr .7fr .6fr;
    color:#d7e4ff; font-weight:800; letter-spacing:.02em;
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.03));
    border:1px solid #243b69; border-radius:16px;
  }
  @media (max-width:1200px){
    .head{ grid-template-columns: 1fr .7fr .5fr .7fr .6fr .6fr; }
  }

  .wrap{ position:relative; height:calc(100vh - 90px - var(--tickerH) - 10px); }
  #viewport{ position:absolute; inset:0; overflow:hidden; }
  #strip{ display:flex; height:100%; transition:transform .45s ease; will-change:transform; }
  .page{ min-width:100%; padding:0 12px; display:grid; grid-template-columns: 1fr 1fr; gap:var(--gap); grid-auto-rows: minmax(var(--row), var(--row)); }
  @media (max-width:1200px){ .page{ grid-template-columns: 1fr; } }

  .card{
    height:var(--row); background:linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
    border:1px solid #21345e; border-radius:18px; box-shadow:var(--shadow);
    display:grid; grid-template-columns: 1fr var(--qrBox);
    align-items:center; gap:18px; padding:var(--pad); overflow:hidden;
  }
  .left{ min-width:0; display:grid; grid-template-columns: .9fr .6fr .5fr .6fr .7fr .4fr; gap:18px; align-items:baseline; }
  .name{ font-size:36px; font-weight:800; letter-spacing:.3px; line-height:1.05; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .by{ color:var(--muted); font-weight:700; font-size:20px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .material{ color:#dbeafe; font-size:22px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
  .qty,.method{ font-size:22px; font-weight:800; white-space:nowrap; }
  .due{ font-size:26px; font-weight:800; }
  .late{ color:var(--late); font-size:24px; font-weight:800; display:flex; align-items:center; gap:.35rem; white-space:nowrap;}
  .late:before{ content:"⚠"; transform:translateY(-1px); }

  .qrbox{
    width:var(--qrBox); height:var(--qrBox); display:flex; align-items:center; justify-content:center;
    border-radius:16px; background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
    border:1px solid #21345e;
  }
  .qrbox img{
    width:var(--qr); height:var(--qr); display:block;
    image-rendering: -webkit-optimize-contrast; image-rendering: crisp-edges;
  }

  /* ==== News-style route ticker ==== */
  .ticker{
    position:fixed; left:12px; right:12px; bottom:10px; height:var(--tickerH);
    display:flex; align-items:center; gap:12px;
    background:linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03));
    border:1px solid #233a68; border-radius:16px; padding:0 14px;
    box-shadow:var(--shadow); overflow:hidden;
  }
  .tlabel{ font-weight:900; color:#bcd3ff; margin-right:6px; white-space:nowrap; }
  .track{ position:relative; flex:1 1 auto; overflow:hidden; height:100%; }
  .marquee{ position:absolute; top:0; left:0; display:flex; align-items:center; gap:30px; white-space:nowrap; will-change:transform; }
  .row{ display:flex; align-items:center; gap:30px; height:100%; }
  .chip{ padding:4px 10px; border-radius:999px; border:1px solid #274174; background:#0e1a36; font-weight:900; }
  .clock{ margin-left:auto; color:#bcd3ff; font-weight:800; white-space:nowrap; }

  @keyframes scrollLeft {
    from { transform: translateX(0); }
    to   { transform: translateX(-50%); }
  }
</style>
</head>
<body>
  <div class="top">
    <h1>Live Gateboard</h1>
    <div class="date">· <?= s($todayPretty) ?></div>
    <button id="fsBtn" class="btn">⤢ Fullscreen</button>
  </div>

  <div class="head">
    <div>Contractor / Booked By</div>
    <div>Material</div>
    <div>Qty</div>
    <div>Method</div>
    <div>Due</div>
    <div>Gate QR</div>
  </div>

  <div class="wrap">
    <div id="viewport" aria-busy="true">
      <div id="strip"></div>
    </div>
  </div>

  <!-- News-style ticker (single) -->
  <div class="ticker" id="ticker">
    <div class="tlabel">Next up →</div>
    <div class="track">
      <div id="marquee" class="marquee" aria-live="off"></div>
    </div>
    <div id="clock" class="clock">--:--:--</div>
  </div>

<script>
const RAW = <?= json_encode($items, JSON_UNESCAPED_SLASHES) ?>;

/* Utilities */
function escapeHtml(s){ return String(s).replace(/[&<>"]/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
function UKtime(ts){ if(!ts) return ''; const d=new Date(ts.replace(' ','T')); return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`; }
function isLate(ts){ if(!ts) return false; return (Date.now() - new Date(ts.replace(' ','T')).getTime()) > 5*60*1000; }

/* Cards */
function card(d){
  const late = isLate(d.due) ? `<div class="late">Late</div>` : `<div></div>`;
  return `
  <div class="card">
    <div class="left">
      <div class="name">${escapeHtml(d.supplier||'')}</div>
      <div class="material">${escapeHtml(d.material||'')}</div>
      <div class="qty">${escapeHtml(d.qty||'')}</div>
      <div class="method">${escapeHtml(d.method||'')}</div>
      <div class="due">${escapeHtml(UKtime(d.due))}</div>
      ${late}
    </div>
    <div class="qrbox">
      <img src="${d.qr}" alt="QR">
    </div>
  </div>`;
}

/* Pagination rendering */
let DATA = [...RAW];
let rowsPerPage = 8;
let pageIdx = 0;
let pager = null;

function twoCols(){ return !matchMedia('(max-width:1200px)').matches; }
function calcRows(){
  const vp = document.getElementById('viewport'); if(!vp){ rowsPerPage=8; return; }
  const h = vp.clientHeight;
  const row = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--row')) || 172;
  const gap = 16;
  const usable = h - 8;
  const rowsPerCol = Math.max(1, Math.floor(usable / (row + gap)));
  rowsPerPage = rowsPerCol * (twoCols()?2:1);
}
function buildPages(items, perPage){
  const pages=[];
  for(let i=0;i<items.length;i+=perPage) pages.push(items.slice(i,i+perPage));
  if(!pages.length) pages.push([]);
  const strip = document.getElementById('strip');
  strip.innerHTML = pages.map(pg => `<div class="page">${pg.map(card).join('')}</div>`).join('');
  // overflow guard
  const first = strip.querySelector('.page');
  if(first && first.scrollHeight > first.clientHeight + 2){
    const step = twoCols()?2:1;
    rowsPerPage = Math.max(step, rowsPerPage - step);
    return buildPages(items, rowsPerPage);
  }
  return pages.length;
}
function render(){
  calcRows();
  const nPages = buildPages(DATA, rowsPerPage);
  pageIdx=0;
  document.getElementById('strip').style.transform='translateX(0)';
  startPager(nPages);
  document.getElementById('viewport').setAttribute('aria-busy','false');
  buildTicker();
}
function startPager(nPages){
  clearInterval(pager);
  if(nPages<=1) return;
  pager=setInterval(()=>{
    pageIdx=(pageIdx+1)%nPages;
    document.getElementById('strip').style.transform=`translateX(${-pageIdx*100}%)`;
  }, 8000);
}

/* News-style Ticker */
function buildTicker(){
  const byTime = [...DATA].sort((a,b)=> new Date(a.due.replace(' ','T')) - new Date(b.due.replace(' ','T')));
  const now = Date.now();
  const next = byTime.find(x => new Date(x.due.replace(' ','T')).getTime() >= now) || byTime[0];

  const totals = {
    total: DATA.length,
    arrived: DATA.filter(d => (d.status||'').toLowerCase()==='arrived').length,
    completed: DATA.filter(d => (d.status||'').toLowerCase()==='completed').length,
    cancelled: DATA.filter(d => (d.status||'').toLowerCase()==='cancelled').length,
    late: DATA.filter(d => isLate(d.due)).length
  };

  // Build scrolling string: Next up + route of all items/time, then totals
  const route = byTime.map(d => `${escapeHtml(d.supplier||'—')} @ ${escapeHtml(UKtime(d.due))}`).join('  •  ');
  const nextTxt = next ? `${escapeHtml(next.supplier)} @ ${escapeHtml(UKtime(next.due))}` : '—';
  const totalsTxt = `Totals  ${totals.total}  •  Arrived: ${totals.arrived}  •  Completed: ${totals.completed}  •  Cancelled: ${totals.cancelled}  •  ⚠ Late: ${totals.late}`;

  const marquee = document.getElementById('marquee');
  const line = `<div class="row"><span class="chip">Next</span> ${nextTxt}  •  ${route}  •  <span class="chip">${totalsTxt}</span></div>`;

  // duplicate content for seamless loop (50% translate in CSS)
  marquee.innerHTML = line + line;

  // measure and set animation
  // We translate -50% because we duplicated content once; time scales with width
  const pxPerSec = 120; // speed control — increase for faster scroll
  const totalWidth = marquee.scrollWidth / 2; // width of single line
  const duration = Math.max(12, totalWidth / pxPerSec);

  marquee.style.animation = `scrollLeft ${duration}s linear infinite`;
}

/* Clock */
setInterval(()=>{
  const d=new Date();
  document.getElementById('clock').textContent =
    `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}:${String(d.getSeconds()).padStart(2,'0')}`;
}, 1000);

/* Fullscreen */
document.getElementById('fsBtn').addEventListener('click', ()=>{
  const doc=document.documentElement;
  if(!document.fullscreenElement){ doc.requestFullscreen?.(); }
  else{ document.exitFullscreen?.(); }
});

/* Auto-refresh data */
async function refreshData(){
  try{
    const r = await fetch(location.pathname + '?ajax=1&_=' + Date.now(), {headers:{'X-Requested-With':'fetch'}});
    if(!r.ok) return;
    const j = await r.json();
    if(!Array.isArray(j)) return;
    DATA = j;
    render();
  }catch(_){}
}
setInterval(refreshData, 15000);

/* init */
window.addEventListener('resize', render);
render();
</script>
</body>
</html>
