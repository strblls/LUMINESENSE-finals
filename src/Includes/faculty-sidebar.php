<?php

/** @var string $initials */
/** @var string $faculty_name */
/** @var string $faculty_email */
// Check if the logged-in faculty member is a Department Head
$is_head = $_SESSION['is_head'] ?? false;
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-start">
        <img src="../../images/logo.png" class="logo" alt="Logo">
    </div>
    <div class="offcanvas-body align-items-start justify-content-start d-flex flex-column gap-2">
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Home" onclick="dissolve('faculty-home.php')">
                <i class="bi bi-house-door"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Home</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Timetable" onclick="dissolve('faculty-timetable.php')">
                <i class="bi bi-calendar-event"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Timetable</h3>
        </div>
        <?php if ($is_head): ?>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Head Timetable" onclick="dissolve('faculty-head-timetable.php')">
                <i class="bi bi-calendar3-range-fill"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Manage Schedules</h3>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('faculty-profile-settings.php')">
                <i class="bi bi-gear"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Settings</h3>
        </div>
    </div>
    <div class="offcanvas-footer align-items-start justify-content-start d-flex">
        <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
    </div>
</div>

<script>
    window.lumiIsHead = <?= json_encode((bool)$is_head) ?>;
</script>
<script src="../../js/includes/faculty-sidebar.js"></script>