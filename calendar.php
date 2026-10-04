<?php
/**
 * calendar.php
 * --------------------------------------------------------------------
 * Site Delivery Management System - Weekly Delivery Calendar
 * --------------------------------------------------------------------
 * - Displays a modern, responsive weekly delivery calendar.
 * - 7-day week (Mon-Sun), 20-min slots from 06:00–18:00.
 * - Users can click free slots to book a delivery (via modal form).
 * - Booked deliveries are shown with contractor and unloading icon (Material Symbols).
 * - Clicking a booked slot opens details in a modal.
 * - Deliveries can be drag-and-dropped to a new slot.
 * - All times in UK format (DD/MM/YYYY HH:mm).
 * - Includes navigation buttons for previous/next week, jump to week, and show all deliveries.
 * --------------------------------------------------------------------
 * Last updated: 19/06/2025 by irlam/copilot
 */
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Weekly Delivery Calendar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Material Symbols for icons -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded" rel="stylesheet" />
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f4f8fb;
            margin: 0;
            padding: 0;
        }
        .calendar-container {
            max-width: 1200px;
            margin: 32px auto 0 auto;
            background: #fff;
            border-radius: 13px;
            box-shadow: 0 4px 22px rgba(0, 0, 0, 0.09);
            padding: 32px 30px 40px 30px;
        }
        .calendar-container h2 {
            margin-bottom: 0.7em;
        }
        .calendar-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 1.2em;
            align-items: center;
            flex-wrap: wrap;
        }
        .calendar-nav button, .calendar-nav input[type="number"] {
            background: #f1f7fd;
            border: 1.5px solid #b6c9e6;
            border-radius: 7px;
            color: #24508c;
            font-weight: 500;
            font-size: 1em;
            padding: 0.33em 1.1em;
            cursor: pointer;
            transition: background 0.13s, border 0.13s;
        }
        .calendar-nav button.active, .calendar-nav button:focus {
            background: #1076f0;
            color: #fff;
            border-color: #0854a0;
        }
        .calendar-nav input[type="number"] {
            width: 75px;
            text-align: center;
        }
        .calendar-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1em;
            background: #f8fafc;
            border-radius: 11px;
            overflow: hidden;
            box-shadow: 0 2px 16px rgba(34, 55, 89, 0.07);
        }
        .calendar-table th, .calendar-table td {
            border: 1px solid #e3eefa;
            padding: 8px 10px;
            text-align: center;
        }
        .calendar-table th {
            background: #e3eefa;
            font-weight: 600;
        }
        .calendar-table td.time-col {
            background: #f2f5fa;
            font-weight: 600;
        }
        .calendar-table th.today {
            background: #b6d8fa;
            color: #004e99;
        }
        .calendar-table td.slot-free {
            background: #e9f7e5;
            cursor: pointer;
            transition: background 0.16s;
        }
        .calendar-table td.slot-free:hover {
            background: #c0f2b0;
        }
        .calendar-table td.slot-booked {
            background: #ffe6e6;
            color: #b32424;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.16s;
        }
        .calendar-table td.slot-booked:hover {
            background: #ffbdbd;
        }
        .calendar-table td.drag-over {
            outline: 2.3px dashed #1076f0;
            background: #e1f0ff !important;
        }
        .slot-icon {
            margin-right: 0.25em;
            vertical-align: middle;
        }
        .modal-bg {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0; width: 100vw; height: 100vh;
            background: rgba(34, 55, 89, 0.14);
            align-items: center;
            justify-content: center;
        }
        .modal {
            background: #fff;
            border-radius: 11px;
            box-shadow: 0 6px 24px rgba(0,0,0,0.13);
            padding: 32px 30px 24px 30px;
            max-width: 410px;
            min-width: 320px;
            margin: auto;
            position: relative;
            font-size: 1.08em;
            animation: fadeIn 0.2s;
        }
        .modal-close {
            position: absolute;
            top: 18px;
            right: 24px;
            font-size: 1.8em;
            color: #b6c9e6;
            cursor: pointer;
            transition: color 0.13s;
        }
        .modal-close:hover {
            color: #1076f0;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(25px);}
            to { opacity: 1; transform: translateY(0);}
        }
        @media (max-width: 700px) {
            .calendar-container { padding: 14px 2vw; }
            .modal { min-width: unset; max-width: 97vw; padding: 19px 2vw 16px 2vw; }
        }
    </style>
</head>
<body>
    <a href="index.php" style="display:inline-block;margin-bottom:8px;text-decoration:none;color:#2573d6;font-weight:500;">
        <span class="material-symbols-rounded align-bottom" style="font-size:1.15em;">arrow_back</span>
        Back to Main App
    </a>
    <div class="calendar-container">
        <h2>
            <span class="material-symbols-rounded align-text-bottom" style="font-size:1.35em;color:#0d6efd;">calendar_month</span>
            Weekly Delivery Calendar <span style="font-size:0.6em;color:#6a7892;">(20 min slots, UK time)</span>
        </h2>
        <!-- Navigation Buttons -->
        <div class="calendar-nav">
            <button id="prevWeekBtn" title="Show previous week"><span class="material-symbols-rounded align-bottom">arrow_back</span> Previous Week</button>
            <button id="todayBtn" title="Show this week">This Week</button>
            <button id="nextWeekBtn" title="Show next week">Next Week <span class="material-symbols-rounded align-bottom">arrow_forward</span></button>
            <span>
                Jump to week (ISO): 
                <input type="number" min="1" max="53" id="weekNumberInput" placeholder="Week #" style="width:60px;">
                <button id="jumpWeekBtn">Go</button>
            </span>
            <button id="showAllDeliveriesBtn" style="margin-left: 12px; background:#25b05b;color:#fff;">Show All Deliveries</button>
        </div>
        <div class="mb-3 d-flex flex-wrap align-items-center gap-4" style="font-size:1.05em;">
            <span><span class="material-symbols-rounded" style="font-size:1.2em;color:#0057b8;">precision_manufacturing</span> Crane</span>
            <span><span class="material-symbols-rounded" style="font-size:1.2em;color:#28a745;">forklift</span> Forklift</span>
            <span><span class="material-symbols-rounded" style="font-size:1.2em;color:#f8b400;">transfer_within_a_station</span> By hand</span>
        </div>
        <div id="calendar"></div>
    </div>
    <!-- Modal for booking or details -->
    <div class="modal-bg" id="modalBg">
        <div class="modal" id="modalContent">
            <!-- Content injected by JS -->
        </div>
    </div>

<script>
// UK date formatting helper
function formatUK(dt) {
    return dt.getDate().toString().padStart(2,'0') + '/' +
           (dt.getMonth()+1).toString().padStart(2,'0') + '/' +
           dt.getFullYear() + ' ' +
           dt.getHours().toString().padStart(2,'0') + ':' +
           dt.getMinutes().toString().padStart(2,'0');
}

// Generate 20-min slots from 06:00 to 18:00
function generateTimeSlots(start='06:00', end='18:00') {
    let slots = [];
    let [sh, sm] = start.split(':').map(Number);
    let [eh, em] = end.split(':').map(Number);
    let date = new Date(2000,0,1,sh,sm);
    let endDate = new Date(2000,0,1,eh,em);
    while (date <= endDate) {
        slots.push(date.getHours().toString().padStart(2,'0') + ':' +
                   date.getMinutes().toString().padStart(2,'0'));
        date.setMinutes(date.getMinutes() + 20);
    }
    return slots;
}

// Get Monday of a week (optionally with week offset)
function getMonday(d, weekOffset=0) {
    d = new Date(d);
    let day = d.getDay(), diff = d.getDate() - day + (day === 0 ? -6:1);
    let monday = new Date(d.setDate(diff));
    monday.setHours(0,0,0,0);
    if (weekOffset !== 0) {
        monday.setDate(monday.getDate() + (weekOffset * 7));
    }
    return monday;
}

// Get the Material Symbols icon for unloading method
function getUnloadingIcon(method) {
    switch ((method || '').toLowerCase()) {
        case "crane":    return '<span class="material-symbols-rounded slot-icon" style="color:#0057b8;">precision_manufacturing</span>';
        case "forklift": return '<span class="material-symbols-rounded slot-icon" style="color:#28a745;">forklift</span>';
        case "by hand":  return '<span class="material-symbols-rounded slot-icon" style="color:#f8b400;">transfer_within_a_station</span>';
        default:         return '<span class="material-symbols-rounded slot-icon" style="color:#a3adb8;">help</span>';
    }
}

// Modal helpers
function showModal(html) {
    document.getElementById('modalContent').innerHTML = html;
    document.getElementById('modalBg').style.display = 'flex';
}
function closeModal() {
    document.getElementById('modalBg').style.display = 'none';
}
document.getElementById('modalBg').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// --- CALENDAR WEEK NAVIGATION ---
let calendarWeekOffset = 0; // 0 = this week; +1 = next week, -1 = previous, etc.

document.getElementById('prevWeekBtn').onclick = function() {
    calendarWeekOffset--;
    renderCalendar();
};
document.getElementById('nextWeekBtn').onclick = function() {
    calendarWeekOffset++;
    renderCalendar();
};
document.getElementById('todayBtn').onclick = function() {
    calendarWeekOffset = 0;
    renderCalendar();
};
document.getElementById('jumpWeekBtn').onclick = function() {
    let weekNo = parseInt(document.getElementById('weekNumberInput').value);
    if (weekNo >= 1 && weekNo <= 53) {
        // Find the first Monday of the current year, then add (weekNo-1)*7 days
        let year = (new Date()).getFullYear();
        let jan1 = new Date(year, 0, 1);
        let day = jan1.getDay(), firstMondayOffset = (day === 0 ? 1 : (8 - day));
        let firstMonday = new Date(year, 0, 1 + firstMondayOffset - 1);
        let monday = new Date(firstMonday);
        monday.setDate(monday.getDate() + (weekNo - 1) * 7);
        let nowMonday = getMonday(new Date());
        calendarWeekOffset = Math.round((monday - nowMonday) / (7 * 24 * 60 * 60 * 1000));
        renderCalendar();
    }
};

// --- Show All Deliveries Button (implement as needed) ---
document.getElementById('showAllDeliveriesBtn').onclick = function() {
    window.location.href = 'all_deliveries.php'; // Or your actual "all deliveries" page
};

// Main calendar rendering
async function renderCalendar() {
    const calendarDiv = document.getElementById('calendar');
    const timeSlots = generateTimeSlots();
    const today = new Date();
    const monday = getMonday(today, calendarWeekOffset);
    let days = [];
    for (let i=0;i<7;i++) {
        let d = new Date(monday);
        d.setDate(d.getDate()+i);
        days.push(d);
    }
    // Fetch deliveries for the displayed week (pass Monday YYYY-MM-DD for week filtering)
    let deliveries = [];
    try {
        let formattedMonday = monday.getFullYear()+'-'+(monday.getMonth()+1).toString().padStart(2,'0')+'-'+monday.getDate().toString().padStart(2,'0');
        let res = await fetch('get_week_deliveries.php?week='+formattedMonday);
        deliveries = await res.json();
    } catch(e) { deliveries = []; }
    // Map deliveries to slot hash (YYYY-MM-DD HH:MM)
    let slotMap = {};
    deliveries.forEach(d => {
        let dt = new Date(d.due_datetime.replace(' ','T'));
        let slotKey = dt.getFullYear()+'-'+(dt.getMonth()+1).toString().padStart(2,'0')+'-'+dt.getDate().toString().padStart(2,'0')
            +' '+dt.getHours().toString().padStart(2,'0')+':'+dt.getMinutes().toString().padStart(2,'0');
        slotMap[slotKey] = d;
    });
    // Build table
    let html = '<div class="table-responsive"><table class="calendar-table"><thead><tr><th>Time</th>';
    days.forEach((d,i)=>{
        let isToday = (calendarWeekOffset === 0 && d.toDateString() == (new Date()).toDateString());
        html+='<th'+(isToday?' class="today"':'')+'>'
            +['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][i]+'<br>'+formatUK(d).slice(0,10)
            +'</th>';
    });
    html += '</tr></thead><tbody>';
    for (let s of timeSlots) {
        html += '<tr>';
        html += '<td class="time-col">'+s+'</td>';
        for (let i=0;i<7;i++) {
            let d = new Date(monday);
            d.setDate(d.getDate()+i);
            let slotKey = d.getFullYear()+'-'+(d.getMonth()+1).toString().padStart(2,'0')+'-'+d.getDate().toString().padStart(2,'0')+' '+s;
            let cellId = 'cell-'+slotKey.replace(/[^a-zA-Z0-9]/g,'');
            if (slotMap[slotKey]) {
                let delivery = slotMap[slotKey];
                let icon = getUnloadingIcon(delivery.unloading_method);
                html += `<td class="slot-booked" id="${cellId}" 
                    draggable="true"
                    data-delivery-id="${delivery.id}"
                    data-slot-key="${slotKey}"
                    title="Click for details or drag to move"
                    >${icon}<span class="d-none d-md-inline">${delivery.contractor ? delivery.contractor : delivery.supplier}</span>
                </td>`;
            } else {
                html += `<td class="slot-free" id="${cellId}" 
                    data-slot-key="${slotKey}" 
                    title="Click to book this slot">
                    <span class="slot-icon" style="color:#25b05b;"><span class="material-symbols-rounded">add_circle</span></span>
                </td>`;
            }
        }
        html += '</tr>';
    }
    html += '</tbody></table></div>';
    calendarDiv.innerHTML = html;

    // --- Event Handlers for slots ---
    // 1. Click free slot to book
    document.querySelectorAll('.slot-free').forEach(td=>{
        td.addEventListener('click', function() {
            let slotKey = this.getAttribute('data-slot-key');
            showBookingForm(slotKey);
        });
        // Drag-over handlers
        td.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('drag-over'); });
        td.addEventListener('dragleave', function(e){ this.classList.remove('drag-over'); });
        td.addEventListener('drop', function(e) {
            this.classList.remove('drag-over');
            let deliveryId = e.dataTransfer.getData('delivery-id');
            let newSlot = this.getAttribute('data-slot-key');
            if (deliveryId) moveDelivery(deliveryId, newSlot);
        });
    });
    // 2. Click booked slot for details
    document.querySelectorAll('.slot-booked').forEach(td=>{
        td.addEventListener('click', function() {
            let deliveryId = this.getAttribute('data-delivery-id');
            showDeliveryDetails(deliveryId);
        });
        // Drag handlers
        td.addEventListener('dragstart', function(e) {
            e.dataTransfer.setData('delivery-id', this.getAttribute('data-delivery-id'));
        });
        td.addEventListener('dragover', function(e){ e.preventDefault(); this.classList.add('drag-over'); });
        td.addEventListener('dragleave', function(e){ this.classList.remove('drag-over'); });
        td.addEventListener('drop', function(e) { this.classList.remove('drag-over'); });
    });
}

// Show modal booking form for a slot
function showBookingForm(slotKey) {
    // slotKey: YYYY-MM-DD HH:MM
    let [date, time] = slotKey.split(' ');
    let html = `<span class="modal-close" onclick="closeModal()">&times;</span>
        <h3 style="margin-bottom:0.7em;">
            <span class="material-symbols-rounded align-bottom" style="color:#25b05b;font-size:1.4em;">add_circle</span>
            Book Delivery: <span class="text-muted" style="font-size:0.8em;">${slotKey}</span>
        </h3>
        <form id="bookForm" style="display:grid;gap:0.7em;">
            <input type="hidden" name="due_datetime" value="${date}T${time}">
            <label>Your Name <input type="text" name="user_name" required class="form-control"></label>
            <label>Contractor <input type="text" name="supplier" required class="form-control"></label>
            <label>Material <input type="text" name="material" required class="form-control"></label>
            <label>Quantity <input type="text" name="quantity" required class="form-control"></label>
            <label>
                Unloading Method
                <select name="unloading_method" required class="form-select">
                    <option value="">Select...</option>
                    <option value="Crane">Crane</option>
                    <option value="Forklift">Forklift</option>
                    <option value="By hand">By hand</option>
                </select>
            </label>
            <button type="submit" style="margin-top:5px;background:#1076f0;color:#fff;font-weight:500;border:none;padding:0.5em 1.2em;border-radius:6px;cursor:pointer;">
                Book Slot
            </button>
        </form>
        <div id="bookMsg" style="margin-top:0.6em;"></div>
    `;
    showModal(html);
    document.getElementById('bookForm').addEventListener('submit', async function(e){
        e.preventDefault();
        let fd = new FormData(this);
        let res = await fetch('book_delivery.php', {method:'POST', body:fd});
        let txt = await res.text();
        if (/success/i.test(txt)) {
            document.getElementById('bookMsg').innerHTML = '<span style="color:green">Booked!</span>';
            setTimeout(()=>{ closeModal(); renderCalendar(); }, 1000);
        } else {
            document.getElementById('bookMsg').innerHTML = '<span style="color:red">'+txt+'</span>';
        }
    });
}

// AJAX: Show delivery details in modal
async function showDeliveryDetails(deliveryId) {
    let res = await fetch('get_delivery_details.php?id='+deliveryId);
    let d = await res.json();
    if (!d) return;
    let icon = getUnloadingIcon(d.unloading_method);
    let html = `<span class="modal-close" onclick="closeModal()">&times;</span>
        <h3 style="margin-bottom:0.7em;">
            ${icon} Delivery Details
        </h3>
        <div style="display:grid;gap:0.4em;font-size:1.07em;">
            <div><b>Contractor:</b> ${d.contractor ? d.contractor : d.supplier}</div>
            <div><b>Booked By:</b> ${d.user_name ? d.user_name : '<span style="color:#888;">Not recorded</span>'}</div>
            <div><b>Material:</b> ${d.material}</div>
            <div><b>Quantity:</b> ${d.quantity}</div>
            <div><b>Due Date/Time:</b> ${formatUK(new Date(d.due_datetime.replace(' ','T')))}</div>
            <div><b>Unloading Method:</b> ${d.unloading_method}</div>
            <div><b>Status:</b> ${d.status}</div>
        </div>
        <button onclick="closeModal()" style="margin-top:1em;background:#1076f0;color:#fff;font-weight:500;border:none;padding:0.5em 1.2em;border-radius:6px;cursor:pointer;">
            Close
        </button>
    `;
    showModal(html);
}

// AJAX: Move delivery to new slot
async function moveDelivery(deliveryId, newSlot) {
    if (!confirm('Move delivery to '+newSlot+'?')) return;
    let fd = new FormData();
    fd.append('id', deliveryId);
    fd.append('new_slot', newSlot);
    let res = await fetch('move_delivery.php', {method:'POST', body:fd});
    let txt = await res.text();
    if (/success/i.test(txt)) {
        renderCalendar();
        closeModal();
    } else {
        alert('Move failed: '+txt);
    }
}

// Initial render
renderCalendar();
</script>
</body>
</html>