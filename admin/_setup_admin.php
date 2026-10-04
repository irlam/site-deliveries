<?php
// /admin/_setup_admin.php
declare(strict_types=1);

/**
 * One-time bootstrap to create the `admins` table and your first admin account.
 * - Visit /admin/_setup_admin.php
 * - Fill in email + password
 * - Submit => table created (if missing) and user inserted
 * - You’ll then be redirected to /admin/login.php
 * IMPORTANT: Delete this file after successful setup.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// If an admin is already logged in, bounce to dashboard
if (admin_is_logged_in()) {
    header('Location: /admin/');
    exit;
}

$error = '';
$notice = '';

/* ---------- Ensure table exists ---------- */
function ensure_admins_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          email VARCHAR(190) NOT NULL UNIQUE,
          pass_hash VARCHAR(255) NOT NULL DEFAULT '',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
ensure_admins_table($pdo);

/* ---------- Short-circuit if at least one admin exists ---------- */
$hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf'] ?? '';
    if (!admin_csrf_check($csrf)) {
        $error = 'Security check failed. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass1 = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['confirm'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email.';
        } elseif ($pass1 === '' || strlen($pass1) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($pass1 !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            try {
                ensure_admins_table($pdo);
                // If table already has a user, don’t allow creating another via setup
                $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn() > 0;
                if ($hasAdmin) {
                    $error = 'An admin already exists. Please use /admin/login.php.';
                } else {
                    $hash = password_hash($pass1, PASSWORD_DEFAULT);
                    $ins  = $pdo->prepare("INSERT INTO admins (email, pass_hash, is_active) VALUES (?, ?, 1)");
                    $ins->execute([$email, $hash]);
                    // Success — redirect to login
                    header('Location: /admin/login.php');
                    exit;
                }
            } catch (PDOException $e) {
                if ($e->errorInfo[1] == 1062) {
                    $error = 'That email is already in use.';
                } else {
                    throw $e;
                }
            }
        }
    }
}

$csrf = admin_csrf_token();

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Setup · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#0b1220; --card:#111827; --muted:#94a3b8; --text:#e5e7eb; --accent:#0ea5e9;
    --ok:#22c55e; --warn:#f59e0b; --danger:#ef4444; --border:#1f2937;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
  .wrap{min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{width:100%;max-width:520px;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:20px 18px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  h1{margin:0 0 6px;font-size:20px}
  p.muted{margin:0 0 16px;color:var(--muted)}
  label{display:block;margin:10px 0 6px;color:#cbd5e1}
  input{
    width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--border);
    background:#0f172a;color:var(--text);outline:none
  }
  .row{display:flex;gap:10px;justify-content:flex-end;margin-top:14px}
  .btn{appearance:none;cursor:pointer;border:1px solid var(--border);background:#0f172a;color:var(--text);
       padding:10px 14px;border-radius:12px;font-weight:600;text-decoration:none}
  .btn.primary{background:var(--accent);border-color:transparent;color:white}
  .msg{margin:8px 0 0;padding:10px 12px;border-radius:12px}
  .msg.ok{background:rgba(34,197,94,.12);border:1px solid #14532d;color:#bbf7d0}
  .msg.err{background:rgba(239,68,68,.12);border:1px solid #7f1d1d;color:#fecaca}
  .note{color:#cbd5e1;font-size:13px;margin-top:10px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Initial Admin Setup</h1>
    <?php if ($hasAdmin && !$error): ?>
      <p class="muted">An admin already exists. You can <a class="btn" href="/admin/login.php">go to login</a>.</p>
    <?php else: ?>
      <p class="muted">Create your first admin account. After successful setup, you’ll be redirected to the login page.</p>
      <?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>
      <form method="post" action="/admin/_setup_admin.php" autocomplete="off" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label for="email">Admin email</label>
        <input id="email" name="email" type="email" required placeholder="admin@yourdomain.tld" value="<?= h($_POST['email'] ?? '') ?>">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        <label for="confirm">Confirm password</label>
        <input id="confirm" name="confirm" type="password" required minlength="8" autocomplete="new-password">
        <div class="row"><button class="btn primary" type="submit">Create admin</button></div>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
