<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name = htmlspecialchars($_SESSION['admin_name']);
$admin_id   = $_SESSION['admin_id'];
$name_parts = explode(' ', $admin_name);
$initials   = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

// Fetch admin email
$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $admin_id);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();

// Handle approve/reject/revoke/delete POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    if ($faculty_id) {
        if ($action === 'approve') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by=?, approved_at=NOW() WHERE id=?');
            $stmt->bind_param('ii', $admin_id, $faculty_id);
            $stmt->execute();
            $stmt->close();
            // Send approval email
            require_once $phpRoot . '/mailer.php';
            $stmt = $conn->prepare('SELECT email, first_name FROM faculty WHERE id=?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->bind_result($f_email, $f_name);
            $stmt->fetch();
            $stmt->close();
            sendApprovalEmail($f_email, $faculty_name);
        } elseif ($action === 'reject' || $action === 'revoke') {
            $stmt = $conn->prepare('UPDATE faculty SET approved_by=NULL, approved_at=NULL WHERE id=?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare('DELETE FROM faculty WHERE id=?');
            $stmt->bind_param('i', $faculty_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: admin-faculty-management.php');
    exit;
}

// Handle extension request approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ext_action'])) {
    $ext_id    = (int)($_POST['ext_id'] ?? 0);
    $ext_action = $_POST['ext_action'];

    if ($ext_id) {
        $status = $ext_action === 'approve_ext' ? 'approved' : 'rejected';
        $stmt = $conn->prepare('UPDATE extension_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?');
        $stmt->bind_param('sii', $status, $admin_id, $ext_id);
        $stmt->execute();
        $stmt->close();

        // If approved, update schedule extended_until
        if ($status === 'approved') {
            $stmt = $conn->prepare('
                SELECT er.schedule_id, er.extend_mins, s.end_time
                FROM extension_requests er
                JOIN schedules s ON s.id = er.schedule_id
                WHERE er.id = ?
            ');
            $stmt->bind_param('i', $ext_id);
            $stmt->execute();
            $stmt->bind_result($sched_id, $extend_mins, $end_time);
            $stmt->fetch();
            $stmt->close();

            $new_end = date('H:i:s', strtotime($end_time) + ($extend_mins * 60));
            $stmt = $conn->prepare('UPDATE schedules SET extended_until=? WHERE id=?');
            $stmt->bind_param('si', $new_end, $sched_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: admin-faculty-management.php');
    exit;
}

// Fetch all faculty
$faculty_list = [];
$r = $conn->query("
    SELECT f.id, f.last_name, f.first_name, f.middle_initial,
           f.email, f.is_verified, f.approved_by, f.created_at,
           CONCAT(a.first_name,' ',a.last_name) AS approved_by_name,
           f.approved_at
    FROM faculty f
    LEFT JOIN admins a ON a.id = f.approved_by
    ORDER BY f.approved_by IS NOT NULL ASC, f.created_at ASC
");
while ($row = $r->fetch_assoc()) $faculty_list[] = $row;

// Fetch selected faculty details
$selected_id = (int)($_GET['id'] ?? ($faculty_list[0]['id'] ?? 0));
$selected    = null;
$f_schedules = [];
$f_logs      = [];

if ($selected_id) {
    $stmt = $conn->prepare('
        SELECT id, first_name, last_name, middle_initial, email, is_verified, approved_by, created_at
        FROM faculty WHERE id = ?
    ');
    $stmt->bind_param('i', $selected_id);
    $stmt->execute();
    $r = $stmt->get_result();
    $selected = $r->fetch_assoc();
    $stmt->close();

    // Their schedules
    $stmt = $conn->prepare("
        SELECT s.id, s.day_of_week, s.start_time, s.end_time, c.room_name
        FROM schedules s JOIN classrooms c ON c.id = s.classroom_id
        ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                 s.start_time
    ");
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $f_schedules[] = $row;
    $stmt->close();

    // Their activity logs
    $stmt = $conn->prepare("
        SELECT l.event_type, l.triggered_by, l.event_time, c.room_name
        FROM lighting_logs l JOIN classrooms c ON c.id = l.classroom_id
        WHERE l.faculty_id = ?
        ORDER BY l.event_time DESC LIMIT 10
    ");
    $stmt->bind_param('i', $selected_id);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $f_logs[] = $row;
    $stmt->close();
}

// Extension requests
$ext_requests = [];
$r = $conn->query("
    SELECT er.id, er.extend_mins, er.status, er.requested_at,
           CONCAT(f.first_name,' ',f.last_name) AS faculty_name,
           s.day_of_week, s.start_time, s.end_time, c.room_name
    FROM extension_requests er
    JOIN faculty f ON f.id = er.faculty_id
    JOIN schedules s ON s.id = er.schedule_id
    JOIN classrooms c ON c.id = s.classroom_id
    ORDER BY er.status ASC, er.requested_at DESC
");
while ($row = $r->fetch_assoc()) $ext_requests[] = $row;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Management – LumineSense</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        .nav-btn {
            width:52px; height:52px; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; cursor:pointer; transition:background-color 0.2s, transform 0.15s;
        }
        .nav-btn i { font-size:22px; }
        .nav-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.06); }

        #sidebarOffcanvas { width:100px !important; background-color:var(--primary-color); }
        #sidebarOffcanvas .offcanvas-header { justify-content:center; padding:1rem 0.5rem; }
        #sidebarOffcanvas .logo { width:75px; height:75px; object-fit:contain; cursor:pointer; }
        #sidebarOffcanvas .offcanvas-body { display:flex; flex-direction:column; align-items:center; gap:8px; padding-top:0.5rem; }
        #sidebarOffcanvas .offcanvas-footer { display:flex; justify-content:center; padding:1rem; }
        #profileOffcanvas { width:240px !important; background-color:var(--primary-color); }
        #profileOffcanvas .avatar-icon { width:80px; height:80px; border-radius:50%; background:#d9d6d6; color:var(--secondary-color-1); display:flex; align-items:center; justify-content:center; }

        .profile-btn {
            width:100%; padding:8px; margin:3px 0; border-radius:8px;
            background-color:var(--secondary-color-1); color:var(--primary-color);
            border:none; font-size:14px; cursor:pointer; font-family:var(--font-primary);
            transition:background-color 0.2s, transform 0.15s;
        }
        .profile-btn:hover { background-color:var(--secondary-color-4); transform:scale(1.02); }

        .info-action-btn {
            width:auto; white-space:nowrap;
            background-color:var(--primary-color); color:var(--secondary-color-1);
            border:1px solid var(--secondary-color-2);
            transition:background-color 0.2s, transform 0.15s;
        }
        .info-action-btn:hover { background-color:var(--secondary-color-1); color:var(--primary-color); transform:scale(1.02); }

        .topbar { position:relative !important; top:auto !important; }

        /* Faculty list sidebar */
        .faculty-sidebar {
            width: 260px;
            flex-shrink: 0;
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1rem;
            max-height: 80vh;
            overflow-y: auto;
        }
        .faculty-list-item {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            padding: 0.6rem 0.8rem;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }
        .faculty-list-item:hover { background: #e9e0f5; }
        .faculty-list-item.active { background: var(--secondary-color-1); color: #fff; }
        .faculty-list-item.active p { color: #ddd !important; }
        .f-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--secondary-color-2); color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px; flex-shrink: 0;
        }
        .faculty-list-item.active .f-avatar { background: var(--secondary-color-4); }

        .badge-pending  { background:#fff3cd; color:#856404; padding:3px 10px; border-radius:20px; font-size:11px; }
        .badge-verified { background:#d1e7dd; color:#0f5132; padding:3px 10px; border-radius:20px; font-size:11px; }
        .badge-ext-pending  { background:#cfe2ff; color:#084298; padding:3px 10px; border-radius:20px; font-size:11px; }
        .badge-ext-approved { background:#d1e7dd; color:#0f5132; padding:3px 10px; border-radius:20px; font-size:11px; }
        .badge-ext-rejected { background:#f8d7da; color:#842029; padding:3px 10px; border-radius:20px; font-size:11px; }

        .faculty-info-card { background:#fff; border-radius:10px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
        .schedule-row { border-bottom: 1px solid #eee; }
        .schedule-row:last-child { border-bottom: none; }

        /* Toast */
        .toast-wrap { position:fixed; bottom:24px; right:24px; z-index:9999; }
        .toast-msg {
            background:var(--secondary-color-1); color:#fff;
            padding:12px 20px; border-radius:10px; font-size:0.85rem;
            font-weight:600; box-shadow:0 6px 20px rgba(0,0,0,.25); display:none;
        }
        .toast-msg.show { display:block; animation:fadeInUp 0.3s ease, fadeOut 0.4s ease 2.2s forwards; }
        @keyframes fadeInUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
        @keyframes fadeOut  { to { opacity:0; } }
    </style>
</head>

<body class="contrast-bg">
<div class="parent-container">

    <!-- TOPBAR -->
    <div class="topbar d-flex" style="background:linear-gradient(0deg,rgba(255,255,255,0) 9%,rgba(47,0,79,0.76) 40%,rgba(47,0,79,0.95) 70%,rgba(47,0,79,1) 100%);">
        <button type="button" id="sidebarTrigger" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list"></i>
        </button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Faculty Management</h1>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-3 mx-2">
            <h4><?= explode(' ', $admin_name)[0] ?></h4>
            <div class="avatar-icon d-flex align-items-center justify-content-center"
                 id="sidebarTrigger2" data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
        </div>
    </div>

    <div class="child-container">
        <div class="main-container p-3 gap-3" style="flex-direction:row; align-items:flex-start;">

            <!-- LEFT: Faculty List -->
            <div class="faculty-sidebar">
                <h6 class="bold mb-3">Faculty Accounts</h6>

                <!-- Filter buttons -->
                <div class="d-flex gap-1 mb-3 flex-wrap">
                    <button class="light" style="font-size:11px; padding:3px 8px;"
                            onclick="filterList('all')">All</button>
                    <button class="light" style="font-size:11px; padding:3px 8px;"
                            onclick="filterList('pending')">Pending</button>
                    <button class="light" style="font-size:11px; padding:3px 8px;"
                            onclick="filterList('approved')">Approved</button>
                </div>

                <?php foreach ($faculty_list as $f):
                    $f_init    = strtoupper(substr($f['first_name'], 0, 1));
                    $f_full    = htmlspecialchars($f['first_name'] . ' ' . $f['last_name']);
                    $approved  = $f['approved_by'] !== null;
                    $is_active = ($f['id'] == $selected_id);
                    $status    = $approved ? 'approved' : 'pending';
                ?>
                    <a href="admin-faculty-management.php?id=<?= $f['id'] ?>"
                       class="faculty-list-item <?= $is_active ? 'active' : '' ?>"
                       data-status="<?= $status ?>">
                        <div class="f-avatar"><?= $f_init ?></div>
                        <div style="min-width:0;">
                            <p class="mb-0" style="font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                <?= $f_full ?>
                            </p>
                            <p class="mb-0" style="font-size:11px; color:var(--muted);">
                                <?= $approved ? '✔ Approved' : '⏳ Pending' ?>
                            </p>
                        </div>
                    </a>
                <?php endforeach; ?>

                <?php if (empty($faculty_list)): ?>
                    <p class="text-muted text-center mt-3" style="font-size:13px;">No faculty yet.</p>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Selected Faculty Details -->
            <div class="flex-fill" style="min-width:0;">

                <?php if ($selected): ?>
                <?php
                    $sel_name    = htmlspecialchars($selected['first_name'] . ' ' . $selected['last_name']);
                    $sel_initials = strtoupper(substr($selected['first_name'], 0, 1) . substr($selected['last_name'], 0, 1));
                    $sel_approved = $selected['approved_by'] !== null;
                ?>

                <!-- Profile row -->
                <div class="faculty-info-card p-4 mb-3">
                    <div class="d-flex align-items-center gap-4 mb-3 pb-3" style="border-bottom:1px solid #eee;">
                        <div class="avatar-icon d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:70px; height:70px; font-size:1.5rem;">
                            <h2 class="bold mb-0"><?= $sel_initials ?></h2>
                        </div>
                        <div>
                            <h3 class="bold mb-1"><?= $sel_name ?></h3>
                            <p class="mb-1" style="font-size:13px; color:var(--muted);">
                                <?= htmlspecialchars($selected['email']) ?>
                            </p>
                            <?= $sel_approved
                                ? '<span class="badge-verified">✔ Approved</span>'
                                : '<span class="badge-pending">⏳ Pending Approval</span>' ?>
                        </div>
                    </div>

                    <!-- Action buttons -->
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if (!$sel_approved): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="faculty_id" value="<?= $selected['id'] ?>">
                                <button type="submit" class="light info-action-btn"
                                        style="padding:8px 16px; font-size:0.85rem; border-radius:8px;">
                                    <i class="bi bi-check-circle me-1"></i>Approve
                                </button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="faculty_id" value="<?= $selected['id'] ?>">
                                <button type="submit" class="light info-action-btn"
                                        style="padding:8px 16px; font-size:0.85rem; border-radius:8px;">
                                    <i class="bi bi-x-circle me-1"></i>Reject
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="faculty_id" value="<?= $selected['id'] ?>">
                                <button type="submit" class="light info-action-btn"
                                        style="padding:8px 16px; font-size:0.85rem; border-radius:8px;">
                                    <i class="bi bi-slash-circle me-1"></i>Revoke Access
                                </button>
                            </form>
                        <?php endif; ?>
                        <button class="light info-action-btn"
                                style="padding:8px 16px; font-size:0.85rem; border-radius:8px;"
                                onclick="dissolve('admin-reports.php?faculty=<?= $selected['id'] ?>')">
                            <i class="bi bi-activity me-1"></i>View Activity
                        </button>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Delete this faculty account? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="faculty_id" value="<?= $selected['id'] ?>">
                            <button type="submit" class="light info-action-btn"
                                    style="padding:8px 16px; font-size:0.85rem; border-radius:8px; border-color:#dc3545; color:#dc3545;">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Schedule + Activity row -->
                <div class="d-flex gap-3 mb-3 flex-wrap">

                    <!-- Schedule -->
                    <div class="faculty-info-card p-3 flex-fill">
                        <h6 class="bold mb-3">Schedule</h6>
                        <?php if (empty($f_schedules)): ?>
                            <p class="text-muted" style="font-size:13px;">No schedules yet.</p>
                        <?php else: foreach ($f_schedules as $s): ?>
                            <div class="d-flex align-items-center justify-content-between py-2 schedule-row">
                                <div>
                                    <p class="bold mb-0" style="font-size:13px;">
                                        <?= $s['day_of_week'] ?> ·
                                        <?= date('g:i A', strtotime($s['start_time'])) ?> –
                                        <?= date('g:i A', strtotime($s['end_time'])) ?>
                                    </p>
                                    <small class="text-muted"><?= htmlspecialchars($s['room_name']) ?></small>
                                </div>
                                <span class="badge-ext-approved"><?= htmlspecialchars($s['room_name']) ?></span>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <!-- Recent Activity -->
                    <div class="faculty-info-card p-3 flex-fill">
                        <h6 class="bold mb-3">Recent Activity</h6>
                        <?php if (empty($f_logs)): ?>
                            <p class="text-muted" style="font-size:13px;">No activity yet.</p>
                        <?php else: foreach ($f_logs as $log): ?>
                            <div class="d-flex justify-content-between py-1 schedule-row">
                                <div>
                                    <p class="mb-0" style="font-size:13px; font-weight:600;">
                                        <?= ucfirst($log['event_type']) ?> – <?= htmlspecialchars($log['room_name']) ?>
                                    </p>
                                    <small class="text-muted"><?= $log['triggered_by'] ?></small>
                                </div>
                                <small class="text-muted"><?= date('g:i A', strtotime($log['event_time'])) ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                </div>

                <?php else: ?>
                    <div class="faculty-info-card p-4 text-center text-muted">
                        <i class="bi bi-people" style="font-size:3rem; opacity:0.3;"></i>
                        <p class="mt-2">No faculty accounts yet.</p>
                    </div>
                <?php endif; ?>

                <!-- Extension Requests -->
                <div class="faculty-info-card p-3 mt-3">
                    <h6 class="bold mb-3">
                        Extension Requests
                        <?php $pending_ext = count(array_filter($ext_requests, fn($e) => $e['status'] === 'pending')); ?>
                        <?php if ($pending_ext > 0): ?>
                            <span class="badge-ext-pending ms-2"><?= $pending_ext ?> pending</span>
                        <?php endif; ?>
                    </h6>
                    <?php if (empty($ext_requests)): ?>
                        <p class="text-muted" style="font-size:13px;">No extension requests yet.</p>
                    <?php else: foreach ($ext_requests as $ext): ?>
                        <div class="d-flex align-items-center justify-content-between py-2 schedule-row flex-wrap gap-2">
                            <div>
                                <p class="mb-0" style="font-size:13px; font-weight:600;">
                                    <?= htmlspecialchars($ext['faculty_name']) ?> —
                                    <?= htmlspecialchars($ext['room_name']) ?>
                                </p>
                                <small class="text-muted">
                                    <?= $ext['day_of_week'] ?> ·
                                    <?= date('g:i A', strtotime($ext['start_time'])) ?> –
                                    <?= date('g:i A', strtotime($ext['end_time'])) ?> ·
                                    +<?= $ext['extend_mins'] ?> mins ·
                                    <?= date('M j g:i A', strtotime($ext['requested_at'])) ?>
                                </small>
                            </div>
                            <div class="d-flex gap-2 align-items-center">
                                <?php if ($ext['status'] === 'pending'): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="ext_action" value="approve_ext">
                                        <input type="hidden" name="ext_id" value="<?= $ext['id'] ?>">
                                        <button type="submit" class="light info-action-btn"
                                                style="padding:4px 12px; font-size:12px; border-radius:8px;">
                                            Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="ext_action" value="reject_ext">
                                        <input type="hidden" name="ext_id" value="<?= $ext['id'] ?>">
                                        <button type="submit" class="light info-action-btn"
                                                style="padding:4px 12px; font-size:12px; border-radius:8px; border-color:#dc3545; color:#dc3545;">
                                            Reject
                                        </button>
                                    </form>
                                <?php elseif ($ext['status'] === 'approved'): ?>
                                    <span class="badge-ext-approved">✔ Approved</span>
                                <?php else: ?>
                                    <span class="badge-ext-rejected">✖ Rejected</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

            </div><!-- /RIGHT -->

        </div>
    </div>

    <!-- Toast -->
    <div class="toast-wrap">
        <div class="toast-msg" id="toastMsg"></div>
    </div>

    <!-- SIDEBAR LEFT -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
        <div class="offcanvas-header justify-content-center">
            <img src="../../images/logo.png" class="logo" alt="Logo" onclick="dissolve('admin-homepage.php')">
        </div>
        <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
            <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')"><i class="bi bi-house-door"></i></button>
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')"><i class="fa-solid fa-person-shelter"></i></button>
            <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')"><i class="bi bi-clipboard2-data"></i></button>
            <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')"><i class="bi bi-exclamation-triangle"></i></button>
            <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')"><i class="bi bi-people"></i></button>
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')"><i class="bi bi-gear"></i></button>
        </div>
        <div class="offcanvas-footer">
            <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
        </div>
    </div>

    <!-- PROFILE OFFCANVAS -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
        <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
            <div class="avatar-icon d-flex align-items-center justify-content-center">
                <h3 class="bold"><?= $initials ?></h3>
            </div>
            <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
            <h6 class="light" style="word-break:break-all;text-align:center;"><?= htmlspecialchars($admin_email) ?></h6>
            <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
                <button class="profile-btn">Classroom Details</button>
                <button class="profile-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
</div>

<script>
    function showToast(msg) {
        const t = document.getElementById('toastMsg');
        t.textContent = msg;
        t.classList.remove('show');
        void t.offsetWidth;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2700);
    }

    function filterList(status) {
        document.querySelectorAll('.faculty-list-item').forEach(item => {
            item.style.display = (status === 'all' || item.dataset.status === status) ? 'flex' : 'none';
        });
    }
</script>
</body>
</html>