<!-- TOPBAR -->
<?php
/** @var string $faculty_name */
/** @var string $faculty_email */
/** @var string $initials */
/** @var string $first_name */
?>

<div class="topbar d-flex">
    <button type="button" id="sidebarTrigger">
        <i class="bi bi-list"></i>
    </button>
    <div class="col d-flex flex-column px-3">
        <h1 class="bold">Welcome, <?= $first_name ?>!</h1>
        <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
        <h4><?= $faculty_name ?></h4>
        <a href="faculty-profile-settings.php" class="avatar-icon d-flex align-items-center justify-content-center"
            style="text-decoration: none;">
            <h3 class="bold"><?= $initials ?></h3>
        </a>
        <button class="light info-action-btn logout-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
    </div>
</div>