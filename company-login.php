<?php
declare(strict_types=1);
require __DIR__.'/db.php';require __DIR__.'/includes/logistics-auth.php';
logistics_start_session();$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{logistics_write_check();if(($_SESSION['login_wait_until']??0)>time())Logistics::error('Please wait a minute before trying again.',403);
        $s=$pdo->prepare('SELECT u.*,c.active AS company_active FROM logistics_users u JOIN logistics_companies c ON c.id=u.company_id WHERE u.email=?');$s->execute([strtolower(trim((string)($_POST['email']??'')))]);$u=$s->fetch(PDO::FETCH_ASSOC);
        if(!$u||!(int)$u['active']||!(int)$u['company_active']||!password_verify((string)($_POST['password']??''),$u['password_hash'])){$_SESSION['login_attempts']=($_SESSION['login_attempts']??0)+1;if($_SESSION['login_attempts']>=5){$_SESSION['login_wait_until']=time()+60;$_SESSION['login_attempts']=0;}Logistics::error('Email or password not recognised.',401);}
        session_regenerate_id(true);unset($_SESSION['admin_id'],$_SESSION['login_attempts'],$_SESSION['login_wait_until']);$_SESSION['logistics_user_id']=(int)$u['id'];header('Location: /schedule.php');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}
function le(string $s): string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Company sign in · Site Deliveries</title><link rel="stylesheet" href="/assets/logistics.css"></head><body><main class="login"><img src="/assets/brand/logo.svg" alt="Site Deliveries" width="50"><h1>Company sign in</h1><p>View and book your company deliveries at your assigned sites.</p><?php if($error):?><p role="alert"><?=le($error)?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=le(logistics_csrf())?>"><label>Email<input name="email" type="email" autocomplete="username" required></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><button class="primary">Sign in</button></form><p><a href="/admin/login.php">Site administrator sign in</a></p><a href="https://suite.defecttracker.uk/">Back to Construction Suite</a></main></body></html>
