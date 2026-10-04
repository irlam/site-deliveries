<?php
/**
 * index.php
 * --------------------------------------------------------------------
 * Construction Site Delivery Management System - Main Page
 * --------------------------------------------------------------------
 * - Modern UI for deliveries, calendar & multi-slot booking.
 * - Weather header (config-driven via Admin → Calendar Weather).
 * - Blackout windows (Admin → Blackouts) fetched via /get_blackouts.php.
 * - Per-day notes banner (via /get_day_notes.php).
 * - Requests banner preview (via /get_open_requests.php) – view-only.
 * --------------------------------------------------------------------
 * Last updated: 17/10/2025
 */
require_once 'db.php';
require_once __DIR__ . '/includes/settings.php';

/* --- Admin session --- */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$adminLoggedIn = isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
$adminHref = $adminLoggedIn ? '/admin/' : '/admin/login.php';
$isAdmin   = $adminLoggedIn;

function get_filter($key) { return isset($_GET[$key]) ? trim($_GET[$key]) : ''; }

$search_supplier = get_filter('search_supplier');
$search_material = get_filter('search_material');
$search_date     = get_filter('search_date');
$search_status   = get_filter('search_status');

$where = []; $params = [];
if ($search_supplier !== '') { $where[] = "supplier LIKE ?"; $params[] = "%$search_supplier%"; }
if ($search_material !== '') { $where[] = "material LIKE ?"; $params[] = "%$search_material%"; }
if ($search_date !== '' && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $search_date, $m)) {
  $where[] = "DATE(due_datetime) = ?"; $params[] = "{$m[3]}-{$m[2]}-{$m[1]}";
}
if ($search_status !== '') { $where[] = "status = ?"; $params[] = $search_status; }
$where_sql = $where ? "WHERE " . implode(' AND ', $where) : "";

$stmt = $pdo->prepare("SELECT * FROM deliveries $where_sql ORDER BY due_datetime DESC");
$stmt->execute($params);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---- Weather settings ---- */
$show_weather = (get_setting($pdo, 'show_weather', '0') === '1') || (get_setting($pdo, 'weather_show', '0') === '1');
$weather_key  = get_setting($pdo, 'weather_api_key', '');
$weather_lat  = get_setting($pdo, 'weather_lat', '53.4808');
$weather_lon  = get_setting($pdo, 'weather_lon', '-2.2426');

date_default_timezone_set('Europe/London');
$todayYmd   = date('Y-m-d');
$dow        = (int)date('N'); // 1=Mon..7=Sun
$mondayYmd  = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Site Delivery Management</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#0b1220">
  <link rel="apple-touch-icon" href="/icons/icon-180.png">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="style.css">

  <style>
    /* Buttons in calendar area */
    .calendar-nav .btn-pill{
      background:#f1f7fd;border:1.5px solid #b6c9e6;color:#24508c;border-radius:12px;font-weight:600;
    }
    .calendar-nav .btn-pill:hover,.calendar-nav .btn-pill:focus{ background:#1076f0;color:#fff;border-color:#0854a0; }

    .calendar-table,.calendar-table th,.calendar-table td,.calendar-table .time-col{
      font-family:var(--bs-body-font-family,system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif);
      font-size:1rem; line-height:1.5;
    }
    .calendar-table th,.calendar-table td{ padding:.45em .25em; }
    .calendar-table th.today{ font-weight:bold; }
    .calendar-table .time-col{ font-size:.98rem; }

    .modern-calendar-container{ background:#fff;border-radius:1.1rem;box-shadow:0 2px 18px rgba(20,60,120,.09),0 1.5px 6px rgba(20,60,120,.09);padding:1.5rem 1.2rem;margin-bottom:2.2rem; }
    .calendar-table{ width:100%; border-collapse:separate; border-spacing:0; }
    .calendar-table th,.calendar-table td{ text-align:center; min-width:96px; vertical-align:middle; border:none; background:none; }
    .calendar-table thead th{ background:#f2f7fb;font-weight:600;padding:.7em .3em;border-radius:10px 10px 0 0;color:#2e4c6d;font-size:1em;letter-spacing:.03em;border-top:1.5px solid #dde6f3;border-bottom:2.5px solid #dde6f3; }
    .calendar-table th.today{ background:#d7ebff!important;color:#1a73e8; box-shadow:0 2px 8px #e3f0ff; }
    .calendar-table .time-col{ background:#f7fafc;color:#6b859e;font-weight:500;border-radius:7px;min-width:70px;position:sticky;left:0;z-index:2; }
    .calendar-table td{ border-radius:8px; cursor:pointer; transition:box-shadow .15s, background .15s, color .15s; position:relative; z-index:1; }

    .calendar-table td.slot-free{ background:linear-gradient(135deg,#eaf7ef 90%,#d9f5e5 100%); color:#206330; border:1.5px dashed #bce4cd; }
    .calendar-table td.slot-free:hover,.calendar-table td.slot-free:focus{ background:linear-gradient(135deg,#b7f0d3 80%,#eaf7ef 100%); box-shadow:0 0 0 2px #a1e6c9,0 2px 6px #b7e2d8; outline:none; }
    .calendar-table td.slot-booked{ background:linear-gradient(135deg,#eaf0fa 80%,#dbeaff 100%); color:#24508c; font-weight:600; border:1.5px solid #b6c9e6; box-shadow:0 2px 8px -5px #a7cfff; }
    .calendar-table td.slot-booked:hover,.calendar-table td.slot-booked:focus{ background:linear-gradient(135deg,#bedbfa 65%,#eaf0fa 100%); box-shadow:0 0 0 2px #aabde6,0 5px 18px #bedbfa; outline:none; }
    .calendar-table td.slot-booked-cancelled{ background:#f2f2f2;color:#c00;font-style:italic;text-decoration:line-through;border:1.5px solid #ddd; }

    .calendar-table td.slot-blackout{
      background-image:repeating-linear-gradient(45deg,rgba(120,120,120,.10),rgba(120,120,120,.10) 8px,rgba(120,120,120,0) 8px,rgba(120,120,120,0) 16px);
      border:1.5px dashed #9aa4af!important; color:#6b7280!important; cursor:not-allowed!important;
    }

    .calendar-table td.selected-slot{ background:linear-gradient(135deg,#1076f0 70%,#5ab5fd 100%)!important; color:#fff!important; border:2.5px solid #0854a0!important; box-shadow:0 0 0 2px #69b2f9,0 2px 18px #1076f0; }

    .site-logo{ max-height:64px; height:auto; width:auto; }
    @media (max-width:600px){ .site-logo{ max-height:42px; } }

    .calendar-legend{ display:flex; flex-wrap:wrap; gap:1.5em; align-items:center; margin-bottom:.7em; font-size:1.09rem; }
    .calendar-title{ font-size:1.4rem; font-weight:bold; color:#1157b8; margin-bottom:.6em; }

    .small-debug{ display:inline-block; font-size:12px; color:#6b859e; background:#f7fafc; border:1px solid #dde6f3; border-radius:10px; padding:4px 8px; margin-left:auto; }

    /* ===== New two-row header ===== */
    .toolbar-row{
      display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:.5rem;
    }
    .toolbar-actions{
      margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; align-items:stretch;
    }
    .banners-row{
      display:grid; grid-template-columns: minmax(280px,1fr) minmax(280px,1fr);
      gap:12px; align-items:stretch; margin-bottom:12px;
    }
    @media (max-width: 920px){ .banners-row{ grid-template-columns: 1fr; } }

    .panel-yellow{
      background:#fff8cc; border:1px solid #f2cf63; color:#7a5a00;
      border-radius:12px; padding:12px 14px; box-shadow:0 2px 8px rgba(20,60,120,.08);
    }
    .panel-yellow h6{ margin:0 0 .4rem 0; font-weight:700; display:flex; align-items:center; gap:.35rem; }
    .panel-yellow ul{ margin:.25rem 0 0 1.1rem; }

    /* Keep compatibility with old notes markup */
    .notes-banner{ padding:0; border:none; box-shadow:none; }
  </style>
</head>
      <div class="container my-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">

    <!-- Left: logo + title -->
    <h1 class="mb-0 d-flex align-items-center gap-2">
      <img src="/icons/icon-192.png" alt="Site Deliveries" class="img-fluid" style="height:48px;width:auto;">
      <span class="text-primary fw-semibold fs-3">Site Deliveries</span>
    </h1>

    <!-- Right: actions -->
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="<?= htmlspecialchars($adminHref, ENT_QUOTES) ?>" class="btn btn-warning">
        <i class="fa-solid fa-user-shield"></i> Admin
      </a>

      <button id="enablePushBtn" class="btn btn-outline-primary" type="button" title="Enable notifications on this device">
        Enable Notifications
      </button>

      <label class="form-check form-switch m-0 d-flex align-items-center gap-2">
        <input class="form-check-input" type="checkbox" id="pushToggle">
        <span>Notifications OFF</span>
      </label>
		
		<a class="btn btn-info" href="/gateboard.php" target="_blank" rel="noopener">
        <i class="fa-solid fa-circle-info"></i> Live Gateboard
      </a>

      <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#aboutModal">
        <i class="fa-solid fa-circle-info"></i> About This System
      </button>

      <a class="btn btn-info" href="/showcase.php" target="_blank" rel="noopener">
        <i class="fa-solid fa-circle-info"></i> Showcase
      </a>
    </div>

  </div>
</div>


    <?php include 'overview_panel.php'; ?>

    <section class="mb-5">
      <h2 class="h4 mb-3">Add New Delivery</h2>
      <?php include 'booking_form.php'; ?>
      <?php if (isset($_GET['msg'])): ?>
        <div id="formMsg" class="alert alert-info mt-3"><?= htmlspecialchars($_GET['msg']) ?></div>
      <?php endif; ?>
    </section>

    <div class="mb-3">
      <button id="toggleDeliveriesBtn" class="btn btn-outline-secondary" type="button" aria-expanded="false" aria-controls="allDeliveriesSection">
        Show All Deliveries
      </button>
    </div>

    <!-- All Deliveries Table and Filters -->
    <section id="allDeliveriesSection" class="mb-5" style="display: none;">
      <h2 class="h4 mb-3">All Deliveries</h2>

      <!-- CSV Export Bar + Pretty Reports -->
      <form class="row filter-bar align-items-end mb-3" method="get" action="export_deliveries.php">
        <div class="col-auto">
          <label class="form-label mb-0">Export from</label>
          <input type="text" name="from" class="form-control" placeholder="DD/MM/YYYY">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0">to</label>
          <input type="text" name="to" class="form-control" placeholder="DD/MM/YYYY">
        </div>
        <div class="col-auto">
          <a class="btn btn-primary" href="export_deliveries.php?format=html&range=daily&date=<?= htmlspecialchars($todayYmd) ?>">Today’s Report</a>
        </div>
        <div class="col-auto">
          <a class="btn btn-primary" href="export_deliveries.php?format=html&range=weekly&week=<?= htmlspecialchars($mondayYmd) ?>">This Weeks Report</a>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-success">Export CSV</button>
        </div>
        <div class="col-auto">
          <a href="export_deliveries.php" class="btn btn-outline-success">Export All (CSV)</a>
        </div>
        <div class="col-auto">
          <a href="printable_calendar.php" class="btn btn-outline-success">Printable Calendar</a>
        </div>
      </form>

      <!-- Filter/Search form -->
      <form class="row filter-bar align-items-end mb-3" method="get" action="">
        <div class="col-auto">
          <label class="form-label mb-0">Contractor</label>
          <input type="text" name="search_supplier" class="form-control" value="<?= htmlspecialchars($search_supplier) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0">Material</label>
          <input type="text" name="search_material" class="form-control" value="<?= htmlspecialchars($search_material) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0">Date</label>
          <input type="text" name="search_date" class="form-control" placeholder="DD/MM/YYYY" value="<?= htmlspecialchars($search_date) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0">Status</label>
          <select name="search_status" class="form-select">
            <option value="">All</option>
            <option value="Booked in"<?= $search_status==='Booked in'?' selected':''?>>Booked in</option>
            <option value="Completed"<?= $search_status==='Completed'?' selected':''?>>Completed</option>
            <option value="Cancelled"<?= $search_status==='Cancelled'?' selected':''?>>Cancelled</option>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-primary">Search</button>
          <a href="index.php#allDeliveriesSection" class="btn btn-primary">Reset</a>
        </div>
      </form>

      <?php if ($isAdmin): ?>
      <div class="mb-2">
        <button id="deleteSelectedBtn" class="btn btn-danger btn" style="display:none;">
          <i class="fa-solid fa-trash"></i> Delete Selected
        </button>
      </div>
      <?php endif; ?>

      <div class="table-responsive">
        <form id="deleteDeliveriesForm" method="post" action="delete_deliveries.php">
          <table id="deliveryTable" class="table table-striped align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:32px;"><input type="checkbox" id="selectAllDeliveries" title="Select All" /></th>
                <th>Contractor</th>
                <th>Name</th>
                <th>Material</th>
                <th>Quantity</th>
                <th>Due Date/Time</th>
                <th>Unloading Method</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($deliveries as $delivery): ?>
  <tr<?= ($delivery['status'] === 'Cancelled') ? ' style="color:#888;text-decoration:line-through;"' : '' ?> class="delivery-select-row">
    <td>
      <input type="checkbox" class="selectDelivery" name="delete_ids[]" value="<?= (int)$delivery['id'] ?>" />
    </td>
    <td><?= htmlspecialchars($delivery['supplier']) ?></td>
    <td><?= htmlspecialchars($delivery['user_name']) ?></td>
    <td><?= htmlspecialchars($delivery['material']) ?></td>
    <td><?= htmlspecialchars($delivery['quantity']) ?></td>
    <td><?= date('d/m/Y H:i', strtotime($delivery['due_datetime'])) ?></td>
    <td><?= htmlspecialchars($delivery['unloading_method']) ?></td>
    <td><?= htmlspecialchars($delivery['status']) ?></td>
    <td>
      <button type="button" class="btn btn-info btn-sm viewRowBtn" data-id="<?= (int)$delivery['id'] ?>"
              title="View<?= $isAdmin ? '/Edit/Cancel/Reschedule' : '' ?>">
        <i class="fa-solid fa-eye"></i>
      </button>
      <?php if ($isAdmin): ?>
      <button type="button" class="btn btn-danger btn-sm deleteRowBtn" data-id="<?= (int)$delivery['id'] ?>" title="Delete this delivery">
        <i class="fa-solid fa-trash"></i>
      </button>
      <?php endif; ?>
    </td>
  </tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </form>
        <?php if (empty($deliveries)): ?>
          <div class="alert alert-warning mt-3">No deliveries found for your search/filter.</div>
        <?php endif; ?>
      </div>
    </section>

    <!-- Weekly Delivery Calendar -->
    <section>
      <div class="calendar-legend mb-2">
        <span><span class="material-symbols-rounded" style="color:#0057b8;">precision_manufacturing</span> Crane</span>
        <span><span class="material-symbols-rounded" style="color:#28a745;">forklift</span> Forklift</span>
        <span><span class="material-symbols-rounded" style="color:#f8b400;">transfer_within_a_station</span> By Hand</span>
        <span class="offgrid-chip"><span class="material-symbols-rounded" style="color:#b58900;">warning</span> Off-grid</span>
      </div>

      <h2 class="calendar-title mb-2">
        Weekly Delivery Calendar
        <span class="fs-6 text-muted">(<span id="slotInfo">20 min</span> slots, UK time)</span>
      </h2>

      <!-- Row 1: main calendar buttons -->
      <div class="toolbar-row">
        <button id="prevWeekBtn" class="btn btn-outline-primary btn-sm" title="Previous week">
          <span class="material-symbols-rounded align-bottom">arrow_back</span> Previous Week
        </button>
        <button id="todayBtn" class="btn btn-outline-secondary btn-sm" title="This week">This Week</button>
        <button id="nextWeekBtn" class="btn btn-outline-primary btn-sm" title="Next week">
          Next Week <span class="material-symbols-rounded align-bottom">arrow_forward</span>
        </button>

        <div class="toolbar-actions">
          <button id="showAllDeliveriesBtn" class="btn btn-success btn-sm">Show All Deliveries</button>
          <a href="/run_sheet.php?date=<?= htmlspecialchars($todayYmd) ?>&qr=1" class="btn btn-outline-primary btn-sm btn-pill">
            Print Today’s Run Sheet
          </a>
          <a href="/late_report.php" class="btn btn-success btn-sm btn-pill">Late / No-show</a>
        </div>
      </div>

      <!-- Row 2: yellow panels (notes + requests) -->
      <div class="banners-row">
        <!-- Notes panel -->
        <div class="panel-yellow" id="notesPanel" role="region" aria-label="Site notes">
          <h6><span class="material-symbols-rounded">campaign</span> Site notes for this week</h6>
          <ul id="notesPanelList"><li class="text-muted">No notes this week.</li></ul>
        </div>

        <!-- Requests preview panel (view-only) -->
        <div class="panel-yellow" id="requestsPanel" role="region" aria-label="Change requests awaiting review">
          <h6><span class="material-symbols-rounded">notifications_active</span> Change requests awaiting review</h6>
          <ul id="requestsPanelList"><li class="text-muted">No open requests.</li></ul>
          <?php if ($isAdmin): ?>
            <div class="mt-2">
              <a class="btn btn-outline-primary btn-sm" href="admin/requests_admin.php">Open requests dashboard</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Admin debug -->
      <div id="calDebug" class="small-debug" style="display:none;"></div>

      <!-- Calendar container -->
      <div id="calendar" class="modern-calendar-container"></div>

      <h6 class="text-primary mb-0 text-center">
        © Defect Tracker. Built for real sites, by real people. Developed And Maintained By Chris Irlam
      </h6>
    </section>

    <!-- Delivery Details Modal -->
    <div class="modal fade" id="deliveryDetailModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content" id="modalContent"></div>
      </div>
    </div>

    <!-- Booking Modal -->
    <div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-bottom modal-dialog-centered modal-md">
        <div class="modal-content" id="bookingModalContent"></div>
      </div>
    </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <!-- Admin flag + helper -->
  <script>
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    function adminOnlyPopup() {
      alert("Only an administrator can make changes to existing deliveries.\nPlease contact your site admin.");
    }
  </script>

  <!-- Time window (server -> JS) -->
  <script>
  <?php $ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int] ?>
  (function (w) {
    if (!('WINDOW_START' in w))           w.WINDOW_START = '<?= htmlspecialchars($ts['start'], ENT_QUOTES) ?>';
    if (!('WINDOW_END' in w))             w.WINDOW_END   = '<?= htmlspecialchars($ts['end'],   ENT_QUOTES) ?>';
    if (!('SLOT_INTERVAL_MINUTES' in w))  w.SLOT_INTERVAL_MINUTES = <?= (int)$ts['interval'] ?>;
  })(window);
  </script>

  <!-- Weather config (server -> JS) -->
  <script>
    (function (w) {
      w.WEATHER_ENABLED = <?= ($show_weather && $weather_key) ? 'true' : 'false' ?>;
      w.WEATHER_API_KEY = <?= json_encode($weather_key) ?>;
      w.WEATHER_LAT = <?= json_encode($weather_lat) ?>;
      w.WEATHER_LON = <?= json_encode($weather_lon) ?>;
    })(window);
  </script>

  <!-- Unloading methods (live from DB, cached) -->
  <script>
  let _unloadingCache = null;
  async function loadUnloadingMethods() {
    if (_unloadingCache) return _unloadingCache;
    try {
      const res = await fetch('get_unloading_methods.php', {cache: 'no-store'});
      const arr = await res.json();
      _unloadingCache = (Array.isArray(arr) && arr.length) ? arr : ['Crane','Forklift','By hand'];
    } catch {
      _unloadingCache = ['Crane','Forklift','By hand'];
    }
    return _unloadingCache;
  }
  function renderUnloadingSelectHtml(options, selectedValue='') {
    const opts = ['<option value="">Select...</option>'].concat(
      options.map(label => {
        const sel = (String(label).toLowerCase() === String(selectedValue||'').toLowerCase()) ? ' selected' : '';
        return `<option value="${label.replace(/"/g,'&quot;')}"${sel}>${label.replace(/</g,'&lt;')}</option>`;
      })
    );
    return `<select name="unloading_method" required class="form-select">${opts.join('')}</select>`;
  }
  </script>

  <!-- Table row “view” buttons -->
  <script>
  document.querySelectorAll('.viewRowBtn').forEach(btn => {
    btn.addEventListener('click', async function() { await showDeliveryDetails(this.getAttribute('data-id')); });
  });
  </script>

  <!-- Modal helpers + QR helpers -->
  <script>
  function showModal(html) {
    document.getElementById('modalContent').innerHTML = html;
    let modal = new bootstrap.Modal(document.getElementById('deliveryDetailModal'));
    modal.show();
  }
  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function gateUrlForId(id){
    const origin = location.origin || (location.protocol + '//' + location.host);
    return origin + '/gate.php?id=' + encodeURIComponent(id) + '&src=modal';
  }
  // NEW: helper for the printable PDF pack
  function packUrlForId(id){
    return '/export/delivery_pack.php?id=' + encodeURIComponent(id);
  }
  // NEW: always use server-side QR generator endpoint (SVG) so it matches run_sheet.php & PDF
  function qrSrcForUrl(u){ return '/icon.php?qr=' + encodeURIComponent(u) + '&s=180&fmt=svg'; }
  async function copyText(t){
    try { await navigator.clipboard.writeText(t); return true; }
    catch {
      const ta = document.createElement('textarea');
      ta.value = t; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); return true; } catch { return false; }
      finally { document.body.removeChild(ta); }
    }
  }
  function formatUK(dt) {
    return dt.getDate().toString().padStart(2,'0') + '/' +
           (dt.getMonth()+1).toString().padStart(2,'0') + '/' +
           dt.getFullYear() + ' ' +
           dt.getHours().toString().padStart(2,'0') + ':' +
           dt.getMinutes().toString().padStart(2,'0');
  }
  function ymdToUK(ymd){ const [Y,M,D] = ymd.split('-'); return `${D}/${M}/${Y}`; }
  </script>
  <!-- Delivery Details modal (includes Request Change button) -->
  <script>
  function getUnloadingIconHTML(method) {
    const m = (method || '').toLowerCase();
    if (m.includes('crane'))  return '<span class="material-symbols-rounded" style="color:#0057b8;">precision_manufacturing</span>';
    if (m.includes('fork'))   return '<span class="material-symbols-rounded" style="color:#28a745;">forklift</span>';
    if (m.includes('hand'))   return '<span class="material-symbols-rounded" style="color:#f8b400;">transfer_within_a_station</span>';
    if (m.includes('hiab'))   return '<span class="material-symbols-rounded" style="color:#7c3aed;">crane</span>';
    return '<i class="fa-solid fa-box"></i>';
  }

  async function showDeliveryDetails(deliveryId) {
    const res = await fetch('get_delivery_details.php?id=' + deliveryId);
    const d = await res.json();
    if (!d) return;

    const cancelled = d.status === "Cancelled";
    const gateUrl = gateUrlForId(deliveryId);
    const packUrl = packUrlForId(deliveryId);   // <-- added
    const qrSrc  = qrSrcForUrl(gateUrl);        // <-- new SVG QR type

    let html = `
    <div class="modal-header">
      <h5 class="modal-title">Delivery Details</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <div class="modal-body">
      <div class="row g-3 align-items-start">
        <div class="col-12 col-md-8" style="display:grid;gap:.4em;font-size:1.07em;">
          <div><b>Contractor:</b> ${esc(d.contractor || d.supplier || '')}</div>
          <div><b>Booked By:</b> ${esc(d.user_name || '')}</div>
          <div><b>Material:</b> ${esc(d.material || '')}</div>
          <div><b>Quantity:</b> ${esc(d.quantity || '')}</div>
          <div><b>Due Date/Time:</b> ${formatUK(new Date(d.due_datetime.replace(' ','T')))}</div>
          <div><b>Unloading Method:</b> ${esc(d.unloading_method || '')}</div>
          <div><b>Status:</b> ${esc(d.status || '')}</div>
          <div class="mt-2"><b>Attachments:</b><ul id="attachList" class="mb-1"></ul></div>
          <div id="deliveryModalMsg" class="mt-1"></div>
        </div>
        <div class="col-12 col-md-4">
          <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
              <div class="fw-semibold mb-2">Gate QR</div>
              <a href="${gateUrl}" target="_blank" rel="noopener" class="d-inline-block" title="Open Gate page">
                <img src="${qrSrc}" alt="QR" width="160" height="160"
                     style="max-width:100%;height:auto;border-radius:10px;border:1px solid rgba(0,0,0,.08);padding:6px;background:#fff" />
              </a>
              <div class="small text-muted mt-2">Scan at gate or tap to open</div>
              <div class="d-grid gap-2 mt-3">
                <a href="${gateUrl}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">Open Gate</a>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="copyGateLinkBtn">Copy Gate Link</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      ${IS_ADMIN && !cancelled ? `<button type="button" class="btn btn-warning me-auto" onclick="showEditDeliveryForm(${deliveryId})"><i class="fa fa-pen"></i> Edit</button>` : (!IS_ADMIN ? `<button type="button" class="btn btn-outline-warning me-auto" onclick="adminOnlyPopup()"><i class="fa fa-lock"></i> Admin required</button>` : '')}
      ${IS_ADMIN && !cancelled ? `<button type="button" class="btn btn-info" onclick="showRescheduleForm(${deliveryId},'${d.due_datetime}')"><i class="fa fa-calendar"></i> Reschedule</button>` : ''}
      ${IS_ADMIN && !cancelled ? `<button type="button" class="btn btn-danger" onclick="cancelDelivery(${deliveryId})"><i class="fa fa-ban"></i> Cancel</button>` : ''}

      <a href="${packUrl}" target="_blank" rel="noopener" class="btn btn-outline-secondary">Download Pack (PDF)</a>
      <button type="button" class="btn btn-outline-primary" onclick="openRequestChangeForm(${deliveryId}, '${esc(d.due_datetime)}')">Request Change</button>
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div>`;

    showModal(html);

    // Copy link
    const copyBtn = document.getElementById('copyGateLinkBtn');
    if (copyBtn) copyBtn.addEventListener('click', async () => {
      const ok = await copyText(gateUrl);
      const msg = document.getElementById('deliveryModalMsg');
      if (msg) msg.innerHTML = ok ? '<span class="text-success">Gate link copied.</span>' : '<span class="text-danger">Could not copy.</span>';
    });

    // Attachments
    try {
      const r = await fetch('list_delivery_files.php?id='+deliveryId);
      const arr = await r.json();
      const ul = document.getElementById('attachList');
      if (Array.isArray(arr) && arr.length) ul.innerHTML = arr.map(a => `<li><a target="_blank" rel="noopener" href="${a.url}">${esc(a.label)}</a></li>`).join('');
      else ul.innerHTML = '<li class="text-muted">None</li>';
    } catch {}
  }
  </script>

  <!-- Request Change modal + submit -->
  <script>
  function openRequestChangeForm(deliveryId, dueDt) {
    const html = `
    <div class="modal-header">
      <h5 class="modal-title">Request a Change</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <form id="reqChangeForm">
    <div class="modal-body" style="display:grid;gap:.7rem;">
      <input type="hidden" name="delivery_id" value="${deliveryId}">
      <label>Your Name
        <input type="text" name="requester_name" required class="form-control">
      </label>
      <label>Contact (phone or email)
        <input type="text" name="contact" class="form-control" placeholder="">
      </label>
      <label>What would you like to do?
        <select name="request_type" class="form-select" required>
          <option value="change_time">Change time</option>
          <option value="cancel">Cancel this delivery</option>
          <option value="edit_details">Edit details</option>
        </select>
      </label>
      <label>New date/time (if changing time)
        <input type="datetime-local" name="requested_dt" class="form-control" value="${dueDt ? dueDt.replace(' ','T').slice(0,16) : ''}">
      </label>
      <label>Reason / details
        <textarea name="details" class="form-control" rows="3" placeholder="Tell the site team what needs changing and why"></textarea>
      </label>
      <div class="text-muted small">Your request will be sent to the site admin. They will review and update the booking or contact you.</div>
      <div id="reqChangeMsg" class="mt-1"></div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary">Send Request</button>
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
    </div>
    </form>`;
    showModal(html);

    document.getElementById('reqChangeForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      const msg = document.getElementById('reqChangeMsg');
      msg.textContent = 'Sending...';
      try {
        const r = await fetch('request_change.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j || !j.ok) throw new Error(j && j.error ? j.error : 'Unknown error');
        msg.innerHTML = '<span class="text-success">Request sent. Thank you!</span>';
        // refresh requests panel
        fetchRequestsPreview();
      } catch (err) {
        msg.innerHTML = '<span class="text-danger">'+esc(String(err.message||err))+'</span>';
      }
    });
  }
  </script>
  <!-- Bulk delete + selection -->
  <script>
  const selectAll = document.getElementById('selectAllDeliveries');
  const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
  const deleteForm = document.getElementById('deleteDeliveriesForm');
  function updateSelectedRows() {
    let anyChecked = false;
    document.querySelectorAll(".delivery-select-row").forEach((row) => {
      const cb = row.querySelector('.selectDelivery');
      if (cb && cb.checked) { row.classList.add('selected'); anyChecked = true; }
      else { row.classList.remove('selected'); }
    });
    if (deleteSelectedBtn) deleteSelectedBtn.style.display = anyChecked ? "" : "none";
  }
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      document.querySelectorAll('.selectDelivery').forEach(cb => { cb.checked = selectAll.checked; });
      updateSelectedRows();
    });
  }
  document.querySelectorAll('.selectDelivery').forEach(cb => {
    cb.addEventListener('change', function() {
      if (!this.checked) selectAll && (selectAll.checked = false);
      else if ([...document.querySelectorAll('.selectDelivery')].every(cb2 => cb2.checked)) selectAll && (selectAll.checked = true);
      updateSelectedRows();
    });
  });
  if (deleteSelectedBtn) {
    deleteSelectedBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const checked = document.querySelectorAll('.selectDelivery:checked');
      if (!checked.length) return;
      if (confirm(`Delete ${checked.length} selected delivery(s)? This cannot be undone!`)) deleteForm.submit();
    });
  }
  updateSelectedRows();
  </script>

  <!-- Notes + requests preview (view-only) -->
  <script>
  const notesCache = new Map(); // key = `${from}:${to}` -> {byDay:{}}
  let notesByDay = {};          // { 'YYYY-MM-DD': 'note' }

  async function fetchDayNotes(fromYmd, toYmd) {
    const key = `${fromYmd}:${toYmd}`;
    if (notesCache.has(key)) {
      notesByDay = notesCache.get(key).byDay || {};
      renderNotesPanelList();
      return notesByDay;
    }
    try {
      const url = `/get_day_notes.php?from=${fromYmd}&to=${toYmd}&_=${Date.now()}`;
      const r = await fetch(url, { cache: 'no-store' });
      let byDay = {};
      if (r.ok) {
        const j = await r.json();
        if (j && j.ok) {
          if (j.notes && typeof j.notes === 'object') {
            byDay = Object.fromEntries(Object.entries(j.notes).map(([k,v]) => [k, String(v ?? '')]));
          } else if (Array.isArray(j.items)) {
            j.items.forEach(x => { if (x && x.date) byDay[x.date] = String(x.note ?? ''); });
          }
        }
      }
      notesByDay = byDay;
      notesCache.set(key, { byDay });
    } catch { notesByDay = {}; }
    renderNotesPanelList();
    return notesByDay;
  }

  function renderNotesPanelList(){
    const list = document.getElementById('notesPanelList');
    if (!list) return;
    const entries = Object.entries(notesByDay)
      .filter(([, n]) => (String(n||'').trim().length > 0))
      .sort((a, b) => a[0].localeCompare(b[0]));
    if (!entries.length) { list.innerHTML = '<li class="text-muted">No notes this week.</li>'; return; }
    list.innerHTML = entries.map(([ymd, note]) => {
      const firstLine = String(note).split('\n')[0].trim();
      return `<li><strong>${ymdToUK(ymd)}</strong> — ${esc(firstLine)}</li>`;
    }).join('');
  }

  async function fetchRequestsPreview() {
    try {
      const r = await fetch('/get_change_requests.php?status=open&limit=5', { cache: 'no-store' });
      const j = await r.json();
      const listEl = document.getElementById('requestsPanelList');
      if (!listEl) return;
      if (!j || !j.ok || !Array.isArray(j.items) || !j.items.length) {
        listEl.innerHTML = '<li class="text-muted">No open requests.</li>';
        return;
      }
      listEl.innerHTML = j.items.map((x, i) => {
        const when = x.requested_dt ? ` (${esc(ymdToUK(x.requested_dt.slice(0,10)))} ${esc(x.requested_dt.slice(11,16))})` : '';
        const what = x.request_type === 'cancel' ? 'cancel' : (x.request_type === 'edit_details' ? 'edit details' : 'change time');
        return `<li><b>#${i+1}</b> — ${esc(what)} for delivery <b>${esc(String(x.delivery_id))}</b>${when}</li>`;
      }).join('');
    } catch {
      const listEl = document.getElementById('requestsPanelList');
      if (listEl) listEl.innerHTML = '<li class="text-muted">Could not load requests.</li>';
    }
  }
  </script>

  <!-- Calendar rendering (uses notesByDay for day headers) -->
  <script>
  // Helpers
  function generateTimeSlots(start = WINDOW_START, end = WINDOW_END, stepMin = SLOT_INTERVAL_MINUTES) {
    let slots = [];
    let [sh, sm] = start.split(':').map(Number);
    let [eh, em] = end.split(':').map(Number);
    let date = new Date(2000,0,1,sh,sm);
    let endDate = new Date(2000,0,1,eh,em);
    while (date <= endDate) {
      slots.push(date.getHours().toString().padStart(2,'0') + ':' +
                 date.getMinutes().toString().padStart(2,'0'));
      date.setMinutes(date.getMinutes() + stepMin);
    }
    return slots;
  }
  function getMonday(d, weekOffset=0) {
    d = new Date(d);
    let day = d.getDay(), diff = d.getDate() - day + (day === 0 ? -6:1);
    let monday = new Date(d.setDate(diff));
    monday.setHours(0,0,0,0);
    if (weekOffset !== 0) monday.setDate(monday.getDate() + (weekOffset * 7));
    return monday;
  }
  function hhmmToMinutes(hhmm) { const [h, m] = hhmm.split(':').map(Number); return (h*60 + m); }
  function isOnGrid(hhmm, start = WINDOW_START, step = SLOT_INTERVAL_MINUTES) {
    const s = hhmmToMinutes(start);
    const t = hhmmToMinutes(hhmm);
    if (t < s) return false;
    return ((t - s) % step) === 0;
  }
  function nearestGridHHMM(hhmm, start = WINDOW_START, end = WINDOW_END, step = SLOT_INTERVAL_MINUTES) {
    const grid = generateTimeSlots(start, end, step);
    let best = grid[0], bestDiff = Math.abs(hhmmToMinutes(hhmm) - hhmmToMinutes(grid[0]));
    for (let i=1;i<grid.length;i++){
      const diff = Math.abs(hhmmToMinutes(hhmm) - hhmmToMinutes(grid[i]));
      if (diff < bestDiff) { best = grid[i]; bestDiff = diff; }
    }
    return best;
  }

  let calendarWeekOffset = 0;
  document.getElementById('prevWeekBtn').onclick = function() { calendarWeekOffset--; renderCalendar(); };
  document.getElementById('nextWeekBtn').onclick = function() { calendarWeekOffset++; renderCalendar(); };
  document.getElementById('todayBtn').onclick   = function() { calendarWeekOffset = 0; renderCalendar(); };
  document.getElementById('showAllDeliveriesBtn').onclick = function() {
    document.getElementById('toggleDeliveriesBtn').click();
    document.getElementById('allDeliveriesSection').scrollIntoView({behavior: 'smooth'});
  };

  // Multi-select
  let selectedSlots = [];
  function clearBookSlotsButton() {
    const btn = document.getElementById('bookSelectedSlotsBtn'); if (btn) btn.remove();
    const clearBtn = document.getElementById('clearSlotsBtn'); if (clearBtn) clearBtn.remove();
  }
  function addBookSlotsButton() {
    clearBookSlotsButton();
    if (!selectedSlots.length) return;
    let btn = document.createElement('button');
    btn.id = 'bookSelectedSlotsBtn';
    btn.className = 'btn btn-primary mb-3 me-2';
    btn.textContent = `Book ${selectedSlots.length} Slot${selectedSlots.length>1?'s':''}`;
    btn.onclick = showMultiSlotBookingForm;
    let clearBtn = document.createElement('button');
    clearBtn.id = 'clearSlotsBtn';
    clearBtn.className = 'btn btn-secondary mb-3';
    clearBtn.textContent = 'Clear Selection';
    clearBtn.onclick = function(){ selectedSlots=[]; document.querySelectorAll('.selected-slot').forEach(td=>td.classList.remove('selected-slot')); clearBookSlotsButton(); };
    const cal = document.getElementById('calendar');
    cal.parentNode.insertBefore(btn, cal);
    cal.parentNode.insertBefore(clearBtn, cal);
  }

  async function renderCalendar() {
    clearBookSlotsButton();
    const calendarDiv = document.getElementById('calendar');
    const timeSlots = generateTimeSlots();
    const today = new Date();
    const monday = getMonday(today, calendarWeekOffset);
    const sunday = new Date(monday); sunday.setDate(sunday.getDate()+6);

    const slotInfo = document.getElementById('slotInfo');
    if (slotInfo) slotInfo.textContent = `${SLOT_INTERVAL_MINUTES} min`;

    // Weather (best effort)
    let weatherByDay = {};
    if (window.WEATHER_ENABLED && window.WEATHER_API_KEY) {
      try {
        const url = `https://api.openweathermap.org/data/2.5/forecast?lat=${encodeURIComponent(WEATHER_LAT)}&lon=${encodeURIComponent(WEATHER_LON)}&units=metric&appid=${encodeURIComponent(WEATHER_API_KEY)}`;
        let weather = await fetch(url, {cache: 'no-store'}).then(res=>res.json());
        if (weather && weather.list) {
          let grouped = {};
          for (let entry of weather.list) {
            let dt = new Date(entry.dt * 1000);
            let ymd = dt.getFullYear()+'-'+(dt.getMonth()+1).toString().padStart(2,'0')+'-'+dt.getDate().toString().padStart(2,'0');
            (grouped[ymd] ||= []).push(entry);
          }
          for (let ymd in grouped) {
            let noon = grouped[ymd].reduce((prev, curr) => {
              let prevHour = new Date(prev.dt * 1000).getHours();
              let currHour = new Date(curr.dt * 1000).getHours();
              return Math.abs(currHour - 12) < Math.abs(prevHour - 12) ? curr : prev;
            });
            weatherByDay[ymd] = { dt: noon.dt, temp: { day: noon.main.temp }, weather: noon.weather };
          }
        }
      } catch (e) {}
    }

    // Week bounds
    const mondayYmd = monday.getFullYear()+'-'+String(monday.getMonth()+1).padStart(2,'0')+'-'+String(monday.getDate()).padStart(2,'0');
    const sundayYmd = sunday.getFullYear()+'-'+String(sunday.getMonth()+1).padStart(2,'0')+'-'+String(sunday.getDate()).padStart(2,'0');

    // Load notes for this week (updates the panel + day headers)
    await fetchDayNotes(mondayYmd, sundayYmd);

    // Fetch deliveries for week
    let deliveries = [];
    let onGridMap = {};
    let offGridByNearestCell = {};
    let offGridCount = 0;

    try {
      let res = await fetch('get_week_deliveries.php?week='+mondayYmd);
      deliveries = await res.json();
      deliveries.forEach(d => {
        let dt = new Date(d.due_datetime.replace(' ','T'));
        const ymd = dt.getFullYear()+'-'+(dt.getMonth()+1).toString().padStart(2,'0')+'-'+dt.getDate().toString().padStart(2,'0');
        const hh = dt.getHours().toString().padStart(2,'0');
        const mm = dt.getMinutes().toString().padStart(2,'0');
        const hhmm = `${hh}:${mm}`;
        const slotKeyExact = `${ymd} ${hhmm}`;

        if (isOnGrid(hhmm)) {
          onGridMap[slotKeyExact] = d;
        } else {
          offGridCount++;
          const nearest = nearestGridHHMM(hhmm);
          const nearestKey = `${ymd} ${nearest}`;
          (offGridByNearestCell[nearestKey] ||= []).push({ ...d, _true_time: hhmm, _nearest_grid: nearest });
        }
      });
    } catch(e) { deliveries = []; }

    // Blackouts
    let blackoutMap = {};
    try{
      const r = await fetch(`/get_blackouts.php?from=${mondayYmd}&to=${sundayYmd}`, {cache:'no-store'});
      const j = await r.json();
      if (j && j.ok && Array.isArray(j.items)){
        for (const b of j.items){
          (blackoutMap[b.date] ||= []).push({ start:b.start||null, end:b.end||null, reason:b.reason||'' });
        }
      }
    }catch(_){}

    const calDebug = document.getElementById('calDebug');
    if (calDebug && IS_ADMIN) { calDebug.style.display=''; calDebug.textContent = `Grid ${WINDOW_START}–${WINDOW_END} / ${SLOT_INTERVAL_MINUTES}m · Off-grid=${offGridCount}`; }

    // Build days array
    let days = [];
    for (let i=0;i<7;i++) { let d = new Date(monday); d.setDate(d.getDate()+i); days.push(d); }

    // Header (weather + 📝 note first line)
    let html = '<table class="table table-bordered calendar-table"><thead><tr><th>Time</th>';
    days.forEach((d,i)=>{
      let ymd = d.getFullYear()+'-'+(d.getMonth()+1).toString().padStart(2,'0')+'-'+d.getDate().toString().padStart(2,'0');
      let w = weatherByDay[ymd];
      let weatherHtml = '';
      if (w) {
        weatherHtml = `
          <div class="weather-header">
            <img src="https://openweathermap.org/img/wn/${w.weather[0].icon}@2x.png" alt="${w.weather[0].main}" title="${w.weather[0].description}">
            <span class="weather-temp">${Math.round(w.temp.day)}°C</span>
            <span class="weather-desc">${w.weather[0].main}</span>
          </div>`;
      }
      const dayNote = notesByDay[ymd] || '';
      const noteHtml = dayNote ? `<span class="day-note" title="${esc(dayNote)}">📝 ${esc(dayNote.split('\n')[0])}</span>` : '';
      let isToday = (calendarWeekOffset === 0 && d.toDateString() == (new Date()).toDateString());
      html += `<th${isToday?' class="today"':''}>
        ${['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]}<br>
        ${formatUK(d).slice(0,10)}
        ${weatherHtml}
        ${noteHtml}
      </th>`;
    });
    html += '</tr></thead><tbody>';

    // Helper: blackout check
    function slotIsBlackout(ymd, hhmm){
      const wins = blackoutMap[ymd]; if (!wins || !wins.length) return false;
      const t = hhmm + ':00';
      for (const w of wins){
        const s = w.start ? w.start+':00' : null;
        const e = w.end   ? w.end+':00'   : null;
        if (s===null && e===null) return true;
        if (s!==null && e===null && t>=s) return true;
        if (s===null && e!==null && t<=e) return true;
        if (s!==null && e!==null && t>=s && t<=e) return true;
      }
      return false;
    }

    // Rows
    for (let s of timeSlots) {
      html += '<tr>';
      html += '<td class="time-col">'+s+'</td>';
      for (let i=0;i<7;i++) {
        let d = new Date(monday);
        d.setDate(d.getDate()+i);
        const ymd = d.getFullYear()+'-'+(d.getMonth()+1).toString().padStart(2,'0')+'-'+d.getDate().toString().padStart(2,'0');
        const slotKey = `${ymd} ${s}`;
        const cellId = 'cell-'+slotKey.replace(/[^a-zA-Z0-9]/g,'');

        const onGridDelivery = onGridMap[slotKey];
        const offGridList = offGridByNearestCell[slotKey] || [];
        const selected = selectedSlots.includes(slotKey);

        if (onGridDelivery) {
          const cancelled = onGridDelivery.status === "Cancelled";
          const unloadingIcon = getUnloadingIconHTML(onGridDelivery.unloading_method);
          html += `<td class="${cancelled ? "slot-booked-cancelled" : "slot-booked"}" id="${cellId}"
              ${IS_ADMIN ? 'draggable="true"' : ''}
              data-delivery-id="${onGridDelivery.id}"
              data-slot-key="${slotKey}"
              tabindex="0"
              title="${IS_ADMIN ? 'Click for details or drag to move' : 'Click for details'}"
            >
              <span class="slot-icon">${unloadingIcon}</span>
              <span class="d-none d-md-inline">${esc(onGridDelivery.supplier)}</span>
              ${offGridList.length ? `<span class="offgrid-badge" title="${esc(offGridList.map(x=>`Off-grid: ${x._true_time}`).join(', '))}">⚠️ x${offGridList.length}</span>` : ``}
            </td>`;
        } else if (slotIsBlackout(ymd, s)) {
          const reasons = (blackoutMap[ymd]||[]).map(w=>w.reason).filter(Boolean).join('; ');
          html += `<td class="slot-blackout" id="${cellId}" data-slot-key="${slotKey}" tabindex="-1" title="${reasons ? ('Blocked: '+esc(reasons)) : 'Blocked'}">
                    <span class="slot-icon"><i class="fa-solid fa-ban"></i></span>
                  </td>`;
        } else if (offGridList.length) {
          const label = offGridList.length === 1
              ? `⚠️ ${esc(offGridList[0].supplier)} (${offGridList[0]._true_time})`
              : `⚠️ ${offGridList.length} off-grid`;
          const title = offGridList.map(x => `${x.supplier} @ ${x._true_time}`).join('\n');

          html += `<td class="slot-free off-grid offgrid-only${selected ? ' selected-slot' : ''}" id="${cellId}"
              data-slot-key="${slotKey}"
              tabindex="0"
              title="${esc(title)}"
            >
              <span class="slot-icon"><i class="fa-solid fa-plus"></i></span>
              <span class="d-none d-md-inline">${label}</span>
            </td>`;
        } else {
          html += `<td class="slot-free${selected ? ' selected-slot' : ''}" id="${cellId}"
              data-slot-key="${slotKey}"
              tabindex="0"
              title="Select slot for booking"><span class="slot-icon"><i class="fa-solid fa-plus"></i></span></td>`;
        }
      }
      html += '</tr>';
    }
    html += '</tbody></table>';
    calendarDiv.innerHTML = html;

    // Interactions
    document.querySelectorAll('.slot-blackout').forEach(td=>{
      td.addEventListener('click', (e) => {
        e.preventDefault();
        const t = td.getAttribute('title') || 'Blocked';
        alert('This time is unavailable.\n' + t.replace(/^Blocked:\s?/, ''));
      });
    });

    document.querySelectorAll('.slot-free').forEach(td=>{
      td.addEventListener('click', function() {
        if (this.classList.contains('offgrid-only')) return;
        let slotKey = this.getAttribute('data-slot-key');
        if (selectedSlots.includes(slotKey)) {
          selectedSlots = selectedSlots.filter(s => s !== slotKey);
          this.classList.remove('selected-slot');
        } else {
          selectedSlots.push(slotKey);
          this.classList.add('selected-slot');
        }
        addBookSlotsButton();
      });

      if (IS_ADMIN) {
        td.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('table-primary'); });
        td.addEventListener('dragleave', function(){ this.classList.remove('table-primary'); });
        td.addEventListener('drop', function(e) {
          this.classList.remove('table-primary');
          let deliveryId = e.dataTransfer.getData('delivery-id');
          let newSlot = this.getAttribute('data-slot-key');
          if (deliveryId) moveDelivery(deliveryId, newSlot);
        });
      } else {
        td.addEventListener('dragover', e => e.preventDefault());
        td.addEventListener('drop', e => { e.preventDefault(); adminOnlyPopup(); });
      }
    });

    document.querySelectorAll('.slot-booked, .slot-booked-cancelled').forEach(td=>{
      td.addEventListener('click', function() {
        let deliveryId = this.getAttribute('data-delivery-id');
        showDeliveryDetails(deliveryId);
      });

      if (IS_ADMIN) {
        td.addEventListener('dragstart', function(e) { e.dataTransfer.setData('delivery-id', this.getAttribute('data-delivery-id')); });
        td.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('table-primary'); });
        td.addEventListener('dragleave', function(){ this.classList.remove('table-primary'); });
        td.addEventListener('drop', function(e) { this.classList.remove('table-primary'); });
      } else {
        td.setAttribute('draggable','false');
      }
    });

    addBookSlotsButton();
  } // renderCalendar()

  // Multi-slot booking modal
  async function showMultiSlotBookingForm() {
    if (!selectedSlots.length) return;
    let sortedSlots = [...selectedSlots].sort();
    let slotsHtml = sortedSlots.map(s => `<li>${s}</li>`).join('');
    const methods = await loadUnloadingMethods();
    const selectHtml = renderUnloadingSelectHtml(methods);

    let html = `<div class="modal-header">
        <h5 class="modal-title">Book ${selectedSlots.length} Delivery Slot${selectedSlots.length>1?'s':''}</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>
    <form id="multiBookForm">
      <div class="modal-body" style="display:grid;gap:0.7em;">
        <div>
          <b>Selected Slots:</b>
          <ul style="margin-bottom:0.5em;">${slotsHtml}</ul>
          <input type="hidden" name="slots" value="${selectedSlots.join(',')}">
        </div>
        <label>Your Name <input type="text" name="user_name" required class="form-control"></label>
        <label>Contractor <input type="text" name="supplier" required class="form-control"></label>
        <label>Material <input type="text" name="material" required class="form-control"></label>
        <label>Quantity <input type="text" name="quantity" required class="form-control"></label>
        <label>Unloading Method ${selectHtml}</label>
        <div id="multiBookMsg" class="mt-1"></div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">Book Slots</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>`;
    document.getElementById('bookingModalContent').innerHTML = html;
    let modal = new bootstrap.Modal(document.getElementById('bookingModal'));
    modal.show();

    document.getElementById('multiBookForm').addEventListener('submit', async function(e){
      e.preventDefault();
      let fd = new FormData(this);
      fd.set('slots', selectedSlots.join(','));
      let res = await fetch('book_delivery_multi.php', {method:'POST', body:fd});
      let txt = await res.text();
      if (/success/i.test(txt)) {
        document.getElementById('multiBookMsg').innerHTML = '<span class="text-success">Booked!</span>';
        setTimeout(()=>{ bootstrap.Modal.getInstance(document.getElementById('bookingModal')).hide(); selectedSlots=[]; renderCalendar(); }, 900);
      } else {
        document.getElementById('multiBookMsg').innerHTML = '<span class="text-danger">'+esc(txt)+'</span>';
      }
    });
  }

  // Move delivery (drag/drop)
  async function moveDelivery(deliveryId, newSlot) {
    if (!IS_ADMIN) { adminOnlyPopup(); return; }
    if (!confirm('Move delivery to '+newSlot+'?')) return;
    let fd = new FormData();
    fd.append('id', deliveryId);
    fd.append('new_slot', newSlot);
    let res = await fetch('move_delivery.php', {method:'POST', body:fd});
    let txt = await res.text();
    if (/success/i.test(txt)) renderCalendar();
    else alert('Move failed: '+txt);
  }

  // Show/hide All Deliveries
  const toggleBtn = document.getElementById('toggleDeliveriesBtn');
  const deliveriesSection = document.getElementById('allDeliveriesSection');
  toggleBtn.addEventListener('click', function() {
    if (deliveriesSection.style.display === 'none') {
      deliveriesSection.style.display = '';
      toggleBtn.textContent = "Hide All Deliveries";
      toggleBtn.setAttribute('aria-expanded', 'true');
    } else {
      deliveriesSection.style.display = 'none';
      toggleBtn.textContent = "Show All Deliveries";
      toggleBtn.setAttribute('aria-expanded', 'false');
    }
  });

  // Initial load
  renderCalendar();
  fetchRequestsPreview();
  </script>

  <?php include 'about_modal.php'; ?>
  <script src="/pwa.php?v=1"></script>
</body>
</html>
