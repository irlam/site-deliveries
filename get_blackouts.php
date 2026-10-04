<?php
// /get_blackouts.php
declare(strict_types=1);
require_once __DIR__.'/db.php';

// Self-heal table (matches what we used in Admin)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS blackouts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    date DATE NOT NULL,
    start TIME NULL,
    end TIME NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX(date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Range (inclusive) — expects YYYY-MM-DD
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Bad date range']);
  exit;
}

$st = $pdo->prepare("SELECT id, DATE_FORMAT(date,'%Y-%m-%d') AS date,
                            TIME_FORMAT(start, '%H:%i') AS start,
                            TIME_FORMAT(end,   '%H:%i') AS end,
                            COALESCE(reason,'') AS reason
                     FROM blackouts
                     WHERE date BETWEEN ? AND ?
                     ORDER BY date, start IS NULL DESC, start");
$st->execute([$from, $to]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['ok'=>true, 'items'=>$rows]);
