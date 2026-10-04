<?php
// /admin/metrics_data.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

admin_require($pdo);

// Time window for capacity math
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int]
$start = $ts['start']; $end = $ts['end']; $interval = max(5, (int)$ts['interval']);

// Helpers
function safe_query(PDO $pdo, string $sql, array $params = []): array {
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return [];
  }
}
function minutes_from_hhmm(string $hhmm): int {
  [$h, $m] = array_map('intval', explode(':', $hhmm));
  return $h * 60 + $m;
}

// Ranges
date_default_timezone_set('Europe/London');
$today = new DateTimeImmutable('today');
$day_30_ago = $today->sub(new DateInterval('P30D'));
$day_56_ago = $today->sub(new DateInterval('P56D'));
$day_14_ago = $today->sub(new DateInterval('P14D'));
$day_7_ahead = $today->add(new DateInterval('P7D'));

// Base filters
$range56 = [$day_56_ago->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
$range30 = [$day_30_ago->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
$range14 = [$day_14_ago->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
$upcoming7 = [$today->format('Y-m-d 00:00:00'), $day_7_ahead->format('Y-m-d 23:59:59')];

// Fetch blocks
$rows_last56 = safe_query($pdo, "SELECT id, supplier, status, unloading_method, due_datetime FROM deliveries WHERE due_datetime BETWEEN ? AND ? ORDER BY due_datetime ASC", $range56);
$rows_last30 = array_values(array_filter($rows_last56, fn($r) => $r['due_datetime'] >= $range30[0]));
$rows_last14 = array_values(array_filter($rows_last56, fn($r) => $r['due_datetime'] >= $range14[0]));
$rows_upcoming7 = safe_query($pdo, "SELECT id FROM deliveries WHERE due_datetime BETWEEN ? AND ?", $upcoming7);

// Totals
$total_all = (int)($pdo->query("SELECT COUNT(*) FROM deliveries")->fetchColumn() ?: 0);
$last7 = (int)( $pdo->query("SELECT COUNT(*) FROM deliveries WHERE due_datetime >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND due_datetime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn() ?: 0 );
$last30 = count($rows_last30);
$up7 = count($rows_upcoming7);

// Status (last 30d)
$statusCounts = ['Booked in'=>0,'Completed'=>0,'Cancelled'=>0,'Other'=>0];
foreach ($rows_last30 as $r) {
  $s = (string)($r['status'] ?? '');
  if (isset($statusCounts[$s])) $statusCounts[$s]++; else $statusCounts['Other']++;
}

// Unloading (last 30d)
$unload = [];
foreach ($rows_last30 as $r) {
  $k = trim((string)($r['unloading_method'] ?? 'Unknown')) ?: 'Unknown';
  $unload[$k] = ($unload[$k] ?? 0) + 1;
}
arsort($unload);

// Per day (last 56d)
$perDay = [];
$cursor = clone $day_56_ago;
while ($cursor <= $today) {
  $perDay[$cursor->format('Y-m-d')] = 0;
  $cursor = $cursor->add(new DateInterval('P1D'));
}
foreach ($rows_last56 as $r) {
  $d = substr($r['due_datetime'], 0, 10);
  if (isset($perDay[$d])) $perDay[$d]++;
}
$perDaySeries = array_map(fn($d, $c)=>['date'=>$d,'count'=>$c], array_keys($perDay), array_values($perDay));

// Per hour (last 30d)
$perHour = array_fill(0, 24, 0);
foreach ($rows_last30 as $r) {
  $h = (int)date('G', strtotime($r['due_datetime']));
  $perHour[$h] += 1;
}

// Top contractors (last 30d)
$bySupplier = [];
foreach ($rows_last30 as $r) {
  $s = trim((string)($r['supplier'] ?? 'Unknown')) ?: 'Unknown';
  $bySupplier[$s] = ($bySupplier[$s] ?? 0) + 1;
}
arsort($bySupplier);
$topSuppliers = [];
$i=0;
foreach ($bySupplier as $k=>$v) {
  $topSuppliers[] = ['supplier'=>$k,'count'=>$v];
  if (++$i>=10) break;
}

// Slot capacity vs booked (last 14 days)
$startMin = minutes_from_hhmm($start);
$endMin   = minutes_from_hhmm($end);
$slotsPerDay = 1 + max(0, intdiv(($endMin - $startMin), $interval)); // include start; if end aligns exactly, last slot at end
$capSeries = [];
$bookedByDay14 = [];
foreach ($rows_last14 as $r) {
  $d = substr($r['due_datetime'],0,10);
  $bookedByDay14[$d] = ($bookedByDay14[$d] ?? 0) + 1;
}
$cur = clone $day_14_ago;
while ($cur <= $today) {
  $d = $cur->format('Y-m-d');
  $booked = (int)($bookedByDay14[$d] ?? 0);
  $capSeries[] = [
    'date' => $d,
    'booked' => $booked,
    'capacity' => $slotsPerDay * 7, // 7 columns (Mon–Sun) capacity would be misleading per day; use per-day capacity only
    // Actually capacity per day is slotsPerDay (one column). Keep it simple:
  ];
  $cur = $cur->add(new DateInterval('P1D'));
}
// fix capacity per-day
foreach ($capSeries as &$row) {
  $row['capacity'] = $slotsPerDay;
  $row['util'] = $row['capacity'] > 0 ? round(($row['booked'] / $row['capacity']) * 100, 1) : 0.0;
}
unset($row);

// Off-grid count (last 30d vs current grid)
function is_off_grid(string $timeHHMM, string $startHHMM, int $step): bool {
  $toMin = fn($hhmm)=> (int)explode(':',$hhmm)[0]*60 + (int)explode(':',$hhmm)[1];
  $t = $toMin($timeHHMM);
  $s = $toMin($startHHMM);
  if ($t < $s) return true;
  return (($t - $s) % $step) !== 0;
}
$offGridCount = 0;
foreach ($rows_last30 as $r) {
  $hhmm = date('H:i', strtotime($r['due_datetime']));
  if (is_off_grid($hhmm, $start, $interval)) $offGridCount++;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'grid' => ['start'=>$start,'end'=>$end,'interval'=>$interval,'slotsPerDay'=>$slotsPerDay],
  'totals' => [
    'all' => $total_all,
    'last7' => $last7,
    'last30' => $last30,
    'upcoming7' => $up7,
    'offgrid_last30' => $offGridCount,
  ],
  'status_last30' => $statusCounts,
  'unloading_last30' => $unload,
  'per_day_last56' => $perDaySeries,
  'per_hour_last30' => $perHour,
  'top_suppliers_last30' => $topSuppliers,
  'capacity_last14' => $capSeries,
], JSON_UNESCAPED_SLASHES);
