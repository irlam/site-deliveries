<?php
// add_delivery.php — create a single delivery with DB-enforced unique time slot
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';

// Utility
function back_with_msg(string $msg): void {
    // Keep it simple: bounce to homepage and let index.php show the alert if it reads ?msg=
    $loc = '/index.php?msg=' . urlencode($msg);
    header('Location: ' . $loc);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_with_msg('Please submit the form to add a delivery.');
}

// Collect & trim inputs
$supplier         = trim((string)($_POST['supplier'] ?? ''));
$user_name        = trim((string)($_POST['user_name'] ?? ''));
$material         = trim((string)($_POST['material'] ?? ''));
$quantity         = trim((string)($_POST['quantity'] ?? ''));
$unloading_method = trim((string)($_POST['unloading_method'] ?? ''));
$due_raw          = trim((string)($_POST['due_datetime'] ?? ''));

// Basic validation
if ($supplier === '' || $user_name === '' || $material === '' || $quantity === '' || $unloading_method === '' || $due_raw === '') {
    back_with_msg('Please complete all fields.');
}

// Parse datetime-local (YYYY-MM-DDTHH:MM)
$dateTime = DateTime::createFromFormat('Y-m-d\TH:i', $due_raw, new DateTimeZone('Europe/London'));
if (!$dateTime) {
    back_with_msg('Please enter a valid date and time.');
}

// Enforce time window from settings
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int]
$hhmm = $dateTime->format('H:i');

[$sh, $sm] = array_map('intval', explode(':', $ts['start']));
[$eh, $em] = array_map('intval', explode(':', $ts['end']));
$mins   = (int)$dateTime->format('H') * 60 + (int)$dateTime->format('i');
$minA   = $sh * 60 + $sm;
$minB   = $eh * 60 + $em;

if ($mins < $minA || $mins > $minB) {
    back_with_msg("Please select a time between {$ts['start']} and {$ts['end']}.");
}

// (Optional) snap-to-interval check (warn but still allow if you prefer strict => enforce)
$step = max(5, (int)$ts['interval']);
$offsetFromStart = $mins - $minA;
if ($offsetFromStart % $step !== 0) {
    // If you want to *enforce* exact steps, uncomment next line:
    // back_with_msg("Please pick a time aligned to {$step}-minute slots.");
    // Otherwise, continue and let DB uniqueness protect overlaps to the minute.
}

// Prepare insert
$dueSql = $dateTime->format('Y-m-d H:i:00'); // seconds set to 00
$status = 'Booked in';                       // keep consistent with your table filters
$durationMin = 20;                           // default; you can expose this later if needed

try {
    // Use a transaction for clean behaviour (optional here but good practice)
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO deliveries
            (supplier, material, quantity, driver, vehicle, due_datetime, status, unloading_method, duration_min, user_name)
        VALUES
            (?,        ?,        ?,        NULL,   NULL,    ?,            ?,      ?,                ?,            ?)
    ");
    $stmt->execute([
        $supplier,
        $material,
        $quantity,
        $dueSql,
        $status,
        $unloading_method,
        $durationMin,
        $user_name
    ]);

    $pdo->commit();

    // Friendly confirmation
    $nice = (new DateTimeImmutable($dueSql))->setTimezone(new DateTimeZone('Europe/London'))->format('d/m/Y H:i');
    back_with_msg("Success: delivery booked for {$nice}.");
} catch (PDOException $e) {
    // Duplicate slot detection: MySQL code 1062 for UNIQUE KEY violation on slot_key
    if (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        $pdo->rollBack();
        back_with_msg('That time slot is already booked. Please choose another.');
    }

    // Any other DB error: roll back and bubble a generic message (avoid leaking details)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    back_with_msg('Could not save delivery due to a database error.');
}
