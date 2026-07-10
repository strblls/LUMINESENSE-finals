<!-- TOPBAR -->
<?php
/** @var string $faculty_name */
/** @var string $faculty_email */
/** @var string $initials */
/** @var string $first_name */

// Check if the session variable is set and true
$is_head = $_SESSION['is_head'] ?? false;

// Initialize current schedule with default value if not set
$current_sched ??= 'No class right now';

// ── Has PIN set? ──
$has_pin = false;
if (isset($conn) && $conn instanceof mysqli) {
    $faculty_id_topbar = (int)($_SESSION['faculty_id'] ?? 0);
    if ($faculty_id_topbar) {
        $stmt = $conn->prepare("SELECT 1 FROM faculty_permissions WHERE faculty_id = ? AND pin_hash IS NOT NULL");
        $stmt->bind_param('i', $faculty_id_topbar);
        $stmt->execute();
        $stmt->bind_result($dummy);
        $has_pin = (bool)$stmt->fetch();
        $stmt->close();
    }
}
?>

<link rel="stylesheet" href="../../css/faculty-settings.css">

<div class="topbar d-flex">
    <button type="button" id="sidebarTrigger">
        <i class="bi bi-list"></i>
    </button>
    <div class="col d-flex flex-column px-3 topbar-greeting">
        <h1 class="bold">Welcome, <?= $first_name ?>!</h1>
        <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
        <div class="d-flex flex-column align-items-end topbar-user-info">
            <h4 class="mb-0"><?= htmlspecialchars($faculty_name) ?></h4>
            <?php if ($is_head): ?>
                <span class="bold status-badge faculty-head">Faculty Head</span>
            <?php else: ?>
                <span class="bold status-badge faculty-member">Faculty Member</span>
            <?php endif; ?>
        </div>
        <a href="faculty-profile-settings.php" class="avatar-icon d-flex align-items-center justify-content-center"
            style="text-decoration: none;">
            <h3 class="bold"><?= $initials ?></h3>
        </a>
        <button class="light w-auto" onclick="dissolve('../../php/logout.php')">Logout</button>
    </div>
</div>

<!-- Hidden schedule end data for audio notifications -->
<div id="scheduleEndData" data-end="<?= htmlspecialchars($active_schedule_end ?? '') ?>" style="display:none;"></div>

<!-- Audio notification toast -->
<div class="notif-toast-wrap" id="notificationToastWrap">
    <div class="notif-toast-msg" id="notificationToastMsg">
        <div class="notif-toast-header">
            <i class="bi bi-clock-fill"></i>
            <span id="notifTimeLabel">Time Remaining</span>
        </div>
        <div class="notif-toast-body" id="notifTimeMessage">5 minutes remaining in your class.</div>
    </div>
</div>

<!-- ══════════════════════════════
     PAGE TIMEOUT OVERLAY (1 min inactivity)
══════════════════════════════ -->
<div id="pageTimeoutOverlay" class="page-timeout-overlay" style="display:none;">
    <div class="page-timeout-modal">
        <i class="bi bi-clock-history" style="font-size:2.5rem;color:var(--secondary-color-4);margin-bottom:0.75rem;"></i>
        <h5 class="schedule-ended-title">Session Timeout</h5>
        <p class="schedule-ended-text">Enter your PIN to continue using controls.</p>
        <div class="mt-3 d-flex flex-column align-items-center gap-2">
            <input type="password" id="timeoutPinInput" maxlength="4" pattern="\d*" inputmode="numeric"
                   class="form-control text-center" style="width:140px;font-size:1.5rem;letter-spacing:4px;" placeholder="••••">
            <div><span id="timeoutPinError" class="text-danger small"></span></div>
            <button class="light" id="timeoutPinSubmit">Unlock</button>
        </div>
    </div>
</div>

<script src="../../script/faculty-notification.js"></script>

<style>
.topbar-greeting,
.topbar-user-info {
    transition: opacity 0.3s ease;
}
.topbar-greeting.hidden,
.topbar-user-info.hidden {
    opacity: 0;
    pointer-events: none;
}
</style>

<script>
// ── PIN / timeout globals ───────────────────────────────────────
var HAS_PIN = <?= json_encode((bool)$has_pin) ?>;
var PIN_VERIFIED = false;
var _timeoutTimer = null;

// ── Verify PIN via API ──────────────────────────────────────────
async function verifyPin(pin) {
    var r = await fetch('../../api/pin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'verify', pin: pin})
    });
    return await r.json();
}

// ── Page timeout (1 min inactivity) ─────────────────────────────
function resetPageTimeout() {
    if (_timeoutTimer) clearTimeout(_timeoutTimer);
    _timeoutTimer = setTimeout(showPageTimeout, 60000);
}

function showPageTimeout() {
    var ov = document.getElementById('pageTimeoutOverlay');
    if (ov) ov.style.display = 'flex';
    PIN_VERIFIED = false;
    if (typeof showPinOverlays === 'function') showPinOverlays();
}

function hidePageTimeout() {
    var ov = document.getElementById('pageTimeoutOverlay');
    if (ov) ov.style.display = 'none';
    PIN_VERIFIED = true;
    if (typeof hidePinOverlays === 'function') hidePinOverlays();
    resetPageTimeout();
}

// ── Activity listeners (reset timer on interaction) ─────────────
document.addEventListener('mousemove', resetPageTimeout);
document.addEventListener('click', resetPageTimeout);
document.addEventListener('keydown', resetPageTimeout);
document.addEventListener('scroll', resetPageTimeout);
document.addEventListener('touchstart', resetPageTimeout);

// ── Wire up page-timeout PIN submit ─────────────────────────────
(function() {
    var inp = document.getElementById('timeoutPinInput');
    var err = document.getElementById('timeoutPinError');
    var btn = document.getElementById('timeoutPinSubmit');
    if (!inp || !btn) return;
    function submitTimeoutPin() {
        var pin = inp.value;
        if (!/^\d{4}$/.test(pin)) { err.textContent = 'Enter exactly 4 digits.'; return; }
        inp.disabled = true;
        verifyPin(pin).then(function(data) {
            if (data.success) {
                hidePageTimeout();
                err.textContent = '';
                inp.value = '';
                inp.disabled = false;
            } else {
                err.textContent = data.message || 'Incorrect PIN.';
                inp.value = '';
                inp.disabled = false;
                inp.focus();
            }
        }).catch(function() {
            err.textContent = 'Network error.';
            inp.disabled = false;
        });
    }
    btn.addEventListener('click', submitTimeoutPin);
    inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') submitTimeoutPin(); });
})();

// ── Initial check on page load ──────────────────────────────────
if (HAS_PIN) {
    resetPageTimeout();
}

// ── Hide topbar text near bottom of page ────────────────────────
window.addEventListener('scroll', function () {
    var scrollThreshold = 100;
    var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - scrollThreshold;
    document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function (el) {
        el.classList.toggle('hidden', nearBottom);
    });
});
</script>
