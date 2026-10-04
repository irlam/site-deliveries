<?php
/**
 * book_delivery.php
 * --------------------------------------------------------------------
 * Handles AJAX booking of a SINGLE delivery slot from the calendar.
 * - Expects POST: supplier, material, quantity, due_datetime (YYYY-MM-DD HH:MM),
 *   unloading_method, user_name
 * - Uses the posted datetime LITERALLY (no timezone conversion) so it matches
 *   the calendar exactly.
 * - Optionally enforces admin time window & interval (if settings.php present).
 * - Avoids race conditions by relying on DB unique constraint and catching it.
 * --------------------------------------------------------------------
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// Optional: use settings to enforce time window & interval
$USE_SETTINGS = true;
$WINDOW_START = '06:00';
$WINDOW_END   = '18:00';
$INTERVAL_MIN = 20;

if ($USE_SETTINGS) {
    $settingsPath = __DIR__ . '/includes/settings.php';
    if (is_file($settingsPath)) {
        require_once $settingsPath;
        // Ensure defaults exist, then fetch
        if (function_exists('ensure_time_defaults') && function_exists('get_time_settings')) {
            try {
                ensure_time_defaults($pdo);
                $ts = get_time_settings($pdo);
                if (!empty($ts['start']))   $WINDOW_START = $ts['start'];
                if (!empty($ts['end']))     $WINDOW_END   = $ts['end'];
                if (!empty($ts['interval'])) $INTERVAL_MIN = (int)$ts['interval'];
            } catch (Throwable $e) {
                // If settings table not ready, fall back to defaults
            }
        }
    }
}

/* -------------------- helpers -------------------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function bad(string $msg): void {
    // Plain text is fine (used by fetch in the UI)
    echo $msg;
    exit;
}

/**
 * Validate "YYYY-MM-DD HH:MM"
 */
function is_valid_mysql_hhmm(string $s): bool {
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2} [0-2]\d:[0-5]\d$/', $s);
}

/**
 * Return minutes since midnight from "HH:MM"
 */
function minutes_of_day(string $hhmm): int {
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

/**
 * Compare two "HH:MM"
 */
function cmp_hhmm(string $a, string $b): int {
    return minutes_of_day($a) <=> minutes_of_day($b);
}

/* -------------------- validate POST -------------------- */

$required = ['supplier','material','quantity','due_datetime','unloading_method','user_name'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || trim((string)$_POST[$key]) === '') {
        bad('All fields required');
    }
}

$supplier         = trim((string)$_POST['supplier']);
$material         = trim((string)$_POST['material']);
$quantity         = trim((string)$_POST['quantity']);
$dueDatetimeInput = trim((string)$_POST['due_datetime']); // expected "YYYY-MM-DD HH:MM"
$unloadingMethod  = trim((string)$_POST['unloading_method']);
$userName         = trim((string)$_POST['user_name']);

// Validate datetime string format (literal)
if (!is_valid_mysql_hhmm($dueDatetimeInput)) {
    bad('Invalid date/time. Expected YYYY-MM-DD HH:MM');
}

// Optional: enforce the admin time window & interval
if ($USE_SETTINGS) {
    // Extract HH:MM part
    $hhmm = substr($dueDatetimeInput, 11, 5);

    // Check window (start <= hhmm <= end)
    if (cmp_hhmm($hhmm, $WINDOW_START) < 0 || cmp_hhmm($hhmm, $WINDOW_END) > 0) {
        bad('Please select a time within the allowed window: ' . $WINDOW_START . '–' . $WINDOW_END);
    }

    // Check interval alignment (e.g., every 20 min)
    $mins   = minutes_of_day($hhmm);
    $startM = minutes_of_day($WINDOW_START);
    $delta  = $mins - $startM;
    if ($INTERVAL_MIN > 0 && ($delta % $INTERVAL_MIN) !== 0) {
        bad('Time must align with ' . $INTERVAL_MIN . ' minute intervals.');
    }
}

// Normalize to seconds "YYYY-MM-DD HH:MM:00"
$dueDatetime = $dueDatetimeInput . ':00';

/* -------------------- insert -------------------- */
/**
 * We rely on DB unique constraint (e.g., uniq_slot_global on slot_key)
 * to prevent double-booking even under concurrency. If you don’t have that
 * index yet, add it (you already did earlier).
 */
try {
    $sql = "INSERT INTO deliveries
                (supplier, material, quantity, due_datetime, unloading_method, status, user_name, created_at)
            VALUES
                (:supplier, :material, :quantity, :due_datetime, :unloading_method, 'Scheduled', :user_name, NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':supplier'         => $supplier,
        ':material'         => $material,
        ':quantity'         => $quantity,
        ':due_datetime'     => $dueDatetime,
        ':unloading_method' => $unloadingMethod,
        ':user_name'        => $userName,
    ]);

    echo 'success';
    exit;

} catch (PDOException $e) {
    // 23000 / 1062 => duplicate key (slot already booked)
    if ($e->getCode() === '23000') {
        bad('Slot already booked!');
    }
    // Otherwise, generic error
    bad('Error booking slot.');
} catch (Throwable $e) {
    bad('Error booking slot.');
}
