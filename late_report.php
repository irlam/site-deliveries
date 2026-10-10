<?php
// late_report.php — shows late and no-show deliveries over a date range
// Usage: /late_report.php?from=DD/MM/YYYY&to=DD/MM/YYYY
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';

date_default_timezone_set('Europe/London');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function parse_dmy(?string $d): ?string {
  if (!$d || !preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $d, $m)) return null;
  return "{$m[3]}-{$m[2]}-{$m[1]}";
}

$fromYmd = parse_dmy($_GET['from'] ?? '') ?: (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$toYmd   = parse_dmy($_GET['to']   ?? '') ?: (new DateTimeImmutable('sunday this week'))->format('Y-m-d');
$lateMins = 30;

$stmt = $pdo->prepare("
  SELECT id, supplier, user_name, material, quantity, unloading_method, status,
         due_datetime, arrived_at, completed_at, no_show,
         TIMESTAMPDIFF(MINUTE, due_datetime, arrived_at) AS late_by
  FROM deliveries
  WHERE DATE(due_datetime) BETWEEN ? AND ?
  ORDER BY due_datetime ASC
");
$stmt->execute([$fromYmd, $toYmd]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$late = [];
$noshow = [];
foreach ($rows as $r) {
  if ((int)$r['no_show'] === 1) {
    $noshow[] = $r;
    continue;
  }
  if (!empty($r['arrived_at'])) {
    $lateBy = (int)$r['late_by'];
    if ($lateBy > $lateMins) $late[] = $r + ['late_by' => $lateBy];
  }
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
<title>Late / No-show Report</title>
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
  th{font-weight:700}
  tbody tr:nth-child(even) td{background:#fafcff}
  form{margin:0 0 10px}
  input{padding:6px 8px;border:1px solid #b6c9e6;border-radius:8px;margin-right:6px}
  button{padding:7px 10px;border:1px solid #b6c9e6;border-radius:8px;background:#f1f7fd;color:#24508c}
  .muted{color:var(--muted)}
</style>
<?php if(logistics_enabled($pdo)): ?><meta name="logistics-csrf" content="<?=htmlspecialchars(logistics_csrf(),ENT_QUOTES,'UTF-8')?>"><script src="/assets/logistics-legacy.js"></script><?php endif; ?>
</head>
<body>
  <h1>Late / No-show</h1>
  <div class="sub">Window: <?= h((new DateTimeImmutable($fromYmd))->format('d/m/Y')) ?> – <?= h((new DateTimeImmutable($toYmd))->format('d/m/Y')) ?> · Late = &gt; <?= $lateMins ?> minutes</div>

  <form method="get">
    <input name="from" placeholder="DD/MM/YYYY" value="<?= h($_GET['from'] ?? '') ?>">
    <input name="to"   placeholder="DD/MM/YYYY" value="<?= h($_GET['to']   ?? '') ?>">
    <button type="submit">Apply</button>
  </form>

  <div class="grid">
    <div class="card">
      <h2>Late arrivals</h2>
      <table>
        <thead><tr>
          <th>Due</th><th>Arrived</th><th>Late by (min)</th><th>Contractor</th><th>Material</th><th>Method</th><th>Status</th>
        </tr></thead>
        <tbody>
          <?php if (!$late): ?>
            <tr><td colspan="7" class="muted">No late arrivals in range.</td></tr>
          <?php else: foreach ($late as $r): ?>
            <tr>
              <td><?= h(date('d/m/Y H:i', strtotime($r['due_datetime']))) ?></td>
              <td><?= h(date('d/m/Y H:i', strtotime($r['arrived_at']))) ?></td>
              <td><?= (int)$r['late_by'] ?></td>
              <td><?= h((string)$r['supplier']) ?></td>
              <td><?= h((string)$r['material']) ?></td>
              <td><?= h((string)$r['unloading_method']) ?></td>
              <td><?= h((string)$r['status']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>No-shows</h2>
      <table>
        <thead><tr>
          <th>Due</th><th>Contractor</th><th>Material</th><th>Method</th><th>Status</th>
        </tr></thead>
        <tbody>
          <?php if (!$noshow): ?>
            <tr><td colspan="5" class="muted">No no-shows in range.</td></tr>
          <?php else: foreach ($noshow as $r): ?>
            <tr>
              <td><?= h(date('d/m/Y H:i', strtotime($r['due_datetime']))) ?></td>
              <td><?= h((string)$r['supplier']) ?></td>
              <td><?= h((string)$r['material']) ?></td>
              <td><?= h((string)$r['unloading_method']) ?></td>
              <td><?= h((string)$r['status']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
