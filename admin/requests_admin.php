<?php
// /admin/requests_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/settings.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$adminLoggedIn = isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0;

if (!$adminLoggedIn) {
  header('Location: /admin/login.php');
  exit;
}

date_default_timezone_set('Europe/London');

// Fetch all open + recent requests (open first)
$stmt = $pdo->query("
  SELECT r.*, d.supplier, d.user_name, d.material, d.due_datetime
  FROM delivery_change_requests r
  LEFT JOIN deliveries d ON r.delivery_id = d.id
  ORDER BY (r.status='open') DESC, r.created_at DESC
");
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Delivery Change Requests – Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { background:#f7f9fc; padding:1.5rem; font-family:system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  h1 { font-size:1.6rem; margin-bottom:1rem; }
  .table td, .table th { vertical-align: middle; }
  .status-open     { background:#fff3cd; }
  .status-resolved { background:#d1e7dd; }
  .status-declined { background:#f8d7da; }
  .badge-open      { background:#ffc107; }
  .badge-resolved  { background:#198754; }
  .badge-declined  { background:#dc3545; }
</style>
</head>
<body>
<div class="container-fluid">
  <div class="d-flex align-items-center justify-content-between mb-2">
    <h1 class="mb-0">Change Requests Dashboard</h1>
    <a class="btn btn-outline-secondary" href="/index.php">&larr; Back to Calendar</a>
  </div>
  <p class="text-muted">Review, resolve, or decline user delivery change requests.</p>

  <?php if (!$requests): ?>
    <div class="alert alert-info">No requests found.</div>
  <?php else: ?>
    <table class="table table-bordered table-hover bg-white shadow-sm">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Status</th>
          <th>Requester</th>
          <th>Type</th>
          <th>Requested Date/Time</th>
          <th>Reason / Details</th>
          <th>Delivery Info</th>
          <th style="width:140px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $r): ?>
          <tr class="status-<?= esc($r['status']) ?>">
            <td><?= (int)$r['id'] ?></td>
            <td><span class="badge badge-<?= esc($r['status']) ?>"><?= esc(ucfirst($r['status'])) ?></span></td>
            <td>
              <strong><?= esc($r['requester_name']) ?></strong><br>
              <small><?= esc($r['contact']) ?: '—' ?></small><br>
              <span class="text-muted small"><?= esc($r['created_at']) ?></span>
            </td>
            <td><?= esc(str_replace('_', ' ', $r['request_type'])) ?></td>
            <td><?= esc($r['requested_dt'] ?: '—') ?></td>
            <td style="max-width:300px;white-space:pre-wrap;"><?= esc($r['details']) ?></td>
            <td>
              <?php if ($r['delivery_id']): ?>
                <strong>#<?= (int)$r['delivery_id'] ?></strong><br>
                <?= esc($r['supplier']) ?><br>
                <?= esc($r['material']) ?><br>
                <small><?= esc($r['due_datetime']) ?></small>
              <?php else: ?>
                <em class="text-muted">Delivery missing</em>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($r['status'] === 'open'): ?>
                <form method="post" action="/admin/update_request_status.php" class="d-flex flex-column gap-1">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button name="action" value="resolve" class="btn btn-success btn-sm">Mark Resolved</button>
                  <button name="action" value="decline" class="btn btn-danger btn-sm">Decline</button>
                </form>
              <?php else: ?>
                <small class="text-muted">Updated <?= esc($r['resolved_at'] ?: '') ?></small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
