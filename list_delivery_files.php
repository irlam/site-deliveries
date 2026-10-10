<?php
declare(strict_types=1);
require __DIR__.'/db.php';require __DIR__.'/includes/logistics-auth.php';header('Content-Type: application/json');header('Cache-Control: no-store');
try{$l=new Logistics($pdo);$u=logistics_user($pdo);$id=(int)($_GET['id']??0);$l->delivery($u,$id);$files=$l->rows('SELECT path,label,created_at FROM delivery_files WHERE delivery_id=? ORDER BY id',[$id]);foreach($files as &$file)$file['url']='/private-file.php?path='.rawurlencode($file['path']);unset($file);echo json_encode($files);}catch(Throwable $e){http_response_code(in_array($e->getCode(),[401,403,404],true)?$e->getCode():500);echo json_encode(['ok'=>false,'error'=>'Files unavailable.']);}
