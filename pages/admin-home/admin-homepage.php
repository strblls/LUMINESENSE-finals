<?php
$page_title = 'Dashboard';
require_once '../../php/includes/admin-head.php';

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

// ── Faculty hierarchy data ──────────────────────────────────────────────
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

// ── Flat lists for Departments & Faculty Members sections ──
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
    <title>Admin Dashboard – LumineSense</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" type="image/png" sizes="32x32" href="../../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../../css/admin-common.css">
    <link rel="stylesheet" href="../../css/admin-home.css?v=<?= time() ?>">
        <link rel="stylesheet" href="../../css/modals.css">

    <link rel="stylesheet" href="../../css/tooltip.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>
    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <div class="parent-container">
        <div class="child-container">
            <div class="main-container admin gap-3">

                <!-- ─── LEFT group-container ───────────────────────── -->
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

                    <style>
                        .hierarchy-canvas-wrap {
                            position: relative;
                            overflow: auto;
                            flex: 1;
                            padding: 0 12px 12px;
                            min-height: 320px;
                            cursor: grab;
                            user-select: none;
                        }

                        .hierarchy-canvas-wrap:active {
                            cursor: grabbing;
                        }

                        .hierarchy-canvas {
                            display: flex;
                            flex-flow: column wrap;
                            gap: 24px;
                            padding: 16px;
                            position: relative;
                            height: 100%;
                            align-content: flex-start;
                            min-width: 100%;
                        }

                        .hierarchy-lines {
                            position: absolute;
                            top: 0;
                            left: 0;
                            width: 100%;
                            height: 100%;
                            pointer-events: none;
                            z-index: 1;
                        }

                        .hierarchy-dept {
                            flex-shrink: 0;
                            width: 200px;
                            cursor: grab;
                            position: relative;
                            z-index: 2;
                            background: #fff;
                            border-radius: 12px;
                            padding: 14px;
                            box-shadow: 0 2px 8px rgba(47, 0, 79, .1);
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            gap: 0;
                        }

                        .hierarchy-dept-pending {
                            opacity: .75;
                            border: 1px dashed var(--secondary-color-3);
                        }

                        .hierarchy-status-badge {
                            font-size: 9px;
                            font-weight: 600;
                            background: var(--secondary-color-3);
                            color: #fff;
                            border-radius: 4px;
                            padding: 1px 6px;
                            text-transform: uppercase;
                            margin-left: 4px;
                        }

                        .hierarchy-dept:active {
                            cursor: grabbing;
                        }

                        .hierarchy-dept-node {
                            background: var(--secondary-color-2);
                            color: #fff;
                            font-weight: 700;
                            font-size: 13px;
                            padding: 8px 18px;
                            border-radius: 8px;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            gap: 4px;
                            white-space: normal;
                            width: 100%;
                            word-break: break-word;
                            hyphens: auto;
                            text-align: center;
                        }

                        .hierarchy-dept-node-row {
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                        }

                        .hierarchy-connector-v {
                            width: 2px;
                            height: 12px;
                            background: var(--secondary-color-3);
                            flex-shrink: 0;
                        }

                        .hierarchy-head-node {
                            display: flex;
                            align-items: center;
                            gap: 8px;
                            background: #f9edfa;
                            border: 1.5px solid var(--secondary-color-2);
                            border-radius: 8px;
                            padding: 8px 12px;
                            width: 100%;
                        }

                        .hierarchy-head-avatar {
                            width: 32px;
                            height: 32px;
                            border-radius: 50%;
                            background: var(--secondary-color-3);
                            color: #fff;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-weight: 700;
                            font-size: 12px;
                            flex-shrink: 0;
                        }

                        .hierarchy-head-name {
                            font-size: 12px;
                            font-weight: 700;
                            color: var(--secondary-color-1);
                            line-height: 1.2;
                        }

                        .hierarchy-head-title {
                            font-size: 9px;
                            font-weight: 600;
                            color: var(--secondary-color-3);
                            text-transform: uppercase;
                            letter-spacing: .04em;
                        }

                        .hierarchy-members-row {
                            display: flex;
                            flex-direction: column;
                            gap: 6px;
                            width: 100%;
                        }

                        .hierarchy-member-node {
                            display: flex;
                            align-items: center;
                            gap: 6px;
                            padding: 6px 10px;
                            background: #fff;
                            border: 1px solid #e0d6f0;
                            border-radius: 6px;
                        }

                        .hierarchy-member-avatar {
                            width: 26px;
                            height: 26px;
                            border-radius: 50%;
                            background: #e9d5ff;
                            color: var(--secondary-color-1);
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-weight: 700;
                            font-size: 10px;
                            flex-shrink: 0;
                        }

                        .hierarchy-member-name {
                            font-size: 11px;
                            font-weight: 600;
                            color: var(--secondary-color-1);
                            line-height: 1.2;
                        }

                        .hierarchy-empty {
                            font-size: 11px;
                            color: var(--muted);
                            padding: 4px 0;
                        }

                        .hierarchy-line-label {
                            font-size: 9px;
                            fill: var(--secondary-color-3);
                            font-weight: 600;
                        }

                        #hierarchySection {
                            transition: transform .25s ease, opacity .25s ease;
                            transform-origin: top center;
                        }

                        .hierarchy-maximized {
                            position: fixed !important;
                            inset: 0 !important;
                            z-index: 1050 !important;
                            border-radius: 0 !important;
                            display: flex;
                            flex-direction: column;
                            background-color: #f8f9fa !important;
                            animation: hierarchyFadeIn .25s ease forwards;
                        }

                        .hierarchy-maximized .hierarchy-canvas-wrap {
                            flex: 1;
                            min-height: 0 !important;
                        }

                        @keyframes hierarchyFadeIn {
                            from {
                                transform: scale(0.96);
                                opacity: 0.92;
                            }

                            to {
                                transform: scale(1);
                                opacity: 1;
                            }
                        }
                    </style>

                    <script>
                        (function() {
                            const canvas = document.getElementById('hierarchyCanvas');
                            const wrap = canvas?.closest('.hierarchy-canvas-wrap');
                            const linesSvg = document.getElementById('hierarchyLines');
                            if (!canvas || !wrap || !linesSvg) return;

                            // ── Restore saved positions ──
                            canvas.querySelectorAll('.hierarchy-dept').forEach(dept => {
                                const saved = localStorage.getItem('hierarchy_pos_' + dept.dataset.deptId);
                                if (saved) {
                                    const pos = JSON.parse(saved);
                                    dept.style.position = 'absolute';
                                    dept.style.left = pos.left + 'px';
                                    dept.style.top = pos.top + 'px';
                                    dept.style.margin = '0';
                                }
                            });

                            // ── Drag-to-pan ──
                            let isPanning = false,
                                startX, startY, scrollLeft, scrollTop;
                            wrap.addEventListener('mousedown', e => {
                                if (e.target.closest('.hierarchy-dept')) return;
                                isPanning = true;
                                startX = e.pageX - wrap.offsetLeft;
                                startY = e.pageY - wrap.offsetTop;
                                scrollLeft = wrap.scrollLeft;
                                scrollTop = wrap.scrollTop;
                            });
                            wrap.addEventListener('mousemove', e => {
                                if (!isPanning) return;
                                e.preventDefault();
                                const x = e.pageX - wrap.offsetLeft;
                                const y = e.pageY - wrap.offsetTop;
                                wrap.scrollLeft = scrollLeft - (x - startX);
                                wrap.scrollTop = scrollTop - (y - startY);
                            });
                            ['mouseup', 'mouseleave'].forEach(ev => wrap.addEventListener(ev, () => {
                                isPanning = false;
                            }));

                            // ── Drag individual department ──
                            let dragDept = null,
                                offX, offY;
                            canvas.querySelectorAll('.hierarchy-dept').forEach(dept => {
                                dept.addEventListener('mousedown', e => {
                                    dragDept = dept;
                                    const rect = dept.getBoundingClientRect();
                                    offX = e.clientX - rect.left;
                                    offY = e.clientY - rect.top;
                                    dept.style.position = 'absolute';
                                    dept.style.left = (dept.offsetLeft) + 'px';
                                    dept.style.top = (dept.offsetTop) + 'px';
                                    dept.style.margin = '0';
                                    dept.style.transition = 'none';
                                    dept.style.zIndex = '10';
                                });
                            });

                            document.addEventListener('mousemove', e => {
                                if (!dragDept) return;
                                const wrapRect = wrap.getBoundingClientRect();
                                const left = e.clientX - wrapRect.left - offX + wrap.scrollLeft;
                                const top = e.clientY - wrapRect.top - offY + wrap.scrollTop;
                                dragDept.style.left = Math.max(0, left) + 'px';
                                dragDept.style.top = Math.max(0, top) + 'px';
                                drawLines();
                            });

                            document.addEventListener('mouseup', () => {
                                if (dragDept) {
                                    const id = dragDept.dataset.deptId;
                                    const left = parseInt(dragDept.style.left) || 0;
                                    const top = parseInt(dragDept.style.top) || 0;
                                    localStorage.setItem('hierarchy_pos_' + id, JSON.stringify({
                                        left,
                                        top
                                    }));
                                }
                                dragDept = null;
                            });

                            // ── Draw SVG lines between cross-department connections ──
                            function drawLines() {
                                const depts = canvas.querySelectorAll('.hierarchy-dept');
                                const deptMap = {};
                                depts.forEach(d => {
                                    deptMap[d.dataset.deptId] = d;
                                });

                                let svgContent = '';
                                const crossData = [];

                                depts.forEach(d => {
                                    const cross = d.dataset.cross;
                                    if (!cross || cross === '[]') return;
                                    const entries = JSON.parse(cross);
                                    entries.forEach(entry => {
                                        const name = typeof entry === 'string' ? entry : entry.name;
                                        const type = typeof entry === 'string' ? 'member_of' : (entry.type || 'member_of');
                                        for (const el of Object.values(deptMap)) {
                                            if (el.dataset.name === name) {
                                                crossData.push({
                                                    from: d,
                                                    to: el,
                                                    type
                                                });
                                                break;
                                            }
                                        }
                                    });
                                });

                                const wrapRect = wrap.getBoundingClientRect();

                                // Group by unordered dept pair for bidirectional detection
                                const pairMap = {};
                                crossData.forEach(item => {
                                    const key = [item.from.dataset.deptId, item.to.dataset.deptId].sort().join('-');
                                    if (!pairMap[key]) pairMap[key] = [];
                                    pairMap[key].push(item);
                                });

                                Object.values(pairMap).forEach(pairs => {
                                    const isBi = pairs.length === 2;
                                    // For bidirectional pairs, draw one line with a consolidated label
                                    const items = isBi ? [pairs[0]] : pairs;
                                    items.forEach(({
                                        from,
                                        to,
                                        type
                                    }, idx) => {
                                        const fromRect = from.getBoundingClientRect();
                                        const toRect = to.getBoundingClientRect();

                                        const x1 = fromRect.left - wrapRect.left + fromRect.width / 2 + wrap.scrollLeft;
                                        const y1 = fromRect.top - wrapRect.top + fromRect.height / 2 + wrap.scrollTop;
                                        const x2 = toRect.left - wrapRect.left + toRect.width / 2 + wrap.scrollLeft;
                                        const y2 = toRect.top - wrapRect.top + toRect.height / 2 + wrap.scrollTop;

                                        const dx = x2 - x1;
                                        const dy = y2 - y1;

                                        let cx1 = x1 + dx * 0.4;
                                        let cy1 = y1;
                                        let cx2 = x2 - dx * 0.4;
                                        let cy2 = y2;

                                        // Solid line with arrow
                                        const pathId = 'p' + from.dataset.deptId + '_' + to.dataset.deptId + '_' + idx;
                                        svgContent += `<path id="${pathId}" d="M${x1},${y1} C${cx1},${cy1} ${cx2},${cy2} ${x2},${y2}" fill="none" stroke="var(--secondary-color-4)" stroke-width="2" opacity="0.7" marker-end="url(#arrow)"/>`;

                                        // Relationship label at bezier midpoint (t=0.5)
                                        const mx = 0.125 * x1 + 0.375 * cx1 + 0.375 * cx2 + 0.125 * x2;
                                        const my = 0.125 * y1 + 0.375 * cy1 + 0.375 * cy2 + 0.125 * y2;
                                        let label;
                                        if (isBi) {
                                            const t1 = pairs[0].type,
                                                t2 = pairs[1].type;
                                            if (t1 === t2) {
                                                label = t1 === 'head_of' ? 'mutual Faculty Heads' : 'mutual Faculty Members';
                                            } else {
                                                label = 'Faculty Head / Member';
                                            }
                                        } else {
                                            label = type === 'head_of' ? '\u2192 is also Faculty Head' : '\u2192 is a Faculty Member';
                                        }

                                        const lw = Math.max(140, label.length * 7 + 20);
                                        const lh = 20;
                                        svgContent += `<rect x="${mx - lw/2}" y="${my - lh/2}" width="${lw}" height="${lh}" rx="4" fill="#f9edfa" opacity="0.92" pointer-events="none"/>`;
                                        svgContent += `<text x="${mx}" y="${my + 4}" text-anchor="middle" font-size="10" font-weight="600" fill="var(--secondary-color-1)" pointer-events="none">${label}</text>`;
                                    });
                                });

                                linesSvg.innerHTML = svgContent;
                                linesSvg.style.width = canvas.scrollWidth + 'px';
                                linesSvg.style.height = canvas.scrollHeight + 'px';
                            }

                            // Initial draw after layout settles
                            setTimeout(drawLines, 100);
                            window.addEventListener('resize', drawLines);
                            wrap.addEventListener('scroll', drawLines);
                            // Redraw after any drag
                            const origMouseUp = document.addEventListener('mouseup', function redraw() {
                                setTimeout(drawLines, 50);
                            });
                        })();

                        function toggleHierarchyMaximize() {
                            const section = document.getElementById('hierarchySection');
                            const btn = document.getElementById('hierarchyToggleBtn');
                            if (!section || !btn) return;
                            const isMax = section.classList.toggle('hierarchy-maximized');
                            btn.innerHTML = isMax ? '<i class="bi bi-arrows-collapse"></i>' : '<i class="bi bi-arrows-expand"></i>';
                            setTimeout(() => window.dispatchEvent(new Event('resize')), 100);
                        }
                    </script>

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

                <!-- ─── (CENTER COLUMN removed — content moved to LEFT group) ── -->

                <!-- ─── RIGHT group-container ──────────────────────── -->
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

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/tooltip.js"></script>
    <script>
        // Mini Calendar
        const SCHEDULES = <?= $schedules_json ?>;
        const DAYS_ENUM = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        let calDate = new Date();

        function renderCalendar() {
            const year = calDate.getFullYear();
            const month = calDate.getMonth();
            const today = new Date();

            document.getElementById('cal-month-label').textContent = `${MONTHS[month]} ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();

            const container = document.getElementById('cal-days');
            container.innerHTML = '';

            for (let i = 0; i < firstDay; i++) {
                const blank = document.createElement('div');
                blank.className = 'cal-day empty';
                container.appendChild(blank);
            }

            for (let d = 1; d <= daysInMonth; d++) {
                const cell = document.createElement('div');
                cell.className = 'cal-day';

                const dateObj = new Date(year, month, d);
                const dayName = DAYS_ENUM[dateObj.getDay()];
                const hasSchedule = SCHEDULES[dayName] && SCHEDULES[dayName].length > 0;

                if (hasSchedule) cell.classList.add('has-schedule');
                if (d === today.getDate() && month === today.getMonth() && year === today.getFullYear()) {
                    cell.classList.add('today');
                }

                cell.textContent = d;
                cell.addEventListener('click', () => showSchedule(d, dayName, cell));
                container.appendChild(cell);
            }
        }

        function showSchedule(day, dayName, cell) {
            const overlay = document.getElementById('calDayOverlay');
            const header = document.getElementById('calDayOverlayHeader');
            const body = document.getElementById('calDayOverlayBody');

            const schedules = SCHEDULES[dayName] || [];
            header.textContent = `${dayName} — ${MONTHS[calDate.getMonth()]} ${day}`;

            if (schedules.length === 0) {
                body.innerHTML = '<p class="cal-no-sched">No schedules for this day.</p>';
            } else {
                body.innerHTML = schedules.map(s => {
                    let extBadge = '';
                    if (s.extended_until) {
                        const extStatus = s.ext_status;
                        let badgeCls = 'ext-badge';
                        let badgeIcon = '';
                        if (extStatus === 'pending') {
                            badgeCls = 'badge-ext-pending';
                            badgeIcon = ' <i class="bi bi-hourglass-bottom"></i>';
                        } else if (extStatus === 'approved') {
                            badgeCls = 'badge-ext-approved';
                            badgeIcon = ' <i class="bi bi-check-circle"></i>';
                        } else if (extStatus === 'rejected') {
                            badgeCls = 'badge-ext-rejected';
                            badgeIcon = ' <i class="bi bi-x-circle"></i>';
                        }
                        extBadge = ` <span class="${badgeCls}" style="font-size:11px;padding:2px 8px;display:inline-flex;align-items:center;gap:2px;">${badgeIcon} extended</span>`;
                    }
                    return `
                    <div class="cal-sched-item">
                        <div class="cal-sched-room"><i class="bi bi-door-open"></i> <span>${s.room_name}</span></div>
                        <div class="cal-sched-time">
                            <i class="bi bi-clock"></i> Schedule: <span>${s.start_time.slice(0,5)} – ${s.extended_until
                                ? s.extended_until.slice(0,5) + extBadge
                                : s.end_time.slice(0,5)}</span>
                        </div>
                        <div class="cal-sched-faculty"><i class="bi bi-people"></i> Faculty: <span>${s.first_name ? s.first_name + ' ' + s.last_name : 'No faculty assigned'}</span></div>
                    </div>
                `}).join('');
            }

            const isOpen = overlay.classList.contains('open') && overlay.dataset.day === String(day);
            document.querySelectorAll('.cal-day').forEach(c => c.classList.remove('selected'));

            if (isOpen) {
                overlay.classList.remove('open');
                overlay.dataset.day = '';
                return;
            }

            // Position overlay using viewport coordinates so it pops out of the container
            const cellRect = cell.getBoundingClientRect();
            const overlayW = 220;
            let top = cellRect.top - 10;
            let left = cellRect.left + cellRect.width / 2;

            // Clamp so overlay doesn't overflow viewport edges
            if (left + overlayW > window.innerWidth - 8) {
                left = window.innerWidth - overlayW - 8;
            }
            if (left < 8) left = 8;
            if (top < 8) top = 8;

            overlay.style.top = top + 'px';
            overlay.style.left = left + 'px';
            overlay.style.transformOrigin = 'top left';
            overlay.dataset.day = day;

            // Force reflow then add open class for animation
            void overlay.offsetWidth;
            overlay.classList.add('open');
            cell.classList.add('selected');

            // Dismiss on mouse leave
            overlay.onmouseleave = () => {
                overlay.classList.remove('open');
                overlay.dataset.day = '';
                cell.classList.remove('selected');
                overlay.onmouseleave = null;
            };
        }

        document.getElementById('cal-prev').addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() - 1);
            renderCalendar();
            document.getElementById('calDayOverlay').classList.remove('open');
        });
        document.getElementById('cal-next').addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() + 1);
            renderCalendar();
            document.getElementById('calDayOverlay').classList.remove('open');
        });

        renderCalendar();

        // ── Hierarchy overlay ────────────────────────────────────────────────
        function showHierarchyOverlay(element) {
            const overlay = document.getElementById('hierarchyOverlay');
            const header = document.getElementById('hierarchyOverlayHeader');
            const body = document.getElementById('hierarchyOverlayBody');

            if (overlay.classList.contains('open')) {
                overlay.classList.remove('open');
                return;
            }

            const data = JSON.parse(element.dataset.overlay);
            const type = element.dataset.type;

            if (type === 'dept') {
                header.textContent = data.name;
                let html = '';
                if (data.created_at) html += `<div class="h-info"><span class="h-info-label">Created:</span> ${data.created_at}</div>`;
                if (data.subjects && data.subjects.length) html += `<div class="h-info"><span class="h-info-label">Subjects:</span> ${data.subjects.join(', ')}</div>`;
                if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Subject Areas:</span> ${data.subject_areas.join(', ')}</div>`;
                body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
            } else if (type === 'head') {
                header.textContent = data.name;
                let html = '';
                if (data.approved_at) html += `<div class="h-info"><span class="h-info-label">Approved:</span> ${data.approved_at}</div>`;
                if (data.cross_depts && data.cross_depts.length) {
                    const deptNames = data.cross_depts.map(d => d.name || d).join(', ');
                    html += `<div class="h-info"><span class="h-info-label">Also in:</span> ${deptNames}</div>`;
                }
                if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Coverage (Subject Areas):</span> ${data.subject_areas.join(', ')}</div>`;
                body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
            } else if (type === 'member') {
                header.textContent = data.name;
                let html = '';
                if (data.approved_at) html += `<div class="h-info"><span class="h-info-label">Approved:</span> ${data.approved_at}</div>`;
                if (data.cross_depts && data.cross_depts.length) html += `<div class="h-info"><span class="h-info-label">Also in:</span> ${data.cross_depts.join(', ')}</div>`;
                if (data.subjects && data.subjects.length) html += `<div class="h-info"><span class="h-info-label">Subjects:</span> ${data.subjects.join(', ')}</div>`;
                if (data.subject_areas && data.subject_areas.length) html += `<div class="h-info"><span class="h-info-label">Subject Areas:</span> ${data.subject_areas.join(', ')}</div>`;
                body.innerHTML = html || '<p class="cal-no-sched">No details available.</p>';
            }

            const rect = element.getBoundingClientRect();
            const overlayW = 220;
            let top = rect.top - 10;
            let left = rect.left + rect.width / 2;
            if (left + overlayW > window.innerWidth - 8) left = window.innerWidth - overlayW - 8;
            if (left < 8) left = 8;
            if (top < 8) top = 8;

            overlay.style.top = top + 'px';
            overlay.style.left = left + 'px';
            overlay.style.transformOrigin = 'top left';
            void overlay.offsetWidth;
            overlay.classList.add('open');

            overlay.onmouseleave = () => {
                overlay.classList.remove('open');
                overlay.onmouseleave = null;
            };
        }

        document.querySelectorAll('.hierarchy-dept-node, .hierarchy-head-node, .hierarchy-member-node').forEach(el => {
            el.addEventListener('click', e => {
                e.stopPropagation();
                closeAllOverlaysExcept('hierarchyOverlay');
                showHierarchyOverlay(el);
            });
        });

        function closeAllOverlaysExcept(keepId) {
            document.querySelectorAll('.cal-day-overlay.open').forEach(o => {
                if (o.id !== keepId) o.classList.remove('open');
            });
        }

        // ── JS mirror of PHP activity_icon() for live updates ────────────────────
        function getActivityIcon(log) {
            const evt = log.event_type || log.action || '';
            const type = log.log_type || 'room';

            const iconMap = {
                'on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
                'off': ['bi-lightbulb', '#842029', '#f8d7da'],
                'light_on': ['bi-lightbulb-fill', '#198754', '#d1e7dd'],
                'light_off': ['bi-lightbulb', '#842029', '#f8d7da'],
                'motion_detect': ['bi-person-bounding-box', '#084298', '#cfe2ff'],
                'pir_motion': ['bi-person-bounding-box', '#084298', '#cfe2ff'],
                'pir_stopped': ['bi-person-bounding-box', '#5a5a5a', '#e9ecef'],
                'gesture': ['bi-hand-index', '#084298', '#cfe2ff'],
                'schedule': ['bi-calendar-check', '#198754', '#d1e7dd'],
                'security_alert': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'class_start': ['bi-play-circle-fill', '#198754', '#d1e7dd'],
                'class_end': ['bi-stop-circle', '#664d03', '#fff3cd'],
                'door_open': ['bi-door-open-fill', '#664d03', '#fff3cd'],
                'door_close': ['bi-door-closed-fill', '#5a3a00', '#ffe5b4'],
                'faculty_approved': ['bi-person-check-fill', '#198754', '#d1e7dd'],
                'faculty_rejected': ['bi-person-x-fill', '#842029', '#f8d7da'],
                'faculty_pending': ['bi-person-plus', '#664d03', '#fff3cd'],
                'extension_approved': ['bi-clock-history', '#084298', '#cfe2ff'],
                'extension_rejected': ['bi-clock-fill', '#842029', '#f8d7da'],
                'admin_login': ['bi-box-arrow-in-right', '#055160', '#cff4fc'],
                'issue_raised': ['bi-exclamation-triangle-fill', '#842029', '#f8d7da'],
                'issue_resolved': ['bi-check-circle-fill', '#198754', '#d1e7dd'],
                'admin_action': ['bi-shield-check', '#084298', '#cfe2ff'],
            };
            const def = ['bi-clock-history', '#5a5a5a', '#e9ecef'];
            const [icon, color, bg] = iconMap[evt] || def;

            const typeMap = {
                'room': ['#f9edfa', '#2f004f', 'Room'],
                'admin': ['#2f004f', '#f9edfa', 'Admin'],
                'admin_login': ['#cff4fc', '#055160', 'Login'],
            };
            const [typeBg, typeClr, typeLabel] = typeMap[type] || typeMap['room'];

            const label = evt.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());

            return {
                icon,
                color,
                bg,
                label,
                typeBg,
                typeClr,
                typeLabel,
                notes: log.notes || ''
            };
        }

        function formatTime(timeStr) {
            const d = new Date(timeStr.replace(' ', 'T') + '+08:00');
            const hours = d.getHours(),
                mins = d.getMinutes();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            const h12 = hours % 12 || 12;
            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return h12 + ':' + String(mins).padStart(2, '0') + ' ' + ampm + ', ' + months[d.getMonth()] + ' ' + d.getDate();
        }

        // ── Admin Dashboard Auto-refresh (every 5s) ───────────────────────────────
        async function pollAdminDashboard() {
            try {
                const res = await fetch('../../api/admin-status.php');
                if (!res.ok) return;
                const data = await res.json();
                if (!data.success) return;

                // ── Update stat cards ──────────────────────────────────────────
                const lightsEl = document.querySelector('.stat-card:nth-child(2) .stat-value');
                const pendingEl = document.querySelector('.stat-card:nth-child(3) .stat-value');
                const extEl = document.querySelector('.stat-card:nth-child(4) .stat-value');
                if (lightsEl) lightsEl.textContent = data.lights_on;
                if (pendingEl) pendingEl.textContent = data.pending;
                if (extEl) extEl.textContent = data.ext_pending;

                // ── Update rooms list ──────────────────────────────────────────
                const roomList = document.getElementById('rooms-list');
                if (roomList && data.classrooms) {
                    roomList.innerHTML = data.classrooms.map(c => {
                        const on = c.light_status === 'on';
                        const detail = JSON.stringify({
                            room_name: c.room_name,
                            room_size: c.room_size || 'N/A',
                            description: c.description || '',
                            light_status: c.light_status
                        }).replace(/'/g, '&#39;');
                        return `
                    <div class="room-item" data-type="room" data-detail='${detail}'>
                        <i class="bi bi-door-open room-icon"></i>
                        <div class="room-info">
                            <div class="d-flex align-items-center gap-2">
                                <h5 class="mb-0" style="font-size: 14.5px;">${c.room_name}</h5>
                                <span style="font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
                                    background:${on ? '#d1e7dd' : '#f8d7da'};
                                    color:${on ? '#0f5132' : '#842029'};">
                                    ${on ? 'ON' : 'OFF'}
                                </span>
                            </div>
                            <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                Room size: <span>${c.room_size.charAt(0).toUpperCase() + c.room_size.slice(1)}</span> room
                            </p>
                        </div>
                    </div>
                `;
                    }).join('');
                }

                // ── Update departments list ─────────────────────────────────────
                const deptList = document.getElementById('depts-list');
                if (deptList && data.departments) {
                    deptList.innerHTML = data.departments.map(d => {
                        const detail = JSON.stringify({
                            name: d.name,
                            head_name: d.head_name || 'Unassigned',
                            status: d.status || 'active',
                            subject_areas: d.subject_areas || [],
                            subjects: d.subjects || [],
                            member_count: d.member_count || 0
                        }).replace(/'/g, '&#39;');
                        return `
                    <div class="room-item" data-type="dept-list" data-detail='${detail}'>
                        <i class="bi bi-diagram-3 room-icon"></i>
                        <div class="room-info">
                            <h5 class="mb-0" style="font-size: 14.5px;">${d.name}</h5>
                            <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                Head: <span>${d.head_name || 'Unassigned'}</span>
                            </p>
                        </div>
                    </div>
                `;
                    }).join('');
                }

                // ── Update faculty members list ─────────────────────────────────
                const facultyList = document.getElementById('faculty-list');
                if (facultyList && data.faculty_members) {
                    facultyList.innerHTML = data.faculty_members.map(f => {
                        const d = new Date(f.date_shown + 'T00:00:00');
                        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                        const dateStr = months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
                        const detail = JSON.stringify({
                            name: f.first_name + ' ' + f.last_name,
                            date_shown: f.date_shown || ''
                        }).replace(/'/g, '&#39;');
                        return `
                    <div class="room-item" data-type="faculty" data-detail='${detail}'>
                        <i class="bi bi-person-badge room-icon"></i>
                        <div class="room-info">
                            <h5 class="mb-0" style="font-size: 14.5px;">${f.first_name} ${f.last_name}</h5>
                            <p class="room-size mb-0" style="font-size:13.5px; color:var(--muted-dark);">
                                Approved: <span>${dateStr}</span>
                            </p>
                        </div>
                    </div>
                `;
                    }).join('');
                }

                // ── Update recent activity ─────────────────────────────────────
                const activityList = document.getElementById('activityTimeline');
                if (activityList && data.logs) {
                    activityList.innerHTML = data.logs.map(log => {
                        const iconData = getActivityIcon(log);
                        return `
                        <div class="timeline-item">
                            <div class="tl-icon" style="background:${iconData.bg}; color:${iconData.color};">
                                <i class="bi ${iconData.icon}"></i>
                            </div>
                            <div class="tl-body">
                                <p class="tl-action" style="font-size:14px; font-weight: 600;">
                                    ${iconData.label}
                                    ${log.room_name ? '&mdash; <span style="color:var(--secondary-color-3);">' + log.room_name + '</span>' : ''}
                                    <span class="tl-type-badge" style="background:${iconData.typeBg}; color:${iconData.typeClr};">${iconData.typeLabel}</span>
                                </p>
                                <div class="tl-meta" style="display: flex; flex-wrap: wrap; row-gap: 4px; width: 100%;">
                                    <span style="width: 100%;"><i class="bi bi-clock"></i> ${formatTime(log.event_time)}</span>
                                    ${log.admin_name ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.admin_name + '</span>' : ''}
                                    ${(!log.admin_name && log.triggered_by) ? '<span style="width: 100%; margin-top: 2px;"><i class="bi bi-person"></i> ' + log.triggered_by + '</span>' : ''}
                                    
                                </div>
                                ${iconData.notes ? '<span class="tl-notes"><i class="bi bi-chat-left-text me-1"></i>' + iconData.notes + '</span>' : ''}
                            </div>
                        </div>`;
                    }).join('');
                }

            } catch (e) {
                console.warn('pollAdminDashboard error:', e);
            }
        }

        pollAdminDashboard();
        setInterval(pollAdminDashboard, 5000);

        // ── Room / Dept / Faculty detail modal (event delegation) ──
        function openDetailModal(roomItem) {
            try {
                var data = JSON.parse(roomItem.dataset.detail);
            } catch(e) { return; }
            var type = roomItem.dataset.type;
            var modal = document.getElementById('detailModal');
            var titleMap = { 'room': 'Room Detail', 'dept-list': 'Department Detail', 'faculty': 'Faculty Detail' };
            document.getElementById('detailModalLabel').textContent = titleMap[type] || 'Detail';
            var body = modal.querySelector('.modal-body');
            if (type === 'room') {
                body.innerHTML =
                    '<div class="detail-row"><span class="detail-label">Room Name</span><span>' + (data.room_name || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Room Size</span><span>' + (data.room_size || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Description</span><span>' + (data.description || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Light Status</span><span style="color:' + (data.light_status === 'on' ? '#198754' : '#842029') + ';font-weight:600;">' + (data.light_status || '').toUpperCase() + '</span></div>';
            } else if (type === 'dept-list') {
                body.innerHTML =
                    '<div class="detail-row"><span class="detail-label">Department</span><span>' + (data.name || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Head</span><span>' + (data.head_name || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Status</span><span>' + (data.status || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Subject Areas</span><span>' + (data.subject_areas && data.subject_areas.length ? data.subject_areas.join(', ') : 'None') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Subjects</span><span>' + (data.subjects && data.subjects.length ? data.subjects.join(', ') : 'None') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Faculty Members</span><span>' + (data.member_count != null ? data.member_count : '0') + '</span></div>';
            } else if (type === 'faculty') {
                body.innerHTML =
                    '<div class="detail-row"><span class="detail-label">Name</span><span>' + (data.name || 'N/A') + '</span></div>' +
                    '<div class="detail-row"><span class="detail-label">Approved</span><span>' + (data.date_shown || 'N/A') + '</span></div>';
            }
            new bootstrap.Modal(modal).show();
        }

        document.getElementById('rooms-list').addEventListener('click', function(e) {
            var item = e.target.closest('.room-item');
            if (item) openDetailModal(item);
        });
        document.getElementById('depts-list').addEventListener('click', function(e) {
            var item = e.target.closest('.room-item');
            if (item) openDetailModal(item);
        });
        document.getElementById('faculty-list').addEventListener('click', function(e) {
            var item = e.target.closest('.room-item');
            if (item) openDetailModal(item);
        });
    </script>

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
    <script src="../../script/faculty-tutorial.js"></script>
</body>

</html>