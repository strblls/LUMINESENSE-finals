<?php
ob_start(); // Start output buffering FIRST

$page_title = "Faculty Management";
require_once __DIR__ . "/../../src/Includes/admin-head.php";

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
/** @var int $admin_id */

require_once __DIR__ . "/../../src/Handlers/admin-handlers.php";
require_once __DIR__ . "/../../src/Handlers/faculty-approvals-handler.php";

/** @var string $message */
/** @var int $total_faculty */
/** @var int $pending_count */
/** @var int $admin_pending_count */
/** @var int $ext_pending */
/** @var array $faculty_list */
/** @var array $pending_admins */
/** @var array $extensions */
/** @var array $departments */

// Check if the current admin can see admin accounts (is_seeded = 1)
$admin_is_seeded = false;
$seed_check = $conn->query("SELECT is_seeded FROM admins WHERE id = " . (int)$admin_id);
if ($seed_check && $seed_row = $seed_check->fetch_assoc()) {
    $admin_is_seeded = !empty($seed_row['is_seeded']);
}

// Get message from session if available
if (isset($_SESSION['message']) && !empty($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// faculty_id => department_id for anyone who is already a department head
$faculty_head_of_dept = [];
foreach ($departments as $dept) {
    if (!empty($dept['head_faculty_id'])) {
        $faculty_head_of_dept[(int)$dept['head_faculty_id']] = (int)$dept['id'];
    }
}

// Fix stat values
$total_rooms = count($departments);
$pending = $admin_is_seeded ? $pending_count + $admin_pending_count : $pending_count;
if (!$admin_is_seeded) {
    $total_accounts = $total_faculty;
}

$approved_faculty = 0;
foreach ($faculty_list as $f) {
    if ($f['status_label'] === 'approved') $approved_faculty++;
}
$approved_admins = 0;
foreach ($admin_list as $a) {
    if ($a['status_label'] === 'approved') $approved_admins++;
}
$total_approved = $admin_is_seeded ? $approved_faculty + $approved_admins : $approved_faculty;

// Exclude the seeded admin's own account from stat counts
if ($admin_is_seeded) {
    $total_accounts -= 1;
    if ($total_approved > 0) $total_approved -= 1;
}

$active_departments = 0;
foreach ($departments as $d) {
    if ($d['status'] === 'active') $active_departments++;
}

// Build unique faculty member list from all departments
$dept_member_names = [];
foreach ($departments as $d) {
    if (!empty($d['faculty_members'])) {
        foreach ($d['faculty_members'] as $m) {
            $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            if ($name) $dept_member_names[$name] = true;
        }
    }
}
$dept_member_names = array_keys($dept_member_names);
sort($dept_member_names);

$conn->close();

$php_content = ob_get_clean(); // Get any PHP output and clear buffer
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Management & Approvals</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/admin/common.css">
    <link rel="stylesheet" href="../../css/admin/home-reports.css">
    <link rel="stylesheet" href="../../css/admin/faculty-management.css">
    <link rel="stylesheet" href="../../css/faculty/settings.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
</head>

<body class="contrast-bg">
    <?php include __DIR__ . "/../../src/Includes/admin-topbar.php"; ?>
    <?php include __DIR__ . "/../../src/Includes/admin-sidebar.php"; ?>

    <?php if (!empty($message)): ?>
        <div class="toast-wrap">
            <div class="toast-msg show" id="toastMsg"><?= htmlspecialchars($message) ?></div>
        </div>
    <?php else: ?>
        <div class="toast-wrap">
            <div class="toast-msg" id="toastMsg"></div>
        </div>
    <?php endif; ?>

    <div class="parent-container">

        <div class="child-container">

            <div class="page-content">
                <div class="main-container faculty-timetable-heading d-flex flex-column align-items-center justify-content-center w-auto" style="position:relative;background-color:var(--secondary-color-2);" id="facultyHeading">
                    <div class="d-flex gap-2" style="position:absolute;left:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn ms-2" data-panel="panelGuideInfo" title="Guide">
                            <i class="bi bi-info-lg"></i>
                            <span class="timetable-btn-title bold">Guide</span>
                        </button>
                        <div id="panelGuideInfo" class="timetable-panel p-3 m-3">
                            <div class="section-container timetable" style="background-color:#f8f9fa;width:320px;">
                                <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Faculty Management Guide</h6>
                                <p class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                    Navigate through the different sections using the top-right buttons in the dedicated containers or via the buttons in the heading.
                                        In this page, you can manage and access the following:
                                </p>
                                <ol class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                    <li><strong>Pending</strong> - Review and approve/deny pending faculty registrations &amp; extension requests.</li>
                                    <li><strong>Departments</strong> - Add, edit, view, or delete departments. Assign department heads and faculty members.</li>
                                    <li><strong>Faculty Directory</strong> - View, revoke, or delete faculty members. Filter by status or creation date.</li>
                                    <li><strong>Search</strong> - Use the search bars in each section to quickly find departments or faculty members.</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);">
                        <button type="button" class="timetable-btn" data-tab="pending-approvals" title="Pending Approvals">
                            <i class="bi bi-person-check"></i>
                            <span class="timetable-btn-title bold">Pending<br>Approvals</span>
                        </button>
                        <button type="button" class="timetable-btn" data-tab="departments" title="Departments">
                            <i class="bi bi-diagram-3"></i>
                            <span class="timetable-btn-title bold">Departments</span>
                        </button>
                        <button type="button" class="timetable-btn" data-tab="faculty-directory" title="Faculty Directory">
                            <i class="bi bi-people"></i>
                            <span class="timetable-btn-title bold">Faculty<br>Directory</span>
                        </button>
                    </div>

                    <div class="p-2" style="color:#fff;background-color:var(--secondary-color-1);border-radius:5px;overflow:hidden;position:relative;">
                        <div class="tab-text-slide" id="tabTextSlide">
                            <h2 class="text-center bold" id="tabHeading">Faculty Management</h2>
                            <p class="text-uppercase text-center mb-0" style="font-size:14px;color:var(--accent-yellow);" id="tabSubheading">
                                Select a category to get started
                            </p>
                        </div>
                    </div>
                </div>

                <div class="landing-layout">
                    <div class="landing-panels">
                        <!-- Default State -->
                        <div class="landing-panel active" id="defaultState">
                            <div class="default-state-body">
                                <div class="default-state-container">
                                    <div class="section-topbar d-flex align-items-center justify-content-between" style="background-color:var(--accent-yellow);border-radius:5px 5px 0 0;padding:0.5rem 1rem;">
                                        <h2 class="bold mb-0">Approvals</h2>
                                        <button class="light mx-2 w-auto" onclick="switchToTab('pending-approvals')" data-bs-toggle="tooltip" data-bs-placement="top" title="View pending approvals"><i class="bi bi-box-arrow-up-right"></i></button>
                                    </div>
                                    <div class="default-state-container-body">
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $pending ?></div>
                                                <p class="stat-label">Pending Registration</p>
                                            </div>
                                        </div>
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $ext_pending ?></div>
                                                <p class="stat-label">Extension Requests</p>
                                            </div>
                                        </div>
                                        <div class="default-state-info"><i class="bi bi-info-circle"></i><span><strong>Review and approve or deny</strong> pending faculty <strong>registration</strong> requests. Manage <strong>schedule extension</strong> requests submitted by faculty members. Keep track of all pending actions that require your attention.</span></div>
                                    </div>
                                </div>
                                <div class="default-state-container">
                                    <div class="section-topbar d-flex align-items-center justify-content-between" style="background-color:var(--accent-yellow);border-radius:5px 5px 0 0;padding:0.5rem 1rem;">
                                        <h2 class="bold mb-0">Departments</h2>
                                        <button class="light mx-2 w-auto" onclick="switchToTab('departments')" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage departments"><i class="bi bi-box-arrow-up-right"></i></button>
                                    </div>
                                    <div class="default-state-container-body">
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-diagram-3" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $total_rooms ?></div>
                                                <p class="stat-label">Total Departments</p>
                                            </div>
                                        </div>
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-check-circle" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $active_departments ?></div>
                                                <p class="stat-label">Active Departments</p>
                                            </div>
                                        </div>
                                        <div class="default-state-info"><i class="bi bi-info-circle"></i><span><strong>Add, edit, and manage</strong> academic <strong>departments</strong>. <strong>Assign department heads</strong> and organize faculty members into their respective departments. Monitor each department's status and membership at a glance.</span></div>
                                    </div>
                                </div>
                                <div class="default-state-container">
                                    <div class="section-topbar d-flex align-items-center justify-content-between" style="background-color:var(--accent-yellow);border-radius:5px 5px 0 0;padding:0.5rem 1rem;">
                                        <h2 class="bold mb-0">Accounts</h2>
                                        <button class="light mx-2 w-auto" onclick="switchToTab('faculty-directory')" data-bs-toggle="tooltip" data-bs-placement="top" title="Manage faculty accounts"><i class="bi bi-box-arrow-up-right"></i></button>
                                    </div>
                                    <div class="default-state-container-body">
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-person-badge" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $total_accounts ?></div>
                                                <p class="stat-label">Total Accounts</p>
                                            </div>
                                        </div>
                                        <div class="stat-card">
                                            <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                            <div>
                                                <div class="stat-value"><?= $total_approved ?></div>
                                                <p class="stat-label">Approved Accounts</p>
                                            </div>
                                        </div>
                                        <div class="default-state-info"><i class="bi bi-info-circle"></i><span><strong>View, manage, and organize</strong> all <strong>faculty accounts</strong>. <strong>Revoke access</strong> for faculty members when needed or delete outdated records. Access detailed faculty profiles and schedules from the directory.</span></div>
                                    </div>
                                </div>

                            </div>
                            <div class="default-state-message">
                                <i class="bi bi-arrow-up-circle"></i>
                                <p>Select a category from the heading above or press <strong><i class="bi bi-box-arrow-up-right"></i></strong> to manage records.</p>
                            </div>
                        </div>
                        <!-- Pending Approvals Panel -->
                        <div class="landing-panel" id="panel-pending-approvals">
                            <div class="landing-panel-header">
                                <button class="light w-auto" onclick="goToDefaultPanel()" data-bs-toggle="tooltip" data-bs-placement="top" title="Return"><i class="bi bi-arrow-left"></i></button>
                                <h2 class="bold"><i class="bi bi-person-check"></i>Pending Approvals</h2>
                            </div>
                            <div class="landing-panel-body-wrapper">
                                <div class="landing-panel-col-left">
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $pending ?></div>
                                            <p class="stat-label">Pending<br>Registration</p>
                                        </div>
                                    </div>
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $ext_pending ?></div>
                                            <p class="stat-label">Extension<br>Requests</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="landing-panel-col-center">
                                    <div class="pending-body-inner">
                                        <div class="pending-body-left">
                                            <h2 class="pending-body-heading"><i class="bi bi-person-check"></i> Pending Registrations</h2>
                                            <div class="pending-body-list style-scrollbar">
                                                <?php
                                                $has_pending_landing = false;
                                                foreach ($faculty_list as $faculty):
                                                    if ($faculty['status_label'] === 'pending'):
                                                        $has_pending_landing = true;
                                                ?>
                                                        <div class="room-info-row" style="padding: 1rem;">
                                                            <div class="item-info">
                                                                <h5 class="bold"><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></h5>
                                                                <span><?= htmlspecialchars($faculty['email']) ?></span>
                                                            </div>
                                                            <button type="button" class="btn-icon btn-icon-view d-inline-flex align-items-center"
                                                                onclick="window.location.href='admin-faculty-review.php?id=<?= $faculty['id'] ?>'"
                                                                title="Review Application" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                    <?php
                                                    endif;
                                                endforeach;

                                                if ($admin_is_seeded && !empty($pending_admins)):
                                                    foreach ($pending_admins as $admin):
                                                        $has_pending_landing = true;
                                                    ?>
                                                        <div class="room-info-row" style="padding: 1rem;">
                                                            <div class="item-info">
                                                                <h5 class="bold"><?= htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']) ?></h5>
                                                                <span><?= htmlspecialchars($admin['email']) ?> <span class="badge badge-scheduled small ms-1">Admin</span></span>
                                                            </div>
                                                            <button type="button" class="btn-icon btn-icon-view d-inline-flex align-items-center"
                                                                onclick="window.location.href='admin-admin-card.php?id=<?= $admin['id'] ?>'"
                                                                title="Review Application" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                    <?php
                                                    endforeach;
                                                endif;

                                                if (!$has_pending_landing):
                                                    ?>
                                                    <div class="empty-state">
                                                        <i class="bi bi-check2-circle"></i>
                                                        No pending registrations require attention right now.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="pending-body-right">
                                            <h2 class="pending-body-heading"><i class="bi bi-clock-history"></i> Extension Requests
                                                <form method="POST" class="d-flex align-items-center gap-2 ms-auto">
                                                    <input type="hidden" name="action" value="set_grace_period">
                                                    <label class="small" style="white-space:nowrap;">Auto-accept:</label>
                                                    <select name="grace_minutes" class="form-select form-select-sm" style="width:auto;font-size:12px;" onchange="sessionStorage.setItem('activeTab','pending-approvals');this.form.submit()">
                                                        <option value="0" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 0) ? 'selected' : '' ?>>Off</option>
                                                        <option value="15" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 15) ? 'selected' : '' ?>>15 min</option>
                                                        <option value="30" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 30) ? 'selected' : '' ?>>30 min</option>
                                                        <option value="60" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 60) ? 'selected' : '' ?>>1 hr</option>
                                                    </select>
                                                </form>
                                            </h2>
                                            <div class="pending-body-list style-scrollbar">
                                                <?php
                                                $has_ext_landing = false;
                                                foreach ($extensions as $ext):
                                                    if ($ext['status'] === 'pending'):
                                                        $has_ext_landing = true;
                                                ?>
                                                        <div class="room-info-row">
                                                            <div class="item-info">
                                                                <h5><?= htmlspecialchars($ext['faculty_name']) ?></h5>
                                                                <span><?= htmlspecialchars($ext['room_name']) ?> &middot; <?= htmlspecialchars($ext['subject_name'] ?? 'No subject') ?> &middot; <?= $ext['day_of_week'] ?> &middot; <?= date('g:i A', strtotime($ext['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($ext['end_time'])) ?> &middot; +<?= $ext['extend_mins'] ?> mins</span>
                                                            </div>
                                                            <div class="d-flex gap-1">
                                                                <form method="POST" class="mb-0">
                                                                    <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>">
                                                                    <input type="hidden" name="action" value="ext_reject">
                                                                    <button type="submit" class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center" title="Deny" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                        <i class="bi bi-x-lg"></i>
                                                                    </button>
                                                                </form>
                                                                <form method="POST" class="mb-0">
                                                                    <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>">
                                                                    <input type="hidden" name="action" value="ext_approve">
                                                                    <button type="submit" class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center" title="Grant" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                        <i class="bi bi-check-lg"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    <?php
                                                    endif;
                                                endforeach;
                                                if (!$has_ext_landing):
                                                    ?>
                                                    <div class="empty-state">
                                                        <i class="bi bi-clock-history"></i>
                                                        No extension requests at this time.
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Departments Panel -->
                        <div class="landing-panel" id="panel-departments">
                            <div class="landing-panel-header d-flex align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-2">
                                    <button class="light w-auto" onclick="goToDefaultPanel()" data-bs-toggle="tooltip" data-bs-placement="top" title="Return"><i class="bi bi-arrow-left"></i></button>
                                    <h2 class="bold mb-0"><i class="bi bi-diagram-3"></i>Departments</h2>
                                </div>
                                <input type="text" id="deptSearch" class="form-control" placeholder="Search department, head, or faculty..." style="max-width:340px;" oninput="filterDepartments(this.value)">
                                <button class="medium px-2 w-auto" onclick="openAddDepartmentModal()"><i class="bi bi-plus-lg"></i>Add Department</button>
                            </div>
                            <div class="landing-panel-body-wrapper">
                                <div class="landing-panel-col-left">
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-diagram-3" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $total_rooms ?></div>
                                            <p class="stat-label">Total<br>Departments</p>
                                        </div>
                                    </div>
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-check-circle" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $active_departments ?></div>
                                            <p class="stat-label">Active<br>Departments</p>
                                        </div>
                                    </div>
                                    <div class="dept-member-filter">
                                        <div class="dept-member-filter-header">Filter by Faculty</div>
                                        <div class="dept-member-filter-list style-scrollbar">
                                            <div class="dept-member-filter-item active" onclick="filterByFacultyMember(this, '')">All</div>
                                            <?php foreach ($dept_member_names as $name): ?>
                                                <div class="dept-member-filter-item" onclick="filterByFacultyMember(this, '<?= strtolower(htmlspecialchars($name)) ?>')"><?= htmlspecialchars($name) ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="dept-member-filter">
                                        <div class="dept-member-filter-header">Filter by Status</div>
                                        <div class="dept-member-filter-list">
                                            <div class="dept-member-filter-item active" onclick="filterDeptByStatus(this, 'all')">All</div>
                                            <div class="dept-member-filter-item" onclick="filterDeptByStatus(this, 'active')">Active</div>
                                            <div class="dept-member-filter-item" onclick="filterDeptByStatus(this, 'inactive')">Inactive</div>
                                            <div class="dept-member-filter-item" onclick="filterDeptByStatus(this, 'pending')">Pending</div>
                                        </div>
                                    </div>
                                    <div class="dept-member-filter">
                                        <div class="dept-member-filter-header">Sort by Name</div>
                                        <div class="dept-member-filter-list d-flex gap-1" style="flex-direction:row;flex-wrap:wrap;">
                                            <div class="dept-member-filter-item active" onclick="sortDeptsByName(this, 'asc')">A-Z</div>
                                            <div class="dept-member-filter-item" onclick="sortDeptsByName(this, 'desc')">Z-A</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="landing-panel-col-center">
                                    <?php if (!empty($departments)): ?>
                                        <div class="departments-grid style-scrollbar">
                                            <?php foreach ($departments as $dept):
                                                $status = $dept['status'];
                                                if ($status === 'active') {
                                                    $accentClass = 'accent-vacant';
                                                    $badgeClass  = 'badge-vacant';
                                                } elseif ($status === 'inactive') {
                                                    $accentClass = 'accent-occupied';
                                                    $badgeClass  = 'badge-occupied';
                                                } else {
                                                    $accentClass = 'accent-scheduled';
                                                    $badgeClass  = 'badge-scheduled';
                                                }
                                                $memberCount = count($dept['faculty_members'] ?? []);
                                                $headName = 'None assigned';
                                                if (!empty($dept['head_faculty_id'])) {
                                                    if (!empty($dept['head_first_name'])) {
                                                        $headName = htmlspecialchars($dept['head_first_name'] . ' ' . $dept['head_last_name']);
                                                    }
                                                }
                                                $memberNames = '';
                                                if (!empty($dept['faculty_members'])) {
                                                    $names = [];
                                                    foreach ($dept['faculty_members'] as $m) {
                                                        if (!empty($m['first_name']) || !empty($m['last_name'])) {
                                                            $names[] = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                                                        }
                                                    }
                                                    $memberNames = implode(', ', $names);
                                                }
                                            ?>
                                                <div class="room-card" data-dept-name="<?= strtolower(htmlspecialchars($dept['name'])) ?>" data-head-name="<?= strtolower(htmlspecialchars($headName)) ?>" data-member-names="<?= strtolower(htmlspecialchars($memberNames)) ?>" data-dept-status="<?= htmlspecialchars($status) ?>">
                                                    <div class="room-card-accent <?= $accentClass ?>"></div>
                                                    <div class="room-card-body">
                                                        <div class="room-card-header">
                                                            <div>
                                                                <h2 class="room-card-name"><?= htmlspecialchars($dept['name']) ?></h2>
                                                                <div class="room-card-section"><?= htmlspecialchars($dept['description'] ?: 'No description') ?></div>
                                                            </div>
                                                            <span class="room-status-badge <?= $badgeClass ?>"><?= ucfirst(htmlspecialchars($status)) ?></span>
                                                        </div>
                                                        <hr class="room-card-divider">
                                                        <div class="room-info-row" data-search-field="head">
                                                            <p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-person-badge"></i> <span class="room-info-label">Head:</span> <span class="room-info-val"><?= $headName ?></span></p>
                                                        </div>
                                                        <div class="room-info-row" data-search-field="members">
                                                            <p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-people"></i> <span class="room-info-label">Faculty:</span> <span class="room-info-val"><?= $memberCount ?> member(s)</span></p>
                                                        </div>
                                                    </div>
                                                    <div class="room-card-actions">
                                                        <div class="d-flex align-items-center room-icons gap-1">
                                                            <button class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center"
                                                                onclick="openViewDepartmentModal(<?= $dept['id'] ?>, '<?= addslashes($dept['name']) ?>', '<?= addslashes($dept['description'] ?? '') ?>', <?= $dept['head_faculty_id'] ?? 'null' ?>)"
                                                                title="View Department"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <button class="btn-icon btn-icon-edit"
                                                                title="Edit Department"
                                                                onclick="openEditDepartmentModal(<?= $dept['id'] ?>, '<?= addslashes($dept['name']) ?>', '<?= addslashes($dept['description'] ?? '') ?>', <?= $dept['head_faculty_id'] ?? 'null' ?>, '<?= addslashes($dept['status']) ?>')"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn-icon btn-icon-del"
                                                                title="Delete Department"
                                                                onclick="openDeleteDepartmentModal(<?= $dept['id'] ?>, '<?= addslashes($dept['name']) ?>')"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-building"></i>
                                            No departments found.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Faculty Directory Panel -->
                        <div class="landing-panel" id="panel-faculty-directory">
                            <div class="landing-panel-header d-flex align-items-center gap-2" style="position:relative;">
                                <button class="light w-auto" onclick="goToDefaultPanel()" data-bs-toggle="tooltip" data-bs-placement="top" title="Return"><i class="bi bi-arrow-left"></i></button>
                                <h2 class="bold mb-0"><i class="bi bi-people"></i>Account Directory</h2>
                                <input type="text" id="facultySearch" class="form-control" placeholder="Search faculty name or email..." style="max-width:340px;position:absolute;left:50%;transform:translateX(-50%);" oninput="filterFacultyCards(this.value)">
                            </div>
                            <div class="landing-panel-body-wrapper">
                                <div class="landing-panel-col-left">
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-person-badge" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $total_accounts ?></div>
                                            <p class="stat-label">Total<br>Accounts</p>
                                        </div>
                                    </div>
                                    <div class="landing-stat-card">
                                        <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                        <div class="stat-info">
                                            <div class="stat-value"><?= $total_approved ?></div>
                                            <p class="stat-label">Approved<br>Accounts</p>
                                        </div>
                                    </div>
                                    <div class="faculty-side-filter">
                                        <div class="dept-member-filter-header">Sort by Name</div>
                                        <div class="dept-member-filter-list d-flex gap-1" style="flex-direction:row;flex-wrap:wrap;">
                                            <div class="dept-member-filter-item active" onclick="sortFacultyByName(this, 'asc')">A-Z</div>
                                            <div class="dept-member-filter-item" onclick="sortFacultyByName(this, 'desc')">Z-A</div>
                                        </div>
                                    </div>
                                    <?php if ($admin_is_seeded): ?>
                                    <div class="faculty-side-filter">
                                        <div class="dept-member-filter-header">Filter by Type</div>
                                        <div class="dept-member-filter-list">
                                            <div class="dept-member-filter-item active" onclick="filterFacultyByType(this, 'all')">All Accounts</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByType(this, 'admin')">Administrator</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByType(this, 'faculty')">Faculty</div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="faculty-side-filter">
                                        <div class="dept-member-filter-header">Filter By Status</div>
                                        <div class="dept-member-filter-list">
                                            <div class="dept-member-filter-item active" onclick="filterFacultyByStatus(this, 'all')">All</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByStatus(this, 'approved')">Approved</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByStatus(this, 'pending')">Pending</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByStatus(this, 'unverified')">Unverified</div>
                                            <?php if ($admin_is_seeded): ?>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByStatus(this, 'archived')">Archived</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="faculty-side-filter">
                                        <div class="dept-member-filter-header">Filter By Date Created</div>
                                        <div class="dept-member-filter-list">
                                            <div class="dept-member-filter-item active" onclick="filterFacultyByDate(this, 'all')">All</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByDate(this, 'today')">Today</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByDate(this, 'week')">This Week</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByDate(this, 'month')">This Month</div>
                                            <div class="dept-member-filter-item" onclick="filterFacultyByDate(this, 'year')">This Year</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="landing-panel-col-center">
                                    <?php
                                    $accounts_merge = array_map(function($f) {
                                        $f['_type'] = 'faculty';
                                        $f['_search'] = strtolower($f['first_name'] . ' ' . $f['last_name'] . ' ' . $f['email'] . ' faculty');
                                        return $f;
                                    }, $faculty_list);
                                    if ($admin_is_seeded) {
                                        $visible_admins = array_filter($admin_list, function($a) use ($admin_id) {
                                            return (int)$a['id'] !== (int)$admin_id;
                                        });
                                        $accounts_merge = array_merge($accounts_merge,
                                            array_map(function($a) {
                                                $a['_type'] = 'admin';
                                                $a['_search'] = strtolower($a['first_name'] . ' ' . $a['last_name'] . ' ' . $a['email'] . ' admin');
                                                return $a;
                                            }, $visible_admins)
                                        );
                                    }
                                    $all_accounts = $accounts_merge;
                                    usort($all_accounts, function($a, $b) {
                                        $cmp = strcmp(strtolower($a['last_name'] ?? $a['first_name']), strtolower($b['last_name'] ?? $b['first_name']));
                                        return $cmp !== 0 ? $cmp : strcmp(strtolower($a['first_name'] ?? ''), strtolower($b['first_name'] ?? ''));
                                    });
                                    ?>
                                    <?php if (!empty($all_accounts)): ?>
                                        <div class="faculty-grid style-scrollbar">
                                            <?php foreach ($all_accounts as $acct):
                                                $is_admin = $acct['_type'] === 'admin';
                                                $f_status = $acct['status_label'];
                                                if ($f_status === 'approved') {
                                                    $f_accent = 'accent-vacant';
                                                    $f_badge = 'badge-vacant';
                                                    $f_badge_label = 'Approved';
                                                } elseif ($f_status === 'pending') {
                                                    $f_accent = 'accent-scheduled';
                                                    $f_badge = 'badge-scheduled';
                                                    $f_badge_label = 'Pending';
                                                } else {
                                                    $f_accent = 'accent-occupied';
                                                    $f_badge = 'badge-occupied';
                                                    $f_badge_label = 'Unverified';
                                                }
                                                $f_created = !empty($acct['created_at']) ? date('M j, Y', strtotime($acct['created_at'])) : '-';
                                                $f_approved = !empty($acct['approved_at']) ? date('M j, Y', strtotime($acct['approved_at'])) : '-';
                                                $f_name = htmlspecialchars($acct['first_name'] . ' ' . $acct['last_name']);
                                                $f_email = htmlspecialchars($acct['email']);
                                                $f_type_label = $is_admin ? 'Admin' : 'Member';
                                                $f_type_class = '';
                                                if ($is_admin) {
                                                    $f_type_class = 'badge-default';
                                                    $f_type_style = 'background:#3b3809;color:#f9c74f;font-size:0.65rem;font-weight:800;';
                                                } else {
                                                    $is_faculty_head = isset($faculty_head_of_dept[$acct['id']]);
                                                    $f_type_class = $is_faculty_head ? 'faculty-head' : 'faculty-member';
                                                    $f_type_style = 'font-size:0.65rem;font-weight:800;';
                                                    if ($is_faculty_head) $f_type_label = 'Head';
                                                }
                                            ?>
                                                <div class="room-card" data-faculty-status="<?= $f_status ?>" data-faculty-created="<?= htmlspecialchars($acct['created_at'] ?? '') ?>" data-faculty-search="<?= $acct['_search'] ?>" data-faculty-name="<?= strtolower(htmlspecialchars($f_name)) ?>" data-faculty-email="<?= strtolower(htmlspecialchars($f_email)) ?>" data-faculty-type="<?= $acct['_type'] ?>" data-faculty-is-archived="<?= $acct['is_archived'] ?? 0 ?>">
                                                    <div class="room-card-accent <?= $f_accent ?>"></div>
                                                    <div class="room-card-body">
                                                        <div class="room-card-header">
                                                            <div>
                                                                <h2 class="room-card-name"><?= $f_name ?></h2>
                                                                <div class="room-card-section"><?= $f_email ?></div>
                                                                <div style="display:flex;align-items:center;gap:4px;margin-top:4px;">
                                                                    <div class="status-badge <?= $f_type_class ?>" style="<?= $f_type_style ?>"><?= $f_type_label ?></div>
                                                                    <span class="room-status-badge <?= $f_badge ?>"><?= $f_badge_label ?></span>
                                                                    <?php if (!empty($acct['is_archived'])): ?>
                                                                        <span class="badge-archived">Archived</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <hr class="room-card-divider">
                                                        <div class="room-info-row">
                                                            <p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-calendar-plus"></i> <span class="room-info-label">Created:</span> <span class="room-info-val"><?= $f_created ?></span></p>
                                                        </div>
                                                        <div class="room-info-row">
                                                            <p class="d-flex align-items-center gap-2 mb-0"><i class="bi bi-check-circle"></i> <span class="room-info-label">Approved:</span> <span class="room-info-val"><?= $f_approved ?></span></p>
                                                        </div>
                                                    </div>
                                                    <div class="room-card-actions">
                                                        <div class="d-flex align-items-center room-icons gap-1">
                                                            <?php if (!$is_admin && $f_status !== 'unverified'): ?>
                                                            <button class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center"
                                                                onclick="window.location.href='<?= $f_status === 'pending' ? 'admin-faculty-review.php?id=' . $acct['id'] : 'admin-faculty-card.php?id=' . $acct['id'] ?>'"
                                                                title="<?= $f_status === 'pending' ? 'Review Application' : 'View Profile' ?>" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <?php endif; ?>
                                                            <?php if ($is_admin): ?>
                                                            <button class="btn-icon btn-icon-del" title="Delete Admin"
                                                                onclick="alert('Delete admin not yet implemented')" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                            <?php else: ?>
                                                            <?php if ($f_status === 'approved'): ?>
                                                                <form method="POST" class="mb-0" style="display:inline-flex;" onsubmit="sessionStorage.setItem('activeTab','faculty-directory')">
                                                                    <input type="hidden" name="faculty_id" value="<?= $acct['id'] ?>"><input type="hidden" name="action" value="revoke">
                                                                    <button type="submit" class="btn-icon btn-icon-revoke room-icon-btn"
                                                                        data-bs-toggle="tooltip" data-bs-placement="auto" title="Revoke Access">
                                                                        <i class="bi bi-x-circle"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <button type="button" class="btn-icon btn-icon-del room-icon-btn"
                                                                data-bs-toggle="tooltip" data-bs-placement="auto" title="Delete Faculty"
                                                                onclick="openDeleteFacultyModal(<?= $acct['id'] ?>, '<?= addslashes($f_name) ?>')">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                            <?php if (!empty($acct['is_archived'])): ?>
                                                                <form method="POST" class="mb-0" style="display:inline-flex;" onsubmit="sessionStorage.setItem('activeTab','faculty-directory')">
                                                                    <input type="hidden" name="faculty_id" value="<?= $acct['id'] ?>">
                                                                    <input type="hidden" name="action" value="reactivate">
                                                                    <button type="submit" class="btn-icon" title="Reactivate Account" style="color:#198754;" data-bs-toggle="tooltip" data-bs-placement="auto">
                                                                        <i class="bi bi-arrow-clockwise"></i>
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                            <div class="empty-state" id="facultyNoResults" style="display:none;">
                                                <i class="bi bi-search"></i>
                                                No results match your filter criteria.
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state">
                                            <i class="bi bi-people"></i>
                                            No accounts found.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>


            </div>

        
        </div>

    </div>

    <?php include __DIR__ . "/../../src/Includes/profile-offcanvas.php"; ?>

    <!-- â•â•â• DELETE FACULTY MODAL â•â•â• -->
    <div class="modal fade" id="deleteFacultyModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Delete Faculty</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-trash" style="font-size:2.5rem;color:#c0392b;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you want to delete <strong id="deleteFacultyName"></strong>?
                        This will also remove all schedules and logs for this faculty.
                    </p>
                </div>
                <form method="POST" action="../../handlers/faculty-approvals-handler.php" onsubmit="sessionStorage.setItem('activeTab','faculty-directory')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="faculty_id" id="deleteFacultyId">
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium" style="background:#c0392b;">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- â•â•â• DELETE DEPARTMENT MODAL â•â•â• -->
    <div class="modal fade" id="deleteDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Delete Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-diagram-3" style="font-size:2.5rem;color:#c0392b;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you want to delete the <strong id="deleteDepartmentName"></strong> department?
                        This action cannot be undone and will unlink all associated faculty members.
                    </p>
                </div>
                <form method="POST" action="../../handlers/admin-handlers.php">
                    <input type="hidden" name="action" value="delete_department">
                    <input type="hidden" name="department_id" id="deleteDepartmentId">
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium" style="background:#c0392b;">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- â•â•â• DUPLICATE WARNING MODAL â•â•â• -->
    <div class="modal fade" id="duplicateWarningModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Duplicate Selection</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;" id="duplicateWarningMessage">
                        A faculty member cannot be both Head of Department and a regular Faculty Member.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <!-- â•â•â• ADD DEPARTMENT MODAL â•â•â• -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/admin-handlers.php" id="addDepartmentForm">
                    <input type="hidden" name="action" value="add_department">
                    <div class="modal-body p-4">
                        <!-- Name Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Name</label>
                            <input type="text" class="form-control" name="dept_name" placeholder="Enter department name" required>
                        </div>

                        <!-- Description Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Description</label>
                            <input type="text" class="form-control" name="dept_description" placeholder="Enter department description">
                        </div>

                        <!-- Subject Area Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Subject Areas</label>
                            <div class="subject-area-input-wrap">
                                <input type="text" class="form-control" id="addSubjectAreaInput" placeholder="Type subject area and press Enter">
                                <div class="subject-area-tags d-flex flex-wrap gap-1 mt-2" id="addSubjectAreaTags"></div>
                            </div>
                        </div>

                        <!-- Head of Department Section -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label bold mb-0">Head of Department <span class="text-muted fw-normal" style="font-size:12px;">(Optional)</span></label>
                                <button type="button" class="btn btn-sm btn-outline-secondary w-auto px-2" onclick="clearHod('add')">None</button>
                            </div>
                            <input type="text" class="form-control mb-2" placeholder="Search faculty members..." oninput="filterFacultySearch(this, 'addHodList')">
                            <div id="addHodList" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php if (!empty($faculty_list)): foreach ($faculty_list as $f): if ($f['status_label'] === 'approved'): ?>
                                            <div class="form-check py-1 faculty-search-item" data-name="<?= strtolower(htmlspecialchars($f['first_name'] . ' ' . $f['last_name'])) ?>">
                                                <input class="form-check-input add-hod-radio" type="radio" name="head_faculty_id" id="addHod_<?= $f['id'] ?>" value="<?= $f['id'] ?>" data-faculty-id="<?= $f['id'] ?>">
                                                <label class="form-check-label" for="addHod_<?= $f['id'] ?>">
                                                    <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                                                    <span class="text-muted small ms-1">(<?= htmlspecialchars($f['email']) ?>)</span>
                                                </label>
                                            </div>
                                    <?php endif;
                                    endforeach;
                                else: ?>
                                    <p class="text-muted small mb-0 p-2">No approved faculty members available.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Faculty Members Section -->
                        <div class="mb-3">
                            <label class="form-label bold">Faculty Members <span class="text-muted fw-normal" style="font-size:12px;">(Optional)</span></label>
                            <input type="text" class="form-control mb-2" placeholder="Search faculty members..." oninput="filterFacultySearch(this, 'addMembersList')">
                            <div id="addMembersList" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php if (!empty($faculty_list)): foreach ($faculty_list as $f): if ($f['status_label'] === 'approved'): ?>
                                            <div class="form-check py-1 faculty-search-item" data-name="<?= strtolower(htmlspecialchars($f['first_name'] . ' ' . $f['last_name'])) ?>">
                                                <input class="form-check-input add-member-checkbox" type="checkbox" name="faculty_members[]" id="addMember_<?= $f['id'] ?>" value="<?= $f['id'] ?>" data-faculty-id="<?= $f['id'] ?>">
                                                <label class="form-check-label" for="addMember_<?= $f['id'] ?>">
                                                    <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                                                    <span class="text-muted small ms-1">(<?= htmlspecialchars($f['email']) ?>)</span>
                                                </label>
                                            </div>
                                    <?php endif;
                                    endforeach;
                                else: ?>
                                    <p class="text-muted small mb-0 p-2">No approved faculty members available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Add Department</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- â•â•â• EDIT DEPARTMENT MODAL â•â•â• -->
    <div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../handlers/admin-handlers.php" id="editDepartmentForm">
                    <input type="hidden" name="action" value="edit_department">
                    <input type="hidden" name="department_id" id="editDeptId">
                    <div class="modal-body p-4">
                        <!-- Name Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Name</label>
                            <input type="text" class="form-control" name="dept_name" id="editDeptName" placeholder="Enter department name" required>
                        </div>

                        <!-- Status Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Status</label>
                            <select class="form-control" name="dept_status" id="editDeptStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <!-- Description Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Description</label>
                            <input type="text" class="form-control" name="dept_description" id="editDeptDescription" placeholder="Enter department description">
                        </div>

                        <!-- Subject Area Field -->
                        <div class="mb-3">
                            <label class="form-label bold">Subject Areas</label>
                            <div class="subject-area-input-wrap">
                                <input type="text" class="form-control" id="editSubjectAreaInput" placeholder="Type subject area and press Enter">
                                <div class="subject-area-tags d-flex flex-wrap gap-1 mt-2" id="editSubjectAreaTags"></div>
                            </div>
                        </div>

                        <!-- Head of Department Section -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label bold mb-0">Head of Department <span class="text-muted fw-normal" style="font-size:12px;">(Optional)</span></label>
                                <button type="button"
                                    class="btn btn-sm btn-outline-secondary w-auto px-3"
                                    onclick="clearHod('edit')"
                                    title="Clear Selection">
                                    None
                                </button>
                            </div>
                            <input type="text" class="form-control mb-2" placeholder="Search faculty members..." oninput="filterFacultySearch(this, 'editHodList')">
                            <div id="editHodList" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php if (!empty($faculty_list)): foreach ($faculty_list as $f): if ($f['status_label'] === 'approved'): ?>
                                            <div class="form-check py-1 faculty-search-item" data-name="<?= strtolower(htmlspecialchars($f['first_name'] . ' ' . $f['last_name'])) ?>">
                                                <input class="form-check-input edit-hod-radio" type="radio" name="head_faculty_id" id="editHod_<?= $f['id'] ?>" value="<?= $f['id'] ?>" data-faculty-id="<?= $f['id'] ?>">
                                                <label class="form-check-label" for="editHod_<?= $f['id'] ?>">
                                                    <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                                                    <span class="text-muted small ms-1">(<?= htmlspecialchars($f['email']) ?>)</span>
                                                </label>
                                            </div>
                                    <?php endif;
                                    endforeach;
                                else: ?>
                                    <p class="text-muted small mb-0 p-2">No approved faculty members available.</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Faculty Members Section -->
                        <div class="mb-3">
                            <label class="form-label bold">Faculty Members <span class="text-muted fw-normal" style="font-size:12px;">(Optional)</span></label>
                            <input type="text" class="form-control mb-2" placeholder="Search faculty members..." oninput="filterFacultySearch(this, 'editMembersList')">
                            <div id="editMembersList" class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <?php if (!empty($faculty_list)): foreach ($faculty_list as $f): if ($f['status_label'] === 'approved'): ?>
                                            <div class="form-check py-1 faculty-search-item" data-name="<?= strtolower(htmlspecialchars($f['first_name'] . ' ' . $f['last_name'])) ?>">
                                                <input class="form-check-input edit-member-checkbox" type="checkbox" name="faculty_members[]" id="editMember_<?= $f['id'] ?>" value="<?= $f['id'] ?>" data-faculty-id="<?= $f['id'] ?>">
                                                <label class="form-check-label" for="editMember_<?= $f['id'] ?>">
                                                    <?= htmlspecialchars($f['first_name'] . ' ' . $f['last_name']) ?>
                                                    <span class="text-muted small ms-1">(<?= htmlspecialchars($f['email']) ?>)</span>
                                                </label>
                                            </div>
                                    <?php endif;
                                    endforeach;
                                else: ?>
                                    <p class="text-muted small mb-0 p-2">No approved faculty members available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- â•â•â• VIEW DEPARTMENT MODAL â•â•â• -->
    <div class="modal fade" id="viewDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title"><i class="bi bi-eye me-2"></i>View Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label bold">Name</label>
                        <input type="text" class="form-control" id="viewDeptName" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold">Description</label>
                        <input type="text" class="form-control" id="viewDeptDescription" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold">Head of Department</label>
                        <p class="form-control-plaintext" id="viewDeptHead">-</p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold">Faculty Members</label>
                        <ul class="list-group" id="viewDeptMembers">
                            <li class="list-group-item text-muted">None</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/tooltip.js"></script>
    <script>
        window.lumiDepartmentsData = <?= json_encode($departments) ?>;
    </script>
    <script src="../../js/admin/admin-faculty-management.js"></script>

    <link rel="stylesheet" href="../../css/pages/admin-faculty-management.css">
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>