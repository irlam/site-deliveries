<?php
// reports_weekly.php — weekly summaries
// Usage: /reports_weekly.php?week=YYYY-MM-DD  (Monday date). Defaults to current Monday.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';

date_default_timezone_set('Europe/London');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$today = new DateTimeImmutable('today');
$isoDay = (int)$today->format('N'); // 1..7
$defaultMonday = $today->modify('-'.($isoDay-1).' days')->format('Y-m-d');

$mondayYmd = (isset($_GET['week']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['week']))
  ? $_GET['week']
  : $defaultMonday;

$monday = new DateTimeImmutable($mondayYmd.' 00:00:00');
$sunday = $monday->modify('+6 days')->setTime(23,59,59);

$from = $monday->format('Y-m-d H:i:s');
$to   = $sunday->format('Y-m-d H:i:s');

$contractor = $pdo->prepare("
  SELECT supplier AS contractor,
         COUNT(*) AS deliveries,
         SUM(CASE WHEN quantity REGEXP '^[0-9]+$' THEN quantity ELSE NULL END) AS total_numeric_qty
  FROM deliveries
  WHERE due_datetime BETWEEN ? AND ?
  GROUP BY supplier
  ORDER BY deliveries DESC, contractor ASC
");
$contractor->execute([$from, $to]);
$byContractor = $contractor->fetchAll(PDO::FETCH_ASSOC);

$method = $pdo->prepare("
  SELECT LOWER(unloading_method) AS m, COUNT(*) AS c
  FROM deliveries
  WHERE due_datetime BETWEEN ? AND ?
  GROUP BY LOWER(unloading_method)
");
$method->execute([$from, $to]);
$methodRows = $method->fetchAll(PDO::FETCH_ASSOC);

$total = array_sum(array_column($methodRows, 'c')) ?: 1;
$util = [];
foreach ($methodRows as $r) {
  $label = ucfirst($r['m'] ?: 'Unknown');
  $util[] = [
    'method' => $label,
    'count'  => (int)$r['c'],
    'pct'    => round(((int)$r['c'] / $total) * 100)
  ];
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
<title>Weekly Reports</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{ --brand:#1157b8; --muted:#667085; --table:#dbe3ee; --head:#eef5ff; }
  body{margin:0;padding:16px;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937}
  h1{margin:0 0 8px;color:var(--brand)}
  .sub{color:#475569;margin-bottom:14px}
  .grid{display:grid;grid-template-columns:1fr;gap:16px}
  .card{border:1px solid var(--table);border-radius:12px;overflow:hidden}
  .card h2{margin:0;padding:10px 12px;background:var(--head);color:#0f3a7a;font-size:16px}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:8px 10px;border-bottom:1px solid var(--table);text-align:left}
  th{text-align:left;font-weight:700}
  tbody tr:nth-child(even) td{background:#fafcff}
  .muted{color:var(--muted)}
  .bar{height:10px;background:#dceafe;border-radius:999px;overflow:hidden}
  .bar>span{display:block;height:100%;background:#1157b8}
  .toolbar{margin-bottom:10px}
  .toolbar a{display:inline-block;padding:7px 10px;border:1px solid #b6c9e6;border-radius:8px;text-decoration:none;color:#24508c;background:#f1f7fd;margin-right:8px}
</style>
<?php if(logistics_enabled($pdo)): ?><meta name="logistics-csrf" content="<?=htmlspecialchars(logistics_csrf(),ENT_QUOTES,'UTF-8')?>"><script src="/assets/logistics-legacy.js"></script><?php endif; ?>
</head>
<body>
  <h1>Weekly Reports</h1>
  <div class="sub"><?= h($monday->format('d/m/Y')) ?> – <?= h($sunday->format('d/m/Y')) ?></div>

  <div class="toolbar">
    <?php
      $prev = $monday->modify('-7 days')->format('Y-m-d');
      $next = $monday->modify('+7 days')->format('Y-m-d');
    ?>
    <a href="?week=<?= h($prev) ?>">← Previous</a>
    <a href="?week=<?= h($defaultMonday) ?>">This Week</a>
    <a href="?week=<?= h($next) ?>">Next →</a>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Summary by Contractor</h2>
      <table>
        <thead><tr><th>Contractor</th><th>Deliveries</th><th>Total Qty (numeric only)</th></tr></thead>
        <tbody>
          <?php if (!$byContractor): ?>
            <tr><td colspan="3" class="muted">No data.</td></tr>
          <?php else: foreach ($byContractor as $r): ?>
            <tr>
              <td><?= h((string)$r['contractor']) ?></td>
              <td><?= (int)$r['deliveries'] ?></td>
              <td><?= $r['total_numeric_qty'] !== null ? (int)$r['total_numeric_qty'] : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Method Utilization</h2>
      <table>
        <thead><tr><th>Method</th><th>Count</th><th>Share</th></tr></thead>
        <tbody>
          <?php if (!$util): ?>
            <tr><td colspan="3" class="muted">No data.</td></tr>
          <?php else: foreach ($util as $u): ?>
            <tr>
              <td><?= h($u['method']) ?></td>
              <td><?= (int)$u['count'] ?></td>
              <td style="min-width:180px">
                <div class="bar"><span style="width:<?= (int)$u['pct'] ?>%"></span></div>
                <div class="muted"><?= (int)$u['pct'] ?>%</div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
