window.updateTopbarScheduleText = function(newEndFormatted) {
    var el = document.getElementById('topbarSchedText');
    if (!el) return;
    el.textContent = el.textContent.replace(/- (\d+:\d+\s[AP]M)/, '- ' + newEndFormatted);
};

var HAS_PIN = !!(window.lumiHasPin);
var PIN_VERIFIED = false;
var _timeoutTimer = null;

// ── Post-class PIN escalation (Final Warning) ──────────────
// Armed only when the faculty member is post-class (lumiPostClassArmed).
// A fixed 5-minute deadline is keyed to the moment the PIN overlay shows;
// no PIN entered before it fires → Final Warning overlay. Dismissing the
// Final Warning restarts a full 5-minute cycle.
var FIVE_MIN_MS    = 5 * 60 * 1000;
var fiveMinTimer   = null;
var fiveMinDeadline = 0;
var fiveMinTick    = null;

function fmtFiveMin(ms) {
    var total = Math.max(0, Math.ceil(ms / 1000));
    var m = Math.floor(total / 60);
    var s = total % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
}

function armFiveMinEscalation() {
    if (window.lumiPostClassArmed !== true) return;
    var ov = document.getElementById('fiveMinLogoutOverlay');
    if (!ov) return;
    if (fiveMinTimer) clearTimeout(fiveMinTimer);
    fiveMinDeadline = Date.now() + FIVE_MIN_MS;
    fiveMinTimer = setTimeout(showFiveMinOverlay, FIVE_MIN_MS);
}

function clearFiveMinEscalation() {
    if (fiveMinTimer) clearTimeout(fiveMinTimer);
    fiveMinTimer = null;
    if (fiveMinTick) clearInterval(fiveMinTick);
    fiveMinTick = null;
    var ov = document.getElementById('fiveMinLogoutOverlay');
    if (ov) ov.style.display = 'none';
}

function showFiveMinOverlay() {
    var ov = document.getElementById('fiveMinLogoutOverlay');
    if (!ov) return;
    ov.style.display = 'flex';
    var countEl = document.getElementById('fiveMinLogoutCountdown');
    if (fiveMinTick) clearInterval(fiveMinTick);
    fiveMinTick = setInterval(function () {
        var remain = fiveMinDeadline - Date.now();
        if (countEl) countEl.textContent = fmtFiveMin(remain);
        if (remain <= 0) {
            clearInterval(fiveMinTick);
            fiveMinTick = null;
            if (typeof window.lumiDoLogout === 'function') window.lumiDoLogout();
        }
    }, 1000);
    if (typeof window.lumiNotifyAlert === 'function') {
        window.lumiNotifyAlert('Final Warning', 'Your account will be logged out in 5 minutes. Please enter your PIN or log out now.');
    }
}

function dismissFiveMinOverlay() {
    clearFiveMinEscalation();
    armFiveMinEscalation();
}

// Expose so the session watcher (faculty-auto-logout.js) can cancel the
// escalation when a new class becomes active.
window.lumiCancelFiveMinEscalation = clearFiveMinEscalation;

(function () {
    var fwOv = document.getElementById('fiveMinLogoutOverlay');
    if (fwOv) {
        var fwNow = document.getElementById('fiveMinLogoutNowBtn');
        var fwCancel = document.getElementById('fiveMinLogoutCancelBtn');
        if (fwNow) fwNow.addEventListener('click', function () {
            if (typeof window.lumiDoLogout === 'function') window.lumiDoLogout();
        });
        if (fwCancel) fwCancel.addEventListener('click', dismissFiveMinOverlay);
    }
})();

async function verifyPin(pin) {
    var r = await fetch('../../api/pin.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'verify', pin: pin})
    });
    return await r.json();
}

function resetPageTimeout() {
    if (_timeoutTimer) clearTimeout(_timeoutTimer);
    if (typeof window.isGestureCameraActive === 'function' && window.isGestureCameraActive()) return;
    _timeoutTimer = setTimeout(showPageTimeout, 60000);
}

function showPageTimeout() {
    if (typeof window.isGestureCameraActive === 'function' && window.isGestureCameraActive()) {
        _timeoutTimer = setTimeout(showPageTimeout, 600000);
        return;
    }
    document.querySelectorAll('.modal.show').forEach(function(m) {
        var modal = bootstrap.Modal.getInstance(m);
        if (modal) modal.hide();
    });
    var ov = document.getElementById('pageTimeoutOverlay');
    if (ov) ov.style.display = 'flex';
    PIN_VERIFIED = false;
    if (typeof showPinOverlays === 'function') showPinOverlays();
    if (typeof window.resetCameraState === 'function') window.resetCameraState();
    armFiveMinEscalation();
}

function hidePageTimeout() {
    var ov = document.getElementById('pageTimeoutOverlay');
    if (ov) ov.style.display = 'none';
    PIN_VERIFIED = true;
    if (typeof hidePinOverlays === 'function') hidePinOverlays();
    clearFiveMinEscalation();
    resetPageTimeout();
}

document.addEventListener('mousemove', resetPageTimeout);
document.addEventListener('click', resetPageTimeout);
document.addEventListener('keydown', resetPageTimeout);
document.addEventListener('scroll', resetPageTimeout);
document.addEventListener('touchstart', resetPageTimeout);

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

if (HAS_PIN) {
    resetPageTimeout();
}
