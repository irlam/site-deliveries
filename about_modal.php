<?php
/**
 * about_modal.php
 * --------------------------------------------------------------
 * Deliveries – About / Help (showcase-style with moving background)
 * --------------------------------------------------------------
 * Include from index.php:
 *   <?php include 'about_modal.php'; ?>
 * --------------------------------------------------------------
 * Last updated: 20/10/2025
 */
?>
<div class="modal fade" id="aboutModal" tabindex="-1" aria-labelledby="aboutModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content about-shell">
      <style>
        /* ---- Palette (match showcase.php) ---- */
        #aboutModal:root {}
        #aboutModal .about-shell{
          position:relative; overflow:hidden; color:#E5E7EB;
          background:#0b1220;
          border:1px solid rgba(255,255,255,.08);
          border-radius:16px;
        }
        /* Radial background + spinning conic glow (like showcase hero) */
        #aboutModal .about-shell::before{
          content:""; position:absolute; inset:-40% -10% auto -10%; height:80%;
          background: conic-gradient(from 180deg at 50% 50%,
                      #0a84ff, #22d3ee, #a78bfa, #0a84ff);
          opacity:.25; filter: blur(60px);
          animation: about-spin 18s linear infinite;
          pointer-events:none;
        }
        #aboutModal .about-shell::after{
          content:""; position:absolute; inset:0;
          background:
            radial-gradient(1000px 600px at 10% -10%, rgba(10,132,255,.10), transparent),
            radial-gradient(800px 500px  at 90% -20%, rgba(167,139,250,.10), transparent);
          pointer-events:none;
        }
        @keyframes about-spin { to { transform: rotate(360deg);} }
        @media (prefers-reduced-motion: reduce){
          #aboutModal .about-shell::before{ animation:none; }
        }

        /* Glass cards & accents */
        #aboutModal .glass{ background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius:14px; }
        #aboutModal .badge-soft{ background: rgba(34,211,238,.12); border:1px solid rgba(34,211,238,.25); color:#BAF1F8; }
        #aboutModal .feature-icon{
          width:42px;height:42px;border-radius:10px;display:grid;place-items:center;
          background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08); margin-right:.6rem;
        }
        #aboutModal .divider{ height:1px; background:linear-gradient(90deg,transparent,rgba(255,255,255,.18),transparent); margin:16px 0; }
        #aboutModal .text-secondary{ color:#A8B0BD !important; }
        #aboutModal .btn-brand{ background: linear-gradient(90deg,#0A84FF,#22D3EE); border:0; color:#001018; font-weight:700; }
        #aboutModal a{ text-decoration:none; }

        /* Header strip */
        #aboutModal .about-hero{
          position:relative; z-index:1;
          padding:22px 24px;
          border-bottom:1px solid rgba(255,255,255,.08);
          background: transparent; /* bg comes from ::after on shell */
        }

        /* Body area */
        #aboutModal .modal-body{ position:relative; z-index:1; max-height:70vh; overflow:auto; padding:24px; }
        #aboutModal .modal-footer{
          position:relative; z-index:1;
          background:rgba(255,255,255,.04);
          border-top:1px solid rgba(255,255,255,.08);
        }

        /* Simple list spacing */
        #aboutModal .klist{ margin:0; padding-left:1.1rem; }
        #aboutModal .klist li{ margin:.35rem 0; }
      </style>

      <!-- HERO / HEADER -->
      <div class="about-hero">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
          <div>
            <span class="badge badge-soft">Built for real sites, by real people</span>
            <h5 class="mt-2 mb-0" id="aboutModalLabel">About this Deliveries system</h5>
            <div class="text-secondary small mt-1">
              Book slots, control arrivals with QR, attach RAMS/PO/Delivery notes, and keep the day visible to everyone.
            </div>
          </div>
          <div class="d-flex gap-2">
            <a href="showcase.php" target="_blank" rel="noopener" class="btn btn-outline-light">Open Showcase</a>
            <a href="index.php" class="btn btn-brand">Open Calendar</a>
          </div>
        </div>
      </div>

      <!-- BODY -->
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="glass p-3 h-100">
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">🗓️</div>
                <h6 class="mb-0">Calendar & multi-slot booking</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Clear week view with configurable start/end time and slot interval.</li>
                <li>Select contiguous slots; snap helper prevents gaps.</li>
                <li><b>Per-day notes</b> banner and optional weather header.</li>
                <li>Off-grid requests flagged with ⚠️ and grouped to nearest cell.</li>
              </ul>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="glass p-3 h-100">
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">🚪</div>
                <h6 class="mb-0">Gate & QR workflow</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Each booking has a deep link and a scannable <b>Gate QR</b>.</li>
                <li>“Arrived / Completed” flow on one screen (ideal for the cabin).</li>
                <li>Optional TV <b>Gateboard</b> mode with auto-refresh and idle clock.</li>
              </ul>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="glass p-3 h-100">
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">📎</div>
                <h6 class="mb-0">Attachments</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Attach RAMS, PO, and delivery notes to each booking.</li>
                <li>Preview PDFs/images in-app so nothing is lost in email.</li>
              </ul>
              <div class="divider"></div>
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">🛠️</div>
                <h6 class="mb-0">Blackouts & rules</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Quiet hours, crane lifts, slot caps per trade, buffer time.</li>
              </ul>
            </div>
          </div>

          <div class="col-lg-6">
            <div class="glass p-3 h-100">
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">📊</div>
                <h6 class="mb-0">Reports & exports</h6>
              </div>
              <ul class="klist text-secondary">
                <li>CSV/PDF exports: gate throughput, on-time %, dwell times, top trades.</li>
                <li>Printable daily run sheet and weekly calendar.</li>
              </ul>
              <div class="divider"></div>
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">🔐</div>
                <h6 class="mb-0">Roles & audit</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Admin / Gate / Trade permissions.</li>
                <li>Audit trail: who changed what & when.</li>
              </ul>
            </div>
          </div>
        </div>

        <div class="row g-3 mt-1">
          <div class="col-lg-12">
            <div class="glass p-3">
              <div class="d-flex align-items-center mb-2">
                <div class="feature-icon">💡</div>
                <h6 class="mb-0">How to use (quick tips)</h6>
              </div>
              <ul class="klist text-secondary">
                <li>Navigate with <b>Previous/This/Next Week</b>. Click any empty cell to book.</li>
                <li>Click a booked cell to open details. Admins can <b>Edit</b>, <b>Reschedule</b>, or <b>Cancel</b>.</li>
                <li>Non-admins can <b>Request a change</b> (time, cancel, edit details) from the details modal.</li>
                <li>Admins: configure time window, interval, weather and rules in the dashboard.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>

      <!-- FOOTER -->
      <div class="modal-footer">
        <span class="text-secondary me-auto small">© DefectTracker — Deliveries. Built for real sites, by real people.</span>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <a href="showcase.php" target="_blank" rel="noopener" class="btn btn-brand">Open Showcase</a>
      </div>
    </div>
  </div>
</div>
