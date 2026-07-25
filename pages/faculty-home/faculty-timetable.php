<?php
date_default_timezone_set('Asia/Manila');
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

// Handle extend request POST (new or edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    $extend_mins = (int)($_POST['extend_mins'] ?? 30);

    // If editing, first remove the old pending request
    if (isset($_POST['edit_ext_request']) && !empty($_POST['edit_ext_request'])) {
        $edit_id = (int)$_POST['edit_ext_request'];
        $stmt = $conn->prepare("DELETE FROM extension_requests WHERE id = ? AND faculty_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $edit_id, $faculty_id);
        $stmt->execute();
        $stmt->close();
    }

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
        // Check if there's a succeeding schedule in the same room
        $stmt = $conn->prepare("
            SELECT s2.id, s2.start_time, s2.end_time, c.room_name, sub.name AS subject_name
            FROM schedules s1
            JOIN schedules s2 ON s2.classroom_id = s1.classroom_id
                             AND s2.day_of_week = s1.day_of_week
                             AND s2.start_time >= COALESCE(s1.extended_until, s1.end_time)
                             AND s2.start_time < ADDTIME(COALESCE(s1.extended_until, s1.end_time), SEC_TO_TIME(? * 60))
                             AND s2.id != s1.id
            JOIN classrooms c ON c.id = s2.classroom_id
            LEFT JOIN subjects sub ON sub.id = s2.subject_id
            WHERE s1.id = ?
            ORDER BY s2.start_time
            LIMIT 1
        ");
        $stmt->bind_param('ii', $extend_mins, $schedule_id);
        $stmt->execute();
        $successor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($successor) {
            $_SESSION['timetable_error'] = 'Cannot request extension: There is a succeeding schedule in ' . htmlspecialchars($successor['room_name'])
                . ' at ' . date('g:i A', strtotime($successor['start_time'])) . '.';
            $_SESSION['room_conflict_successor'] = [
                'room' => $successor['room_name'],
                'start' => date('g:i A', strtotime($successor['start_time'])),
                'end' => date('g:i A', strtotime($successor['end_time'])),
                'subject' => $successor['subject_name'] ?? 'N/A'
            ];
            header('Location: faculty-timetable.php');
            exit;
        }

        // Daily limit check for today's slots
        $today_dow = date('l');
        $sched_day = '';
        $stmt = $conn->prepare("SELECT day_of_week FROM schedules WHERE id = ?");
        $stmt->bind_param('i', $schedule_id);
        $stmt->execute();
        $stmt->bind_result($sched_day);
        $stmt->fetch();
        $stmt->close();

        if ($sched_day === $today_dow) {
            $remaining = 3;
            $stmt = $conn->prepare("
                SELECT 3 - COUNT(*) AS remaining
                FROM extension_requests er
                JOIN schedules s ON s.id = er.schedule_id
                WHERE er.faculty_id = ? AND s.day_of_week = ?
                AND er.status IN ('pending', 'approved')
            ");
            $stmt->bind_param('is', $faculty_id, $today_dow);
            $stmt->execute();
            $stmt->bind_result($remaining);
            $stmt->fetch();
            $stmt->close();

            if ($remaining <= 0) {
                $_SESSION['show_limit_modal'] = true;
                header('Location: faculty-timetable.php');
                exit;
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO extension_requests (schedule_id, faculty_id, extend_mins)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param('iii', $schedule_id, $faculty_id, $extend_mins);
        $stmt->execute();
        $inserted_id = $stmt->insert_id;
        $stmt->close();

        // ── Auto-approve if grace period is enabled ────────────────────
        $auto_approved = false;
        $r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_minutes'");
        $grace_minutes = $r && $row = $r->fetch_assoc() ? (int)$row['setting_value'] : 0;

        if ($grace_minutes > 0) {
            $today = date('l');
            $stmt = $conn->prepare("
                SELECT COALESCE(extended_until, end_time) AS current_end, classroom_id
                FROM schedules
                WHERE id = ? AND day_of_week = ?
            ");
            $stmt->bind_param('is', $schedule_id, $today);
            $stmt->execute();
            $stmt->bind_result($current_end, $classroom_id);
            $found = $stmt->fetch();
            $stmt->close();

            if ($found) {
                $new_end = date('H:i:s', strtotime($current_end) + ($extend_mins * 60));

                $upd = $conn->prepare("UPDATE extension_requests SET status = 'approved', reviewed_at = NOW() WHERE id = ?");
                $upd->bind_param('i', $inserted_id);
                $upd->execute();
                $upd->close();

                $upd = $conn->prepare("UPDATE schedules SET extended_until = ? WHERE id = ?");
                $upd->bind_param('si', $new_end, $schedule_id);
                $upd->execute();
                $upd->close();

                $checkCol = $conn->query("SHOW COLUMNS FROM classrooms LIKE 'schedule_dirty'");
                if ($checkCol && $checkCol->num_rows > 0) {
                    $conn->query("UPDATE classrooms SET schedule_dirty = 1 WHERE id = {$classroom_id}");
                }

                $auto_approved = true;
            }
        }

        $_SESSION['timetable_success'] = $auto_approved ? 'Extension request auto-approved!' : 'Extension request submitted!';
    } else {
        $_SESSION['timetable_error'] = 'You already have a pending request for this slot.';
    }

    header('Location: faculty-timetable.php');
    exit;
}

// Handle end early POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['end_early'])) {
    $sched_id = (int)$_POST['end_early'];

    $stmt = $conn->prepare("
        SELECT s.classroom_id, c.room_name
        FROM schedules s
        JOIN classrooms c ON c.id = s.classroom_id
        WHERE s.id = ? AND s.faculty_id = ?
    ");
    $stmt->bind_param('ii', $sched_id, $faculty_id);
    $stmt->execute();
    $stmt->bind_result($cid, $room_name);
    $has_row = $stmt->fetch();
    $stmt->close();

    if ($has_row && $cid) {
        $conn->query("UPDATE schedules SET extended_until = CURTIME() WHERE id = $sched_id");
        $conn->query("UPDATE classrooms SET light_status = 'off', row1_status = 'off', row2_status = 'off', row3_status = 'off', schedule_dirty = 1 WHERE id = $cid");
        $conn->query("INSERT INTO lighting_logs (classroom_id, faculty_id, event_type, triggered_by) VALUES ($cid, $faculty_id, 'off', 'faculty_end_early')");

        $_SESSION['timetable_success'] = "Class in {$room_name} ended early.";
    } else {
        $_SESSION['timetable_error'] = 'Schedule not found or access denied.';
    }

    header('Location: faculty-timetable.php');
    exit;
}

// Handle delete extension request POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_ext_request'])) {
    $ext_req_id = (int)$_POST['delete_ext_request'];

    $stmt = $conn->prepare("DELETE FROM extension_requests WHERE id = ? AND faculty_id = ?");
    $stmt->bind_param('ii', $ext_req_id, $faculty_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['timetable_success'] = 'Extension request cancelled.';
    header('Location: faculty-timetable.php');
    exit;
}

// Current schedule label
$today = date('l');
$current_sched = 'No class right now';
$now = date('H:i:s');

// Full weekly schedule
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$schedule_by_day = [];
foreach ($days as $day) $schedule_by_day[$day] = [];

$r = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time,
           s.extended_until, c.room_name, s.subject_id, sub.name AS subject_name,
           (SELECT status FROM extension_requests
            WHERE schedule_id = s.id AND faculty_id = $faculty_id
            ORDER BY requested_at DESC LIMIT 1) AS ext_status
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'),
             s.start_time
");
while ($row = $r->fetch_assoc()) {
    $schedule_by_day[$row['day_of_week']][] = $row;
}

// ── Active schedule for timer (for Time Left widget) ─────────────────────────
$fid      = (int)$faculty_id;
$today_e  = $conn->real_escape_string($today);
$now_e    = $conn->real_escape_string($now);

$r = $conn->query("
    SELECT s.id, s.classroom_id, s.start_time, s.end_time, s.extended_until, c.room_name, sub.name AS subject_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = $fid
      AND s.day_of_week = '$today_e'
      AND s.start_time <= '$now_e'
      AND (s.extended_until >= '$now_e' OR s.end_time >= '$now_e')
    ORDER BY s.start_time
    LIMIT 1
");
$active_schedule = ($r && $r->num_rows > 0) ? $r->fetch_assoc() : null;
$active_schedule_end = $active_schedule ? ($active_schedule['extended_until'] ?? $active_schedule['end_time']) : '';
if ($active_schedule) {
    $end_display = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
    $current_sched = $active_schedule['room_name'] . ' · '
        . ($active_schedule['subject_name'] ?? 'Class')
        . ' (' . date('g:i A', strtotime($active_schedule['start_time']))
        . ' - ' . date('g:i A', strtotime($end_display)) . ')';
}

// ── Current & Next class from today's schedule ───────────────────────
$current_class = null;
$next_class = null;
foreach ($schedule_by_day[$today] as $slot) {
    $slot_end = $slot['extended_until'] ?? $slot['end_time'];
    if ($slot['start_time'] <= $now && $slot_end >= $now) {
        $current_class = $slot;
    } elseif ($slot['start_time'] > $now && $next_class === null) {
        $next_class = $slot;
    }
}

// Build schedules array for View Schedule modal
$schedules = [];
$r2 = $conn->query("
    SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name, sub.name AS subject_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.faculty_id = $faculty_id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
");
while ($row = $r2->fetch_assoc()) {
    $schedules[] = $row;
}

// ── Faculty coverage, department & last-edit info ─────────────────────
$coverage = [];
$has_any_subject = false;
$member_name = $faculty_name;
$dept_name = 'N/A';
$head_name = 'N/A';
$last_edited = null;
$edited_by_name = '';

$dept_names = [];
$dept_q = $conn->query("
    SELECT d.id, d.name, d.head_faculty_id,
           CONCAT(f.first_name, ' ', f.last_name) AS head_name
    FROM departments d
    JOIN junction_faculty_department jfd ON jfd.department_id = d.id
    LEFT JOIN faculty f ON f.id = d.head_faculty_id
    WHERE jfd.faculty_id = $faculty_id AND d.status = 'active'
");
if ($dept_q && $dept_q->num_rows > 0) {
    while ($dept_row = $dept_q->fetch_assoc()) {
        $dept_names[] = $dept_row['name'];
        if ($head_name === 'N/A' && !empty($dept_row['head_name'])) {
            $head_name = $dept_row['head_name'];
        }
        $dept_id = (int)$dept_row['id'];

        $sa_q = $conn->query("
            SELECT sa.id, sa.name, d.name AS department_name
            FROM subject_area sa
            JOIN departments d ON d.id = sa.department_id
            JOIN junction_faculty_subjectarea jfsa ON jfsa.subject_area_id = sa.id
            WHERE jfsa.faculty_id = $faculty_id AND sa.department_id = $dept_id
            ORDER BY sa.name
        ");
        while ($sa = $sa_q->fetch_assoc()) {
            $subj_q = $conn->query("
                SELECT s.name
                FROM subjects s
                JOIN junction_faculty_subject jfs ON jfs.subject_id = s.id
                WHERE jfs.faculty_id = $faculty_id AND s.subject_area_id = {$sa['id']}
                ORDER BY s.name
            ");
            $subjects = [];
            while ($sub = $subj_q->fetch_assoc()) {
                $subjects[] = $sub['name'];
            }
            $sa['subjects'] = $subjects;
            $coverage[] = $sa;
        }
    }
    $dept_name = implode(', ', $dept_names);
}
foreach ($coverage as $sa) {
    if (!empty($sa['subjects'])) {
        $has_any_subject = true;
        break;
    }
}

$le_q = $conn->query("
    SELECT s.updated_at,
           CONCAT(f.first_name, ' ', f.last_name) AS editor_name
    FROM schedules s
    LEFT JOIN faculty f ON f.id = s.updated_by
    WHERE s.faculty_id = $faculty_id AND s.updated_at IS NOT NULL
    ORDER BY s.updated_at DESC
    LIMIT 1
");
if ($le_q && $le_q->num_rows > 0) {
    $le = $le_q->fetch_assoc();
    $last_edited = $le['updated_at'];
    $edited_by_name = $le['editor_name'] ?? '';
}

// ── Extension requests by this faculty ───────────────────────────────
$extension_requests = [];
$er_q = $conn->query("
    SELECT er.id, er.schedule_id, er.extend_mins, er.status, er.requested_at,
           s.day_of_week, s.start_time, s.end_time, s.extended_until, c.room_name, sub.name AS subject_name
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    JOIN classrooms c ON c.id = s.classroom_id
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE er.faculty_id = $faculty_id AND s.day_of_week = '$today'
    ORDER BY er.requested_at DESC
");
if ($er_q) {
    while ($er_row = $er_q->fetch_assoc()) {
        $extension_requests[] = $er_row;
    }
}

// ── Daily extension limit ──────────────────────────────────────────
$extensions_left_today = 3;
$today_dow = date('l');
$limit_q = $conn->prepare("
    SELECT 3 - COUNT(*) AS remaining
    FROM extension_requests er
    JOIN schedules s ON s.id = er.schedule_id
    WHERE er.faculty_id = ? AND s.day_of_week = ?
    AND er.status IN ('pending', 'approved')
");
if ($limit_q) {
    $limit_q->bind_param('is', $faculty_id, $today_dow);
    $limit_q->execute();
    $limit_q->bind_result($extensions_left_today);
    $limit_q->fetch();
    $limit_q->close();
}

function ordinal(int $number): string
{
    if (!in_array($number % 100, [11, 12, 13])) {
        $suffix = match ($number % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th'
        };
    } else {
        $suffix = 'th';
    }
    return $number . $suffix;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--External links-->
    <link rel="stylesheet" href="https://cloudflare.com">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <!--Relative links-->
    <link rel="icon" type="image/png" href="../../images/icon.png">

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-timetable.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../../css/faculty-head-timetable.css">
    <link rel="stylesheet" href="../../css/faculty-common.css">
    <link rel="stylesheet" href="../../css/faculty-settings.css">

    <title>Class Schedule – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <div class="d-flex flex-row" style="width:100%;flex:1;position:relative;">


            <div class="child-container gap-3" style="flex:1;min-width:0;">

                <!-- Intro Heading -->
                <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto" style="position:relative; background-color: var(--secondary-color-2);">
                    <div class="d-flex gap-2" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn" data-panel="panelTimeLeft" title="Time Left">
                            <span class="timetable-btn-title bold">Time Left</span>
                            <i class="bi bi-clock me-2"></i>
                            <span class="notif-dot"></span>
                        </button>
                        <button type="button" class="timetable-btn" data-panel="panelClassDetails" title="Class Details">
                            <span class="timetable-btn-title bold">Class<br>Details</span>
                            <i class="bi bi bi-easel me-2"></i>
                            <span class="notif-dot"></span>
                        </button>
                        <button type="button" class="timetable-btn" data-panel="panelExtRequests" title="Extension Requests">
                            <span class="timetable-btn-title bold">Extensions<br>Today</span>
                            <i class="bi bi-clock-history me-2"></i>
                            <span class="notif-dot"></span>
                        </button>
                    </div>
                    <div class="d-flex gap-2" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn" data-panel="panelCoverage" title="Coverage Details">
                            <span class="timetable-btn-title bold">Your<br>Coverage</span>
                            <i class="bi bi-briefcase me-2"></i>
                            <span class="notif-dot"></span>
                        </button>
                        <button type="button" class="timetable-btn" data-panel="panelInfo" title="Schedule Info">
                            <span class="timetable-btn-title bold">Schedule<br>Info</span>
                            <i class="bi bi-info-circle me-2"></i>
                        </button>
                        <button type="button" class="timetable-btn" id="exportPdfBtn" title="Export PDF" data-bs-toggle="tooltip" data-bs-placement="auto">
                            <span class="timetable-btn-title bold">Export<br>PDF</span>
                            <i class="bi bi-filetype-pdf me-2"></i>
                        </button>
                    </div>
                    <div class="p-2" style="color: #fff; background-color: var(--secondary-color-1); border-radius: 5px;">
                        <h2 class="bold">Class Timetable for <?= $faculty_name ?></h2>
                        <?php
                        $today_num = date('j');
                        $today_month_name = date('F');
                        $today_year = date('Y');
                        ?>
                        <p class="text-uppercase text-center mb-0 " style="font-size: 14px; color: var(--muted-white); ">
                            Today is the <span class="bold"><?= ordinal((int)$today_num) ?> </span>
                            day of <span class="bold"><?= $today_month_name ?></span>,
                            of school year <span class="bold"><?= $today_year ?></span>
                        </p>
                    </div>

                    <!-- Time Left panel -->
                    <div id="panelTimeLeft" class="timetable-panel p-3 m-3">
                        <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                            <div class="section-topbar mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold"><i class="bi bi-clock me-1"></i>Time Left <span class="medium text-muted fs-6">until end of class</span></h2>
                                </div>
                                <div class="d-flex mx-2 align-items-center justify-content-end">
                                </div>
                            </div>
                            <div class="gap-1 align-items-center  d-flex flex-column">
                                <div class="subsection-container d-flex flex-column mx-1 align-items-center justify-content-center">
                                    <?php if ($active_schedule): ?>
                                        <?php
                                        $end = $active_schedule['extended_until'] ?? $active_schedule['end_time'];
                                        ?>
                                        <h1 class="bold display-1 p-2" style="color: var(--secondary-color-2);" id="timerDisplay" data-end="<?= htmlspecialchars($end) ?>">
                                            --:--:--
                                        </h1>
                                    <?php else: ?>
                                        <h1 class="bold display-1 p-2" style="font-size: 5rem; color: var(--secondary-color-2);" id="timerDisplay">00:00:00</h1>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex flex-row mx-2 align-items-end justify-content-center">
                                    <?php if ($active_schedule): ?>
                                        <?php
                                        $start_12h = date('g:i A', strtotime($active_schedule['start_time']));
                                        $end_12h = date('g:i A', strtotime($active_schedule['end_time']));
                                        ?>
                                        <button class="light" style="width:auto;" onclick="requestExtend(<?= $active_schedule['id'] ?>, '<?= htmlspecialchars($active_schedule['room_name']) ?>', '<?= $start_12h ?>', '<?= $end_12h ?>')">
                                            <i class="bi bi-clock-history me-1"></i> Extend
                                        </button>
                                        <button class="danger px-2" style="width:auto;" onclick="openEndEarlyModal(<?= $active_schedule['id'] ?>, '<?= htmlspecialchars($active_schedule['room_name']) ?>')">
                                            <i class="bi bi-stop-circle me-1"></i> End Early
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$active_schedule): ?>
                                        <p class="text-muted text-center mt-2 mb-1">No active class schedule right now.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Class Details panel -->
                    <div id="panelClassDetails" class="timetable-panel p-3 m-3">
                        <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                            <div class="section-topbar d-flex flex-column mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold"><i class="bi bi-easel me-1"></i>Class Details</h2>
                                </div>
                                <div class="d-flex mx-2 align-items-center justify-content-end">
                                </div>
                            </div>
                            <div class="d-flex flex-column mx-1 gap-3 align-items-center justify-content-center mb-3">
                                <?php if ($current_class): ?>
                                    <div class="subsection-container p-3" style="background-color: var(--secondary-color-1); color: #fff; width: 100%;">
                                        <h2 class="bold text-uppercase" style="color: #fff;">Current</h2>
                                        <h2 class="medium fs-6" style="color: #fff;"><i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($current_class['start_time'])) ?> – <?= date('g:i A', strtotime($current_class['extended_until'] ?? $current_class['end_time'])) ?></h2>
                                        <h2 class="medium fs-6" style="color: #fff;"><i class="bi bi-door-open me-1"></i>Room: <?= htmlspecialchars($current_class['room_name']) ?></h2>
                                        <h2 class="medium fs-6" style="color: #fff;"><i class="bi bi-book me-1"></i>Subject: <?= htmlspecialchars($current_class['subject_name'] ?? 'N/A') ?></h2>
                                    </div>
                                <?php elseif (!$current_class && !$next_class): ?>
                                    <div class="d-flex align-items-center justify-content-center w-100">
                                        <p class="text-muted text-center my-2">No classes scheduled for today.</p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($next_class): ?>
                                    <div class="p-3" style="width: 100%;">
                                        <h2 class="bold text-uppercase" style="font-size: 14px;">Next</h2>
                                        <h2 class="medium fs-6" style="font-size: 14px;"><i class="bi bi-clock me-1"></i><?= date('g:i A', strtotime($next_class['start_time'])) ?> – <?= date('g:i A', strtotime($next_class['end_time'])) ?></h2>
                                        <h2 class="medium fs-6" style="font-size: 14px;"><i class="bi bi-door-open me-1"></i>Room: <?= htmlspecialchars($next_class['room_name']) ?></h2>
                                        <h2 class="medium fs-6" style="font-size: 14px;"><i class="bi bi-book me-1"></i>Subject: <?= htmlspecialchars($next_class['subject_name'] ?? 'N/A') ?></h2>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Extension Requests panel -->
                    <div id="panelExtRequests" class="timetable-panel p-3 m-3">
                        <div style="background-color: #f8f9fa;" class="section-container timetable overflow-hidden d-flex flex-column">
                            <div class="section-topbar flex-shrink-0 d-flex flex-column mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold"><i class="bi bi-clock-history me-1"></i>Extension Requests for Today</h2>
                                </div>
                                <div>
                                    <span class="badge text-dark fs-6 px-3 py-2" style="background-color: var(--accent-yellow);" id="extensionsLeftBadge">Time Extensions Left for Today: <?= max(0, $extensions_left_today) ?></span>
                                </div>
                            </div>
                            <div id="extTodayContainer" class="d-flex flex-column gap-2 p-2 overflow-auto flex-grow-1" style="max-height:20vh;"></div>
                            <hr class="mx-2 my-1">
                            <div class="section-topbar flex-shrink-0 mx-2">
                                <h2 class="bold" style="font-size:1rem;"><i class="bi bi-calendar me-1"></i>Other Extension Requests</h2>
                            </div>
                            <div id="extOtherContainer" class="d-flex flex-column gap-2 p-2 overflow-auto flex-grow-1" style="max-height:20vh;"></div>
                        </div>
                    </div>



                    <!-- Coverage panel -->
                    <div id="panelCoverage" class="timetable-panel panel-from-right p-3 m-3">
                        <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                            <div class="section-topbar mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold"><i class="bi bi-briefcase me-1"></i>Assigned Coverage</h2>
                                </div>
                            </div>
                            <div class="d-flex flex-column p-2 gap-2" style="max-height:25vh;overflow-y:auto;">
                                <?php if (!empty($coverage)): ?>
                                    <?php foreach ($coverage as $sa): ?>
                                        <div class="dept-info-card mb-0 p-2">
                                            <div class="small bold" style="color: var(--secondary-color-1);">
                                                <i class="bi bi-diagram-3"></i> <?= htmlspecialchars($sa['department_name']) ?>
                                            </div>
                                            <div class="mt-1">
                                                <i class="bi bi-briefcase"></i>
                                                <span class="dept-subject-area bold dept-emphases"><?= htmlspecialchars($sa['name']) ?></span>
                                            </div>
                                            <?php if (!empty($sa['subjects'])): ?>
                                                <div class="d-flex flex-wrap gap-1 mt-1">
                                                    <i class="bi bi-book"></i>
                                                    <?php foreach ($sa['subjects'] as $subj_name): ?>
                                                        <span class="subarea-subject bold dept-emphases"><?= htmlspecialchars($subj_name) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="small text-muted mt-1">No subjects under this area.</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="text-muted">No subject areas assigned.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Info panel -->
                    <div id="panelInfo" class="timetable-panel panel-from-right p-3 m-3">
                        <div style="background-color: #f8f9fa;" class="section-container timetable mb-3">
                            <div class="section-topbar mx-2 justify-content-between">
                                <div>
                                    <h2 class="bold"><i class="bi bi-info-circle me-1"></i>Schedule Info</h2>
                                </div>
                            </div>
                            <div class="d-flex flex-column p-3 gap-2">
                                <div class="dept-info-card mb-0 p-2">
                                    <div class="small text-muted">Prepared by</div>
                                    <div><span class="status-badge faculty-head bold">Faculty Head</span> <strong><?= !empty($edited_by_name) ? htmlspecialchars($edited_by_name) : htmlspecialchars($head_name) ?></strong></div>
                                </div>
                                <div class="dept-info-card mb-0 p-2">
                                    <div class="small text-muted">Last Edited</div>
                                    <div><strong><?= $last_edited ? date('F j, Y (g:i A)', strtotime($last_edited)) : 'No schedules yet' ?></strong></div>
                                </div>
                                <div class="dept-info-card mb-0 p-2">
                                    <div class="small text-muted">Current Department</div>
                                    <div><strong><?= htmlspecialchars($dept_name) ?></strong></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Timetable Itself -->
                <div class="main-container homepage gap-3" style="flex-direction:column;">
                    <?php if (!empty($_SESSION['timetable_success'])): ?>
                        <script>document.addEventListener('DOMContentLoaded',function(){showToast(<?= json_encode($_SESSION['timetable_success']) ?>);});</script>
                        <?php unset($_SESSION['timetable_success']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['timetable_error'])): ?>
                        <script>document.addEventListener('DOMContentLoaded',function(){showToast(<?= json_encode($_SESSION['timetable_error']) ?>);});</script>
                        <?php unset($_SESSION['timetable_error']); ?>
                    <?php endif; ?>

                    <!-- Weekly schedule -->
                    <?php
                    $dow_map = ['Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6];
                    $today_dow_num = $dow_map[$today];
                    $day_date_map = [];
                    foreach ($days as $day) {
                        $diff = $dow_map[$day] - $today_dow_num;
                        $dt = new DateTime("$diff days");
                        $day_date_map[$day] = strtoupper($dt->format('M j'));
                    }
                    ?>
                    <div class="weekly-schedule-grid">
                        <?php foreach ($days as $day):
                            $is_today = ($day === $today);
                            $slots    = $schedule_by_day[$day];
                        ?>
                            <div class="day-card <?= $is_today ? 'today' : '' ?>">
                                <div class="day-label">
                                    <div class="text-uppercase small fw-bold mb-1" style="font-size:11px;letter-spacing:0.5px;color:<?= $is_today ? '#fff' : '#6c757d' ?>;"><?= $day_date_map[$day] ?? '' ?></div>
                                    <?= $day ?> <?= $is_today ? '· Today' : '' ?>
                                </div>

                                <?php if (empty($slots)): ?>
                                    <p class="no-sched">No classes scheduled.</p>
                                    <?php else: foreach ($slots as $slot):
                                        $start     = date('g:i A', strtotime($slot['start_time']));
                                        $end       = date('g:i A', strtotime($slot['extended_until'] ?? $slot['end_time']));
                                        $modal_end = date('g:i A', strtotime($slot['end_time']));
                                        $ext       = $slot['extended_until']
                                            ? date('g:i A', strtotime($slot['extended_until']))
                                            : null;
                                        $ext_status = $slot['ext_status'];
                                    ?>
                                        <div class="slot-row" data-slot-id="<?= $slot['id'] ?>">
                                            <div class="slot-time">
                                                <?php
                                                $start_parts = explode(' ', $start);
                                                $start_time_part = $start_parts[0];
                                                $start_ampm = $start_parts[1] ?? 'AM';
                                                $end_parts = explode(' ', $end);
                                                $end_time_part = $end_parts[0];
                                                $end_ampm = $end_parts[1] ?? 'AM';
                                                ?>
                                                <span class="slot-time-start"><?= $start_time_part ?></span>
                                                <span class="slot-time-separator">TO</span>
                                                <span class="slot-time-end"><?= $end_time_part ?></span>
                                                <span class="slot-time-ampm"><?= $end_ampm ?></span>
                                            </div>
                                            <div class="slot-content">
                                                <div class="slot-room">
                                                    <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($slot['room_name']) ?>
                                                </div>
                                                <div class="slot-subject d-flex flex-row">
                                                    <i class="bi bi-book me-1"></i>
                                                    <h5><?= htmlspecialchars($slot['subject_name'] ?? 'No subject') ?></h5>
                                                </div>
                                            </div>
                                            <div class="slot-actions">
                                                <?php if ($ext_status === 'pending'): ?>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>', '<?= htmlspecialchars($slot['subject_name'] ?? 'No subject') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <span class="badge-ext-pending"
                                                        title="Extension request pending"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-hourglass-bottom"></i>
                                                    </span>
                                                <?php elseif ($ext_status === 'approved'): ?>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>', '<?= htmlspecialchars($slot['subject_name'] ?? 'No subject') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <?php if ($is_today): ?>
                                                         <button class="extend-icon-btn"
                                                             onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>', '<?= $modal_end ?>')"
                                                             title="Request Another Extension"
                                                            data-bs-toggle="tooltip"
                                                            data-bs-placement="auto">
                                                            <i class="bi bi-clock-history"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <span class="badge-ext-approved"
                                                        title="Extension approved"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-check-circle"></i>
                                                    </span>
                                                <?php elseif ($ext_status === 'rejected'): ?>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>', '<?= htmlspecialchars($slot['subject_name'] ?? 'No subject') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="extend-icon-btn"
                                                         onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>', '<?= $modal_end ?>')"
                                                         title="Re-request Extension"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto">
                                                        <i class="bi bi-clock-history"></i>
                                                    </button>
                                                    <span class="badge-ext-rejected"
                                                        title="Extension rejected"
                                                        data-bs-toggle="tooltip">
                                                        <i class="bi bi-x-circle"></i>
                                                    </span>
                                                <?php else: ?>
                                                    <button class="btn-icon btn-icon-view"
                                                        title="View Details"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        onclick="openSlotDetails(<?= $slot['id'] ?>, '<?= htmlspecialchars($slot['day_of_week']) ?>', '<?= $start ?>', '<?= $end ?>', '<?= htmlspecialchars($slot['room_name']) ?>', '<?= htmlspecialchars($ext ?? '') ?>', '<?= htmlspecialchars($slot['subject_name'] ?? 'No subject') ?>')">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    <button class="extend-icon-btn"
                                                         onclick="requestExtend(<?= $slot['id'] ?>, '<?= $slot['room_name'] ?>', '<?= $start ?>', '<?= $modal_end ?>')"
                                                         title="Request Extension"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto">
                                                        <i class="bi bi-clock-history"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>


                <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto mb-3" style="background-color:var(--secondary-color-2);">
                    <div class="d-flex flex-row align-items-stretch gap-3" style="width:100%;flex-wrap:nowrap;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Request Extension Modal -->
        <div class="profile-details-modal modal fade" id="extendModal" tabindex="-1" aria-labelledby="extendModalLabel" aria-hidden="true">
            <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="extendModalLabel">
                            <i class="bi bi-clock-history me-2"></i>Request Time Extension
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="extend-description">
                            <span class="emphasis">
                                Requesting extension for
                                <span id="extend-room"></span>
                                from <span id="extend-start-time"></span>
                                to <span id="extend-end-time"></span>
                            </span>
                            <br>How many extra minutes do you need?
                        </p>
                        <div class="extend-modal-content d-flex gap-4">
                            <!-- LEFT DIV: Timer -->
                            <div class="extend-left-div">
                                <h2 class="time-elapsed-title">Time Elapsed</h2>
                                <h1 class="timer-display">
                                    <input type="text" class="timer-input" id="timer-hours" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-minutes" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-seconds" value="00" maxlength="2" />
                                </h1>
                                <div class="timer-labels d-flex gap-3 justify-content-center">
                                    <h6 class="timer-label">HOURS</h6>
                                    <h6 class="timer-label">MINUTES</h6>
                                    <h6 class="timer-label">SECONDS</h6>
                                </div>
                                <p class="extend-description mt-3" id="extend-description">
                                    Extending time for Math discussion at <span id="extend-room"></span> for <span id="extend-time-range"></span>
                                </p>
                            </div>

                            <!-- RIGHT DIV: Extend Buttons -->
                            <div class="extend-right-div d-flex flex-column align-items-center gap-3">

                                <h2 class="time-elapsed-title">Extend Time</h2>
                                <p class="extend-description mb-0">Add desired time:</p>

                                <div class="d-flex flex-column gap-2" id="extendPills">
                                    <?php foreach ([15, 30, 45, 60] as $mins): ?>
                                        <button class="btn btn-outline-primary extend-pill" data-mins="<?= $mins ?>">
                                            +<?= $mins ?> min
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                        <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="medium w-100" id="submitExtendBtn" disabled>
                            Send Request
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden form for extend submit -->
        <form id="extend-form" method="POST" action="faculty-timetable.php" style="display:none;">
            <input type="hidden" name="schedule_id" id="extend-schedule-id">
            <input type="hidden" name="extend_mins" id="extend-mins-val">
            <input type="hidden" name="edit_ext_request" id="extend-edit-id" value="">
        </form>

        <?php include '../../php/includes/faculty-sidebar.php'; ?>


        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
        <script src="../../script/tooltip.js"></script>
    </div>

    <script>
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
    </script>

    <!-- ── Countdown timer for Time Left widget (separate script to avoid errors) ── -->
    <script>
    (function() {
        var display = document.getElementById('timerDisplay');
        if (!display) return;
        var _scheduleEnd = display.dataset.end || null;

        function pad(n) { return String(n).padStart(2, '0'); }

        function setTimerColor(diff) {
            if (diff <= 900) { display.style.color = '#dc3545'; }
            else if (diff <= 1800) { display.style.color = '#ff8c00'; }
            else { display.style.color = 'var(--secondary-color-2)'; }
        }

        window._updateScheduleEnd = function(newEnd) {
            _scheduleEnd = newEnd;
            if (display) display.dataset.end = newEnd;
            // Keep speech/beep notification in sync
            var schedData = document.getElementById('scheduleEndData');
            if (schedData) schedData.dataset.end = newEnd;
            tick();
        };

        function tick() {
            if (!_scheduleEnd) {
                display.textContent = '00:00:00';
                display.style.color = '#6c757d';
                return;
            }
            var parts = _scheduleEnd.split(':').map(Number);
            var end = new Date();
            end.setHours(parts[0], parts[1], parts[2], 0);
            var diff = Math.max(0, Math.floor((end - Date.now()) / 1000));
            display.textContent = pad(Math.floor(diff / 3600)) + ':' + pad(Math.floor((diff % 3600) / 60)) + ':' + pad(diff % 60);
            setTimerColor(diff);
        }
        tick();
        setInterval(tick, 1000);

        // Poll schedule status to keep timer updated (like faculty-home.php)
        var pollCid = <?= $active_schedule ? (int)$active_schedule['classroom_id'] : 0 ?>;
        setInterval(function() {
            var cid = pollCid;
            if (!cid) return;
            fetch('../../api/faculty-status.php?classroom_id=' + cid)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.schedule_active && data.schedule_end) {
                        var current = _scheduleEnd || '';
                        if (data.schedule_end !== current) {
                            window._updateScheduleEnd(data.schedule_end);
                        }
                        // Keep notification data synced even if end time unchanged
                        var schedData = document.getElementById('scheduleEndData');
                        if (schedData && schedData.dataset.end !== data.schedule_end) {
                            schedData.dataset.end = data.schedule_end;
                        }
                    } else {
                        _scheduleEnd = null;
                    }
                })
                .catch(function() {});
        }, 5000);
    })();
    </script>



    <!-- Delete Confirmation Modal -->
    <div class="profile-details-modal modal fade" id="deleteExtModal" tabindex="-1" aria-labelledby="deleteExtLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="deleteExtLabel">
                        <i class="bi bi-exclamation-triangle me-2"></i>Cancel Extension Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to cancel this extension request? This action cannot be undone.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteExtForm" method="POST" action="faculty-timetable.php" style="display:contents;">
                        <input type="hidden" name="delete_ext_request" id="deleteExtId" value="">
                        <button type="submit" class="medium w-100" style="background:#dc3545;border-color:#dc3545;">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Extend Modal -->
    <div class="profile-details-modal modal fade" id="confirmExtendModal" tabindex="-1" aria-labelledby="confirmExtendLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="confirmExtendLabel">
                        <i class="bi bi-check-circle me-2"></i>Confirm Extension Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Please review the details below before submitting your extension request.</p>
                    <div class="dept-info-card p-3">
                        <div class="mb-2"><strong>Room:</strong> <span id="confirmExtendRoom"></span></div>
                        <div class="mb-2"><strong>Time:</strong> <span id="confirmExtendTime"></span></div>
                        <div class="mb-2"><strong>Extension:</strong> <span id="confirmExtendMins"></span></div>
                        <div><strong>Action:</strong> <span id="confirmExtendAction"></span></div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium w-100" id="confirmExtendBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Slot Details Modal -->
    <div class="profile-details-modal modal fade" id="viewSlotModal" tabindex="-1" aria-labelledby="viewSlotLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewSlotLabel">
                        <i class="bi bi-calendar-event me-2"></i>Schedule Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-calendar-week" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Day</div>
                                <strong id="slot-day"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-clock" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Time</div>
                                <strong id="slot-time"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-door-open" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Room</div>
                                <strong id="slot-room"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-book" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Subject</div>
                                <strong id="slot-subject"></strong>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                            <i class="bi bi-person-badge" style="font-size:1.6rem; flex-shrink:0; color:var(--secondary-color-4);"></i>
                            <div class="flex-grow-1">
                                <div class="text-muted">Faculty</div>
                                <strong id="slot-faculty"><?= htmlspecialchars($faculty_name) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Elapsed Time Warning Modal -->
    <div class="profile-details-modal modal fade" id="elapsedWarningModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#dc3545;">
                    <h5 class="modal-title bold"><i class="bi bi-exclamation-triangle me-2"></i>Invalid Time</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#dc3545;"></i>
                    <p class="mt-3 mb-1">The elapsed time shown (<strong id="elapsedWarningInput"></strong>) is less than the actual elapsed time (<strong id="elapsedWarningActual"></strong>).</p>
                    <p class="text-muted small">Please reset the timer to the correct elapsed time before submitting.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-center">
                    <button type="button" class="medium" id="elapsedWarningUnderstood">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Daily Limit Reached Modal -->
    <div class="profile-details-modal modal fade" id="limitModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold">
                        <i class="bi bi-exclamation-triangle me-2"></i>Daily Limit Reached
                    </h5>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-clock-history" style="font-size: 3rem; color: var(--secondary-color-2);"></i>
                    <p class="mt-3 mb-0">You have reached the daily limit of 3 extension requests for today.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-center">
                    <button type="button" class="medium w-50" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Room Schedule Conflict Modal -->
    <div class="profile-details-modal modal fade" id="roomConflictModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#dc3545;">
                    <h5 class="modal-title bold">
                        <i class="bi bi-exclamation-triangle me-2"></i>Extension Cannot Be Made
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-door-open" style="font-size: 3rem; color: #dc3545;"></i>
                    <p class="mt-3 mb-1">You cannot request an extension because there is a succeeding schedule in <strong id="conflictRoom"></strong>.</p>
                    <div class="dept-info-card p-3 mt-3 text-start">
                        <div class="mb-2"><strong>Next Schedule Details:</strong></div>
                        <div class="mb-1"><i class="bi bi-clock me-1"></i> <span id="conflictTime"></span></div>
                        <div class="mb-1"><i class="bi bi-book me-1"></i> <span id="conflictSubject"></span></div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-center">
                    <button type="button" class="medium w-50" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- End Early Confirm Modal -->
    <div class="profile-details-modal modal fade" id="endEarlyModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#dc3545;">
                    <h5 class="modal-title bold"><i class="bi bi-stop-circle me-2"></i>End Class Early</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#dc3545;"></i>
                    <p class="mt-3 mb-1">End your current class in <strong id="endEarlyRoom"></strong> early?</p>
                    <p class="text-muted small">Lights in this room will be turned off and the schedule will be marked as finished.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="end_early" id="endEarlySchedId" value="">
                        <button type="submit" class="medium w-100" style="background:#dc3545;border-color:#dc3545;">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openEndEarlyModal(schedId, roomName) {
            document.getElementById('endEarlyRoom').textContent = roomName;
            document.getElementById('endEarlySchedId').value = schedId;
            new bootstrap.Modal(document.getElementById('endEarlyModal')).show();
        }
    </script>

    <?php if (!empty($_SESSION['show_limit_modal'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var limitModal = new bootstrap.Modal(document.getElementById('limitModal'));
                limitModal.show();
            });
        </script>
    <?php unset($_SESSION['show_limit_modal']);
    endif; ?>

    <?php if (!empty($_SESSION['room_conflict_successor'])): ?>
        <?php
        $cs = $_SESSION['room_conflict_successor'];
        unset($_SESSION['room_conflict_successor']);
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('conflictRoom').textContent = '<?= htmlspecialchars($cs['room'], ENT_QUOTES) ?>';
                document.getElementById('conflictTime').textContent = '<?= htmlspecialchars($cs['start'], ENT_QUOTES) ?> - <?= htmlspecialchars($cs['end'], ENT_QUOTES) ?>';
                document.getElementById('conflictSubject').textContent = '<?= htmlspecialchars($cs['subject'], ENT_QUOTES) ?>';
                var conflictModal = new bootstrap.Modal(document.getElementById('roomConflictModal'));
                conflictModal.show();
            });
        </script>
    <?php endif; ?>

    <script>
        fetch('../../api/auto-approve-extensions.php').catch(function() {});
    </script>

    <script>
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
    </script>

    <script>
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
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('confirmPdfExportBtn').addEventListener('click', function () {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = '../../php/handlers/export-pdf-handler.php';
                form.style.display = 'none';
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
                var pdfModal = bootstrap.Modal.getInstance(document.getElementById('confirmPdfModal'));
                if (pdfModal) pdfModal.hide();
            });
        });
    </script>

    <!-- Confirm PDF Export Modal -->
    <div class="profile-details-modal modal fade" id="confirmPdfModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold">
                        <i class="bi bi-filetype-pdf me-2"></i>Export PDF
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-filetype-pdf" style="font-size: 3rem; color: var(--secondary-color-2);"></i>
                    <p class="mt-3 mb-0">Are you sure you want to export your class schedule as PDF?</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium w-100" id="confirmPdfExportBtn">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('exportPdfBtn').addEventListener('click', function () {
                new bootstrap.Modal(document.getElementById('confirmPdfModal')).show();
            });
        });
    </script>

    <style>
        .toast-wrap {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
        }
        .toast-msg {
            background: var(--secondary-color-1);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(0,0,0,.25);
            display: none;
        }
        .toast-msg.show {
            display: block;
            animation: fadeInUp 0.3s ease, fadeOut 0.4s ease 2.2s forwards;
        }
        @keyframes fadeInUp {
            from { opacity:0; transform:translateY(12px); }
            to   { opacity:1; transform:translateY(0); }
        }
        @keyframes fadeOut { to { opacity:0; } }
    </style>
    <div class="toast-wrap" id="toastWrap"><div class="toast-msg" id="toastMsg"></div></div>

    <script>
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
    </script>
    <script src="../../script/faculty-tutorial.js"></script>
</body>

</html>