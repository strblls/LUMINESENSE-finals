function updateBadge(id, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    const okText = el.dataset.okText;
    const failText = el.dataset.failText;
    el.textContent = ok ? okText : failText;
    el.style.background = ok ? '#f9edfa' : '#2f004f';
    el.style.color = ok ? '#2f004f' : '#ffffff';
}

(function pollSystemStatus() {
    const interval = 3000;
    const bulbOff = '../../images/bulb-off.png';
    const bulbOn  = '../../images/bulb-on.png';

    async function checkWebcam() {
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const hasCamera = devices.some(d => d.kind === 'videoinput');
            updateBadge('statusWebcam', hasCamera);
        } catch (e) {
            updateBadge('statusWebcam', false);
        }
    }

    async function fetchStatus() {
        try {
            if (!CLASSROOM_ID) return;
            const res = await fetch(`../../api/faculty-status.php?classroom_id=${CLASSROOM_ID}`);
            if (!res.ok) return;
            const data = await res.json();
            if (!data.success) return;

            updateBadge('statusLighting', data.light_status === 'on');
            updateBadge('statusPIR', data.pir_occupied === true);

            for (let row = 1; row <= 3; row++) {
                const on = data['row' + row + '_status'] === 'on';
                document.querySelectorAll('.bulb-img[data-row="' + row + '"]')
                    .forEach(img => img.src = on ? bulbOn : bulbOff);
            }

            for (let row = 1; row <= 3; row++) {
                const sw = document.getElementById('row-' + row + '-switch');
                if (sw) sw.checked = data['row' + row + '_status'] === 'on';
            }

            const allOn = data.row1_status === 'on' && data.row2_status === 'on' && data.row3_status === 'on';
            const allText = document.getElementById('allLightsStatus');
            if (allText) {
                allText.textContent = allOn ? 'ON' : 'OFF';
                allText.classList.replace(allOn ? 'off' : 'on', allOn ? 'on' : 'off');
            }
            const allContainer = document.getElementById('allLightsContainer');
            if (allContainer) {
                allContainer.classList.replace(
                    allOn ? 'all-lights-off' : 'all-lights-on',
                    allOn ? 'all-lights-on'  : 'all-lights-off'
                );
            }
        } catch (e) {
            console.warn('Status poll error:', e);
        }
    }

    checkWebcam();
    fetchStatus();
    setInterval(fetchStatus, interval);
})();

document.getElementById('refreshBtn').addEventListener('click', () => {
    var gs = document.getElementById('gestureSection');
    if (gs && gs.classList.contains('gesture-maximized')) {
        sessionStorage.setItem('gestureMaximized', 'true');
    } else {
        sessionStorage.removeItem('gestureMaximized');
    }
    location.reload();
});

(function() {
    if (sessionStorage.getItem('gestureMaximized') === 'true') {
        var gs = document.getElementById('gestureSection');
        var btn = document.getElementById('gestureMaximizeBtn');
        if (gs) gs.classList.add('gesture-maximized');
        if (btn) btn.innerHTML = '<i class="bi bi-arrows-collapse"></i>';
    }
})();

(function pollLocks() {
    const interval = 4000;

    async function fetchLocks() {
        try {
            if (!CLASSROOM_ID) return;
            const res = await fetch(`../../api/faculty-status.php?classroom_id=${CLASSROOM_ID}&check_lock=1`);
            if (!res.ok) return;
            const data = await res.json();
            if (data.lock_gesture || data.lock_lighting) {
                lockAllControls(data.lock_gesture, data.lock_lighting, data.message, data.light_status);
            } else {
                unlockAllControls();
            }
        } catch (e) {
            console.warn('Lock poll error:', e);
        }
    }

    function lockAllControls(lockG, lockL, message, light_status) {
        showOverlayModal(lockG, lockL, message, light_status);
        toggleControl('gesture-controls', !lockG);
        toggleControl('lights-controls', !lockL);
    }

    function unlockAllControls() {
        var om = document.getElementById('overlayModal');
        if (om) om.style.display = 'none';
        toggleControl('gesture-controls', true);
        toggleControl('lights-controls', true);
    }

    function toggleControl(id, enable) {
        var el = document.getElementById(id);
        if (!el) return;
        if (enable) { el.style.display = ''; el.style.pointerEvents = ''; }
        else { el.style.display = 'none'; el.style.pointerEvents = 'none'; }
    }

    function showOverlayModal(lockG, lockL, message, light_status) {
        var om = document.getElementById('overlayModal');
        if (!om) return;
        var msg = document.getElementById('overlayMessage');
        if (msg) msg.innerHTML = message || '';
        var icon = document.getElementById('overlayIcon');
        if (icon) {
            icon.className = 'bi ' + (lockG ? 'bi-hand-index' : 'bi-lightbulb') + ' overlay-icon';
        }
        om.style.display = 'flex';
    }

    fetchLocks();
    setInterval(fetchLocks, interval);
})();

(function() {
    var gestureEvents = [];

    window.logGestureEvent = function(eventType, metadata) {
        gestureEvents.push({ eventType: eventType, metadata: metadata || '' });
    };

    function flushGestureEvents() {
        if (gestureEvents.length === 0) return;
        if (!CLASSROOM_ID) { gestureEvents = []; return; }
        var batch = gestureEvents.splice(0);
        var form = new FormData();
        form.append('classroom_id', CLASSROOM_ID);
        form.append('faculty_id', FACULTY_ID);
        form.append('events', JSON.stringify(batch));
        fetch('../../api/lights.php', { method: 'POST', body: form }).catch(function(){});
    }

    setInterval(flushGestureEvents, 5000);
    window.addEventListener('beforeunload', flushGestureEvents);
})();

(function pollGestureStatus() {
    const interval = 3000;

    async function fetchGestureStatus() {
        try {
            if (!CLASSROOM_ID) return;
            const res = await fetch(`../../api/faculty-status.php?classroom_id=${CLASSROOM_ID}`);
            if (!res.ok) return;
            const data = await res.json();
            var indicator = document.getElementById('gestureIndicator');
            if (!indicator) return;

            if (data.gesture_enabled) {
                indicator.textContent = 'Gesture Control Active';
                indicator.className = 'badge bg-success';
            } else {
                indicator.textContent = 'Gesture Control Disabled';
                indicator.className = 'badge bg-secondary';
            }
        } catch (e) {}
    }

    fetchGestureStatus();
    setInterval(fetchGestureStatus, interval);
})();

(function() {
    const interval = 5000;

    function updateUptime() {
        var el = document.getElementById('uptimeDisplay');
        if (!el) return;
        var created = el.dataset.created;
        if (!created) return;
        var start = new Date(created.replace(' ', 'T') + '+08:00');
        var diff = Math.floor((Date.now() - start) / 1000);
        if (diff < 0) diff = 0;
        var h = Math.floor(diff / 3600);
        var m = Math.floor((diff % 3600) / 60);
        var s = diff % 60;
        el.textContent = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    setInterval(updateUptime, 1000);
})();

(function pollRecentActivities() {
    const interval = 3000;

    async function fetchActivities() {
        try {
            if (!CLASSROOM_ID) return;
            const res = await fetch(`../../api/activity-logs.php?classroom_id=${CLASSROOM_ID}`);
            if (!res.ok) return;
            const data = await res.json();
            if (!data.success || !data.logs) return;

            var tl = document.getElementById('activityTimeline');
            if (!tl) return;
            tl.innerHTML = '';

            var toShow = data.logs.slice(0, 10);
            toShow.forEach(function(log) {
                var iconData = getIconData(log.event_type);
                var time = log.event_time || '';
                if (time.length > 19) time = time.substring(11, 19);

                var div = document.createElement('div');
                div.className = 'timeline-item';
                div.innerHTML =
                    '<div class="timeline-icon" style="background:' + iconData.bg + ';color:' + iconData.color + ';">' +
                        '<i class="bi ' + iconData.icon + '"></i>' +
                    '</div>' +
                    '<div class="timeline-content">' +
                        '<strong>' + (iconData.label || log.event_type) + '</strong>' +
                        '<small class="timeline-time">' + time + '</small>' +
                    '</div>';
                tl.appendChild(div);
            });
        } catch (e) {}
    }

    function getIconData(eventType) {
        var map = {
            'on':              { icon: 'bi-lightbulb-fill', label: 'Lights On', color: '#fff', bg: '#28a745' },
            'off':             { icon: 'bi-lightbulb',      label: 'Lights Off', color: '#fff', bg: '#6c757d' },
            'security_alert':  { icon: 'bi-shield-exclamation', label: 'Security Alert', color: '#fff', bg: '#dc3545' },
            'gesture':         { icon: 'bi-hand-index',     label: 'Gesture', color: '#fff', bg: '#6f42c1' },
            'schedule':        { icon: 'bi-clock',          label: 'Schedule', color: '#fff', bg: '#0d6efd' },
        };
        return map[eventType] || { icon: 'bi-circle', label: eventType, color: '#fff', bg: '#6c757d' };
    }

    fetchActivities();
    setInterval(fetchActivities, interval);
})();

(function handlePinSubmit() {
    function submitPin(pin) {
        fetch('../../api/pin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify', pin: pin, classroom_id: CLASSROOM_ID, faculty_id: FACULTY_ID })
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var error = document.getElementById('pinError');
            if (d.success) {
                var modal = bootstrap.Modal.getInstance(document.getElementById('pinModal'));
                if (modal) modal.hide();
                unlockControls();
                fetch('../../api/auto-approve-extensions.php').catch(function(){});
            } else {
                if (error) error.textContent = d.message || 'Invalid PIN.';
            }
        })
        .catch(function() {
            var error = document.getElementById('pinError');
            if (error) error.textContent = 'Network error. Please try again.';
        });
    }

    var pinInputs = document.querySelectorAll('#pinModal .pin-digit');
    if (pinInputs.length) {
        for (var i = 0; i < pinInputs.length; i++) {
            pinInputs[i].addEventListener('input', function() {
                var pin = '';
                document.querySelectorAll('#pinModal .pin-digit').forEach(function(inp) { pin += inp.value; });
                if (pin.length === 4) {
                    submitPin(pin);
                }
            });
        }
    }

    var submitBtn = document.getElementById('submitPinBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', function() {
            var pin = '';
            document.querySelectorAll('#pinModal .pin-digit').forEach(function(inp) { pin += inp.value; });
            submitPin(pin);
        });
    }
})();

(function checkPinOnLoad() {
    if (HAS_ACTIVE_SCHEDULE) {
        var pinModal = new bootstrap.Modal(document.getElementById('pinModal'), { backdrop: 'static', keyboard: false });
        pinModal.show();
    }
})();

fetch('../../api/auto-approve-extensions.php').catch(function(){});

(function() {
    var input = document.getElementById('pinSetupInput');
    var confirm = document.getElementById('pinSetupConfirm');
    var error = document.getElementById('pinSetupError');
    var btn = document.getElementById('pinSetupSubmit');
    if (!input || !confirm || !btn) return;
    function submitPin() {
        var pin = input.value;
        if (!/^\d{4}$/.test(pin)) { error.textContent = 'Enter exactly 4 digits.'; return; }
        if (pin !== confirm.value) { error.textContent = 'PINs do not match.'; return; }
        btn.disabled = true;
        btn.textContent = 'Saving\u2026';
        fetch('../../api/pin.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'save', pin: pin})
        }).then(function(r){ return r.json(); }).then(function(d){
            if (d.success) {
                var ov = document.getElementById('pinSetupOverlay');
                if (ov) ov.style.display = 'none';
                HAS_PIN = true;
                location.reload();
            } else {
                error.textContent = d.message;
                btn.disabled = false;
                btn.textContent = 'Save PIN';
            }
        }).catch(function(){
            error.textContent = 'Network error.';
            btn.disabled = false;
            btn.textContent = 'Save PIN';
        });
    }
    btn.addEventListener('click', submitPin);
    input.addEventListener('keydown', function(e){ if (e.key === 'Enter') submitPin(); });
    confirm.addEventListener('keydown', function(e){ if (e.key === 'Enter') submitPin(); });
})();

(function() {
    var scheduleId = typeof SCHEDULE_EXTEND_ID !== 'undefined' ? SCHEDULE_EXTEND_ID : null;
    var classStart = typeof CLASS_START_EXTEND !== 'undefined' ? CLASS_START_EXTEND : '';
    var classEnd = typeof CLASS_END_EXTEND !== 'undefined' ? CLASS_END_EXTEND : '';
    var roomName = typeof ROOM_NAME_EXTEND !== 'undefined' ? ROOM_NAME_EXTEND : '';

    if (!scheduleId) return;

    function parseTime(timeStr) {
        const now = new Date();
        const parts = timeStr.trim().split(' ');
        const timeParts = parts[0].split(':').map(Number);
        let hours = timeParts[0], minutes = timeParts[1] || 0;
        const ampm = parts[1];
        if (ampm === 'PM' && hours !== 12) hours += 12;
        if (ampm === 'AM' && hours === 12) hours = 0;
        now.setHours(hours, minutes, 0, 0);
        return now;
    }

    function formatTime(date) {
        let hours = date.getHours();
        const minutes = date.getMinutes();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        if (hours === 0) hours = 12;
        return hours + ':' + String(minutes).padStart(2, '0') + ' ' + ampm;
    }

    function calcElapsedMinutes() {
        const start = parseTime(classStart);
        const end = parseTime(classEnd);
        return Math.max(0, Math.floor((end - start) / 60000));
    }

    function getTotalSeconds() {
        const h = parseInt(document.getElementById('timer-hours').value) || 0;
        const m = parseInt(document.getElementById('timer-minutes').value) || 0;
        const s = parseInt(document.getElementById('timer-seconds').value) || 0;
        return h * 3600 + m * 60 + s;
    }

    function updateTimerDisplay(totalSeconds) {
        const h = Math.floor(totalSeconds / 3600);
        const m = Math.floor((totalSeconds % 3600) / 60);
        const s = totalSeconds % 60;
        document.getElementById('timer-hours').value = String(h).padStart(2, '0');
        document.getElementById('timer-minutes').value = String(m).padStart(2, '0');
        document.getElementById('timer-seconds').value = String(s).padStart(2, '0');
    }

    function updateDescription() {
        const totalSeconds = getTotalSeconds();
        const elapsedMins = calcElapsedMinutes();
        const extraMins = Math.max(0, Math.floor(totalSeconds / 60) - elapsedMins);
        const endDT = parseTime(classEnd);
        endDT.setMinutes(endDT.getMinutes() + extraMins);
        const el = document.getElementById('extend-time-range');
        if (el) el.textContent = classStart + ' - ' + formatTime(endDT);
        const submitBtn = document.getElementById('submitExtendBtn');
        if (submitBtn) submitBtn.disabled = !(extraMins > 0);
    }

    updateTimerDisplay(calcElapsedMinutes() * 60);
    updateDescription();

    document.querySelectorAll('#extendPills .extend-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var mins = parseInt(this.dataset.mins);
            var h = parseInt(document.getElementById('timer-hours').value) || 0;
            var m = parseInt(document.getElementById('timer-minutes').value) || 0;
            var s = parseInt(document.getElementById('timer-seconds').value) || 0;
            m += mins;
            if (m >= 60) { h += Math.floor(m / 60); m = m % 60; }
            if (h > 99) h = 99;
            document.getElementById('timer-hours').value = String(h).padStart(2, '0');
            document.getElementById('timer-minutes').value = String(m).padStart(2, '0');
            document.getElementById('timer-seconds').value = String(s).padStart(2, '0');
            document.querySelectorAll('.extend-pill').forEach(function(b) {
                b.classList.remove('active', 'btn-primary');
                b.classList.add('btn-outline-primary');
            });
            this.classList.add('active', 'btn-primary');
            this.classList.remove('btn-outline-primary');
            setTimeout(function() {
                btn.classList.remove('active', 'btn-primary');
                btn.classList.add('btn-outline-primary');
            }, 150);
            updateDescription();
            var submitBtn = document.getElementById('submitExtendBtn');
            if (submitBtn) submitBtn.disabled = false;
        });
    });

    document.querySelectorAll('.timer-input').forEach(function(input) {
        input.addEventListener('focus', function(e) { e.target.select(); });
        input.addEventListener('input', function(e) { e.target.value = e.target.value.replace(/[^0-9]/g, ''); });
        input.addEventListener('blur', function(e) {
            var val = parseInt(e.target.value) || 0;
            if (e.target.id === 'timer-hours') { if (val > 99) val = 99; e.target.value = String(val).padStart(2, '0'); }
            else { if (val >= 60) val = 59; e.target.value = String(val).padStart(2, '0'); }
            updateDescription();
        });
        input.addEventListener('keydown', function(e) { if (e.key === 'Enter') e.target.blur(); });
    });

    document.getElementById('submitExtendBtn').addEventListener('click', function() {
        const totalSeconds = getTotalSeconds();
        const elapsedMins = calcElapsedMinutes();
        const timerMins = Math.floor(totalSeconds / 60);
        const extensionMins = timerMins - elapsedMins;
        if (extensionMins > 0) {
            document.getElementById('confirmExtendMins').textContent = extensionMins + ' min';
            document.getElementById('confirmExtendAction').textContent = 'submit';
            new bootstrap.Modal(document.getElementById('confirmExtendModal')).show();
        }
    });

    document.getElementById('confirmExtendBtn').addEventListener('click', async function() {
        const totalSeconds = getTotalSeconds();
        const elapsedMins = calcElapsedMinutes();
        const extensionMins = Math.floor(totalSeconds / 60) - elapsedMins;
        const btn = this;
        const form = new FormData();
        form.append('schedule_id', scheduleId);
        form.append('extend_mins', extensionMins);
        btn.disabled = true;
        btn.textContent = 'Sending\u2026';
        try {
            const res = await fetch('../../api/request-extension.php', { method: 'POST', body: form });
            const data = await res.json();
            if (data.success) {
                var confirmModal = bootstrap.Modal.getInstance(document.getElementById('confirmExtendModal'));
                if (confirmModal) confirmModal.hide();
                var extendModal = bootstrap.Modal.getInstance(document.getElementById('extendModal'));
                if (extendModal) extendModal.hide();
                showToast(data.message, 'success');
                if (data.auto_approved && data.extended_until && typeof window._updateScheduleEnd === 'function') {
                    window._updateScheduleEnd(data.extended_until);
                }
                if (data.extended_until_formatted && typeof window.updateTopbarScheduleText === 'function') {
                    window.updateTopbarScheduleText(data.extended_until_formatted);
                }
            } else {
                showToast(data.message, 'error');
            }
        } catch {
            showToast('Network error. Please try again.', 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Confirm';
    });
})();

function showToast(message, type) {
    type = type || 'success';
    var container = document.getElementById('toastContainer');
    if (!container) return;
    var toast = document.createElement('div');
    toast.className = 'toast-notification ' + type;
    toast.innerHTML = (type === 'success' ? '\u2705 ' : '\u26a0\ufe0f ') + message;
    container.appendChild(toast);
    setTimeout(function() {
        toast.style.transition = 'opacity .5s';
        toast.style.opacity = '0';
        setTimeout(function() { toast.remove(); }, 500);
    }, 5000);
}

function openEndEarlyModal(schedId, roomName) {
    document.getElementById('endEarlyRoom').textContent = roomName;
    document.getElementById('endEarlySchedId').value = schedId;
    new bootstrap.Modal(document.getElementById('endEarlyModal')).show();
}

function toggleGestureMaximize() {
    const section = document.getElementById('gestureSection');
    const btn = document.getElementById('gestureMaximizeBtn');
    if (!section || !btn) return;
    const isMax = section.classList.toggle('gesture-maximized');
    btn.innerHTML = isMax
        ? '<i class="bi bi-arrows-collapse"></i>'
        : '<i class="bi bi-arrows-expand"></i>';
    setTimeout(() => window.dispatchEvent(new Event('resize')), 100);
}

// ── Temporary gesture TEST MODE ───────────────────────────────────────────────
// Bypasses the schedule-based lock so gestures can be tested without an active
// schedule. This is a dev/testing aid only; it is not persisted.
let gestureTestMode = false;

function toggleGestureTestMode() {
    const section = document.getElementById('gestureSection');
    const btn = document.getElementById('gestureTestBtn');
    const content = document.getElementById('gestureControlsContent');
    const enableBtn = document.getElementById('enableCameraBtn');
    const scheduleOverlay = document.getElementById('gestureScheduleOverlay');
    const pinOverlay = document.getElementById('gesturePinOverlay');

    gestureTestMode = !gestureTestMode;
    const originallyBlocked = section && section.dataset.gestureBlocked === '1';

    if (gestureTestMode) {
        // Unlock the controls and dismiss all gesture overlays
        if (content) { content.style.filter = ''; content.style.pointerEvents = ''; }
        if (enableBtn) { enableBtn.disabled = false; enableBtn.removeAttribute('title'); }
        if (scheduleOverlay) scheduleOverlay.style.display = 'none';
        if (pinOverlay) pinOverlay.style.display = 'none';
        if (btn) {
            btn.classList.add('active');
            btn.innerHTML = '<i class="bi bi-bug-fill me-1"></i>Test ON';
        }
    } else {
        // Restore the original locked/blocked state
        if (originallyBlocked) {
            if (content) { content.style.filter = 'blur(6px)'; content.style.pointerEvents = 'none'; }
            if (enableBtn) { enableBtn.disabled = true; enableBtn.setAttribute('title', 'No active schedule'); }
            if (scheduleOverlay) scheduleOverlay.style.display = '';
        } else {
            if (content) { content.style.filter = ''; content.style.pointerEvents = ''; }
            if (scheduleOverlay) scheduleOverlay.style.display = 'none';
        }
        if (btn) {
            btn.classList.remove('active');
            btn.innerHTML = '<i class="bi bi-bug me-1"></i>Test';
        }
    }
}

function syncRowPills() {
    [1, 2, 3].forEach(function (r) {
        var sw = document.getElementById('row-' + r + '-switch');
        var pill = document.getElementById('rowPill' + r);
        if (!sw || !pill) return;
        if (!pill.classList.contains('pending') && !pill.classList.contains('confirmed')) {
            pill.classList.toggle('active', sw.checked);
        }
    });
}
syncRowPills();
