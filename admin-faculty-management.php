<?php
$page_title = "Faculty Management";
require_once '../../php/includes/admin-head.php';

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
/** @var int $admin_id */

$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/handlers/faculty-approvals-handler.php';
require_once $phpRoot . '/handlers/admin-handlers.php';

/** @var string $message */
/** @var int $total_faculty */
/** @var int $pending_count */
/** @var int $ext_pending */
/** @var array $faculty_list */
/** @var array $extensions */

require_once '../../php/handlers/admin-handlers.php';
$conn->close();
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
                    <div style="background-color:#f8f9fa;" class="section-container system-status">
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

                            <div class="department-card">
                                <div class="department-header d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-start">
                                        <h3 class="bold">Magical Transfiguration Department</h3>
                                    </div>
                                    <div class="d-flex align-items-end">
                                        <button class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center"
                                            onclick="window.location.href='admin-department-card.php?id=1'"
                                            title="View Department"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="auto">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <!-- <button class="btn-icon btn-icon-edit d-inline-flex align-items-center justify-content-center"
                                            onclick="window.location.href='admin-department-card.php?id=1'"
                                            title="Edit Department"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="auto">
                                            <i class="bi bi-pencil"></i>
                                        </button> -->
                                    </div>
                                </div>

                                <span class="department-badge department-badge-active bold">Active</span>
                                <span class="department-badge department-badge-pending bold">Pending</span>
                                <span class="department-badge department-badge-inactive bold">Inactive</span>
                                <!--Note: Status pills for when admin wants to disable/enable departments-->

                                <div class="department-info mt-2">
                                    <div class="info-row">
                                        <i class="bi bi-person-badge me-2"></i>
                                        <span class="label">Head:</span>
                                        <span class="value bold">Minerva McGonagall</span>
                                    </div>
                                    <div class="info-row">
                                        <i class="bi bi-people me-2"></i>
                                        <span class="label">Number of faculty:</span>
                                        <span class="value bold">5</span>
                                    </div>
                                </div>
                            </div>

                            <div class="department-card">
                                <div class="department-header d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-start">
                                        <h3 class="bold">example if newly created</h3>
                                    </div>
                                    <div class="d-flex align-items-end">
                                        <button class="btn-icon btn-icon-view d-inline-flex align-items-center justify-content-center"
                                            onclick="window.location.href='admin-department-card.php?id=1'"
                                            title="View Department"
                                            data-bs-toggle="tooltip"
                                            data-bs-placement="auto">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <span class="department-badge department-badge-pending bold">Pending</span>

                                <div class="department-info mt-2">
                                    <div class="info-row">
                                        <i class="bi bi-person-badge me-2"></i>
                                        <span class="label">Head:</span>
                                        <span class="value bold">None assigned</span>
                                    </div>
                                    <div class="info-row">
                                        <i class="bi bi-people me-2"></i>
                                        <span class="label">Number of faculty:</span>
                                        <span class="value bold">None assigned</span>
                                    </div>
                                </div>
                            </div>

                        </div>


                    </div>
                </div>


                <div class="group-container gap-3">
                    <!-- Faculty Directory -->
                    <div class="faculty-directory card border-0 shadow-sm p-4 bg-white w-100">
                        <div class="faculty-directory-container d-flex flex-column justify-content-center align-items-center p-3 mb-3">
                            <h2 class="bold mb-0"><i class="bi bi-people mb-3"></i> Faculty Directory</h2>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="light medium gap-2" style="font-size: 12px;" onclick="filterList('all')"><i class="bi bi-border-all"></i> All Records</button>
                                <button type="button" class="light gap-2" style="font-size: 12px;" onclick="filterList('approved')"><i class="bi bi-check-circle"></i> Approved</button>
                                <button type="button" class="light gap-2" style="font-size: 12px;" onclick="filterList('unverified')"><i class="bi bi-x-circle"></i> Unverified</button>
                            </div>
                        </div>
                        <div class="style-scrollbar" style="max-height: 400px; overflow-y: auto;">
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
                                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check"></i><!--  Approved Account --></span>
                                            <?php elseif ($faculty['status_label'] === 'pending'): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1"><i class="fa-solid fa-clock"></i><!--  Awaiting Approval --></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary px-2 py-1"><i class="fa-solid fa-envelope"></i><!-- Email Pending Verification --></span>
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
                                            <form method="POST" class="mb-0">
                                                <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit"
                                                    class="btn-icon btn-icon-del"
                                                    data-bs-toggle="tooltip"
                                                    data-bs-placement="auto"
                                                    title="Delete Faculty"
                                                    onclick="openDeleteFacultyModal(<?= $faculty['id'] ?>, '<?= addslashes($faculty['first_name'] . ' ' . $faculty['last_name']) ?>')">
                                                    <!--Dummy onclick function for backend implementation later on-->
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                    </div>

                    <!--Registration Approvals Pending-->
                    <div class="row g-4 mb-4">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm p-4 h-100" style="background-color: var(--secondary-color-1);">
                                <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between p-3 mb-3" style="background: var(--primary-color) !important;
                                border-radius: 8px !important;">
                                    <div class="d-flex flex-column mx-2 align-items-start">
                                        <h2 class="bold" style="font-size:24.5px;"><i class="fa-solid fa-user-clock me-2"></i>Pending Approvals</h2>
                                        <p class="subtitle">Pending registration approvals are displayed here.</p>
                                    </div>
                                </div>
                                <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
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
                        <div class="col-12">
                            <div class="card border-0 shadow-sm p-4 h-100" style="background-color: var(--secondary-color-1);">
                                <div class="section-topbar d-flex my-auto gap-1 align-items-center justify-content-between p-3 mb-3" style="background: var(--primary-color) !important;
                                border-radius: 8px !important;">
                                    <div class="d-flex flex-column mx-2 align-items-start">
                                        <h5 class="bold" style="font-size:24.5px;"><i class="bi bi-clock-history me-2"></i>Pending Extensions</h5>
                                        <p class="subtitle">Pending schedule extensions are displayed here.</p>
                                    </div>
                                </div>
                                <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
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
                                                    <?= $ext['room_name'] ?> · <?= $ext['day_of_week'] ?> ·
                                                    <?= date('g:i A', strtotime($ext['start_time'])) ?> –
                                                    <?= date('g:i A', strtotime($ext['end_time'])) ?>
                                                </p>
                                                <div class="d-flex gap-2 justify-content-end">
                                                    <form method="POST" class="mb-0">
                                                        <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_reject">
                                                        <button type="submit" class="btn btn-xs btn-outline-danger py-1 px-2">Deny</button>
                                                    </form>
                                                    <form method="POST" class="mb-0">
                                                        <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_approve">
                                                        <button type="submit" class="btn btn-xs btn-primary py-1 px-2">Grant</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php
                                        endif;
                                    endforeach;
                                    if (!$has_ext):
                                        ?>
                                        <p class=" text-center py-4 small" style="color: #fff;">No schedule extensions are currently requested.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    </div>
    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <!-- ═══ DELETE FACULTY MODAL ═══ Preempt the delete functionality before deleting-->
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

    <!-- Add Department Modal -->
    <div class="modal fade" id="addDepartmentModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-primary">
                    <h5 class="modal-title">Add Department</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="addDepartmentForm">
                        <!-- Name Field -->
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" placeholder="Enter department name">
                        </div>

                        <!-- Head of Department Dropdown -->
                        <div class="mb-3">
                            <label class="form-label">Head of Department</label>
                            <select class="form-select">
                                <option value="" selected disabled>Select Head of Department</option>
                                <option value="1">Minerva McGonagall</option>
                                <option value="2">Severus Snape</option>
                                <option value="3">Albus Dumbledore</option>
                                <option value="4">Filius Flitwick</option>
                                <option value="5">Pomona Sprout</option>
                            </select>
                        </div>

                        <!-- Faculty Members Section -->
                        <div class="mb-3">
                            <label class="form-label">Faculty Members</label>
                            <!-- Search Bar -->
                            <input type="text" class="form-control mb-2" placeholder="Search faculty members...">
                            <!-- Checkboxes for Faculty Members -->
                            <div class="border rounded p-2" style="max-height: 150px; overflow-y: auto;">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="facultyMembers[]" id="faculty1" value="1">
                                    <label class="form-check-label" for="faculty1">
                                        Minerva McGonagall
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="facultyMembers[]" id="faculty2" value="2">
                                    <label class="form-check-label" for="faculty2">
                                        Severus Snape
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="facultyMembers[]" id="faculty3" value="3">
                                    <label class="form-check-label" for="faculty3">
                                        Albus Dumbledore
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="facultyMembers[]" id="faculty4" value="4">
                                    <label class="form-check-label" for="faculty4">
                                        Filius Flitwick
                                    </label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="facultyMembers[]" id="faculty5" value="5">
                                    <label class="form-check-label" for="faculty5">
                                        Pomona Sprout
                                    </label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/tooltip.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const toast = document.getElementById('toastMsg');
            if (toast && toast.classList.contains('show')) {
                setTimeout(() => toast.classList.remove('show'), 3500);
            }
        });

        function filterList(status) {
            const buttons = document.querySelectorAll('.btn-group button');
            buttons.forEach(btn => btn.classList.remove('medium'));
            event.currentTarget.classList.add('medium');

            document.querySelectorAll('.faculty-list-item').forEach(item => {
                if (status === 'all' || item.dataset.status === status) {
                    item.style.setProperty('display', 'flex', 'important');
                } else {
                    item.style.setProperty('display', 'none', 'important');
                }
            });
        }

        function openDeleteFacultyModal(id, name) {
            document.getElementById('deleteFacultyId').value = id;
            document.getElementById('deleteFacultyName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteFacultyModal')).show();
        }

        function openAddDepartmentModal() {
            new bootstrap.Modal(document.getElementById('addDepartmentModal')).show();
        }
    </script>
</body>

</html>