<?php
// booking_form.php — public add-delivery form with admin-driven time window + unloading methods
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';

// --- Time window from settings (defaults 06:00-18:00, step 20m) ---
ensure_time_defaults($pdo);
$ts = get_time_settings($pdo); // ['start'=>'HH:MM','end'=>'HH:MM','interval'=>int]

// Build slot list for the “Time” select (purely for UX). We’ll still submit a single datetime-local.
function build_time_options(string $start, string $end, int $stepMin): array {
    [$sh,$sm] = array_map('intval', explode(':',$start));
    [$eh,$em] = array_map('intval', explode(':',$end));
    $out = [];
    $t = $sh*60 + $sm;
    $limit = $eh*60 + $em;
    while ($t <= $limit) {
        $h = intdiv($t,60); $m = $t % 60;
        $out[] = sprintf('%02d:%02d', $h, $m);
        $t += $stepMin;
    }
    return $out;
}
$timeOptions = build_time_options($ts['start'], $ts['end'], (int)$ts['interval']);

// --- Unloading methods (active only, ordered). Fallback to legacy list if empty. ---
$methods = [];
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unloading_methods (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          label VARCHAR(100) NOT NULL UNIQUE,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $stmt = $pdo->query("SELECT label FROM unloading_methods WHERE is_active = 1 ORDER BY sort_order ASC, label ASC");
    $methods = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // ignore; fall back below
}
if (!$methods) {
    $methods = ['Crane', 'Forklift', 'By hand'];
}

// --- Helpful defaults for inputs ---
date_default_timezone_set('Europe/London');
$today = new DateTimeImmutable('now'); // browser will show local; server used for default only
$defaultDate = $today->format('Y-m-d');
$defaultTime = $timeOptions[0] ?? '06:00';
$defaultDT   = $defaultDate . 'T' . $defaultTime;

// For min/max on the datetime input (optional: restrict to business hours of the chosen day)
$minDT = $defaultDate . 'T' . $ts['start'];
$maxDT = $defaultDate . 'T' . $ts['end'];
?>
<form id="deliveryForm" class="row g-3" method="post" action="add_delivery.php" autocomplete="off" novalidate>
  <div class="col-md-4">
    <label class="form-label">Your Name</label>
    <input type="text" name="user_name" class="form-control" required placeholder="Your name">
  </div>
  <div class="col-md-4">
    <label class="form-label">Contractor</label>
    <input type="text" name="supplier" class="form-control" required placeholder="Company / Contractor">
  </div>
  <div class="col-md-4">
    <label class="form-label">Material</label>
    <input type="text" name="material" class="form-control" required placeholder="What’s being delivered?">
  </div>

  <div class="col-md-4">
    <label class="form-label">Quantity</label>
    <input type="text" name="quantity" class="form-control" required placeholder="e.g., 10 pallets">
  </div>

  <div class="col-md-4">
    <label class="form-label">Unloading Method</label>
    <select name="unloading_method" class="form-select" required>
      <option value="">Select...</option>
      <?php foreach ($methods as $m): ?>
        <option value="<?= htmlspecialchars($m, ENT_QUOTES) ?>"><?= htmlspecialchars($m) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Nice UX: pick date and time separately, but we still submit a single datetime-local -->
  <div class="col-md-4">
    <label class="form-label">Date</label>
    <input type="date" id="bf_date" class="form-control" value="<?= htmlspecialchars($defaultDate) ?>" required>
  </div>
  <div class="col-md-4">
    <label class="form-label">Time</label>
    <select id="bf_time" class="form-select" required>
      <?php foreach ($timeOptions as $t): ?>
        <option value="<?= $t ?>"<?= $t===$defaultTime?' selected':''; ?>><?= $t ?></option>
      <?php endforeach; ?>
    </select>
    <div class="form-text">Window: <?= htmlspecialchars($ts['start']) ?>–<?= htmlspecialchars($ts['end']) ?> in <?= (int)$ts['interval'] ?>-min steps</div>
  </div>

  <!-- Hidden actual field that the server expects -->
  <input type="hidden" name="due_datetime" id="bf_due_datetime" value="<?= htmlspecialchars($defaultDT) ?>">

  <div class="col-12">
    <button type="submit" class="btn btn-primary">Book Delivery</button>
    <span id="bf_msg" class="ms-3 text-muted"></span>
  </div>
</form>

<script>
// Keep hidden due_datetime in sync with Date + Time controls
const dateEl = document.getElementById('bf_date');
const timeEl = document.getElementById('bf_time');
const dueEl  = document.getElementById('bf_due_datetime');
const msgEl  = document.getElementById('bf_msg');

// Echo PHP time settings for client checks
<?php echo_time_settings_js($pdo); ?>

function pad2(n){ return String(n).padStart(2,'0'); }
function isWithinWindow(hhmm){
  const [h,m] = hhmm.split(':').map(Number);
  const t = h*60+m;
  const [sh,sm] = WINDOW_START.split(':').map(Number);
  const [eh,em] = WINDOW_END.split(':').map(Number);
  const a = sh*60+sm, b = eh*60+em;
  return t >= a && t <= b;
}
function syncDue(){
  const d = dateEl.value || '';
  const t = timeEl.value || '';
  if (!d || !t) return;
  dueEl.value = d + 'T' + t;
}
dateEl.addEventListener('input', syncDue);
timeEl.addEventListener('change', function(){
  if (!isWithinWindow(this.value)) {
    this.setCustomValidity('Please choose a time within the configured window.');
  } else {
    this.setCustomValidity('');
  }
  syncDue();
});
syncDue();

// Friendly handling of server responses (optional enhancement if form is AJAXed later)
document.getElementById('deliveryForm').addEventListener('submit', function(e){
  // Client-side guard against out-of-window times if user hand-edits fields
  const t = timeEl.value;
  if (!isWithinWindow(t)) {
    e.preventDefault();
    alert(`Please pick a time between ${WINDOW_START} and ${WINDOW_END}.`);
    timeEl.focus();
    return;
  }
  // Let normal submit happen; add_delivery.php will enforce uniqueness (DB) and reply.
});
</script>
