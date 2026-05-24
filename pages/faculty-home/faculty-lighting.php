<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = $_SESSION['faculty_id'];
$name_parts   = explode(' ', $faculty_name);
$first_name   = $name_parts[0];
$initials     = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch email
$faculty_email = '';
$stmt = $conn->prepare('SELECT email FROM faculty WHERE id = ?');
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$stmt->bind_result($faculty_email);
$stmt->fetch();
$stmt->close();

// Current schedule
$today = date('l');
$current_sched = 'No class right now';
$r = $conn->query("
    SELECT s.start_time, s.end_time, c.room_name
    FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.day_of_week = '$today'
    ORDER BY s.start_time
");
$now = date('H:i:s');
while ($row = $r->fetch_assoc()) {
    if ($now >= $row['start_time'] && $now <= $row['end_time']) {
        $current_sched = $row['room_name'] . ' · '
            . date('g:i A', strtotime($row['start_time'])) . ' - '
            . date('g:i A', strtotime($row['end_time']));
        break;
    }
}

// Get first classroom for logging
$classroom_id = 1; // default fallback
$r = $conn->query("SELECT id FROM classrooms ORDER BY id LIMIT 1");
if ($row = $r->fetch_assoc()) $classroom_id = $row['id'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <title>Lighting – LumineSense</title>
</head>
<body class="contrast-bg">
<div class="parent-container">

    <!-- TOPBAR -->
    <div class="topbar d-flex">
        <button type="button" id="sidebarTrigger"><i class="bi bi-list"></i></button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Lighting Status</h1>
            <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
            <h4><?= $faculty_name ?></h4>
            <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <div class="child-container">
        <div class="main-container lightin-status gap-3">

            <!-- LEFT: Lighting Grid -->
            <div class="group-container gap-3">
                <div style="background-color:#f8f9fa;" class="fit-width section-container">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Lighting Grid</h2>
                        </div>
                        <div class="d-flex mx-2 align-items-end">
                            <button class="light mx-2" onclick="location.reload()">Refresh</button>
                        </div>
                    </div>
                    <div class="d-flex flex-row align-items-center justify-content-center">
                        <div class="lighting-grid" id="bulb-grid">
                            <!-- Row 1 -->
                            <img src="../../images/bulb-off.png" id="bulb-0" data-row="1">
                            <img src="../../images/bulb-off.png" id="bulb-1" data-row="1">
                            <img src="../../images/bulb-off.png" id="bulb-2" data-row="1">
                            <hr class="w-100">
                            <!-- Row 2 -->
                            <img src="../../images/bulb-off.png" id="bulb-3" data-row="2">
                            <img src="../../images/bulb-off.png" id="bulb-4" data-row="2">
                            <img src="../../images/bulb-off.png" id="bulb-5" data-row="2">
                            <hr class="w-100">
                            <!-- Row 3 -->
                            <img src="../../images/bulb-off.png" id="bulb-6" data-row="3">
                            <img src="../../images/bulb-off.png" id="bulb-7" data-row="3">
                            <img src="../../images/bulb-off.png" id="bulb-8" data-row="3">
                            <hr class="w-100">
                        </div>
                        <div class="p-5">
                            <!-- Row switches -->
                            <?php foreach ([1,2,3] as $row): ?>
                            <div class="d-flex flex-column align-items-center gap-1">
                                <label class="form-check-label" for="row-<?= $row ?>-switch">Row <?= $row ?></label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input row-switch"
                                           type="checkbox" role="switch"
                                           id="row-<?= $row ?>-switch"
                                           data-row="<?= $row ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <br>
                            <!-- All lights -->
                            <div class="d-flex flex-column align-items-center gap-1">
                                <h5 class="bold">All Lights</h5>
                                <h4 class="bold" id="all-lights-label">OFF</h4>
                                <div class="all-lights-off d-flex flex-column align-items-center justify-content-center"
                                     id="all-lights-btn">
                                    <i class="bi bi-power" id="all-lights"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Lighting Status gauges (unchanged) -->
            <div class="group-container gap-3">
                <div style="background-color:#f8f9fa;" class="section-container recents">
                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                        <div class="d-flex mx-2 align-items-start">
                            <h2 class="bold">Lighting Status</h2>
                        </div>
                    </div>
                    <div class="gauge-container d-flex pt-1 flex-column align-items-center justify-content-center gap-3">
                        <div class="gauge">
                            <canvas id="energyGauge"></canvas>
                            <div class="gauge-value"><span id="tempNumber">36</span><span class="gauge-unit"> kWh</span></div>
                            <div class="gauge-label bold">Overall Lighting Consumption</div>
                        </div>
                        <h6 class="text-center">Status: Normal<br>Max Room Consumption: 4 kWh</h6>
                        <div class="gauge">
                            <canvas id="luxGauge"></canvas>
                            <div class="gauge-value"><span id="humidNumber">58</span><span class="gauge-unit"> lux</span></div>
                            <div class="gauge-label bold">Overall Room Illuminance (lux)</div>
                        </div>
                        <h6 class="text-center">Max Room Illuminance: 300 lux</h6>
                    </div>
                </div>
            </div>

            <!-- SIDEBAR LEFT -->
            <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
                <div class="offcanvas-header justify-content-center">
                    <img src="../../images/logo.png" class="logo" onclick="dissolve('faculty-homepage.php')">
                </div>
                <div class="offcanvas-body align-items-center d-flex flex-column">
                    <button class="wb-2" onclick="dissolve('faculty-lighting.php')"><i class="bi bi-lightbulb"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-readings.php')"><i class="bi bi-broadcast"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-gesture.php')"><i class="bi bi-hand-thumbs-up"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-timetable.php')"><i class="bi bi-calendar-event"></i></button>
                    <button class="wb-2" onclick="dissolve('faculty-profile-settings.php')"><i class="bi bi-gear"></i></button>
                </div>
                <div class="offcanvas-footer">
                    <img src="../../images/team-logo.png" class="logo">
                </div>
            </div>

            <!-- SIDEBAR RIGHT (profile) -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
                <div class="offcanvas-body align-items-center d-flex flex-column">
                    <div class="avatar-icon d-flex align-items-center justify-content-center">
                        <h3 class="bold"><?= $initials ?></h3>
                    </div>
                    <h4 class="bold"><?= $faculty_name ?></h4>
                    <h6 class="light email-limit"><?= htmlspecialchars($faculty_email) ?></h6>
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <button onclick="dissolve('faculty-profile-settings.php')">Profile Settings</button>
                        <button>Classroom Details</button>
                        <button onclick="dissolve('../../php/logout.php')">Logout</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/analytics-gauge.js"></script>
</div>

<!-- ── Hidden PHP values for JS ── -->
<script>
    const CLASSROOM_ID  = <?= $classroom_id ?>;
    const FACULTY_ID    = <?= $faculty_id ?>;

    // ── Arduino config — fill these in when hardware is ready ──
    const ARDUINO_IP    = '192.168.x.x';   // TODO: replace with real IP
    const ARDUINO_PORT  = '80';             // TODO: replace with real port

    // Bulb image paths
    const BULB_ON  = '../../images/bulb-on.png';
    const BULB_OFF = '../../images/bulb-off.png';

    // Track row states
    const rowState = { 1: false, 2: false, 3: false };

    // ── Update bulb images for a row ──
    function updateBulbs(row, isOn) {
        document.querySelectorAll(`[data-row="${row}"]`).forEach(bulb => {
            bulb.src = isOn ? BULB_ON : BULB_OFF;
        });
    }

    // ── Update "All Lights" button label ──
    function updateAllLightsLabel() {
        const anyOn = Object.values(rowState).some(v => v);
        document.getElementById('all-lights-label').textContent = anyOn ? 'ON' : 'OFF';
        document.getElementById('all-lights-label').className   = anyOn ? 'bold on' : 'bold off';
    }

    // ── Log to DB + ping Arduino ──
    async function sendLightCommand(row, state) {
        const eventType   = state ? 'on' : 'off';
        const triggeredBy = 'manual';

        // 1. Log to DB
        const form = new FormData();
        form.append('classroom_id',  CLASSROOM_ID);
        form.append('faculty_id',    FACULTY_ID);
        form.append('event_type',    eventType);
        form.append('triggered_by',  triggeredBy);
        form.append('row',           row);
        await fetch('../../api/logs.php', { method: 'POST', body: form });

        // 2. Ping Arduino — TODO: update URL when hardware is ready
        try {
            await fetch(`http://${ARDUINO_IP}:${ARDUINO_PORT}/lights?row=${row}&state=${eventType}`, {
                method: 'GET',
                signal: AbortSignal.timeout(3000) // 3s timeout so it doesn't hang
            });
        } catch (e) {
            console.warn('Arduino not reachable yet — logged to DB only.');
        }
    }

    // ── Row switches ──
    document.querySelectorAll('.row-switch').forEach(sw => {
        sw.addEventListener('change', async function () {
            const row   = parseInt(this.dataset.row);
            const isOn  = this.checked;
            rowState[row] = isOn;
            updateBulbs(row, isOn);
            updateAllLightsLabel();
            await sendLightCommand(row, isOn);
        });
    });

    // ── All Lights button ──
    document.getElementById('all-lights-btn').addEventListener('click', async function () {
        const anyOn = Object.values(rowState).some(v => v);
        const newState = !anyOn; // toggle

        for (const row of [1, 2, 3]) {
            rowState[row] = newState;
            updateBulbs(row, newState);
            document.getElementById(`row-${row}-switch`).checked = newState;
            await sendLightCommand(row, newState);
        }
        updateAllLightsLabel();
    });

    // ── Sidebar triggers ──
    document.getElementById('sidebarTrigger').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('sidebarOffcanvas')).toggle();
    });
    document.getElementById('sidebarTrigger2').addEventListener('click', function () {
        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('profileOffcanvas')).toggle();
    });
</script>
</body>
</html>