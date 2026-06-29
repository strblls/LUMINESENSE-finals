<?php
require_once '../../php/session_guard.php';
check_faculty();
require_once '../../php/db_connect.php';

if (empty($_SESSION['is_head'])) {
    header('Location: faculty-timetable.php');
    exit;
}

$faculty_name = htmlspecialchars($_SESSION['faculty_name']);
$faculty_id   = (int)$_SESSION['faculty_id'];
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

// All departments this head manages
$departments = [];
$stmt = $conn->prepare("
    SELECT id, name, description
    FROM departments
    WHERE head_faculty_id = ? AND status = 'active'
    ORDER BY name
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) $departments[] = $row;
$stmt->close();

// Faculty members for each department
$dept_data = [];
$all_subject_areas = []; // By department id: [dept_id => [ [ 'id', 'name', 'subjects' => [..] ] ]]
foreach ($departments as $dept) {
    $dept_id = (int)$dept['id'];
    $dept_members = [];

    $stmt = $conn->prepare("
        SELECT f.id,
               CONCAT(f.first_name, ' ', f.last_name) AS full_name,
               f.is_verified,
               f.approved_by,
               sa.id   AS subject_area_id,
               sa.name AS subject_area_name
        FROM faculty f
        LEFT JOIN subject_area sa ON sa.id = f.subject_area_id
        WHERE f.department_id = ?
        ORDER BY f.last_name, f.first_name
    ");
    $stmt->bind_param('i', $dept_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $dept_members[] = $row;
    $stmt->close();

    // Subject areas for this department
    $sa_list = [];
    $stmt2 = $conn->prepare("
        SELECT sa.id, sa.name
        FROM subject_area sa
        WHERE sa.department_id = ?
        ORDER BY sa.name
    ");
    $stmt2->bind_param('i', $dept_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($sa = $res2->fetch_assoc()) {
        // Subjects under this subject area
        $subj_list = [];
        $stmt3 = $conn->prepare("
            SELECT s.id, s.name
            FROM subjects s
            WHERE s.subject_area_id = ?
            ORDER BY s.name
        ");
        $stmt3->bind_param('i', $sa['id']);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($sub = $res3->fetch_assoc()) $subj_list[] = $sub;
        $stmt3->close();
        $sa['subjects'] = $subj_list;
        $sa_list[] = $sa;
    }
    $stmt2->close();
    $all_subject_areas[$dept_id] = $sa_list;

    $dept_data[$dept_id] = [
        'department' => $dept,
        'members' => $dept_members
    ];
}

// All subject areas for per-faculty assignment
$subject_areas = [];
$r = $conn->query("
    SELECT sa.id, sa.name
    FROM subject_area sa
    ORDER BY sa.name
");
if ($r) {
    while ($row = $r->fetch_assoc()) $subject_areas[] = $row;
}

$current_sched = 'No class right now';
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

    <link type="icon" href="../../logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/faculty-timetable.css">
    <link rel="stylesheet" href="../../css/faculty-common.css">
    <link rel="stylesheet" href="../../css/faculty-settings.css">
    <link rel="stylesheet" href="../../css/faculty-head-timetable.css">

    <title>Department Schedules – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include '../../php/includes/faculty-topbar.php'; ?>

        <!-- ====== DEPARTMENTS ====== -->
        <div class="child-container mb-3">
            <!-- Department Overview -->
            <div class="section-heading">All Assigned Departments</div>

            <div class="main-container faculty-timetable  w-auto mb-3">
                <div class="dept-grid">
                    <?php foreach ($dept_data as $dept_id => $data):
                        $dept = $data['department'];
                        $dept_members = $data['members'];
                    ?>
                        <div class="section-container head-timetable p-2 mb-3">
                            <div class="dept-accent accent-active"></div>
                            <div class="dept-body">

                                <!--Department Header-->
                                <div class="d-flex flex-nowrap flex-row justify-content-between align-items-center">
                                    <h2 class="bold p-3">
                                        <i class="bi bi-diagram-3 me-1"></i><?= htmlspecialchars($dept['name']) ?>
                                    </h2>
                                    <span class="department-status-badge department-badge-active bold h-100">Active</span>
                                </div>

                                <!--Department Overview-->
                                <div class="m-2">

                                    <div class="dept-info-card"><!--Head of Department, which is them-->
                                        <p class="m-0">
                                            <i class="bi bi-person-badge me-1"></i>
                                            Faculty Head: <span class="bold dept-emphases">
                                                <?= $faculty_name ?>
                                            </span>
                                        </p>
                                    </div>
                                    <div class="dept-info-card d-flex align-items-center justify-content-between"><!--Subject Area-->
                                        <div class="d-flex align-items-start">
                                            <p class="m-0 text-wrap">
                                                <i class="bi bi-briefcase me-1"></i>
                                                Subject Area/s:<br>
                                                <?php
                                                $dept_sas = $all_subject_areas[$dept_id] ?? [];
                                                if (!empty($dept_sas)):
                                                    foreach ($dept_sas as $sa):
                                                ?>
                                                    <span class="dept-subject-area bold dept-emphases"><?= htmlspecialchars($sa['name']) ?></span>
                                                <?php
                                                    endforeach;
                                                else:
                                                ?>
                                                    <span class="text-muted small">No subject areas assigned</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-end p-2">
                                            <button class="btn-icon btn-icon-view"
                                                title="View Subject Area/s"
                                                onclick='viewSubjectArea(<?= $dept_id ?>, "<?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>")'
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-icon btn-icon-edit"
                                                title="Edit Subject Area/s"
                                                onclick='openEditSubjectAreaModal(<?= $dept_id ?>, "<?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>")'
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- ====== RESPECTIVE FACULTY MEMBERS ====== -->
                                <h2 class="bold mt-3 p-3"><i class="bi bi-diagram-3-fill me-1"></i>Faculty Members</h2>
                                <div class="d-flex flex-column gap-2">
                                    <?php if (empty($dept_members)): ?>
                                        <p class="text-muted mb-0 p-2"><i class="bi bi-people me-1"></i>No faculty members assigned to this department yet.</p>
                                        <?php else: foreach ($dept_members as $member):
                                            $is_active = $member['is_verified'] && $member['approved_by'];
                                            $area_label = !empty($member['subject_area_name'])
                                                ? $member['subject_area_name']
                                                : 'No subject area assigned';
                                        ?>
                                            <div class="section-container head-timetable p-2 mb-1">
                                                <div class="faculty-member-container">
                                                    <div class="d-flex align-items-center justify-content-center gap-2">
                                                        <div class="faculty-name"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($member['full_name']) ?></div>
                                                        <div class="faculty-subject-area" id="area-label-<?= (int)$member['id'] ?>">
                                                            <i class="bi bi-briefcase me-1"></i><?= htmlspecialchars($area_label) ?>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-end gap-2">
                                                        <div class="d-flex align-items-center room-icons gap-1">
                                                            <?php if ($is_active): ?>
                                                                <span class="status-badge badge-active bold">Active</span>
                                                            <?php else: ?>
                                                                <span class="status-badge bold" style="background:#888;">Pending</span>
                                                            <?php endif; ?>
                                                            <button class="btn-icon btn-icon-edit"
                                                                title="Edit Subject Area"
                                                                onclick="openSubjectAreaModal(<?= (int)$member['id'] ?>, '<?= addslashes($member['full_name']) ?>', <?= (int)($member['subject_area_id'] ?? 0) ?>)"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-pencil"></i>
                                                            </button>
                                                            <button class="btn-icon btn-icon-view"
                                                                title="View Faculty Schedule"
                                                                onclick="window.location.href='faculty-head-membersched.php?faculty_id=<?= (int)$member['id'] ?>'"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                    <?php endforeach;
                                    endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

        <?php include '../../php/includes/faculty-sidebar.php'; ?>

        <script src="../../script/animations.js"></script>
        <script src="../../script/toggles.js"></script>
        <script src="../../script/tooltip.js"></script>
    </div>

    <!-- View Subject Area Modal for Department-->
    <div class="profile-details-modal modal fade" id="viewSubjectAreaModal" tabindex="-1" aria-labelledby="viewSubjectAreaLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">

            <div class="modal-content">

                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewSubjectAreaLabel">
                        <i class="bi bi-briefcase me-2"></i>Subject Area/s
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label bold">Note:</label>
                        <p class="text-muted mb-0" style="font-size: 14px;">This is a read-only view
                            of the subject area assigned to the faculty member.
                            To edit, please use the "Edit Subject Area" button in the department overview.
                        </p>
                    </div>

                    <!-- Subject Area List -->
                    <div class="mb-3">
                        <label class="form-label bold">Subject Area/s and Coverage:</label>
                        <ul class="list-group-horizontal dept-grid" id="viewDeptMembers">
                           
                                <li class="list-group-item text-muted">No subject areas assigned to this department.</li>
                            
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Area Modal for Department -->
    <div class="profile-details-modal modal fade" id="editSubjectAreaModal" tabindex="-1" aria-labelledby="editSubjectAreaLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="editSubjectAreaLabel">
                        <i class="bi bi-briefcase me-2"></i>Edit Subject Area/s
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">

                    <!-- Current view of subject areas -->
                    <div class="dept-info-card mb-3">
                        <label class="form-label bold">Current Subject Area/s:</label>
                        <ul class="list-group-horizontal" id="editDeptMembers">
                            <li class="list-group-item text-muted">No subject areas assigned yet.</li>
                        </ul>
                    </div>

                    <!-- Current subjects under selected subject area -->
                    <div class="dept-info-card mb-3">
                        <label class="form-label bold" style="font-size: 13px;">Current Subjects:</label>
                        <div id="currentSubjectsContainer" class="d-flex flex-wrap gap-1 mb-2">
                            <p class="text-muted small mb-0">Select a subject area to view its subjects.</p>
                        </div>
                    </div>

                    <!-- Adding new subject areas -->
                    <div class="mb-3">
                        <label class="form-label bold">Add New Subject Area/s:</label>
                        <input type="text"
                            class="form-control mb-2"
                            name="dept_subject_area"
                            id="editDeptSubjectArea"
                            placeholder="Enter subject area name and press Enter">
                        <div id="newSubjectAreasContainer" class="d-flex flex-wrap gap-1"></div>
                    </div>

                    <!-- Adding new subjects under subject areas -->
                    <div class="mb-3">
                        <label class="form-label bold">Add New Subject/s <span id="editSelectedSubjectAreaName"></span></label>
                        <input type="hidden" id="editSelectedSubjectAreaId" value="">
                        <input type="text"
                            class="form-control mb-2"
                            name="dept_subject"
                            id="editDeptSubject"
                            placeholder="Select a subject area first"
                            disabled>
                        
                        <label class="form-label bold" style="font-size: 13px;">New Subjects to Add:</label>
                        <div id="newSubjectsContainer" class="d-flex flex-wrap gap-1"></div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="confirmSaveChanges()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Changes Modal -->
    <div class="modal fade" id="confirmChangesModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Confirm Changes</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you are satisfied with your changes? This will update the subject areas and subjects for this department.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="doSaveChanges()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Subject Area Modal -->
    <div class="profile-details-modal modal fade" id="subjectAreaModal" tabindex="-1" aria-labelledby="subjectAreaLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">

                <!--Modal Title-->
                <div class="modal-header">
                    <h5 class="modal-title bold" id="subjectAreaLabel">
                        <i class="bi bi-briefcase me-2"></i>Edit Subject Area
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Assign a subject area for <strong id="sa-faculty-name"></strong>.</p>
                    <input type="hidden" id="sa-faculty-id" value="">
                    <label class="form-label bold">Subject Area</label>
                    <select class="form-select" id="sa-select">
                        <option value="0">No subject area assigned</option>
                        <?php foreach ($subject_areas as $sa): ?>
                            <option value="<?= (int)$sa['id'] ?>">
                                <?= htmlspecialchars(trim($sa['name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="saveSubjectArea()">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let subjectAreaModal = null;

        function openSubjectAreaModal(facultyId, facultyName, currentAreaId) {
            if (!subjectAreaModal) {
                subjectAreaModal = new bootstrap.Modal(document.getElementById('subjectAreaModal'));
            }
            document.getElementById('sa-faculty-id').value = facultyId;
            document.getElementById('sa-faculty-name').textContent = facultyName;
            document.getElementById('sa-select').value = currentAreaId || 0;
            subjectAreaModal.show();
        }

        async function saveSubjectArea() {
            const facultyId = document.getElementById('sa-faculty-id').value;
            const subjectAreaId = document.getElementById('sa-select').value;
            const selectEl = document.getElementById('sa-select');
            const labelText = selectEl.options[selectEl.selectedIndex].text;

            const body = new URLSearchParams({
                action: 'update_subject_area',
                faculty_id: facultyId,
                subject_area_id: subjectAreaId
            });

            const res = await fetch('../../php/handlers/faculty-head-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });
            const data = await res.json();

            if (data.success) {
                const displayLabel = subjectAreaId === '0'
                    ? 'No subject area assigned'
                    : labelText.trim();

                document.getElementById('area-label-' + facultyId).innerHTML =
                    '<i class="bi bi-briefcase me-1"></i>' + displayLabel;
                subjectAreaModal.hide();
            } else {
                alert(data.message || 'Could not save subject area.');
            }
        }

        // ── Dynamic Subject Area Modals ─────────────────────────────
        const subjectAreasData = <?= json_encode($all_subject_areas) ?>;

        function viewSubjectArea(deptId, deptName) {
            const modal = document.getElementById('viewSubjectAreaModal');
            document.getElementById('viewSubjectAreaLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Subject Area/s for ' + deptName;

            const list = document.getElementById('viewDeptMembers');
            const sas = subjectAreasData[deptId] || [];

            if (sas.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned to this department.</li>';
            } else {
                list.innerHTML = sas.map(sa => {
                    const subjects = sa.subjects || [];
                    let subjHtml = '';
                    if (subjects.length === 0) {
                        subjHtml = '<p class="text-muted mb-0" style="font-size: 14px;">Currently no assigned coverage under this subject area</p>';
                    } else {
                        subjHtml = '<div class="d-flex flex-wrap gap-1">' +
                            subjects.map(s =>
                                '<span class="subarea-subject bold dept-emphases align-self-start">' + escapeHtml(s.name) + '</span>'
                            ).join('') +
                            '</div>';
                    }
                    return '<li class="list-group-item">' +
                        '<div class="dept-info-card d-flex flex-column">' +
                        '<span class="dept-subject-area bold dept-emphases align-self-start">' + escapeHtml(sa.name) + '</span>' +
                        '<label class="bold" style="font-size: 14px;">Subjects:</label>' +
                        subjHtml +
                        '</div></li>';
                }).join('');
            }

            new bootstrap.Modal(modal).show();
        }

        let currentEditDeptId = null;

        function openEditSubjectAreaModal(deptId, deptName) {
            currentEditDeptId = deptId;
            const modal = document.getElementById('editSubjectAreaModal');
            document.getElementById('editSubjectAreaLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Edit Coverage for ' + deptName;

            renderEditSubjectAreas(deptId);
            document.getElementById('editDeptSubjectArea').value = '';
            document.getElementById('editDeptSubject').value = '';
            document.getElementById('editDeptSubject').disabled = true;
            document.getElementById('editSelectedSubjectAreaName').textContent = '';

            new bootstrap.Modal(modal).show();
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function renderEditSubjectAreas(deptId) {
            const container = document.getElementById('editDeptMembers');
            const sas = subjectAreasData[deptId] || [];

            if (sas.length === 0) {
                container.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned yet.</li>';
                return;
            }

            container.innerHTML = '<li class="list-group-item d-flex flex-wrap gap-1">' +
                sas.map(sa =>
                    '<span class="dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 subject-area-item" data-sa-id="' + sa.id + '" title="Select Subject Area" data-bs-toggle="tooltip" data-bs-placement="auto">' +
                    escapeHtml(sa.name) +
                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>' +
                    '</span>'
                ).join('') +
                '</li>';

            // Reset current subjects container
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('editDeptSubject').disabled = true;
            document.getElementById('editDeptSubject').placeholder = 'Select a subject area first';
            document.getElementById('editSelectedSubjectAreaName').textContent = 'insertSubjectArea';
            document.getElementById('editSelectedSubjectAreaId').value = '';
            document.getElementById('newSubjectsContainer').innerHTML = '';

            // Re-init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('#editDeptMembers [data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            }
        }

        // Handle subject area input to add chips
        document.addEventListener('DOMContentLoaded', function () {
            const saInput = document.getElementById('editDeptSubjectArea');
            const subjectInput = document.getElementById('editDeptSubject');
            const newSubjectAreasContainer = document.getElementById('newSubjectAreasContainer');

            saInput.addEventListener('change', function () {
                addNewSubjectAreaChip(this);
            });
            saInput.addEventListener('blur', function () {
                if (this.value.trim()) addNewSubjectAreaChip(this);
            });

            subjectInput.addEventListener('change', function () {
                addNewSubjectChip(this);
            });
            subjectInput.addEventListener('blur', function () {
                if (this.value.trim()) addNewSubjectChip(this);
            });
        });

        function addNewSubjectAreaChip(input) {
            const val = input.value.trim();
            if (!val) return;

            const container = document.getElementById('newSubjectAreasContainer');
            const chip = document.createElement('span');
            chip.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3';
            chip.innerHTML = escapeHtml(val) +
                '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
            container.appendChild(chip);
            input.value = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            }
        }

        function addNewSubjectChip(input) {
            const val = input.value.trim();
            if (!val) return;

            const container = document.getElementById('newSubjectsContainer');
            const chip = document.createElement('span');
            chip.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
            chip.innerHTML = escapeHtml(val) +
                '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
            container.appendChild(chip);
            input.value = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
            }
        }

        // Reset edit modal when closed
        document.getElementById('editSubjectAreaModal').addEventListener('hidden.bs.modal', function () {
            document.getElementById('newSubjectAreasContainer').innerHTML = '';
            document.getElementById('newSubjectsContainer').innerHTML = '';
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view its subjects.</p>';
            const subInput = document.getElementById('editDeptSubject');
            subInput.disabled = true;
            subInput.placeholder = 'Select a subject area first';
            subInput.value = '';
            document.getElementById('editSelectedSubjectAreaName').textContent = 'insertSubjectArea';
            document.getElementById('editSelectedSubjectAreaId').value = '';
            document.querySelectorAll('#editDeptMembers .subject-area-item').forEach(el => {
                el.style.boxShadow = '';
                el.style.border = '';
            });
            currentEditDeptId = null;
        });

        function disableSpan(span) {
            if (span.classList.contains('disabled')) return;
            span.classList.add('disabled');
            // Dispose tooltips on the span and its btn-close
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                bootstrap.Tooltip.getInstance(span)?.dispose();
                const close = span.querySelector('.btn-close');
                if (close) bootstrap.Tooltip.getInstance(close)?.dispose();
            }
            const close = span.querySelector('.btn-close');
            if (close) close.style.display = 'none';
            if (!span.querySelector('.bi-plus')) {
                const plusBtn = document.createElement('button');
                plusBtn.type = 'button';
                plusBtn.className = 'btn btn-sm p-0 ms-2 d-inline-flex align-items-center justify-content-center text-white border-0 bg-transparent opacity-50 opacity-100-hover';
                plusBtn.title = 'Add Back';
                plusBtn.setAttribute('data-bs-toggle', 'tooltip');
                plusBtn.innerHTML = '<i class="bi bi-plus fs-3"></i>';
                span.appendChild(plusBtn);
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    new bootstrap.Tooltip(plusBtn);
                }
            }
        }

        function restoreSpan(span) {
            if (!span.classList.contains('disabled')) return;
            span.classList.remove('disabled');
            // Re-init tooltip on the span and its btn-close
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                if (span.hasAttribute('data-bs-toggle')) new bootstrap.Tooltip(span);
                const close = span.querySelector('.btn-close');
                if (close) new bootstrap.Tooltip(close);
            }
            const close = span.querySelector('.btn-close');
            if (close) close.style.display = '';
            const plus = span.querySelector('.btn.btn-sm');
            if (plus) plus.remove();
        }

        // Delegate btn-close, restore, and subject area selection
        document.addEventListener('click', function (e) {
            // btn-close inside editDeptMembers or currentSubjectsContainer → disable
            const containerBtnClose = e.target.closest('#editDeptMembers .btn-close, #currentSubjectsContainer .btn-close');
            if (containerBtnClose) {
                e.preventDefault();
                const span = containerBtnClose.closest('span');
                if (span) disableSpan(span);
                return;
            }

            // bi-plus inside editDeptMembers or currentSubjectsContainer → restore
            const containerPlus = e.target.closest('#editDeptMembers .bi-plus, #currentSubjectsContainer .bi-plus');
            if (containerPlus) {
                const span = containerPlus.closest('span');
                if (span) restoreSpan(span);
                return;
            }

            // btn-close inside new subject areas → remove chip
            const closeNewSa = e.target.closest('#newSubjectAreasContainer .btn-close');
            if (closeNewSa) {
                const span = closeNewSa.closest('span');
                if (span) {
                    bootstrap.Tooltip.getInstance(closeNewSa)?.dispose();
                    span.remove();
                }
                return;
            }

            // btn-close inside new subjects → remove chip
            const closeNewSub = e.target.closest('#newSubjectsContainer .btn-close');
            if (closeNewSub) {
                const span = closeNewSub.closest('span');
                if (span) {
                    bootstrap.Tooltip.getInstance(closeNewSub)?.dispose();
                    span.remove();
                }
                return;
            }

            // Subject area selection in current list
            const saItem = e.target.closest('#editDeptMembers .subject-area-item');
            if (saItem && !saItem.classList.contains('disabled')) {
                const saId = saItem.dataset.saId;
                // Find this subject area's subjects
                for (const deptId in subjectAreasData) {
                    const sas = subjectAreasData[deptId];
                    const found = sas.find(sa => String(sa.id) === saId);
                    if (found) {
                        // Highlight selected
                        document.querySelectorAll('#editDeptMembers .subject-area-item').forEach(el => {
                            el.style.boxShadow = '';
                            el.style.border = '';
                        });
                        saItem.style.boxShadow = '0 0 0 2px #ffc107';
                        saItem.style.border = '2px solid #ffc107';

                        // Update the add-new-subjects section label
                        document.getElementById('editSelectedSubjectAreaName').textContent = ' for ' + escapeHtml(found.name);
                        document.getElementById('editSelectedSubjectAreaId').value = saId;

                        // Enable subject input
                        const subInput = document.getElementById('editDeptSubject');
                        subInput.disabled = false;
                        subInput.placeholder = 'Enter subject name for ' + found.name;
                        subInput.focus();

                        // Clear new subjects container
                        document.getElementById('newSubjectsContainer').innerHTML = '';

                        // Show current subjects for this area
                        const currentSubjectsContainer = document.getElementById('currentSubjectsContainer');
                        currentSubjectsContainer.innerHTML = '';
                        if (found.subjects && found.subjects.length > 0) {
                            found.subjects.forEach(sub => {
                                const span = document.createElement('span');
                                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
                                span.dataset.subjectId = sub.id;
                                span.innerHTML = escapeHtml(sub.name) +
                                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
                                currentSubjectsContainer.appendChild(span);
                            });
                            // Init tooltips on the newly added subject spans
                            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                                currentSubjectsContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
                            }
                        } else {
                            currentSubjectsContainer.innerHTML = '<p class="text-muted small mb-0">No subjects currently under this subject area.</p>';
                        }
                        break;
                    }
                }
                return;
            }
        });

        // Save Changes → Confirm Modal
        function confirmSaveChanges() {
            const confirmModal = new bootstrap.Modal(document.getElementById('confirmChangesModal'));
            confirmModal.show();
        }

        async function doSaveChanges() {
            // Collect data for saving
            const deptId = currentEditDeptId;
            if (!deptId) return;

            // Get current (non-disabled) subject area IDs
            const currentSaSpans = document.querySelectorAll('#editDeptMembers .subject-area-item:not(.disabled)');
            const keepSaIds = Array.from(currentSaSpans).map(el => parseInt(el.dataset.saId));

            // Get removed (disabled) subject area IDs
            const removedSaSpans = document.querySelectorAll('#editDeptMembers .subject-area-item.disabled');
            const removeSaIds = Array.from(removedSaSpans).map(el => parseInt(el.dataset.saId));

            // Get removed subject IDs (disabled subject spans)
            const removedSubjSpans = document.querySelectorAll('#currentSubjectsContainer .subarea-subject.disabled');
            const removeSubjIds = Array.from(removedSubjSpans).map(el => parseInt(el.dataset.subjectId)).filter(id => !isNaN(id));

            // Get new subject area names
            const newSaNames = Array.from(document.querySelectorAll('#newSubjectAreasContainer span')).map(el => {
                const txt = el.childNodes[0]?.textContent?.trim();
                return txt || '';
            }).filter(n => n);

            // Get new subject names (for the currently selected subject area)
            const selectedSaId = document.getElementById('editSelectedSubjectAreaId').value;
            const newSubjNames = Array.from(document.querySelectorAll('#newSubjectsContainer span')).map(el => {
                const txt = el.childNodes[0]?.textContent?.trim();
                return txt || '';
            }).filter(n => n);

            const body = new URLSearchParams({
                action: 'save_department_subject_areas',
                department_id: deptId,
                keep_sa_ids: JSON.stringify(keepSaIds),
                remove_sa_ids: JSON.stringify(removeSaIds),
                remove_subject_ids: JSON.stringify(removeSubjIds),
                new_sa_names: JSON.stringify(newSaNames),
                selected_sa_id: selectedSaId,
                new_subject_names: JSON.stringify(newSubjNames)
            });

            try {
                const res = await fetch('../../php/handlers/faculty-head-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                });
                const data = await res.json();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('confirmChangesModal'))?.hide();
                    bootstrap.Modal.getInstance(document.getElementById('editSubjectAreaModal'))?.hide();
                    location.reload();
                } else {
                    alert(data.message || 'Failed to save changes.');
                }
            } catch (err) {
                alert('An error occurred while saving.');
            }
        }
    </script>
</body>

</html>