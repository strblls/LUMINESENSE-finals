<?php
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<div class="topbar d-flex">
    <button type="button" id="sidebarTrigger">
        <i class="bi bi-list"></i>
    </button>
    <div class="col d-flex flex-column justify-content-center px-3">
        <h1 class="bold m-0"><?= $page_title ?? 'Dashboard' ?></h1>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-3 mx-2">
        <div class="d-flex flex-column align-items-end">
            <h4 class="mb-0"><?= htmlspecialchars($admin_name) ?></h4>
            <span class="bold status-badge admin">Admin</span>
        </div>

        <a href="admin-profile-settings.php" class="avatar-icon d-flex align-items-center justify-content-center"
            style="text-decoration: none;">
            <h3 class="bold"><?= $initials ?></h3>
        </a>
        <button class="light info-action-btn logout-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
    </div>
</div>