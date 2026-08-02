<?php
$page_title = 'Dashboard';
require_once __DIR__ . "/../../src/Includes/admin-head.php";

/** @var int $total_rooms */
/** @var int $lights_on */
/** @var int $pending */
/** @var int $ext_pending */
/** @var bool $db_ok */
/** @var int $lights_data */
/** @var array $logs */
/** @var array $approval_logs */
/** @var array $classrooms */
/** @var string $schedules_json */

// - Faculty hierarchy data -----------------------
$hierarchy = [];
$dept_res = $conn->query("
    SELECT d.id, d.name, d.head_faculty_id, d.status, d.created_at,
           CONCAT(h.first_name,' ',h.last_name) AS head_name
    FROM departments d
    LEFT JOIN faculty h ON h.id = d.head_faculty_id
    WHERE d.status IN ('active','pending')
    ORDER BY d.name
");
while ($dept = $dept_res->fetch_assoc()) {
    $dept_id = (int)$dept['id'];

    // Department subjects & subject areas
    $dept_subjects = [];
    $subj_res = $conn->query("
        SELECT DISTINCT s.id, s.name FROM subjects s
        JOIN subject_area sa ON sa.subject_id = s.id
        WHERE sa.department_id = $dept_id
        ORDER BY s.name
    ");
    if (!$subj_res) {
        // fallback: subjects.department_id may exist in some schemas
        $subj_res = $conn->query("
            SELECT id, name FROM subjects WHERE department_id = $dept_id ORDER BY name
        ");
    }
    if ($subj_res) while ($s = $subj_res->fetch_assoc()) $dept_subjects[] = $s;

    $dept_subject_areas = [];
    $sa_res = $conn->query("
        SELECT id, name FROM subject_area WHERE department_id = $dept_id ORDER BY name
    ");
    if ($sa_res) while ($sa = $sa_res->fetch_assoc()) $dept_subject_areas[] = $sa;

    // Faculty members in this department (excluding the head)
    $members = [];
    $mem_res = $conn->query("
        SELECT f.id, f.first_name, f.last_name, f.approved_at
        FROM faculty f
        JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
        WHERE jfd.department_id = $dept_id
          AND f.id != " . ($dept['head_faculty_id'] ? (int)$dept['head_faculty_id'] : 0) . "
        ORDER BY f.last_name, f.first_name
    ");
    while ($m = $mem_res->fetch_assoc()) {
        $mid = (int)$m['id'];
        $m['cross_depts'] = [];
        $cd2_res = $conn->query("
            SELECT d.name FROM departments d
            JOIN junction_faculty_department jfd ON d.id = jfd.department_id
            WHERE jfd.faculty_id = $mid AND d.id != $dept_id AND d.status IN ('active','pending')
            ORDER BY d.name
        ");
        if ($cd2_res) while ($cd2 = $cd2_res->fetch_assoc()) $m['cross_depts'][] = $cd2['name'];
        $m['subjects'] = [];
        $fs_res = $conn->query("
            SELECT s.name FROM subjects s
            JOIN junction_faculty_subject jfs ON jfs.subject_id = s.id
            WHERE jfs.faculty_id = $mid ORDER BY s.name
        ");
        if ($fs_res) while ($fs = $fs_res->fetch_assoc()) $m['subjects'][] = $fs['name'];
        $m['subject_areas'] = [];
        $fsa_res = $conn->query("
            SELECT sa.name FROM subject_area sa
            JOIN junction_faculty_subjectarea jfsa ON jfsa.subject_area_id = sa.id
            WHERE jfsa.faculty_id = $mid ORDER BY sa.name
        ");
        if ($fsa_res) while ($fsa = $fsa_res->fetch_assoc()) $m['subject_areas'][] = $fsa['name'];
        $members[] = $m;
    }
    $mem_res->free();

    // Departments where the head is ALSO the head of another dept, OR a member
    $cross_depts = [];
    if ($dept['head_faculty_id']) {
        $hid = (int)$dept['head_faculty_id'];
        $h_res = $conn->query("
            SELECT name FROM departments
            WHERE head_faculty_id = $hid AND id != $dept_id AND status IN ('active','pending')
            ORDER BY name
        ");
        while ($cd = $h_res->fetch_assoc()) $cross_depts[] = ['name' => $cd['name'], 'type' => 'head_of'];
        $h_res->free();
        $m_res = $conn->query("
            SELECT d.name FROM departments d
            JOIN junction_faculty_department jfd ON d.id = jfd.department_id
            WHERE jfd.faculty_id = $hid AND d.id != $dept_id
              AND d.status IN ('active','pending')
              AND (d.head_faculty_id IS NULL OR d.head_faculty_id != $hid)
            ORDER BY d.name
        ");
        while ($cd = $m_res->fetch_assoc()) $cross_depts[] = ['name' => $cd['name'], 'type' => 'member_of'];
        $m_res->free();
    }

    // Head extra data
    $head_approved_at = null;
    $head_subjects = [];
    $head_subject_areas = [];
    if ($dept['head_faculty_id']) {
        $hid = (int)$dept['head_faculty_id'];
        $ha_res = $conn->query("SELECT approved_at FROM faculty WHERE id = $hid");
        if ($ha_res) $head_approved_at = $ha_res->fetch_assoc()['approved_at'] ?? null;
        $hs_res = $conn->query("
            SELECT s.name FROM subjects s
            JOIN junction_faculty_subject jfs ON jfs.subject_id = s.id
            WHERE jfs.faculty_id = $hid ORDER BY s.name
        ");
        if ($hs_res) while ($hs = $hs_res->fetch_assoc()) $head_subjects[] = $hs['name'];
        $hsa_res = $conn->query("
            SELECT sa.name FROM subject_area sa
            JOIN junction_faculty_subjectarea jfsa ON jfsa.subject_area_id = sa.id
            WHERE jfsa.faculty_id = $hid ORDER BY sa.name
        ");
        if ($hsa_res) while ($hsa = $hsa_res->fetch_assoc()) $head_subject_areas[] = $hsa['name'];
    }

    $hierarchy[] = [
        'id'               => $dept_id,
        'name'             => $dept['name'],
        'status'           => $dept['status'],
        'created_at'       => $dept['created_at'],
        'head_id'          => $dept['head_faculty_id'] ? (int)$dept['head_faculty_id'] : null,
        'head_name'        => $dept['head_name'],
        'head_approved_at' => $head_approved_at,
        'head_subjects'    => $head_subjects,
        'head_subject_areas' => $head_subject_areas,
        'cross_depts'      => $cross_depts,
        'subjects'         => $dept_subjects,
        'subject_areas'    => $dept_subject_areas,
        'members'          => $members,
    ];
}
$dept_res->free();

// - Flat lists for Departments & Faculty Members sections -
$departments_list = [];
foreach ($hierarchy as $dept) {
    $departments_list[] = [
        'id'            => $dept['id'],
        'name'          => $dept['name'],
        'head_name'     => $dept['head_name'] ?? 'Unassigned',
        'status'        => $dept['status'],
        'created_at'    => $dept['created_at'],
        'subjects'      => array_column($dept['subjects'] ?? [], 'name'),
        'subject_areas' => array_column($dept['subject_areas'] ?? [], 'name'),
        'member_count'  => count($dept['members']),
    ];
}

// Faculty members with approval date + department info
$faculty_members_list = [];
$fm_res = $conn->query("
    SELECT f.id, f.first_name, f.last_name,
           COALESCE(f.approved_at, f.created_at) AS date_shown
    FROM faculty f
    WHERE f.is_verified = 1
    ORDER BY f.last_name, f.first_name
");
while ($m = $fm_res->fetch_assoc()) {
    $mid = (int)$m['id'];
    $m['departments'] = [];
    $fd_res = $conn->query("
        SELECT d.name FROM departments d
        JOIN junction_faculty_department jfd ON jfd.department_id = d.id
        WHERE jfd.faculty_id = $mid AND d.status IN ('active','pending')
        ORDER BY d.name
    ");
    if ($fd_res) while ($fd = $fd_res->fetch_assoc()) $m['departments'][] = $fd['name'];
    $faculty_members_list[] = $m;
}
$fm_res->free();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard - LumineSense</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="stylesheet" href="../../css/admin/home.css?v=<?= time() ?>">
        <link rel="stylesheet" href="../../css/base/modals.css">

    <link rel="stylesheet" href="../../css/base/tooltip.css">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="main-container admin gap-3">

                <!-- -- LEFT group-container ------------- -->
                <div class="group-container gap-3">

                    <!-- Stat cards -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="stat-row">
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-door-open" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div class="justify-content-center">
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Total Rooms</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-lightbulb-fill" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $lights_on ?></div>
                                    <p class="stat-label">Rooms Currently Running</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $pending ?></div>
                                    <p class="stat-label">Faculty Pending Approval</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $ext_pending ?></div>
                                    <p class="stat-label">Extension Requests</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Faculty Hierarchy -->
                    <div id="hierarchySection" style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Faculty Hierarchy</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-faculty-management.php')">Details</button>
                                <button class="light mx-2" id="hierarchyToggleBtn" onclick="toggleHierarchyMaximize()"><i class="bi bi-arrows-expand"></i></button>
                            </div>
                        </div>
                        <div class="hierarchy-canvas-wrap">
                            <?php if (empty($hierarchy)): ?>
                                <p class="text-muted small text-center py-3">No departments configured yet.</p>
                            <?php else: ?>
                                <div class="hierarchy-canvas" id="hierarchyCanvas">
                                    <?php foreach ($hierarchy as $i => $dept):
                                        $head_initial = $dept['head_name'] ? strtoupper(substr($dept['head_name'], 0, 1)) : '';
                                    ?>
                                        <div class="hierarchy-dept<?= $dept['status'] !== 'active' ? ' hierarchy-dept-pending' : '' ?>" data-dept-id="<?= $dept['id'] ?>" data-head-id="<?= $dept['head_id'] ?? 0 ?>" data-cross='<?= json_encode($dept['cross_depts']) ?>' data-name="<?= htmlspecialchars($dept['name']) ?>" data-status="<?= $dept['status'] ?>">
                                            <div class="hierarchy-dept-node" data-type="dept" data-overlay='<?= json_encode([
                                                'name'         => $dept['name'],
                                                'created_at'   => $dept['created_at'],
                                                'subjects'     => array_column($dept['subjects'] ?? [], 'name'),
                                                'subject_areas' => array_column($dept['subject_areas'] ?? [], 'name'),
                                            ]) ?>'>
                                                <span class="hierarchy-dept-node-row">
                                                    <i class="bi bi-diagram-3"></i>
                                                    <?= htmlspecialchars($dept['name']) ?>
                                                </span>
                                                <?php if ($dept['status'] !== 'active'): ?>
                                                    <span class="hierarchy-status-badge"><?= ucfirst($dept['status']) ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($dept['head_id']): ?>
                                                <div class="hierarchy-connector-v"></div>
                                                <div class="hierarchy-head-node" data-type="head" data-overlay='<?= json_encode([
                                                    'name'         => $dept['head_name'],
                                                    'approved_at'  => $dept['head_approved_at'],
                                                    'cross_depts'  => $dept['cross_depts'],
                                                    'subject_areas' => $dept['head_subject_areas'],
                                                ]) ?>'>
                                                    <div class="hierarchy-head-avatar"><?= $head_initial ?></div>
                                                    <div>
                                                        <div class="hierarchy-head-name"><?= htmlspecialchars($dept['head_name']) ?></div>
                                                        <div class="hierarchy-head-title">Faculty Head</div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($dept['members'])): ?>
                                                <div class="hierarchy-connector-v"></div>
                                                <div class="hierarchy-members-row">
                                                    <?php foreach ($dept['members'] as $member): ?>
                                                        <div class="hierarchy-member-node" data-type="member" data-overlay='<?= json_encode([
                                                            'name'          => $member['first_name'] . ' ' . $member['last_name'],
                                                            'approved_at'   => $member['approved_at'],
                                                            'cross_depts'   => $member['cross_depts'] ?? [],
                                                            'subjects'      => $member['subjects'] ?? [],
                                                            'subject_areas' => $member['subject_areas'] ?? [],
                                                        ]) ?>'>
                                                            <div class="hierarchy-member-avatar"><?= strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)) ?></div>
                                                            <div class="hierarchy-member-name"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($dept['head_id']): ?>
                                                <div class="hierarchy-connector-v"></div>
                                                <div class="hierarchy-empty">No faculty members assigned</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <svg class="hierarchy-lines" id="hierarchyLines">
                                    <defs>
                                        <marker id="arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
                                            <path d="M 0 0 L 10 5 L 0 10 z" fill="var(--secondary-color-4)" />
                                        </marker>
                                    </defs>
                                </svg>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Hierarchy info overlay -->
                    <div class="cal-day-overlay" id="hierarchyOverlay">
                        <div class="cal-day-overlay-header" id="hierarchyOverlayHeader"></div>
                        <div class="cal-day-overlay-body" id="hierarchyOverlayBody"></div>
                    </div>

                    <link rel="stylesheet" href="../../css/pages/admin-homepage.css">



                    <!-- 3-divided row: Rooms | Departments | Faculty Members -->
                    <div class="three-col-row">
                        <div style="background-color:#f8f9fa;" class="section-container">
                            <div class="section-topbar d-flex gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                                <div class="d-flex mx-2 align-items-start">
                                    <h2 class="bold">Rooms</h2>
                                </div>
                                <div class="d-flex mx-2 align-items-end">
                                    <button class="light mx-2" onclick="dissolve('admin-room-manage.php')" data-bs-toggle="tooltip" data-bs-placement="top" title="View all rooms"><i class="bi bi-box-arrow-up-right"></i></button>
                                </div>
                            </div>
                            <div class="room-list px-1 mt-1" id="rooms-list">
                                <?php if (empty($classrooms)): ?>
                                    <p class="text-muted text-center mt-2">No classrooms yet.</p>
                                    <?php else:
                                    foreach ($classrooms as $c):
                                        $on = ($c['light_status'] === 'on'); ?>
                                        <div class="room-item" data-type="room" data-detail='<?= htmlspecialchars(json_encode([
                                            'room_name'   => $c['room_name'],
                                            'room_size'   => $c['room_size'],
                                            'description' => $c['description'] ?? '',
                                            'light_status' => $c['light_status'],
                                        ]), ENT_QUOTES) ?>'>
<i class="bi bi-door-open room-icon"></i>
                                            <div class="room-info">
                                                <div class="d-flex align-items-center gap-2">
                                                    <h5 class="mb-0"><?= htmlspecialchars($c['room_name']) ?></h5>
                                                    <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                                                    background:<?= $on ? '#d1e7dd' : '#f8d7da' ?>;
                                                    color:<?= $on ? '#0f5132' : '#842029' ?>;">
                                                        <?= $on ? 'ON' : 'OFF' ?>
                                                    </span>
                                                </div>
                                                <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                                    Room size: <span><?= ucfirst($c['room_size']) ?></span> room
                                                </p>
                                            </div>
                                        </div>
                                <?php endforeach;
                                endif; ?>
                            </div>
                        </div>
                        <div style="background-color:#f8f9fa;" class="section-container">
                            <div class="section-topbar d-flex gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                                <div class="d-flex mx-2 align-items-start">
                                    <h2 class="bold">Departments</h2>
                                </div>
                                <div class="d-flex mx-2 align-items-end">
                                    <button class="light mx-2" onclick="dissolve('admin-faculty-management.php?tab=departments')" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage departments"><i class="bi bi-box-arrow-up-right"></i></button>
                                </div>
                            </div>
                            <div class="room-list px-1 mt-1" id="depts-list">
                                <?php if (empty($departments_list)): ?>
                                    <p class="text-muted text-center mt-2">No departments.</p>
                                <?php else: ?>
                                    <?php foreach ($departments_list as $dept): ?>
                                        <div class="room-item" data-type="dept-list" data-detail='<?= htmlspecialchars(json_encode([
                                            'name'          => $dept['name'],
                                            'head_name'     => $dept['head_name'],
                                            'status'        => $dept['status'],
                                            'created_at'    => $dept['created_at'],
                                            'subjects'      => $dept['subjects'],
                                            'subject_areas' => $dept['subject_areas'],
                                            'member_count'  => $dept['member_count'],
                                        ]), ENT_QUOTES) ?>'>
                                            <i class="bi bi-diagram-3 room-icon"></i>
                                            <div class="room-info">
                                                <h5 class="mb-0"><?= htmlspecialchars($dept['name']) ?></h5>
                                                <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                                    Head: <span><?= htmlspecialchars($dept['head_name']) ?></span>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="background-color:#f8f9fa;" class="section-container">
                            <div class="section-topbar d-flex gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                                <div class="d-flex mx-2 align-items-start">
                                    <h2 class="bold">Faculty</h2>
                                </div>
                                <div class="d-flex mx-2 align-items-end">
                                    <button class="light mx-2" onclick="dissolve('admin-faculty-management.php?tab=faculty-directory')" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage faculty members"><i class="bi bi-box-arrow-up-right"></i></button>
                                </div>
                            </div>
                            <div class="room-list px-1 mt-1" id="faculty-list">
                                <?php if (empty($faculty_members_list)): ?>
                                    <p class="text-muted text-center mt-2">No faculty members.</p>
                                <?php else: ?>
                                    <?php foreach ($faculty_members_list as $member): ?>
                                        <div class="room-item" data-type="faculty" data-detail='<?= htmlspecialchars(json_encode([
                                            'name'        => $member['first_name'] . ' ' . $member['last_name'],
                                            'date_shown'  => $member['date_shown'],
                                            'departments' => $member['departments'],
                                        ]), ENT_QUOTES) ?>'>
                                            <i class="bi bi-person-badge room-icon"></i>
                                            <div class="room-info">
                                                <h5 class="mb-0"><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></h5>
                                                <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                                    Approved: <span><?= date('M j, Y', strtotime($member['date_shown'])) ?></span>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div><!-- /LEFT group-container -->

                <!-- -- (CENTER COLUMN removed - content moved to LEFT group) - -->

                <!-- -- RIGHT group-container ------------ -->
                <div class="group-container gap-3">
                    <!-- Calendar -->
                    <div style="background-color:#f8f9fa;" class="section-container">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Calendar</h2>
                            </div>
                        </div>

                        <div class="mini-calendar">
                            <div class="cal-nav">
                                <button class="cal-nav-btn" id="cal-prev">&#8249;</button>
                                <span class="cal-month-label" id="cal-month-label"></span>
                                <button class="cal-nav-btn" id="cal-next">&#8250;</button>
                            </div>
                            <div class="cal-grid">
                                <div class="cal-dow">Sun</div>
                                <div class="cal-dow">Mon</div>
                                <div class="cal-dow">Tue</div>
                                <div class="cal-dow">Wed</div>
                                <div class="cal-dow">Thu</div>
                                <div class="cal-dow">Fri</div>
                                <div class="cal-dow">Sat</div>
                            </div>
                            <div class="cal-days" id="cal-days"></div>

                            <!-- Schedule day overlay (shows on top of clicked day) -->
                            <div class="cal-day-overlay" id="calDayOverlay">
                                <div class="cal-day-overlay-header" id="calDayOverlayHeader"></div>
                                <div class="cal-day-overlay-body" id="calDayOverlayBody"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div style="background-color:#f8f9fa;" class="section-container recents">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between" style="background-color:var(--accent-yellow);">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold">Recent Activity</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="light mx-2" onclick="dissolve('admin-reports.php?tab=activity')">Details</button>
                            </div>
                        </div>
                        <div style="overflow:visible; flex:1;">
                            <div class="activity-list admin px-2 max-width" id="activityTimeline">
                                <?php if (empty($logs)): ?>
                                    <p class="text-muted">No recent activity.</p>
                                <?php else: ?>
                                    <?php foreach ($logs as $log):
                                        $iconData = activity_icon($log);
                                    ?>
                                        <div class="timeline-item">
                                            <div class="tl-icon" style="background:<?= $iconData['bg'] ?>; color:<?= $iconData['color'] ?>;">
                                                <i class="bi <?= $iconData['icon'] ?>"></i>
                                            </div>
                                            <div class="tl-body">
                                                <p class="tl-action">
                                                    <?= htmlspecialchars($iconData['label']) ?>
                                                    <?php if (!empty($log['room_name'])): ?>
                                                        &mdash; <span style="color:var(--secondary-color-3);"><?= htmlspecialchars($log['room_name']) ?></span>
                                                    <?php endif; ?>
                                                </p>
                                                <div class="tl-meta" style="flex-wrap: wrap; row-gap: 2px;">
                                                    <span><i class="bi bi-clock"></i> <?= date('g:i A', strtotime($log['event_time'])) ?>, <?= date('M j', strtotime($log['event_time'])) ?></span>
                                                    <?php if (!empty($log['admin_name'])): ?>
                                                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($log['admin_name']) ?></span>
                                                    <?php elseif (!empty($log['triggered_by'])): ?>
                                                        <span><i class="bi bi-person"></i> <?= htmlspecialchars($log['triggered_by']) ?></span>
                                                    <?php endif; ?>
                                                    <span class="tl-type-badge" style="background:<?= $iconData['typeBg'] ?>; color:<?= $iconData['typeClr'] ?>;"><?= $iconData['typeLabel'] ?></span>
                                                </div>
                                                <?php if (!empty($iconData['notes'])): ?>
                                                    <span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i><?= htmlspecialchars($iconData['notes']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div><!-- /RIGHT group-container -->

            </div>
        </div>
    </div>

    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>
    <script src="../../js/lib/tooltip.js"></script>
    <script src="../../js/admin/admin-homepage.js"></script>

    <!-- Detail Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title" id="detailModalLabel"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4"></div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>