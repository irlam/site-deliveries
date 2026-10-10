<?php
// book_delivery_multi.php — create multiple deliveries (one per selected slot)
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';

require_once __DIR__ . '/includes/settings.php';

header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Only POST allowed";
    exit;
}

// Gather inputs
$supplier         = trim((string)($_POST['supplier'] ?? ''));
$user_name        = trim((string)($_POST['user_name'] ?? ''));
$material         = trim((string)($_POST['material'] ?? ''));
$quantity         = trim((string)($_POST['quantity'] ?? ''));
$unloading_method = trim((string)($_POST['unloading_method'] ?? ''));
$slotsCsv         = trim((string)($_POST['slots'] ?? ''));

if ($supplier === '' || $user_name === '' || $material === '' || $quantity === '' || $unloading_method === '' || $slotsCsv === '') {
    http_response_code(400);
    echo "Please complete all fields.";
    exit;
}

// Load time settings (window + interval)
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int]
$START = $ts['start'] ?? '06:00';
$END   = $ts['end']   ?? '18:00';
$STEP  = max(5, (int)($ts['interval'] ?? 20));

// Helpers
$toMinutes = static function (string $hhmm): int {
    [$h,$m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
};
$inWindow = static function (string $hhmm) use ($START,$END,$toMinutes): bool {
    $x = $toMinutes($hhmm);
    return $x >= $toMinutes($START) && $x <= $toMinutes($END);
};
$aligned = static function (string $hhmm) use ($START,$STEP,$toMinutes): bool {
    if ($STEP <= 0) return true;
    $base = $toMinutes($START);
    $x    = $toMinutes($hhmm);
    return (($x - $base) % $STEP) === 0;
};

// Parse slots: “YYYY-MM-DD HH:MM” or “YYYY-MM-DDTHH:MM”
$rawSlots = array_filter(array_map('trim', explode(',', str_replace("\n", ',', $slotsCsv))));
if (!$rawSlots) {
    http_response_code(400);
    echo "No slots provided.";
    exit;
}

$normalized = []; // list of ['raw' => 'YYYY-MM-DD HH:MM', 'dueSql' => 'YYYY-MM-DD HH:MM:00']
$badFormat  = [];
$badWindow  = [];
$badStep    = [];

foreach ($rawSlots as $raw) {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})$/', $raw, $m)) {
        $badFormat[] = $raw;
        continue;
    }
    $Y = (int)$m[1]; $M = (int)$m[2]; $D = (int)$m[3];
    $h = (int)$m[4]; $i = (int)$m[5];

    // basic range checks
    if ($M < 1 || $M > 12 || $D < 1 || $D > 31 || $h < 0 || $h > 23 || $i < 0 || $i > 59) {
        $badFormat[] = $raw;
        continue;
    }

    $hhmm = sprintf('%02d:%02d', $h, $i);

    if (!$inWindow($hhmm)) {
        $badWindow[] = $raw;
        continue;
    }
    if (!$aligned($hhmm)) {
        $badStep[] = $raw;
        continue;
    }

    $dueSql = sprintf('%04d-%02d-%02d %02d:%02d:00', $Y,$M,$D,$h,$i);
    $normalized[] = ['raw' => $raw, 'dueSql' => $dueSql];
}

if ($badFormat) {
    http_response_code(400);
    echo "Invalid date/time format for: " . implode(', ', $badFormat);
    exit;
}
if ($badWindow) {
    http_response_code(400);
    echo "Out of time window ({$START}–{$END}): " . implode(', ', $badWindow);
    exit;
}
if ($badStep) {
    http_response_code(400);
    echo "Not aligned to {$STEP}-minute intervals: " . implode(', ', $badStep);
    exit;
}

// Insert each (best-effort). We rely on a unique key on the slot (e.g. GENERATED slot_key)
// so we can safely catch clashes.
$status      = 'Booked in';
$durationMin = 20; // keep as-is unless you want to read from settings too.

$created   = 0;
$conflicts = [];
$errors    = [];

$sql = "
    INSERT INTO deliveries
        (supplier, material, quantity, driver, vehicle, due_datetime, status, unloading_method, duration_min, user_name)
    VALUES
        (:supplier, :material, :quantity, NULL, NULL, :due_datetime, :status, :unloading_method, :duration_min, :user_name)
";
$stmt = $pdo->prepare($sql);

foreach ($normalized as $n) {
    try {
        $stmt->execute([
            ':supplier'         => $supplier,
            ':material'         => $material,
            ':quantity'         => $quantity,
            ':due_datetime'     => $n['dueSql'],   // literal
            ':status'           => $status,
            ':unloading_method' => $unloading_method,
            ':duration_min'     => $durationMin,
            ':user_name'        => $user_name,
        ]);
        $created++;
    } catch (PDOException $e) {
        // Duplicate (slot already taken) — MySQL 1062, SQLSTATE 23000
        if (($e->getCode() === '23000') || (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062)) {
            $conflicts[] = $n['raw'];
        } else {
            $errors[] = $n['raw'];
        }
    }
}

// Build response
if ($created > 0 && !$conflicts && !$errors) {
    echo "Success: booked {$created} slot(s).";
    exit;
}

$msg = [];
if ($created > 0) $msg[] = "Booked {$created} slot(s)";
if ($conflicts)   $msg[] = "Already booked: " . implode(', ', $conflicts);
if ($errors)      $msg[] = "Failed: " . implode(', ', $errors);

http_response_code($created > 0 ? 200 : 409);
echo $msg ? implode(' | ', $msg) : 'No slots booked.';
