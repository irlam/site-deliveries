<?php
// upload_delivery_sheet.php — camera image upload + record
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$deliveryId = isset($_POST['delivery_id']) ? (int)$_POST['delivery_id'] : 0;
$label      = trim((string)($_POST['label'] ?? 'Delivery sheet'));

if ($deliveryId <= 0 || empty($_FILES['sheet']['tmp_name'])) {
  echo json_encode(['ok'=>false,'error'=>'Missing file or delivery_id']); exit;
}

// ensure delivery exists
$chk = $pdo->prepare("SELECT id FROM deliveries WHERE id=?");
$chk->execute([$deliveryId]);
if (!$chk->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'Delivery not found']); exit; }

// ensure table
$pdo->query("CREATE TABLE IF NOT EXISTS delivery_files (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id INT NOT NULL,
  path VARCHAR(255) NOT NULL,
  label VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(delivery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// validate file
$f  = $_FILES['sheet'];
$okTypes = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
$mime = @mime_content_type($f['tmp_name']) ?: $f['type'];
$ext  = $okTypes[$mime] ?? null;
if (!$ext) { echo json_encode(['ok'=>false,'error'=>'Only JPG/PNG/WEBP allowed']); exit; }
if ($f['size'] > 10*1024*1024) { echo json_encode(['ok'=>false,'error'=>'Max 10MB']); exit; }

// target path
$baseDir = __DIR__ . '/uploads/delivery_sheets/' . $deliveryId;
if (!is_dir($baseDir)) { @mkdir($baseDir, 0775, true); }
$fname = 'sheet_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
$pathAbs = $baseDir . '/' . $fname;
if (!move_uploaded_file($f['tmp_name'], $pathAbs)) {
  echo json_encode(['ok'=>false,'error'=>'Could not save file']); exit;
}

// public URL
$pathRel = '/uploads/delivery_sheets/' . $deliveryId . '/' . $fname;

// save record
$ins = $pdo->prepare("INSERT INTO delivery_files (delivery_id, path, label) VALUES (?,?,?)");
$ins->execute([$deliveryId, $pathRel, $label ?: 'Delivery sheet']);

echo json_encode([
  'ok'=>true,
  'url'=>$pathRel,
  'label'=>$label ?: 'Delivery sheet',
  'created_at'=>date('d/m/Y H:i')
]);
