<?php
declare(strict_types=1);
require __DIR__.'/db.php';require __DIR__.'/includes/logistics-auth.php';
try{
    $u=logistics_user($pdo);$l=new Logistics($pdo);$path='/'.ltrim((string)($_GET['path']??''),'/');
    if(str_contains($path,'..')||!preg_match('~^/(uploads|delivery_sheets|export)/~',$path))Logistics::error('File not found.',404);
    if(!$u['admin']){$row=$l->one('SELECT delivery_id FROM delivery_files WHERE path=?',[$path]);if(!$row)Logistics::error('File not found.',404);$l->delivery($u,(int)$row['delivery_id']);}
    $file=realpath(__DIR__.$path);$root=realpath(__DIR__);if(!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR)||!is_file($file))Logistics::error('File not found.',404);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: '.$mime);
    if(!in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true))header('Content-Disposition: attachment; filename="delivery-file"');readfile($file);
}catch(Throwable $e){http_response_code(in_array($e->getCode(),[401,403,404],true)?$e->getCode():500);echo 'File unavailable.';}
