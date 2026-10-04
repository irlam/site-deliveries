<?php
/**
 * /showcase.php
 * --------------------------------------------------------------
 * DefectTracker Deliveries — Sales & Demo Page (Public)
 * A single page you can open in meetings to pitch the system.
 * - Zero login required
 * - Hero, problem→solution, features, ROI calc, testimonials,
 *   architecture trust, and live demo CTA.
 * --------------------------------------------------------------
 * Last updated: 19/10/2025
 */
?>
<!doctype html>
<html lang="en">
<head>
<link rel="apple-touch-icon" href="/assets/brand/icon-180.png">
<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" type="image/svg+xml" href="/assets/brand/logo.svg">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/brand/icon-32.png">

  <meta charset="utf-8">
  <title>DefectTracker Deliveries — Built for Real Sites</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Modern deliveries scheduling for construction sites: slot booking, QR gate, attachments, weather, exports, and live gateboard.">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="icon" href="/icons/defecttracker-favicon.png">
  <style>
    :root{
      --brand:#0A84FF;
      --ink:#111827;
      --muted:#6B7280;
      --bg:#0b1220;
      --card:#111827;
      --grad1:#0a84ff; --grad2:#22d3ee; --grad3:#a78bfa;
    }
    body{ background: radial-gradient(1000px 600px at 10% -10%, rgba(10,132,255,.10), transparent),
                    radial-gradient(800px 500px at 90% -20%, rgba(167,139,250,.10), transparent),
                    #0b1220; color: #E5E7EB; }
    .navbar{ background: rgba(17,24,39,.6); backdrop-filter: blur(6px); }
    .hero{
      position: relative; overflow: hidden; border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .hero:before{
      content:""; position:absolute; inset:-40% -10% auto -10%; height:80%;
      background: conic-gradient(from 180deg at 50% 50%, var(--grad1), var(--grad2), var(--grad3), var(--grad1));
      opacity:.25; filter: blur(60px);
      animation: spin 18s linear infinite;
    }
    @keyframes spin{ to{ transform: rotate(360deg);} }
    .glass{ background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); border-radius: 16px; }
    .btn-brand{ background: linear-gradient(90deg,var(--grad1),var(--grad2)); border:0; color:#001018; font-weight:700; }
    .btn-outline-brand{ border:1px solid rgba(255,255,255,.2); color:#E5E7EB;}
    .badge-soft{ background: rgba(34,211,238,.12); border:1px solid rgba(34,211,238,.25); color:#BAF1F8; }
    .feature-icon{
      width:48px;height:48px;border-radius:12px;display:grid;place-items:center;
      background: rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08);
    }
    .tick::before{ content:"✔"; margin-right:.5rem; color:#86efac; }
    .x::before{ content:"✖"; margin-right:.5rem; color:#fca5a5; }
    .shadow-soft{ box-shadow: 0 10px 40px rgba(2,6,23,.35);}
    .kpi{
      border-radius: 16px; padding: 18px; border:1px solid rgba(255,255,255,.08);
      background: linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
    }
    .divider{ height:1px; background: linear-gradient(90deg, transparent, rgba(255,255,255,.18), transparent); }
    .logo-mark{
      width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,var(--grad1),var(--grad3));
      display:inline-block;margin-right:.5rem; vertical-align:middle;
    }
    a { text-decoration: none; }
    .foot{ color:#9CA3AF; font-size:.9rem;}
    .demo-iframe{ border:1px solid rgba(255,255,255,.12); border-radius:14px; overflow:hidden; }
    /* ROI slider */
    input[type=range]{ width:100%; background:transparent; }
    .range-wrap{ position:relative; }
    .range-bubble{ position:absolute; top:-44px; padding:4px 10px; background:#111827; border:1px solid rgba(255,255,255,.15); border-radius:12px; font-size:.85rem; }
  </style>
</head>
<body>
  <nav class="navbar navbar-dark sticky-top">
    <div class="container py-2">
      <div>
        <span class="logo-mark"></span>
        <strong>DefectTracker Deliveries</strong>
      </div>
      <div class="d-flex gap-2">
        <a class="btn btn-outline-brand btn-sm" href="index.php">Open Live Demo</a>
        <a class="btn btn-brand btn-sm" href="#contact">Book a 15-min Call</a>
      </div>
    </div>
  </nav>

  <!-- HERO -->
  <header class="hero">
    <div class="container py-5 py-md-6">
      <div class="row align-items-center g-4">
        <div class="col-lg-6">
          <span class="badge badge-soft mb-3">Built for real sites, by real people</span>
          <h1 class="display-5 fw-bold">A smarter way to run your site gate, <span style="background:linear-gradient(90deg,var(--grad1),var(--grad3));-webkit-background-clip:text;background-clip:text;color:transparent;">every day</span>.</h1>
          <p class="lead text-secondary mt-3">Book slots in seconds, control arrivals with QR, keep RAMS & POs attached, and see the day at a glance—with an auto-refresh <em>Gateboard</em> your team will actually use.</p>
          <div class="d-flex gap-3 mt-4">
            <a class="btn btn-brand btn-lg shadow-soft" href="index.php">Try the Live Calendar</a>
            <a class="btn btn-outline-brand btn-lg" href="#features">See Features</a>
          </div>
          <div class="row mt-4 g-3">
            <div class="col-6 col-md-4">
              <div class="kpi text-center">
                <div class="fs-3 fw-bold">70%</div>
                <div class="text-secondary">Fewer gate calls</div>
              </div>
            </div>
            <div class="col-6 col-md-4">
              <div class="kpi text-center">
                <div class="fs-3 fw-bold">+2 hrs</div>
                <div class="text-secondary">Planner time saved / wk</div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="kpi text-center">
                <div class="fs-3 fw-bold">100%</div>
                <div class="text-secondary">RAMS captured</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="glass p-2 demo-iframe shadow-soft">
            <iframe src="index.php" title="Live Demo" loading="lazy" style="width:100%;height:420px;background:#0b1220;"></iframe>
          </div>
          <div class="text-secondary small mt-2">Embedded demo shows your live calendar UI. (Private data stays behind login.)</div>
        </div>
      </div>
    </div>
  </header>

  <!-- PROBLEM → SOLUTION -->
  <section class="container py-5" id="why">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="fw-bold">Why sites struggle</h2>
        <ul class="mt-3 list-unstyled">
          <li class="x">Phone & WhatsApp chaos — nothing is visible.</li>
          <li class="x">Clashes at the gate; drivers waiting, trades arguing.</li>
          <li class="x">RAMS / POs get lost in inboxes.</li>
          <li class="x">No data: can’t prove delays or show improvements.</li>
        </ul>
      </div>
      <div class="col-lg-6">
        <h2 class="fw-bold">What Deliveries fixes</h2>
        <ul class="mt-3 list-unstyled">
          <li class="tick">One calendar for the whole site; book multi-slots in seconds.</li>
          <li class="tick">Gate QR for arrivals; “Today’s Deliveries” board for the cabin.</li>
          <li class="tick">Attachments with each booking: RAMS, PO, Delivery Note.</li>
          <li class="tick">Exports & metrics prove performance and unblock disputes.</li>
        </ul>
      </div>
    </div>
  </section>

  <div class="divider my-4"></div>

  <!-- FEATURES GRID -->
  <section class="container py-5" id="features">
    <div class="row g-4">
      <?php
        $features = [
          ["Calendar & Multi-slot", "Click to select contiguous slots; snap helper prevents gaps."],
          ["Gate QR & Deep Links", "Driver shows QR; guard taps ‘Arrived/Completed’ on one screen."],
          ["Attachments", "RAMS / PO / Delivery Note preview in-app; no email hunting."],
          ["Blackouts & Rules", "Block crane lifts, quiet hours; cap per-trade per-day; buffer times."],
          ["Weather Header", "Optional Open-Meteo header to plan around wind/rain."],
          ["Per-Day Notes", "Banner notes for the day; admin inline edit with history."],
          ["Exports & Reports", "CSV/PDF: gate throughput, on-time %, dwell times, top trades."],
          ["PWA & Offline", "Install to tablet; Gateboard keeps working if Wi-Fi blips."],
          ["Audit & Roles", "Who changed what/when; Admin / Gate / Trade permissions."],
          ["API Friendly", "Lightweight JSON endpoints for BI or integrations."],
          ["Theming & Logos", "Project branding; sponsor logos for directors’ demos."],
          ["Secure by Default", "CSP, no composer required, PHP 8.x, hardened uploads."],
        ];
        foreach($features as $f): ?>
        <div class="col-md-6 col-lg-4">
          <div class="glass p-4 h-100">
            <div class="feature-icon mb-3">🧩</div>
            <h5 class="mb-2"><?= htmlspecialchars($f[0]) ?></h5>
            <p class="text-secondary mb-0"><?= htmlspecialchars($f[1]) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ROI CALCULATOR -->
  <section class="container py-5" id="roi">
    <div class="row g-4 align-items-center">
      <div class="col-lg-6">
        <h2 class="fw-bold">Your ROI in minutes</h2>
        <p class="text-secondary">Slide the team size and we’ll estimate weekly time saved vs. phone-based booking. Use this in the meeting—numbers stick.</p>
        <div class="glass p-4">
          <div class="mb-3">
            <label class="form-label">Gate/Logistics team size</label>
            <div class="range-wrap">
              <input id="team" type="range" min="1" max="8" step="1" value="3">
              <div id="bubble" class="range-bubble">3</div>
            </div>
          </div>
          <div class="row text-center">
            <div class="col-4">
              <div class="text-secondary">Hours saved / wk</div>
              <div id="hours" class="fs-3 fw-bold">6</div>
            </div>
            <div class="col-4">
              <div class="text-secondary">Fewer gate calls</div>
              <div id="calls" class="fs-3 fw-bold">70%</div>
            </div>
            <div class="col-4">
              <div class="text-secondary">Payback</div>
              <div id="payback" class="fs-3 fw-bold">&lt; 4 wks</div>
            </div>
          </div>
          <div class="small text-secondary mt-2">Assumes ~2 hrs/wk saved per team member + reduced interruptions.</div>
        </div>
      </div>
      <div class="col-lg-6">
        <h2 class="fw-bold">Trusted, portable, self-contained</h2>
        <div class="glass p-4">
          <ul class="list-unstyled m-0">
            <li class="tick">PHP 8.x + MySQL, no Composer required on server.</li>
            <li class="tick">Configurable via `app_settings` (lat/lon/weather/key/slot rules).</li>
            <li class="tick">Clean exports (CSV/PDF) for board or client reports.</li>
            <li class="tick">Icons & QR served by `/icon.php` (fast and cacheable).</li>
            <li class="tick">Deployed today on standard Plesk hosting.</li>
          </ul>
        </div>
      </div>
    </div>
  </section>

  <div class="divider my-4"></div>

  <!-- TESTIMONIALS -->
  <section class="container py-5" id="testimonials">
    <h2 class="fw-bold mb-4">What site teams say</h2>
    <div class="row g-4">
      <div class="col-md-4">
        <div class="glass p-4 h-100">
          <p class="mb-3">“We stopped the 8am scrum at the gate. Everyone can see the plan—no arguments.”</p>
          <div class="text-secondary small">Site Manager, Major Res Scheme</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="glass p-4 h-100">
          <p class="mb-3">“RAMS on the booking means no last-minute hunts. Our H&S lead loves it.”</p>
          <div class="text-secondary small">Logistics Lead, City Centre Project</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="glass p-4 h-100">
          <p class="mb-3">“The TV Gateboard is perfect for the cabin. It just ticks over all day.”</p>
          <div class="text-secondary small">Gate Operative</div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="container py-5" id="contact">
    <div class="glass p-4 p-md-5 text-center shadow-soft">
      <h2 class="fw-bold mb-3">Ready to try it on your project?</h2>
      <p class="text-secondary mb-4">We’ll brand it to your job, import your trades, and set rules & blackout windows. You’ll have a live Gateboard this week.</p>
      <div class="d-flex justify-content-center gap-3">
        <a href="index.php" class="btn btn-brand btn-lg">Open the Demo</a>
        <a href="mailto:clean-up.notice@defecttracker.uk?subject=Deliveries%20Demo" class="btn btn-outline-brand btn-lg">Email Us</a>
      </div>
    </div>
  </section>

  <footer class="container py-4 foot">
    © DefectTracker. Built for real sites, by real people.
  </footer>

  <script>
    // ROI slider bubble & quick calc
    (function(){
      const team = document.getElementById('team');
      const bubble = document.getElementById('bubble');
      const hours = document.getElementById('hours');
      const calls = document.getElementById('calls');
      const payback = document.getElementById('payback');
      function setBubble(){
        const val = team.value;
        bubble.textContent = val;
        const pct = (val - team.min) / (team.max - team.min);
        const left = pct * (team.offsetWidth - bubble.offsetWidth) + team.offsetLeft;
        bubble.style.left = left + 'px';
        // simple model: 2 hrs/week saved per person
        const hrs = val * 2;
        hours.textContent = hrs.toString();
        // calls reduction stays at 70% headline
        calls.textContent = "70%";
        // rough payback: assume licence/equip cost recouped < 1 mo with 6+ hrs/wk
        payback.textContent = (hrs >= 6) ? "< 4 wks" : "≈ 1–2 mo";
      }
      team.addEventListener('input', setBubble);
      window.addEventListener('resize', setBubble);
      setTimeout(setBubble, 50);
    })();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
