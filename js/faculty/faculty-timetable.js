        // Initialize Bootstrap modal for extend request
        const extendModalEl = document.getElementById('extendModal');
        const extendModal = new bootstrap.Modal(extendModalEl);

        let currentScheduleId = null;
        let currentRoom = '';
        let currentStartTime = '';
        let currentEndTime = '';
        let totalExtensionMinutes = 0;

        // Parse time string (e.g., "1:00 PM") to Date object for today
        function parseTime(timeStr) {
            const now = new Date();
            const [time, ampm] = timeStr.trim().split(' ');
            let [hours, minutes] = time.split(':').map(Number);
            if (ampm === 'PM' && hours !== 12) hours += 12;
            if (ampm === 'AM' && hours === 12) hours = 0;
            now.setHours(hours, minutes, 0, 0);
            return now;
        }

        // Format time to 12-hour format (e.g., "1:00 PM")
        function formatTime(date) {
            let hours = date.getHours();
            const minutes = date.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            if (hours === 0) hours = 12;
            const minStr = minutes.toString().padStart(2, '0');
            return `${hours}:${minStr} ${ampm}`;
        }

        // Calculate elapsed time between start and end
        function calculateElapsedMinutes(startTime, endTime) {
            const start = parseTime(startTime);
            const end = parseTime(endTime);
            const diffMs = end - start;
            return Math.floor(diffMs / 60000);
        }

        // Update timer display from total seconds
        function updateTimerDisplay(totalSeconds) {
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;
            document.getElementById('timer-hours').value = hours.toString().padStart(2, '0');
            document.getElementById('timer-minutes').value = minutes.toString().padStart(2, '0');
            document.getElementById('timer-seconds').value = seconds.toString().padStart(2, '0');
        }

        // Get total seconds from timer inputs
        function getTotalSecondsFromInputs() {
            const hours = parseInt(document.getElementById('timer-hours').value) || 0;
            const minutes = parseInt(document.getElementById('timer-minutes').value) || 0;
            const seconds = parseInt(document.getElementById('timer-seconds').value) || 0;
            return hours * 3600 + minutes * 60 + seconds;
        }

        // Update the description text with extended time
        function updateDescription() {
            const totalSeconds = getTotalSecondsFromInputs();
            const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
            const extraMinutes = Math.max(0, Math.floor(totalSeconds / 60) - elapsedMinutes);

            document.getElementById('extend-room').textContent = currentRoom;
            document.getElementById('extend-start-time').textContent = currentStartTime;

            if (currentEndTime) {
                const endDateTime = parseTime(currentEndTime);
                endDateTime.setMinutes(endDateTime.getMinutes() + extraMinutes);
                const newEndTime = formatTime(endDateTime);
                document.getElementById('extend-end-time').textContent = newEndTime;
                document.getElementById('extend-time-range').textContent = `${currentStartTime} - ${newEndTime}`;
            }

            // Disable send button if no extension minutes added
            document.getElementById('submitExtendBtn').disabled = !(extraMinutes > 0);
        }

        // Reset timer to elapsed time based on slot
        function resetTimerToElapsed() {
            if (currentStartTime && currentEndTime) {
                const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
                totalExtensionMinutes = 0;
                updateTimerDisplay(elapsedMinutes * 60);
                updateDescription();
            } else {
                totalExtensionMinutes = 0;
                updateTimerDisplay(0);
                updateDescription();
            }
        }

        let conflictModalInstance = null;

        function showConflictModal(room, start, end, subject) {
            document.getElementById('conflictRoom').textContent = room;
            document.getElementById('conflictTime').textContent = start + ' - ' + end;
            document.getElementById('conflictSubject').textContent = subject;
            if (!conflictModalInstance) {
                conflictModalInstance = new bootstrap.Modal(document.getElementById('roomConflictModal'));
            }
            conflictModalInstance.show();
        }

        function requestExtend(scheduleId, room, startTime, endTime) {
            currentScheduleId = scheduleId;
            currentRoom = room;
            currentStartTime = startTime;
            currentEndTime = endTime;

            document.getElementById('extend-edit-id').value = '';
            document.getElementById('submitExtendBtn').disabled = true;

            // Reset pills
            document.querySelectorAll('.extend-pill').forEach(btn => {
                btn.classList.remove('active', 'btn-primary');
                btn.classList.add('btn-outline-primary');
            });

            // Reset timer to elapsed time
            resetTimerToElapsed();

            // Check for succeeding schedule in the same room
            fetch('../../api/check-room-successor.php?schedule_id=' + scheduleId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success && data.has_successor) {
                        showConflictModal(
                            data.next.room_name,
                            data.next.start_time,
                            data.next.end_time,
                            data.next.subject_name
                        );
                    } else {
                        extendModal.show();
                    }
                })
                .catch(function() {
                    extendModal.show();
                });
        }

        function editExtensionRequest(scheduleId, room, startTime, endTime, extendMins, extRequestId) {
            currentScheduleId = scheduleId;
            currentRoom = room;
            currentStartTime = startTime;
            currentEndTime = endTime;

            document.getElementById('extend-edit-id').value = extRequestId;
            document.getElementById('submitExtendBtn').disabled = false;

            // Reset pills
            document.querySelectorAll('.extend-pill').forEach(btn => {
                btn.classList.remove('active', 'btn-primary');
                btn.classList.add('btn-outline-primary');
            });

            // Reset timer to elapsed time then add the existing extension minutes
            resetTimerToElapsed();
            let currentHours = parseInt(document.getElementById('timer-hours').value) || 0;
            let currentMinutes = parseInt(document.getElementById('timer-minutes').value) || 0;
            currentMinutes += extendMins;
            if (currentMinutes >= 60) {
                currentHours += Math.floor(currentMinutes / 60);
                currentMinutes = currentMinutes % 60;
            }
            if (currentHours > 99) currentHours = 99;
            document.getElementById('timer-hours').value = currentHours.toString().padStart(2, '0');
            document.getElementById('timer-minutes').value = currentMinutes.toString().padStart(2, '0');
            updateDescription();

            // Check for succeeding schedule in the same room
            fetch('../../api/check-room-successor.php?schedule_id=' + scheduleId)
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (data.success && data.has_successor) {
                        showConflictModal(
                            data.next.room_name,
                            data.next.start_time,
                            data.next.end_time,
                            data.next.subject_name
                        );
                    } else {
                        extendModal.show();
                    }
                })
                .catch(function() {
                    extendModal.show();
                });
        }

        let deleteModalInstance = null;
        let confirmExtendModal = null;
        let elapsedWarningModal = null;

        function openDeleteModal(extRequestId) {
            document.getElementById('deleteExtId').value = extRequestId;
            if (!deleteModalInstance) {
                deleteModalInstance = new bootstrap.Modal(document.getElementById('deleteExtModal'));
            }
            deleteModalInstance.show();
        }

        // Handle pill selection - adds minutes to timer
        document.querySelectorAll('.extend-pill').forEach(btn => {
            btn.addEventListener('click', () => {
                const minsToAdd = parseInt(btn.dataset.mins);

                // Read current values directly from the inputs
                let currentHours = parseInt(document.getElementById('timer-hours').value) || 0;
                let currentMinutes = parseInt(document.getElementById('timer-minutes').value) || 0;
                let currentSeconds = parseInt(document.getElementById('timer-seconds').value) || 0;

                // Add to minutes
                currentMinutes += minsToAdd;

                // Cascade overflow upward
                if (currentMinutes >= 60) {
                    currentHours += Math.floor(currentMinutes / 60);
                    currentMinutes = currentMinutes % 60;
                }
                if (currentHours > 99) currentHours = 99;

                // Write back
                document.getElementById('timer-hours').value = currentHours.toString().padStart(2, '0');
                document.getElementById('timer-minutes').value = currentMinutes.toString().padStart(2, '0');
                document.getElementById('timer-seconds').value = currentSeconds.toString().padStart(2, '0');

                // Visual state
                document.querySelectorAll('.extend-pill').forEach(b => {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-primary');
                });
                // Visual state - flash active then revert (push button behavior)
                btn.classList.add('active', 'btn-primary');
                btn.classList.remove('btn-outline-primary');

                setTimeout(() => {
                    btn.classList.remove('active', 'btn-primary');
                    btn.classList.add('btn-outline-primary');
                }, 150);

                updateDescription();
                document.getElementById('submitExtendBtn').disabled = false;
            });
        });

        // Handle timer input changes
        document.querySelectorAll('.timer-input').forEach(input => {
            input.addEventListener('focus', (e) => {
                e.target.select();
            });

            input.addEventListener('blur', (e) => {
                let val = parseInt(e.target.value) || 0;

                if (e.target.id === 'timer-hours') {
                    if (val > 99) val = 99;
                    e.target.value = val.toString().padStart(2, '0');
                } else if (e.target.id === 'timer-minutes') {
                    if (val >= 60) {
                        const carryHours = Math.floor(val / 60);
                        const remMinutes = val % 60;
                        const hoursInput = document.getElementById('timer-hours');
                        let currentHours = parseInt(hoursInput.value) || 0;
                        currentHours = Math.min(99, currentHours + carryHours);
                        hoursInput.value = currentHours.toString().padStart(2, '0');
                        val = remMinutes;
                    }
                    e.target.value = val.toString().padStart(2, '0');
                } else if (e.target.id === 'timer-seconds') {
                    if (val >= 60) {
                        const carryMinutes = Math.floor(val / 60);
                        const remSeconds = val % 60;
                        const minutesInput = document.getElementById('timer-minutes');
                        let currentMinutes = parseInt(minutesInput.value) || 0;
                        currentMinutes += carryMinutes;
                        // Seconds carry may itself push minutes over 60, cascade up
                        if (currentMinutes >= 60) {
                            const carryHours = Math.floor(currentMinutes / 60);
                            currentMinutes = currentMinutes % 60;
                            const hoursInput = document.getElementById('timer-hours');
                            let currentHours = parseInt(hoursInput.value) || 0;
                            currentHours = Math.min(99, currentHours + carryHours);
                            hoursInput.value = currentHours.toString().padStart(2, '0');
                        }
                        minutesInput.value = currentMinutes.toString().padStart(2, '0');
                        val = remSeconds;
                    }
                    e.target.value = val.toString().padStart(2, '0');
                }

                updateDescription();
            });

            input.addEventListener('input', (e) => {
                e.target.value = e.target.value.replace(/[^0-9]/g, '');
            });

            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') e.target.blur();
            });
        });

        // Handle submit button click
        document.getElementById('submitExtendBtn').addEventListener('click', () => {
            const totalSeconds = getTotalSecondsFromInputs();
            const elapsedMinutes = calculateElapsedMinutes(currentStartTime, currentEndTime);
            const timerMinutes = Math.floor(totalSeconds / 60);
            const extensionMinutes = timerMinutes - elapsedMinutes;

            // Validate timer hasn't been reduced below actual elapsed time
            if (timerMinutes < elapsedMinutes) {
                if (!elapsedWarningModal) {
                    elapsedWarningModal = new bootstrap.Modal(document.getElementById('elapsedWarningModal'));
                }
                document.getElementById('elapsedWarningActual').textContent = elapsedMinutes + ' min';
                document.getElementById('elapsedWarningInput').textContent = timerMinutes + ' min';
                document.getElementById('elapsedWarningUnderstood').onclick = function() {
                    resetTimerToElapsed();
                    elapsedWarningModal.hide();
                };
                elapsedWarningModal.show();
                return;
            }

            if (extensionMinutes > 0) {
                document.getElementById('extend-schedule-id').value = currentScheduleId;
                document.getElementById('extend-mins-val').value = extensionMinutes;
                // Show confirm modal
                if (!confirmExtendModal) {
                    confirmExtendModal = new bootstrap.Modal(document.getElementById('confirmExtendModal'));
                }
                const isEdit = document.getElementById('extend-edit-id').value ? true : false;
                document.getElementById('confirmExtendRoom').textContent = currentRoom;
                document.getElementById('confirmExtendTime').textContent = currentStartTime + ' - ' + currentEndTime;
                document.getElementById('confirmExtendMins').textContent = extensionMinutes + ' min';
                document.getElementById('confirmExtendAction').textContent = isEdit ? 'update' : 'submit';
                confirmExtendModal.show();
            }
        });

        // Handle confirm button — AJAX submit instead of form POST
        document.addEventListener('DOMContentLoaded', function() {
            var confirmBtn = document.getElementById('confirmExtendBtn');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', async function() {
                    const btn = this;
                    const schedId = document.getElementById('extend-schedule-id').value;
                    const mins = document.getElementById('extend-mins-val').value;
                    const editId = document.getElementById('extend-edit-id').value;

                    const form = new FormData();
                    form.append('schedule_id', schedId);
                    form.append('extend_mins', mins);
                    if (editId) form.append('edit_ext_request', editId);

                    btn.disabled = true;
                    btn.textContent = 'Sending…';

                    try {
                        const res = await fetch('../../api/request-extension.php', { method: 'POST', body: form });
                        const data = await res.json();
                        if (data.success) {
                            if (confirmExtendModal) confirmExtendModal.hide();
                            if (extendModal) extendModal.hide();
                            showToast(data.message);
                            if (data.auto_approved && data.extended_until) {
                                if (typeof window._updateScheduleEnd === 'function') {
                                    window._updateScheduleEnd(data.extended_until);
                                }
                                var slotRow = document.querySelector('.slot-row[data-slot-id="' + schedId + '"]');
                                if (slotRow && data.extended_until_formatted) {
                                    var endParts = data.extended_until_formatted.split(' ');
                                    var endTime = endParts[0];
                                    var endAmpm = endParts[1] || '';
                                    var timeEnd = slotRow.querySelector('.slot-time-end');
                                    var timeAmpm = slotRow.querySelector('.slot-time-ampm');
                                    if (timeEnd) timeEnd.textContent = endTime;
                                    if (timeAmpm) timeAmpm.textContent = endAmpm;
                                }
                                if (data.extended_until_formatted && typeof window.updateTopbarScheduleText === 'function') {
                                    window.updateTopbarScheduleText(data.extended_until_formatted);
                                }
                            }
                        } else {
                            showToast(data.message);
                        }
                    } catch {
                        showToast('Network error. Please try again.');
                    }
                    btn.disabled = false;
                    btn.textContent = 'Confirm';
                });
            }
        });

        // Clear edit state when modal is hidden
        extendModalEl.addEventListener('hidden.bs.modal', () => {
            currentScheduleId = null;
            currentRoom = '';
            currentStartTime = '';
            currentEndTime = '';
            totalExtensionMinutes = 0;
            document.getElementById('extend-edit-id').value = '';
        });

        // ── View Slot Details Modal ───────────────────────────────
        let viewSlotModal = null;

        function openSlotDetails(id, day, startTime, endTime, room, extension, subject) {
            if (!viewSlotModal) {
                viewSlotModal = new bootstrap.Modal(document.getElementById('viewSlotModal'));
            }

            document.getElementById('slot-day').textContent = day;
            document.getElementById('slot-time').textContent = `${startTime} — ${endTime}`;
            document.getElementById('slot-room').textContent = room;
            document.getElementById('slot-subject').textContent = subject;

            viewSlotModal.show();
        }

        (function() {
            const panels = ['panelTimeLeft', 'panelClassDetails', 'panelExtRequests', 'panelCoverage', 'panelInfo'];
            const timers = {};

            panels.forEach(id => {
                const btn = document.querySelector(`[data-panel="${id}"]`);
                const panel = document.getElementById(id);
                if (!btn || !panel) return;

                timers[id] = null;

                const open = () => {
                    if (timers[id]) {
                        clearTimeout(timers[id]);
                        timers[id] = null;
                    }
                    panel.classList.add('show');
                    btn.classList.remove('has-update');
                };

                const close = () => {
                    if (timers[id]) clearTimeout(timers[id]);
                    timers[id] = setTimeout(() => panel.classList.remove('show'), 150);
                };

                btn.addEventListener('mouseenter', open);
                btn.addEventListener('focus', open);
                panel.addEventListener('mouseenter', open);
                panel.addEventListener('mouseleave', close);
                btn.addEventListener('mouseleave', close);

                // Watch for content changes to show notification dot
                const observer = new MutationObserver(() => {
                    btn.classList.add('has-update');
                });
                observer.observe(panel, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });
            });
        })();

    (function() {
        var _firstExtPoll = true;

        function esc(str) {
            if (str == null) return '';
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        }

        function escapeJs(str) {
            if (str == null) return '';
            return String(str).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
        }

        function fmtTime(t) {
            if (!t) return '';
            var p = t.split(':');
            var h = parseInt(p[0]), m = p[1];
            var ampm = h >= 12 ? 'PM' : 'AM';
            if (h > 12) h -= 12;
            if (h === 0) h = 12;
            return h + ':' + m + ' ' + ampm;
        }

        function buildExtCard(r, showDay) {
            var dayHtml = showDay ? '<span class="text-muted">' + esc(r.day_of_week) + ' \u00b7 </span>' : '';
            var timeRange = fmtTime(r.start_time) + ' - ' + fmtTime(r.end_time);
            var statusClass = r.status === 'approved' ? 'text-success' : (r.status === 'rejected' ? 'text-danger' : 'text-warning');
            var statusLabel = r.status.charAt(0).toUpperCase() + r.status.slice(1);
            var actionsHtml = '';
            if (r.status === 'pending') {
                var roomEsc = escapeJs(r.room_name);
                var startEsc = escapeJs(fmtTime(r.start_time));
                var endEsc = escapeJs(fmtTime(r.end_time));
                actionsHtml = '<div class="d-flex gap-1 flex-shrink-0">'
                    + '<button class="btn-icon btn-icon-view" style="width:auto;padding:4px 10px;font-size:12px;"'
                    + ' onclick="editExtensionRequest(' + r.schedule_id + ',\'' + roomEsc + '\',\'' + startEsc + '\',\'' + endEsc + '\',' + r.extend_mins + ',' + r.id + ')"'
                    + ' title="Edit" data-bs-toggle="tooltip">'
                    + '<i class="bi bi-pencil"></i></button>'
                    + '<button class="btn-icon btn-icon-del" style="width:auto;padding:4px 10px;font-size:12px;"'
                    + ' onclick="openDeleteModal(' + r.id + ')"'
                    + ' title="Delete" data-bs-toggle="tooltip">'
                    + '<i class="bi bi-trash"></i></button></div>';
            }
            return '<div class="dept-info-card d-flex flex-row align-items-center justify-content-between gap-2 p-2">'
                + '<div class="d-flex flex-column small flex-grow-1">'
                + '<span><strong>' + esc(r.room_name) + '</strong> \u00b7 ' + esc(r.subject_name || 'No subject') + '</span>'
                + '<span class="text-muted">' + dayHtml + timeRange + '</span>'
                + '<span class="text-muted">+' + r.extend_mins + ' min \u00b7 Status:'
                + ' <span class="fw-bold ' + statusClass + '">' + statusLabel + '</span></span>'
                + '</div>'
                + actionsHtml
                + '</div>';
        }

        function renderExtRequests(data) {
            var todayContainer = document.getElementById('extTodayContainer');
            var otherContainer = document.getElementById('extOtherContainer');
            var badge = document.getElementById('extensionsLeftBadge');
            if (!todayContainer || !otherContainer) return;

            if (badge) {
                badge.textContent = 'Time Extensions Left for Today: ' + data.extensions_left_today;
            }

            if (data.today && data.today.length > 0) {
                todayContainer.innerHTML = data.today.map(function(r) { return buildExtCard(r, false); }).join('');
            } else {
                todayContainer.innerHTML = '<div class="d-flex flex-column align-items-center justify-content-center h-100"><p class="text-muted text-center">No extension requests yet.</p></div>';
            }

            if (data.other && data.other.length > 0) {
                otherContainer.innerHTML = data.other.map(function(r) { return buildExtCard(r, true); }).join('');
            } else {
                otherContainer.innerHTML = '<div class="d-flex flex-column align-items-center justify-content-center h-100"><p class="text-muted text-center">No other extension requests.</p></div>';
            }

            if (_firstExtPoll) {
                _firstExtPoll = false;
                var btn = document.querySelector('[data-panel="panelExtRequests"]');
                if (btn) btn.classList.remove('has-update');
            }
        }

        function fetchExtRequests() {
            fetch('../../api/faculty-extensions.php')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) renderExtRequests(data);
                })
                .catch(function() {});
        }

        document.addEventListener('DOMContentLoaded', function() {
            fetchExtRequests();
            setInterval(fetchExtRequests, 10000);
        });
    })();

        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('confirmPdfExportBtn').addEventListener('click', function () {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '../../handlers/export-pdf-handler.php';
                form.style.display = 'none';
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
                var pdfModal = bootstrap.Modal.getInstance(document.getElementById('confirmPdfModal'));
                if (pdfModal) pdfModal.hide();
            });
        });

    function showToast(message) {
        var el = document.getElementById('toastMsg');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('show');
        void el.offsetWidth;
        el.classList.add('show');
        setTimeout(function() {
            el.classList.remove('show');
        }, 2600);
    }