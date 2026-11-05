        document.addEventListener('DOMContentLoaded', function() {

            const ACT_PAGE_SIZE = 10;
            let actPage = 1;
            let facultyPage = 1;
            let facultySort = { key: 'gesture_count', direction: -1 };
            let facultyRows = [];
            let facultyQuery = null;
            let facultyRequest = null;
            let facultyDetailsRequest = null;

            /* ── Dynamic date filter: server-driven Status Reports ──
               Declared up front: showReportPanel()/resetStatusFilters() can run
               during this handler, before the rest of the body executes. */
            let statusServerActive = false;
            let statusFetchTimer = null;
            let statusAbort = null;
            let statusReqId = 0;

            /* Cross-filter state, set by clicking chart elements or legend
               items (multi-select supported). Owned by status-charts.js and
               mirrored here so table, KPIs and all four charts always
               refresh from a single api/status-report.php response.
               Shape: { durations: [{value, series}], types: [...], ... } */
            let chartFilters = emptyChartFilters();

            function emptyChartFilters() {
                return { durations: [], types: [], perRoom: [], toggles: [] };
            }

            function chartFiltersActive() {
                return Object.keys(chartFilters).some(function (k) {
                    return chartFilters[k] && chartFilters[k].length > 0;
                });
            }

            function chartFilterPayload() {
                var out = {};
                Object.keys(chartFilters).forEach(function (k) {
                    if (chartFilters[k] && chartFilters[k].length) {
                        out[k] = chartFilters[k].map(function (p) {
                            return { value: p.value, series: p.series };
                        });
                    }
                });
                return out;
            }

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

            function setTopbarTitle(text) {
                const el = document.getElementById('topbarTitle');
                if (el) el.textContent = text;
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
                document.getElementById('reportExportPdf').classList.toggle('d-none', panel === 'faculty');
                document.getElementById('reportsSearch').placeholder = panel === 'faculty'
                    ? 'Search faculty name…'
                    : 'Search action, type, actor, source, room, date, notes...';
                try {
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', panel === 'status' ? (subTab || 'activity') : 'faculty');
                    window.history.replaceState(null, '', url.toString());
                } catch (e) { /* ignore */ }
                if (panel === 'status') {
                    actPage = 1;
                    document.getElementById('reportExportCsv').disabled = false;
                    setTopbarTitle('Status Reports');
                    if (window.initStatusCharts) window.initStatusCharts();
                    filterStatus();
                } else if (panel === 'faculty') {
                    setTopbarTitle('Faculty Reports');
                    if (!document.getElementById('facultyReportStart').value) resetFacultyFilters();
                    loadFacultyReport();
                }
            };

            window.showReportLanding = function() {
                // Back clears every selection and search
                resetStatusFilters();
                resetFacultyFilters();
                setTopbarTitle('Report Management');
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
                if (currentView() === 'faculty') {
                    facultyPage = 1;
                    filterFaculty();
                    if (facultyQuery) window.updateFacultyCharts?.(sortedFacultyRows());
                    return;
                }
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
                if (!facultyQuery) return;
                const rows = sortedFacultyRows();
                const columns = Array.from(document.querySelectorAll('#facultyTable .faculty-sort-button'));
                const totalPages = Math.max(1, Math.ceil(rows.length / ACT_PAGE_SIZE));
                facultyPage = Math.min(facultyPage, totalPages);
                const tbody = document.querySelector('#facultyTable tbody');
                tbody.replaceChildren();
                rows.slice((facultyPage - 1) * ACT_PAGE_SIZE, facultyPage * ACT_PAGE_SIZE).forEach(row => {
                    const tr = tbody.insertRow();
                    const button = document.createElement('button');
                    button.className = 'faculty-detail-button';
                    button.type = 'button';
                    button.textContent = facultyValue(row, 'name');
                    button.addEventListener('click', () => showFacultyDetails(row, button));
                    tr.insertCell().append(button);
                    columns.slice(1).forEach(column => {
                        const key = column.dataset.metric;
                        const cell = tr.insertCell();
                        cell.textContent = facultyValue(row, key);
                        if (typeof row[key] === 'number' && !['ui_share', 'early_rate', 'extension_rate', 'median_minutes_early'].includes(key)) {
                            const badge = document.createElement('span');
                            badge.className = 'event-count-badge';
                            badge.textContent = cell.textContent;
                            cell.replaceChildren(badge);
                        }
                    });
                });
                document.getElementById('facultyComparison').hidden = rows.length === 0;
                document.getElementById('reportExportCsv').disabled = rows.length === 0;
                setText('facultyReportStatus', rows.length ? '' : (
                    facultyRows.length ? 'No faculty matches your search.' : 'No current faculty records found.'
                ));
                setText('facultyReportPage', 'Page ' + facultyPage + ' of ' + totalPages);
                document.getElementById('facultyReportPrev').disabled = facultyPage === 1;
                document.getElementById('facultyReportNext').disabled = facultyPage === totalPages;
                columns.forEach(button => {
                    const active = button.dataset.metric === facultySort.key;
                    const header = button.closest('th');
                    if (active) header.setAttribute('aria-sort', facultySort.direction === 1 ? 'ascending' : 'descending');
                    else header.removeAttribute('aria-sort');
                    button.querySelector('i').className = 'bi ' + (
                        active ? (facultySort.direction === 1 ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up'
                    );
                });
            }

            function sortedFacultyRows() {
                const q = document.getElementById('reportsSearch').value.trim().toLowerCase();
                const { key, direction } = facultySort;
                return facultyRows.filter(row => (row.name || '').toLowerCase().includes(q)).sort((a, b) => {
                    const left = a[key];
                    const right = b[key];
                    if (left == null && right != null) return 1;
                    if (right == null && left != null) return -1;
                    const order = typeof left === 'number' && typeof right === 'number'
                        ? left - right
                        : String(left ?? '').localeCompare(String(right ?? ''));
                    return direction * order || String(a.name).localeCompare(String(b.name));
                });
            }

            function facultyValue(row, key) {
                const value = row[key];
                if (key === 'name') return value || 'Unnamed Faculty';
                if (value == null || value === '') return '—';
                if (['ui_share', 'early_rate', 'extension_rate'].includes(key)) {
                    return new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 }).format(value) + '%';
                }
                if (key === 'top_gesture') return formatFacultyEvent(value);
                return String(value);
            }

            function formatFacultyEvent(value) {
                return String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase()) || '—';
            }

            async function requestFacultyReport(params, signal) {
                const response = await fetch('../../api/faculty-report.php?' + params, { signal });
                const data = await response.json();
                if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load faculty activity.');
                return data;
            }

            async function loadFacultyReport() {
                const room = document.getElementById('facultyReportRoom');
                const start = document.getElementById('facultyReportStart').value;
                const end = document.getElementById('facultyReportEnd').value;
                const status = document.getElementById('facultyReportStatus');
                facultyRequest?.abort();
                facultyQuery = null;
                document.getElementById('facultyComparison').hidden = true;
                document.getElementById('facultyReportGraphs').hidden = true;
                document.getElementById('facultyPirReport').hidden = true;
                document.getElementById('facultyReportNotices').hidden = true;
                document.getElementById('reportExportCsv').disabled = true;
                ['facultyRosterCount', 'facultyGestureCount', 'facultyToggleCount'].forEach(id => setText(id, '—'));
                if (!start || !end || start > end) {
                    status.textContent = 'Choose a valid date range. The end date must be on or after the start date.';
                    return;
                }
                const request = new AbortController();
                facultyRequest = request;
                const params = new URLSearchParams({ start, end });
                if (room.value) params.set('room_id', room.value);
                status.textContent = 'Loading faculty activity…';
                try {
                    const data = await requestFacultyReport(params, request.signal);
                    if (request.signal.aborted) return;
                    const selectedRoom = params.get('room_id') || '';
                    room.replaceChildren(new Option('All Rooms', ''));
                    data.rooms.forEach(item => room.add(new Option(item.name, item.id)));
                    room.value = selectedRoom;
                    facultyRows = data.faculty;
                    facultyQuery = params;
                    facultyPage = 1;
                    setText('facultyRosterCount', facultyRows.length);
                    [['facultyGestureCount', 'gesture_count'], ['facultyToggleCount', 'ui_toggles']].forEach(([id, metric]) => {
                        const counts = facultyRows.map(row => row[metric]).filter(value => value != null);
                        setText(id, counts.length ? counts.reduce((sum, count) => sum + Number(count), 0) : '—');
                    });
                    filterFaculty();
                    window.updateFacultyCharts?.(sortedFacultyRows());
                    renderFacultyPir(data.pir);
                    const notes = document.getElementById('facultyReportNotices');
                    notes.replaceChildren(...(data.notices || []).map(notice => {
                        const item = document.createElement('li');
                        const description = document.createElement('span');
                        description.className = 'legend-guide-desc';
                        description.textContent = notice;
                        item.append(description);
                        return item;
                    }));
                    notes.hidden = !notes.childElementCount;
                } catch (error) {
                    if (!request.signal.aborted) status.textContent = error.message || 'Unable to load faculty activity.';
                }
            }

            function renderFacultyPir(rows) {
                const table = document.getElementById('facultyPirTable');
                const tbody = table.tBodies[0];
                tbody.replaceChildren();
                rows.forEach(row => {
                    const tr = tbody.insertRow();
                    [row.room, row.hour, row.scheduled, row.unscheduled].forEach(value => {
                        tr.insertCell().textContent = value ?? '—';
                    });
                });
                table.hidden = rows.length === 0;
                document.getElementById('facultyPirEmpty').hidden = rows.length > 0;
                document.getElementById('facultyPirReport').hidden = false;
            }

            function renderFacultyDetailSummary(data, row) {
                const container = document.getElementById('facultyDetailsBreakdown');
                const descriptions = new Map(Array.from(document.querySelectorAll('#facultyTable .faculty-sort-button'),
                    button => [button.dataset.metric, button.dataset.description]));
                descriptions.set('classes_with_extensions', 'Number of class occurrences with at least one recorded extension request.');
                const methods = {
                    active_days: 'Count each date once across the sign-in and sign-out logs. Account activity is available for All Rooms.',
                    sign_ins: 'Count successful sign-in events. Account activity is available for All Rooms.',
                    sign_outs: 'Count explicit sign-out events. Account activity is available for All Rooms.',
                    gesture_count: 'Count confirmed gesture entries in the lighting log.',
                    top_gesture: 'Group confirmed gesture entries by saved name and select the highest count.',
                    ui_toggles: 'Count lighting log entries with an ON or OFF state and a manual source.',
                    ui_share: 'UI toggles ÷ (UI toggles + recorded gesture events) × 100.',
                    early_endings: 'Count lighting log entries recorded when the faculty member ends a class early.',
                    early_rate: 'Matched early endings ÷ matched recorded class endings × 100.',
                    median_minutes_early: 'Take the median of scheduled end time minus recorded early end time for matched classes.',
                    extension_requests: 'Count saved extension requests, including archived requests.',
                    classes_with_extensions: 'Count each schedule and class date once across the recorded extension requests.',
                    extension_rate: 'Classes with a matched extension request ÷ scheduled class occurrences × 100.'
                };
                const categories = [
                    ['Account Activity', [['Active days', 'active_days'], ['Sign-ins', 'sign_ins'], ['Sign-outs', 'sign_outs']]],
                    ['Gesture Controls', [['Gestures', 'gesture_count'], ['Top gesture', 'top_gesture']]],
                    ['UI Light Controls', [['Toggles', 'ui_toggles'], ['UI Share (UI vs. Gestures)', 'ui_share']]],
                    ['Early Class Endings', [['Early endings', 'early_endings'], ['Early ending rate', 'early_rate'], ['Median minutes early', 'median_minutes_early']]],
                    ['Extension Requests', [['Requests', 'extension_requests'], ['Classes with requests', 'classes_with_extensions'], ['Extension rate', 'extension_rate']]]
                ];
                container.replaceChildren(...categories.map(([name, metrics]) => {
                    const column = document.createElement('div');
                    column.className = 'col-sm-6 col-lg-4';
                    const card = document.createElement('section');
                    card.className = 'faculty-detail-category';
                    const title = document.createElement('h6');
                    title.textContent = name;
                    const list = document.createElement('div');
                    list.className = 'faculty-detail-metrics';
                    metrics.forEach(([label, key]) => {
                        const pill = document.createElement('div');
                        pill.className = 'mini-stat-pill kpi-expandable';
                        pill.tabIndex = 0;
                        const top = document.createElement('div');
                        top.className = 'kpi-top';
                        const term = document.createElement('small');
                        const value = document.createElement('b');
                        term.textContent = label;
                        value.textContent = facultyValue(row, key);
                        const breakdown = document.createElement('ul');
                        breakdown.className = 'kpi-breakdown';
                        [descriptions.get(key), methods[key]].forEach(text => {
                            const item = document.createElement('li');
                            item.textContent = text;
                            breakdown.append(item);
                        });
                        top.append(value, term);
                        pill.append(top, breakdown);
                        list.append(pill);
                    });
                    card.append(title, list);
                    column.append(card);
                    return column;
                }));
                const timing = data.active_time;
                setText('facultyDetailsUnknown', 'Unknown gesture: ' + data.unknown_gestures
                    + (data.gestures.length ? '' : ' · No named gestures recorded'));
                setText('facultyDetailsCoverage', timing.matched + ' of ' + timing.scheduled
                    + ' scheduled classes represented · No recorded lights-on action: ' + (timing.scheduled - timing.matched)
                    + ' · Unmatched lights-on actions: ' + timing.unmatched);
                if (typeof Chart === 'undefined') return [];
                const options = { responsive: true, maintainAspectRatio: false };
                return [
                    new Chart(document.getElementById('facultyDetailsGestures'), {
                        type: 'doughnut', options,
                        data: {
                            labels: data.gestures.map(gesture => formatFacultyEvent(gesture.name)),
                            datasets: [{
                                data: data.gestures.map(gesture => gesture.count),
                                backgroundColor: ['#733e91', '#f8c64f', '#4b8298', '#bc77a0', '#699876', '#d87552', '#8790c8']
                            }]
                        }
                    }),
                    new Chart(document.getElementById('facultyDetailsActiveTime'), {
                        type: 'bar',
                        options: { ...options, scales: { x: { stacked: true }, y: { stacked: true, ticks: { precision: 0 } } } },
                        data: {
                            labels: timing.buckets.map(bucket => bucket.minute < 0
                                ? 'Before class' : bucket.minute + '–' + (bucket.minute + 9) + ' min'),
                            datasets: [
                                { label: 'Gesture', data: timing.buckets.map(bucket => bucket.gesture), backgroundColor: '#733e91' },
                                { label: 'UI toggle', data: timing.buckets.map(bucket => bucket.manual), backgroundColor: '#f8c64f' }
                            ]
                        }
                    })
                ];
            }

            async function showFacultyDetails(row, trigger) {
                if (!facultyQuery) return;
                facultyDetailsRequest?.abort();
                const request = new AbortController();
                facultyDetailsRequest = request;
                const dialog = document.getElementById('facultyReportDetails');
                const status = document.getElementById('facultyDetailsStatus');
                const content = document.getElementById('facultyDetailsContent');
                const form = document.getElementById('facultyDetailsEventFilters');
                const fields = form.elements;
                const table = document.getElementById('facultyDetailsEvents');
                const empty = document.getElementById('facultyDetailsEmpty');
                const params = new URLSearchParams(facultyQuery);
                params.set('faculty_id', row.id);
                let charts = [];
                let events = [];
                let eventRequest;
                let page = 1;
                const pageSize = 25;
                const navigation = document.createElement('div');
                navigation.className = 'activity-pagination';
                const previous = document.createElement('button');
                const next = document.createElement('button');
                const pageStatus = document.createElement('span');
                pageStatus.id = 'facultyDetailsPage';
                pageStatus.setAttribute('aria-live', 'polite');
                const eventStatus = document.createElement('p');
                eventStatus.setAttribute('role', 'status');
                previous.type = next.type = 'button';
                previous.textContent = '« Prev';
                next.textContent = 'Next »';
                navigation.append(previous, pageStatus, next);
                table.parentElement.after(navigation);
                form.after(eventStatus);

                function renderEvents() {
                    const search = fields.search.value.trim().toLowerCase();
                    const matches = events.filter(event => [event.room, event.type, event.description, event.source]
                        .some(value => String(value ?? '').replaceAll('_', ' ').toLowerCase().includes(search)));
                    const pages = Math.max(1, Math.ceil(matches.length / pageSize));
                    const tbody = table.tBodies[0];
                    tbody.replaceChildren();
                    matches.slice((page - 1) * pageSize, page * pageSize).forEach(event => {
                        const tr = tbody.insertRow();
                        [event.time, event.room, formatFacultyEvent(event.type), event.description].forEach(value => {
                            tr.insertCell().textContent = value || '—';
                        });
                    });
                    table.hidden = !matches.length;
                    empty.hidden = !!matches.length;
                    navigation.hidden = pages < 2;
                    previous.disabled = page <= 1;
                    next.disabled = page >= pages;
                    pageStatus.textContent = 'Page ' + page + ' of ' + pages;
                }

                previous.onclick = () => { page--; renderEvents(); };
                next.onclick = () => { page++; renderEvents(); };
                fields.search.oninput = () => { page = 1; renderEvents(); };
                form.onsubmit = async event => {
                    event.preventDefault();
                    eventRequest?.abort();
                    if (fields.start.value > fields.end.value) {
                        eventStatus.textContent = 'Choose an end date on or after the start date.';
                        return;
                    }
                    const currentRequest = new AbortController();
                    eventRequest = currentRequest;
                    const query = new URLSearchParams({
                        start: fields.start.value, end: fields.end.value,
                        faculty_id: row.id, room_id: fields.room_id.value || '0'
                    });
                    eventStatus.textContent = 'Loading recorded events…';
                    try {
                        const data = await requestFacultyReport(query, currentRequest.signal);
                        if (currentRequest.signal.aborted) return;
                        events = data.events;
                        page = 1;
                        renderEvents();
                        eventStatus.textContent = '';
                    } catch (error) {
                        if (!currentRequest.signal.aborted) eventStatus.textContent = error.message;
                    }
                };
                setText('facultyReportDetailsTitle', facultyValue(row, 'name') + ' · Faculty Activity');
                content.hidden = true;
                status.textContent = 'Loading faculty activity…';
                dialog.addEventListener('hidden.bs.modal', () => {
                    request.abort();
                    eventRequest?.abort();
                    charts.forEach(chart => chart.destroy());
                    eventStatus.remove();
                    navigation.remove();
                    if (trigger.isConnected) trigger.focus();
                }, { once: true });
                bootstrap.Modal.getOrCreateInstance(dialog).show();
                try {
                    const data = await requestFacultyReport(params, request.signal);
                    if (request.signal.aborted) return;
                    fields.room_id.replaceChildren(new Option('All Rooms', ''));
                    data.rooms.forEach(room => fields.room_id.add(new Option(room.name, room.id)));
                    fields.room_id.value = params.get('room_id') || '';
                    fields.start.value = data.start;
                    fields.end.value = data.end;
                    fields.search.value = '';
                    events = data.events;
                    renderEvents();
                    content.hidden = false;
                    charts = renderFacultyDetailSummary(data, data.faculty.find(person => person.id === row.id) || row);
                    status.textContent = '';
                } catch (error) {
                    if (!request.signal.aborted) status.textContent = error.message || 'Unable to load faculty activity.';
                }
            }
            const ISSUE_ACTIONS = ['issue_raised', 'issue_resolved', 'tilt_alert'];

            function manilaToday(offsetDays) {
                try {
                    var manila = new Date(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
                    if (offsetDays) manila.setDate(manila.getDate() + offsetDays);
                    var m = String(manila.getMonth() + 1).padStart(2, '0');
                    var d = String(manila.getDate()).padStart(2, '0');
                    return manila.getFullYear() + '-' + m + '-' + d;
                } catch (e) {
                    var f = new Date(Date.now() + (offsetDays || 0) * 86400000);
                    return f.getFullYear() + '-' + String(f.getMonth() + 1).padStart(2, '0') + '-' + String(f.getDate()).padStart(2, '0');
                }
            }

            function statusDateActive() {
                return ((document.getElementById('statusDate')?.value || '') !== '') ||
                    ((document.getElementById('statusFrom')?.value || '') !== '') ||
                    ((document.getElementById('statusTo')?.value || '') !== '');
            }

            function getStatusQuery() {
                var q = {
                    preset: document.getElementById('statusDate')?.value || '',
                    from: document.getElementById('statusFrom')?.value || '',
                    to: document.getElementById('statusTo')?.value || '',
                    type: document.getElementById('statusType')?.value || '',
                    actor: document.getElementById('statusActor')?.value || '',
                    source: document.getElementById('statusSource')?.value || '',
                    search: document.getElementById('reportsSearch')?.value || '',
                    page: actPage,
                    limit: ACT_PAGE_SIZE
                };
                if (chartFiltersActive()) q.cross = JSON.stringify(chartFilterPayload());
                return q;
            }

            function scheduleStatusFetch(resetPage) {
                if (currentView() !== 'status') return;
                if (resetPage) actPage = 1;
                if (statusFetchTimer) clearTimeout(statusFetchTimer);
                statusFetchTimer = setTimeout(fetchStatusReport, 250);
            }

            function fetchStatusReport() {
                var q = getStatusQuery();
                var params = new URLSearchParams();
                Object.keys(q).forEach(function (k) {
                    if (q[k] !== '' && q[k] !== null && q[k] !== undefined) params.set(k, q[k]);
                });
                if (statusAbort) { try { statusAbort.abort(); } catch (e) { /* ignore */ } }
                statusAbort = new AbortController();
                var myId = ++statusReqId;
                var board = document.querySelector('.status-board');
                if (board) board.setAttribute('aria-busy', 'true');
                fetch('../../api/status-report.php?' + params.toString(), { signal: statusAbort.signal })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (myId !== statusReqId || !res || !res.success) return;
                        statusServerActive = true;
                        renderStatusResponse(res);
                    })
                    .catch(function () {
                        if (myId === statusReqId && !statusServerActive) filterStatusLocal();
                    })
                    .finally(function () { if (board) board.removeAttribute('aria-busy'); });
            }

            function escHtml(s) {
                return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function statusRowHtml(log) {
                var action = log.action || '';
                var actor = log.actor || '';
                var actorLower = actor.toLowerCase();
                var iconKey = (action === 'issue_raised' && actorLower === 'pir') ? 'pir_motion' : action;
                var ic = getEventIcon(iconKey);
                var isRoom = (log.log_type || '') === 'room';
                var typeBg = isRoom ? '#ede6f2' : '#4a0078';
                var typeClr = isRoom ? '#4a0078' : '#ede6f2';
                var typeLabel = isRoom ? 'Room' : 'Admin';
                var source = log.source || 'Manual';
                var target = (log.target || '').trim();
                var label = escHtml(log.label || action.replace(/_/g, ' '));
                var dateTime = escHtml(log.log_time || '-');
                var d = log.log_time ? new Date(String(log.log_time).replace(' ', 'T') + '+08:00') : null;
                if (d && !isNaN(d)) {
                    try {
                        dateTime = escHtml(
                            d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true }) + ', ' +
                            d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                        );
                    } catch (e) { /* keep raw */ }
                }
                var searchVal = escHtml(((log.label || '') + ' ' + (log.target || '') + ' ' + actor + ' ' + action + ' ' + source + ' ' + (log.log_type || '') + ' ' + (log.date || '') + ' ' + (log.notes || '')).toLowerCase());
                return '<tr class="status-row" data-type="' + escHtml(log.log_type || '') + '" data-action="' + escHtml(action) +
                    '" data-actor="' + escHtml(actorLower) + '" data-source="' + escHtml(source.toLowerCase()) +
                    '" data-date="' + escHtml(log.date || '') + '" data-search="' + searchVal + '" data-filtered="1">' +
                    '<td><span class="accordion-log-icon" style="background:' + ic[2] + '; color:' + ic[1] + ';"><i class="bi ' + ic[0] + '"></i></span> ' +
                    '<span style="font-weight:600;">' + label + '</span></td>' +
                    '<td><span class="tl-type-badge" style="background:' + typeBg + '; color:' + typeClr + ';">' + typeLabel + '</span></td>' +
                    '<td>' + (actor ? escHtml(actor) : '-') + '</td>' +
                    '<td><span class="event-count-badge">' + escHtml(source) + '</span></td>' +
                    '<td>' + (target !== '' ? escHtml(target) : '-') + '</td>' +
                    '<td class="last-event-text" style="white-space:nowrap;">' + dateTime + '</td>' +
                    '<td style="max-width:220px; color:var(--muted); font-size:0.75rem;">' + (log.notes ? escHtml(log.notes) : '-') + '</td></tr>';
            }

            function renderStatusResponse(res) {
                var k = res.kpis || {};
                setText('kpiRoomActions', k.roomActions ?? 0);
                setText('kpiAdminActions', k.adminActions ?? 0);
                setText('kpiAnomalies', k.anomalies ?? 0);
                setText('kpiLightsOn', k.lightsOn ?? 0);
                setText('kpiLightsOff', k.lightsOff ?? 0);
                setText('kpiLightsFreq', k.lightsFreq ?? '0/day');
                setText('kpiSpike', k.spike ?? 0);
                setText('kpiMotion', k.motion ?? 0);
                setText('kpiPowerResolved', k.powerResolved ?? 0);
                setText('kpiOther', k.other ?? 0);
                setText('kpiAnomFreq', k.anomFreq ?? '0/day');
                setText('kpiFacApproved', k.facApproved ?? 0);
                setText('kpiFacRejected', k.facRejected ?? 0);
                setText('kpiExtDecisions', k.extDecisions ?? 0);
                var pendingEl = document.getElementById('kpiPending');
                if (pendingEl) {
                    pendingEl.dataset.extPending = k.extPending ?? 0;
                    pendingEl.textContent = k.pending ?? 0;
                }
                if (window.updateStatusCharts && res.charts) {
                    window.updateStatusCharts(res, res.crossFilter);
                    if (window.getStatusChartFilters) {
                        chartFilters = window.getStatusChartFilters();
                        updateChartChips();
                    }
                }

                var tbody = document.querySelector('#statusTable tbody');
                if (tbody) {
                    if (!res.rows || !res.rows.length) {
                        tbody.innerHTML = '<tr class="status-row" data-filtered="1"><td colspan="7" style="text-align:center;color:var(--muted);padding:1.25rem;">' +
                            (chartFiltersActive() ? 'No entries match the active chart filters.' : 'No entries match this date range.') + '</td></tr>';
                    } else {
                        tbody.innerHTML = res.rows.map(statusRowHtml).join('');
                    }
                }
                actPage = res.page || 1;
                var totalPages = res.totalPages || 1;
                var pageInfo = document.getElementById('activityPageInfo');
                var prevBtn = document.getElementById('activityPrev');
                var nextBtn = document.getElementById('activityNext');
                if (pageInfo) pageInfo.textContent = 'Page ' + actPage + ' of ' + totalPages + (res.total !== undefined ? ' (' + res.total + ' entries)' : '');
                if (prevBtn) prevBtn.disabled = actPage <= 1;
                if (nextBtn) nextBtn.disabled = actPage >= totalPages;
                refreshClearButtons();
            }

            function filterStatusLocal() {
                const q = (document.getElementById('reportsSearch')?.value || '').toLowerCase();
                const type = document.getElementById('statusType')?.value || '';
                const actor = document.getElementById('statusActor')?.value || '';
                const source = document.getElementById('statusSource')?.value || '';
                const date = document.getElementById('statusDate')?.value || '';
                const today = manilaToday(0);
                const weekAgo = manilaToday(-6);
                const monthAgo = manilaToday(-29);

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
                    } else if (type === 'light') {
                        matchType = action === 'light_on' || action === 'light_off';
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
                const roomRows = filtered.filter(r => r.dataset.type !== 'admin');
                const adminRows = filtered.filter(r => r.dataset.type === 'admin');
                const anomRows = filtered.filter(r => ISSUE_ACTIONS.includes(r.dataset.action || ''));
                if (kpiRoom) kpiRoom.textContent = roomRows.length;
                if (kpiAdm) kpiAdm.textContent = adminRows.length;
                if (kpiAnom) kpiAnom.textContent = anomRows.length;

                // Breakdown bullets recomputed from the same filtered set
                const days = spanDays(filtered.map(r => r.dataset.date || ''));
                const onCount = filtered.filter(r => r.dataset.action === 'light_on').length;
                const offCount = filtered.filter(r => r.dataset.action === 'light_off').length;
                setText('kpiLightsOn', onCount);
                setText('kpiLightsOff', offCount);
                setText('kpiLightsFreq', freqPerDay(onCount + offCount, days));
                const spike = filtered.filter(r => r.dataset.action === 'issue_raised' && (r.dataset.actor || '') === 'pzem' && (r.dataset.search || '').includes('spike')).length;
                const motion = filtered.filter(r => r.dataset.action === 'issue_raised' && (r.dataset.actor || '') === 'pir').length;
                const powerRes = filtered.filter(r => r.dataset.action === 'issue_resolved' && (r.dataset.actor || '') === 'pzem').length;
                setText('kpiSpike', spike);
                setText('kpiMotion', motion);
                setText('kpiPowerResolved', powerRes);
                setText('kpiOther', Math.max(0, anomRows.length - spike - motion - powerRes));
                setText('kpiAnomFreq', freqPerDay(anomRows.length, days));
                setText('kpiFacApproved', adminRows.filter(r => r.dataset.action === 'faculty_approved').length);
                setText('kpiFacRejected', adminRows.filter(r => r.dataset.action === 'faculty_rejected').length);
                setText('kpiExtDecisions', adminRows.filter(r => r.dataset.action === 'extension_approved' || r.dataset.action === 'extension_rejected').length);
                const pendingEl = document.getElementById('kpiPending');
                if (pendingEl) {
                    const extPending = parseInt(pendingEl.dataset.extPending || '0', 10) || 0;
                    pendingEl.textContent = adminRows.filter(r => r.dataset.action === 'faculty_pending').length + extPending;
                }

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

            // Single code path: KPIs, charts and table come from one response so
            // they can never disagree. The instant local pass is only a
            // fetch-failure fallback (see fetchStatusReport).
            function filterStatus() {
                scheduleStatusFetch(false);
            }

            window.goActivityPage = function(dir) {
                if (currentView() === 'faculty') { filterFaculty(); return; }
                if (statusServerActive) {
                    actPage = Math.max(1, actPage + dir);
                    scheduleStatusFetch(false);
                    return;
                }
                actPage += dir;
                filterStatusLocal();
            };

            function statusFiltersActive() {
                if (chartFiltersActive()) return true;
                if ((document.getElementById('reportsSearch')?.value || '') !== '') return true;
                if ((document.getElementById('statusFrom')?.value || '') !== '') return true;
                if ((document.getElementById('statusTo')?.value || '') !== '') return true;
                return ['statusType', 'statusActor', 'statusSource', 'statusDate']
                    .some(id => (document.getElementById(id)?.value || '') !== '');
            }

            function refreshClearButtons() {
                document.querySelectorAll('.filter-clear').forEach(btn => {
                    var sel = document.getElementById(btn.dataset.clear);
                    var sel2 = btn.dataset.clear2 ? document.getElementById(btn.dataset.clear2) : null;
                    var hasVal = (sel && sel.value !== '') || (sel2 && sel2.value !== '');
                    btn.hidden = !hasVal;
                });
            }

            function resetStatusFilters() {
                var search = document.getElementById('reportsSearch');
                if (search) search.value = '';
                ['statusType', 'statusActor', 'statusSource', 'statusDate', 'statusFrom', 'statusTo'].forEach(id => {
                    var sel = document.getElementById(id);
                    if (sel) sel.value = '';
                });
                chartFilters = emptyChartFilters();
                updateChartChips();
                if (window.clearStatusChartFilters) window.clearStatusChartFilters();
                actPage = 1;
                refreshClearButtons();
            }

            /* ── Chart cross-filter chips (multi-select, Power BI style) ── */
            function chartFilterText(key, picks) {
                if (key === 'types') {
                    return 'Type: ' + picks.map(function (p) { return p.value; }).join(', ');
                }
                if (key === 'perRoom') {
                    return 'Room: ' + picks.map(function (p) { return p.value; }).join(', ');
                }
                var byRoom = {};
                var seriesOnly = [];
                picks.forEach(function (p) {
                    var s = p.series === 'on' ? 'On' : (p.series === 'off' ? 'Off' : '');
                    if (!p.value) { if (s) seriesOnly.push(s); return; }
                    (byRoom[p.value] = byRoom[p.value] || []).push(s);
                });
                var parts = [];
                Object.keys(byRoom).forEach(function (room) {
                    var s = byRoom[room].filter(Boolean);
                    parts.push(s.length === 1 ? room + ' \u00b7 ' + s[0] : room);
                });
                seriesOnly.forEach(function (s) { parts.push(s + ' only'); });
                return (key === 'durations' ? 'Hours' : 'Toggles') + ': ' + parts.join(', ');
            }

            function chartFilterChipList() {
                var out = [];
                ['types', 'perRoom', 'durations', 'toggles'].forEach(function (key) {
                    var picks = chartFilters[key] || [];
                    if (!picks.length) return;
                    out.push({ key: key, text: chartFilterText(key, picks) });
                });
                return out;
            }

            function updateChartChips() {
                var wrap = document.getElementById('chartFilterChips');
                if (!wrap) return;
                var parts = chartFilterChipList();
                if (!parts.length) {
                    wrap.hidden = true;
                    wrap.innerHTML = '';
                    return;
                }
                wrap.hidden = false;
                wrap.innerHTML = parts.map(function (p) {
                    return '<span class="chart-filter-chip">' +
                        '<i class="bi bi-funnel-fill"></i>' +
                        '<span>' + escHtml(p.text) + '</span>' +
                        '<button type="button" class="chart-filter-clear" data-chart="' + p.key + '"' +
                        ' title="Clear this chart filter" aria-label="Clear this chart filter">&times;</button>' +
                        '</span>';
                }).join('') +
                    '<button type="button" class="chart-filter-clear-all" id="chartFilterClearAll"' +
                    ' title="Clear all chart filters">Clear all</button>';
            }

            function clearChartFilter() {
                chartFilters = emptyChartFilters();
                updateChartChips();
                if (window.clearStatusChartFilters) window.clearStatusChartFilters();
            }

            // Single choke point: status-charts.js owns the selections and
            // broadcasts the full map whenever they change.
            document.addEventListener('statuschart:filters', function (ev) {
                chartFilters = ev.detail || emptyChartFilters();
                updateChartChips();
                actPage = 1;
                filterStatus();
            });

            document.getElementById('chartFilterChips')?.addEventListener('click', function (ev) {
                var clearBtn = ev.target.closest('.chart-filter-clear[data-chart]');
                if (clearBtn) {
                    var key = clearBtn.dataset.chart;
                    if (window.removeStatusChartFilter) window.removeStatusChartFilter(key);
                    else { chartFilters[key] = []; updateChartChips(); actPage = 1; filterStatus(); }
                    return;
                }
                if (ev.target.closest('#chartFilterClearAll')) {
                    clearChartFilter();
                    actPage = 1;
                    filterStatus();
                }
            });

            function resetFacultyFilters() {
                facultyRequest?.abort();
                facultyQuery = null;
                facultyPage = 1;
                facultySort = { key: 'gesture_count', direction: -1 };
                document.getElementById('reportsSearch').value = '';
                document.getElementById('facultyReportRoom').value = '';
                document.getElementById('facultyReportStart').value = manilaToday(-29);
                document.getElementById('facultyReportEnd').value = manilaToday(0);
            }

            document.querySelectorAll('.filter-clear').forEach(btn => {
                btn.addEventListener('click', () => {
                    var sel = document.getElementById(btn.dataset.clear);
                    if (sel) sel.value = '';
                    if (btn.dataset.clear2) {
                        var sel2 = document.getElementById(btn.dataset.clear2);
                        if (sel2) sel2.value = '';
                    }
                    actPage = 1;
                    refreshClearButtons();
                    if (currentView() === 'faculty') filterFaculty();
                    else filterStatus();
                });
            });
            document.querySelectorAll('.kpi-expandable').forEach(btn => {
                btn.addEventListener('click', () => {
                    const isOpen = btn.classList.contains('open');
                    document.querySelectorAll('.kpi-expandable.open').forEach(b => {
                        b.classList.remove('open');
                        b.setAttribute('aria-expanded', 'false');
                    });
                    if (!isOpen) {
                        btn.classList.add('open');
                        btn.setAttribute('aria-expanded', 'true');
                    }
                });
            });

            function setText(id, val) {
                const el = document.getElementById(id);
                if (el) el.textContent = val;
            }

            function spanDays(dates) {
                const uniq = [...new Set(dates)];
                return Math.max(1, uniq.length);
            }

            function freqPerDay(count, days) {
                return (Math.round((count / days) * 10) / 10) + '/day';
            }

            document.getElementById('statusType')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusActor')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusSource')?.addEventListener('change', () => { actPage = 1; refreshClearButtons(); filterStatus(); });
            document.getElementById('statusDate')?.addEventListener('change', () => {
                var f = document.getElementById('statusFrom'); if (f) f.value = '';
                var t = document.getElementById('statusTo'); if (t) t.value = '';
                actPage = 1; refreshClearButtons(); filterStatus();
            });
            ['statusFrom', 'statusTo'].forEach(id => {
                // Custom range wins: typing dates clears the preset.
                document.getElementById(id)?.addEventListener('change', () => {
                    var preset = document.getElementById('statusDate');
                    if ((document.getElementById('statusFrom')?.value || '') !== '' ||
                        (document.getElementById('statusTo')?.value || '') !== '') {
                        if (preset) preset.value = '';
                    }
                    actPage = 1; refreshClearButtons(); filterStatus();
                });
            });
            document.getElementById('facultyReportFilters').addEventListener('submit', event => {
                event.preventDefault();
                loadFacultyReport();
            });
            document.getElementById('facultyReportFilters').addEventListener('reset', event => {
                event.preventDefault();
                resetFacultyFilters();
                loadFacultyReport();
            });
            document.querySelectorAll('#facultyTable .faculty-sort-button').forEach(button => {
                button.addEventListener('click', () => {
                    const key = button.dataset.metric;
                    const direction = key === facultySort.key ? -facultySort.direction : (key === 'name' || key === 'top_gesture' ? 1 : -1);
                    facultySort = { key, direction };
                    facultyPage = 1;
                    filterFaculty();
                });
            });
            document.getElementById('facultyReportPrev').addEventListener('click', () => {
                facultyPage = Math.max(1, facultyPage - 1);
                filterFaculty();
            });
            document.getElementById('facultyReportNext').addEventListener('click', () => {
                facultyPage += 1;
                filterFaculty();
            });

            function getFilterParams() {
                if (currentView() === 'faculty') {
                    return { tab: 'faculty' };
                }
                const search = document.getElementById('reportsSearch')?.value || '';
                var cross = chartFiltersActive() ? JSON.stringify(chartFilterPayload()) : '';
                return {
                    tab: 'status',
                    search,
                    type: document.getElementById('statusType')?.value || '',
                    actor: document.getElementById('statusActor')?.value || '',
                    source: document.getElementById('statusSource')?.value || '',
                    date: document.getElementById('statusDate')?.value || '',
                    from: document.getElementById('statusFrom')?.value || '',
                    to: document.getElementById('statusTo')?.value || '',
                    cross: cross
                };
            }

            function showExportModal(type) {
                const el = document.getElementById('exportConfirmModal');
                document.getElementById('exportModalIcon').className = 'bi ' + (type === 'csv' ? 'bi-filetype-csv' : 'bi-filetype-pdf');
                const { tab, search, type: ftype, actor: factor, source: fsource, date, from, to, cross } = getFilterParams();
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
                        if (from) params.set('from', from);
                        if (to) params.set('to', to);
                        if (cross) params.set('cross', cross);
                        window.location.href = '../../api/export-report-pdf.php?' + params.toString();
                    }
                };
                new bootstrap.Modal(el).show();
            }

            function doExportCSV() {
                if (currentView() === 'faculty') {
                    if (!facultyQuery) return;
                    const columns = Array.from(document.querySelectorAll('#facultyTable .faculty-sort-button'));
                    const rows = [columns.map(column => column.textContent.trim())];
                    sortedFacultyRows().forEach(row => rows.push(columns.map(column => facultyValue(row, column.dataset.metric))));
                    const csv = rows.map(row => row.map(value => {
                        const safe = /^[\s]*[=+@-]/.test(value) ? "'" + value : value;
                        return '"' + safe.replaceAll('"', '""') + '"';
                    }).join(',')).join('\n');
                    const blob = new Blob([csv], { type: 'text/csv' });
                    const a = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    a.href = url;
                    a.download = `report-faculty-${facultyQuery.get('start')}-to-${facultyQuery.get('end')}.csv`;
                    a.click();
                    setTimeout(() => URL.revokeObjectURL(url), 0);
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
            window.exportPDF = function() {
                if (currentView() === 'status') showExportModal('pdf');
            };

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
                setText('kpiRoomActions', logs.length ? roomActions : document.getElementById('kpiRoomActions')?.textContent);
                setText('kpiAdminActions', logs.length ? adminActions : document.getElementById('kpiAdminActions')?.textContent);
                if (stats.issue_raised !== undefined || stats.issue_resolved !== undefined) setText('kpiAnomalies', anomalies);
                if (!logs.length) return;
                const lo = l => (l.log_type || '');
                const ac = l => (l.action || '');
                const at = l => (l.actor || '').toLowerCase();
                const nt = l => (l.notes || '').toLowerCase();
                const onCount = logs.filter(l => ac(l) === 'light_on').length;
                const offCount = logs.filter(l => ac(l) === 'light_off').length;
                const days = spanDays(logs.map(l => (l.log_time || '').slice(0, 10)));
                setText('kpiLightsOn', onCount);
                setText('kpiLightsOff', offCount);
                setText('kpiLightsFreq', freqPerDay(onCount + offCount, days));
                const spike = logs.filter(l => ac(l) === 'issue_raised' && at(l) === 'pzem' && nt(l).includes('spike')).length;
                const motion = logs.filter(l => ac(l) === 'issue_raised' && at(l) === 'pir').length;
                const powerRes = logs.filter(l => ac(l) === 'issue_resolved' && at(l) === 'pzem').length;
                setText('kpiSpike', spike);
                setText('kpiMotion', motion);
                setText('kpiPowerResolved', powerRes);
                setText('kpiOther', Math.max(0, anomalies - spike - motion - powerRes));
                setText('kpiAnomFreq', freqPerDay(anomalies, days));
                setText('kpiFacApproved', logs.filter(l => ac(l) === 'faculty_approved').length);
                setText('kpiFacRejected', logs.filter(l => ac(l) === 'faculty_rejected').length);
                setText('kpiExtDecisions', logs.filter(l => ac(l) === 'extension_approved' || ac(l) === 'extension_rejected').length);
                const pendingEl = document.getElementById('kpiPending');
                if (pendingEl) {
                    const extPending = parseInt(pendingEl.dataset.extPending || '0', 10) || 0;
                    pendingEl.textContent = logs.filter(l => ac(l) === 'faculty_pending').length + extPending;
                }
            }

            function reapplyFilters() {
                if (currentView() === 'faculty') { filterFaculty(); return; }
                if (currentView() !== 'status') return;
                actPage = 1;
                filterStatus();
            }

            function pollActivityLog() {
                // The Status panel is always server-driven: refresh through the
                // single endpoint so KPIs, graphs and table stay in sync.
                if (currentView() === 'status') {
                    scheduleStatusFetch(false);
                    return;
                }
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
