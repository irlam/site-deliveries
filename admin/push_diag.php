<?php
// /admin/push_diag.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

admin_require($pdo);

if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ====================== helpers ====================== */

function table_exists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) 
                                 FROM INFORMATION_SCHEMA.TABLES 
                                WHERE TABLE_SCHEMA = DATABASE() 
                                  AND TABLE_NAME = :t");
        $stmt->execute([':t'=>$table]);
        return ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        // Fallback: DESCRIBE (some hosts restrict INFORMATION_SCHEMA)
        try {
            $pdo->query("DESCRIBE `$table`");
            return true;
        } catch (Throwable $e2) {
            return false;
        }
    }
}

/** True if a column exists on a table. */
function table_has_col(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) 
                                 FROM INFORMATION_SCHEMA.COLUMNS 
                                WHERE TABLE_SCHEMA = DATABASE() 
                                  AND TABLE_NAME = :t 
                                  AND COLUMN_NAME = :c");
        $stmt->execute([':t'=>$table, ':c'=>$col]);
        if ((int)$stmt->fetchColumn() > 0) return true;
    } catch (Throwable $e) {
        // Fallback: DESCRIBE and scan
        try {
            $cols = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
            return in_array($col, $cols, true);
        } catch (Throwable $e2) {
            // ignore
        }
    }
    return false;
}

/**
 * Build a SELECT that projects to a unified shape:
 *   id, endpoint, p256dh, auth, ua, created_at, updated_at, touched, src
 * We map various possible column names per table.
 */
function subscription_select(PDO $pdo, string $table, string $srcLabel): ?string {
    if (!table_exists($pdo, $table)) return null;

    // id (optional)
    $col_id        = table_has_col($pdo, $table, 'id')          ? "`$table`.`id`"          : "NULL";

    // endpoint (required for usefulness, but don't crash if missing)
    $col_endpoint  = table_has_col($pdo, $table, 'endpoint')    ? "`$table`.`endpoint`"    : "NULL";

    // p256dh variations
    if (table_has_col($pdo, $table, 'p256dh')) {
        $col_p256dh = "`$table`.`p256dh`";
    } elseif (table_has_col($pdo, $table, 'p256dh_key')) {
        $col_p256dh = "`$table`.`p256dh_key`";
    } else {
        $col_p256dh = "NULL";
    }

    // auth variations
    if (table_has_col($pdo, $table, 'auth')) {
        $col_auth = "`$table`.`auth`";
    } elseif (table_has_col($pdo, $table, 'auth_key')) {
        $col_auth = "`$table`.`auth_key`";
    } else {
        $col_auth = "NULL";
    }

    // user agent variations
    if (table_has_col($pdo, $table, 'user_agent')) {
        $col_ua = "`$table`.`user_agent`";
    } elseif (table_has_col($pdo, $table, 'ua')) {
        $col_ua = "`$table`.`ua`";
    } else {
        $col_ua = "NULL";
    }

    // created/updated variations
    if (table_has_col($pdo, $table, 'created_at')) {
        $col_created = "`$table`.`created_at`";
    } elseif (table_has_col($pdo, $table, 'created')) {
        $col_created = "`$table`.`created`";
    } else {
        $col_created = "NULL";
    }

    if (table_has_col($pdo, $table, 'updated_at')) {
        $col_updated = "`$table`.`updated_at`";
    } elseif (table_has_col($pdo, $table, 'updated')) {
        $col_updated = "`$table`.`updated`";
    } else {
        $col_updated = "NULL";
    }

    $src = $pdo->quote($srcLabel);

    $sql = "
        SELECT
            $col_id       AS id,
            $col_endpoint AS endpoint,
            $col_p256dh   AS p256dh,
            $col_auth     AS auth,
            $col_ua       AS ua,
            $col_created  AS created_at,
            $col_updated  AS updated_at,
            COALESCE($col_updated, $col_created) AS touched,
            $src AS src
        FROM `$table`
    ";
    return $sql;
}

/** Fetch unified subscription rows (both tables if present). */
function fetch_subscriptions(PDO $pdo): array {
    $parts = [];

    $s1 = subscription_select($pdo, 'push_subscriptions', 'push_subscriptions');
    if ($s1) $parts[] = $s1;

    $s2 = subscription_select($pdo, 'webpush_subscriptions', 'webpush_subscriptions');
    if ($s2) $parts[] = $s2;

    if (!$parts) return [];

    $union = implode("\nUNION ALL\n", $parts);

    // MySQL doesn’t support "NULLS LAST", so force non-NULLs first via boolean sort key.
    $sql = "SELECT * FROM ( $union ) AS u
            ORDER BY (u.touched IS NULL) ASC, u.touched DESC, u.id DESC
            LIMIT 200";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/* ====================== data ====================== */

$rows  = fetch_subscriptions($pdo);
$total = count($rows);

/* ====================== view ====================== */
?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Push Diagnostics · Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9; --border:#1f2937;
    --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:1100px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  .panel{background:#0f172a;border:1px solid var(--border);border-radius:16px;padding:16px;margin-bottom:16px}
  h1{margin:0 0 10px;font-size:20px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:top}
  th{color:#cbd5e1;text-align:left}
  code, .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
  .row-actions{display:flex;gap:8px;flex-wrap:wrap}
  .btn{appearance:none;border:1px solid var(--border);background:#0f172a;color:#e5e7eb;border-radius:10px;padding:6px 10px;cursor:pointer;text-decoration:none}
  .btn.small{font-size:12px;padding:4px 8px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);background:#0b1528;color:#cbd5e1;font-size:11px}
  .muted{color:#94a3b8}
  .copy-ok{color:var(--ok)}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="badge">Deliveries · Push Diagnostics</div>
    <div>
      <a class="btn" href="/admin/">Dashboard</a>
      <a class="btn" href="/">Back to Site</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="panel">
    <h1>Subscriptions</h1>
    <p class="muted">Unified list from <span class="mono">push_subscriptions</span> and <span class="mono">webpush_subscriptions</span>. Latest first. Showing up to 200.</p>
    <p><span class="pill"><?= (int)$total ?></span> devices subscribed.</p>

    <?php if (!$rows): ?>
      <p class="muted">No subscriptions found. Visit the public page and click “Enable Notifications”, then try again.</p>
    <?php else: ?>
      <table aria-label="Subscriptions">
        <thead>
          <tr>
            <th>#</th>
            <th>Endpoint</th>
            <th>P256DH</th>
            <th>Auth</th>
            <th>User Agent</th>
            <th>Created</th>
            <th>Updated</th>
            <th>Source</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
          <tr>
            <td class="mono"><?= (int)$i+1 ?></td>
            <td class="mono" style="max-width:280px;overflow-wrap:anywhere"><?= h($r['endpoint'] ?? '') ?></td>
            <td class="mono" style="max-width:220px;overflow-wrap:anywhere"><?= h($r['p256dh'] ?? '') ?></td>
            <td class="mono"><?= h($r['auth'] ?? '') ?></td>
            <td style="max-width:280px"><?= h($r['ua'] ?? '') ?></td>
            <td class="mono"><?= h($r['created_at'] ?? '') ?></td>
            <td class="mono"><?= h($r['updated_at'] ?? '') ?></td>
            <td class="mono"><span class="pill"><?= h($r['src'] ?? '') ?></span></td>
            <td class="row-actions">
              <button class="btn small" data-copy="<?= h($r['endpoint'] ?? '') ?>">Copy endpoint</button>
              <button class="btn small" data-prefill="<?= h($r['endpoint'] ?? '') ?>">Send via form…</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p class="muted" style="margin-top:8px">“Send via form…” opens the Broadcast panel with helpful defaults (manual target for now).</p>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  // Copy endpoint
  document.querySelectorAll('button[data-copy]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      try{
        await navigator.clipboard.writeText(btn.getAttribute('data-copy') || '');
        btn.textContent = 'Copied';
        btn.classList.add('copy-ok');
        setTimeout(()=>{ btn.textContent='Copy endpoint'; btn.classList.remove('copy-ok'); }, 1200);
      }catch(e){
        alert('Copy failed: ' + (e && e.message ? e.message : e));
      }
    });
  });

  // Prefill admin broadcast form with sensible defaults
  document.querySelectorAll('button[data-prefill]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const url = '/admin/index.php'
                + '?prefill_title=' + encodeURIComponent('Test push')
                + '&prefill_body='  + encodeURIComponent('Hello from diagnostics')
                + '&prefill_tag='   + encodeURIComponent('single-endpoint')
                + '#send';
      window.location.href = url;
    });
  });
})();
</script>
</body>
</html>
