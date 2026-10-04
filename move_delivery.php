<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/settings.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    admin_require($pdo); // 403 for non-admins
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Admins only.';
    exit;
}

function bad(string $m,int $code=400){ http_response_code($code); echo $m; exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$new = trim((string)($_POST['new_slot'] ?? $_POST['due_datetime'] ?? '')); // accept either
if ($id <= 0 || $new==='') bad('Invalid request');

// Accept "YYYY-MM-DD HH:MM" or "YYYY-MM-DDTHH:MM" literally
$new = str_replace('T',' ',$new);
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$new)) bad('Invalid date/time');
$new .= ':00';

// Optional: enforce admin time window & interval
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo);
$start = $ts['start'] ?? '06:00';
$end   = $ts['end']   ?? '18:00';
$step  = (int)($ts['interval'] ?? 20);

$toMin = static function(string $hhmm){ [$h,$m]=array_map('intval',explode(':',$hhmm)); return $h*60+$m; };
$hhmm = substr($new,11,5);
if ($toMin($hhmm) < $toMin($start) || $toMin($hhmm) > $toMin($end)) bad('Out of time window '.$start.'–'.$end);
if ($step>0 && (($toMin($hhmm)-$toMin($start)) % $step) !== 0) bad('Not aligned to '.$step.'-minute intervals');

try {
    $stmt = $pdo->prepare("UPDATE deliveries SET due_datetime = ? WHERE id = ?");
    $stmt->execute([$new,$id]);
    echo 'success';
} catch (PDOException $e) {
    if ($e->getCode()==='23000') bad('Slot already booked!',409);
    bad('Move failed',500);
}
