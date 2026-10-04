<?php
// /admin/account.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// admin_force_https();
admin_require($pdo);
$me = admin_current($pdo);

$csrf   = admin_csrf_token();
$notice = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Security check failed.';
    } else {
        try {
            $email = trim((string)($_POST['email'] ?? ''));
            $pass  = (string)($_POST['password'] ?? '');
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email.');
            }

            $sql = "UPDATE ".ADMIN_TABLE." SET email = ?";
            $params = [$email];

            if ($pass !== '') {
                $sql .= ", password_hash = ?";
                $params[] = password_hash($pass, PASSWORD_BCRYPT);
            }
            $sql .= ", updated_at = NOW() WHERE id = ?";
            $params[] = (int)$me['id'];

            $st = $pdo->prepare($sql);
            $st->execute($params);
            $notice = 'Account updated.';
            // refresh local copy
            $me = admin_current($pdo);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>My Account · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#0b1220;--card:#111827;--muted:#94a3b8;--text:#e5e7eb;--accent:#0ea5e9;--border:#1f2937}
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  header{position:sticky;top:0;background:rgba(17,24,39,.8);backdrop-filter:blur(6px);border-bottom:1px solid var(--border)}
  .top{max-width:760px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;justify-content:space-between}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);color:#bae6fd;font-size:12px}
  .wrap{max-width:760px;margin:24px auto;padding:0 16px}
  .card{background:#0f172a;border:1px solid var(--border);border-radius:16px;padding:16px}
  label{display:block;margin:10px 0 6px;color:#cbd5e1}
  input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#0b1528;color:var(--text)}
  .row{display:flex;gap:10px;justify-content:flex-end;margin-top:12px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);padding:8px 12px;border-radius:12px;font-weight:600}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
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
  <h1>My Account</h1>
  <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif; ?>
  <?php if ($error):  ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

  <div class="card">
    <form method="post" action="/admin/account.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <label>Email</label>
      <input type="email" name="email" required value="<?= h($me['email'] ?? '') ?>">
      <label>New Password <small style="color:#94a3b8">(leave blank to keep current)</small></label>
      <input type="password" name="password" autocomplete="new-password">
      <div class="row">
        <button class="btn primary" type="submit">Save Changes</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
