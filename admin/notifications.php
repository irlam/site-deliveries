<?php
// /admin/notifications.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

admin_require($pdo);
$me = admin_current($pdo);

// ---- SAFE helper (avoid redeclare) ----
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

// Union subscriber count for header badge
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
$subCount = count_push_subscribers($pdo);

// Ensure table exists and add `notes` column if missing
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
    // Add notes column if it doesn't exist
    try { $pdo->query("SELECT notes FROM push_broadcasts LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE push_broadcasts ADD COLUMN notes TEXT NULL AFTER body"); }
} catch (Throwable $e) { /* ignore */ }

// Fetch rows (newest first)
$q = $pdo->query("SELECT * FROM push_broadcasts ORDER BY created_at DESC, id DESC LIMIT 200");
$rows = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];

?><!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Push History · Deliveries Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#0b1220;--card:#111827;--muted:#94a3b8;--text:#e5e7eb;--accent:#0ea5e9;--border:#1f2937}
  *{box-sizing:border-box}html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:1100px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .btn{appearance:none;border:1px solid var(--border);background:#0f172a;color:var(--text);padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  .panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px;margin-top:16px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid #1f2937;vertical-align:top}
  th{color:#cbd5e1;text-align:left;font-weight:700}
  .muted{color:var(--muted)}
  .chip{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0b1528;color:#cbd5e1;font-size:12px}
  .toolbar{display:flex;gap:10px;flex-wrap:wrap}
</style>
</head>
<body>
<header>
  <div class="top">
    <div>
      <span class="badge">Deliveries · Admin</span>
      <span class="badge" title="Union of push_subscriptions & webpush_subscriptions"><?= (int)$subCount ?> subscribed</span>
    </div>
    <div class="toolbar">
      <a class="btn" href="/admin/">Dashboard</a>
      <a class="btn" href="/admin/push_export.php?format=csv">Export CSV</a>
      <a class="btn" href="/admin/push_export.php?format=pdf">Export PDF</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <h1 style="margin:0 0 8px">Broadcast History</h1>
  <p class="muted" style="margin:0 0 12px">Newest first (last 200). Rows come from <code>push_broadcasts</code>.</p>

  <div class="panel">
    <?php if (!$rows): ?>
      <p class="muted">No broadcasts yet.</p>
    <?php else: ?>
      <div style="overflow:auto">
      <table>
        <thead>
          <tr>
            <th style="width:90px">When</th>
            <th>Title / Body</th>
            <th style="width:180px">Meta</th>
            <th style="width:120px">Result</th>
            <th style="width:200px">Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td class="muted"><?= h($r['created_at']) ?></td>
            <td>
              <div><b><?= h($r['title']) ?></b></div>
              <div class="muted"><?= nl2br(h($r['body'])) ?></div>
              <?php if (!empty($r['url'])): ?>
                <div class="muted">URL: <code><?= h($r['url']) ?></code></div>
              <?php endif; ?>
            </td>
            <td class="muted">
              <div>Urgency: <span class="chip"><?= h($r['urgency']) ?></span></div>
              <div>Require interaction: <?= (int)$r['require_interaction'] ? 'Yes' : 'No' ?></div>
              <?php if (!empty($r['tag'])): ?><div>Tag: <code><?= h($r['tag']) ?></code></div><?php endif; ?>
              <?php if (!empty($r['icon'])): ?><div>Icon: <code><?= h($r['icon']) ?></code></div><?php endif; ?>
              <?php if (!empty($r['badge'])): ?><div>Badge: <code><?= h($r['badge']) ?></code></div><?php endif; ?>
            </td>
            <td>
              <div>Sent: <b><?= (int)$r['sent'] ?></b></div>
              <div>Failed: <b><?= (int)$r['failed'] ?></b></div>
            </td>
            <td><?= nl2br(h((string)($r['notes'] ?? ''))) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
