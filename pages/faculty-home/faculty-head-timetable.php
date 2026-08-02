<?php
require_once __DIR__ . "/../../src/Session/session_guard.php";
check_faculty();
require_once __DIR__ . "/../../src/Config/db_connect.php";

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

// Determine which faculty members have assigned subject areas in each department
$faculty_assignment_map = [];
foreach ($dept_data as $data) {
    foreach ($data['members'] as $member) {
        $fid = (int)$member['id'];
        $faculty_assignment_map[$fid] = !empty($member['subject_area_ids']);
    }
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

    <link rel="icon" type="image/png" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/base/global.css">
    <link rel="stylesheet" href="../../css/base/containers.css">
    <link rel="stylesheet" href="../../css/base/tooltip.css">
    <link rel="stylesheet" href="../../css/base/modals.css">
    <link rel="stylesheet" href="../../css/faculty/timetable.css">
    <link rel="stylesheet" href="../../css/faculty/common.css">
    <link rel="stylesheet" href="../../css/faculty/settings.css">
    <link rel="stylesheet" href="../../css/faculty/head-timetable.css">

    <title>Department Schedules – LumineSense</title>
</head>

<body class="contrast-bg">
    <div class="parent-container">

        <?php include __DIR__ . "/../../src/Includes/faculty-topbar.php"; ?>

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

        <?php include __DIR__ . "/../../src/Includes/faculty-sidebar.php"; ?>

    </div>



    <script src="../../js/lib/animations.js"></script>
    <script src="../../js/lib/toggles.js"></script>
    <script src="../../js/lib/tooltip.js"></script>
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

    <!-- No Subject Area Assigned Warning Modal -->
    <div class="modal fade" id="noAssignmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">No Subject Area Assigned</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#e67e22;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        This faculty member has no subject area assigned under this department. Please assign subject areas first before viewing their schedule.
                    </p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="medium w-100" data-bs-dismiss="modal">Understood</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/faculty/faculty-head-timetable.js"></script>

    <link rel="stylesheet" href="../../css/pages/faculty-head-timetable.css">
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>
