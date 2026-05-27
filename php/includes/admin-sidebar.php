<?php
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-center">
        <img src="../../images/logo.png" class="logo" alt="Logo">
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
        <!-- <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')">
            <i class="bi bi-gear"></i>
        </button> -->
    </div>
    <div class="offcanvas-footer">
        <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
    </div>
</div>

<script>
(function () {
    const page = window.location.pathname.split('/').pop();
    const map = {
        'admin-homepage.php':           0,
        'admin-room-manage.php':        1,
        'admin-analytics.php':          2,
        'admin-faculty-management.php': 3,
        'admin-faculty-card.php':       3,
        'admin-profile-settings.php':   4,
        'admin-reports.php':            null,
    };
    const index = map[page];
    if (index !== null && index !== undefined) {
        const btns = document.querySelectorAll('#sidebarOffcanvas .nav-btn');
        if (btns[index]) {
            btns[index].style.backgroundColor = 'var(--secondary-color-4)';
            btns[index].style.boxShadow = '0 0 0 3px rgba(155,0,233,0.3)';
        }
    }
})();
</script>