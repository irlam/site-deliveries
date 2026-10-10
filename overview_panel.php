<?php
/**
 * overview_panel.php
 * --------------------------------------------------------------------
 * Site Delivery Management System - Overview Stats Panel (Component)
 * --------------------------------------------------------------------
 * - Displays a modern, responsive, and visually appealing overview of booking statistics.
 * - Shows total deliveries, today's deliveries, this week's deliveries,
 *   and a breakdown by unloading method (Crane, Forklift, By hand).
 * - All main stat cards and unloading method cards are clickable.
 * - Clicking any card opens a Bootstrap modal with detailed stats for that metric.
 * - Uses Bootstrap 5 for styling, layout, and modal, and Material Symbols for icons.
 * - All times and dates in UK format (DD/MM/YYYY or HH:MM).
 * --------------------------------------------------------------------
 */

require_once 'db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';


// --- Fetch delivery stats from the database ---

// Total deliveries ever booked in
$total_deliveries = $pdo->query("SELECT COUNT(*) FROM deliveries")->fetchColumn();

// Deliveries for today (UK date)
$today = date('Y-m-d');
$today_deliveries = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE DATE(due_datetime) = ?");
$today_deliveries->execute([$today]);
$today_deliveries = $today_deliveries->fetchColumn();

// Deliveries for current week (Mon-Sun, UK)
$monday = date('Y-m-d', strtotime('monday this week'));
$sunday = date('Y-m-d', strtotime('sunday this week'));
$week_deliveries = $pdo->prepare("SELECT COUNT(*) FROM deliveries WHERE DATE(due_datetime) BETWEEN ? AND ?");
$week_deliveries->execute([$monday, $sunday]);
$week_deliveries = $week_deliveries->fetchColumn();

// Unloading method breakdown for this week (Mon-Sun)
$crane_deliveries = $pdo->prepare("SELECT * FROM deliveries WHERE unloading_method = 'Crane' AND DATE(due_datetime) BETWEEN ? AND ? ORDER BY due_datetime");
$crane_deliveries->execute([$monday, $sunday]);
$crane_deliveries_arr = $crane_deliveries->fetchAll(PDO::FETCH_ASSOC);
$crane_count = count($crane_deliveries_arr);

$forklift_deliveries = $pdo->prepare("SELECT * FROM deliveries WHERE unloading_method = 'Forklift' AND DATE(due_datetime) BETWEEN ? AND ? ORDER BY due_datetime");
$forklift_deliveries->execute([$monday, $sunday]);
$forklift_deliveries_arr = $forklift_deliveries->fetchAll(PDO::FETCH_ASSOC);
$forklift_count = count($forklift_deliveries_arr);

$byhand_deliveries = $pdo->prepare("SELECT * FROM deliveries WHERE unloading_method = 'By hand' AND DATE(due_datetime) BETWEEN ? AND ? ORDER BY due_datetime");
$byhand_deliveries->execute([$monday, $sunday]);
$byhand_deliveries_arr = $byhand_deliveries->fetchAll(PDO::FETCH_ASSOC);
$byhand_count = count($byhand_deliveries_arr);

// Next delivery due (today and in the future, status not Cancelled)
$next_delivery = $pdo->query("SELECT * FROM deliveries WHERE due_datetime >= NOW() AND status != 'Cancelled' ORDER BY due_datetime ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Helper to format date/time as UK
function uk_datetime($dt) {
    return date('d/m/Y H:i', strtotime($dt));
}
function uk_date($dt) {
    return date('d/m/Y', strtotime($dt));
}
function uk_time($dt) {
    return date('H:i', strtotime($dt));
}
?>
<!--
  Overview Stats Panel
  - Responsive, visually appealing dashboard cards for quick stats.
  - Cards are clickable to show Bootstrap modal with more info.
  - Uses Bootstrap 5 grid for layout and Material Symbols for icons.
  - Custom CSS is used for modern, colourful card effects.
-->
<style>
/* Modern stat card styling for overview */
.stat-cards-row .overview-card {
    border: none;
    border-radius: 1.1rem;
    box-shadow: 0 2px 18px 0 rgba(20,60,120,0.09), 0 1.5px 6px 0 rgba(20,60,120,0.09);
    transition: transform 0.11s, box-shadow 0.18s;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    background: #fff;
    min-height: 160px;
}
.stat-cards-row .overview-card::before {
    content: "";
    position: absolute;
    z-index: 0;
    top: -45px; left: -45px;
    width: 110px; height: 110px;
    border-radius: 100%;
    opacity: 0.12;
    background: var(--accent, #0d6efd);
}
.stat-cards-row .overview-card:hover,
.stat-cards-row .overview-card:focus {
    transform: translateY(-3px) scale(1.03);
    box-shadow: 0 8px 32px 0 rgba(20,60,120,0.12), 0 3px 12px 0 rgba(0,60,200,0.09);
}
.stat-cards-row .overview-card .stat-icon {
    font-size: 2.6em;
    background: var(--accent, #0d6efd);
    color: #fff;
    padding: 0.28em;
    border-radius: 0.5em;
    margin-bottom: 0.15em;
    box-shadow: 0 0.5em 1.5em -1em var(--accent, #0d6efd);
    z-index: 1;
    position: relative;
    display: inline-block;
}
.stat-cards-row .overview-card .stat-label {
    font-size: 1.09em;
    font-weight: 500;
    color: #4A587e;
}
.stat-cards-row .overview-card .stat-value {
    font-size: 2.0em;
    font-weight: 700;
    margin-bottom: 0.1em;
}
.stat-cards-row .overview-card[data-type="total"] { --accent: #0d6efd; }
.stat-cards-row .overview-card[data-type="today"] { --accent: #28a745; }
.stat-cards-row .overview-card[data-type="week"] { --accent: #17a2b8; }
.stat-cards-row .overview-card[data-type="next"] { --accent: #f8b400; }
.stat-cards-row .overview-card[data-type="crane"] { --accent: #0057b8; }
.stat-cards-row .overview-card[data-type="forklift"] { --accent: #28a745; }
.stat-cards-row .overview-card[data-type="byhand"] { --accent: #f8b400; }
@media (max-width: 575px) {
    .stat-cards-row .overview-card { min-height: 125px; }
    .stat-cards-row .overview-card .stat-value { font-size: 1.3em; }
    .stat-cards-row .overview-card .stat-label { font-size: 1em; }
}
</style>

<div class="container mb-4">
  <!-- Row: Main Stat Cards (clickable) -->
  <div class="row g-3 stat-cards-row">
    <!-- Total Deliveries Card -->
    <div class="col-6 col-md-3">
      <div class="card overview-card" 
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="total"
        tabindex="0" aria-label="Total Deliveries (click for details)">
        <div class="card-body text-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">inventory_2</span>
          <div class="stat-value"><?= $total_deliveries ?></div>
          <div class="stat-label">Total Booked In</div>
        </div>
      </div>
    </div>
    <!-- Today's Deliveries Card -->
    <div class="col-6 col-md-3">
      <div class="card overview-card" 
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="today"
        tabindex="0" aria-label="Today's Deliveries (click for details)">
        <div class="card-body text-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">calendar_today</span>
          <div class="stat-value"><?= $today_deliveries ?></div>
          <div class="stat-label">Today</div>
        </div>
      </div>
    </div>
    <!-- This Week's Deliveries Card -->
    <div class="col-6 col-md-3">
      <div class="card overview-card" 
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="week"
        tabindex="0" aria-label="This Week's Deliveries (click for details)">
        <div class="card-body text-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">calendar_view_week</span>
          <div class="stat-value"><?= $week_deliveries ?></div>
          <div class="stat-label">This Week</div>
        </div>
      </div>
    </div>
    <!-- Next Delivery Card -->
    <div class="col-6 col-md-3">
      <div class="card overview-card" 
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="next"
        tabindex="0" aria-label="Next Delivery (click for details)">
        <div class="card-body text-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">schedule</span>
          <?php if ($next_delivery): ?>
            <div class="stat-value" style="font-size:1.3em;"><?= uk_time($next_delivery['due_datetime']) ?><br><span style="font-size:0.75em;"><?= uk_date($next_delivery['due_datetime']) ?></span></div>
            <div class="stat-label">Next: <?= htmlspecialchars($next_delivery['supplier']) ?></div>
          <?php else: ?>
            <div class="stat-value" style="font-size:1.1em;">No upcoming</div>
            <div class="stat-label">deliveries</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <!-- Row: Unloading Method Cards (clickable) -->
  <div class="row g-3 mt-1 stat-cards-row">
    <div class="col-4">
      <div class="card overview-card d-flex flex-row align-items-center p-2"
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="crane"
        tabindex="0" aria-label="Crane Deliveries (click for details)">
        <div class="me-2 align-self-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">precision_manufacturing</span>
        </div>
        <div>
          <div class="stat-value"><?= $crane_count ?></div>
          <div class="stat-label">Crane</div>
        </div>
      </div>
    </div>
    <div class="col-4">
      <div class="card overview-card d-flex flex-row align-items-center p-2"
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="forklift"
        tabindex="0" aria-label="Forklift Deliveries (click for details)">
        <div class="me-2 align-self-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">forklift</span>
        </div>
        <div>
          <div class="stat-value"><?= $forklift_count ?></div>
          <div class="stat-label">Forklift</div>
        </div>
      </div>
    </div>
    <div class="col-4">
      <div class="card overview-card d-flex flex-row align-items-center p-2"
        data-bs-toggle="modal" data-bs-target="#overviewModal"
        data-type="byhand"
        tabindex="0" aria-label="By hand Deliveries (click for details)">
        <div class="me-2 align-self-center">
          <span class="stat-icon material-symbols-rounded" aria-hidden="true">transfer_within_a_station</span>
        </div>
        <div>
          <div class="stat-value"><?= $byhand_count ?></div>
          <div class="stat-label">By hand</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Bootstrap Modal for overview details -->
<div class="modal fade" id="overviewModal" tabindex="-1" aria-labelledby="overviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="overviewModalLabel">Overview Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="overviewModalBody">
        <!-- Details will be loaded here -->
        <div class="text-center text-muted">Loading...</div>
      </div>
    </div>
  </div>
</div>

<script>
// Pass PHP data to JS for modal details (including unloading method arrays)
const overviewStats = {
  total: {
    label: "Total Deliveries Booked In",
    value: <?= json_encode($total_deliveries) ?>,
    icon: "inventory_2",
    description: "This is the total number of deliveries that have been booked into the system since launch."
  },
  today: {
    label: "Deliveries Today",
    value: <?= json_encode($today_deliveries) ?>,
    icon: "calendar_today",
    description: "Total deliveries booked in for today (<?= date('d/m/Y') ?>)."
  },
  week: {
    label: "Deliveries This Week",
    value: <?= json_encode($week_deliveries) ?>,
    icon: "calendar_view_week",
    description: "Total deliveries between <?= date('d/m/Y', strtotime($monday)) ?> and <?= date('d/m/Y', strtotime($sunday)) ?>."
  },
  next: {
    label: "Next Delivery Due",
    value: <?php if($next_delivery): ?>
      <?= json_encode(uk_time($next_delivery['due_datetime']).', '.uk_date($next_delivery['due_datetime'])) ?>
      <?php else: ?> null <?php endif; ?>,
    supplier: <?php if($next_delivery): ?><?= json_encode($next_delivery['supplier']) ?><?php else: ?>null<?php endif; ?>,
    material: <?php if($next_delivery): ?><?= json_encode($next_delivery['material']) ?><?php else: ?>null<?php endif; ?>,
    method: <?php if($next_delivery): ?><?= json_encode($next_delivery['unloading_method']) ?><?php else: ?>null<?php endif; ?>,
    icon: "schedule",
    description: <?php if($next_delivery): ?>
      <?= json_encode("The next booked delivery is from {$next_delivery['supplier']} (Material: {$next_delivery['material']}) using {$next_delivery['unloading_method']}.") ?>
      <?php else: ?>
      "There are no upcoming deliveries booked in."
      <?php endif; ?>
  },
  crane: {
    label: "Deliveries by Crane (This Week)",
    value: <?= json_encode($crane_count) ?>,
    icon: "precision_manufacturing",
    description: "Deliveries scheduled to be unloaded by crane this week.",
    deliveries: <?= json_encode(array_map(function($d){
        return [
            'supplier' => $d['supplier'],
            'material' => $d['material'],
            'quantity' => $d['quantity'],
            'due' => uk_datetime($d['due_datetime']),
            'status' => $d['status']
        ];
    }, $crane_deliveries_arr)) ?>
  },
  forklift: {
    label: "Deliveries by Forklift (This Week)",
    value: <?= json_encode($forklift_count) ?>,
    icon: "forklift",
    description: "Deliveries scheduled to be unloaded by forklift this week.",
    deliveries: <?= json_encode(array_map(function($d){
        return [
            'supplier' => $d['supplier'],
            'material' => $d['material'],
            'quantity' => $d['quantity'],
            'due' => uk_datetime($d['due_datetime']),
            'status' => $d['status']
        ];
    }, $forklift_deliveries_arr)) ?>
  },
  byhand: {
    label: "Deliveries by Hand (This Week)",
    value: <?= json_encode($byhand_count) ?>,
    icon: "transfer_within_a_station",
    description: "Deliveries scheduled to be unloaded by hand this week.",
    deliveries: <?= json_encode(array_map(function($d){
        return [
            'supplier' => $d['supplier'],
            'material' => $d['material'],
            'quantity' => $d['quantity'],
            'due' => uk_datetime($d['due_datetime']),
            'status' => $d['status']
        ];
    }, $byhand_deliveries_arr)) ?>
  }
};

// Listen for card click to show correct modal details
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.overview-card').forEach(function(card) {
    card.addEventListener('click', function() {
      const type = card.getAttribute('data-type');
      showOverviewModal(type);
    });
    // Allow keyboard access for accessibility
    card.addEventListener('keypress', function(e) {
      if (e.key === ' ' || e.key === 'Enter') {
        e.preventDefault();
        card.click();
      }
    });
  });
});

// Modern modal content fill
function showOverviewModal(type) {
  const stat = overviewStats[type];
  const modalLabel = document.getElementById('overviewModalLabel');
  const modalBody = document.getElementById('overviewModalBody');
  if (!stat) {
    modalLabel.innerText = "Overview Details";
    modalBody.innerHTML = "<div class='text-danger'>No details found.</div>";
    return;
  }
  // Start modal content
  let inner = `
    <div class="text-center mb-3">
      <span class="material-symbols-rounded mb-2" style="font-size:2.6em;color:#0d6efd;">${stat.icon}</span>
      <div class="h3 fw-bold mt-2">${stat.value !== null ? stat.value : '<span class="text-muted">N/A</span>'}</div>
      <div class="mb-2 text-muted">${stat.label}</div>
    </div>
    <div class="mb-2">${stat.description}</div>
  `;

  // Extra info for "next"
  if (type === "next" && stat.value && stat.supplier) {
    inner += `
      <ul class="list-group list-group-flush mb-2">
        <li class="list-group-item"><b>Supplier:</b> ${stat.supplier}</li>
        <li class="list-group-item"><b>Material:</b> ${stat.material}</li>
        <li class="list-group-item"><b>Unloading Method:</b> ${stat.method}</li>
        <li class="list-group-item"><b>Due:</b> ${stat.value}</li>
      </ul>
    `;
  }

  // For unloading method breakdowns, show a table of all matching deliveries (if any)
  if ((type === "crane" || type === "forklift" || type === "byhand") && Array.isArray(stat.deliveries)) {
    if (stat.deliveries.length > 0) {
      inner += `
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Supplier</th>
                <th>Material</th>
                <th>Qty</th>
                <th>Due</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
      `;
      stat.deliveries.forEach(function(del){
        inner += `<tr>
          <td>${del.supplier}</td>
          <td>${del.material}</td>
          <td>${del.quantity}</td>
          <td>${del.due}</td>
          <td>${del.status}</td>
        </tr>`;
      });
      inner += `</tbody></table></div>`;
    } else {
      inner += `<div class="text-muted text-center mt-2">No deliveries found for this unloading method this week.</div>`;
    }
  }

  modalLabel.innerText = stat.label;
  modalBody.innerHTML = inner;
}
</script>