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



// Department this head manages

$department = null;

$stmt = $conn->prepare("

    SELECT id, name, description

    FROM departments

    WHERE head_faculty_id = ? AND status = 'active'

    LIMIT 1

");

$stmt->bind_param('i', $faculty_id);

$stmt->execute();

$department = $stmt->get_result()->fetch_assoc();

$stmt->close();



$dept_name = $department ? $department['name'] : '—';

$dept_id   = $department ? (int)$department['id'] : 0;



// Faculty members in the department

$dept_members = [];

if ($dept_id) {

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

}



// All subject areas for the edit modal

$subject_areas = [];

$r = $conn->query("

    SELECT sa.id, sa.name, sub.name AS subject_name

    FROM subject_area sa

    JOIN subjects sub ON sub.id = sa.subject_id

    ORDER BY sub.name, sa.name

");

if ($r) {

    while ($row = $r->fetch_assoc()) $subject_areas[] = $row;

}



$conn->close();

?>

<!DOCTYPE html>

<html lang="en">



<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">



    <!--External links-->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>



    <!--Relative links-->

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



        <div class="child-container mb-3">



            <div class="main-container faculty-timetable w-auto mb-3">

                <div class="section-container head-timetable p-2">

                    <h2 class="bold p-1"><i class="bi bi-diagram-3 me-1"></i>Department Overview</h2>

                    <p class="m-0"><i class="bi bi-info-circle me-1"></i>Name: <span class="bold department-emphases"><?= htmlspecialchars($dept_name) ?></span></p>

                    <p class="m-0"><i class="bi bi-person-badge me-1"></i>Faculty Head: <span class="bold department-emphases"><?= $faculty_name ?></span></p>



                    <h2 class="bold mt-3 p-1"><i class="bi bi-diagram-3-fill me-1"></i>Faculty Members</h2>



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

                        <?php endforeach; endif; ?>

                    </div>



                </div>

            </div>



        </div>



        <?php include '../../php/includes/faculty-sidebar.php'; ?>



        <script src="../../script/animations.js"></script>

        <script src="../../script/toggles.js"></script>

        <script src="../../script/tooltip.js"></script>

    </div>



    <!-- Edit Subject Area Modal -->

    <div class="profile-details-modal modal fade" id="subjectAreaModal" tabindex="-1" aria-labelledby="subjectAreaLabel" aria-hidden="true">

        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">

            <div class="modal-content">

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

                                <?= htmlspecialchars(trim($sa['name'] ?: $sa['subject_name'])) ?>

                                (<?= htmlspecialchars(trim($sa['subject_name'])) ?>)

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

                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },

                body

            });

            const data = await res.json();



            if (data.success) {

                const displayLabel = subjectAreaId === '0'

                    ? 'No subject area assigned'

                    : labelText.replace(/\s*\([^)]*\)\s*$/, '').trim();

                document.getElementById('area-label-' + facultyId).innerHTML =

                    '<i class="bi bi-briefcase me-1"></i>' + displayLabel;

                subjectAreaModal.hide();

            } else {

                alert(data.message || 'Could not save subject area.');

            }

        }

    </script>



</body>



</html>

