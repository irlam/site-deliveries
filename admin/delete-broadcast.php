<?php
declare(strict_types=1);
require __DIR__.'/../db.php';
require __DIR__.'/../includes/admin_auth.php';
admin_require($pdo);
header('Cache-Control: no-store');
if(!admin_is_admin($pdo)){http_response_code(403);exit('Administrator access required.');}
$id=filter_var($_POST['id']??$_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(400);exit('Choose a broadcast.');}
$st=$pdo->prepare('SELECT id,title,body,created_at FROM push_broadcasts WHERE id=?');$st->execute([$id]);$broadcast=$st->fetch(PDO::FETCH_ASSOC);
if(!$broadcast){http_response_code(404);exit('Broadcast not found.');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!admin_csrf_check($_POST['csrf']??null)){http_response_code(403);$error='The confirmation expired. Please try again.';}
    elseif(($_POST['confirm']??'')!=='delete'){http_response_code(422);$error='Confirm deletion before continuing.';}
    else{$st=$pdo->prepare('DELETE FROM push_broadcasts WHERE id=?');$st->execute([$id]);header('Location: /admin/notifications.php?deleted=1',true,303);exit;}
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delete broadcast · Site Deliveries</title><link rel="icon" href="/favicon.ico"><link rel="stylesheet" href="/assets/logistics.css?v=4"></head><body><main class="login"><h1>Delete broadcast?</h1><p>This permanently removes this entry from Recent Broadcasts, broadcast history and future exports. Notifications already delivered to phones cannot be recalled.</p><section class="panel"><strong><?=h($broadcast['title'])?></strong><p><?=nl2br(h($broadcast['body']))?></p><p class="help"><?=h((new DateTimeImmutable($broadcast['created_at']))->format('d/m/Y H:i'))?></p></section><?php if($error):?><p role="alert"><?=h($error)?></p><?php endif?><form method="post"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="csrf" value="<?=h(admin_csrf_token())?>"><label><input type="checkbox" name="confirm" value="delete" required> I confirm I want to delete this history entry.</label><button class="primary">Delete broadcast</button></form><p><a href="/admin/notifications.php">Cancel and return to history</a></p></main></body></html>
