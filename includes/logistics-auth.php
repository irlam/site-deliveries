<?php
declare(strict_types=1);
require_once __DIR__.'/logistics.php';
function logistics_enabled(PDO $pdo): bool {
    try{$s=$pdo->query("SELECT value FROM app_settings WHERE `key`='logistics_enabled'");return $s->fetchColumn()==='1';}catch(PDOException){return false;}
}
function logistics_user(PDO $pdo): array {
    if(session_status()!==PHP_SESSION_ACTIVE)session_start();
    if(isset($_SESSION['admin_id'])){
        $s=$pdo->prepare('SELECT id,email,is_active FROM admins WHERE id=?');$s->execute([(int)$_SESSION['admin_id']]);$a=$s->fetch(PDO::FETCH_ASSOC);
        if($a&&(int)$a['is_active'])return ['id'=>(int)$a['id'],'name'=>$a['email'],'admin'=>true,'company_id'=>null];
    }
    if(isset($_SESSION['logistics_user_id'])){
        $s=$pdo->prepare('SELECT u.*,c.active AS company_active FROM logistics_users u JOIN logistics_companies c ON c.id=u.company_id WHERE u.id=?');$s->execute([(int)$_SESSION['logistics_user_id']]);$u=$s->fetch(PDO::FETCH_ASSOC);
        if($u&&(int)$u['active']&&(int)$u['company_active'])return ['id'=>(int)$u['id'],'name'=>$u['name'],'admin'=>false,'company_id'=>(int)$u['company_id']];
    }
    Logistics::error('Sign in to view your company deliveries.',401);
}
function logistics_csrf(): string {if(session_status()!==PHP_SESSION_ACTIVE)session_start();return $_SESSION['logistics_csrf']??=$_SESSION['admin_csrf']??bin2hex(random_bytes(32));}
function logistics_write_check(): void {
    if($_SERVER['REQUEST_METHOD']!=='POST')Logistics::error('Use POST for changes.',405);
    if(!hash_equals(logistics_csrf(),(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??'')))Logistics::error('Security check failed. Refresh and retry.',403);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    if($origin!==''&&$origin!==((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'')))Logistics::error('Request origin denied.',403);
}
