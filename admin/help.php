<?php
// /admin/help.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

admin_require($pdo);
$me = admin_current($pdo);

// Time settings for “What changes on the public page?”
ensure_time_defaults($pdo);
$time = get_time_settings($pdo);

// Subscriber count (supports either table name)
$subCount = null;
try {
    $subCount = (int)$pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
} catch (Throwable $e) {
    try { $subCount = (int)$pdo->query("SELECT COUNT(*) FROM webpush_subscriptions")->fetchColumn(); }
    catch (Throwable $e2) { $subCount = null; }
}

function h2(string $t){ echo '<h2 id="'.preg_replace('/\s+/','-',strtolower($t)).'">'.htmlspecialchars($t).'</h2>'; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Help & Guide · Deliveries Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9; --border:#1f2937;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:960px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .wrap{max-width:960px;margin:24px auto;padding:0 16px}
  .card{background:#0f172a;border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none}
  .btn.primary{background:var(--accent);border-color:transparent;color:#fff}
  h1{margin:0 0 8px;font-size:26px}
  h2{margin:22px 0 8px;font-size:20px}
  .muted{color:var(--muted)}
  ul,ol{margin:8px 0 12px 22px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0b1528;color:#cbd5e1;font-size:12px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
  .kvs{display:flex;gap:10px;flex-wrap:wrap}
  .kv{background:#0b1528;border:1px solid var(--border);padding:8px 10px;border-radius:10px}
  a{color:#8bcbff}
  .toc a{display:block;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:#0f172a;text-decoration:none;margin-bottom:8px}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="badge">Deliveries · Admin</div>
    <div>
      <a class="btn" href="/admin/">Dashboard</a>
      <a class="btn" href="/">Back to Site</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <h1>Admin Guide</h1>
  <p class="muted">A quick, non-technical guide to using the Deliveries Admin. If you can use email, you can use this 😊</p>

  <div class="grid toc">
    <a href="#overview">Overview</a>
    <a href="#logging-in">Logging in & out</a>
    <a href="#dashboard">What each tile does</a>
    <a href="#push">Push notifications & PWA</a>
    <a href="#common-tasks">Common tasks</a>
    <a href="#qr-gate-flow">QR “Gate” flow</a>
    <a href="#how-it-affects-site">What changes on the public page?</a>
    <a href="#roles">Roles & permissions</a>
    <a href="#tips">Tips & troubleshooting</a>
  </div>

  <div class="card" id="overview">
    <?php h2('Overview'); ?>
    <p>The admin area controls four things:</p>
    <ul>
      <li><b>Time Settings</b> – when bookings are allowed and the slot length.</li>
      <li><b>Unloading Methods</b> – the choices people see (e.g. Forklift, Crane, By hand).</li>
      <li><b>Users</b> – who can log into this admin panel.</li>
      <li><b>Push & PWA</b> – one-tap push notifications to all subscribed devices, plus a simple installable app (PWA).</li>
    </ul>
    <p>Changes here update the public booking form and weekly calendar straight away.</p>
  </div>

  <div class="card" id="logging-in">
    <?php h2('Logging in & out'); ?>
    <ol>
      <li>Go to <span class="pill">/admin</span> and sign in with your email & password.</li>
      <li>Use the <b>Logout</b> button (top right) when you’re finished.</li>
    </ol>
  </div>

  <div class="card" id="dashboard">
    <?php h2('What each tile does'); ?>
    <ul>
      <li><b>Time Settings</b> – Choose a start time, end time, and slot interval (e.g. <i>08:00 → 18:00, 20 minutes</i>). The calendar’s left “Time” column uses this.</li>
      <li><b>Unloading Methods</b> – Turn options on/off and set their order. Whatever is <i>enabled</i> appears in the “Unloading Method” dropdown everywhere.</li>
      <li><b>Users</b> – Create or deactivate admin logins. Owners can also change roles.</li>
      <li><b>Admin Account</b> – Change <i>your</i> email or password.</li>
      <li><b>Notifications</b> – Lists broadcast history (what was sent and when).</li>
      <li><b>Push Diagnostics</b> – One-page checks for service worker, subscription count and a local test notification.</li>
      <li><b>Back to Site</b> – Opens the public calendar page in a new tab.</li>
    </ul>
  </div>

  <div class="card" id="push">
    <?php h2('Push notifications & PWA'); ?>
    <p>The site is a Progressive Web App (PWA). Users can install it and enable push to receive alerts such as “Delivery arrived at Gate”.</p>
    <div class="kvs" style="margin-bottom:8px">
      <?php if ($subCount !== null): ?>
        <div class="kv"><b>Subscribed devices:</b> <?= (int)$subCount ?></div>
      <?php else: ?>
        <div class="kv"><b>Subscribed devices:</b> n/a</div>
      <?php endif; ?>
    </div>
    <ul>
      <li><b>How users opt in:</b> On the main page there’s an <i>Enable Notifications</i> button. The browser asks permission and stores a secure subscription for this device only.</li>
      <li><b>Sending a broadcast:</b> On the admin Dashboard, use <i>Send Push Notification</i> (title + message). Everyone subscribed gets it instantly. Tap opens your optional URL.</li>
      <li><b>History:</b> See <span class="pill">/admin/notifications.php</span> for a list of broadcasts (title, body, URL, sent/failed).</li>
      <li><b>Diagnostics:</b> <span class="pill">/admin/push_diag.php</span> verifies the service worker, subscription endpoint and tries a local self-test notification.</li>
      <li><b>Self-healing DB:</b> the system upgrades its push tables automatically if a new column is needed (no manual SQL).</li>
    </ul>
    <p class="muted">Users can remove notifications at any time from their browser settings. If an endpoint dies, it’s cleaned automatically during a send.</p>
  </div>

  <div class="card" id="common-tasks">
    <?php h2('Common tasks'); ?>

    <p><b>1) Change the booking window or slot length</b></p>
    <ol>
      <li>Open <b>Time Settings</b>.</li>
      <li>Set <i>Start</i>, <i>End</i>, and <i>Interval</i> (minutes).</li>
      <li>Save. The public calendar will immediately show the new time rows.</li>
    </ol>

    <p><b>2) Add or rename an unloading method</b></p>
    <ol>
      <li>Open <b>Unloading Methods</b>.</li>
      <li>Add a new method (e.g. “HIAB”) or edit an existing one.</li>
      <li>Toggle <i>Enabled</i> to control visibility and drag to reorder if available.</li>
      <li>Save. The booking form dropdown updates straight away.</li>
    </ol>

    <p><b>3) Invite a colleague to the admin</b> (owner only)</p>
    <ol>
      <li>Open <b>Users</b>.</li>
      <li>Use the <i>Add Admin</i> form: enter email, password, choose role, tick <i>Active</i>.</li>
      <li>They can now log in at <span class="pill">/admin</span>.</li>
    </ol>

    <p><b>4) Send a push alert to all devices</b></p>
    <ol>
      <li>On the Dashboard, fill in <i>Send Push Notification</i> (Title + Message).</li>
      <li>Optionally add a <i>Click URL</i> (e.g. a Gate page) and set urgency.</li>
      <li>Click <b>Send to All</b>. You’ll see “Sent / Failed” totals and the item will appear under <span class="pill">Notifications</span>.</li>
    </ol>
  </div>

  <div class="card" id="qr-gate-flow">
    <?php h2('QR “Gate” flow'); ?>
    <p>Each delivery on the run sheet has a QR. Scanning it at the gate opens the Gate page for that delivery with one-tap <b>Arrived Now</b> / <b>Completed</b>. When marked, a push can be sent to all subscribers if you choose to broadcast major events (e.g. “Delivery arrived at Gate”).</p>
    <ul>
      <li>Run Sheet link: <span class="pill">/run_sheet.php?date=YYYY-MM-DD&amp;qr=1</span></li>
      <li>QRs are rendered server-side (no external dependencies) so they work offline at print time.</li>
    </ul>
  </div>

  <div class="card" id="how-it-affects-site">
    <?php h2('What changes on the public page?'); ?>
    <div class="kvs" style="margin-bottom:8px">
      <div class="kv"><b>Current window:</b> <?= htmlspecialchars($time['start']) ?> → <?= htmlspecialchars($time['end']) ?></div>
      <div class="kv"><b>Slot length:</b> <?= (int)$time['interval'] ?> min</div>
    </div>
    <ul>
      <li>The calendar’s <b>time rows</b> and <b>slot spacing</b> follow your Time Settings.</li>
      <li>The <b>Unloading Method</b> dropdown only shows <i>enabled</i> options, in your order.</li>
      <li>Only admins can <b>edit / cancel / reschedule</b> existing bookings. Non-admins see a pop-up telling them to contact an admin if they try.</li>
      <li>A new <b>Enable Notifications</b> button appears; once granted, it hides automatically for that device.</li>
      <li>Installed PWA looks and behaves like an app (full-screen, app icon, offline caching for essentials).</li>
    </ul>
  </div>

  <div class="card" id="roles">
    <?php h2('Roles & permissions'); ?>
    <ul>
      <li><b>Owner</b> – Full access, including the <b>Users</b> page (create, disable, delete admins, set roles) and all push tools.</li>
      <li><b>Admin</b> – Can change Time Settings, Unloading Methods, send broadcasts and manage deliveries; cannot access the Users page.</li>
    </ul>
  </div>

  <div class="card" id="tips">
    <?php h2('Tips & troubleshooting'); ?>
    <ul>
      <li><b>No notification received?</b> Ensure the device pressed <i>Enable Notifications</i>, the browser shows permission as <i>Allowed</i>, and the device has internet.</li>
      <li><b>PWA won’t install?</b> Use HTTPS, make sure <span class="pill">/manifest.webmanifest</span> and <span class="pill">/sw.js</span> are reachable, and icons exist (the site ships with valid icons already).</li>
      <li><b>Diagnostics page shows a problem?</b> Open <span class="pill">/admin/push_diag.php</span> and follow the hints shown on that page.</li>
      <li><b>Times look wrong on a printed report?</b> The system uses UK time. Refresh the page and confirm the server’s timezone is Europe/London.</li>
      <li><b>Unloading method missing on the form?</b> Make sure it’s <i>Enabled</i> in Unloading Methods.</li>
      <li><b>Users page is blocked?</b> Only Owners can change other admins.</li>
    </ul>
    <p class="muted">Need something else added to the admin? Speak to Chris Irlam (site developer/Owner) or your web admin.</p>
  </div>
</div>
</body>
</html>
