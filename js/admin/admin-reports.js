        document.addEventListener('DOMContentLoaded', function() {

            function switchTab(tab) {
                document.querySelectorAll('.timetable-btn[data-tab]').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
                document.getElementById('defaultState').style.display = 'none';
                document.querySelector(`.timetable-btn[data-tab="${tab}"]`)?.classList.add('active');
                document.getElementById('tab-' + tab).classList.add('active');

                if (tab === 'activity') {
                    filterActivity();
                } else if (tab === 'issues') {
                    filterIssues();
                } else {
                    filterRooms();
                }

                document.querySelectorAll('.stat-card').forEach(function(card) {
                    var icon = card.querySelector('.stat-icon i');
                    var valEl = card.querySelector('.stat-value');
                    var labelEl = card.querySelector('.stat-label');
                    if (tab === 'rooms') {
                        if (icon) icon.className = 'bi ' + card.dataset.rIcon;
                        if (valEl) valEl.textContent = card.dataset.rVal;
                        if (labelEl) labelEl.textContent = card.dataset.rLabel;
                    } else if (tab === 'issues') {
                        if (icon) icon.className = 'bi ' + card.dataset.iIcon;
                        if (valEl) valEl.textContent = card.dataset.iVal;
                        if (labelEl) labelEl.textContent = card.dataset.iLabel;
                    } else {
                        if (icon) icon.className = 'bi ' + card.dataset.aIcon;
                        if (valEl) valEl.textContent = card.dataset.aVal;
                        if (labelEl) labelEl.textContent = card.dataset.aLabel;
                    }
                });
            }

            document.querySelectorAll('.timetable-btn[data-tab]').forEach(btn => {
                btn.addEventListener('click', () => switchTab(btn.dataset.tab));
            });

            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            if (tabParam) {
                const target = document.querySelector(`.timetable-btn[data-tab="${tabParam}"]`);
                if (target) switchTab(tabParam);
            }

            (function() {
                var panels = ['panelGuideInfo'];
                var timers = {};
                panels.forEach(function(id) {
                    var btn = document.querySelector('[data-panel="' + id + '"]');
                    var panel = document.getElementById(id);
                    if (!btn || !panel) return;
                    timers[id] = null;
                    function open() {
                        if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
                        panel.classList.add('show');
                    }
                    function close() {
                        if (timers[id]) clearTimeout(timers[id]);
                        timers[id] = setTimeout(function() { panel.classList.remove('show'); }, 150);
                    }
                    btn.addEventListener('mouseenter', open);
                    btn.addEventListener('focus', open);
                    panel.addEventListener('mouseenter', open);
                    panel.addEventListener('mouseleave', close);
                    btn.addEventListener('mouseleave', close);
                });
            })();

            const reportsSearch = document.getElementById('reportsSearch');
            if (reportsSearch) {
                reportsSearch.addEventListener('input', function() {
                    var activeEl = document.querySelector('.timetable-btn[data-tab].active');
                    if (!activeEl) return;
                    if (activeEl.dataset.tab === 'activity') filterActivity();
                    else if (activeEl.dataset.tab === 'issues') filterIssues();
                    else filterRooms();
                });
            }

            const globalSearch = document.getElementById('globalSearch');
            if (globalSearch) {
                globalSearch.addEventListener('input', function() {
                    var reportsSearch = document.getElementById('reportsSearch');
                    if (reportsSearch) reportsSearch.value = this.value;
                    var activeEl = document.querySelector('.timetable-btn[data-tab].active');
                    if (!activeEl) return;
                    if (activeEl.dataset.tab === 'activity') filterActivity();
                    else if (activeEl.dataset.tab === 'issues') filterIssues();
                    else filterRooms();
                });
            }

            const ACT_PAGE_SIZE = 10;
            let actPage = 1;

            function filterActivity() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const type = document.getElementById('activityType').value;
                const date = document.getElementById('activityDate').value;
                const today = new Date().toISOString().slice(0, 10);
                const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
                const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

                const items = document.querySelectorAll('#activityTimeline .timeline-item');

                items.forEach(item => {
                    const matchQ = !q || item.dataset.search.includes(q);
                    let matchType = true;
                    if (type === 'pir') {
                        matchType = item.dataset.action && item.dataset.action.startsWith('pir_');
                    } else if (type === 'class') {
                        matchType = item.dataset.action && item.dataset.action.startsWith('class_');
                    } else if (type) {
                        matchType = item.dataset.type === type;
                    }
                    let matchDate = true;
                    if (date === 'today') matchDate = item.dataset.date === today;
                    if (date === 'week') matchDate = item.dataset.date >= weekAgo;
                    if (date === 'month') matchDate = item.dataset.date >= monthAgo;
                    item.dataset.filtered = (matchQ && matchType && matchDate) ? '1' : '0';
                });

                const filtered = [...items].filter(i => i.dataset.filtered === '1');
                const totalPages = Math.max(1, Math.ceil(filtered.length / ACT_PAGE_SIZE));
                if (actPage > totalPages) actPage = totalPages;

                const start = (actPage - 1) * ACT_PAGE_SIZE;
                items.forEach(item => {
                    if (item.dataset.filtered === '0') {
                        item.style.display = 'none';
                    } else {
                        const idx = filtered.indexOf(item);
                        item.style.display = (idx >= start && idx < start + ACT_PAGE_SIZE) ? '' : 'none';
                    }
                });

                const pageInfo = document.getElementById('activityPageInfo');
                const prevBtn = document.getElementById('activityPrev');
                const nextBtn = document.getElementById('activityNext');
                pageInfo.textContent = 'Page ' + actPage + ' of ' + totalPages;
                prevBtn.disabled = actPage <= 1;
                nextBtn.disabled = actPage >= totalPages;
            }

            window.goActivityPage = function(dir) {
                actPage += dir;
                filterActivity();
            };

            function filterRooms() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const light = document.getElementById('roomLightFilter').value;
                document.querySelectorAll('#roomTable tbody .room-main-row').forEach(row => {
                    const matchQ = !q || (row.dataset.search && row.dataset.search.includes(q));
                    const matchLight = !light || row.dataset.light === light;
                    const show = matchQ && matchLight;
                    row.style.display = show ? '' : 'none';
                    if (!show) {
                        const accordionRow = row.nextElementSibling;
                        if (accordionRow && accordionRow.classList.contains('room-accordion-row')) {
                            accordionRow.style.display = 'none';
                        }
                    }
                });
            }

            function filterIssues() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const type = document.getElementById('issueType').value;
                const date = document.getElementById('issueDate').value;
                const today = new Date().toISOString().slice(0, 10);
                const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
                const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

                document.querySelectorAll('#tab-issues .timeline-item').forEach(item => {
                    const matchQ = !q || item.dataset.search.includes(q);
                    const matchType = !type || item.dataset.action === type;
                    let matchDate = true;
                    if (date === 'today') matchDate = item.dataset.date === today;
                    if (date === 'week') matchDate = item.dataset.date >= weekAgo;
                    if (date === 'month') matchDate = item.dataset.date >= monthAgo;
                    item.style.display = (matchQ && matchType && matchDate) ? '' : 'none';
                });
            }

            document.getElementById('activityType').addEventListener('change', filterActivity);
            document.getElementById('activityDate').addEventListener('change', filterActivity);
            document.getElementById('roomLightFilter').addEventListener('change', filterRooms);
            document.getElementById('issueType').addEventListener('change', filterIssues);
            document.getElementById('issueDate').addEventListener('change', filterIssues);

            function getFilterParams() {
                const active = document.querySelector('.timetable-btn[data-tab].active');
                const tab = active ? active.dataset.tab : 'activity';
                const search = document.getElementById('reportsSearch')?.value || '';
                let type = '';
                let date = '';
                if (tab === 'activity') {
                    type = document.getElementById('activityType')?.value || '';
                    date = document.getElementById('activityDate')?.value || '';
                } else if (tab === 'rooms') {
                    type = document.getElementById('roomLightFilter')?.value || '';
                } else if (tab === 'issues') {
                    type = document.getElementById('issueType')?.value || '';
                    date = document.getElementById('issueDate')?.value || '';
                }
                return { tab, search, type, date };
            }

            function showExportModal(type) {
                const el = document.getElementById('exportConfirmModal');
                document.getElementById('exportModalIcon').className = 'bi ' + (type === 'csv' ? 'bi-filetype-csv' : 'bi-filetype-pdf');
                const { tab, search, type: ftype, date } = getFilterParams();
                const label = tab === 'rooms' ? 'Room Activity' : tab === 'issues' ? 'Issues Logged' : 'Recent Activity';
                document.getElementById('exportModalMsg').textContent = 'Export ' + label + ' as ' + type.toUpperCase() + '?';
                document.getElementById('exportConfirmBtn').onclick = function() {
                    const bs = bootstrap.Modal.getInstance(el);
                    if (bs) bs.hide();
                    if (type === 'csv') doExportCSV();
                    else {
                        var params = new URLSearchParams({ tab: tab });
                        if (search) params.set('search', search);
                        if (ftype) params.set('type', ftype);
                        if (date) params.set('date', date);
                        window.location.href = '../../api/export-report-pdf.php?' + params.toString();
                    }
                };
                new bootstrap.Modal(el).show();
            }

            function doExportCSV() {
                const active = document.querySelector('.timetable-btn[data-tab].active');
                const tab = active ? active.dataset.tab : 'activity';
                let rows = [];
                if (tab === 'rooms') {
                    rows = [['Room', 'Light Status', 'Size', 'Total Events', 'Last Activity', 'Description']];
                    document.querySelectorAll('#roomTable tbody .room-main-row').forEach(row => {
                        if (row.style.display === 'none') return;
                        rows.push([...row.querySelectorAll('td')].map(td => td.innerText.trim()));
                    });
                } else if (tab === 'issues') {
                    rows = [['Time', 'Issue', 'Room', 'Triggered By', 'Notes']];
                    document.querySelectorAll('#tab-issues .timeline-item').forEach(item => {
                        if (item.style.display === 'none') return;
                        const metaSpans = [...item.querySelectorAll('.tl-meta span')];
                        const timeStr = metaSpans[0]?.innerText.trim() ?? '';
                        const triggeredBy = metaSpans[1]?.innerText.trim() ?? '';
                        const actionEl = item.querySelector('.tl-action');
                        const actionText = actionEl?.innerText.trim().replace(/\u2014/g, '|') ?? '';
                        const parts = actionText.split('|').map(s => s.trim());
                        const issueLabel = parts[0] ?? '';
                        const roomName = parts[1]?.replace(/"/g, '') ?? '';
                        const tl_notes = item.querySelector('.tl-notes')?.innerText.trim() ?? '';
                        rows.push([timeStr, issueLabel, roomName, triggeredBy, tl_notes]);
                    });
                } else {
                    rows = [['Time', 'Action', 'Target', 'Actor', 'Type', 'Notes']];
                    document.querySelectorAll('#activityTimeline .timeline-item').forEach(item => {
                        if (item.style.display === 'none') return;
                        const tl_action = (item.querySelector('.tl-action')?.innerText.trim() ?? '').replace(/-/g, '-');
                        const tl_meta = [...item.querySelectorAll('.tl-meta span')].map(s => s.innerText.trim()).join(' | ');
                        const tl_notes = item.querySelector('.tl-notes')?.innerText.trim() ?? '';
                        rows.push([tl_meta, tl_action, '', '', item.dataset.type, tl_notes]);
                    });
                }
                const csv = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `report-${tab}-${new Date().toISOString().slice(0, 10)}.csv`;
                a.click();
            }

            window.exportCSV = function() { showExportModal('csv'); };
            window.exportPDF = function() { showExportModal('pdf'); };

            const EVENT_ICONS = {
                light_on:       ['bi-lightbulb-fill',      '#0f5132', '#d1e7dd'],
                light_off:      ['bi-lightbulb',            '#842029', '#f8d7da'],
                motion_detect:  ['bi-person-bounding-box',  '#084298', '#cfe2ff'],
                pir_motion:     ['bi-person-bounding-box',  '#084298', '#cfe2ff'],
                pir_stopped:    ['bi-person-bounding-box',  '#5a5a5a', '#e9ecef'],
                door_open:      ['bi-door-open-fill',       '#664d03', '#fff3cd'],
                door_close:     ['bi-door-closed-fill',     '#5a3a00', '#ffe5b4'],
                class_start:    ['bi-play-circle-fill',     '#0d6e3b', '#d1e7dd'],
                class_end:      ['bi-stop-circle',          '#6c4c00', '#fff3cd'],
                faculty_approved: ['bi-person-check-fill',  '#0f5132', '#d1e7dd'],
                faculty_pending:  ['bi-person-plus',        '#664d03', '#fff3cd'],
                issue_raised:   ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                issue_resolved: ['bi-check-circle-fill',   '#0f5132', '#d1e7dd'],
                admin_action:   ['bi-shield-check',        '#084298', '#cfe2ff'],
            };
            const DEFAULT_ICON = ['bi-clock-history', '#5a5a5a', '#e9ecef'];

            function getEventIcon(action) {
                const key = action.toLowerCase().replace(/\s+/g, '_');
                return EVENT_ICONS[key] || DEFAULT_ICON;
            }

            function renderActivityLog(logs) {
                const container = document.getElementById('activityTimeline');
                if (!logs.length) {
                    container.innerHTML = '<div class="empty-state"><i class="bi bi-journal-x"></i><p>No activity logs found. Events will appear here as they are recorded.</p></div>';
                    document.getElementById('activityPagination').style.display = 'none';
                    return;
                }
                container.innerHTML = logs.map(log => {
                    const [icon, iconColor, iconBg] = getEventIcon(log.action);
                    const isRoom = log.log_type === 'room';
                    const typeBg = isRoom ? '#ede6f2' : '#4a0078';
                    const typeClr = isRoom ? '#4a0078' : '#ede6f2';
                    const typeLabel = isRoom ? 'Room' : 'Admin';
                    const d = new Date(log.log_time);
                    const dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' });
                    const timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
                    const dateVal = d.toISOString().slice(0, 10);
                    const searchVal = (log.target + ' ' + log.actor + ' ' + log.action).toLowerCase().replace(/"/g, '&quot;');
                    const actionLabel = log.action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).replace('Pir ', 'PIR ');
                    return `<div class="timeline-item" data-type="${log.log_type}" data-action="${log.action}" data-date="${dateVal}" data-search="${searchVal}">
                        <div class="tl-icon" style="background:${iconBg}; color:${iconColor};"><i class="bi ${isRoom ? 'bi-door-open' : icon}"></i></div>
                        <div class="tl-body">
                            <p class="tl-action">${actionLabel}${log.target ? ' &mdash; <span style="color:var(--secondary-color-3);">' + log.target.replace(/"/g, '&quot;') + '</span>' : ''}</p>
                            <div class="tl-meta">
                                <span><i class="bi bi-clock"></i> ${timeStr}, ${dateStr}</span>
                                ${log.actor ? '<span><i class="bi bi-person"></i> ' + log.actor.replace(/"/g, '&quot;') + '</span>' : ''}
                                <span class="tl-type-badge" style="background:${typeBg}; color:${typeClr};">${typeLabel}</span>
                            </div>
                            ${log.notes ? '<span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i>' + log.notes.replace(/"/g, '&quot;') + '</span>' : ''}
                        </div>
                    </div>`;
                }).join('');
            }

            function updateStats(stats) {
                const cards = document.querySelectorAll('.stat-card');
                if (cards.length >= 3) {
                    cards[0].dataset.aVal = stats.total_logs;
                    cards[1].dataset.aVal = stats.total_rooms;
                    cards[2].dataset.aVal = stats.lights_on;
                    cards[0].dataset.iVal = (stats.issue_raised + stats.issue_resolved) || 0;
                    cards[1].dataset.iVal = stats.issue_raised || 0;
                    cards[2].dataset.iVal = stats.issue_resolved || 0;
                    var active = document.querySelector('.tab-btn.active, .timetable-btn[data-tab].active');
                    var tab = active ? active.dataset.tab : 'activity';
                    if (tab === 'rooms') {
                        cards[0].querySelector('.stat-value').textContent = cards[0].dataset.rVal;
                        cards[1].querySelector('.stat-value').textContent = cards[1].dataset.rVal;
                        cards[2].querySelector('.stat-value').textContent = cards[2].dataset.rVal;
                    } else if (tab === 'issues') {
                        cards[0].querySelector('.stat-value').textContent = cards[0].dataset.iVal;
                        cards[1].querySelector('.stat-value').textContent = cards[1].dataset.iVal;
                        cards[2].querySelector('.stat-value').textContent = cards[2].dataset.iVal;
                    } else {
                        cards[0].querySelector('.stat-value').textContent = stats.total_logs;
                        cards[1].querySelector('.stat-value').textContent = stats.total_rooms;
                        cards[2].querySelector('.stat-value').textContent = stats.lights_on;
                    }
                }
            }

            function reapplyFilters() {
                var active = document.querySelector('.timetable-btn[data-tab].active');
                if (!active) return;
                if (active.dataset.tab === 'activity') {
                    actPage = 1;
                    filterActivity();
                } else if (active.dataset.tab === 'issues') {
                    filterIssues();
                } else {
                    filterRooms();
                }
            }

            function pollActivityLog() {
                fetch('../../api/activity-logs.php')
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            renderActivityLog(res.data);
                            if (res.stats) updateStats(res.stats);
                            reapplyFilters();
                        }
                    })
                    .catch(() => {});
            }
            setInterval(pollActivityLog, 30000);

        });

        function toggleRoomAccordion(row) {
            var chevron = row.querySelector('.room-chevron');
            var accordionRow = row.nextElementSibling;
            if (!accordionRow || !accordionRow.classList.contains('room-accordion-row')) return;
            var isOpen = accordionRow.style.display !== 'none';
            accordionRow.style.display = isOpen ? 'none' : 'table-row';
            if (chevron) chevron.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
            if (!isOpen && accordionRow.querySelector('.room-accordion-content').dataset.loaded !== '1') {
                var room = row.dataset.room;
                var content = accordionRow.querySelector('.room-accordion-content');
                content.innerHTML = 'Loading...';
                fetch('../../api/get-room-logs.php?room=' + encodeURIComponent(room))
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.success && res.data.length) {
                            var html = '<div class="accordion-search-wrap"><input type="text" class="accordion-search" placeholder="Search this room\'s logs..." /></div>';
                            html += '<div class="accordion-log-list">';
                            res.data.forEach(function(log) {
                                var d = new Date(log.event_time);
                                var dateStr = d.toLocaleDateString('en-US', { timeZone: 'Asia/Manila', month: 'short', day: 'numeric', year: 'numeric' });
                                var timeStr = d.toLocaleTimeString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit', hour12: true });
                                var iconMap = {
                                    light_on: 'bi-lightbulb-fill', light_off: 'bi-lightbulb',
                                    motion_detect: 'bi-person-bounding-box', pir_motion: 'bi-person-bounding-box', pir_stopped: 'bi-person-bounding-box', door_open: 'bi-door-open-fill',
                                    door_close: 'bi-door-closed-fill', class_start: 'bi-play-circle-fill',
                                    class_end: 'bi-stop-circle', issue_raised: 'bi-exclamation-triangle-fill',
                                    issue_resolved: 'bi-check-circle-fill'
                                };
                                var icon = iconMap[log.event_type] || 'bi-clock-history';
                                html += '<div class="accordion-log-item">';
                                html += '<span class="accordion-log-icon"><i class="bi ' + icon + '"></i></span>';
                                html += '<span class="accordion-log-action">' + log.event_type.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()).replace('Pir ', 'PIR ') + '</span>';
                                html += '<span class="accordion-log-time">' + timeStr + ', ' + dateStr + '</span>';
                                if (log.triggered_by) html += '<span class="accordion-log-actor">by ' + log.triggered_by + '</span>';
                                if (log.notes) html += '<span class="accordion-log-notes">' + log.notes + '</span>';
                                html += '</div>';
                            });
                            html += '</div>';
                            content.innerHTML = html;
                            var searchInput = content.querySelector('.accordion-search');
                            var logList = content.querySelector('.accordion-log-list');
                            if (searchInput && logList) {
                                searchInput.addEventListener('input', function() {
                                    var q = this.value.toLowerCase();
                                    var items = logList.querySelectorAll('.accordion-log-item');
                                    for (var i = 0; i < items.length; i++) {
                                        items[i].style.display = items[i].textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
                                    }
                                });
                            }
                        } else {
                            content.innerHTML = '<div style="padding:12px;text-align:center;color:#999;font-size:13px;">No recent activity for this room.</div>';
                        }
                        content.dataset.loaded = '1';
                    })
                    .catch(function() {
                        content.innerHTML = '<div style="padding:12px;text-align:center;color:#c00;font-size:13px;">Failed to load activities.</div>';
                    });
            }
        }
