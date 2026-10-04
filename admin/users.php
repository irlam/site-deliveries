<?php
// /admin/users.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// admin_force_https();
admin_require($pdo);
$me = admin_current($pdo);
$csrf = admin_csrf_token();

$notice = '';
$error  = '';

/** guard: only OWNER can manage other admins (change if you want) */
if (!admin_is_admin($pdo)) {
    http_response_code(403);
    echo "<p style='font:14px/1.5 system-ui; padding:20px;'>Only an <b>owner</b> can manage admin accounts.</p>";
    exit;
}

// Handle create/update/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Security check failed.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'create') {
                $email = trim((string)($_POST['email'] ?? ''));
                $role  = strtolower(trim((string)($_POST['role'] ?? 'admin')));
                $pass  = (string)($_POST['password'] ?? '');
                $active= isset($_POST['is_active']) ? 1 : 0;

                if ($email === '' || $pass === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Valid email and password required.');
                }
                if (!in_array($role, ['owner','admin'], true)) $role = 'admin';

                $st = $pdo->prepare("INSERT INTO ".ADMIN_TABLE."
                  (email, password_hash, role, is_active, created_at)
                  VALUES (?, ?, ?, ?, NOW())");
                $st->execute([$email, password_hash($pass, PASSWORD_BCRYPT), $role, $active]);
                $notice = 'Admin created.';
            }
            elseif ($action === 'update') {
                $id    = (int)($_POST['id'] ?? 0);
                $email = trim((string)($_POST['email'] ?? ''));
                $role  = strtolower(trim((string)($_POST['role'] ?? 'admin')));
                $pass  = (string)($_POST['password'] ?? '');
                $active= isset($_POST['is_active']) ? 1 : 0;

                if ($id <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Valid id and email required.');
                }
                if (!in_array($role, ['owner','admin'], true)) $role = 'admin';

                $sql = "UPDATE ".ADMIN_TABLE." SET email=?, role=?, is_active=?";
                $params = [$email, $role, $active];
                if ($pass !== '') {
                    $sql .= ", password_hash=?";
                    $params[] = password_hash($pass, PASSWORD_BCRYPT);
                }
                $sql .= " WHERE id=?";
                $params[] = $id;

                $st = $pdo->prepare($sql);
                $st->execute($params);
                $notice = 'Admin updated.';
            }
            elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new RuntimeException('Invalid id.');
                if ($id === (int)$me['id']) throw new RuntimeException('You cannot delete your own account.');
                $pdo->prepare("DELETE FROM ".ADMIN_TABLE." WHERE id=?")->execute([$id]);
                $notice = 'Admin deleted.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Fetch rows
$rows = $pdo->query("
  SELECT id, email, role, is_active, created_at, last_login
  FROM ".ADMIN_TABLE."
  ORDER BY email ASC
")->fetchAll(PDO::FETCH_ASSOC);

?><!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Admin Users · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#0b1220;--card:#111827;--muted:#94a3b8;--text:#e5e7eb;--accent:#0ea5e9;--ok:#22c55e;--danger:#ef4444;--border:#1f2937}
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:1100px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .wrap{max-width:1100px;margin:24px auto;padding:0 16px}
  h1{margin:0 0 6px;font-size:22px}
  p.muted{margin:0 0 16px;color:var(--muted)}
  table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--border);border-radius:14px;overflow:hidden}
  thead th, tbody td{padding:10px;border-bottom:1px solid var(--border)}
  thead th{background:#0f172a;color:#cbd5e1;text-align:left}
  tbody tr:nth-child(even) td{background:#0c1424}
  .card{background:#0f172a;border:1px solid var(--border);border-radius:14px;padding:14px;margin-bottom:16px}
  .row{display:flex;gap:8px;flex-wrap:wrap}
  input,select{padding:8px;border-radius:10px;border:1px solid var(--border);background:#0b1528;color:var(--text)}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);padding:8px 12px;border-radius:12px;font-weight:600}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .btn.danger{background:#7f1d1d;border-color:#7f1d1d}
  .msg{margin:10px 0 0;padding:10px 12px;border-radius:12px}
  .msg.ok{background:rgba(34,197,94,.12);border:1px solid #14532d;color:#bbf7d0}
  .msg.err{background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#fecaca}
</style>
</head>
<body>
<header>
  <div class="top">
    <div class="badge">Deliveries · Admin</div>
    <div>
      <a class="btn" href="/admin/">Dashboard</a>
      <a class="btn" href="/admin/logout.php">Logout</a>
    </div>
  </div>
</header>

<div class="wrap">
  <h1>Admin Users</h1>
  <p class="muted">Create and manage admin logins. Only an <b>owner</b> can access this page.</p>

  <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error):  ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

  <!-- Create -->
  <div class="card">
    <form method="post" class="row" action="/admin/users.php">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="create">
      <input type="email" name="email" placeholder="Email" required>
      <input type="password" name="password" placeholder="Password" required>
      <select name="role">
        <option value="admin">admin</option>
        <option value="owner">owner</option>
      </select>
      <label><input type="checkbox" name="is_active" checked> Active</label>
      <button class="btn primary" type="submit">Add Admin</button>
    </form>
  </div>

  <!-- List -->
  <table>
    <thead>
      <tr>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
        <th>Created</th>
        <th>Last login</th>
        <th style="width:320px">Edit</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= h($r['email']) ?></td>
        <td><?= h($r['role']) ?></td>
        <td><?= ((int)$r['is_active']) ? 'Active' : 'Disabled' ?></td>
        <td><?= h($r['created_at']) ?></td>
        <td><?= h($r['last_login'] ?? '') ?></td>
        <td>
          <form method="post" action="/admin/users.php" class="row" style="align-items:center">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="email" name="email" value="<?= h($r['email']) ?>" required>
            <input type="password" name="password" placeholder="New password (optional)">
            <select name="role">
              <option value="admin" <?= strtolower($r['role'])==='admin'?'selected':'' ?>>admin</option>
              <option value="owner" <?= strtolower($r['role'])==='owner'?'selected':'' ?>>owner</option>
            </select>
            <label><input type="checkbox" name="is_active" <?= (int)$r['is_active'] ? 'checked' : '' ?>> Active</label>
            <button class="btn primary" type="submit">Save</button>
          </form>
          <?php if ((int)$r['id'] !== (int)$me['id']): ?>
          <form method="post" action="/admin/users.php" class="row" onsubmit="return confirm('Delete this admin? This cannot be undone.');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="btn danger" type="submit">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
      <tr><td colspan="6" style="padding:12px;color:#94a3b8">No admins found.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
