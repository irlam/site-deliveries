<?php
// gate.php — Gate actions + delivery sheet capture
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';


date_default_timezone_set('Europe/London');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function site_origin(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme . '://' . $host;
}
function abs_url(string $u): string {
  return preg_match('~^https?://~i', $u) ? $u : site_origin() . $u;
}
function run_sheet_link(?string $ymd): string {
  if (!$ymd) return '/run_sheet.php';
  return '/run_sheet.php?date=' . rawurlencode($ymd);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo "Missing id";
  exit;
}

// load delivery
$stmt = $pdo->prepare("SELECT id,supplier AS contractor,user_name,material,quantity,unloading_method,status,due_datetime,arrived_at,completed_at
                       FROM deliveries WHERE id = ?");
$stmt->execute([$id]);
$delivery = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$delivery){
  http_response_code(404);
  echo "Delivery not found";
  exit;
}

// compute ymd for run sheet link
$ymd = null;
if (!empty($delivery['due_datetime'])) {
  try { $ymd = (new DateTime($delivery['due_datetime']))->format('Y-m-d'); } catch(Throwable $e){}
}

// list existing files (delivery sheet etc.)
$files = [];
try{
  // table may or may not exist yet — ignore if not
  $pdo->query("CREATE TABLE IF NOT EXISTS delivery_files (
      id INT AUTO_INCREMENT PRIMARY KEY,
      delivery_id INT NOT NULL,
      path VARCHAR(255) NOT NULL,
      label VARCHAR(255) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(delivery_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $q = $pdo->prepare("SELECT id, path, label, created_at FROM delivery_files WHERE delivery_id=? ORDER BY id DESC");
  $q->execute([$id]);
  $files = $q->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){ /* non-fatal */ }

// helpers
$runSheetHref = run_sheet_link($ymd);
$packHref     = '/export/delivery_pack.php?id='.(int)$delivery['id'];
$canComplete  = ($delivery['status'] !== 'Completed');
$canArrive    = (empty($delivery['arrived_at']) || $delivery['status'] === 'Booked in');
?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Gate · Delivery #<?= (int)$id ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --bg:#0b1020; --panel:#131a2a; --ink:#eff5ff; --muted:#92a2c7; --accent:#ffc72c; --green:#22c55e; }
  body{ background:var(--bg); color:var(--ink); }
  .wrap{ max-width:960px; margin:24px auto; padding:0 14px; }
  .card-dark{ background:var(--panel); border:1px solid rgba(255,255,255,.06); border-radius:14px; padding:18px; }
  .grid{ display:grid; gap:12px; grid-template-columns: 1fr 1fr; }
  @media (max-width: 720px){ .grid{ grid-template-columns: 1fr; } }
  .fld{ background:#0f1524; border:1px solid rgba(255,255,255,.06); padding:14px; border-radius:10px; min-height:78px; }
  .lbl{ color:var(--muted); font-size:.9rem; margin-bottom:.25rem; }
  .bigbtn{ display:block; width:100%; font-weight:800; font-size:1.25rem; padding:16px 18px; border-radius:12px; border:none; }
  .bigbtn-arrive{ background:var(--accent); color:#1a1400; }
  .bigbtn-arrive:disabled{ filter: grayscale(.7) opacity(.7); }
  .bigbtn-complete{ background:var(--green); color:#062411; }
  .bigbtn-complete:disabled{ filter: grayscale(.7) opacity(.7); }
  .subtle{ color:var(--muted); font-size:.9rem; }
  .thumb{ max-width:100%; height:auto; border-radius:8px; border:1px solid rgba(255,255,255,.08); }
  .file-row{ display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px dashed rgba(255,255,255,.08); }
  .file-row:last-child{ border-bottom:none; }
  .topbar{display:flex;gap:8px;align-items:center;justify-content:flex-end;margin-bottom:10px;}
  a.btn-outline-light{ --bs-btn-color:#d7e3ff; --bs-btn-border-color:#3b4a6b; --bs-btn-hover-bg:#223154; --bs-btn-hover-border-color:#5572a7; }
</style>
<?php if(logistics_enabled($pdo)): ?><meta name="logistics-csrf" content="<?=htmlspecialchars(logistics_csrf(),ENT_QUOTES,'UTF-8')?>"><script src="/assets/logistics-legacy.js"></script><?php endif; ?>
</head>
<body>
  <div class="wrap">
    <div class="topbar">
      <a class="btn btn-outline-light btn-sm" href="<?= h($runSheetHref) ?>" target="_blank" rel="noopener">Run sheet</a>
      <a class="btn btn-outline-light btn-sm" href="<?= h($packHref) ?>" target="_blank" rel="noopener">Download Pack (PDF)</a>
    </div>

    <h2 class="mb-3">Gate · Delivery #<?= (int)$delivery['id'] ?></h2>

    <div class="card-dark mb-3">
      <div class="grid">
        <div class="fld"><div class="lbl">Contractor</div><div><?= h($delivery['contractor']) ?></div></div>
        <div class="fld"><div class="lbl">Booked By</div><div><?= h($delivery['user_name']) ?></div></div>
        <div class="fld"><div class="lbl">Material</div><div><?= h($delivery['material']) ?></div></div>
        <div class="fld"><div class="lbl">Quantity</div><div><?= h($delivery['quantity']) ?></div></div>
        <div class="fld"><div class="lbl">Due</div><div><?= h(date('d/m/Y H:i', strtotime($delivery['due_datetime']))) ?></div></div>
        <div class="fld"><div class="lbl">Method</div><div><?= h($delivery['unloading_method']) ?></div></div>
        <div class="fld"><div class="lbl">Status</div><div><?= h($delivery['status']) ?></div></div>
        <div class="fld"><div class="lbl">Arrived</div><div><?= $delivery['arrived_at'] ? h(date('d/m/Y H:i', strtotime($delivery['arrived_at']))) : '—' ?></div></div>
        <div class="fld" style="grid-column:1 / -1;"><div class="lbl">Completed</div><div><?= $delivery['completed_at'] ? h(date('d/m/Y H:i', strtotime($delivery['completed_at']))) : '—' ?></div></div>
      </div>

      <div class="mt-3">
        <div class="alert alert-warning py-2 mb-3" role="alert">
          Enable notifications on this device to alert the team when you tap Arrived/Completed.
          <button class="btn btn-sm btn-outline-dark ms-2" id="enablePushBtn">Enable</button>
        </div>

        <div class="d-grid gap-2">
          <button class="bigbtn bigbtn-arrive" id="btnArrived" <?= $canArrive ? '' : 'disabled' ?>>Arrived Now</button>
          <button class="bigbtn bigbtn-complete" id="btnCompleted" <?= $canComplete ? '' : 'disabled' ?>>Completed Now</button>
        </div>
        <div id="actionMsg" class="mt-2"></div>
        <div class="subtle mt-3">Tap when the delivery reaches the gate.</div>
      </div>
    </div>

    <!-- Delivery sheet capture -->
    <div class="card-dark mb-4">
      <h5 class="mb-2">Delivery Sheet (photo upload)</h5>
      <p class="subtle mb-2">Use your phone camera to capture the signed delivery sheet. JPG/PNG up to 10&nbsp;MB.</p>
      <form id="sheetForm" enctype="multipart/form-data" class="d-flex flex-column gap-2">
        <input type="hidden" name="delivery_id" value="<?= (int)$delivery['id'] ?>">
        <input class="form-control" type="file" name="sheet" accept="image/*" capture="environment" required>
        <div class="d-flex gap-2">
          <input class="form-control" type="text" name="label" placeholder="Label (optional, e.g. Signed sheet)">
          <button type="submit" class="btn btn-primary">Upload</button>
        </div>
        <div id="sheetMsg" class="small"></div>
      </form>

      <div class="mt-3">
        <h6 class="mb-2">Files</h6>
        <div id="filesList">
          <?php if (!$files): ?>
            <div class="subtle">No files yet.</div>
          <?php else: foreach ($files as $f): ?>
            <div class="file-row">
              <img src="<?= h(abs_url($f['path'])) ?>" alt="" style="width:70px;height:70px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.08)">
              <div>
                <div><a class="link-light" href="<?= h(abs_url($f['path'])) ?>" target="_blank" rel="noopener"><?= h($f['label']) ?></a></div>
                <div class="subtle"><?= h(date('d/m/Y H:i', strtotime($f['created_at']))) ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <p class="text-center subtle mb-4">Tip: add this page to your Home Screen for one-tap access.</p>
  </div>

<script>
const deliveryId = <?= (int)$delivery['id'] ?>;
const actionMsg = document.getElementById('actionMsg');

async function postAction(action){
  try{
    const r = await fetch('/gate_action.php', {
      method: 'POST',
      body: new URLSearchParams({id:String(deliveryId), action})
    });
    const j = await r.json();
    if (!j || !j.ok) throw new Error(j?.error || 'Action failed');
    actionMsg.innerHTML = '<span class="text-success">'+(action==='arrived'?'Arrived':'Completed')+' saved.</span>';
    if (action==='arrived') document.getElementById('btnArrived').disabled = true;
    if (action==='completed') document.getElementById('btnCompleted').disabled = true;
  }catch(e){
    actionMsg.innerHTML = '<span class="text-danger">'+(e.message||e)+'</span>';
  }
}
document.getElementById('btnArrived')?.addEventListener('click', ()=>postAction('arrived'));
document.getElementById('btnCompleted')?.addEventListener('click', ()=>postAction('completed'));

// Upload delivery sheet (camera)
const sheetForm = document.getElementById('sheetForm');
sheetForm?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const msg = document.getElementById('sheetMsg');
  msg.textContent = 'Uploading...';
  const fd = new FormData(sheetForm);
  try{
    const r = await fetch('/upload_delivery_sheet.php', { method:'POST', body:fd });
    const j = await r.json();
    if (!j || !j.ok) throw new Error(j?.error || 'Upload failed');
    msg.innerHTML = '<span class="text-success">Uploaded.</span>';
    // prepend to list
    const list = document.getElementById('filesList');
    const row = document.createElement('div');
    row.className = 'file-row';
    row.innerHTML = `
      <img src="${j.url}" alt="" style="width:70px;height:70px;object-fit:cover;border-radius:8px;border:1px solid rgba(255,255,255,.08)">
      <div><div><a class="link-light" href="${j.url}" target="_blank" rel="noopener">${j.label}</a></div>
      <div class="subtle">${j.created_at}</div></div>`;
    if (list.firstElementChild && list.firstElementChild.classList.contains('subtle')) list.innerHTML='';
    list.prepend(row);
    sheetForm.reset();
  }catch(err){
    msg.innerHTML = '<span class="text-danger">'+(err.message||err)+'</span>';
  }
});

// (Optional) push enable hook – wire to your existing service worker if needed
document.getElementById('enablePushBtn')?.addEventListener('click', ()=>{
  alert('If your browser prompts for notifications, please allow them');
});
</script>
</body>
</html>
