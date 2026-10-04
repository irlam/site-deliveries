<?php
// /admin/login.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// admin_force_https(); // optional

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if ($email === '' || $pass === '') {
        $err = 'Please enter your email and password.';
    } else {
        $row = admin_login($pdo, $email, $pass);
        if ($row) {
            $next = isset($_GET['next']) ? (string)$_GET['next'] : '/admin/';
            header('Location: ' . $next);
            exit;
        }
        $err = 'Invalid credentials or inactive account.';
    }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Login · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{--bg:#0b1220;--card:#111827;--muted:#94a3b8;--text:#e5e7eb;--accent:#0ea5e9;--border:#1f2937}
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .wrap{max-width:360px;margin:8vh auto;padding:18px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px}
  h1{margin:0 0 12px;font-size:20px}
  label{display:block;margin:10px 0 6px;color:#cbd5e1}
  input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:#0f172a;color:var(--text)}
  .row{display:flex;justify-content:flex-end;margin-top:12px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);padding:8px 12px;border-radius:12px;font-weight:600}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .msg{margin:10px 0 0;padding:10px 12px;border-radius:12px;background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#fecaca}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Admin Login</h1>
    <?php if ($err): ?><div class="msg"><?= h($err) ?></div><?php endif; ?>
    <form method="post" action="/admin/login.php">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required autofocus>
      <label for="password">Password</label>
      <input id="password" name="password" type="password" required>
      <div class="row">
        <button class="btn primary" type="submit">Sign in</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
