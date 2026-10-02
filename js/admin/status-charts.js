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

    var PALETTE = ['#58078f', '#790faf', '#9b00e9', '#f8c64f', '#198754', '#dc3545', '#0dcaf0', '#6c757d'];

    function smallLegend(position) {
        return { position: position || 'bottom', labels: { boxWidth: 8, boxHeight: 8, font: { size: 8 }, padding: 6 } };
    }

    function smallScales(xTitle, yTitle) {
        var tick = { font: { size: 8 } };
        return {
            x: { ticks: tick, grid: { display: false }, title: xTitle ? { display: true, text: xTitle, font: { size: 8 } } : undefined },
            y: { ticks: tick, beginAtZero: true, title: yTitle ? { display: true, text: yTitle, font: { size: 8 } } : undefined }
        };
    }

    function bigLegend(position) {
        return { position: position || 'bottom', labels: { boxWidth: 14, boxHeight: 14, font: { size: 12 }, padding: 12 } };
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

    function buildDefs(data) {
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
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: smallLegend() }, scales: smallScales(null, 'Hours') }
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
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: smallLegend('right') } }
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
                    options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: smallLegend('right') } }
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
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: smallLegend() }, scales: smallScales('Toggles', null) }
                },
                thead: ['Room', 'On Toggles', 'Off Toggles'],
                rows: g.rooms.map(function (room, i) { return [room, g.on[i] || 0, g.off[i] || 0]; })
            }
        };
    }

    function modalizeConfig(def) {
        // Deep clone (configs hold plain data only) and enlarge text for modal
        var cfg = JSON.parse(JSON.stringify(def.config));
        cfg.options = cfg.options || {};
        cfg.options.responsive = true;
        cfg.options.maintainAspectRatio = false;
        cfg.options.plugins = cfg.options.plugins || {};
        var isPie = cfg.type === 'pie' || cfg.type === 'doughnut';
        cfg.options.plugins.legend = bigLegend(isPie ? 'right' : 'bottom');
        if (cfg.type === 'bar' && def.canvas === 'statusChart4') {
            cfg.options.indexAxis = 'y';
            cfg.options.scales = bigScales('Toggles', null);
        } else if (cfg.type === 'bar') {
            cfg.options.scales = bigScales(null, 'Hours');
        }
        return cfg;
    }

    window.openStatusGraph = function (key, title) {
        var def = modalSources[key];
        if (!def) return;
        document.getElementById('graphModalTitle').innerHTML =
            '<i class="bi bi-arrows-expand me-2"></i>' + esc(title || key);
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
            try { modalChart = new Chart(ctx('graphModalCanvas'), modalizeConfig(def)); }
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

        try {
            var defs = buildDefs(data);
            Object.keys(defs).forEach(function (key) {
                var def = defs[key];
                if (!def.hasData) return;
                modalSources[key] = def;
                charts.push(new Chart(ctx(def.canvas), def.config));
            });
        } catch (e) { return false; }

        initialized = true;
        return true;
    };
})();
