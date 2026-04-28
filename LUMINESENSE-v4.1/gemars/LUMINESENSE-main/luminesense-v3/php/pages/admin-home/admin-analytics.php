<?php
// ============================================================
//  admin-analytics.php
//  LumineSense – Energy Consumption Analytics
//
//  Shows:
//  - Total hours lights were ON per classroom (this week/month)
//  - Estimated energy consumption (kWh)
//  - Daily usage chart (Chart.js — placeholder for Arduino data)
//
//  ENERGY FORMULA (from capstone document):
//  kWh = (Watts per bulb × number of bulbs × hours ON) ÷ 1000
//  For prototype: 9 bulbs × 3W = 27W total
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

// ── Prototype constants (from capstone specs) ─────────────────
const BULB_WATTAGE   = 3;    // 3W LED mini bulbs (prototype)
const BULB_COUNT     = 9;    // 3×3 grid (medium classroom)
const TOTAL_WATTS    = BULB_WATTAGE * BULB_COUNT; // = 27W

// ── Fetch: hours ON per classroom this week ────────────────────
// We approximate: find pairs of ON/OFF events and sum durations.
// For prototype simplicity, we count ON events × default 1 hour
// (replace with actual duration tracking once Arduino feeds data).
$classroom_stats = [];
$r = $conn->query("
    SELECT c.room_name, c.room_size,
           COUNT(CASE WHEN l.event_type = 'on' THEN 1 END) AS on_count,
           COUNT(CASE WHEN l.event_type = 'off' THEN 1 END) AS off_count,
           COUNT(CASE WHEN l.event_type = 'security_alert' THEN 1 END) AS alert_count
    FROM classrooms c
    LEFT JOIN lighting_logs l ON l.classroom_id = c.id
        AND l.event_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY c.id
    ORDER BY c.room_name
");
if ($r) while ($row = $r->fetch_assoc()) $classroom_stats[] = $row;

// ── Daily usage for the past 7 days (for chart) ────────────────
$daily_labels  = [];
$daily_on_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $label = date('D M d', strtotime($date));
    $daily_labels[] = $label;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM lighting_logs
        WHERE event_type = 'on' AND DATE(event_time) = ?
    ");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $daily_on_data[] = (int)($res['cnt'] ?? 0);
    $stmt->close();
}

$conn->close();

// ── Compute estimated kWh per classroom ──────────────────────
// Rough estimate: each ON event assumed 1 hour for prototype
foreach ($classroom_stats as &$stat) {
    $hours_on      = $stat['on_count'];             // 1 hr per ON event
    $kwh           = (TOTAL_WATTS * $hours_on) / 1000;
    $stat['hours'] = $hours_on;
    $stat['kwh']   = round($kwh, 3);
}
unset($stat);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Energy Analytics – LumineSense Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js for bar chart -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
<div class="dashboard-wrapper">

    <?php include 'admin-sidebar.php'; ?>

    <div class="dashboard-main">

        <div class="dashboard-topbar">
            <h1 class="topbar-title">Energy Analytics</h1>
            <div class="topbar-right">
                <span style="color:#888; font-size:0.82rem;">Past 7 days</span>
            </div>
        </div>

        <div class="dashboard-content">

            <!-- Prototype notice -->
            <div class="alert-banner" style="margin-bottom:20px;">
                <i class="bi bi-info-circle"></i>
                <span><strong>Prototype mode:</strong> Energy figures are estimated based on logged ON events × 27W (9 bulbs × 3W). Live wattage readings will replace these once the Arduino ACS712 sensor feeds data.</span>
            </div>

            <!-- ── Per-Classroom Stats ────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-lightning-charge-fill"></i> This Week – Per Classroom</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($classroom_stats)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:24px 0;">No data yet.</p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>Size</th>
                                <th>ON Events</th>
                                <th>Est. Hours ON</th>
                                <th>Est. Energy Used</th>
                                <th>Alerts</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classroom_stats as $s): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($s['room_name']) ?></strong></td>
                                <td><span class="badge-info"><?= ucfirst($s['room_size']) ?></span></td>
                                <td><?= $s['on_count'] ?></td>
                                <td><?= $s['hours'] ?> hr(s)</td>
                                <td>
                                    <strong><?= $s['kwh'] ?> kWh</strong>
                                    <span style="color:#aaa; font-size:0.75rem; margin-left:4px;">(@ <?= TOTAL_WATTS ?>W)</span>
                                </td>
                                <td>
                                    <?php if ($s['alert_count'] > 0): ?>
                                    <span class="badge-alert"><?= $s['alert_count'] ?> alert(s)</span>
                                    <?php else: ?>
                                    <span style="color:#ccc;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Daily ON Events Chart ──────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-bar-chart-fill"></i> Daily Light Activations – Past 7 Days</h6>
                </div>
                <div class="panel-body">
                    <?php
                    $has_data = array_sum($daily_on_data) > 0;
                    if (!$has_data): ?>
                    <div class="chart-placeholder">
                        <i class="bi bi-bar-chart"></i>
                        No activation data yet. Once the Arduino starts logging, the chart will appear here.
                    </div>
                    <?php else: ?>
                    <canvas id="daily-chart" height="100"></canvas>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Formula Reference ─────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-calculator"></i> Energy Formula Reference</h6>
                </div>
                <div class="panel-body">
                    <p style="font-size:0.85rem; color:#555; margin:0 0 10px;">
                        <strong>Formula used:</strong> kWh = (Total Watts × Hours ON) ÷ 1000
                    </p>
                    <p style="font-size:0.85rem; color:#555; margin:0 0 10px;">
                        <strong>Prototype setup:</strong> 9 bulbs × 3W = <strong>27W total</strong> per activation.
                    </p>
                    <p style="font-size:0.85rem; color:#555; margin:0;">
                        <strong>Example:</strong> If lights were ON for 6 hours → 27W × 6h ÷ 1000 = <strong>0.162 kWh</strong>
                    </p>
                </div>
            </div>

        </div>
    </div>
</div>

<?php if (isset($has_data) && $has_data): ?>
<script>
const ctx = document.getElementById('daily-chart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($daily_labels) ?>,
        datasets: [{
            label: 'Light ON Events',
            data: <?= json_encode($daily_on_data) ?>,
            backgroundColor: 'rgba(224, 168, 0, 0.7)',
            borderColor: 'rgba(224, 168, 0, 1)',
            borderWidth: 1.5,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => `${ctx.parsed.y} activation(s)`
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, font: { size: 11 } },
                grid: { color: '#f0f2f7' }
            },
            x: {
                ticks: { font: { size: 11 } },
                grid: { display: false }
            }
        }
    }
});
</script>
<?php endif; ?>
</body>
</html>
