<?php
// live_gateboard.php — Live Gateboard with news-style route ticker
// ------------------------------------------------------------------
declare(strict_types=1);
require_once __DIR__ . '/db.php';

date_default_timezone_set('Europe/London');
header('Cache-Control: no-store, max-age=0');
$today = (new DateTime('today'))->format('Y-m-d');

$sql = "SELECT id, supplier, user_name, material, quantity, unloading_method, status, due_datetime
        FROM deliveries
        WHERE DATE(due_datetime) = ?
        ORDER BY due_datetime ASC, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function s($v){ return htmlspecialchars((string)$v, ENT_QUOTES,'UTF-8'); }

/** URL helpers */
function site_origin(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme.'://'.$host;
}
function abs_url(string $u): string {
  return preg_match('~^https?://~i',$u) ? $u : site_origin().$u;
}
function gate_url(int $id): string {
  return abs_url('/gate.php?id='.$id.'&src=board');
}
function qr_src_for_id(int $id, int $size=360): string {
  return abs_url('/icon.php?qr='.rawurlencode(gate_url($id)).'&s='.$size.'&fmt=svg');
}

/** normalize rows for JS */
$items = [];
foreach ($rows as $r){
  $items[] = [
    'id'      => (int)$r['id'],
    'supplier'=> (string)($r['supplier'] ?? ''),
    'user'    => (string)($r['user_name'] ?? ''),
    'material'=> (string)($r['material'] ?? ''),
    'qty'     => (string)($r['quantity'] ?? ''),
    'method'  => (string)($r['unloading_method'] ?? ''),
    'status'  => (string)($r['status'] ?? ''),
    'due'     => (string)($r['due_datetime'] ?? ''),
    'due_epoch' => strtotime((string)$r['due_datetime']),
    'due_time' => date('H:i', strtotime((string)$r['due_datetime'])),
    'qr'      => qr_src_for_id((int)$r['id'], 360),
  ];
}
$todayPretty = (new DateTime($today))->format('d/m/Y');

/* AJAX feed for auto-refresh */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($items, JSON_UNESCAPED_SLASHES);
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Live Gateboard · Site Deliveries</title>
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="stylesheet" href="/assets/gateboard/board.css?v=1">
</head>
<body>
<header>
<img src="/assets/brand/logo.svg" alt="" width="48" height="48">
<div><h1>Live Gateboard</h1><div id="date" class="subtitle"><?= s($todayPretty) ?></div></div>
<div class="tools"><time id="clock"></time><a class="home" href="/">Bookings</a><button id="fsBtn" type="button">Fullscreen</button></div>
</header>
<main id="viewport" aria-label="Today's deliveries"></main>
<footer>
<div><div id="summary" class="summary"></div><div id="connection" role="status"></div></div>
<nav class="paging" aria-label="Delivery pages"><button id="previous" type="button" aria-label="Previous page">←</button><span id="pageInfo"></span><button id="next" type="button" aria-label="Next page">→</button><button id="pause" type="button" aria-pressed="false">Pause</button></nav>
</footer>
<script id="initialData" type="application/json"><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/assets/gateboard/board.js?v=1" defer></script>
</body>
</html>
