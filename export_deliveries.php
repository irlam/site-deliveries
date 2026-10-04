<?php
// export_deliveries.php
// -----------------------------------------------------------------------------
// Exports deliveries as CSV or pretty HTML (daily/weekly reports).
// Uses the MySQL datetime *literally* (no timezone conversion) so it matches the calendar.
// -----------------------------------------------------------------------------

declare(strict_types=1);
require_once __DIR__ . '/db.php';

date_default_timezone_set('Europe/London'); // ok to keep for "Generated:" etc.

// ---------- helpers ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// Parse DD/MM/YYYY to YYYY-MM-DD (returns null if invalid)
function parse_dmy(?string $dmy): ?string {
    if (!$dmy) return null;
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dmy, $m)) return null;
    [$all, $d, $mth, $y] = $m;
    return sprintf('%04d-%02d-%02d', (int)$y, (int)$mth, (int)$d);
}

function title_date_range(\DateTimeInterface $a, \DateTimeInterface $b): string {
    if ($a->format('Y-m-d') === $b->format('Y-m-d')) return $a->format('d/m/Y');
    return $a->format('d/m/Y') . ' – ' . $b->format('d/m/Y');
}

/**
 * Render a MySQL DATETIME literally as UK DD/MM/YYYY HH:MM with NO TZ conversion.
 * Accepts "YYYY-MM-DD HH:MM:SS" or "YYYY-MM-DD HH:MM".
 */
function uk_from_mysql_local(string $dt): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}:\d{2})/', $dt, $m)) {
        return h($dt);
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4];
}

// ---------- inputs ----------
$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'csv'; // 'csv' or 'html'
$range  = isset($_GET['range'])  ? strtolower(trim((string)$_GET['range']))  : '';
$date   = isset($_GET['date'])   ? trim((string)$_GET['date'])               : '';   // YYYY-MM-DD for daily
$week   = isset($_GET['week'])   ? trim((string)$_GET['week'])               : '';   // YYYY-MM-DD (Monday) for weekly
$fromDMY= isset($_GET['from'])   ? trim((string)$_GET['from'])               : '';
$toDMY  = isset($_GET['to'])     ? trim((string)$_GET['to'])                 : '';

$fromYmd = parse_dmy($fromDMY);
$toYmd   = parse_dmy($toDMY);

// Build WHERE + params
$where = [];
$params = [];

// Daily / Weekly presets (HTML or CSV—both supported)
if ($range === 'daily' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'DATE(due_datetime) = ?';
    $params[] = $date;
} elseif ($range === 'weekly' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    // assume $week is the Monday
    $monday = new DateTimeImmutable($week . ' 00:00:00');
    // End inclusive: Sunday 23:59:59
    $sunday = $monday->modify('+6 days')->setTime(23,59,59);
    $where[] = 'due_datetime BETWEEN ? AND ?';
    $params[] = $monday->format('Y-m-d H:i:s');
    $params[] = $sunday->format('Y-m-d H:i:s');
} elseif ($fromYmd || $toYmd) {
    // custom date range via from/to (inclusive)
    if ($fromYmd) {
        $where[] = 'due_datetime >= ?';
        $params[] = $fromYmd . ' 00:00:00';
    }
    if ($toYmd) {
        $where[] = 'due_datetime <= ?';
        $params[] = $toYmd . ' 23:59:59';
    }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ---------- fetch ----------
$sql = "SELECT id, supplier, user_name, material, quantity, unloading_method, status, due_datetime
        FROM deliveries
        $whereSql
        ORDER BY due_datetime ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- output ----------
if ($format !== 'html') {
    // CSV (default)
    header('Content-Type: text/csv; charset=UTF-8');
    $fnameBits = ['deliveries'];
    if ($range === 'daily' && $date) $fnameBits[] = 'daily_' . $date;
    if ($range === 'weekly' && $week) $fnameBits[] = 'weekly_' . $week;
    if ($fromYmd || $toYmd) $fnameBits[] = 'custom';
    $filename = implode('_', $fnameBits) . '.csv';
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel friendliness
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Contractor','Booked By','Material','Quantity','Unloading Method','Status','Due Date/Time (UK)']);

    foreach ($rows as $r) {
        $tsUK = uk_from_mysql_local((string)$r['due_datetime']); // <<< NO conversion
        fputcsv($out, [
            $r['supplier'] ?? '',
            $r['user_name'] ?? '',
            $r['material'] ?? '',
            $r['quantity'] ?? '',
            $r['unloading_method'] ?? '',
            $r['status'] ?? '',
            $tsUK
        ]);
    }
    fclose($out);
    exit;
}

// ---------- HTML (pretty printable report) ----------
$today = new DateTimeImmutable('now');
$reportTitle = 'Deliveries Report';
$subTitle    = '';

if ($range === 'daily' && $date) {
    try {
        $d = new DateTimeImmutable($date);
        $reportTitle = 'Today’s Deliveries';
        $subTitle = $d->format('l d/m/Y');
    } catch (\Throwable $e) { /* ignore */ }
} elseif ($range === 'weekly' && $week) {
    try {
        $monday = new DateTimeImmutable($week);
        $sunday = $monday->modify('+6 days');
        $reportTitle = 'Weekly Deliveries';
        $subTitle = title_date_range($monday, $sunday) . ' (' . $monday->format('W') . ')';
    } catch (\Throwable $e) { /* ignore */ }
} elseif ($fromYmd || $toYmd) {
    $a = new DateTimeImmutable(($fromYmd ?: $today->format('Y-m-d')) . ' 00:00:00');
    $b = new DateTimeImmutable(($toYmd ?: $today->format('Y-m-d')) . ' 23:59:59');
    $reportTitle = 'Deliveries (Custom Range)';
    $subTitle = title_date_range($a, $b);
} else {
    $reportTitle = 'All Deliveries';
    $subTitle = 'Generated ' . $today->format('d/m/Y H:i') . ' (UK)';
}

?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title><?= h($reportTitle) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  /* A4 landscape print */
  @page { size: A4 landscape; margin: 14mm; }
  @media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .no-print { display: none !important; }
  }
  :root{
    --brand:#1157b8;
    --muted:#667085;
    --table-border:#dbe3ee;
    --thead-bg:#eef5ff;
  }
  *{box-sizing:border-box}
  body{
    margin:0; padding:16px;
    font: 14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    color:#1f2937;
    background:#ffffff;
  }
  header{
    display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:14px;
    border-bottom: 2px solid var(--table-border); padding-bottom: 10px;
  }
  .brand{
    display:flex; align-items:center; gap:12px;
  }
  .brand img{ height:48px; width:auto; }
  .titles h1{
    margin:0; font-size: 22px; color: var(--brand);
  }
  .titles .sub{
    margin:0; color: var(--muted); font-weight: 500;
  }
  .meta{
    text-align:right; color:#334155; font-size:13px;
  }
  .wrap{ margin-top: 12px; }

  table{
    width:100%;
    border-collapse: separate;
    border-spacing:0;
    table-layout: fixed;
    border:1px solid var(--table-border);
    border-radius: 12px;
    overflow: hidden;
  }
  thead th{
    background: var(--thead-bg);
    color:#0f3a7a;
    text-align: center;
    padding:10px 6px;
    font-weight:700;
    border-bottom:1px solid var(--table-border);
    font-size: 13.5px;
  }
  tbody td{
    text-align: center;
    padding:8px 6px;
    border-bottom:1px solid var(--table-border);
    font-size: 13.5px;
    word-wrap: break-word;
  }
  tbody tr:nth-child(even) td{ background:#fafcff; }
  tfoot td{
    padding:10px 6px; text-align:center; color:#475569; font-size:12.5px;
  }

  .footer-note{ margin-top:8px; color:#64748b; font-size:12.5px; text-align:center; }

  /* A4 landscape widths */
  colgroup col:nth-child(1){ width: 18%; }
  colgroup col:nth-child(2){ width: 14%; }
  colgroup col:nth-child(3){ width: 18%; }
  colgroup col:nth-child(4){ width: 10%; }
  colgroup col:nth-child(5){ width: 14%; }
  colgroup col:nth-child(6){ width: 12%; }
  colgroup col:nth-child(7){ width: 14%; }
</style>
</head>
<body>
<header>
  <div class="brand">
    <img src="icons/icon-192.png" alt="Logo">
    <div class="titles">
      <h1><?= h($reportTitle) ?></h1>
      <div class="sub"><?= h($subTitle) ?></div>
    </div>
  </div>
  <div class="meta">
    <div><strong>Generated:</strong> <?= h((new DateTimeImmutable('now'))->format('d/m/Y H:i')) ?> (UK)</div>
  </div>
</header>

<div class="wrap">
  <table>
    <colgroup>
      <col><col><col><col><col><col><col>
    </colgroup>
    <thead>
      <tr>
        <th>Contractor</th>
        <th>Booked By</th>
        <th>Material</th>
        <th>Quantity</th>
        <th>Unloading Method</th>
        <th>Status</th>
        <th>Due Date/Time (UK)</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:16px;color:#64748b;">No deliveries found for the selected period.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= h((string)($r['supplier'] ?? '')) ?></td>
            <td><?= h((string)($r['user_name'] ?? '')) ?></td>
            <td><?= h((string)($r['material'] ?? '')) ?></td>
            <td><?= h((string)($r['quantity'] ?? '')) ?></td>
            <td><?= h((string)($r['unloading_method'] ?? '')) ?></td>
            <td><?= h((string)($r['status'] ?? '')) ?></td>
            <td><?= h(uk_from_mysql_local((string)$r['due_datetime'])) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="7">Total deliveries: <?= count($rows) ?></td></tr>
    </tfoot>
  </table>

  <div class="footer-note">© Defect Tracker — Optimised for A4 Landscape printing</div>
</div>
</body>
</html>
