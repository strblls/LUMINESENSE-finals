<?php
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-center">
        <img src="../../images/logo.png" class="logo" alt="Logo"
             onclick="dissolve('admin-homepage.php')">
    </div>
    <div class="offcanvas-body align-items-center d-flex flex-column gap-2">
        <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')">
            <i class="bi bi-house-door"></i>
        </button>
        <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')">
            <i class="fa-solid fa-person-shelter"></i>
        </button>
        <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')">
            <i class="bi bi-clipboard2-data"></i>
        </button>
        <!-- <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')">
            <i class="bi bi-exclamation-triangle"></i>
        </button> -->
        <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')">
            <i class="bi bi-people"></i>
        </button>
        <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')">
            <i class="bi bi-gear"></i>
        </button>
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
        <h6 class="light" style="word-break:break-all;text-align:center;">
            <?= htmlspecialchars($admin_email) ?>
        </h6>
        <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
            <button class="profile-btn" onclick="dissolve('admin-profile-settings.php')">Profile Settings</button>
            <button class="profile-btn">Classroom Details</button>
            <button class="profile-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
        </div>
    </div>
</div>