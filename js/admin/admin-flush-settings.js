/**
 * admin-flush-settings.js
 * Handles the System Flush tab interactions on admin-profile-settings.php
 */

// ── Cascade checkbox logic ──────────────────────────────────────────────────
function onFlushCascade() {
    var depts = document.getElementById('flushDepts');
    var areas = document.getElementById('flushSubjectAreas');
    var subjects = document.getElementById('flushSubjects');
    var areasOption = document.getElementById('flushSubjectAreasOption');
    var subjectsOption = document.getElementById('flushSubjectsOption');

    if (depts.checked) {
        areas.checked = true;
        areas.disabled = true;
        areasOption.classList.add('cascaded');
        subjects.checked = true;
        subjects.disabled = true;
        subjectsOption.classList.add('cascaded');
    } else if (areas.checked) {
        areas.disabled = false;
        areasOption.classList.remove('cascaded');
        subjects.checked = true;
        subjects.disabled = true;
        subjectsOption.classList.add('cascaded');
    } else {
        areas.disabled = false;
        areasOption.classList.remove('cascaded');
        subjects.disabled = false;
        subjectsOption.classList.remove('cascaded');
    }
}

// ── Date change handler ─────────────────────────────────────────────────────
function onFlushDateChange() {
    var dateEl = document.getElementById('flushDate');
    var minDate = dateEl.getAttribute('min');
    if (dateEl.value < minDate) {
        dateEl.value = minDate;
    }
}

// ── Schedule flush ──────────────────────────────────────────────────────────
function scheduleFlush() {
    var dateVal = document.getElementById('flushDate').value;
    var timeVal = document.getElementById('flushTime').value;
    if (!dateVal || !timeVal) {
        showFlushToast('Please select a date and time.', true);
        return;
    }

    var minDate = document.getElementById('flushDate').getAttribute('min');
    if (dateVal < minDate) {
        showFlushToast('Date must be at least 5 months from now.', true);
        return;
    }

    var datetime = dateVal + ' ' + timeVal + ':00';

    // Show confirmation modal
    var modalEl = document.getElementById('flushConfirmModal');
    var dateDisplay = new Date(datetime).toLocaleString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
    document.getElementById('flushConfirmDate').textContent = dateDisplay;
    document.getElementById('flushConfirmSub').textContent = 'This action will permanently delete the selected data. A confirmation prompt will appear 7 days before the scheduled date.';

    var confirmBtn = document.getElementById('flushConfirmSubmit');
    var newBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);

    newBtn.addEventListener('click', function () {
        var depts = document.getElementById('flushDepts').checked ? 1 : 0;
        var areas = document.getElementById('flushSubjectAreas').checked ? 1 : 0;
        var subjects = document.getElementById('flushSubjects').checked ? 1 : 0;

        var formData = new FormData();
        formData.append('action', 'schedule_flush');
        formData.append('scheduled_datetime', datetime);
        formData.append('flush_departments', depts);
        formData.append('flush_subject_areas', areas);
        formData.append('flush_subjects', subjects);

        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Scheduling\u2026';

        fetch('../../php/handlers/flush-handler.php', {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                showFlushToast('Flush scheduled successfully!');
                setTimeout(function () { window.location.reload(); }, 1500);
            } else {
                showFlushToast(d.message, true);
                btn.disabled = false;
                btn.textContent = 'Confirm & Schedule';
            }
        })
        .catch(function () {
            showFlushToast('Network error.', true);
            btn.disabled = false;
            btn.textContent = 'Confirm & Schedule';
        });
    });

    var modal = new bootstrap.Modal(modalEl);
    modal.show();
}

// ── Schedule extensions flush ───────────────────────────────────────────────
function scheduleExtensionsFlush() {
    var extDate = document.getElementById('flushExtDate').value;
    var extTime = document.getElementById('flushExtTime').value;
    if (!extDate || !extTime) {
        showFlushToast('Please select a date and time for extensions flush.', true);
        return;
    }

    var datetime = extDate + ' ' + extTime + ':00';

    // Show confirmation modal
    var modalEl = document.getElementById('flushConfirmModal');
    var dateDisplay = new Date(datetime).toLocaleString('en-US', {
        year: 'numeric', month: 'long', day: 'numeric',
        hour: 'numeric', minute: '2-digit'
    });
    document.getElementById('flushConfirmDate').textContent = 'Extensions reset — ' + dateDisplay;
    document.getElementById('flushConfirmSub').textContent = 'All schedule extensions will be cleared at the scheduled date and time.';

    var confirmBtn = document.getElementById('flushConfirmSubmit');
    var newBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newBtn, confirmBtn);

    newBtn.addEventListener('click', function () {
        var formData = new FormData();
        formData.append('action', 'schedule_flush_extensions');
        formData.append('flush_extensions_datetime', datetime);

        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Scheduling\u2026';

        fetch('../../php/handlers/flush-handler.php', {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
                showFlushToast('Extensions reset scheduled successfully!');
                setTimeout(function () { window.location.reload(); }, 1500);
            } else {
                showFlushToast(d.message, true);
                btn.disabled = false;
                btn.textContent = 'Confirm & Schedule';
            }
        })
        .catch(function () {
            showFlushToast('Network error.', true);
            btn.disabled = false;
            btn.textContent = 'Confirm & Schedule';
        });
    });

    var modal = new bootstrap.Modal(modalEl);
    modal.show();
}

// ── Confirm flush (from confirmation window) ────────────────────────────────
function confirmFlush(flushId) {
    if (!confirm('Are you sure you want to confirm the system flush? This action cannot be undone.')) return;

    var formData = new FormData();
    formData.append('action', 'confirm_flush');
    formData.append('flush_id', flushId);

    fetch('../../php/handlers/flush-handler.php', {
        method: 'POST',
        body: formData
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.success) {
            showFlushToast('Flush confirmed. It will execute on the scheduled date.');
            setTimeout(function () { window.location.reload(); }, 1500);
        } else {
            showFlushToast(d.message, true);
        }
    })
    .catch(function () {
        showFlushToast('Network error.', true);
    });
}

// ── Dismiss reminder (Understood button) ────────────────────────────────────
function dismissReminder(flushId) {
    var formData = new FormData();
    formData.append('action', 'dismiss_reminder');
    formData.append('flush_id', flushId);

    fetch('../../php/handlers/flush-handler.php', {
        method: 'POST',
        body: formData
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.success) {
            var banner = document.getElementById('flushConfirmBanner');
            if (banner) banner.style.display = 'none';
        }
    })
    .catch(function () {});
}

// ── Cancel flush ────────────────────────────────────────────────────────────
function cancelFlush(flushId) {
    if (!confirm('Are you sure you want to cancel the scheduled system flush?')) return;

    var formData = new FormData();
    formData.append('action', 'cancel_flush');
    formData.append('flush_id', flushId);

    fetch('../../php/handlers/flush-handler.php', {
        method: 'POST',
        body: formData
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d.success) {
            showFlushToast('Flush cancelled.');
            setTimeout(function () { window.location.reload(); }, 1000);
        } else {
            showFlushToast(d.message, true);
        }
    })
    .catch(function () {
        showFlushToast('Network error.', true);
    });
}

// ── Toast notification ─────────────────────────────────────────────────────
function showFlushToast(msg, isError) {
    var toast = document.getElementById('toastMsg');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toastMsg';
        toast.className = 'toast-msg';
        var wrap = document.querySelector('.toast-wrap') || document.body;
        if (!wrap.classList.contains('toast-wrap')) {
            var w = document.createElement('div');
            w.className = 'toast-wrap';
            w.appendChild(toast);
            document.body.appendChild(w);
            wrap = w;
        } else {
            wrap.appendChild(toast);
        }
    }
    toast.textContent = msg;
    toast.className = 'toast-msg' + (isError ? ' error' : '') + ' show';
    setTimeout(function () { toast.classList.remove('show'); }, 3500);
}
