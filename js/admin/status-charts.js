/* Status Reports graphs — lazy Chart.js init (panel is hidden on load,
   so charts build on first open when canvases have real dimensions). */
(function () {
    'use strict';

    var initialized = false;
    var charts = [];

    var PALETTE = ['#58078f', '#790faf', '#9b00e9', '#f8c64f', '#198754', '#dc3545', '#0dcaf0', '#6c757d'];

    function smallLegend(position) {
        return { position: position || 'bottom', labels: { boxWidth: 8, boxHeight: 8, font: { size: 8 }, padding: 6 } };
    }

    function smallScales(xTitle, yTitle, stacked) {
        var tick = { font: { size: 8 } };
        return {
            x: { ticks: tick, grid: { display: false }, title: xTitle ? { display: true, text: xTitle, font: { size: 8 } } : undefined, stacked: !!stacked },
            y: { ticks: tick, beginAtZero: true, title: yTitle ? { display: true, text: yTitle, font: { size: 8 } } : undefined, stacked: !!stacked }
        };
    }

    function ctx(id) {
        var el = document.getElementById(id);
        return el ? el.getContext('2d') : null;
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
            // (1) Clustered bar: Lights On vs Off hours, clustered by room
            var d = data.durations || { rooms: [], on: [], off: [] };
            if (d.rooms && d.rooms.length) {
                charts.push(new Chart(ctx('statusChart1'), {
                    type: 'bar',
                    data: {
                        labels: ['Lights On', 'Lights Off'],
                        datasets: d.rooms.map(function (room, i) {
                            return {
                                label: room,
                                data: [d.on[i] || 0, d.off[i] || 0],
                                backgroundColor: PALETTE[i % PALETTE.length]
                            };
                        })
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: smallLegend() },
                        scales: smallScales(null, 'Hours')
                    }
                }));
            }

            // (2) Pie: entries by action type
            var t = data.types || { labels: [], data: [] };
            if (t.labels && t.labels.length) {
                charts.push(new Chart(ctx('statusChart2'), {
                    type: 'pie',
                    data: {
                        labels: t.labels,
                        datasets: [{
                            data: t.data,
                            backgroundColor: t.labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; })
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: smallLegend('right') } }
                }));
            }

            // (3) Donut: entries per room
            var r = data.perRoom || { labels: [], data: [] };
            if (r.labels && r.labels.length) {
                charts.push(new Chart(ctx('statusChart3'), {
                    type: 'doughnut',
                    data: {
                        labels: r.labels,
                        datasets: [{
                            data: r.data,
                            backgroundColor: r.labels.map(function (_, i) { return PALETTE[i % PALETTE.length]; })
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: smallLegend('right') } }
                }));
            }

            // (4) Horizontal grouped bar: toggles per room (on vs off counts)
            var g = data.toggles || { rooms: [], on: [], off: [] };
            if (g.rooms && g.rooms.length) {
                charts.push(new Chart(ctx('statusChart4'), {
                    type: 'bar',
                    data: {
                        labels: g.rooms,
                        datasets: [
                            { label: 'On', data: g.on, backgroundColor: '#198754' },
                            { label: 'Off', data: g.off, backgroundColor: '#f8c64f' }
                        ]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: smallLegend() },
                        scales: smallScales('Toggles', null)
                    }
                }));
            }
        } catch (e) { return false; }

        initialized = true;
        return true;
    };
})();
