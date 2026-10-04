<?php
// /get_day_notes.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
// avoid stale caches (service workers/CDN)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';

// --- tiny helpers ---
function bad(int $code, string $msg){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}
function is_date_ymd(string $s): bool {
  return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
  } catch (Throwable $e) { return false; }
}

// --- ensure table exists (with `day` PK) ---
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS day_notes (
      day DATE NOT NULL PRIMARY KEY,
      note TEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  // non-fatal: continue (older schema may already exist)
}

// pick the column name (`day` preferred, fallback to `date`)
$COL = column_exists($pdo, 'day_notes', 'day') ? 'day'
     : (column_exists($pdo, 'day_notes', 'date') ? 'date' : 'day');

// --- params ---
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

if (!$from || !$to || !is_date_ymd($from) || !is_date_ymd($to)) {
  bad(400, 'Provide from and to as YYYY-MM-DD');
}

// --- query ---
try {
  $st = $pdo->prepare("SELECT `$COL` AS day, note FROM day_notes WHERE `$COL` BETWEEN ? AND ? ORDER BY `$COL` ASC");
  $st->execute([$from, $to]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $byDay = [];
  foreach ($rows as $r) {
    $d = (string)$r['day'];
    $byDay[$d] = (string)($r['note'] ?? '');
  }

  // Return BOTH keys for compatibility:
  //  - items: used by the calendar JS I sent
  //  - notes: used by your admin panel listing
  echo json_encode([
    'ok'    => true,
    'items' => $byDay,  // <-- what the calendar expects
    'notes' => $byDay   // <-- keep this for your admin panel
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  bad(500, 'DB error: '.$e->getMessage());
}
