window.updateTopbarScheduleText = function(newEndFormatted) {
    var el = document.getElementById('topbarSchedText');
    if (!el) return;
    el.textContent = el.textContent.replace(/- (\d+:\d+\s[AP]M)/, '- ' + newEndFormatted);
};

var HAS_PIN = !!(window.lumiHasPin);
var PIN_VERIFIED = false;
var _timeoutTimer = null;

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
}

function hidePageTimeout() {
    var ov = document.getElementById('pageTimeoutOverlay');
    if (ov) ov.style.display = 'none';
    PIN_VERIFIED = true;
    if (typeof hidePinOverlays === 'function') hidePinOverlays();
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

window.addEventListener('scroll', function () {
    var scrollThreshold = 100;
    var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - scrollThreshold;
    document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function (el) {
        el.classList.toggle('hidden', nearBottom);
    });
});
