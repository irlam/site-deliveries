<?php
// run_sheet.php — Daily Run Sheet using self-hosted QR (SVG via /icon.php)
// Params: ?date=YYYY-MM-DD (defaults to today, UK time)

declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Optional HMAC signing (if you set it globally, remove here).
// define('GATE_SECRET', 'change-this-to-a-long-random-secret');

date_default_timezone_set('Europe/London');

// Parse date (UK today default)
$today = (new DateTime('today'))->format('Y-m-d');
$inputDate = isset($_GET['date']) ? trim((string)$_GET['date']) : $today;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inputDate)) {
    $inputDate = $today;
}

// Fetch all deliveries on that date
$sql = "SELECT id, supplier, user_name, material, quantity, unloading_method, status, due_datetime
        FROM deliveries
        WHERE DATE(due_datetime) = ?
        ORDER BY due_datetime ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$inputDate]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build absolute URL helper (proxy/HTTPS aware)
function abs_url(string $path): string {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if ($path !== '' && $path[0] !== '/') $path = '/'.$path;
    return $scheme.'://'.$host.$path;
}

// Gate URL helper (absolute; optionally signed)
function gate_url_for(array $row): string {
    $id  = (int)($row['id'] ?? 0);
    $qs  = 'id='.$id;

    if (defined('GATE_SECRET')) {
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', (string)$id, GATE_SECRET, true)), '+/', '-_'), '=');
        $qs .= '&t='.rawurlencode($sig);
    }
    return abs_url('/gate.php?'.$qs);
}

// Pretty date for header
$prettyDate = DateTime::createFromFormat('Y-m-d', $inputDate);
$pretty = $prettyDate ? $prettyDate->format('d/m/Y') : $inputDate;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Delivires Run Sheet · <?= htmlspecialchars($pretty) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' fill='%23fff'/%3E%3Cpath d='M20 70 Q20 50 30 40 L70 40 Q80 50 80 70 L75 70 Q75 60 70 55 L30 55 Q25 60 25 70 Z' fill='%23FFD700'/%3E%3Crect x='20' y='68' width='60' height='7' rx='3' ry='3' fill='%23FFA500'/%3E%3C/svg%3E">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --ink:#0f172a; --muted:#64748b; --rule:#e2e8f0; --table:#ffffff; --chip:#eff6ff; --chip-border:#bfdbfe; }
  body{ background:#fafafa; color:var(--ink); }
  .page{ max-width:1150px; margin:20px auto; padding:0 14px; }
  .topbar{ display:flex; gap:12px; align-items:center; margin-bottom:16px; }
  .topbar .btn{ border-radius:10px; }
  h1{ font-size:2rem; font-weight:800; margin:0 0 8px; }
  .note{ color:var(--muted); margin-bottom:16px; }
  table.run{ width:100%; background:var(--table); border:1px solid var(--rule); border-radius:14px; overflow:hidden; }
  table.run th, table.run td{ padding:12px 14px; vertical-align:top; }
  table.run thead th{ background:#f8fafc; border-bottom:1px solid var(--rule); font-weight:700; }
  table.run td + td, table.run th + th{ border-left:1px solid var(--rule); }
  .time{ white-space:nowrap; font-weight:700; }
  .contractor small{ color:var(--muted); display:block; }
  .mat small{ color:var(--muted); display:block; }
  .status-chip{ display:inline-block; background:var(--chip); border:1px solid var(--chip-border); color:#1e40af; font-weight:600; padding:4px 10px; border-radius:999px; font-size:.9rem; }
  .qr-cell{ text-align:center; }
  .qr-img{ display:inline-block; width:140px; height:140px; border:1px solid rgba(0,0,0,.08); border-radius:10px; padding:6px; background:#fff; }

  /* ---- Print-only brand header with logo ---- */
  .print-only{ display:none; }
  .brandbar{
    display:flex; align-items:center; gap:12px; margin-bottom:12px;
    border-bottom:1px solid var(--rule); padding-bottom:8px;
  }
  .brandbar .brand-logo{ height:48px; width:auto; }
  .brandbar .brand-text{ line-height:1.2; }
  .brandbar .brand-text small{ color:var(--muted); }

  @media print{
    .noprint{ display:none !important; }
    .print-only{ display:block !important; }
    body{ background:#fff; }
    .page{ margin:0; padding:0; max-width:100%; }
    table.run{ border-color:#ddd; }
  }
</style>
</head>
<body>
  <div class="page">
    <div class="topbar noprint">
      <a href="index.php" class="btn btn-outline-secondary">&larr; Back</a>
      <button onclick="window.print()" class="btn btn-primary">Print</button>
      <div class="ms-auto"></div>
    </div>

    <!-- Print-only brand header (shows on paper/PDF, hidden on screen) -->
    <div class="print-only">
      <div class="brandbar">
        <img src="/icons/icon-192.png" alt="Site Deliveries Logo" class="brand-logo">
        <div class="brand-text">
          <strong>Delivirey Run Sheet · <?= htmlspecialchars($pretty) ?></strong><br>
          <small>sitedeliveries.site</small>
        </div>
      </div>
    </div>

    <!-- On-screen heading remains for users -->
    <h1 class="noprint">Delivrey Run Sheet · <?= htmlspecialchars($pretty) ?></h1>
    <p class="note noprint">Each QR opens the Gate page for one-tap <b>Arrived</b> / <b>Completed</b>. Anyone who enabled notifications on the website will receive a push notification.</p>

    <div class="table-responsive">
      <table class="run table">
        <thead>
          <tr>
            <th style="width:100px;">Time</th>
            <th>Contractor</th>
            <th>Material / Qty</th>
            <th style="width:140px;">Method</th>
            <th style="width:140px;">Status</th>
            <th style="width:170px;">Gate QR</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No deliveries found for this date.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $row):
                $gateUrl = gate_url_for($row);
                $time = date('H:i', strtotime((string)$row['due_datetime']));
                // Build QR image URL (SVG via icon.php)
                $qrSrc = '/icon.php?qr='.urlencode($gateUrl).'&s=180&fmt=svg&level=M&margin=2';
          ?>
          <tr>
            <td class="time"><?= htmlspecialchars($time) ?></td>
            <td class="contractor">
              <?= htmlspecialchars((string)$row['supplier']) ?>
              <?php if (!empty($row['user_name'])): ?><small><?= htmlspecialchars((string)$row['user_name']) ?></small><?php endif; ?>
            </td>
            <td class="mat">
              <?= htmlspecialchars((string)$row['material']) ?>
              <?php if ($row['quantity'] !== null && $row['quantity'] !== ''): ?>
                <small>Qty: <?= htmlspecialchars((string)$row['quantity']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars((string)$row['unloading_method']) ?></td>
            <td><span class="status-chip"><?= htmlspecialchars((string)$row['status']) ?></span></td>
            <td class="qr-cell">
              <a href="<?= htmlspecialchars($gateUrl) ?>" class="text-decoration-none" target="_blank" rel="noopener">
                <img
                  class="qr-img"
                  src="<?= htmlspecialchars($qrSrc) ?>"
                  alt="Gate QR"
                  width="140" height="140"
                  decoding="async" loading="lazy"
                />
                <div class="small text-muted mt-2">Scan at gate</div>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
