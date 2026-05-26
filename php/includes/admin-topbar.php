<?php
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<div class="topbar d-flex"
    style="background:linear-gradient(0deg,rgba(255,255,255,0) 9%,rgba(47,0,79,0.76) 40%,rgba(47,0,79,0.95) 70%,rgba(47,0,79,1) 100%);">
    <button type="button" id="sidebarTrigger" data-bs-toggle="offcanvas" data-bs-target="#sidebarOffcanvas">
        <i class="bi bi-list"></i>
    </button>
    <div class="col d-flex flex-column px-3">
        <h1 class="bold"><?= $page_title ?? 'Dashboard' ?></h1>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-3 mx-2">
        <h4><?= explode(' ', $admin_name)[0] ?></h4>
        <a href="admin-profile-settings.php" class="avatar-icon d-flex align-items-center justify-content-center"
            style="text-decoration: none;">
            <h3 class="bold"><?= $initials ?></h3>
        </a>
        <button class="light info-action-btn logout-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
    </div>
</div>