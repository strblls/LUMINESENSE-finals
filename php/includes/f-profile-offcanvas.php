<?php
/** @var string $initials */
/** @var string $faculty_name */
/** @var string $faculty_email */
?>


<!-- SIDEBAR RIGHT -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas"
    aria-labelledby="sidebarOffcanvasLabel">
    <div class="offcanvas-body align-items-center d-flex flex-column">
        <div class="avatar-icon d-flex align-items-center justify-content-center">
            <h3 class="bold"><?= htmlspecialchars($initials) ?></h3>
        </div>
        <h4 class="bold"><?= htmlspecialchars($faculty_name) ?></h4>
        <h6 class="light email-limit"><?= htmlspecialchars($faculty_email) ?></h6>
        <div class="d-flex flex-column align-items-center justify-content-center">
            <button class="profile-btn" onclick="dissolve('faculty-profile-settings.php')">Profile Settings</button>
            <button class="profile-btn">Classroom Details</button>
            <button class="profile-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
        </div>
    </div>
</div>