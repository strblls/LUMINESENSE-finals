/* Status Reports graphs — lazy Chart.js init (panel is hidden on load,
   so charts build on first open when canvases have real dimensions).
   Each card chart is stored with its data table so the maximize modal
   can re-render it large with full details. */
(function () {
    'use strict';

    var initialized = false;
    var charts = [];
    var modalSources = {}; // key -> { title, config, thead, rows }
    var modalChart = null;

    var PALETTE = ['#58078f', '#790faf', '#9b00e9', '#f8c64f', '#b8860b', '#6c757d', '#0dcaf0', '#888888'];

    /* ── Cross-filter plumbing (Power BI style) ──────────────────────────
       Each chart key holds a list of picks. A row matches one chart when it
       matches ANY of that chart's picks; a row must match EVERY chart that
       has picks (AND across charts). A pick is { value, series } — series is
       only used by the On/Off bar charts, value is a room or type label.
       Interactions:
         click            replace this chart's selection
         Ctrl/Cmd + click add/remove one pick (multi-select)
         Shift + click    range-select (ordered charts: types, perRoom)
         Alt + click      hide/show the item (visual only)
       admin-reports.js listens to `statuschart:filters` and re-fetches, so
       table + KPIs + charts always come from one server response. */
    var activeFilters = emptyFilters();
    var lastIndex = { types: -1, perRoom: -1 };

    function emptyFilters() {
        return { durations: [], types: [], perRoom: [], toggles: [] };
    }

    function normalizeSeries(s) {
        s = String(s || '').toLowerCase();
        if (s === 'light_on' || s === 'on') return 'on';
        if (s === 'light_off' || s === 'off') return 'off';
        return '';
    }

    function normalizeFilters(raw) {
        var out = emptyFilters();
        if (!raw) return out;
        Object.keys(out).forEach(function (key) {
            if (!Array.isArray(raw[key])) return;
            raw[key].forEach(function (p) {
                if (!p) return;
                var value = String(p.value || '').trim();
                var series = normalizeSeries(p.series);
                if (value === '' && series === '') return;
                var dup = out[key].some(function (q) { return q.value === value && q.series === series; });
                if (!dup) out[key].push({ value: value, series: series });
            });
        });
        return out;
    }

    function serializedFilters() {
        var out = emptyFilters();
        Object.keys(out).forEach(function (k) {
            out[k] = activeFilters[k].map(function (p) { return { value: p.value, series: p.series }; });
        });
        return out;
    }

    function filtersActive() {
        return Object.keys(activeFilters).some(function (k) { return activeFilters[k].length > 0; });
    }

    function emitFilters() {
        document.dispatchEvent(new CustomEvent('statuschart:filters', { detail: serializedFilters() }));
    }

    function rgba(hex, alpha) {
        var m = /^#([0-9a-f]{6})$/i.exec(String(hex || '').trim());
        if (!m) return hex;
        var n = parseInt(m[1], 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    // How a clicked element maps to a pick, per chart.
    var PICKERS = {
        durations: function (el, chart) {
            var ds = chart.data.datasets[el.datasetIndex];
            if (!ds) return null;
            return { value: ds.label, series: el.index === 0 ? 'on' : (el.index === 1 ? 'off' : ''), idx: el.datasetIndex };
        },
        types: function (el, chart) {
            var label = (chart.data.labels || [])[el.index];
            return label ? { value: label, series: '', idx: el.index } : null;
        },
        perRoom: function (el, chart) {
            var label = (chart.data.labels || [])[el.index];
            return label ? { value: label, series: '', idx: el.index } : null;
        },
        toggles: function (el, chart) {
            var label = (chart.data.labels || [])[el.index];
            if (!label) return null;
            return { value: label, series: el.datasetIndex === 0 ? 'on' : (el.datasetIndex === 1 ? 'off' : ''), idx: el.index };
        }
    };

    // Apply a pick to a chart's selection using the modifier keys.
    // orderedLabels enables Shift range-select for ordered charts.
    function applyPick(key, pick, modifiers, orderedLabels) {
        if (!pick || (pick.value === '' && pick.series === '')) return;
        var ctrl = !!(modifiers && (modifiers.ctrl || modifiers.meta));
        var shift = !!(modifiers && modifiers.shift) && (key === 'types' || key === 'perRoom');
        var list = activeFilters[key] || [];
        var sameAs = function (a, b) { return a.value === b.value && a.series === b.series; };

        if (shift && lastIndex[key] >= 0 && Array.isArray(orderedLabels) && pick.idx >= 0) {
            var from = lastIndex[key];
            var to = pick.idx;
            var lo = Math.min(from, to);
            var hi = Math.max(from, to);
            var range = [];
            for (var i = lo; i <= hi; i++) {
                if (orderedLabels[i] !== undefined) range.push({ value: String(orderedLabels[i]), series: '' });
            }
            if (ctrl) {
                range.forEach(function (p) {
                    if (!list.some(function (q) { return sameAs(q, p); })) list.push(p);
                });
            } else {
                list = range;
            }
        } else if (ctrl) {
            var at = -1;
            for (var j = 0; j < list.length; j++) {
                if (sameAs(list[j], pick)) { at = j; break; }
            }
            if (at === -1) list.push({ value: pick.value, series: pick.series });
            else list.splice(at, 1);
        } else {
            // Plain click selects one item; clicking that same item again
            // clears the chart's selection (Power BI behaviour).
            var only = list.length === 1 && sameAs(list[0], pick);
            list = only ? [] : [{ value: pick.value, series: pick.series }];
        }

        activeFilters[key] = list;
        if ((key === 'types' || key === 'perRoom') && pick.idx >= 0) lastIndex[key] = pick.idx;
        emitFilters();
        syncChartChrome();
    }

    function pickFromElements(key, elements, chart, fromModal, nativeEvent) {
        var picker = PICKERS[key];
        if (!picker || !elements || !elements.length) return;
        var pick = picker(elements[0], chart);
        if (!pick || (!pick.value && !pick.series)) return;
        var modifiers = {
            ctrl: !!(nativeEvent && nativeEvent.ctrlKey),
            meta: !!(nativeEvent && nativeEvent.metaKey),
            shift: !!(nativeEvent && nativeEvent.shiftKey)
        };
        applyPick(key, pick, modifiers, chart.data.labels || []);
        // Only close the maximize modal on a plain click, so Ctrl/Shift can
        // build a multi-selection without losing the chart.
        if (fromModal && !modifiers.ctrl && !modifiers.meta && !modifiers.shift && window.bootstrap) {
            var modalEl = document.getElementById('graphModal');
            var inst = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
            if (inst) inst.hide();
        }
    }

    function attachInteractions(chart, key, fromModal) {
        if (!chart || !PICKERS[key] || !chart.options) return;
        chart.options.onClick = function (evt, elements) {
            var nativeEvt = (evt && evt.native) ? evt.native : evt;
            pickFromElements(key, elements, chart, fromModal, nativeEvt);
        };
        chart.options.onHover = function (evt, elements) {
            if (!evt || !evt.native || !evt.native.target) return;
            evt.native.target.style.cursor = (elements && elements.length) ? 'pointer' : 'default';
        };
    }

    function findChart(key) {
        for (var i = 0; i < charts.length; i++) {
            if (charts[i] && charts[i]._statusKey === key) return charts[i];
        }
        return null;
    }

    // Repaint every chart's highlight + legend active state without rebuilding
    // the footers (keeps double-click hide working).
    function syncChartChrome() {
        charts.forEach(function (c) {
            var key = c._statusKey;
            applyHighlight(c, key);
            try { c.update('none'); } catch (e) { /* ignore */ }
            syncLegendState(c, key, document.querySelector('.graph-legend-footer[data-legend="' + key + '"]'));
        });
        if (modalChart) {
            var mk = modalChart._statusKey;
            applyHighlight(modalChart, mk);
            try { modalChart.update('none'); } catch (e) { /* ignore */ }
            syncLegendState(modalChart, mk, document.getElementById('graphModalLegend'));
        }
    }

    function syncLegendState(chart, key, footerEl) {
        if (!footerEl || !chart || !PICKERS[key]) return;
        var items = legendItems(chart);
        footerEl.querySelectorAll('.legend-item').forEach(function (btn) {
            var item = items[parseInt(btn.dataset.n, 10)];
            if (!item) return;
            var on = isLegendActive(key, item);
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function pickMatches(picks, value, series) {
        for (var i = 0; i < picks.length; i++) {
            var p = picks[i];
            if (p.value !== '' && value !== undefined && p.value !== value) continue;
            if (p.series !== '' && series !== undefined && p.series !== series) continue;
            return true;
        }
        return false;
    }

    // Repaint a chart: dim every segment/bar that no pick matches, keep the
    // selected ones at full opacity.
    function applyHighlight(chart, key) {
        if (!chart) return;
        var picks = activeFilters[key] || [];
        var on = picks.length > 0;
        var labels = chart.data.labels || [];
        (chart.data.datasets || []).forEach(function (ds) {
            if (ds._baseBg === undefined) ds._baseBg = ds.backgroundColor;
            var base = ds._baseBg;
            if (!on) { ds.backgroundColor = base; return; }

            if (key === 'types' || key === 'perRoom') {
                var arr = Array.isArray(base) ? base : [base];
                ds.backgroundColor = labels.map(function (l, i) {
                    var c = arr[i % arr.length];
                    return pickMatches(picks, l, undefined) ? c : rgba(c, 0.2);
                });
                return;
            }
            if (key === 'durations') {
                // dataset = room, data index 0 = Lights On, 1 = Lights Off
                var durColor = Array.isArray(base) ? base[0] : base;
                ds.backgroundColor = labels.map(function (_, i) {
                    return pickMatches(picks, ds.label, i === 0 ? 'on' : 'off') ? durColor : rgba(durColor, 0.18);
                });
                return;
            }
            // toggles: dataset = On/Off, label = room
            var togColor = Array.isArray(base) ? base[0] : base;
            var series = String(ds.label || '').toLowerCase();
            ds.backgroundColor = labels.map(function (l) {
                return pickMatches(picks, l, series) ? togColor : rgba(togColor, 0.18);
            });
        });
    }

    // Legends render as HTML footers under each chart (and in the modal),
    // so Chart.js built-in legends stay off everywhere.
    function noLegend() {
        return { display: false };
    }

    function smallScales(xTitle, yTitle) {
        var tick = { font: { size: 8 } };
        return {
            x: { ticks: tick, grid: { display: false }, title: xTitle ? { display: true, text: xTitle, font: { size: 8 } } : undefined },
            y: { ticks: tick, beginAtZero: true, title: yTitle ? { display: true, text: yTitle, font: { size: 8 } } : undefined }
        };
    }

    function bigScales(xTitle, yTitle) {
        var tick = { font: { size: 12 } };
        return {
            x: { ticks: tick, grid: { display: false }, title: xTitle ? { display: true, text: xTitle, font: { size: 13 } } : undefined },
            y: { ticks: tick, beginAtZero: true, title: yTitle ? { display: true, text: yTitle, font: { size: 13 } } : undefined }
        };
    }

    function ctx(id) {
        var el = document.getElementById(id);
        return el ? el.getContext('2d') : null;
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildAllDefs(data) {
        var d = data.durations || { rooms: [], on: [], off: [] };
        var t = data.types || { labels: [], data: [] };
        var r = data.perRoom || { labels: [], data: [] };
        var g = data.toggles || { rooms: [], on: [], off: [] };
        return {
            durations: {
                canvas: 'statusChart1',
                hasData: !!(d.rooms && d.rooms.length),
                config: {
                    type: 'bar',
                    data: {
                        labels: ['Lights On', 'Lights Off'],
                        datasets: d.rooms.map(function (room, i) {
                            return { label: room, data: [d.on[i] || 0, d.off[i] || 0], backgroundColor: PALETTE[i % PALETTE.length] };
                        })
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: noLegend() }, scales: smallScales(null, 'Hours') }
                },
                thead: ['Room', 'On Hours', 'Off Hours'],
                rows: d.rooms.map(function (room, i) { return [room, d.on[i] || 0, d.off[i] || 0]; })
            },
            types: {
                canvas: 'statusChart2',
                hasData: !!(t.labels && t.labels.length),
                config: {
                    type: 'pie',
                    data: {
                        labels: t.labels,
                        datasets: [{ data: t.data, backgroundColor: t.labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; }) }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: noLegend() } }
                },
                thead: ['Action Type', 'Entries'],
                rows: t.labels.map(function (label, i) { return [label, t.data[i] || 0]; })
            },
            perRoom: {
                canvas: 'statusChart3',
                hasData: !!(r.labels && r.labels.length),
                config: {
                    type: 'doughnut',
                    data: {
                        labels: r.labels,
                        datasets: [{ data: r.data, backgroundColor: r.labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; }) }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: noLegend() } }
                },
                thead: ['Room', 'Entries'],
                rows: r.labels.map(function (label, i) { return [label, r.data[i] || 0]; })
            },
            toggles: {
                canvas: 'statusChart4',
                hasData: !!(g.rooms && g.rooms.length),
                config: {
                    type: 'bar',
                    data: {
                        labels: g.rooms,
                        datasets: [
                            { label: 'On', data: g.on, backgroundColor: '#198754' },
                            { label: 'Off', data: g.off, backgroundColor: '#f8c64f' }
                        ]
                    },
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: noLegend() }, scales: smallScales('Toggles', null) }
                },
                thead: ['Room', 'On Toggles', 'Off Toggles'],
                rows: g.rooms.map(function (room, i) { return [room, g.on[i] || 0, g.off[i] || 0]; })
            }
        };
    }

    function buildDef(key, data) {
        return buildAllDefs(data)[key];
    }

    function buildDefs(data) {
        return buildAllDefs(data);
    }

    function modalizeConfig(def) {
        // Deep clone (configs hold plain data only) and enlarge text for modal
        var cfg = JSON.parse(JSON.stringify(def.config));
        cfg.options = cfg.options || {};
        cfg.options.responsive = true;
        cfg.options.maintainAspectRatio = false;
        cfg.options.plugins = cfg.options.plugins || {};
        cfg.options.plugins.legend = { display: false };
        if (cfg.type === 'bar' && def.axisTitle) {
            cfg.options.scales = bigScales(def.axisTitle, null);
        } else if (cfg.type === 'bar' && def.canvas === 'statusChart4') {
            cfg.options.indexAxis = 'y';
            cfg.options.scales = bigScales('Toggles', null);
        } else if (cfg.type === 'bar') {
            cfg.options.scales = bigScales(null, 'Hours');
        }
        return cfg;
    }

    function legendBaseColor(ds) {
        // applyHighlight() swaps backgroundColor for dimmed hues; the chips must
        // always show the true palette, so prefer the cached base colour.
        var base = (ds._baseBg !== undefined) ? ds._baseBg : ds.backgroundColor;
        return Array.isArray(base) ? base : [base];
    }

    function legendItems(chart) {
        var data = chart.data || {};
        var labels = data.labels || [];
        var sets = data.datasets || [];
        if (sets.length === 1) {
            var ds = sets[0];
            var colors = legendBaseColor(ds);
            return labels.map(function (label, i) {
                return { label: label, color: colors[i % Math.max(1, colors.length)] || '#999', value: (ds.data || [])[i], di: 0, idx: i, single: true };
            });
        }
        return sets.map(function (ds, di) {
            return { label: ds.label, color: legendBaseColor(ds)[0] || '#999', value: null, di: di, idx: 0, single: false };
        });
    }

    function isLegendActive(key, item) {
        var picks = activeFilters[key] || [];
        if (!picks.length) return false;
        var label = String(item.label || '');
        if (key === 'toggles') {
            var s = label.toLowerCase();
            return picks.some(function (p) { return p.value === '' && p.series === s; });
        }
        return picks.some(function (p) { return p.value === label; });
    }

    function legendPick(key, item) {
        if (!item) return null;
        if (key === 'toggles') {
            var s = String(item.label || '').toLowerCase();
            if (s === 'on' || s === 'off') return { value: '', series: s, idx: 0 };
        }
        return { value: item.label, series: '', idx: item.idx };
    }

    /* ── Legend explanations ────────────────────────────────────────────
       What each chip means, shown on hover/focus. The card footers and the
       maximize modal share this renderer, so one map covers both. */

    // Appended to every chip so the click behaviour is discoverable in place.
    var INTERACTION_HINT = 'Click to filter · Ctrl/Cmd-click to add · Shift-click range · Alt-click hide.';

    var TYPE_DOCS = {
        'Admin': 'Actions performed by admin accounts including faculty approvals, rejections, schedule modifications, and extension decisions. Each entry represents a discrete administrative action with timestamp and actor.',
        'Lights': 'Physical light on/off events recorded by room controllers. Includes manual wall switches, admin overrides from the dashboard, and automated scheduler commands.',
        'PIR Motion': 'Passive infrared sensor events: pir_motion when occupancy is detected and pir_stopped when the room becomes vacant. Useful for verifying sensor coverage and occupancy patterns.',
        'Class': 'Automated class start and end events triggered by the scheduling system. These drive scheduled lighting changes and define the expected occupancy window for anomaly detection.',
        'Anomalies': 'Power and sensor issues detected by PZEM (voltage/current spikes), PIR faults (motion outside schedule), and tilt alerts. Also includes issue_resolved entries when sensors confirm normal conditions restored.',
        'Other Room': 'Room-level events that do not fit other categories: door open/close, schedule edits, and any unclassified room_logs entries. Acts as a catch-all for miscellaneous room activity.'
    };

    var LEGEND_DOCS = {
        types: function (label) {
            return TYPE_DOCS[label] || '';
        },
        perRoom: function (label) {
            if (label === 'Other') {
                return 'Aggregated count of all events targeting non-classroom entities (free-text targets, system-wide actions) plus any real classroom outside the top 7 by volume. Useful for spotting untracked locations.';
            }
            return 'Every logged event whose target is this specific classroom, regardless of event type (lights, PIR, class, anomalies, admin). Sum of all categories for this room.';
        },
        durations: function (label) {
            return 'Total lighting on-hours for this room within the current filter window. Each light-on is paired with the next light-off; if the last command was on, it counts as on until the window end. Off-hours are the remainder of the observed span.';
        },
        toggles: function (label) {
            var s = String(label || '').toLowerCase();
            if (s === 'on') return 'Count of light-on commands per room. Includes manual switches, admin dashboard toggles, and scheduler-initiated ons. High values may indicate frequent manual intervention.';
            if (s === 'off') return 'Count of light-off commands per room. Typically higher than ons because the scheduler auto-turns off lights after class ends or when PIR detects vacancy. Includes all off sources.';
            return '';
        }
    };

    // One-line "what this chart plots" for the maximize modal.
    var CHART_ABOUT = {
        durations: 'Clustered bar chart showing lighting on-hours vs off-hours for the top 6 rooms by on-time. On-hours accumulate from paired on/off events; off-hours are the remaining gap in the observed span.',
        types: 'Pie chart partitioning all log entries into six mutually exclusive categories. The six slices sum to the total entries in the current filter. Click a slice to cross-filter the table and other charts.',
        perRoom: 'Donut chart of event volume by classroom. Top 7 rooms shown individually; all others grouped as Other. Slice size = total entries for that room across all types.',
        toggles: 'Horizontal bar chart of light-on and light-off toggle counts per room. On = manual + admin + scheduler ons; Off = all off sources. Useful for identifying rooms with excessive manual switching.'
    };

    // The full multi-sentence text lives in the maximized Legend Guide; the
    // hover tip stays compact by showing only the lead sentence.
    function firstSentence(text) {
        var m = /^([\s\S]+?[.!?])(\s|$)/.exec(String(text || '').trim());
        return m ? m[1] : String(text || '');
    }

    function legendTip(key, item) {
        if (!item) return '';
        var doc = LEGEND_DOCS[key] ? LEGEND_DOCS[key](item.label) : '';
        var label = String(item.label || '');
        var head = doc ? label + ' — ' + firstSentence(doc) : label;
        return head + ' ' + (PICKERS[key] ? INTERACTION_HINT : 'Click to show or hide.');
    }

    /* Single body-mounted tooltip. The footers are re-rendered on every
       filter update and Bootstrap tooltips get clipped inside the modal, so
       one fixed node driven by delegated listeners is the reliable option. */
    var tipEl = null;
    var tipTarget = null;

    function ensureTip() {
        if (tipEl && document.body.contains(tipEl)) return tipEl;
        tipEl = document.createElement('div');
        tipEl.className = 'chart-tip';
        tipEl.id = 'statusChartTip';
        tipEl.setAttribute('role', 'tooltip');
        tipEl.hidden = true;
        document.body.appendChild(tipEl);
        return tipEl;
    }

    function showTip(target) {
        var text = target.getAttribute('data-tip');
        if (!text) return;
        tipTarget = target;
        var el = ensureTip();
        el.textContent = text;
        el.hidden = false;

        var r = target.getBoundingClientRect();
        var w = el.offsetWidth;
        var h = el.offsetHeight;
        var left = r.left + (r.width / 2) - (w / 2);
        left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
        var top = r.top - h - 8;
        if (top < 8) top = r.bottom + 8; // no room above: flip below
        // Keep it on screen even in a short viewport (overlapping the chip is
        // better than being clipped).
        top = Math.max(8, Math.min(top, window.innerHeight - h - 8));
        el.style.left = Math.round(left) + 'px';
        el.style.top = Math.round(top) + 'px';

        // body.contrast-bg carries a translate() transform, which re-roots
        // position:fixed to the body box instead of the viewport. Correct for
        // whatever offset that introduces and re-clamp afterwards.
        var box = el.getBoundingClientRect();
        var dx = left - box.left;
        var dy = top - box.top;
        if (Math.abs(dx) > 0.5 || Math.abs(dy) > 0.5) {
            el.style.left = Math.round(left + dx) + 'px';
            el.style.top = Math.round(top + dy) + 'px';
            box = el.getBoundingClientRect();
            if (box.bottom > window.innerHeight - 8) {
                el.style.top = Math.round(parseFloat(el.style.top) - (box.bottom - (window.innerHeight - 8))) + 'px';
            }
            if (box.right > window.innerWidth - 8) {
                el.style.left = Math.round(parseFloat(el.style.left) - (box.right - (window.innerWidth - 8))) + 'px';
            }
        }
    }

    function hideTip() {
        if (tipEl) tipEl.hidden = true;
        tipTarget = null;
    }

    function initLegendTips() {
        if (initLegendTips.done) return;
        initLegendTips.done = true;
        function chipFor(node) {
            return (node && node.closest) ? node.closest('.legend-item[data-tip]') : null;
        }
        document.addEventListener('mouseover', function (ev) {
            var chip = chipFor(ev.target);
            if (chip) showTip(chip);
        });
        document.addEventListener('mouseout', function (ev) {
            var chip = chipFor(ev.target);
            if (!chip || chip !== tipTarget) return;
            if (ev.relatedTarget && chip.contains(ev.relatedTarget)) return; // moved inside the same chip
            hideTip();
        });
        document.addEventListener('focusin', function (ev) {
            var chip = chipFor(ev.target);
            if (chip) showTip(chip);
        });
        document.addEventListener('focusout', function (ev) {
            if (chipFor(ev.target) === tipTarget) hideTip();
        });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') hideTip();
        });
        window.addEventListener('scroll', function () {
            // Scroll can come from focusing a chip (scrollIntoView) as well as
            // from the user: follow the chip instead of hiding the tooltip.
            if (tipTarget && tipTarget.isConnected) showTip(tipTarget);
            else hideTip();
        }, true);
        window.addEventListener('resize', hideTip);
    }

    function renderLegendFooter(footerEl, chart, key) {
        if (!footerEl || !chart) return;
        key = key || chart._statusKey || '';
        hideTip(); // the chip under the cursor is about to be replaced
        initLegendTips();
        var items = legendItems(chart);
        footerEl.innerHTML = items.map(function (item, n) {
            var on = isLegendActive(key, item);
            return '<button type="button" class="legend-item' + (on ? ' active' : '') + '" data-n="' + n + '"' +
                ' data-tip="' + esc(legendTip(key, item)) + '" aria-describedby="statusChartTip"' +
                (on || !PICKERS[key] ? ' aria-pressed="true"' : '') + '>' +
                '<span class="legend-dot" style="background:' + esc(item.color) + ';"></span>' +
                '<span>' + esc(item.label) + '</span>' +
                (item.value !== null && item.value !== undefined
                    ? '<span class="legend-val">' + esc(item.value) + '</span>' : '') + '</button>';
        }).join('');
        footerEl.querySelectorAll('.legend-item').forEach(function (btn) {
            var item = items[parseInt(btn.dataset.n, 10)];
            if (!item) return;
            // Alt/Cmd-click or double-click keeps the old hide/show behaviour.
            function toggleHidden() {
                var hidden;
                if (item.single) {
                    chart.toggleDataVisibility(item.idx, !chart.getDataVisibility(item.idx));
                    hidden = !chart.getDataVisibility(item.idx);
                } else {
                    chart.setDatasetVisibility(item.di, !chart.isDatasetVisible(item.di));
                    hidden = !chart.isDatasetVisible(item.di);
                }
                chart.update();
                btn.classList.toggle('struck', !!hidden);
                if (!PICKERS[key]) btn.setAttribute('aria-pressed', String(!hidden));
            }
            btn.addEventListener('click', function (ev) {
                if (!PICKERS[key]) { toggleHidden(); return; }
                // Alt keeps the old hide/show behaviour; Cmd is freed up for
                // multi-select.
                if (ev.altKey) { toggleHidden(); return; }
                var pick = legendPick(key, item);
                if (!pick) return;
                // Shift ranges over the chart's own label order (types/perRoom).
                var labels = (key === 'types' || key === 'perRoom') ? (chart.data.labels || []) : null;
                applyPick(key, pick, { ctrl: ev.ctrlKey, meta: ev.metaKey, shift: ev.shiftKey }, labels);
            });
            btn.addEventListener('dblclick', function (ev) {
                if (!PICKERS[key]) return;
                ev.preventDefault();
                toggleHidden();
            });
        });
    }

    window.openStatusGraph = function (key, title) {
        var def = modalSources[key];
        if (!def) return;
        document.getElementById('graphModalTitle').innerHTML =
            '<i class="bi bi-arrows-expand me-2"></i>' + esc(title || key);
        var aboutEl = document.getElementById('graphModalAbout');
        if (aboutEl) {
            var about = def.about || CHART_ABOUT[key] || '';
            aboutEl.textContent = about;
            aboutEl.hidden = about === '';
        }
        var headRow = document.getElementById('graphModalHeadRow');
        var body = document.getElementById('graphModalBody');
        headRow.innerHTML = def.thead.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('');
        body.innerHTML = def.rows.length
            ? def.rows.map(function (row) {
                return '<tr>' + row.map(function (cell, i) {
                    return i === 0
                        ? '<td style="font-weight:600;">' + esc(cell) + '</td>'
                        : '<td>' + esc(cell) + '</td>';
                }).join('') + '</tr>';
            }).join('')
            : '<tr><td colspan="' + def.thead.length + '" style="text-align:center;color:#999;">No data available.</td></tr>';

        var modalEl = document.getElementById('graphModal');
        modalEl.addEventListener('shown.bs.modal', function onShown() {
            modalEl.removeEventListener('shown.bs.modal', onShown);
            if (modalChart) { try { modalChart.destroy(); } catch (e) { /* ignore */ } modalChart = null; }
            try {
                modalChart = new Chart(ctx('graphModalCanvas'), modalizeConfig(def));
                modalChart._statusKey = key;
                attachInteractions(modalChart, key, true);
                applyHighlight(modalChart, key);
                modalChart.update();
                renderLegendFooter(document.getElementById('graphModalLegend'), modalChart, key);
                renderLegendGuide(key);
            }
            catch (e) { /* ignore */ }
        });
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    };

    document.querySelectorAll('.graph-expand-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var card = btn.closest('.status-graph-card');
            var label = card ? card.querySelector('.status-graph-label') : null;
            window.openStatusGraph(btn.dataset.graph, label ? label.textContent.trim() : btn.dataset.graph);
        });
    });

    var graphModalEl = document.getElementById('graphModal');
    if (graphModalEl) {
        graphModalEl.addEventListener('hidden.bs.modal', function () {
            if (modalChart) { try { modalChart.destroy(); } catch (e) { /* ignore */ } modalChart = null; }
        });
    }

    /* ── Legend Guide (modal) ───────────────────────────────────────── */
    function getLegendGuideItems(key) {
        // Returns [{label, color, desc}, ...] for the given chart key
        var items = [];
        if (key === 'types') {
            var labels = ['Admin', 'Lights', 'PIR Motion', 'Class', 'Anomalies', 'Other Room'];
            var colors = labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; });
            labels.forEach(function (label, i) {
                items.push({ label: label, color: colors[i], desc: TYPE_DOCS[label] || '' });
            });
        } else if (key === 'perRoom') {
            var def = modalSources['perRoom'];
            if (def && def.rows) {
                def.rows.forEach(function (row) {
                    var label = row[0];
                    var color = PALETTE[items.length % PALETTE.length];
                    var desc = LEGEND_DOCS.perRoom ? LEGEND_DOCS.perRoom(label) : '';
                    items.push({ label: label, color: color, desc: desc });
                });
            }
        } else if (key === 'durations') {
            var def = modalSources['durations'];
            if (def && def.rows) {
                def.rows.forEach(function (row) {
                    var label = row[0];
                    var color = PALETTE[items.length % PALETTE.length];
                    var desc = LEGEND_DOCS.durations ? LEGEND_DOCS.durations(label) : '';
                    items.push({ label: label, color: color, desc: desc });
                });
            }
        } else if (key === 'toggles') {
            items.push({ label: 'On', color: '#198754', desc: LEGEND_DOCS.toggles ? LEGEND_DOCS.toggles('On') : '' });
            items.push({ label: 'Off', color: '#f8c64f', desc: LEGEND_DOCS.toggles ? LEGEND_DOCS.toggles('Off') : '' });
        }
        return items;
    }

    function renderLegendGuide(key) {
        var guideEl = document.getElementById('graphModalLegendGuide');
        var listEl = document.getElementById('legendGuideList');
        var toggleEl = guideEl ? guideEl.querySelector('.legend-guide-toggle') : null;
        if (!guideEl || !listEl) return;

        var items = getLegendGuideItems(key);
        if (!items.length) {
            guideEl.hidden = true;
            return;
        }
        guideEl.hidden = false;
        guideEl.classList.remove('open');
        toggleEl.setAttribute('aria-expanded', 'false');
        listEl.style.maxHeight = '0';
        listEl.style.opacity = '0';

        listEl.innerHTML = items.map(function (item) {
            return '<li>' +
                '<span class="legend-guide-dot" style="background:' + esc(item.color) + ';"></span>' +
                '<span class="legend-guide-label">' + esc(item.label) + '</span>' +
                '<span class="legend-guide-desc">' + esc(item.desc) + '</span>' +
                '</li>';
        }).join('');

        // Toggle handler
        if (toggleEl && !toggleEl._guideBound) {
            toggleEl._guideBound = true;
            toggleEl.addEventListener('click', function () {
                var isOpen = guideEl.classList.toggle('open');
                toggleEl.setAttribute('aria-expanded', isOpen);
                if (isOpen) {
                    listEl.style.maxHeight = listEl.scrollHeight + 'px';
                    listEl.style.opacity = '1';
                } else {
                    listEl.style.maxHeight = '0';
                    listEl.style.opacity = '0';
                }
            });
        }
    }

    function legendEmptyFooter(footerEl) {
        if (footerEl) footerEl.innerHTML = '';
    }

    function upsertChart(key, def) {
        var footer = document.querySelector('.graph-legend-footer[data-legend="' + key + '"]');
        var existing = null;
        for (var i = 0; i < charts.length; i++) {
            if (charts[i] && charts[i]._statusKey === key) { existing = charts[i]; break; }
        }
        if (!def.hasData) {
            if (existing) {
                try { existing.destroy(); } catch (e) { /* ignore */ }
                charts = charts.filter(function (c) { return c !== existing; });
            }
            delete modalSources[key];
            legendEmptyFooter(footer);
            return;
        }
        modalSources[key] = def;
        if (existing) {
            // Update data in place. Do NOT replace chart.options: Chart.js
            // wraps it in a proxy and assigning a plain object breaks it
            // (which is why this used to destroy/recreate on every update).
            existing.data = JSON.parse(JSON.stringify(def.config.data));
            attachInteractions(existing, key, false);
            applyHighlight(existing, key);
            existing.update();
            renderLegendFooter(footer, existing, key);
            return;
        }
        try {
            var chart = new Chart(ctx(def.canvas), JSON.parse(JSON.stringify(def.config)));
            chart._statusKey = key;
            attachInteractions(chart, key, false);
            applyHighlight(chart, key);
            charts.push(chart);
            chart.update();
            renderLegendFooter(footer, chart, key);
        } catch (e) { console.error('[status-charts] upsert failed for ' + key, e); /* ignore */ }
    }

    window.updateFacultyCharts = function (faculty) {
        if (typeof Chart === 'undefined') return;
        var graphs = document.getElementById('facultyReportGraphs');
        graphs.hidden = false;

        var gestureCounts = new Map();
        var gestures = 0;
        var uiToggles = 0;
        var extensionRows = [];
        faculty.forEach(function (person) {
            gestures += person.gesture_count || 0;
            uiToggles += person.ui_toggles || 0;
            (person.gesture_breakdown || []).forEach(function (gesture) {
                gestureCounts.set(gesture.name, (gestureCounts.get(gesture.name) || 0) + gesture.count);
            });
            if (person.classes_with_extensions > 0) {
                extensionRows.push([person.name || 'Unnamed Faculty', person.classes_with_extensions]);
            }
        });

        var gestureRows = Array.from(gestureCounts, function (entry) {
            return [entry[0].replaceAll('_', ' ').replace(/\b\w/g, function (char) { return char.toUpperCase(); }), entry[1]];
        });
        function rankRows(a, b) {
            return b[1] - a[1] || a[0].localeCompare(b[0]);
        }
        gestureRows.sort(rankRows);
        extensionRows.sort(rankRows);

        [
            {
                key: 'facultyGestures', type: 'doughnut',
                thead: ['Gesture', 'Recorded Uses'], rows: gestureRows,
                about: 'Counts include individually named gestures recorded for the selected faculty, dates, and room. Older gesture records without names appear in Gesture vs UI Activity.'
            },
            {
                key: 'facultyControls', type: 'doughnut',
                thead: ['Control Method', 'Recorded Events'],
                rows: [['Gestures', gestures], ['UI Toggles', uiToggles]],
                about: 'Gestures count confirmed records. UI toggles count recorded dashboard light-on and light-off actions.'
            },
            {
                key: 'facultyExtensions', type: 'bar', axisTitle: 'Classes with requests',
                thead: ['Faculty', 'Classes with Requests'], rows: extensionRows,
                about: 'Classes are counted once per scheduled class date, even when several requests were submitted. The chart shows the six highest counts. The table lists all faculty with recorded requests.'
            }
        ].forEach(function (definition) {
            var canvas = definition.key + 'Chart';
            var chartRows = definition.type === 'bar' ? definition.rows.slice(0, 6) : definition.rows;
            var hasData = chartRows.some(function (row) { return row[1] > 0; });
            var card = document.getElementById(canvas).closest('.status-graph-card');
            card.querySelector('.faculty-graph-empty').hidden = hasData;
            card.querySelector('.graph-expand-btn').disabled = !hasData;
            upsertChart(definition.key, {
                canvas: canvas,
                hasData: hasData,
                about: definition.about,
                axisTitle: definition.axisTitle,
                thead: definition.thead,
                rows: definition.rows,
                config: {
                    type: definition.type,
                    data: {
                        labels: chartRows.map(function (row) { return row[0]; }),
                        datasets: [{
                            data: chartRows.map(function (row) { return row[1]; }),
                            backgroundColor: chartRows.map(function (_, index) { return PALETTE[index % PALETTE.length]; })
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        indexAxis: definition.type === 'bar' ? 'y' : undefined,
                        scales: definition.type === 'bar' ? smallScales(definition.axisTitle, null) : undefined,
                        plugins: { legend: noLegend() }
                    }
                }
            });
        });
    };

    // Live update entry point for every filter change (search, dropdowns,
    // date range, chart click). `data` is the api/status-report.php response
    // (with `charts`, `chartsBase` and per-chart `chartsOwnBase`).
    // `filter` is the `crossFilter` map keyed by chart name.
    window.updateStatusCharts = function (data, filter) {
        if (typeof Chart === 'undefined' || !data) return false;
        try {
            var chartsObj = data.charts || data;
            var ownBase = data.chartsOwnBase || null;
            activeFilters = normalizeFilters(filter);
            ['durations', 'types', 'perRoom', 'toggles'].forEach(function (key) {
                // An active chart keeps its own full breakdown: swap in only
                // its entry from chartsOwnBase (all other charts are applied).
                var src = chartsObj;
                if (activeFilters[key].length && ownBase && ownBase[key]) {
                    src = {};
                    Object.keys(chartsObj).forEach(function (k) { src[k] = chartsObj[k]; });
                    src[key] = ownBase[key];
                }
                upsertChart(key, buildDef(key, src));
            });
            initialized = true;
            return true;
        } catch (e) { console.error('[status-charts] update failed', e); return false; }
    };

    /* Public API used by admin-reports.js for the filter chips + exports. */
    window.getStatusChartFilters = function () { return serializedFilters(); };
    window.hasStatusChartFilters = function () { return filtersActive(); };

    window.clearStatusChartFilters = function () {
        activeFilters = emptyFilters();
        lastIndex = { types: -1, perRoom: -1 };
        emitFilters();
        syncChartChrome();
    };

    window.removeStatusChartFilter = function (key) {
        if (!key || !activeFilters[key]) return;
        activeFilters[key] = [];
        emitFilters();
        syncChartChrome();
    };

    window.initStatusCharts = function () {
        if (typeof Chart === 'undefined') return false;
        if (initialized) {
            charts.forEach(function (c) { try { c.resize(); } catch (e) { /* ignore */ } });
            return true;
        }
        var dataEl = document.getElementById('statusChartData');
        if (!dataEl) return false;
        var data;
        try { data = JSON.parse(dataEl.textContent); } catch (e) { return false; }

        activeFilters = emptyFilters();
        try {
            var defs = buildDefs(data);
            Object.keys(defs).forEach(function (key) {
                var def = defs[key];
                if (!def.hasData) return;
                modalSources[key] = def;
                var chart = new Chart(ctx(def.canvas), def.config);
                chart._statusKey = key;
                attachInteractions(chart, key, false);
                applyHighlight(chart, key);
                charts.push(chart);
                chart.update();
                renderLegendFooter(document.querySelector('.graph-legend-footer[data-legend="' + key + '"]'), chart, key);
            });
        } catch (e) { return false; }

        initialized = true;
        return true;
    };
})();
