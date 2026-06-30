<?php
ob_start(); // Start output buffering FIRST

$page_title = "Faculty Management";
require_once '../../php/includes/admin-head.php';

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
/** @var int $admin_id */

$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/handlers/admin-handlers.php';
require_once $phpRoot . '/handlers/faculty-approvals-handler.php';

/** @var string $message */
/** @var int $total_faculty */
/** @var int $pending_count */
/** @var int $ext_pending */
/** @var array $faculty_list */
/** @var array $extensions */
/** @var array $departments */

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
$pending = $pending_count;

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
    <link rel="icon" href="../../images/logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-common.css">
    <link rel="stylesheet" href="../../css/admin-faculty-management.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>
    <?php include '../../php/includes/admin-sidebar.php'; ?>

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
            <div class="main-container faculty-management gap-5">

                <div class="group-container">
                    <!--Registration Approvals Pending-->
                    <div class="row g-4 mb-2">
                        <div class="col-6">
                            <div class="card border-0 shadow-sm p-4 h-100" style="background-color: var(--secondary-color-1);">
                                <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
                                    <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between p-3 mb-2 sticky-topbar" style="background: var(--primary-color) !important;
                                    border-radius: 8px !important;">
                                        <div class="d-flex flex-column mx-2 align-items-start">
                                            <h2 class="bold" style="font-size:24.5px;"><i class="fa-solid fa-user-clock me-2"></i>Pending Approvals</h2>
                                            <p class="subtitle">Pending registration approvals are displayed here.</p>
                                        </div>
                                    </div>
                                    <?php
                                    $has_pending = false;
                                    foreach ($faculty_list as $faculty):
                                        if ($faculty['status_label'] === 'pending'):
                                            $has_pending = true;
                                    ?>
                                            <div class="d-flex align-items-center justify-content-between p-3 mb-2 border border-warning-subtle rounded bg-warning-subtle bg-opacity-10">
                                                <div>
                                                    <h5 class="bold mb-0"><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></h5>
                                                    <span class="text-muted small" style="font-size: 11px;"><?= htmlspecialchars($faculty['email']) ?></span>
                                                </div>

                                                <button type="button"
                                                    class="btn-icon btn-icon-view d-inline-flex align-items-center"
                                                    onclick="window.location.href='admin-faculty-review.php?id=<?= $faculty['id'] ?>'"
                                                    title="Review Access Request"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-placement="auto">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                        <?php endif;
                                    endforeach;
                                    if (!$has_pending):
                                        ?>
                                        <p class="text-center py-4 small" style="color: #fff;">No pending registrations require attention right now.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Schedule Extension Requests -->
                        <div class="col-6">
                            <div class="card border-0 shadow-sm px-4 pb-4 h-100" style="background-color: var(--secondary-color-1);">
                                <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
                                    <div class="section-topbar d-flex my-4 gap-1 align-items-center justify-content-between p-3 sticky-topbar" style="background: var(--primary-color) !important;
                                    border-radius: 8px !important;">
                                        <div class="d-flex flex-column mx-2 align-items-start">
                                            <h5 class="bold" style="font-size:24.5px;"><i class="bi bi-clock-history me-2"></i>Pending Extensions</h5>
                                            <p class="subtitle">Pending schedule extensions are displayed here.</p>
                                            <form method="POST" class="d-flex align-items-center gap-2 mt-2">
                                                <input type="hidden" name="action" value="set_grace_period">
                                                <label class="small" style="white-space:nowrap;">Auto-accept:</label>
                                                <select name="grace_minutes" class="form-select form-select-sm" style="width:auto;font-size:12px;" onchange="this.form.submit()">
                                                    <option value="0" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 0) ? 'selected' : '' ?>>Off</option>
                                                    <option value="15" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 15) ? 'selected' : '' ?>>15 min</option>
                                                    <option value="30" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 30) ? 'selected' : '' ?>>30 min</option>
                                                    <option value="60" <?= (($_SESSION['ext_grace_minutes'] ?? 0) == 60) ? 'selected' : '' ?>>1 hr</option>
                                                </select>
                                            </form>
                                        </div>
                                    </div>
                                    <?php
                                    $has_ext = false;
                                    foreach ($extensions as $ext):
                                        if ($ext['status'] === 'pending'):
                                            $has_ext = true;
                                    ?>
                                            <div class="p-3 border rounded mb-2 bg-light">
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <h6 class="bold mb-0 text-dark"><?= htmlspecialchars($ext['faculty_name']) ?></h6>
                                                    <span class="badge bg-info text-dark">+<?= $ext['extend_mins'] ?> mins</span>
                                                </div>
                                                <p class="text-secondary small mb-2">
                                                    <?= htmlspecialchars($ext['room_name']) ?> ·
                                                    <?= htmlspecialchars($ext['subject_name'] ?? 'No subject') ?> ·
                                                    <?= $ext['day_of_week'] ?> ·
                                                    <?= date('g:i A', strtotime($ext['start_time'])) ?> –
                                                    <?= date('g:i A', strtotime($ext['end_time'])) ?>
                                                </p>
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <form method="POST" class="mb-0">
                                                        <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_reject">
                                                        <button type="submit" class="light py-1 px-2" data-bs-toggle="tooltip" title="Deny Extension">Deny</button>
                                                    </form>
                                                    <form method="POST" class="mb-0">
                                                        <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_approve">
                                                        <button type="submit" class="medium py-1 px-2" data-bs-toggle="tooltip" title="Grant Extension">Grant</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php
                                        endif;
                                    endforeach;
                                    if (!$has_ext):
                                        ?>
                                        <p class=" text-center small" style="color: #fff;">No schedule extensions are currently requested.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Stats cards -->
                    <div style="background-color:#f8f9fa;" class="section-container py-4">
                        <div class="stat-row gap-3">
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-diagram-3" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $total_rooms ?></div>
                                    <p class="stat-label">Total<br>Departments</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-person-check" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $pending ?></div>
                                    <p class="stat-label">Faculty Pending<br>Approval</p>
                                </div>
                            </div>
                            <div class="stat-card">
                                <span class="stat-icon"><i class="bi bi-clock-history" style="font-size:2rem;color:var(--secondary-color-2);"></i></span>
                                <div>
                                    <div class="stat-value"><?= $ext_pending ?></div>
                                    <p class="stat-label">Extension<br>Requests</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!--Departments-->
                    <div style="background-color:#f8f9fa;" class="section-container system-status gap-3">
                        <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between">
                            <div class="d-flex mx-2 align-items-start">
                                <h2 class="bold"><i class="bi bi-diagram-3 me-2"></i>Departments</h2>
                            </div>
                            <div class="d-flex mx-2 align-items-end">
                                <button class="medium px-2 flex-grow-1"
                                    onclick="openAddDepartmentModal()"><i class="bi bi-plus-lg"></i>Add Department</button>
                            </div>
                        </div>
                        <div class="departments-scroll-container gap-2" style="max-height: 100vh; overflow-y: auto;">

                            <?php if (!empty($departments)): foreach ($departments as $dept): ?>
                        <div class="department-card m-3">
                            <div class="department-card-accent <?= $dept['status'] === 'active' ? 'department-badge-active' : ($dept['status'] === 'inactive' ? 'department-badge-inactive' : 'department-badge-pending') ?>"></div>
                            <div class="department-card-body">
                                <div class="department-card-header">
                                    <div>
                                        <div class="department-card-name d-flex align-items-center justify-content-between">
                                            <?= htmlspecialchars($dept['name']) ?>
                                            <span class="department-status-badge <?= $dept['status'] === 'active' ? 'department-badge-active' : ($dept['status'] === 'inactive' ? 'department-badge-inactive' : 'department-badge-pending') ?> bold mx-2">
                                                <?= ucfirst(htmlspecialchars($dept['status'])) ?>
                                            </span>
                                        </div>
                                        <div class="department-card-section"><?= htmlspecialchars($dept['description']) ?></div>
                                    </div>
                                    <div class="d-flex align-items-center department-icons gap-1">
                                        <button class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center"
                                            onclick="openViewDepartmentModal(<?= $dept['id'] ?>, '<?= addslashes($dept['name']) ?>', '<?= addslashes($dept['description']) ?>', <?= $dept['head_faculty_id'] ?? 'null' ?>)"
                                            title="View Department"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="auto">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button class="btn-icon btn-icon-edit"
                                            title="Edit Department"
                                            onclick="openEditDepartmentModal(<?= $dept['id'] ?>, '<?= addslashes($dept['name']) ?>', '<?= addslashes($dept['description']) ?>', <?= $dept['head_faculty_id'] ?? 'null' ?>, '<?= addslashes($dept['status']) ?>')"
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
                                <hr class="department-card-divider">
                                <div class="department-info-row">
                                    <i class="bi bi-person-badge"></i>
                                    <span class="department-info-label">Head:</span>
                                    <span class="department-info-val bold">
                                        <?php
                                        // head_faculty_id sits on departments (FK → faculty.id ON DELETE SET NULL).
                                        // The handler JOINs faculty, so head_first_name / head_last_name are
                                        // already in $dept — use them directly without touching $all_faculty_map.
                                        // Fallback to the map in case the handler hasn't been updated yet.
                                        if (!empty($dept['head_faculty_id'])) {
                                            if (!empty($dept['head_first_name'])) {
                                                // Fast path: name came from the JOIN in admin-handlers.php
                                                echo htmlspecialchars($dept['head_first_name'] . ' ' . $dept['head_last_name']);
                                            } elseif (!empty($all_faculty_map[(int)$dept['head_faculty_id']])) {
                                                // Fallback: full row stored in map has first_name / last_name
                                                $h = $all_faculty_map[(int)$dept['head_faculty_id']];
                                                echo htmlspecialchars($h['name']);

                                            } else {
                                                echo 'None assigned';
                                            }
                                        } else {
                                            echo 'None assigned';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="department-info-row">
                                    <i class="bi bi-people"></i>
                                    <span class="department-info-label">Number of faculty:</span>
                                    <span class="department-info-val bold">
                                        <?php
                                        // Count all faculty in this department via junction table
                                        $faculty_count = count($dept['faculty_members'] ?? []);
                                        echo $faculty_count > 0 ? $faculty_count : '—';
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                            <p class="text-muted text-center py-4">No departments found.</p>
                            <?php endif; ?>

                        </div>
                    </div>
                </div>

                <div class="group-container gap-3 d-flex flex-column">
                    <!-- Faculty Directory -->
                    <div class="faculty-directory card border-0 shadow-sm p-4 bg-white w-100 d-flex flex-column flex-grow-1">
                        <div class="faculty-directory-container d-flex flex-column justify-content-center align-items-center p-3 mb-3">
                            <h2 class="bold mb-0"><i class="bi bi-people mb-3"></i> Faculty Directory</h2>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="light medium gap-2" style="font-size: 12px;" onclick="filterList('all')"><i class="bi bi-border-all"></i> All Records</button>
                                <button type="button" class="light gap-2" style="font-size: 12px;" onclick="filterList('approved')"><i class="bi bi-check-circle"></i> Approved</button>
                                <button type="button" class="light gap-2" style="font-size: 12px;" onclick="filterList('unverified')"><i class="bi bi-x-circle"></i> Unverified</button>
                            </div>
                        </div>
                        <div class="style-scrollbar flex-grow-1" style="overflow-y: auto;">
                            <?php if (empty($faculty_list)): ?>
                                <p class="text-muted text-center py-4">No records found inside the active index.</p>
                                <?php else: foreach ($faculty_list as $faculty): ?>
                                    <div class="faculty-list-item d-flex align-items-start justify-content-between p-3 mb-2 border rounded" data-status="<?= $faculty['status_label'] ?>">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="avatar bg-light rounded-circle d-flex align-items-center justify-content-center text-secondary bold">
                                                <?= strtoupper(substr($faculty['first_name'], 0, 1) . substr($faculty['last_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h5 class="bold mb-0"><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></h5>
                                                <span class="text-muted small" style="font-size: 11px;"><?= htmlspecialchars($faculty['email']) ?></span>
                                            </div>
                                            <?php if ($faculty['status_label'] === 'approved'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check"></i></span>
                                            <?php elseif ($faculty['status_label'] === 'pending'): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-solid fa-clock"></i></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary px-2 py-1"><i class="fa-solid fa-envelope"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <div>
                                                <button type="button"
                                                    class="btn-icon btn-icon-view d-inline-flex align-items-center"
                                                    onclick="window.location.href='admin-faculty-card.php?id=<?= $faculty['id'] ?>'"
                                                    title="View Profile"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-placement="auto">
                                                    <i class="bi bi-eye"></i>
                                                </button>
                                            </div>
                                            <?php if ($faculty['status_label'] === 'approved'): ?>
                                                <form method="POST" class="mb-0">
                                                    <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>"><input type="hidden" name="action" value="revoke">
                                                    <button type="submit"
                                                        class="btn-icon btn-icon-revoke"
                                                        data-bs-toggle="tooltip"
                                                        data-bs-placement="auto"
                                                        title="Revoke Access">
                                                        <i class="bi bi-x-circle"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <!-- Delete button now opens modal instead of submitting directly -->
                                            <button type="button"
                                                class="btn-icon btn-icon-del"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto"
                                                title="Delete Faculty"
                                                onclick="openDeleteFacultyModal(<?= $faculty['id'] ?>, '<?= addslashes($faculty['first_name'] . ' ' . $faculty['last_name']) ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach;
                                endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <!-- ═══ DELETE FACULTY MODAL ═══ -->
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
                <form method="POST" action="../../php/handlers/faculty-approvals-handler.php">
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

    <!-- ═══ DELETE DEPARTMENT MODAL ═══ -->
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
                <form method="POST" action="../../php/handlers/admin-handlers.php">
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

    <!-- ═══ DUPLICATE WARNING MODAL ═══ -->
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

    <!-- ═══ ADD DEPARTMENT MODAL ═══ -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../php/handlers/admin-handlers.php" id="addDepartmentForm">
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
                                <?php endif; endforeach; else: ?>
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
                                <?php endif; endforeach; else: ?>
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

    <!-- ═══ EDIT DEPARTMENT MODAL ═══ -->
    <div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../php/handlers/admin-handlers.php" id="editDepartmentForm">
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
                                <?php endif; endforeach; else: ?>
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
                                <?php endif; endforeach; else: ?>
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

    <!-- ═══ VIEW DEPARTMENT MODAL ═══ -->
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
                        <p class="form-control-plaintext" id="viewDeptHead">—</p>
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
    <script src="../../script/animations.js"></script>
    <script src="../../script/tooltip.js"></script>
    <script>
        // Departments data to prefill edit modal
        const departmentsData = <?= json_encode($departments) ?>;

        function openDeleteFacultyModal(facultyId, facultyName) {
            document.getElementById('deleteFacultyId').value = facultyId;
            document.getElementById('deleteFacultyName').textContent = facultyName;
            new bootstrap.Modal(document.getElementById('deleteFacultyModal')).show();
        }

        function openDeleteDepartmentModal(deptId, deptName) {
            document.getElementById('deleteDepartmentId').value = deptId;
            document.getElementById('deleteDepartmentName').textContent = deptName;
            new bootstrap.Modal(document.getElementById('deleteDepartmentModal')).show();
        }

        function openAddDepartmentModal() {
            // Reset form
            document.getElementById('addDepartmentForm').reset();
            initSubjectAreaTags('add', []);
            new bootstrap.Modal(document.getElementById('addDepartmentModal')).show();
        }

        function openEditDepartmentModal(deptId, deptName, deptDesc, headId, deptStatus) {
            document.getElementById('editDeptId').value = deptId;
            document.getElementById('editDeptName').value = deptName;
            document.getElementById('editDeptDescription').value = deptDesc;

            // Set status dropdown
            const statusSelect = document.getElementById('editDeptStatus');
            if (deptStatus && ['active', 'inactive'].includes(deptStatus)) {
                statusSelect.value = deptStatus;
            } else {
                statusSelect.value = 'active';
            }
            
            // Find the department in departmentsData
            const dept = departmentsData.find(d => d.id == deptId);
            
            // Pre-select subject areas
            initSubjectAreaTags('edit', dept && dept.subject_areas ? dept.subject_areas : []);
            
            // Clear all previous selections
            document.querySelectorAll('.edit-hod-radio').forEach(r => r.checked = false);
            document.querySelectorAll('.edit-member-checkbox').forEach(c => c.checked = false);
            
            // Pre-select head of department if provided
            if (headId) {
                const radio = document.getElementById('editHod_' + headId);
                if (radio) radio.checked = true;
            }
            
            // Pre-select faculty members
            if (dept && dept.faculty_members) {
                dept.faculty_members.forEach(m => {
                    const checkbox = document.getElementById('editMember_' + m.id);
                    if (checkbox) checkbox.checked = true;
                });
            }
            
            new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
        }

        function openViewDepartmentModal(deptId, deptName, deptDesc, headId) {
            document.getElementById('viewDeptName').value = deptName;
            document.getElementById('viewDeptDescription').value = deptDesc;

            const dept = departmentsData.find(d => d.id == deptId);

            // Head of Department — resolved from the departments query JOIN (faculty h)
            const headEl = document.getElementById('viewDeptHead');
            if (headId && dept && dept.head_first_name) {
                headEl.textContent = dept.head_first_name + ' ' + dept.head_last_name;
            } else {
                headEl.textContent = 'None assigned';
            }

            // Faculty Members — resolved via junction_faculty_department JOIN
            const membersEl = document.getElementById('viewDeptMembers');
            if (dept && dept.faculty_members && dept.faculty_members.length > 0) {
                const names = dept.faculty_members
                    .map(m => (m.first_name || '') + ' ' + (m.last_name || ''))
                    .map(n => n.trim())
                    .filter(n => n);
                if (names.length > 0) {
                    membersEl.innerHTML = names.map(n =>
                        '<li class="list-group-item"><i class="bi bi-person-fill me-2"></i>' + n + '</li>'
                    ).join('');
                } else {
                    membersEl.innerHTML = '<li class="list-group-item text-muted">None</li>';
                }
            } else {
                membersEl.innerHTML = '<li class="list-group-item text-muted">None</li>';
            }

            new bootstrap.Modal(document.getElementById('viewDepartmentModal')).show();
        }

        function filterFacultySearch(input, listId) {
            const filter = input.value.toLowerCase();
            const list = document.getElementById(listId);
            const items = list.querySelectorAll('.faculty-search-item');
            items.forEach(item => {
                const name = item.getAttribute('data-name') || item.textContent.toLowerCase();
                if (name.includes(filter)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function filterList(status) {
            const items = document.querySelectorAll('.faculty-list-item');
            items.forEach(item => {
                if (status === 'all' || item.getAttribute('data-status') === status) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function clearHod(type) {
            const prefix = type === 'add' ? 'add' : 'edit';
            document.querySelectorAll(`.${prefix}-hod-radio`).forEach(r => r.checked = false);
        }

        function showDuplicateWarning() {
            const modal = new bootstrap.Modal(document.getElementById('duplicateWarningModal'));
            modal.show();
        }

        // Form validation to prevent duplicate selection in edit department
        document.getElementById('editDepartmentForm').addEventListener('submit', function(e) {
            const selectedHod = document.querySelector('.edit-hod-radio:checked');
            const selectedMembers = document.querySelectorAll('.edit-member-checkbox:checked');
            
            if (selectedHod) {
                const hodId = selectedHod.getAttribute('data-faculty-id');
                const duplicate = Array.from(selectedMembers).find(m => m.getAttribute('data-faculty-id') === hodId);
                
                if (duplicate) {
                    e.preventDefault();
                    duplicate.closest('.faculty-search-item').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    showDuplicateWarning();
                    return false;
                }
            }
        });

        // Set departments-scroll-container max-height to match main-container
        function syncScrollContainerHeight() {
            const mainContainer = document.querySelector('.main-container.faculty-management.gap-5');
            const scrollContainer = document.querySelector('.departments-scroll-container');
            if (mainContainer && scrollContainer) {
                scrollContainer.style.maxHeight = mainContainer.offsetHeight + 'px';
            }
        }
        document.addEventListener('DOMContentLoaded', syncScrollContainerHeight);
        window.addEventListener('resize', syncScrollContainerHeight);

        // Form validation for add department
        document.getElementById('addDepartmentForm').addEventListener('submit', function(e) {
            const selectedHod = document.querySelector('.add-hod-radio:checked');
            const selectedMembers = document.querySelectorAll('.add-member-checkbox:checked');
            
            if (selectedHod) {
                const hodId = selectedHod.getAttribute('data-faculty-id');
                const duplicate = Array.from(selectedMembers).find(m => m.getAttribute('data-faculty-id') === hodId);
                
                if (duplicate) {
                    e.preventDefault();
                    duplicate.closest('.faculty-search-item').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    showDuplicateWarning();
                    return false;
                }
            }
        });

        // ═════ SUBJECT AREA TAG INPUT ═════
        const subjectAreaState = { add: [], edit: [] };

        function renderSubjectAreaTags(context) {
            const container = document.getElementById(context + 'SubjectAreaTags');
            const tags = subjectAreaState[context];
            container.innerHTML = tags.map((tag, i) =>
                `<span class="subject-area-tag me-1 mb-1 d-inline-flex align-items-center">
                    ${escapeHtml(tag)}
                    <i class="bi bi-x ms-1 subject-area-remove" style="cursor:pointer;font-size:1.1em" data-context="${context}" data-index="${i}"></i>
                </span>`
            ).join('');
        }

        function initSubjectAreaTags(context, initialTags) {
            const input = document.getElementById(context + 'SubjectAreaInput');
            const container = document.getElementById(context + 'SubjectAreaTags');
            subjectAreaState[context] = [...initialTags];
            renderSubjectAreaTags(context);
            input.value = '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            ['add', 'edit'].forEach(function(ctx) {
                const input = document.getElementById(ctx + 'SubjectAreaInput');
                if (!input) return;

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addSubjectAreaTag(ctx);
                    }
                });
                input.addEventListener('blur', function() {
                    addSubjectAreaTag(ctx);
                });
            });

            document.querySelectorAll('.subject-area-tags').forEach(function(container) {
                container.addEventListener('click', function(e) {
                    const removeBtn = e.target.closest('.subject-area-remove');
                    if (removeBtn) {
                        const ctx = removeBtn.dataset.context;
                        const idx = parseInt(removeBtn.dataset.index);
                        subjectAreaState[ctx].splice(idx, 1);
                        renderSubjectAreaTags(ctx);
                    }
                });
            });
        });

        function addSubjectAreaTag(context) {
            const input = document.getElementById(context + 'SubjectAreaInput');
            const val = input.value.trim();
            if (!val) return;
            if (subjectAreaState[context].includes(val)) {
                input.value = '';
                return;
            }
            subjectAreaState[context].push(val);
            renderSubjectAreaTags(context);
            input.value = '';
        }

        // Serialize subject area tags into hidden inputs before form submit
        document.getElementById('addDepartmentForm').addEventListener('submit', function() {
            serializeSubjectAreas('add', this);
        });
        document.getElementById('editDepartmentForm').addEventListener('submit', function() {
            serializeSubjectAreas('edit', this);
        });

        function serializeSubjectAreas(context, form) {
            form.querySelectorAll('input[name="dept_subject_areas[]"]').forEach(function(el) {
                el.remove();
            });
            subjectAreaState[context].forEach(function(name) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'dept_subject_areas[]';
                input.value = name;
                form.appendChild(input);
            });
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    </script>
</body>
</html>
