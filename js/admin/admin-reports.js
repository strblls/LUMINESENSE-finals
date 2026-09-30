        document.addEventListener('DOMContentLoaded', function() {

            const ACT_PAGE_SIZE = 10;
            let actPage = 1;

            // Single source of truth for landing/panel visibility: the `hidden`
            // attribute (enforced by CSS with !important, immune to other
            // stylesheets). Inline `display` styles are never used here.
            function setHidden(id, hide) {
                var el = document.getElementById(id);
                if (!el) return;
                el.hidden = !!hide;
                el.style.display = '';
            }

            function currentView() {
                if (!document.getElementById('panel-faculty')?.hidden) return 'faculty';
                if (!document.getElementById('panel-status')?.hidden) return 'status';
                return 'landing';
            }

            window.showReportPanel = function(panel, subTab) {
                // Exiting one tab clears its selections and searches
                if (panel === 'status') resetFacultyFilters();
                else resetStatusFilters();
                setHidden('reportLanding', true);
                setHidden('reportToolbar', false);
                setHidden('panel-faculty', panel !== 'faculty');
                setHidden('panel-status', panel !== 'status');
                document.getElementById('reportBackBtn').style.display = '';
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', panel === 'status' ? (subTab || 'activity') : 'faculty');
                    window.history.replaceState(null, '', url.toString());
                } catch (e) { /* ignore */ }
                if (panel === 'status') {
                    actPage = 1;
                    filterStatus();
                } else if (panel === 'faculty') {
                    filterFaculty();
                }
            };

            window.showReportLanding = function() {
                // Back clears every selection and search
                resetStatusFilters();
                resetFacultyFilters();
                setHidden('reportLanding', false);
                setHidden('reportToolbar', true);
                setHidden('panel-faculty', true);
                setHidden('panel-status', true);
                document.getElementById('reportBackBtn').style.display = 'none';
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.delete('tab');
                    window.history.replaceState(null, '', url.toString());
                } catch (e) { /* ignore */ }
            };

            // Unified Status view: a single table, no sub-tabs.
            // switchTab is kept as a shim (old deep links, tutorial).
            function switchTab(tab) {
                if (document.getElementById('panel-status')?.hidden) {
                    window.showReportPanel('status');
                    return;
                }
                actPage = 1;
                filterStatus();
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', 'status');
                    window.history.replaceState(null, '', url.toString());
                } catch (e) { /* ignore */ }
            }

            // Explicit init: enforce a known-visible state on every load.
            // No ?tab= -> landing (toolbar + panels stay hidden).
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab');
            if (tabParam === 'faculty') {
                window.showReportPanel('faculty');
            } else if (['status', 'activity', 'rooms', 'issues'].includes(tabParam)) {
                window.showReportPanel('status');
            } else {
                window.showReportLanding();
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

            function applySearchForCurrentView() {
                if (currentView() === 'faculty') { filterFaculty(); return; }
                if (currentView() !== 'status') return;
                actPage = 1;
                filterStatus();
            }

            const reportsSearch = document.getElementById('reportsSearch');
            if (reportsSearch) {
                reportsSearch.addEventListener('input', applySearchForCurrentView);
            }

            const globalSearch = document.getElementById('globalSearch');
            if (globalSearch) {
                globalSearch.addEventListener('input', function() {
                    var reportsSearch = document.getElementById('reportsSearch');
                    if (reportsSearch) reportsSearch.value = this.value;
                    applySearchForCurrentView();
                });
            }

            function filterFaculty() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const status = document.getElementById('facultyStatusFilter')?.value || '';
                document.querySelectorAll('#facultyTable tbody .faculty-main-row').forEach(row => {
                    const matchQ = !q || (row.dataset.search && row.dataset.search.includes(q));
                    const matchStatus = !status || row.dataset.status === status;
                    row.style.display = (matchQ && matchStatus) ? '' : 'none';
                });
            }

            const ISSUE_ACTIONS = ['issue_raised', 'issue_resolved', 'tilt_alert'];

            function filterStatus() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const type = document.getElementById('statusType')?.value || '';
                const actor = document.getElementById('statusActor')?.value || '';
                const source = document.getElementById('statusSource')?.value || '';
                const date = document.getElementById('statusDate')?.value || '';
                const today = new Date().toISOString().slice(0, 10);
                const weekAgo = new Date(Date.now() - 7 * 86400000).toISOString().slice(0, 10);
                const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);

                const rows = document.querySelectorAll('#statusTable tbody .status-row');

                rows.forEach(row => {
                    const matchQ = !q || (row.dataset.search && row.dataset.search.includes(q));
                    const matchActor = !actor || (row.dataset.actor || '') === actor;
                    const matchSource = !source || (row.dataset.source || '') === source;
                    let matchType = true;
                    const action = row.dataset.action || '';
                    if (type === 'anomaly') {
                        matchType = ISSUE_ACTIONS.includes(action);
                    } else if (type === 'pir') {
                        matchType = action.startsWith('pir_');
                    } else if (type === 'class') {
                        matchType = action.startsWith('class_');
                    } else if (type === 'room') {
                        matchType = row.dataset.type === 'room' && !ISSUE_ACTIONS.includes(action);
                    } else if (type === 'admin') {
                        matchType = row.dataset.type === 'admin';
                    }
                    let matchDate = true;
                    if (date === 'today') matchDate = row.dataset.date === today;
                    if (date === 'week') matchDate = row.dataset.date >= weekAgo;
                    if (date === 'month') matchDate = row.dataset.date >= monthAgo;
                    row.dataset.filtered = (matchQ && matchActor && matchSource && matchType && matchDate) ? '1' : '0';
                });

                const filtered = [...rows].filter(r => r.dataset.filtered === '1');

                // KPIs reflect the current search + dropdown selection
                var kpiRoom = document.getElementById('kpiRoomActions');
                var kpiAnom = document.getElementById('kpiAnomalies');
                var kpiAdm = document.getElementById('kpiAdminActions');
                if (kpiRoom) kpiRoom.textContent = filtered.filter(r => r.dataset.type !== 'admin').length;
                if (kpiAdm) kpiAdm.textContent = filtered.filter(r => r.dataset.type === 'admin').length;
                if (kpiAnom) kpiAnom.textContent = filtered.filter(r => ISSUE_ACTIONS.includes(r.dataset.action || '')).length;

                const totalPages = Math.max(1, Math.ceil(filtered.length / ACT_PAGE_SIZE));
                if (actPage > totalPages) actPage = totalPages;

                const start = (actPage - 1) * ACT_PAGE_SIZE;
                rows.forEach(row => {
                    if (row.dataset.filtered === '0') {
                        row.style.display = 'none';
                    } else {
                        const idx = filtered.indexOf(row);
                        row.style.display = (idx >= start && idx < start + ACT_PAGE_SIZE) ? '' : 'none';
                    }
                });

                const pageInfo = document.getElementById('activityPageInfo');
                const prevBtn = document.getElementById('activityPrev');
                const nextBtn = document.getElementById('activityNext');
                if (pageInfo) pageInfo.textContent = 'Page ' + actPage + ' of ' + totalPages;
                if (prevBtn) prevBtn.disabled = actPage <= 1;
                if (nextBtn) nextBtn.disabled = actPage >= totalPages;
            }

            window.goActivityPage = function(dir) {
                actPage += dir;
                if (currentView() === 'faculty') { filterFaculty(); return; }
                filterStatus();
            };

            function statusFiltersActive() {
                if ((document.getElementById('reportsSearch')?.value || '') !== '') return true;
                return ['statusType', 'statusActor', 'statusSource', 'statusDate']
                    .some(id => (document.getElementById(id)?.value || '') !== '');
            }

            function refreshClearButtons() {
                document.querySelectorAll('.filter-clear').forEach(btn => {
                    var sel = document.getElementById(btn.dataset.clear);
                    btn.hidden = !sel || sel.value === '';
                });
            }

            function resetStatusFilters() {
                var search = document.getElementById('reportsSearch');
                if (search) search.value = '';
                ['statusType', 'statusActor', 'statusSource', 'statusDate'].forEach(id => {
                    var sel = document.getElementById(id);
                    if (sel) sel.value = '';
                });
                actPage = 1;
                refreshClearButtons();
            }

            function resetFacultyFilters() {
                var search = document.getElementById('reportsSearch');
                if (search) search.value = '';
                var sel = document.getElementById('facultyStatusFilter');
                if (sel) sel.value = '';
            }

            document.querySelectorAll('.filter-clear').forEach(btn => {
                btn.addEventListener('click', () => {
                    var sel = document.getElementById(btn.dataset.clear);
                    if (sel) sel.value = '';
                    actPage = 1;
                    refreshClearButtons();
                    if (currentView() === 'faculty') filterFaculty();
                    else filterStatus();
                });
            });
            document.getElementById('statusType')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusActor')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusSource')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusDate')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('facultyStatusFilter')?.addEventListener('change', filterFaculty);

            function getFilterParams() {
                if (currentView() === 'faculty') {
                    return {
                        tab: 'faculty',
                        search: document.getElementById('reportsSearch')?.value || '',
                        type: document.getElementById('facultyStatusFilter')?.value || '',
                        date: ''
                    };
                }
                const search = document.getElementById('reportsSearch')?.value || '';
                return {
                    tab: 'status',
                    search,
                    type: document.getElementById('statusType')?.value || '',
                    actor: document.getElementById('statusActor')?.value || '',
                    source: document.getElementById('statusSource')?.value || '',
                    date: document.getElementById('statusDate')?.value || ''
                };
            }

            function showExportModal(type) {
                const el = document.getElementById('exportConfirmModal');
                document.getElementById('exportModalIcon').className = 'bi ' + (type === 'csv' ? 'bi-filetype-csv' : 'bi-filetype-pdf');
                const { tab, search, type: ftype, actor: factor, source: fsource, date } = getFilterParams();
                const label = tab === 'faculty' ? 'Faculty Reports' : 'Status Reports';
                document.getElementById('exportModalMsg').textContent = 'Export ' + label + ' as ' + type.toUpperCase() + '?';
                document.getElementById('exportConfirmBtn').onclick = function() {
                    const bs = bootstrap.Modal.getInstance(el);
                    if (bs) bs.hide();
                    if (type === 'csv') doExportCSV();
                    else {
                        var params = new URLSearchParams({ tab: tab });
                        if (search) params.set('search', search);
                        if (ftype) params.set('type', ftype);
                        if (factor) params.set('actor', factor);
                        if (fsource) params.set('source', fsource);
                        if (date) params.set('date', date);
                        window.location.href = '../../api/export-report-pdf.php?' + params.toString();
                    }
                };
                new bootstrap.Modal(el).show();
            }

            function doExportCSV() {
                if (currentView() === 'faculty') {
                    const rows = [['Faculty', 'Email', 'Status', 'Schedules', 'Extensions', 'Lighting Events', 'Last Activity']];
                    document.querySelectorAll('#facultyTable tbody .faculty-main-row').forEach(row => {
                        if (row.style.display === 'none') return;
                        rows.push([...row.querySelectorAll('td')].map(td => td.innerText.trim()));
                    });
                    const csv = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = `report-faculty-${new Date().toISOString().slice(0, 10)}.csv`;
                    a.click();
                    return;
                }
                const rows = [['Action', 'Action Type', 'Actor', 'Source', 'Description', 'Date and Time', 'Notes']];
                document.querySelectorAll('#statusTable tbody .status-row').forEach(row => {
                    if (row.style.display === 'none') return;
                    rows.push([...row.querySelectorAll('td')].map(td => td.innerText.trim()));
                });
                const csv = rows.map(r => r.map(c => `"${c.replace(/"/g, '""')}"`).join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `report-status-${new Date().toISOString().slice(0, 10)}.csv`;
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
                tilt_alert:     ['bi-exclamation-octagon-fill', '#7f1d1d', '#fee2e2'],
                admin_action:   ['bi-shield-check',        '#084298', '#cfe2ff'],
            };
            const DEFAULT_ICON = ['bi-clock-history', '#5a5a5a', '#e9ecef'];

            function getEventIcon(action) {
                const key = action.toLowerCase().replace(/\s+/g, '_');
                return EVENT_ICONS[key] || DEFAULT_ICON;
            }

            function renderActivityLog(logs) {
                // Timeline view was replaced by the unified status table
                // (server-rendered). Poll only refreshes KPI counts now.
                const container = document.getElementById('activityTimeline');
                if (!container) return;
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

            function updateStats(res) {
                // Never overwrite KPIs that reflect an active search/filter
                if (currentView() === 'status' && statusFiltersActive()) return;
                const stats = res.stats || {};
                const logs = res.data || [];
                const roomActions = logs.filter(l => l.log_type !== 'admin').length;
                const adminActions = logs.filter(l => l.log_type === 'admin').length;
                const anomalies = (stats.issue_raised || 0) + (stats.issue_resolved || 0);
                var roomEl = document.getElementById('kpiRoomActions');
                var anomEl = document.getElementById('kpiAnomalies');
                var admEl = document.getElementById('kpiAdminActions');
                if (roomEl && logs.length) roomEl.textContent = roomActions;
                if (admEl && logs.length) admEl.textContent = adminActions;
                if (anomEl && (stats.issue_raised !== undefined || stats.issue_resolved !== undefined)) anomEl.textContent = anomalies;
            }

            function reapplyFilters() {
                if (currentView() === 'faculty') { filterFaculty(); return; }
                if (currentView() !== 'status') return;
                actPage = 1;
                filterStatus();
            }

            function pollActivityLog() {
                fetch('../../api/activity-logs.php')
                    .then(r => r.json())
                    .then(res => {
                        if (res.success) {
                            renderActivityLog(res.data);
                            updateStats(res);
                            reapplyFilters();
                        }
                    })
                    .catch(() => {});
            }
            setInterval(pollActivityLog, 30000);

        });
