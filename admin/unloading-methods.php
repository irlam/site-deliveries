<?php
// /admin/unloading-methods.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

// Optional: enforce HTTPS
// admin_force_https();

admin_require($pdo);

/* -------------------- Bootstrap table (idempotent) -------------------- */
function ensure_unloading_table(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unloading_methods (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          label VARCHAR(100) NOT NULL UNIQUE,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $done = true;
}
ensure_unloading_table($pdo);

/* -------------------- Handle POST actions -------------------- */
$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!admin_csrf_check($csrf)) {
        $error = 'Security check failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create') {
                $label = trim($_POST['label'] ?? '');
                $order = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 1; // default active

                if ($label === '') {
                    $error = 'Please enter a label.';
                } else {
                    $stmt = $pdo->prepare("INSERT INTO unloading_methods (label, sort_order, is_active) VALUES (?, ?, ?)");
                    $stmt->execute([$label, $order, $active]);
                    $notice = 'Unloading method added.';
                }
            } elseif ($action === 'update') {
                $id    = (int)($_POST['id'] ?? 0);
                $label = trim($_POST['label'] ?? '');
                $order = (int)($_POST['sort_order'] ?? 0);
                $active = isset($_POST['is_active']) ? 1 : 0;

                if ($id <= 0) {
                    $error = 'Invalid record.';
                } elseif ($label === '') {
                    $error = 'Please enter a label.';
                } else {
                    $stmt = $pdo->prepare("UPDATE unloading_methods SET label = ?, sort_order = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$label, $order, $active, $id]);
                    $notice = 'Unloading method updated.';
                }
            } elseif ($action === 'toggle') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $error = 'Invalid record.';
                } else {
                    $pdo->beginTransaction();
                    $cur = $pdo->prepare("SELECT is_active FROM unloading_methods WHERE id = ? FOR UPDATE");
                    $cur->execute([$id]);
                    $state = $cur->fetchColumn();
                    if ($state === false) {
                        $pdo->rollBack();
                        $error = 'Record not found.';
                    } else {
                        $new = ((int)$state === 1) ? 0 : 1;
                        $upd = $pdo->prepare("UPDATE unloading_methods SET is_active = ? WHERE id = ?");
                        $upd->execute([$new, $id]);
                        $pdo->commit();
                        $notice = $new ? 'Activated.' : 'Hidden.';
                    }
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $error = 'Invalid record.';
                } else {
                    $stmt = $pdo->prepare("DELETE FROM unloading_methods WHERE id = ?");
                    $stmt->execute([$id]);
                    $notice = 'Unloading method deleted.';
                }
            }
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $error = 'That label already exists. Labels must be unique.';
            } else {
                throw $e;
            }
        }
    }
}

/* -------------------- Fetch rows for display -------------------- */
$stmt = $pdo->query("SELECT id, label, sort_order, is_active, created_at, updated_at
                     FROM unloading_methods
                     ORDER BY is_active DESC, sort_order ASC, label ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrf = admin_csrf_token();

?><!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Unloading Methods · Deliveries Admin</title>
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
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);
       padding:8px 12px;border-radius:12px;font-weight:600;text-decoration:none}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  h1{margin:0 0 6px;font-size:22px}
  p.muted{margin:0 0 16px;color:var(--muted)}
  .panel{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px}
  table{width:100%;border-collapse:collapse;margin-top:12px}
  th, td{border-bottom:1px solid var(--border);padding:10px;vertical-align:middle}
  th{color:var(--muted);font-weight:600;text-align:left}
  input[type="text"], input[type="number"]{
    width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);
    background:#0f172a;color:var(--text);outline:none
  }
  .status-badge{display:inline-block;padding:4px 8px;border-radius:999px;border:1px solid var(--border);font-size:12px}
  .on{background:rgba(34,197,94,.12);color:#bbf7d0;border-color:#14532d}
  .off{background:rgba(239,68,68,.12);color:#fecaca;border-color:#7f1d1d}
  .actions{display:flex;gap:8px;flex-wrap:wrap}
  .msg{margin:10px 0 0;padding:10px 12px;border-radius:12px}
  .msg.ok{background:rgba(34,197,94,.12);border:1px solid #14532d;color:#bbf7d0}
  .msg.err{background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#fecaca}
  .grid{display:grid;grid-template-columns:1fr;gap:16px}
  .note{color:#cbd5e1;font-size:13px;margin-top:6px}
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
  <h1>Unloading Methods</h1>
  <p class="muted">Manage the selectable unloading options for the booking form. Hide items you don’t currently use; reorder with the “Order” field (lower numbers appear first).</p>

  <?php if ($notice): ?>
    <div class="msg ok"><?= h($notice) ?></div>
  <?php elseif ($error): ?>
    <div class="msg err"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="grid">
    <div class="panel">
      <h3 style="margin:0 0 8px;font-size:16px">Add New</h3>
      <form method="post" action="/admin/unloading-methods.php" autocomplete="off" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:10px;align-items:end">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="create">
        <div>
          <label style="display:block;margin:0 0 6px;color:#cbd5e1">Label</label>
          <input type="text" name="label" required placeholder="e.g., Forklift">
        </div>
        <div>
          <label style="display:block;margin:0 0 6px;color:#cbd5e1">Order</label>
          <input type="number" name="sort_order" value="50">
        </div>
        <div>
          <label style="display:block;margin:0 0 6px;color:#cbd5e1">Status</label>
          <div class="note">New methods start as Active</div>
        </div>
        <div>
          <button class="btn primary" type="submit">Add</button>
        </div>
      </form>
    </div>

    <div class="panel">
      <h3 style="margin:0 0 8px;font-size:16px">Existing Methods</h3>
      <table>
        <thead>
          <tr>
            <th style="width:40%">Label</th>
            <th style="width:12%">Order</th>
            <th style="width:14%">Status</th>
            <th style="width:34%">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" style="color:#cbd5e1">No unloading methods yet. Add one above.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <form method="post" action="/admin/unloading-methods.php" autocomplete="off" style="display:flex;gap:10px;align-items:center">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="label" value="<?= h($r['label']) ?>" required>
            </td>
            <td>
                <input type="number" name="sort_order" value="<?= (int)$r['sort_order'] ?>">
            </td>
            <td>
                <span class="status-badge <?= $r['is_active'] ? 'on':'off' ?>">
                  <?= $r['is_active'] ? 'Active' : 'Hidden' ?>
                </span>
            </td>
            <td class="actions">
                <label style="display:flex;align-items:center;gap:6px;color:#cbd5e1">
                  <input type="checkbox" name="is_active" <?= $r['is_active'] ? 'checked':'' ?> style="accent-color:#0ea5e9">
                  Active
                </label>
                <button class="btn primary" type="submit">Save</button>
              </form>

              <form method="post" action="/admin/unloading-methods.php" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn" type="submit"><?= $r['is_active'] ? 'Hide' : 'Activate' ?></button>
              </form>

              <form method="post" action="/admin/unloading-methods.php" style="display:inline" onsubmit="return confirm('Delete this method? This does NOT affect existing delivery rows (they store the label).');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn" type="submit" style="border-color:#ef4444;color:#ef4444">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>

      <div class="note">
        <strong>Note:</strong> The booking form will show only <em>Active</em> methods, ordered by the “Order” number (lowest first), then by label.
        Existing deliveries keep their stored label even if you rename or delete a method here.
      </div>
    </div>
  </div>
</div>
</body>
</html>
