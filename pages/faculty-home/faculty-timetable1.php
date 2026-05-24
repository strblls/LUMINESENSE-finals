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

// Handle extend request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $extend_mins = (int)($_POST['extend_mins'] ?? 30);

    // Check if there's already a pending request for this slot
    $stmt = $conn->prepare("
        SELECT id FROM extension_requests
        WHERE schedule_id = ? AND faculty_id = ? AND status = 'pending'
    ");
    $stmt->bind_param('ii', $schedule_id, $faculty_id);
    $stmt->execute();
    $stmt->store_result();
    $already_requested = $stmt->num_rows > 0;
    $stmt->close();

    if (!$already_requested) {
        $stmt = $conn->prepare("
            INSERT INTO extension_requests (schedule_id, faculty_id, extend_mins)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iii', $schedule_id, $faculty_id, $extend_mins);
        $stmt->execute();
        $stmt->close();
        $_SESSION['timetable_success'] = 'Extension request submitted!';
    } else {
        $_SESSION['timetable_error'] = 'You already have a pending request for this slot.';
    }

    header('Location: faculty-timetable.php');
    exit;
}

// Current schedule label
$today = date('l');
$current_sched = 'No class right now';
$now = date('H:i:s');

// Full weekly schedule
$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
$schedule_by_day = [];
foreach ($days as $day) $schedule_by_day[$day] = [];

$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time,
           s.extended_until, c.room_name,
           (SELECT status FROM extension_requests
            WHERE schedule_id = s.id AND faculty_id = $faculty_id
            ORDER BY requested_at DESC LIMIT 1) AS ext_status
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.created_by = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
    // Check current schedule
    if ($row['day_of_week'] === $today && $now >= $row['start_time'] && $now <= $row['end_time']) {
        $current_sched = $row['room_name'] . ' · '
            . date('g:i A', strtotime($row['start_time'])) . ' - '
            . date('g:i A', strtotime($row['end_time']));
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap and JS CDN-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <!--CSS files-->
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-timetable.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.1.0/fullcalendar.min.css">
    <title>Faculty Timetable - LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <!-- TOPBAR -->
        <div class="topbar d-flex">
            <button type="button" id="sidebarTrigger">
                <i class="bi bi-list"></i>
            </button>
            <div class="col d-flex flex-column px-3">
                <h1 class="bold">Class Schedule</h1> 
                <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
            </div>

            <div class="d-flex align-items-center justify-content-center gap-2 mx-2">  
                <h4><?= $faculty_name ?> </h4>
                <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                    <h3 class="bold"><?= $initials ?> </h3>
                </div>
            </div>
        </div>
        <div class="child-container homepage-modal">
            <div class="main-container homepage gap-3 flex-column">
                <div style="background-color: #f8f9fa;" class="section-container w-100 calendar-section">
                    <div class="calendar-shell">
                        <div class="calendar-grid mt-3">
                            <div class="row g-0">
                                <div class="col-12">
                                    <div id="calendar"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas"
                    aria-labelledby="sidebarOffcanvasLabel">
                    <div class="offcanvas-header justify-content-center">
                        <img src="../../images/logo.png" class="logo" onclick="dissolve('faculty-homepage.html')">
                    </div>
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <button class="wb-2" onclick="dissolve('faculty-lighting.html')"><i
                                class="bi bi-lightbulb"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-readings.html')"><i
                                class="bi bi-broadcast"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-gesture.html')"><i
                                class="bi bi-hand-thumbs-up"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-timetable.html')"><i
                                class="bi bi-calendar-event"></i></button>
                        <button class="wb-2" onclick="dissolve('faculty-profile-settings.html')"><i
                                class="bi bi-gear"></i></button>
                    </div>
                    <div class="offcanvas-footer">
                        <img src="../../images/team-logo.png" class="logo">
                    </div>
                </div>

                <!-- SIDEBAR RIGHT -->
                <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas"
                    aria-labelledby="sidebarOffcanvasLabel">
                    <div class="offcanvas-body align-items-center d-flex flex-column">
                        <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2">
                            <h3 class="bold"><?= $initials ?></h3> 
                        </div>
                        <h4 class="bold"><?= $faculty_name ?></h4>
                        <h6 class="light email-limit"><?= $faculty_email?></h6>
                        <div class="d-flex flex-column align-items-center justify-content-center">
                            <button onclick="dissolve('faculty-profile-settings.php')">Profile Settings</button>
                            <button onclick="dissolve('../../php/logout.php')">Logout</button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        
                <!--Profile Modal-->
                <div class="profile-details-modal modal fade" id="profileModal" tabindex="-1"
                    aria-labelledby="profileModalLabel" aria-hidden="true">
                    <div class="d-flex justify-content-center modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <div>
                                    <h5 class="modal-title" id="profileModalLabel">Profile</h5>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                    aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="card border-0 shadow-sm rounded-4">
                                    <div class="card-body p-4">
                                        <div class="d-flex flex-between align-items-center gap-3 mb-4">
                                            <div
                                                class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0">
                                                <h3 class="bold mb-0">JD</h3>
                                            </div>
                                            <div>
                                                <h4 class="bold mb-1">John Doe</h4>
                                                <p class="mb-0">Faculty Member</p>
                                            </div>
                                            <button type="button"
                                                class="edit-button btn btn-sm btn-light border rounded-circle ms-auto"
                                                aria-label="Edit profile details">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>

                                        <div class="row g-3">
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Email</small>
                                                    <p class="mb-0">j*******@raffles.uni.edu</p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Department</small>
                                                    <p class="mb-0">N/A</p>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <div class="p-3 bg-light rounded-3">
                                                    <small class="text-muted d-block">Address</small>
                                                    <p class="mb-0">N/A</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.17.1/moment.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/3.1.0/fullcalendar.min.js"></script>
        <script src="../../script/calendar-data.js"></script> <!--ALERT: PHP | DISPLAY (dynamic mag gwa ang events depende sa schedule)-->
        <script> 
            $(document).ready(function () {
                $('#calendar').fullCalendar({
                    header: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'month,basicWeek,basicDay'
                    },
                    defaultDate: moment().format('YYYY-MM-DD'),
                    navLinks: true,
                    editable: true,
                    eventLimit: true,
                    events: calendarEvents
                });
            });
        </script>
    </div>
</body>

</html>