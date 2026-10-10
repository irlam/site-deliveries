<?php
/**
 * printable_calendar.php
 * --------------------------------------------------------------------
 * Construction Site Delivery Management System - Printable Weekly Calendar
 * --------------------------------------------------------------------
 * - Generates a print-friendly (and PDF-exportable) weekly delivery calendar.
 * - Shows all 20-min slots from 06:00–18:00, Mon–Sun, with deliveries for the selected week.
 * - Uses UK date and time format throughout.
 * - Print styles are optimized for A4 landscape paper.
 * - Can be printed directly from the browser or exported as PDF using the browser's Print dialog.
 * - Usage: Link to this file from your main app, optionally with a `week` GET parameter (YYYY-MM-DD, any date in week).
 * --------------------------------------------------------------------
 */

require_once 'db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';


// Get the reference week from GET, default to today
$week = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d');

// Find the Monday of the week
$dt = new DateTime($week);
$day = (int)$dt->format('w'); // 0=Sunday, 1=Monday,...
$monday = clone $dt;
if ($day !== 1) {
    $monday->modify('last monday');
}
$monday->setTime(0,0,0);
$sunday = clone $monday;
$sunday->modify('+6 days');

// Generate all 20-minute time slots from 06:00 to 18:00
function generate_time_slots() {
    $slots = [];
    $t = new DateTime('06:00');
    $end = new DateTime('18:00');
    while ($t <= $end) {
        $slots[] = $t->format('H:i');
        $t->modify('+20 minutes');
    }
    return $slots;
}
$time_slots = generate_time_slots();

// Prepare array of days (Mon-Sun)
$days = [];
for ($i=0; $i<7; $i++) {
    $d = clone $monday;
    $d->modify("+$i days");
    $days[] = $d;
}

// Fetch all deliveries for the week
$start_str = $monday->format('Y-m-d 06:00:00');
$end_str = $sunday->format('Y-m-d 18:00:00');
$stmt = $pdo->prepare("SELECT * FROM deliveries WHERE due_datetime BETWEEN ? AND ? ORDER BY due_datetime ASC");
$stmt->execute([$start_str, $end_str]);
$deliveries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map slot (Y-m-d H:i) => delivery
$delivery_map = [];
foreach ($deliveries as $del) {
    $slot = date('Y-m-d H:i', strtotime($del['due_datetime']));
    $delivery_map[$slot] = $del;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

    <meta charset="UTF-8">
    <title>Printable Weekly Delivery Calendar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Print-optimized CSS -->
    <style>
    body {
        font-family: Arial, sans-serif;
        margin: 0.5cm 1.2cm;
        background: #fff;
        color: #222;
    }
    .header {
        text-align: center;
        margin-bottom: 1em;
    }
    .header h1 {
        margin: 0;
        color: #154e7b;
        font-size: 2em;
    }
    .header .meta {
        color: #666;
        font-size: 1.1em;
        margin-top: 0.3em;
    }
    .calendar-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 2em;
        font-size: 0.97em;
        table-layout: fixed;
    }
    .calendar-table th, .calendar-table td {
        border: 1px solid #b7cbe0;
        text-align: center;
        padding: 0.3em 0.2em;
        vertical-align: top;
    }
    .calendar-table th {
        background: #e4f2fd;
        color: #194d8e;
        font-weight: bold;
        font-size: 1em;
        position: sticky;
        top: 0;
        z-index: 2;
    }
    .calendar-table th.time-col {
        background: #f5f8fb;
        color: #5d6f7b;
        font-weight: 600;
        font-size: 0.97em;
        min-width: 56px;
    }
    .calendar-table td {
        min-width: 80px;
        height: 32px;
        background: #fafdff;
    }
    .calendar-table td.delivery {
        background: #eaf4de;
        color: #276618;
        font-weight: bold;
        font-size: 0.98em;
        border: 2px solid #95bb7b;
    }
    .calendar-table td.cancelled {
        background: #f5eaea;
        color: #a00;
        text-decoration: line-through;
        font-style: italic;
    }
    .calendar-table td.delivery .small {
        font-size: 0.90em;
        color: #537a44;
        font-weight: normal;
    }
    .calendar-table .unloading-icon {
        font-size: 1.3em;
        vertical-align: middle;
        margin-right: 0.12em;
    }
    @media print {
        body { margin: 0.1cm 0.2cm; }
        .header { margin-bottom: 0.3em; }
        .print-btn { display: none; }
        .calendar-table th, .calendar-table td { font-size: 0.92em; }
        .footer { display: none; }
    }
    .print-btn {
        display: inline-block;
        margin-bottom: 1em;
        padding: 0.4em 1.2em;
        background: #1976d2;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: 1.08em;
        cursor: pointer;
        float: right;
    }
    .footer {
        color: #aaa;
        font-size: 0.95em;
        text-align: right;
        margin-top: 2em;
    }
    </style>
<?php if(logistics_enabled($pdo)): ?><meta name="logistics-csrf" content="<?=htmlspecialchars(logistics_csrf(),ENT_QUOTES,'UTF-8')?>"><script src="/assets/logistics-legacy.js"></script><?php endif; ?>
</head>
<body>
    <div class="header">
        <button class="print-btn" onclick="window.print()">🖨 Print / Export as PDF</button>
        <h1>Weekly Delivery Calendar</h1>
        <div class="meta">
            <?= htmlspecialchars($monday->format('d/m/Y')) ?> – <?= htmlspecialchars($sunday->format('d/m/Y')) ?>
        </div>
    </div>
    <table class="calendar-table">
        <thead>
            <tr>
                <th class="time-col">Time</th>
                <?php foreach ($days as $d): ?>
                    <th>
                        <?= $d->format('D') ?><br>
                        <?= $d->format('d/m/Y') ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($time_slots as $slot_time): ?>
            <tr>
                <th class="time-col"><?= $slot_time ?></th>
                <?php foreach ($days as $d): 
                    $slot_key = $d->format('Y-m-d') . ' ' . $slot_time;
                    $delivery = isset($delivery_map[$slot_key]) ? $delivery_map[$slot_key] : null;
                ?>
                    <td
                        class="<?=
                            $delivery
                                ? ($delivery['status'] === 'Cancelled' ? 'cancelled' : 'delivery')
                                : ''
                        ?>"
                    >
                        <?php if ($delivery): ?>
                            <!-- Unloading method icon -->
                            <?php
                            $icon = '';
                            switch (strtolower($delivery['unloading_method'])) {
                                case 'crane':
                                    $icon = '<span class="unloading-icon" title="Crane">&#x1f4e2;</span>';
                                    break;
                                case 'forklift':
                                    $icon = '<span class="unloading-icon" title="Forklift">&#x1f69c;</span>';
                                    break;
                                case 'by hand':
                                    $icon = '<span class="unloading-icon" title="By Hand">&#x1f590;</span>';
                                    break;
                            }
                            ?>
                            <?= $icon ?>
                            <?= htmlspecialchars($delivery['supplier']) ?><br>
                            <span class="small"><?= htmlspecialchars($delivery['material']) ?></span>
                            <span class="small"><?= htmlspecialchars($delivery['quantity']) ?></span>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer">
        Printed: <?= date('d/m/Y H:i') ?> &mdash; Site Delivery Management System
    </div>
</body>
</html>