<?php
// /push/subscribe.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

// Only POST with JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']);
  exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Empty body']);
  exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);
  exit;
}

$endpoint = trim((string)($data['endpoint'] ?? ''));
$p256dh   = trim((string)($data['keys']['p256dh'] ?? ''));
$auth     = trim((string)($data['keys']['auth'] ?? ''));
if ($endpoint === '' || $p256dh === '' || $auth === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Missing endpoint/keys']);
  exit;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

// --- Ensure schema (table + columns + index) --------------------------------
try {
  // Create table if it doesn't exist (includes columns used below)
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS push_subscriptions (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      endpoint TEXT NOT NULL,
      p256dh VARCHAR(255) NOT NULL,
      auth    VARCHAR(255) NOT NULL,
      ua      TEXT NULL,
      ip      VARCHAR(45) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_endpoint (endpoint(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  // Add any missing columns, safely
  $cols = [];
  foreach ($pdo->query("SHOW COLUMNS FROM push_subscriptions") as $row) {
    $cols[strtolower($row['Field'])] = true;
  }
  $alters = [];
  if (!isset($cols['ua']))         $alters[] = "ADD COLUMN ua TEXT NULL AFTER auth";
  if (!isset($cols['ip']))         $alters[] = "ADD COLUMN ip VARCHAR(45) NULL AFTER ua";
  if (!isset($cols['created_at'])) $alters[] = "ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
  if (!isset($cols['updated_at'])) $alters[] = "ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
  if ($alters) {
    $pdo->exec("ALTER TABLE push_subscriptions " . implode(", ", $alters));
  }

  // Ensure unique index on endpoint (prefix for TEXT)
  $hasIdx = false;
  foreach ($pdo->query("SHOW INDEX FROM push_subscriptions") as $idx) {
    if (isset($idx['Key_name']) && $idx['Key_name'] === 'uniq_endpoint') { $hasIdx = true; break; }
  }
  if (!$hasIdx) {
    // Try to create; ignore if it already exists
    try { $pdo->exec("CREATE UNIQUE INDEX uniq_endpoint ON push_subscriptions (endpoint(255))"); } catch (\Throwable $e) {}
  }

} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Schema error: '.$e->getMessage()]);
  exit;
}

// --- Upsert subscription -----------------------------------------------------
try {
  // Insert or update by unique endpoint
  $sql = "
    INSERT INTO push_subscriptions (endpoint, p256dh, auth, ua, ip, created_at, updated_at)
    VALUES (:endpoint, :p256dh, :auth, :ua, :ip, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      p256dh = VALUES(p256dh),
      auth   = VALUES(auth),
      ua     = VALUES(ua),
      ip     = VALUES(ip),
      updated_at = NOW()
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':endpoint' => $endpoint,
    ':p256dh'   => $p256dh,
    ':auth'     => $auth,
    ':ua'       => $ua,
    ':ip'       => $ip,
  ]);

  echo json_encode(['ok'=>true]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Save error: '.$e->getMessage()]);
}
