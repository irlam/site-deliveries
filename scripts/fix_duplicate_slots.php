<?php
declare(strict_types=1);

/**
 * One-time fixer: spreads deliveries so each minute (slot_key) is unique.
 * It keeps the earliest record in any minute and bumps later ones forward
 * by their own step (duration_min, min 5) until a free minute is found.
 *
 * Delete this file after running successfully.
 */

// Reuse your existing PDO config
require_once __DIR__ . '/../db.php'; // <-- adjust if db.php lives elsewhere

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Helper: step minutes (respect duration_min, floor 5)
function stepMinutes(?int $m): int {
    $m = (int)($m ?? 20);
    return max(5, $m);
}

// Load *all* deliveries sorted by due_datetime then id
$sql = "SELECT id, due_datetime, duration_min FROM deliveries ORDER BY due_datetime ASC, id ASC";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Build an occupied set of minute keys
function keyFrom(string $dt): string {
    $d = new DateTime($dt);
    return $d->format('YmdHi');
}
$occupied = [];

// First pass: mark the first occurrence of each existing minute as occupied
foreach ($rows as $r) {
    $k = keyFrom($r['due_datetime']);
    if (!isset($occupied[$k])) {
        $occupied[$k] = true;
    }
}

// Second pass: for any row that collides (minute already taken by an earlier row),
// push it forward by its step repeatedly until a free minute is found.
$update = $pdo->prepare("UPDATE deliveries SET due_datetime = ? WHERE id = ?");
$fixed = 0;

foreach ($rows as $r) {
    $dt = new DateTime($r['due_datetime']);
    $k  = $dt->format('YmdHi');

    // If this minute wasn't yet claimed when we first saw it, claim it now and continue
    if (!isset($occupied[$k])) {
        $occupied[$k] = true;
        continue;
    }

    // Otherwise, we must move this row forward until we find a free minute
    $step = stepMinutes($r['duration_min'] ?? 20);
    $moved = false;
    while (isset($occupied[$k])) {
        $dt->modify("+{$step} minutes");
        $k = $dt->format('YmdHi');
        $moved = true;
    }

    if ($moved) {
        $update->execute([$dt->format('Y-m-d H:i:s'), $r['id']]);
        $occupied[$k] = true;
        $fixed++;
    }
}

echo "<pre>Resolved {$fixed} collisions. Now re-run the duplicates query; it should be zero.\n";
echo "When clean, add the UNIQUE index on deliveries.slot_key.\n</pre>";
