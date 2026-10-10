<?php
// /admin/index.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

admin_require($pdo);
$me = admin_current($pdo);

// Ensure time settings exist (safe/idempotent)
ensure_time_defaults($pdo);

// ---------- helpers ----------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/** Union count of subscribers across both possible tables. */
function count_push_subscribers(PDO $pdo): int {
  $total = 0;
  foreach ([
    "SELECT COUNT(*) FROM push_subscriptions",
    "SELECT COUNT(*) FROM webpush_subscriptions",
  ] as $sql) {
    try { $total += (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) {}
  }
  return $total;
}

/** Reusable card tile. */
function card(string $href, string $title, string $desc, string $tag = ''): string {
  $tagHtml = $tag ? '<span class="tag">'.$tag.'</span>' : '';
  return <<<HTML
    <a class="card" href="{$href}">
      <div class="card-title">{$title} {$tagHtml}</div>
      <div class="card-desc">{$desc}</div>
    </a>
HTML;
}

// ---------- self-heal tables for new features ----------
try {
  // Blackouts
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS blackouts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      date DATE NOT NULL,
      start TIME NULL,
      end TIME NULL,
      reason VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX(date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  // Day notes
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS day_notes (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      day DATE NOT NULL UNIQUE,
      note TEXT NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  // ignore
}

// ---------- POST: Save / mutate settings & data ----------
$saveMsg = '';
$saveErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'save_weather') {
      // Normalise inputs
      $show   = isset($_POST['show_weather']) && $_POST['show_weather'] === '1' ? '1' : '0';
      $apiKey = trim((string)($_POST['weather_api_key'] ?? ''));
      $lat    = trim((string)($_POST['weather_lat'] ?? ''));
      $lon    = trim((string)($_POST['weather_lon'] ?? ''));

      // Basic validation
      if ($lat !== '' && !preg_match('/^-?\d{1,2}(\.\d+)?$/', $lat)) {
        throw new RuntimeException('Latitude must be a number between -90 and 90.');
      }
      if ($lon !== '' && !preg_match('/^-?\d{1,3}(\.\d+)?$/', $lon)) {
        throw new RuntimeException('Longitude must be a number between -180 and 180.');
      }

      // Persist — use the same key as the public page
      set_setting($pdo, 'weather_show', $show);
      set_setting($pdo, 'weather_api_key', $apiKey);
      if ($lat !== '') set_setting($pdo, 'weather_lat',  $lat);
      if ($lon !== '') set_setting($pdo, 'weather_lon',  $lon);

      $saveMsg = 'Weather settings saved.';
    }

    if ($action === 'add_blackout') {
      $date = trim((string)($_POST['date'] ?? ''));
      $start = trim((string)($_POST['start'] ?? ''));
      $end   = trim((string)($_POST['end'] ?? ''));
      $reason= trim((string)($_POST['reason'] ?? ''));

      if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Please provide a blackout date (YYYY-MM-DD).');
      }
      // Empty start or end means open-ended (from start of day / to end of day)
      $startSql = $start !== '' ? $start.':00' : null;
      $endSql   = $end   !== '' ? $end.':00'   : null;

      $st = $pdo->prepare("INSERT INTO blackouts(date,start,end,reason) VALUES (?,?,?,?)");
      $st->execute([$date, $startSql, $endSql, $reason !== '' ? $reason : null]);

      $saveMsg = 'Blackout added.';
    }

    if ($action === 'delete_blackout') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid blackout id.');
      $st = $pdo->prepare("DELETE FROM blackouts WHERE id = ?");
      $st->execute([$id]);
      $saveMsg = 'Blackout deleted.';
    }

    if ($action === 'add_note') {
      $day  = trim((string)($_POST['day'] ?? ''));
      $note = trim((string)($_POST['note'] ?? ''));
      if (!$day || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        throw new RuntimeException('Please provide a note date (YYYY-MM-DD).');
      }
      if ($note === '') throw new RuntimeException('Note cannot be empty.');

      // Upsert on day
      $st = $pdo->prepare("INSERT INTO day_notes(day, note) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = CURRENT_TIMESTAMP");
      $st->execute([$day, $note]);
      $saveMsg = 'Day note saved.';
    }

    if ($action === 'delete_note') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Invalid note id.');
      $st = $pdo->prepare("DELETE FROM day_notes WHERE id = ?");
      $st->execute([$id]);
      $saveMsg = 'Day note deleted.';
    }

  } catch (Throwable $e) {
    $saveErr = $e->getMessage();
  }
}

// ---------- Fetch settings for display ----------
$time = get_time_settings($pdo); // ['start','end','interval']
$subCount = count_push_subscribers($pdo);

// Weather settings (match public key names)
$show_weather   = get_setting($pdo, 'weather_show', '0');             // "1" or "0"
$weather_api    = get_setting($pdo, 'weather_api_key', '');
$weather_lat    = get_setting($pdo, 'weather_lat', '53.4808');        // Manchester
$weather_lon    = get_setting($pdo, 'weather_lon', '-2.2426');

// ---------- Fetch upcoming blackouts & nearby notes ----------
$today = date('Y-m-d');
$blackouts = [];
$notes     = [];

try {
  $q = $pdo->prepare("SELECT id, date, start, end, reason FROM blackouts
                      WHERE date >= ? ORDER BY date ASC, COALESCE(start,'00:00:00') ASC LIMIT 100");
  $q->execute([$today]);
  $blackouts = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

try {
  $q = $pdo->prepare("SELECT id, day, note, updated_at FROM day_notes
                      WHERE day >= DATE_SUB(?, INTERVAL 14 DAY)
                      ORDER BY day ASC LIMIT 100");
  $q->execute([$today]);
  $notes = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ---------- Recent broadcasts (self-heal table + notes column) ----------
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS push_broadcasts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      title VARCHAR(255) NOT NULL,
      body TEXT NOT NULL,
      url TEXT NULL,
      icon TEXT NULL,
      badge TEXT NULL,
      tag VARCHAR(128) NULL,
      urgency VARCHAR(16) NOT NULL DEFAULT 'normal',
      require_interaction TINYINT(1) NOT NULL DEFAULT 0,
      sent INT NOT NULL DEFAULT 0,
      failed INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  // add notes if missing
  try { $pdo->query("SELECT notes FROM push_broadcasts LIMIT 0"); }
  catch (Throwable $e) { $pdo->exec("ALTER TABLE push_broadcasts ADD COLUMN notes TEXT NULL AFTER body"); }
} catch (Throwable $e) { /* ignore */ }

$recent = [];
try {
  $q = $pdo->query("SELECT id, created_at, title, body, tag, urgency, sent, failed, notes FROM push_broadcasts ORDER BY created_at DESC, id DESC LIMIT 3");
  if ($q) $recent = $q->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Optional prefill from query string (used by push_diag)
$prefill = [
  'title' => (string)($_GET['prefill_title'] ?? ''),
  'body'  => (string)($_GET['prefill_body']  ?? ''),
  'tag'   => (string)($_GET['prefill_tag']   ?? ''),
  'url'   => (string)($_GET['prefill_url']   ?? ''),
];

?><!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Admin Dashboard · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9;
    --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444; --border:#1f2937;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:1100px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .brand{display:flex;align-items:center;gap:10px}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .me{color:var(--muted);font-size:13px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);
       padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none;display:inline-block}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .btn.danger{background:rgba(239,68,68,.15);border-color:#b91c1c;color:#fecaca}
  .btn.ghost{background:transparent}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  h1{margin:0 0 6px;font-size:22px}
  p.muted{margin:0 0 16px;color:var(--muted)}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:18px}
  .card{display:block;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;text-decoration:none;color:inherit;transition:transform .08s ease, box-shadow .12s ease, border-color .12s ease}
  .card:hover{transform:translateY(-2px);border-color:#334155;box-shadow:0 10px 28px rgba(0,0,0,.35)}
  .card-title{font-weight:700;margin-bottom:6px}
  .card-desc{color:var(--muted)}
  .tag{margin-left:8px;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:11px;font-weight:600}
  .panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;margin-top:16px}
  .kv{display:flex;flex-wrap:wrap;gap:10px;font-size:13px;color:#cbd5e1}
  .kv .item{background:#0f172a;border:1px solid var(--border);border-radius:10px;padding:8px 10px}
  .kv .item b{color:#e5e7eb}
  .alert{border-radius:12px;padding:10px 12px;margin:12px 0;font-size:13px}
  .alert.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);color:#bbf7d0}
  .alert.error{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fecaca}
  footer{color:var(--muted);font-size:12px;margin:24px 0;text-align:center}

  /* form controls */
  .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
  label{display:block;font-weight:600;margin-bottom:6px}
  .ctl, textarea, select, input[type="text"], input[type="url"], input[type="number"], input[type="date"], input[type="time"]{
    width:100%; background:#0f172a; color:var(--text); border:1px solid var(--border);
    border-radius:10px; padding:10px 12px; font:inherit;
  }
  textarea{min-height:90px; resize:vertical}
  .row{display:flex;gap:12px;flex-wrap:wrap}
  .row > .col{flex:1 1 240px}
  .hint{color:var(--muted);font-size:12px;margin-top:4px}

  /* mini table */
  table.mini{width:100%;border-collapse:collapse;margin-top:6px}
  table.mini th, table.mini td{padding:8px;border-bottom:1px solid #1f2937;vertical-align:top}
  table.mini th{color:#cbd5e1;text-align:left;font-weight:700}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0b1528;color:#cbd5e1;font-size:12px}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="brand">
      <div class="badge">Deliveries · Admin</div>
      <div class="badge" title="Union of push_subscriptions & webpush_subscriptions"><?= (int)$subCount ?> subscribed</div>
    </div>
    <div class="me">
      <?= h($me['email'] ?? 'admin') ?>
      &nbsp;·&nbsp;
      <a class="btn" href="/admin/account.php">Account</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <h1>Dashboard</h1>
  <p class="muted">Configure booking behaviour and master lists. Changes apply immediately to the public booking form and calendar.</p>

  <?php if ($saveMsg): ?>
    <div class="alert success"><?= h($saveMsg) ?></div>
  <?php endif; ?>
  <?php if ($saveErr): ?>
    <div class="alert error"><?= h($saveErr) ?></div>
  <?php endif; ?>

  <div class="grid">
	<?= card('/', 'Back to main Site', 'Open the public deliveries page in a new tab.','Live'); ?> 
    <?= card('/admin/logistics.php', 'Gates, equipment & companies', 'Manage gate capacity, cranes, forklifts and private company access.', 'New'); ?>
    <?= card('/admin/time-config.php', 'Time Settings', 'Define the visible Time column: start, end, and slot interval.', 'Live'); ?>
    <?= card('/admin/unloading-methods.php', 'Unloading Methods', 'Manage the selectable unloading options (enable/disable, order, add new options).', 'Live'); ?>
    <?= card('/admin/account.php', 'Admin Account', 'Change admin email and password.','Live'); ?>
    <?= card('/admin/users.php', 'Admin Users', 'Invite additional admins, deactivate, or reset passwords.','Live'); ?>
	<?= card('/admin/day_notes.php', 'Day Notes', 'Short messages shown above each calendar day.','Live'); ?>
    <?= card('/admin/notifications.php', 'Notifications', 'Broadcast history (what was sent and when) Scroll down to send a Push Notification to all users.','Live'); ?>
    <?= card('/admin/push_diag.php', 'Push Diagnostics', 'Quick checks for service worker & subscriptions.','Live'); ?>
    <?= card('/admin/push_export.php?format=csv', 'Export CSV', 'Download Notification history as CSV.','In Development'); ?>
    <?= card('/admin/push_export.php?format=pdf', 'Export PDF', 'Printable PDF of Notification history.','In Development'); ?>
    <?= card('/admin/metrics.php', 'Metrics', 'Trends, capacity, status mix & more.','Live'); ?>
	<?= card('/admin/qr_poster.php', 'QR Poster (A4)', 'Printable poster with site QR & logo.','Live'); ?>
    <?= card('/admin/help.php', 'Help & Guide', 'Short, friendly instructions for admins.','Live'); ?>
  </div>

  <div class="panel">
    <div class="kv">
      <div class="item"><b>Current deliveries time window:</b> <?= h($time['start']) ?> → <?= h($time['end']) ?></div>
      <div class="item"><b>Interval:</b> <?= (int)$time['interval'] ?> minutes</div>
      <div class="item"><b>Uniqueness:</b> Per minute (DB-enforced)</div>
      <div class="item"><b>Push subscribers:</b> <?= (int)$subCount ?></div>
    </div>
  </div>

  <!-- Calendar Weather Settings -->
  <div class="panel">
    <h2 style="margin:0 0 8px;font-size:18px">Calendar Weather</h2>
    <p class="muted" style="margin-top:0">Controls weather shown above each day in the public calendar.</p>

    <form method="post" class="form-grid" action="/admin/index.php">
      <input type="hidden" name="action" value="save_weather">

      <div>
        <label>Show Weather</label>
        <select name="show_weather" class="ctl">
          <option value="0"<?= $show_weather==='0'?' selected':''; ?>>Off</option>
          <option value="1"<?= $show_weather==='1'?' selected':''; ?>>On</option>
        </select>
        <div class="hint">If Off, no weather requests are made on the public page.</div>
      </div>

      <div>
        <label>OpenWeather API Key</label>
        <input class="ctl" type="text" name="weather_api_key" placeholder="key from openweathermap.org" value="<?= h($weather_api) ?>">
        <div class="hint">Free key from openweathermap.org (Forecast API).</div>
      </div>

      <div>
        <label>Latitude</label>
        <input class="ctl" type="text" name="weather_lat" placeholder="e.g. 53.4808" value="<?= h($weather_lat) ?>">
      </div>

      <div>
        <label>Longitude</label>
        <input class="ctl" type="text" name="weather_lon" placeholder="e.g. -2.2426" value="<?= h($weather_lon) ?>">
      </div>

      <div style="grid-column:1 / -1;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn primary" type="submit">Save Weather Settings</button>
        <a class="btn" href="/" target="_blank" rel="noopener">Open Public Page</a>
      </div>
    </form>

    <div class="hint" style="margin-top:10px">
      Tip: Leave API key blank or set “Show Weather” to Off to disable weather completely.
    </div>
  </div>

  <!-- Blackout Windows -->
  <div class="panel">
    <h2 style="margin:0 0 8px;font-size:18px">Blackout Windows / Exceptions</h2>
    <p class="muted" style="margin-top:0">Greys out the calendar and blocks bookings in these periods (e.g. bank holidays, crane maintenance). Leave “Start” and/or “End” blank for open-ended within the day.</p>

    <form method="post" class="form-grid" action="/admin/index.php">
      <input type="hidden" name="action" value="add_blackout">
      <div>
        <label>Date (YYYY-MM-DD)</label>
        <input class="ctl" type="date" name="date" required>
      </div>
      <div>
        <label>Start (HH:MM, optional)</label>
        <input class="ctl" type="time" name="start" placeholder="e.g. 08:00">
        <div class="hint">Empty = from start of day</div>
      </div>
      <div>
        <label>End (HH:MM, optional)</label>
        <input class="ctl" type="time" name="end" placeholder="e.g. 12:00">
        <div class="hint">Empty = to end of day</div>
      </div>
      <div style="grid-column:1 / -1">
        <label>Reason (optional)</label>
        <input class="ctl" type="text" name="reason" placeholder="Bank holiday / crane maintenance / site meeting …">
      </div>
      <div style="grid-column:1 / -1;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn primary" type="submit">Add Blackout</button>
      </div>
    </form>

    <h3 style="margin:12px 0 6px;font-size:15px">Upcoming Blackouts</h3>
    <?php if (!$blackouts): ?>
      <p class="muted">None scheduled.</p>
    <?php else: ?>
      <div style="overflow:auto">
        <table class="mini">
          <thead>
            <tr>
              <th style="width:130px">Date</th>
              <th style="width:140px">Window</th>
              <th>Reason</th>
              <th style="width:110px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($blackouts as $b): ?>
            <tr>
              <td><?= h($b['date']) ?></td>
              <td>
                <?php
                  $s = $b['start'] ? substr($b['start'],0,5) : '';
                  $e = $b['end']   ? substr($b['end'],0,5)   : '';
                  if ($s==='' && $e==='') echo 'All day';
                  elseif ($s!=='' && $e==='') echo h($s).' → end';
                  elseif ($s==='' && $e!=='') echo 'start → '.h($e);
                  else echo h($s).' → '.h($e);
                ?>
              </td>
              <td><?= h((string)$b['reason']) ?></td>
              <td>
                <form method="post" action="/admin/index.php" onsubmit="return confirm('Delete this blackout?');" style="display:inline">
                  <input type="hidden" name="action" value="delete_blackout">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Per-day Notes -->
  <div class="panel">
    <h2 style="margin:0 0 8px;font-size:18px">Per-day Notes</h2>
    <p class="muted" style="margin-top:0">A short note (e.g. “Road closure Tue AM”). Appears under the date on the public calendar.</p>

    <form method="post" class="form-grid" action="/admin/index.php">
      <input type="hidden" name="action" value="add_note">
      <div>
        <label>Date (YYYY-MM-DD)</label>
        <input class="ctl" type="date" name="day" required>
      </div>
      <div style="grid-column:1 / -1">
        <label>Note</label>
        <textarea name="note" class="ctl" placeholder="Road closure 07:00–11:00. Use Gate B."></textarea>
      </div>
      <div style="grid-column:1 / -1;display:flex;gap:8px;justify-content:flex-end">
        <button class="btn primary" type="submit">Save Note</button>
      </div>
    </form>

    <h3 style="margin:12px 0 6px;font-size:15px">Upcoming / Recent Notes</h3>
    <?php if (!$notes): ?>
      <p class="muted">No notes.</p>
    <?php else: ?>
      <div style="overflow:auto">
        <table class="mini">
          <thead>
            <tr>
              <th style="width:130px">Date</th>
              <th>Note</th>
              <th style="width:110px">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($notes as $n): ?>
            <tr>
              <td><?= h($n['day']) ?></td>
              <td><?= nl2br(h((string)$n['note'])) ?></td>
              <td>
                <form method="post" action="/admin/index.php" onsubmit="return confirm('Delete this note?');" style="display:inline">
                  <input type="hidden" name="action" value="delete_note">
                  <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Send Push -->
  <div class="panel">
    <div class="row" style="align-items:center;justify-content:space-between;margin-bottom:8px">
      <h2 style="margin:0;font-size:18px">Send Push Notification</h2>
      <span class="hint"><?= (int)$subCount ?> device<?= $subCount==1?'':'s' ?> subscribed</span>
    </div>

    <div id="pushAlert" class="alert" style="display:none"></div>

    <form id="pushForm" method="post" action="/admin/push-send.php">
      <div class="form-grid">
        <div>
          <label>Title</label>
          <input class="ctl" type="text" name="title" placeholder="e.g. Delivery at Gate" required value="<?= h($prefill['title']) ?>">
        </div>
        <div>
          <label>Click Action URL (optional)</label>
          <input class="ctl" type="url" name="url" placeholder="/gate.php?id=123 or https://..." value="<?= h($prefill['url']) ?>">
          <div class="hint">Opened when the user taps the notification.</div>
        </div>
      </div>

      <div style="margin-top:12px">
        <label>Message</label>
        <textarea name="body" class="ctl" placeholder="Short message that will appear in the notification" required><?= h($prefill['body']) ?></textarea>
      </div>

      <div class="form-grid" style="margin-top:12px">
        <div>
          <label>Urgency</label>
          <select name="urgency" class="ctl">
            <option value="normal" selected>normal</option>
            <option value="high">high</option>
            <option value="low">low</option>
            <option value="very-low">very-low</option>
          </select>
        </div>
        <div>
          <label>Require Interaction</label>
          <select name="require_interaction" class="ctl">
            <option value="0" selected>No</option>
            <option value="1">Yes</option>
          </select>
          <div class="hint">If Yes, notification stays until dismissed.</div>
        </div>
        <div>
          <label>Tag / Channel (optional)</label>
          <input class="ctl" type="text" name="tag" placeholder="e.g. gate-alert" value="<?= h($prefill['tag']) ?>">
          <div class="hint">When set, only clients with this tag may be targeted (server must support).</div>
        </div>
      </div>

      <div class="form-grid" style="margin-top:12px">
        <div>
          <label>Icon (optional)</label>
          <input class="ctl" type="url" name="icon" placeholder="/icon.php?f=icon-192.png">
        </div>
        <div>
          <label>Badge (optional)</label>
          <input class="ctl" type="url" name="badge" placeholder="/icon.php?f=icon-96.png">
        </div>
      </div>

      <div class="form-grid" style="margin-top:12px">
        <div>
          <label>Target single endpoint (optional)</label>
          <input class="ctl" type="text" name="endpoint" placeholder="Paste a subscription endpoint URL">
          <div class="hint">If set, sends only to this endpoint (ignores tag).</div>
        </div>
        <div>
          <label>Notes (saved to history)</label>
          <input class="ctl" type="text" name="notes" placeholder="Internal note e.g. ‘Test before shift change’">
        </div>
      </div>

      <div class="row" style="margin-top:10px;align-items:center">
        <div class="col" style="min-width:200px">
          <label><input type="checkbox" name="dry_run" value="1"> Dry-run (validate & count only)</label>
          <div class="hint">No notifications will be sent; you’ll get a count.</div>
        </div>
        <div class="col" style="display:flex;gap:8px;justify-content:flex-end">
          <button id="testNewestBtn" class="btn" type="button" title="Send to the most recently created subscription only">Test push to newest</button>
          <button class="btn primary" type="submit">Send</button>
        </div>
      </div>
    </form>

    <p class="hint" style="margin-top:12px">
      Users must have “Enable Notifications” switched on from the main page. Dead endpoints are auto-pruned.
    </p>
  </div>

  <!-- Recent results -->
  <div class="panel">
    <h2 style="margin:0 0 8px;font-size:18px">Recent Broadcasts</h2>
    <?php if (!$recent): ?>
      <p class="muted">No broadcasts yet.</p>
    <?php else: ?>
      <div style="overflow:auto">
        <table class="mini">
          <thead>
            <tr>
              <th style="width:110px">When</th>
              <th>Title / Body</th>
              <th style="width:160px">Meta</th>
              <th style="width:100px">Result</th>
              <th style="width:200px">Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td class="muted"><?= h($r['created_at']) ?></td>
                <td>
                  <div><b><?= h($r['title']) ?></b></div>
                  <div class="muted"><?= nl2br(h($r['body'])) ?></div>
                </td>
                <td class="muted">
                  <?php if (!empty($r['tag'])): ?><div>Tag: <code><?= h($r['tag']) ?></code></div><?php endif; ?>
                  <div>Urgency: <span class="chip"><?= h($r['urgency']) ?></span></div>
                </td>
                <td><b><?= (int)$r['sent'] ?></b> / <b><?= (int)$r['failed'] ?></b></td>
                <td><?= nl2br(h((string)($r['notes'] ?? ''))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px;justify-content:flex-end">
        <a class="btn" href="/admin/notifications.php">View full history</a>
        <a class="btn" href="/admin/push_export.php?format=csv">Export CSV</a>
        <a class="btn" href="/admin/push_export.php?format=pdf">Export PDF</a>
      </div>
    <?php endif; ?>
  </div>

  <footer>
    Deliveries Admin · <?= date('Y') ?> · Session active
  </footer>
</div>

<script>
(function(){
  const form = document.getElementById('pushForm');
  const alertBox = document.getElementById('pushAlert');
  const testBtn = document.getElementById('testNewestBtn');

  function showAlert(kind, msg){
    alertBox.className = 'alert ' + (kind === 'error' ? 'error' : 'success');
    alertBox.innerText = msg;
    alertBox.style.display = '';
  }

  async function submitTo(url, data){
    const res = await fetch(url, { method:'POST', body: data });
    const txt = await res.text();
    let j=null; try{ j=JSON.parse(txt) }catch(e){}
    if(!res.ok || !j){
      throw new Error(`Error ${res.status}${txt ? (': '+txt) : ''}`);
    }
    if(!j.ok){
      const extra = j.first_error ? ` (${j.first_error})` : '';
      throw new Error(j.error ? j.error + extra : 'Unknown error');
    }
    return j;
  }

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    alertBox.style.display = 'none';
    try {
      const data = new FormData(form);
      const j = await submitTo(form.action, data);
      if (j.dry_run){
        showAlert('success', `Dry-run OK: would target ${j.count ?? 0} device(s).`);
      } else {
        showAlert('success', `Sent: ${j.sent ?? 0}, Failed: ${j.failed ?? 0}`);
        form.reset();
      }
    } catch(err){
      showAlert('error', err && err.message ? err.message : String(err));
    }
  });

  testBtn.addEventListener('click', async function(){
    alertBox.style.display = 'none';
    try{
      const data = new FormData(form);
      data.append('test_newest','1'); // server: send to newest endpoint only
      const j = await submitTo(form.action, data);
      showAlert('success', `Test to newest: Sent ${j.sent ?? 0}, Failed ${j.failed ?? 0}`);
    }catch(err){
      showAlert('error', err && err.message ? err.message : String(err));
    }
  });
})();
</script>
</body>
</html>
