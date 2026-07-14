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
               GROUP_CONCAT(DISTINCT sa.id ORDER BY sa.name SEPARATOR ',')      AS subject_area_ids,
               GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR '||')    AS subject_area_names,
               GROUP_CONCAT(DISTINCT jfs.subject_id ORDER BY jfs.subject_id SEPARATOR ',') AS assigned_subject_ids
        FROM faculty f
        JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
        LEFT JOIN junction_faculty_subjectarea jfsa ON f.id = jfsa.faculty_id
        LEFT JOIN subject_area sa ON sa.id = jfsa.subject_area_id AND sa.department_id = jfd.department_id
        LEFT JOIN junction_faculty_subject jfs ON f.id = jfs.faculty_id
        WHERE jfd.department_id = ?
          AND f.id != ?
        GROUP BY f.id
        ORDER BY f.last_name, f.first_name
    ");
    $stmt->bind_param('ii', $dept_id, $faculty_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $dept_members[] = $row;
    $stmt->close();

    // Head's own subject areas for this department
    $head_data = [];
    $stmt_h = $conn->prepare("
        SELECT f.id,
               CONCAT(f.first_name, ' ', f.last_name) AS full_name,
               GROUP_CONCAT(DISTINCT sa.id ORDER BY sa.name SEPARATOR ',')      AS subject_area_ids,
               GROUP_CONCAT(DISTINCT sa.name ORDER BY sa.name SEPARATOR '||')    AS subject_area_names,
               GROUP_CONCAT(DISTINCT jfs.subject_id ORDER BY jfs.subject_id SEPARATOR ',') AS assigned_subject_ids
        FROM faculty f
        JOIN junction_faculty_department jfd ON f.id = jfd.faculty_id
        LEFT JOIN junction_faculty_subjectarea jfsa ON f.id = jfsa.faculty_id
        LEFT JOIN subject_area sa ON sa.id = jfsa.subject_area_id AND sa.department_id = jfd.department_id
        LEFT JOIN junction_faculty_subject jfs ON f.id = jfs.faculty_id
        WHERE jfd.department_id = ?
          AND f.id = ?
        GROUP BY f.id
    ");
    $stmt_h->bind_param('ii', $dept_id, $faculty_id);
    $stmt_h->execute();
    $res_h = $stmt_h->get_result();
    $head_data = $res_h->fetch_assoc() ?: [];
    $stmt_h->close();

    // Subject areas for this department
    $sa_list = [];
    $stmt2 = $conn->prepare("
        SELECT sa.id, sa.name, sa.department_id
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
        'members' => $dept_members,
        'head_data' => $head_data
    ];
}

// Determine which departments have coverage (at least one subject area with subjects)
$dept_has_coverage = [];
foreach ($departments as $dept) {
    $did = (int)$dept['id'];
    $has = false;
    if (!empty($all_subject_areas[$did])) {
        foreach ($all_subject_areas[$did] as $sa) {
            if (!empty($sa['subjects'])) {
                $has = true;
                break;
            }
        }
    }
    $dept_has_coverage[$did] = $has;
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

// Flat list of all subject areas with subjects for the faculty coverage modal
$all_subject_areas_flat = [];
foreach ($all_subject_areas as $dept_id => $sas) {
    foreach ($sas as $sa) {
        $all_subject_areas_flat[] = $sa;
    }
}

// Collect unique subject areas and subjects for filter dropdowns
$filter_sa_names = [];
$filter_subject_names = [];
$filter_subject_to_sa = []; // subject name => [subject area names]
foreach ($all_subject_areas as $dept_id => $sas) {
    foreach ($sas as $sa) {
        $sa_name = $sa['name'];
        $filter_sa_names[$sa_name] = $sa_name;
        foreach ($sa['subjects'] as $sub) {
            $sub_name = $sub['name'];
            $filter_subject_names[$sub_name] = $sub_name;
            $filter_subject_to_sa[$sub_name][] = $sa_name;
        }
    }
}
sort($filter_sa_names);
sort($filter_subject_names);

$current_sched = 'No class right now';
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
            <div class="main-container faculty-timetable-heading d-flex align-items-center w-auto" style="background-color: var(--secondary-color-2);">
                <div class="d-flex align-items-center flex-grow-1" style="position:relative;">
                    <button type="button" class="timetable-btn ms-2" data-panel="panelInfoSteps" title="Guide">
                        <i class="bi bi-info-lg"></i>
                        <span class="timetable-btn-title bold">Guide</span>
                    </button>
                    <div id="panelInfoSteps" class="timetable-panel p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;width:320px;">
                            <h6 class="bold mb-2"><i class="bi bi-info-circle me-1"></i>Adding a Schedule for a New Faculty Member</h6>
                            <ol class="ps-3 mb-0" style="font-size:13px;line-height:1.7;">
                                <li>Ensure the faculty member is assigned to a department with coverage (subject areas &amp; subjects).</li>
                                <li>Adding coverages to department/s can be done by clicking the <strong>Assign Coverage</strong> button next to the department coverage.</li>
                                <li>Click the <strong>Edit Assignment</strong> button (briefcase icon) next to their name to assign subject areas and subjects.</li>
                                <li>Once coverage is set, the <strong>calendar icon</strong> button becomes available to view or add their schedule.</li>
                                <li>Click the calendar icon to go to the schedule page and assign time slots.</li>
                            </ol>
                        </div>
                    </div>
                    <input type="text" id="deptSearch" class="form-control" placeholder="Search departments or faculty..." style="max-width:500px;margin-left:16px;">
                </div>
                <div class="d-flex align-items-center pe-2" style="position:relative;">
                    <button type="button" class="timetable-btn" data-panel="panelCoverageFilter" title="Filter by Coverage">
                        <i class="bi bi-funnel"></i>
                        <span class="timetable-btn-title bold">Subject<br>Area</span>
                    </button>
                    <div id="panelCoverageFilter" class="timetable-panel panel-from-right p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;">
                            <ul class="list-unstyled mb-0" id="coverageFilterMenu" style="max-height:300px;overflow-y:auto;">
                                <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Coverages</a></li>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="timetable-btn" data-panel="panelSubjectFilter" title="Filter by Subject">
                        <i class="bi bi-funnel"></i>
                        <span class="timetable-btn-title bold">Subject</span>
                    </button>
                    <div id="panelSubjectFilter" class="timetable-panel panel-from-right p-3 m-3">
                        <div class="section-container timetable" style="background-color:#f8f9fa;">
                            <ul class="list-unstyled mb-0" id="subjectFilterMenu" style="max-height:300px;overflow-y:auto;">
                                <li><a class="d-block px-2 py-1 filter-option active" href="#" data-value="">All Subjects</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section-heading">All Assigned Departments</div>

            <div class="main-container faculty-timetable w-auto mb-3">
                <div class="dept-grid" id="deptGrid">
                    <?php foreach ($dept_data as $dept_id => $data):
                        $dept = $data['department'];
                        $dept_members = $data['members'];
                    ?>
                        <div class="section-container head-timetable p-2 mb-3" data-dept-id="<?= $dept_id ?>">
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


                                    <?php
                                    $h = $data['head_data'];
                                    $h_sa_ids   = !empty($h['subject_area_ids'])   ? explode(',', $h['subject_area_ids'])   : [];
                                    $h_sa_names = !empty($h['subject_area_names']) ? explode('||', $h['subject_area_names']) : [];
                                    $h_sa_json  = htmlspecialchars(json_encode(array_map('intval', $h_sa_ids)), ENT_QUOTES);
                                    $h_subj_ids = !empty($h['assigned_subject_ids']) ? explode(',', $h['assigned_subject_ids']) : [];
                                    $h_subj_json = htmlspecialchars(json_encode(array_map('intval', $h_subj_ids)), ENT_QUOTES);
                                    ?>


                                    <!--Department Coverage -->
                                    <div class="dept-info-card d-flex align-items-center justify-content-between">

                                        <div class="d-flex align-items-start">
                                            <p class="m-0 text-wrap">
                                                <i class="bi bi-briefcase me-1"></i>
                                                Department Coverage:<br>
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
                                                title="View Coverage"
                                                onclick='viewSubjectArea(<?= $dept_id ?>, "<?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>")'
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-icon btn-icon-view"
                                                title="Assign Coverage"
                                                onclick='openEditSubjectAreaModal(<?= $dept_id ?>, "<?= htmlspecialchars($dept['name'], ENT_QUOTES) ?>")'
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Head of Department's own card -->
                                    <div class="dept-info-card d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-start">
                                            <p class="m-0">
                                                <i class="bi bi-person-badge me-1"></i>
                                                Faculty Head: <span class="bold dept-emphases"><?= $faculty_name ?></span>
                                            </p>
                                        </div>
                                        <div class="d-flex align-items-end p-2">
                                            <button class="btn-icon btn-icon-view"
                                                title="View Assignment"
                                                onclick="viewFacultyCoverage(<?= (int)$faculty_id ?>, '<?= addslashes($faculty_name) ?>', <?= (int)$dept_id ?>, '<?= $h_sa_json ?>', '<?= $h_subj_json ?>')"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <button class="btn-icon btn-icon-view"
                                                title="Edit Assignment"
                                                onclick="openSubjectAreaModal(<?= (int)$faculty_id ?>, '<?= addslashes($faculty_name) ?>', '<?= $h_sa_json ?>', <?= (int)$dept_id ?>, '<?= $h_subj_json ?>')"
                                                data-bs-toggle="tooltip"
                                                data-bs-placement="auto">
                                                <i class="bi bi-briefcase"></i>
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
                                            $sa_ids   = !empty($member['subject_area_ids'])   ? explode(',', $member['subject_area_ids'])   : [];
                                            $sa_names = !empty($member['subject_area_names']) ? explode('||', $member['subject_area_names']) : [];
                                            $sa_json  = htmlspecialchars(json_encode(array_map('intval', $sa_ids)), ENT_QUOTES);
                                            $subj_ids = !empty($member['assigned_subject_ids']) ? explode(',', $member['assigned_subject_ids']) : [];
                                            $assigned_subj_json = htmlspecialchars(json_encode(array_map('intval', $subj_ids)), ENT_QUOTES);
                                        ?>
                                            <div class="section-container head-timetable faculty-member-card p-2 mb-1" style="border-left: 5px solid var(--secondary-color-4);">
                                                <div class="faculty-member-container">
                                                    <div class="d-flex flex-column gap-1">
                                                        <div class="faculty-name"><i class="bi bi-person me-1"></i><?= htmlspecialchars($member['full_name']) ?></div>
                                                        <div class="faculty-subject-area d-flex flex-wrap" id="area-label-<?= (int)$member['id'] ?>">
                                                            <?php if (!empty($sa_names)): foreach ($sa_names as $sname): ?>
                                                                    <span class="dept-subject-area bold dept-emphases align-self-start"><?= htmlspecialchars(trim($sname)) ?></span>
                                                                <?php endforeach;
                                                            else: ?>
                                                                <span class="text-muted small">No subject area assigned</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex align-items-end gap-2">
                                                        <div class="d-flex align-items-center room-icons gap-1">
                                                            <?php if ($is_active): ?>
                                                                <span class="status-badge badge-active bold">Active</span>
                                                            <?php else: ?>
                                                                <span class="status-badge bold" style="background:#888;">Pending</span>
                                                            <?php endif; ?>
                                                            <button class="btn-icon btn-icon-view"
                                                                title="View Assignment"
                                                                onclick="viewFacultyCoverage(<?= (int)$member['id'] ?>, '<?= addslashes($member['full_name']) ?>', <?= (int)$dept_id ?>, '<?= $sa_json ?>', '<?= $assigned_subj_json ?>')"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-eye"></i>
                                                            </button>
                                                            <button class="btn-icon btn-icon-view"
                                                                title="Edit Assignment"
                                                                onclick="openSubjectAreaModal(<?= (int)$member['id'] ?>, '<?= addslashes($member['full_name']) ?>', '<?= $sa_json ?>', <?= (int)$dept_id ?>, '<?= $assigned_subj_json ?>')"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-briefcase"></i>
                                                            </button>
                                                            <button class="btn-icon btn-icon-view"
                                                                title="View Faculty Schedule"
                                                                onclick="checkDeptCoverage(<?= (int)$dept_id ?>, <?= (int)$member['id'] ?>)"
                                                                data-bs-toggle="tooltip"
                                                                data-bs-placement="auto">
                                                                <i class="bi bi-calendar-event"></i>
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

    </div>



    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/tooltip.js"></script>
    </div>

    <!-- View Faculty Coverage Modal -->
    <div class="profile-details-modal modal fade" id="viewFacultyCoverageModal" tabindex="-1" aria-labelledby="viewFacultyLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewFacultyLabel">
                        <i class="bi bi-briefcase me-2"></i>Coverage
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label bold">Note:</label>
                        <p class="text-muted mb-0" style="font-size: 14px;">This is a read-only view
                            of the faculty member's current coverage.
                            To edit, use the "Edit Assignment" button.
                        </p>
                    </div>
                    <div class="mb-3">
                        <label class="form-label bold"><i class="bi bi-briefcase me-1"></i>Assigned Coverage:</label>
                        <ul class="list-group-horizontal dept-grid" id="viewFacultyMembers">
                            <li class="list-group-item text-muted">No subject areas assigned.</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
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
                        <label class="form-label bold"><i class="bi bi-briefcase me-1"></i>Subject Area/s and Coverage:</label>
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
                        <i class="bi bi-briefcase me-2"></i>Edit Coverage
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body dept-grid">

                    <div>
                        <!-- Current view of subject areas -->
                        <div class="dept-info-card mb-3">
                            <label class="form-label bold"><i class="bi bi-briefcase me-2"></i>Current Subject Area/s:</label>
                            <ul class="list-group-horizontal" id="editDeptMembers">
                                <li class="list-group-item text-muted">No subject areas assigned yet.</li>
                            </ul>
                        </div>
                        <!-- Current subjects under selected subject area -->
                        <div class="dept-info-card mb-3">
                            <label class="form-label bold" style="font-size: 13px;"><i class="bi bi-book me-2"></i>Current Subjects:</label>
                            <div id="currentSubjectsContainer" class="d-flex flex-wrap gap-1 mb-2">
                                <p class="text-muted mb-0">Select a subject area to view its subjects.</p>
                            </div>
                        </div>
                    </div>



                    <div>
                        <!-- Adding new subject areas -->
                        <div class="mb-3">
                            <label class="form-label bold">Add New Subject Area/s:</label>
                            <input type="text"
                                class="form-control mb-2"
                                name="dept_subject_area"
                                id="editDeptSubjectArea"
                                placeholder="Enter subject area name and press Enter">
                            <label id="newSubjectAreasLabel" class="form-label bold" style="font-size: 13px; display: none;">New Subject Areas to Add:</label>
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

                            <label id="newSubjectsLabel" class="form-label bold" style="font-size: 13px; display: none;">New Subjects to Add:</label>
                            <div id="newSubjectsContainer" class="d-flex flex-wrap gap-1"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="confirmSaveChanges()"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
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
                    <button type="button" class="medium" onclick="doSaveChanges()"><i class="bi bi-check-lg me-1"></i>Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Faculty Changes Modal -->
    <div class="modal fade" id="confirmFacultyModal" tabindex="-1" aria-hidden="true" style="z-index: 1070;">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Confirm Changes</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you are satisfied with your changes? This will update the subject areas and subjects assigned to this faculty member.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="confirmFacultySave()"><i class="bi bi-check-lg me-1"></i>Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Coverage Modal (Faculty) - Overhauled like Edit Coverage for Department -->
    <div class="profile-details-modal modal fade" id="subjectAreaModal" tabindex="-1" aria-labelledby="subjectAreaLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="subjectAreaLabel">
                        <i class="bi bi-briefcase me-2"></i>Edit Coverage for <span id="editFacultyCoverageName"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body dept-grid">
                    <input type="hidden" id="editFacultyId" value="">

                    <div>
                        <!-- Current subject areas assigned to faculty -->
                        <div class="dept-info-card mb-3">
                            <label class="form-label bold"><i class="bi bi-briefcase me-2"></i>Current Subject Area/s:</label>
                            <ul class="list-group-horizontal" id="editFacultyMembers">
                                <li class="list-group-item text-muted">No subject areas assigned yet.</li>
                            </ul>
                        </div>
                        <!-- Current subjects under selected subject area -->
                        <div class="dept-info-card mb-3">
                            <label class="form-label bold" style="font-size: 13px;"><i class="bi bi-book me-2"></i>Current Subjects:</label>
                            <div id="editFacultySubjectsContainer" class="d-flex flex-wrap gap-1 mb-2">
                                <p class="text-muted mb-0">Select a subject area to view its subjects.</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <!-- Search and available subject areas -->
                        <div class="mb-3">
                            <label class="form-label bold"><i class="bi bi-search me-1"></i>Search Subject Areas:</label>
                            <input type="text"
                                class="form-control mb-2"
                                id="facultySaSearch"
                                placeholder="Search available subject areas...">
                            <label class="form-label bold" style="font-size: 13px;">Available Subject Areas:</label>
                            <div id="availableFacultySAsContainer" class="d-flex flex-wrap gap-1">
                                <p class="text-muted small mb-0">No available subject areas.</p>
                            </div>
                        </div>

                        <!-- Search and available subjects under selected subject area -->
                        <div class="mb-3">
                            <label class="form-label bold"><i class="bi bi-search me-1"></i>Search Subjects <span id="editFacultySelectedSubjectAreaName"></span></label>
                            <input type="hidden" id="editFacultySelectedSAId" value="">
                            <input type="text"
                                class="form-control mb-2"
                                id="facultySubjectSearch"
                                placeholder="Select a subject area first"
                                disabled>
                            <label class="form-label bold" style="font-size: 13px;">Available Subjects:</label>
                            <div id="availableFacultySubjectsContainer" class="d-flex flex-wrap gap-1">
                                <p class="text-muted small mb-0">Select a subject area to view available subjects.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium" onclick="showConfirmFacultyModal()">
                        <i class="bi bi-check-lg me-1"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- No Coverage Warning Modal -->
    <div class="modal fade" id="noCoverageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">No Coverage Assigned</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        This department has no assigned coverage yet. Please add subject areas and subjects first before editing a faculty member's schedule.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="medium w-100" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const deptHasCoverage = <?= json_encode($dept_has_coverage) ?>;
        let noCoverageModal = null;

        function checkDeptCoverage(deptId, facultyId) {
            if (deptHasCoverage[deptId]) {
                window.location.href = 'faculty-head-membersched.php?faculty_id=' + facultyId + '&department_id=' + deptId;
            } else {
                if (!noCoverageModal) {
                    noCoverageModal = new bootstrap.Modal(document.getElementById('noCoverageModal'));
                }
                noCoverageModal.show();
            }
        }

        const subjectAreasData = <?= json_encode($all_subject_areas) ?>;
        const subjectAreasFlatData = <?= json_encode($all_subject_areas_flat) ?>;

        let editFacultyModal = null;
        let editFacultyId = null;
        let editFacultyDeptId = null;
        let editFacultyAssignedSubjIds = []; // faculty's assigned subject IDs (from junction_faculty_subject)

        function openSubjectAreaModal(facultyId, facultyName, currentAreaIdsJson, deptId, preSelectedSubjJson) {
            if (!editFacultyModal) {
                editFacultyModal = new bootstrap.Modal(document.getElementById('subjectAreaModal'));
            }
            editFacultyId = facultyId;
            editFacultyDeptId = deptId;
            window._facultyDeptFilter = deptId;

            document.getElementById('editFacultyId').value = facultyId;
            document.getElementById('editFacultyCoverageName').textContent = facultyName;

            // Parse assigned subject area IDs
            var assignedSaIds = [];
            try {
                assignedSaIds = JSON.parse(currentAreaIdsJson) || [];
            } catch (e) {
                assignedSaIds = [];
            }
            if (!Array.isArray(assignedSaIds)) assignedSaIds = [];
            assignedSaIds = assignedSaIds.filter(function(id) {
                return id > 0;
            });

            // Parse assigned subject IDs
            editFacultyAssignedSubjIds = [];
            try {
                editFacultyAssignedSubjIds = JSON.parse(preSelectedSubjJson) || [];
            } catch (e) {
                editFacultyAssignedSubjIds = [];
            }
            if (!Array.isArray(editFacultyAssignedSubjIds)) editFacultyAssignedSubjIds = [];
            editFacultyAssignedSubjIds = editFacultyAssignedSubjIds.filter(function(id) {
                return id > 0;
            });

            renderFacultySubjectAreas(deptId, assignedSaIds);
            renderAvailableSubjectAreas(deptId, assignedSaIds);

            // Reset containers
            document.getElementById('availableFacultySubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view available subjects.</p>';
            document.getElementById('editFacultySubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('facultySubjectSearch').disabled = true;
            document.getElementById('facultySubjectSearch').placeholder = 'Select a subject area first';
            document.getElementById('editFacultySelectedSubjectAreaName').textContent = '';
            document.getElementById('editFacultySelectedSAId').value = '';
            document.getElementById('facultySaSearch').value = '';
            document.getElementById('facultySubjectSearch').value = '';

            editFacultyModal.show();
        }

        function renderFacultySubjectAreas(deptId, assignedSaIds) {
            const container = document.getElementById('editFacultyMembers');
            const sas = subjectAreasData[deptId] || [];

            // Filter to only assigned SAs (like dept modal shows only what's in the table)
            const assignedSas = sas.filter(function(sa) {
                return assignedSaIds.indexOf(sa.id) !== -1;
            });

            if (assignedSas.length === 0) {
                container.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned yet.</li>';
                return;
            }

            container.innerHTML = '<li class="list-group-item d-flex flex-wrap gap-1">' +
                assignedSas.map(function(sa) {
                    return '<span class="dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 subject-area-item" data-sa-id="' + sa.id + '" title="Select Subject Area" data-bs-toggle="tooltip" data-bs-placement="auto">' +
                        escapeHtml(sa.name) +
                        '<button type="button" class="btn-close btn-close-white" title="Remove Subject Area" data-bs-toggle="tooltip" data-bs-placement="top"></button>' +
                        '</span>';
                }).join('') +
                '</li>';

            // Re-init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('#editFacultyMembers [data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderFacultySubjects(saId) {
            var container = document.getElementById('editFacultySubjectsContainer');
            var deptId = editFacultyDeptId;
            var sas = subjectAreasData[deptId] || [];
            var found = null;
            for (var i = 0; i < sas.length; i++) {
                if (sas[i].id == saId) {
                    found = sas[i];
                    break;
                }
            }

            if (!found) {
                container.innerHTML = '<p class="text-muted mb-0">Subject area not found.</p>';
                return;
            }

            // Show only assigned subjects under this SA (from junction_faculty_subject)
            var subjects = found.subjects || [];
            var assignedSubjects = subjects.filter(function(s) {
                return editFacultyAssignedSubjIds.indexOf(s.id) !== -1;
            });

            container.innerHTML = '';
            if (assignedSubjects.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">No assigned subjects under this subject area.</p>';
                return;
            }

            for (var j = 0; j < assignedSubjects.length; j++) {
                var sub = assignedSubjects[j];
                var span = document.createElement('span');
                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3';
                span.dataset.subjectId = sub.id;
                span.innerHTML = escapeHtml(sub.name) +
                    '<button type="button" class="btn-close btn-close-white" title="Remove Subject" data-bs-toggle="tooltip" data-bs-placement="top"></button>';
                container.appendChild(span);
            }

            // Init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderAvailableSubjectAreas(deptId, assignedSaIds) {
            var container = document.getElementById('availableFacultySAsContainer');
            var sas = subjectAreasData[deptId] || [];

            // Filter to SAs NOT assigned to faculty
            var available = sas.filter(function(sa) {
                return assignedSaIds.indexOf(sa.id) === -1;
            });

            if (available.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">All subject areas are already assigned.</p>';
                return;
            }

            container.innerHTML = '';
            for (var i = 0; i < available.length; i++) {
                var sa = available[i];
                var span = document.createElement('span');
                span.className = 'dept-subject-area bold dept-emphases align-items-center justify-content-center px-3 available-sa-item';
                span.dataset.saId = sa.id;
                span.title = 'Click to add this subject area';
                span.setAttribute('data-bs-toggle', 'tooltip');
                span.innerHTML = escapeHtml(sa.name) +
                    '<button type="button" class="p-0 ms-1 d-inline-flex flex-shrink-0 align-items-center text-white border-0 bg-transparent" title="Add Subject Area">' +
                    '<i class="bi bi-plus-circle"></i></button>';
                container.appendChild(span);
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function renderAvailableSubjects(saId) {
            var container = document.getElementById('availableFacultySubjectsContainer');
            var deptId = editFacultyDeptId;
            var sas = subjectAreasData[deptId] || [];
            var found = null;
            for (var i = 0; i < sas.length; i++) {
                if (sas[i].id == saId) {
                    found = sas[i];
                    break;
                }
            }

            if (!found) {
                container.innerHTML = '<p class="text-muted small mb-0">Subject area not found.</p>';
                return;
            }

            var subjects = found.subjects || [];
            var available = subjects.filter(function(s) {
                return editFacultyAssignedSubjIds.indexOf(s.id) === -1;
            });

            if (available.length === 0) {
                container.innerHTML = '<p class="text-muted small mb-0">All subjects are already assigned under this area.</p>';
                return;
            }

            container.innerHTML = '';
            for (var j = 0; j < available.length; j++) {
                var sub = available[j];
                var span = document.createElement('span');
                span.className = 'subarea-subject bold dept-emphases align-items-center justify-content-center px-3 available-subject-item';
                span.dataset.subjectId = sub.id;
                span.title = 'Click to add this subject';
                span.setAttribute('data-bs-toggle', 'tooltip');
                span.innerHTML = escapeHtml(sub.name) +
                    '<button type="button" class="btn btn-sm p-0 ms-1 d-inline-flex align-items-center text-white border-0 bg-transparent" title="Add Subject">' +
                    '<i class="bi bi-plus-circle"></i></button>';
                container.appendChild(span);
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                container.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        function showConfirmFacultyModal() {
            var modal = new bootstrap.Modal(document.getElementById('confirmFacultyModal'));
            modal.show();
        }

        function confirmFacultySave() {
            bootstrap.Modal.getInstance(document.getElementById('confirmFacultyModal'))?.hide();
            saveFacultyCoverage();
        }

        async function saveFacultyCoverage() {
            var facultyId = editFacultyId;
            var deptId = editFacultyDeptId;
            if (!facultyId || !deptId) return;

            // Get keep SA IDs (non-disabled assigned SAs)
            var keepSaSpans = document.querySelectorAll('#editFacultyMembers .subject-area-item:not(.disabled)');
            var keepSaIds = Array.from(keepSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get removed SA IDs (disabled assigned SAs)
            var removedSaSpans = document.querySelectorAll('#editFacultyMembers .subject-area-item.disabled');
            var removeSaIds = Array.from(removedSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get removed subject IDs
            var removedSubjSpans = document.querySelectorAll('#editFacultySubjectsContainer .subarea-subject.disabled');
            var removeSubjIds = Array.from(removedSubjSpans).map(function(el) {
                return parseInt(el.dataset.subjectId);
            }).filter(function(id) {
                return !isNaN(id);
            });

            // Get add SA IDs (available SAs selected to add)
            var addSaSpans = document.querySelectorAll('#availableFacultySAsContainer .available-sa-item.selected');
            var addSaIds = Array.from(addSaSpans).map(function(el) {
                return parseInt(el.dataset.saId);
            });

            // Get add subject IDs (available subjects selected to add)
            var addSubjSpans = document.querySelectorAll('#availableFacultySubjectsContainer .available-subject-item.selected');
            var addSubjIds = Array.from(addSubjSpans).map(function(el) {
                return parseInt(el.dataset.subjectId);
            });

            var body = new URLSearchParams({
                action: 'save_faculty_coverage',
                faculty_id: facultyId,
                department_id: deptId,
                keep_sa_ids: JSON.stringify(keepSaIds),
                remove_sa_ids: JSON.stringify(removeSaIds),
                remove_subject_ids: JSON.stringify(removeSubjIds),
                add_sa_ids: JSON.stringify(addSaIds),
                add_subject_ids: JSON.stringify(addSubjIds)
            });

            try {
                var res = await fetch('../../php/handlers/faculty-head-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: body
                });
                var data = await res.json();
                if (data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('confirmFacultyModal'))?.hide();
                    bootstrap.Modal.getInstance(document.getElementById('subjectAreaModal'))?.hide();
                    location.reload();
                } else {
                    alert(data.message || 'Failed to save changes.');
                }
            } catch (err) {
                alert('An error occurred while saving.');
            }
        }

        // ── Dynamic Subject Area Modals ─────────────────────────────

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
                        '<label class="bold" style="font-size: 14px;"><i class="bi bi-book me-2"></i>Subjects:</label>' +
                        subjHtml +
                        '</div></li>';
                }).join('');
            }

            new bootstrap.Modal(modal).show();
        }

        function viewFacultyCoverage(facultyId, facultyName, deptId, saIdsJson, subjectIdsJson) {
            var modal = document.getElementById('viewFacultyCoverageModal');
            document.getElementById('viewFacultyLabel').innerHTML =
                '<i class="bi bi-briefcase me-2"></i>Coverage for ' + escapeHtml(facultyName);

            var assignedSaIds = [];
            try {
                assignedSaIds = JSON.parse(saIdsJson) || [];
            } catch (e) {
                assignedSaIds = [];
            }
            if (!Array.isArray(assignedSaIds)) assignedSaIds = [];

            var assignedSubjIds = [];
            try {
                assignedSubjIds = JSON.parse(subjectIdsJson) || [];
            } catch (e) {
                assignedSubjIds = [];
            }
            if (!Array.isArray(assignedSubjIds)) assignedSubjIds = [];

            var list = document.getElementById('viewFacultyMembers');
            var filtered = subjectAreasFlatData.filter(function(sa) {
                return sa.department_id == deptId && assignedSaIds.indexOf(sa.id) !== -1;
            });

            if (filtered.length === 0) {
                list.innerHTML = '<li class="list-group-item text-muted">No subject areas assigned to this faculty member.</li>';
            } else {
                list.innerHTML = filtered.map(function(sa) {
                    var subjects = sa.subjects || [];
                    var assignedSubjects = subjects.filter(function(s) {
                        return assignedSubjIds.indexOf(s.id) !== -1;
                    });
                    var subjHtml = '';
                    if (assignedSubjects.length === 0) {
                        subjHtml = '<p class="text-muted mb-0" style="font-size: 14px;">Currently no assigned subjects under this subject area.</p>';
                    } else {
                        subjHtml = '<div class="d-flex flex-wrap gap-1">' +
                            assignedSubjects.map(function(s) {
                                return '<span class="subarea-subject bold dept-emphases align-self-start">' + escapeHtml(s.name) + '</span>';
                            }).join('') +
                            '</div>';
                    }
                    return '<li class="list-group-item">' +
                        '<div class="dept-info-card d-flex flex-column">' +
                        '<span class="dept-subject-area bold dept-emphases align-self-start">' + escapeHtml(sa.name) + '</span>' +
                        '<label class="bold" style="font-size: 14px;"><i class="bi bi-book me-2"></i>Subjects:</label>' +
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

            // Reset new chips and labels
            document.getElementById('newSubjectAreasContainer').innerHTML = '';
            document.getElementById('newSubjectAreasLabel').style.display = 'none';
            document.getElementById('newSubjectsContainer').innerHTML = '';
            document.getElementById('newSubjectsLabel').style.display = 'none';
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('editDeptSubject').disabled = true;
            document.getElementById('editDeptSubject').placeholder = 'Select a subject area first';
            document.getElementById('editSelectedSubjectAreaName').textContent = 'insertSubjectArea';
            document.getElementById('editSelectedSubjectAreaId').value = '';

            // Re-init tooltips
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                document.querySelectorAll('#editDeptMembers [data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }

        // Handle inputs to add chips (Department + Faculty)
        document.addEventListener('DOMContentLoaded', function() {
            // Department modal inputs
            const saInput = document.getElementById('editDeptSubjectArea');
            const subjectInput = document.getElementById('editDeptSubject');

            if (saInput) {
                saInput.addEventListener('change', function() {
                    addNewSubjectAreaChip(this);
                });
                saInput.addEventListener('blur', function() {
                    if (this.value.trim()) addNewSubjectAreaChip(this);
                });
            }
            if (subjectInput) {
                subjectInput.addEventListener('change', function() {
                    addNewSubjectChip(this);
                });
                subjectInput.addEventListener('blur', function() {
                    if (this.value.trim()) addNewSubjectChip(this);
                });
            }

            // Faculty modal search
            const facultySaSearch = document.getElementById('facultySaSearch');
            if (facultySaSearch) {
                facultySaSearch.addEventListener('input', function() {
                    var filter = this.value.toLowerCase();
                    var container = document.getElementById('availableFacultySAsContainer');
                    var items = container.querySelectorAll('.available-sa-item');
                    var anyVisible = false;
                    for (var i = 0; i < items.length; i++) {
                        var show = items[i].textContent.toLowerCase().includes(filter);
                        items[i].style.display = show ? '' : 'none';
                        if (show) anyVisible = true;
                    }
                    var emptyMsg = container.querySelector('.text-muted');
                    if (!anyVisible && !emptyMsg) {
                        var msg = document.createElement('p');
                        msg.className = 'text-muted small mb-0';
                        msg.textContent = 'No matching subject areas.';
                        container.appendChild(msg);
                    } else if (anyVisible && emptyMsg) {
                        emptyMsg.remove();
                    }
                });
            }

            const facultySubSearch = document.getElementById('facultySubjectSearch');
            if (facultySubSearch) {
                facultySubSearch.addEventListener('input', function() {
                    var filter = this.value.toLowerCase();
                    var container = document.getElementById('availableFacultySubjectsContainer');
                    var items = container.querySelectorAll('.available-subject-item');
                    var anyVisible = false;
                    for (var i = 0; i < items.length; i++) {
                        var show = items[i].textContent.toLowerCase().includes(filter);
                        items[i].style.display = show ? '' : 'none';
                        if (show) anyVisible = true;
                    }
                    var emptyMsg = container.querySelector('.text-muted');
                    if (!anyVisible && !emptyMsg) {
                        var msg = document.createElement('p');
                        msg.className = 'text-muted small mb-0';
                        msg.textContent = 'No matching subjects.';
                        container.appendChild(msg);
                    } else if (anyVisible && emptyMsg) {
                        emptyMsg.remove();
                    }
                });
            }
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
            document.getElementById('newSubjectAreasLabel').style.display = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
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
            document.getElementById('newSubjectsLabel').style.display = '';

            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                chip.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                });
            }
        }



        // Reset edit modal when closed
        document.getElementById('editSubjectAreaModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('newSubjectAreasContainer').innerHTML = '';
            document.getElementById('newSubjectAreasLabel').style.display = 'none';
            document.getElementById('newSubjectsContainer').innerHTML = '';
            document.getElementById('newSubjectsLabel').style.display = 'none';
            document.getElementById('currentSubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
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

        // Reset faculty modal when closed
        document.getElementById('subjectAreaModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('editFacultySubjectsContainer').innerHTML =
                '<p class="text-muted mb-0">Select a subject area to view its subjects.</p>';
            document.getElementById('availableFacultySubjectsContainer').innerHTML =
                '<p class="text-muted small mb-0">Select a subject area to view available subjects.</p>';
            var subSearch = document.getElementById('facultySubjectSearch');
            if (subSearch) {
                subSearch.disabled = true;
                subSearch.placeholder = 'Select a subject area first';
                subSearch.value = '';
            }
            document.getElementById('editFacultySelectedSubjectAreaName').textContent = '';
            document.getElementById('editFacultySelectedSAId').value = '';
            document.getElementById('facultySaSearch').value = '';
            document.querySelectorAll('#editFacultyMembers .subject-area-item').forEach(function(el) {
                el.style.boxShadow = '';
                el.style.border = '';
            });
            editFacultyId = null;
            editFacultyDeptId = null;
            editFacultyAssignedSubjIds = [];
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
                    if (!bootstrap.Tooltip.getInstance(plusBtn)) new bootstrap.Tooltip(plusBtn);
                }
            }
        }

        function restoreSpan(span) {
            if (!span.classList.contains('disabled')) return;
            span.classList.remove('disabled');
            // Re-init tooltip on the span and its btn-close (only if not already active)
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                if (span.hasAttribute('data-bs-toggle') && !bootstrap.Tooltip.getInstance(span)) new bootstrap.Tooltip(span);
                const close = span.querySelector('.btn-close');
                if (close && !bootstrap.Tooltip.getInstance(close)) new bootstrap.Tooltip(close);
            }
            const close = span.querySelector('.btn-close');
            if (close) close.style.display = '';
            const plus = span.querySelector('.btn.btn-sm');
            if (plus) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                    bootstrap.Tooltip.getInstance(plus)?.dispose();
                }
                plus.remove();
            }
        }

        // Delegate btn-close, restore, and subject area selection
        document.addEventListener('click', function(e) {
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
                    if (!document.getElementById('newSubjectAreasContainer').hasChildNodes()) {
                        document.getElementById('newSubjectAreasLabel').style.display = 'none';
                    }
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
                    if (!document.getElementById('newSubjectsContainer').hasChildNodes()) {
                        document.getElementById('newSubjectsLabel').style.display = 'none';
                    }
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

                        // Clear new subjects container and hide label
                        document.getElementById('newSubjectsContainer').innerHTML = '';
                        document.getElementById('newSubjectsLabel').style.display = 'none';

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
                                currentSubjectsContainer.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
                                    if (!bootstrap.Tooltip.getInstance(el)) new bootstrap.Tooltip(el);
                                });
                            }
                        } else {
                            currentSubjectsContainer.innerHTML = '<p class="text-muted small mb-0">No subjects currently under this subject area.</p>';
                        }
                        break;
                    }
                }
                return;
            }

            // ── Faculty modal handlers ──────────────────────────────────

            // btn-close inside editFacultyMembers → disable SA
            var facultySaBtnClose = e.target.closest('#editFacultyMembers .btn-close');
            if (facultySaBtnClose) {
                e.preventDefault();
                var saSpan = facultySaBtnClose.closest('span');
                if (saSpan) disableSpan(saSpan);
                return;
            }

            // bi-plus inside editFacultyMembers → restore SA
            var facultySaPlus = e.target.closest('#editFacultyMembers .bi-plus');
            if (facultySaPlus) {
                var restoredSaSpan = facultySaPlus.closest('span');
                if (restoredSaSpan) restoreSpan(restoredSaSpan);
                return;
            }

            // btn-close inside editFacultySubjectsContainer → disable subject
            var facultySubBtnClose = e.target.closest('#editFacultySubjectsContainer .btn-close');
            if (facultySubBtnClose) {
                e.preventDefault();
                var subSpan = facultySubBtnClose.closest('span');
                if (subSpan) disableSpan(subSpan);
                return;
            }

            // bi-plus inside editFacultySubjectsContainer → restore subject
            var facultySubPlus = e.target.closest('#editFacultySubjectsContainer .bi-plus');
            if (facultySubPlus) {
                var restoredSubSpan = facultySubPlus.closest('span');
                if (restoredSubSpan) restoreSpan(restoredSubSpan);
                return;
            }

            // Available SA item click → toggle add (selected state)
            var availSaItem = e.target.closest('#availableFacultySAsContainer .available-sa-item');
            if (availSaItem) {
                if (availSaItem.classList.contains('selected')) {
                    availSaItem.classList.remove('selected');
                    availSaItem.style.boxShadow = '';
                    availSaItem.style.border = '';
                } else {
                    availSaItem.classList.add('selected');
                    availSaItem.style.boxShadow = '0 0 0 2px #2ecc71';
                    availSaItem.style.border = '2px solid #2ecc71';
                }
                return;
            }

            // Available subject item click → toggle add (selected state)
            var availSubItem = e.target.closest('#availableFacultySubjectsContainer .available-subject-item');
            if (availSubItem) {
                if (availSubItem.classList.contains('selected')) {
                    availSubItem.classList.remove('selected');
                    availSubItem.style.boxShadow = '';
                    availSubItem.style.border = '';
                } else {
                    availSubItem.classList.add('selected');
                    availSubItem.style.boxShadow = '0 0 0 2px #2ecc71';
                    availSubItem.style.border = '2px solid #2ecc71';
                }
                return;
            }

            // Subject area selection in editFacultyMembers → show its subjects + available
            var facultySaItem = e.target.closest('#editFacultyMembers .subject-area-item');
            if (facultySaItem && !facultySaItem.classList.contains('disabled')) {
                var facultySaId = facultySaItem.dataset.saId;
                var deptId = editFacultyDeptId;
                var sas = subjectAreasData[deptId] || [];
                var found = null;
                for (var fi = 0; fi < sas.length; fi++) {
                    if (sas[fi].id == facultySaId) {
                        found = sas[fi];
                        break;
                    }
                }
                if (found) {
                    // Highlight selected
                    document.querySelectorAll('#editFacultyMembers .subject-area-item').forEach(function(el) {
                        el.style.boxShadow = '';
                        el.style.border = '';
                    });
                    facultySaItem.style.boxShadow = '0 0 0 2px #ffc107';
                    facultySaItem.style.border = '2px solid #ffc107';

                    // Update available subjects section
                    document.getElementById('editFacultySelectedSubjectAreaName').textContent = ' for ' + escapeHtml(found.name);
                    document.getElementById('editFacultySelectedSAId').value = facultySaId;

                    // Enable subject search
                    var subSearch = document.getElementById('facultySubjectSearch');
                    subSearch.disabled = false;
                    subSearch.placeholder = 'Search available subjects for ' + found.name;
                    subSearch.value = '';
                    subSearch.focus();

                    // Show assigned subjects + available subjects
                    renderFacultySubjects(facultySaId);
                    renderAvailableSubjects(facultySaId);
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

    <style>
        .timetable-btn.active-filter {
            background: var(--accent-yellow);
            color: var(--accent-black);
        }
        .timetable-btn.active-filter i {
            font-size: 24px;
        }
    </style>

    <script>
        var filterData = <?= json_encode([
            'sa_names' => array_values($filter_sa_names),
            'subject_names' => array_values($filter_subject_names),
            'subject_to_sa' => $filter_subject_to_sa,
        ]) ?>;

        var activeCoverage = '';
        var activeSubject = '';

        function applyFilters() {
            var q = document.getElementById('deptSearch').value.toLowerCase().trim();
            document.querySelectorAll('#deptGrid > .section-container').forEach(function(card) {
                var deptName = card.querySelector('h2.bold').textContent.toLowerCase();
                var hasFacultyMatch = false;
                card.querySelectorAll('.faculty-name').forEach(function(fn) {
                    if (fn.textContent.toLowerCase().includes(q)) hasFacultyMatch = true;
                });
                var show = deptName.includes(q) || hasFacultyMatch;

                if (show && activeCoverage) {
                    var saSpans = card.querySelectorAll('.dept-subject-area');
                    var hasSa = Array.from(saSpans).some(function(sp) {
                        return sp.textContent.trim().toLowerCase() === activeCoverage.toLowerCase();
                    });
                    show = hasSa;
                }

                if (show && activeSubject) {
                    var relatedSas = filterData.subject_to_sa[activeSubject] || [];
                    var saSpans = card.querySelectorAll('.dept-subject-area');
                    var hasSubject = Array.from(saSpans).some(function(sp) {
                        return relatedSas.some(function(rsa) {
                            return sp.textContent.trim().toLowerCase() === rsa.toLowerCase();
                        });
                    });
                    show = hasSubject;
                }

                card.style.display = show ? '' : 'none';
            });

            document.getElementById('panelCoverageFilter').classList.remove('show');
            document.getElementById('panelSubjectFilter').classList.remove('show');
        }

        document.getElementById('deptSearch').addEventListener('input', applyFilters);

        // Build coverage filter panel
        (function() {
            var menu = document.getElementById('coverageFilterMenu');
            filterData.sa_names.forEach(function(name) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'filter-option d-block px-2 py-1';
                a.href = '#';
                a.textContent = name;
                a.dataset.value = name;
                li.appendChild(a);
                menu.appendChild(li);
            });
            menu.addEventListener('click', function(e) {
                var a = e.target.closest('.filter-option');
                if (!a) return;
                menu.querySelectorAll('.filter-option').forEach(function(el) { el.classList.remove('active'); });
                a.classList.add('active');
                activeCoverage = a.dataset.value;
                activeSubject = '';
                document.querySelectorAll('#subjectFilterMenu .filter-option').forEach(function(el) { el.classList.remove('active'); });
                var allSubj = document.querySelector('#subjectFilterMenu .filter-option[data-value=""]');
                if (allSubj) allSubj.classList.add('active');
                document.querySelector('[data-panel="panelCoverageFilter"]').classList.toggle('active-filter', !!activeCoverage);
                document.querySelector('[data-panel="panelSubjectFilter"]').classList.remove('active-filter');
                applyFilters();
            });
        })();

        // Build subject filter panel
        (function() {
            var menu = document.getElementById('subjectFilterMenu');
            filterData.subject_names.forEach(function(name) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'filter-option d-block px-2 py-1';
                a.href = '#';
                a.textContent = name;
                a.dataset.value = name;
                li.appendChild(a);
                menu.appendChild(li);
            });
            menu.addEventListener('click', function(e) {
                var a = e.target.closest('.filter-option');
                if (!a) return;
                menu.querySelectorAll('.filter-option').forEach(function(el) { el.classList.remove('active'); });
                a.classList.add('active');
                activeSubject = a.dataset.value;
                activeCoverage = '';
                document.querySelectorAll('#coverageFilterMenu .filter-option').forEach(function(el) { el.classList.remove('active'); });
                var allCov = document.querySelector('#coverageFilterMenu .filter-option[data-value=""]');
                if (allCov) allCov.classList.add('active');
                document.querySelector('[data-panel="panelSubjectFilter"]').classList.toggle('active-filter', !!activeSubject);
                document.querySelector('[data-panel="panelCoverageFilter"]').classList.remove('active-filter');
                applyFilters();
            });
        })();

        // Timetable-panel toggle (hover/focus open, mouseleave close with delay)
        (function() {
            var panels = ['panelInfoSteps', 'panelCoverageFilter', 'panelSubjectFilter'];
            var timers = {};

            panels.forEach(function(id) {
                var btn = document.querySelector('[data-panel="' + id + '"]');
                var panel = document.getElementById(id);
                if (!btn || !panel) return;

                timers[id] = null;

                function open() {
                    if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
                    panel.classList.add('show');
                }

                function close() {
                    if (timers[id]) clearTimeout(timers[id]);
                    timers[id] = setTimeout(function() { panel.classList.remove('show'); }, 150);
                }

                btn.addEventListener('mouseenter', open);
                btn.addEventListener('focus', open);
                panel.addEventListener('mouseenter', open);
                panel.addEventListener('mouseleave', close);
                btn.addEventListener('mouseleave', close);
            });
        })();
    </script>
    <script src="../../script/faculty-tutorial.js"></script>
</body>

</html>