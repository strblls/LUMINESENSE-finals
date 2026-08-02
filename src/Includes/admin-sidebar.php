<?php

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-start">
        <img src="../../images/logo.png" class="logo" alt="Logo">
    </div>
    <div class="offcanvas-body align-items-start justify-content-start d-flex flex-column gap-2">
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')">
                <i class="bi bi-house-door"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Home</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')">
                <i class="fa-solid fa-person-shelter"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Rooms</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')">
                <i class="bi bi-clipboard2-data"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Analytics</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')">
                <i class="bi bi-exclamation-triangle"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Reports</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')">
                <i class="bi bi-people"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Faculty</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Settings" onclick="dissolve('admin-profile-settings.php')">
                <i class="bi bi-gear"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Settings</h3>
        </div>
    </div>
    <div class="offcanvas-footer align-items-start justify-content-start d-flex">
        <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
    </div>
</div>

<script src="../js/includes/admin-sidebar.js"></script>