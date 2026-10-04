<?php
// /admin/day_notes.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

admin_require($pdo);

// ---- helpers --------------------------------------------------------------
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetch();
  } catch (Throwable $e) { return false; }
}

// ---- ensure table (prefer `day` column) -----------------------------------
$pdo->exec("
  CREATE TABLE IF NOT EXISTS day_notes (
    day DATE NOT NULL PRIMARY KEY,
    note TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
      ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$COL = column_exists($pdo, 'day_notes', 'day') ? 'day'
     : (column_exists($pdo, 'day_notes', 'date') ? 'date' : 'day');

// ---- POST upsert / delete --------------------------------------------------
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $dayStr = trim((string)($_POST['date'] ?? '')); // value as YYYY-MM-DD
  $note   = trim((string)($_POST['note'] ?? ''));
  try {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayStr)) {
      throw new RuntimeException('Please use a valid date.');
    }
    if ($note === '') {
      $st = $pdo->prepare("DELETE FROM day_notes WHERE `$COL` = ?");
      $st->execute([$dayStr]);
      $msg = 'Note removed.';
    } else {
      $sql = "INSERT INTO day_notes (`$COL`, note) VALUES (?, ?)
              ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = CURRENT_TIMESTAMP";
      $st = $pdo->prepare($sql);
      $st->execute([$dayStr, $note]);
      $msg = 'Saved.';
    }
  } catch (Throwable $e) { $err = $e->getMessage(); }
}

// GET delete
if (isset($_GET['del'])) {
  $d = (string)$_GET['del'];
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
    $pdo->prepare("DELETE FROM day_notes WHERE `$COL` = ?")->execute([$d]);
    $msg = 'Deleted.';
  }
}

// ---- fetch recent & upcoming ----------------------------------------------
$from = (new DateTime('today'))->modify('-30 days')->format('Y-m-d');
$to   = (new DateTime('today'))->modify('+60 days')->format('Y-m-d');

$st = $pdo->prepare("SELECT `$COL` AS day, note, updated_at
                     FROM day_notes
                     WHERE `$COL` BETWEEN ? AND ?
                     ORDER BY `$COL` ASC");
$st->execute([$from, $to]);
$list = $st->fetchAll(PDO::FETCH_ASSOC);

// prefill today in control
$today = (new DateTime('today'))->format('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

<meta charset="utf-8">
<title>Admin · Day Notes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Flatpickr (calendar) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
  body{background:#0b1220;color:#e5e7eb}
  .panel{background:#111827;border:1px solid #1f2937;border-radius:16px;padding:16px}
  label{font-weight:600;margin-top:8px}
  .form-control, textarea{background:#0f172a;border:1px solid #1f2937;color:#e5e7eb}
  a.btn, .btn{border-radius:12px}
  table{color:#e5e7eb}
  .muted{color:#94a3b8}
  /* flatpickr dark tweak */
  .flatpickr-calendar{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
</style>
</head>
<body>
<div class="container my-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4">Day Notes</h1>
    <div><a class="btn btn-secondary" href="/admin/">Back to Dashboard</a></div>
  </div>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>

  <div class="panel mb-4">
    <form method="post" autocomplete="off">
      <div class="row g-3 align-items-start">
        <div class="col-sm-3">
          <label>Date (DD/MM/YYYY)</label>
          <!-- We keep the value as YYYY-MM-DD for the server but show UK via flatpickr altInput -->
          <input type="text" id="noteDate" name="date" class="form-control" value="<?= h($today) ?>" placeholder="dd/mm/yyyy" required>
          <div class="form-text muted">One note per day (upsert). Leave note empty to delete.</div>
        </div>
        <div class="col-sm-9">
          <label>Note</label>
          <textarea name="note" class="form-control" rows="3" placeholder="e.g. Road closure Tue AM, or crane service."></textarea>
        </div>
      </div>
      <div class="mt-3 text-end">
        <button class="btn btn-primary" type="submit">Save Note</button>
      </div>
    </form>
  </div>

  <div class="panel">
    <h2 class="h5 mb-3">Recent & Upcoming Notes</h2>
    <?php if (!$list): ?>
      <p class="muted mb-0">No notes yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr>
            <th style="width:130px">Date</th>
            <th>Note</th>
            <th style="width:160px">Updated</th>
            <th style="width:100px"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($list as $r): ?>
            <tr>
              <td><?= h($r['day']) ?></td>
              <td><?= nl2br(h((string)$r['note'])) ?></td>
              <td class="muted"><?= h($r['updated_at']) ?></td>
              <td><a class="btn btn-sm btn-danger" href="/admin/day_notes.php?del=<?= h($r['day']) ?>" onclick="return confirm('Delete note for <?= h($r['day']) ?>?')">Delete</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// UK-style calendar: shows DD/MM/YYYY, posts YYYY-MM-DD
flatpickr("#noteDate", {
  dateFormat: "Y-m-d",   // value sent to server
  altInput: true,
  altFormat: "d/m/Y",    // what the user sees
  allowInput: true,
  defaultDate: "<?= h($today) ?>",
  locale: { firstDayOfWeek: 1 } // Monday start, UK feel
});
</script>
</body>
</html>
