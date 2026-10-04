<?php
// /admin/time-config.php (no helper name clashes)
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php'; // may already define helpers

// Optional: enforce HTTPS
// admin_force_https();

admin_require($pdo);

// ---- Safe HTML escape (in case admin_auth.php didn’t declare h()) ----
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

/* --- Define local helpers ONLY if they don't already exist in includes/settings.php --- */
if (!function_exists('is_valid_hhmm')) {
    function is_valid_hhmm(string $t): bool {
        return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $t);
    }
}
if (!function_exists('cmp_hhmm')) {
    function cmp_hhmm(string $a, string $b): int {
        [$ah,$am] = array_map('intval', explode(':',$a));
        [$bh,$bm] = array_map('intval', explode(':',$b));
        return ($ah*60+$am) <=> ($bh*60+$bm);
    }
}

$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!admin_csrf_check($csrf)) {
        $error = 'Security check failed. Please try again.';
    } else {
        $start = trim((string)($_POST['time_window_start'] ?? ''));
        $end   = trim((string)($_POST['time_window_end'] ?? ''));
        $step  = (int)($_POST['time_interval_minutes'] ?? 20);

        // Validate
        if (!is_valid_hhmm($start)) {
            $error = 'Please enter a valid Start time in HH:MM (24h).';
        } elseif (!is_valid_hhmm($end)) {
            $error = 'Please enter a valid End time in HH:MM (24h).';
        } elseif (cmp_hhmm($start, $end) >= 0) {
            $error = 'End time must be later than Start time.';
        } elseif ($step < 5 || $step > 720) {
            $error = 'Interval must be between 5 and 720 minutes.';
        } else {
            // Save (idempotent upserts)
            set_setting($pdo, 'time_window_start', $start);
            set_setting($pdo, 'time_window_end',   $end);
            set_setting($pdo, 'time_interval_minutes', (string)$step);
            $notice = 'Time settings updated.';
        }
    }
}

// Ensure defaults exist and load current
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo);
$csrf = admin_csrf_token();

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Time Settings · Deliveries Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9;
    --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444; --border:#1f2937;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:900px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .brand{display:flex;align-items:center;gap:10px}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);
       padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .wrap{max-width:900px;margin:24px auto;padding:0 16px}
  h1{margin:0 0 6px;font-size:22px}
  p.muted{margin:0 0 16px;color:var(--muted)}
  .grid{display:grid;grid-template-columns:1fr;gap:16px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px}
  label{display:block;margin:10px 0 6px;color:#cbd5e1}
  input[type="time"], input[type="number"]{
    width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);
    background:#0f172a;color:var(--text);outline:none
  }
  .row{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}
  .msg{margin:10px 0 0;padding:10px 12px;border-radius:12px}
  .msg.ok{background:rgba(34,197,94,.12);border:1px solid #14532d;color:#bbf7d0}
  .msg.err{background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#fecaca}
  .preview{margin-top:10px;color:#cbd5e1;font-size:13px}
  .slots{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .slot{padding:6px 8px;border-radius:10px;background:#0f172a;border:1px solid var(--border);font-size:12px}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="brand">
      <div class="badge">Deliveries · Admin</div>
    </div>
    <div>
      <a class="btn" href="/admin/">Dashboard</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <h1>Time Settings</h1>
  <p class="muted">Define the visible Time column for the booking form and calendar. Changes take effect immediately.</p>

  <?php if ($notice): ?>
    <div class="msg ok"><?= h($notice) ?></div>
  <?php elseif ($error): ?>
    <div class="msg err"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="grid">
    <div class="card">
      <form method="post" action="/admin/time-config.php" autocomplete="off" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
          <div>
            <label for="time_window_start">Start time</label>
            <input id="time_window_start" name="time_window_start" type="time" required value="<?= h($ts['start']) ?>">
          </div>
          <div>
            <label for="time_window_end">End time</label>
            <input id="time_window_end" name="time_window_end" type="time" required value="<?= h($ts['end']) ?>">
          </div>
          <div>
            <label for="time_interval_minutes">Interval (minutes)</label>
            <input id="time_interval_minutes" name="time_interval_minutes" type="number" min="5" max="720" step="1" required value="<?= (int)$ts['interval'] ?>">
          </div>
        </div>
        <div class="row">
          <button class="btn primary" type="submit">Save Settings</button>
        </div>
      </form>

      <div class="preview">
        <strong>Preview slots:</strong>
        <div id="slots" class="slots"></div>
      </div>
    </div>
  </div>
</div>

<script>
<?php echo_time_settings_js($pdo); ?>

function toMinutes(hhmm){ const [h,m]=hhmm.split(':').map(Number); return h*60+m; }
function pad2(n){ return String(n).padStart(2,'0'); }
function fromMinutes(mins){ const h=Math.floor(mins/60), m=mins%60; return pad2(h)+':'+pad2(m); }

function buildSlots(start, end, step){
  const out = [];
  let a = toMinutes(start), b = toMinutes(end);
  for(let t=a; t<=b; t+=step){ out.push(fromMinutes(t)); }
  return out;
}

function renderPreview(){
  const start = document.getElementById('time_window_start').value || WINDOW_START;
  const end   = document.getElementById('time_window_end').value   || WINDOW_END;
  const step  = parseInt(document.getElementById('time_interval_minutes').value || SLOT_INTERVAL_MINUTES, 10);
  const slots = buildSlots(start, end, step).slice(0, 60); // cap preview to first 60 slots
  const wrap  = document.getElementById('slots');
  wrap.innerHTML = slots.map(s => `<span class="slot">${s}</span>`).join('');
}

['time_window_start','time_window_end','time_interval_minutes'].forEach(id=>{
  document.getElementById(id).addEventListener('input', renderPreview);
});
renderPreview();
</script>
</body>
</html>
