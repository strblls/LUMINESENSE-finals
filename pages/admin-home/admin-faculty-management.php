<?php
$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/session_guard.php';
check_admin();
require_once $phpRoot . '/db_connect.php';

$admin_name  = htmlspecialchars($_SESSION['admin_name']);
$name_parts  = explode(' ', $admin_name);
$initials    = strtoupper(substr($name_parts[0], 0, 1) . substr(end($name_parts), 0, 1));

$admin_email = '';
$stmt = $conn->prepare('SELECT email FROM admins WHERE id = ?');
$stmt->bind_param('i', $_SESSION['admin_id']);
$stmt->execute();
$stmt->bind_result($admin_email);
$stmt->fetch();
$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">


    <!-- ═══ SHARED SIDEBAR & PROFILE STYLES ═══ -->
    <style>
        /* ── Sidebar nav buttons ── */
        .nav-btn {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.15s;
        }

        .nav-btn i,
        .nav-btn svg {
            font-size: 22px;
        }

        .nav-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.06);
        }

        /* ── Sidebar offcanvas shell ── */
        #sidebarOffcanvas {
            width: 100px !important;
            background-color: var(--primary-color);
        }

        #sidebarOffcanvas .offcanvas-header {
            justify-content: center;
            padding: 1rem 0.5rem;
        }

        #sidebarOffcanvas .logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            cursor: pointer;
        }

        #sidebarOffcanvas .offcanvas-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding-top: 0.5rem;
        }

        #sidebarOffcanvas .offcanvas-footer {
            display: flex;
            justify-content: center;
            padding: 1rem;
        }

        #sidebarOffcanvas .offcanvas-footer img {
            width: 4rem;
        }

        /* ── Profile offcanvas shell ── */
        #profileOffcanvas {
            width: 240px !important;
            background-color: var(--primary-color);
        }

        #profileOffcanvas .avatar-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #d9d6d6;
            color: var(--secondary-color-1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ── Profile offcanvas buttons ── */
        .profile-btn {
            width: 100%;
            padding: 8px;
            margin: 3px 0;
            border-radius: 8px;
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-family: var(--font-primary);
            transition: background-color 0.2s, transform 0.15s;
        }

        .profile-btn:hover {
            background-color: var(--secondary-color-4);
            transform: scale(1.02);
        }

        /* Page-specific override: keep topbar inside the single scrolling container */
        .topbar {
            position: relative !important;
            top: auto !important;
        }
    </style>
</head>

<body class="contrast-bg">


    <!-- Topbar -->
    <div class="topbar d-flex"
        style="background: linear-gradient(0deg, rgba(255,255,255,0) 9%, rgba(47,0,79,0.76) 40%, rgba(47,0,79,0.95) 70%, rgba(47,0,79,1) 100%);">
        <button type="button" id="sidebarTrigger" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
            <i class="bi bi-list"></i>
        </button>
        <div class="col d-flex flex-column px-3">
            <h1 class="bold">Faculty Management</h1>
        </div>
        <div class="d-flex align-items-center justify-content-center gap-3 mx-2">
            <div class="search-container">
                <input type="text" class="form-control search-input" placeholder="Search">
                <i class="bi bi-search search-icon"></i>
            </div>
            <h4>Admin 10</h4>
            <div class="avatar-icon d-flex align-items-center justify-content-center" id="sidebarTrigger2"
                data-bs-toggle="offcanvas" data-bs-target="#profileOffcanvas">
                <h3 class="bold">AN</h3>
            </div>
        </div>
    </div>

    <div class="page-shell main-container p-4 faculty-card">

        <!-- Profile row -->
        <div class="d-flex align-items-center gap-4 mb-4 pb-4 divider-bottom">
            <div class="avatar-icon avatar-large d-flex align-items-center justify-content-center flex-shrink-0"
                id="profileAvatar">
                <h3 class="bold mb-0" id="profileAvatarText">JD</h3>
            </div>
            <div>
                <h3 class="bold mb-1 profile-name" id="profileName">John Doe</h3>
                <p class="mb-0 profile-label">
                    Department: <span class="semibold" id="profileDept">SHS</span>
                </p>
            </div>
        </div>

        <div class="d-flex gap-2 flex-wrap mb-4">
            <button class="light info-action-btn"
                style="width:auto; padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;" data-bs-toggle="modal"
                data-bs-target="#editFacultyModal">
                <i class="bi bi-pencil me-1"></i>Edit Profile
            </button>
            <button class="light info-action-btn"
                style="width:auto; padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;">
                <i class="bi bi-door-open me-1"></i>Manage Rooms
            </button>
            <button class="light info-action-btn"
                style="width:auto; padding: 8px 16px; font-size: 0.85rem; border-radius: 8px;">
                <i class="bi bi-activity me-1"></i>View Activity
            </button>
        </div>

        <!-- Assigned Rooms + Access Control row -->
        <div class="d-flex gap-3 mb-3 flex-wrap">

            <!-- Assigned Rooms -->
            <div class="section-container p-3 flex-fill faculty-info-card">
                <h6 class="bold mb-3">Assigned Rooms</h6>
                <div class="mb-2">
                    <span class="badge faculty-badge me-2">SEL 08</span>
                    <span class="badge faculty-badge">SEL 11</span>
                </div>
                <p class="mb-0 faculty-status-text">
                    Status: <span class="semibold status-highlight">SEL 08 Occupied</span>
                </p>
            </div>

            <!-- Access Control -->
            <div class="section-container p-3 flex-fill faculty-info-card">
                <h6 class="bold mb-3">Access Control</h6>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-lightbulb-fill access-icon"></i>
                        <span class="access-label">Lighting Control</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" checked>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-hand-index-fill access-icon"></i>
                        <span class="access-label">Gesture Control</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" checked>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <i class="bi bi-journal-text access-icon"></i>
                        <span class="access-label">Request Access</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" checked>
                    </div>
                </div>
            </div>

        </div>

        <!-- Schedule -->
        <div class="section-container p-3 faculty-info-card">
            <h6 class="bold mb-3">Schedule</h6>
            <div class="d-flex align-items-center justify-content-between py-2 schedule-row">
                <div>
                    <p class="bold mb-0 schedule-time">8:00 - 9:00 AM</p>
                    <small class="text-muted">Earth and Life Science II</small>
                </div>
                <span class="badge schedule-badge">SEL 08</span>
            </div>
            <div class="d-flex align-items-center justify-content-between py-2 schedule-row">
                <div>
                    <p class="bold mb-0 schedule-time">9:00 - 12:00 PM</p>
                    <small class="text-muted">Earth and Life Science II</small>
                </div>
                <span class="badge schedule-badge">SEL 11</span>
            </div>
        </div>

        <!-- Edit Faculty Modal -->
        <div class="profile-details-modal modal fade" id="editFacultyModal" tabindex="-1"
            aria-labelledby="editFacultyModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="editFacultyModalLabel">
                            <i class="bi bi-pencil me-2"></i>Edit Faculty
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="editName" class="modal-label">Full Name</label>
                            <input type="text" class="form-control mt-1" id="editName" value="John Doe">
                        </div>
                        <div class="mb-3">
                            <label for="editDept" class="modal-label">Department</label>
                            <input type="text" class="form-control mt-1" id="editDept" value="SHS">
                        </div>
                        <div class="mb-3">
                            <label for="editEmail" class="modal-label">Email Address</label>
                            <input type="email" class="form-control mt-1" id="editEmail" value="john.doe@school.edu">
                        </div>
                        <div class="mb-3">
                            <label for="editStatusModal" class="modal-label">Status</label>
                            <select class="form-select mt-1" id="editStatusModal">
                                <option value="Validated" selected>Validated</option>
                                <option value="Pending">Pending</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-4">
                            <button class="light action-btn" data-bs-dismiss="modal">Cancel</button>
                            <button class="action-btn" onclick="saveEdit()">Save Changes</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /page-shell -->

    <!-- Status select inline style (minimal, blends with topbar) -->
    <style>
        .faculty-status-select {
            appearance: none;
            -webkit-appearance: none;
            background: rgba(255, 255, 255, 0.15);
            border: 1.5px solid rgba(255, 255, 255, 0.4);
            border-radius: 20px;
            color: #fff;
            font-family: var(--font-primary, 'Poppins', sans-serif);
            font-size: 0.82rem;
            font-weight: 600;
            padding: 4px 28px 4px 12px;
            cursor: pointer;
            outline: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='white' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            transition: background 0.2s, border-color 0.2s;
        }

        .faculty-status-select:hover {
            background-color: rgba(255, 255, 255, 0.22);
            border-color: #fff;
        }

        .faculty-status-select option {
            background: #2f004f;
            color: #fff;
        }

        /* Toast */
        .toast-wrap {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
        }

        .toast-msg {
            background: var(--secondary-color-1, #2f004f);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.85rem;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
            display: none;
        }

        .toast-msg.show {
            display: block;
            animation: fadeInUp 0.3s ease, fadeOut 0.4s ease 2.2s forwards;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(12px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
            }
        }
    </style>

    <!-- Toast -->
    <div class="toast-wrap">
        <div class="toast-msg" id="toastMsg"></div>
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

        function updateStatus(val) {
            showToast('Status updated to: ' + val);
        }

        function saveEdit() {
            const name = document.getElementById('editName').value.trim();
            const dept = document.getElementById('editDept').value.trim();
            const status = document.getElementById('editStatusModal').value;

            if (!name) { alert('Please enter a name.'); return; }

            // Update profile card
            document.getElementById('profileName').textContent = name;
            document.getElementById('profileDept').textContent = dept;
            const initials = name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
            document.getElementById('profileAvatarText').textContent = initials;

            // Sync status dropdown if present
            const statusSelect = document.getElementById('facultyStatusSelect');
            if (statusSelect) {
                statusSelect.value = status;
            }

            bootstrap.Modal.getInstance(document.getElementById('editFacultyModal')).hide();
            showToast('Faculty details saved successfully.');
        }
    </script>

    <script src="../../script/animations.js" onerror="void(0)"></script>
    <script src="../../script/toggles.js" onerror="void(0)"></script>



    <!-- ═══ SIDEBAR OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas" aria-labelledby="sidebarOffcanvasLabel">
        <div class="offcanvas-header justify-content-center">
            <img src="../../images/logo.png" class="logo clickable-logo" alt="Logo">
        </div>
        <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
            <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')"><i
                    class="bi bi-house-door"></i></button>
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')"><i
                    class="fa-solid fa-person-shelter"></i></button>
            <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')"><i
                    class="bi bi-clipboard2-data"></i></button>
            <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')"><i
                    class="bi bi-exclamation-triangle"></i></button>
            <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')"><i
                    class="bi bi-people"></i></button>
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')"><i
                    class="bi bi-gear"></i></button>
        </div>
        <div class="offcanvas-footer">
            <img src="../../images/team-logo.png" alt="Team Logo" class="team-logo">
        </div>
    </div>

    <!-- ═══ PROFILE OFFCANVAS ═══ -->
    <div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas" aria-labelledby="profileOffcanvasLabel">
        <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
            <div class="avatar-icon d-flex align-items-center justify-content-center">
                <h3 class="bold"><?= $initials ?></h3> 
            </div>
            <h4 class="bold mt-2 profile-offcanvas-heading"><?= $admin_name ?></h4> 
            <h6 class="light profile-email"><? htmlspecialchars($admin_email)?></h6>
            <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
                <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
                <button class="profile-btn">Classroom Details</button>
                <button class="profile-btn" onclick="window.location.href='../../index.php'">Logout</button>
            </div>
        </div>
    </div>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/initialize-gesture.js"></script>

</body>

</html>