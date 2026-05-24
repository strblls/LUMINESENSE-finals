<?php
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<!-- PROFILE OFFCANVAS -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="profileOffcanvas">
    <div class="offcanvas-body align-items-center d-flex flex-column pt-4 gap-2">
        <div class="avatar-icon d-flex align-items-center justify-content-center">
            <h3 class="bold"><?= $initials ?></h3>
        </div>
        <h4 class="bold mt-2" style="color:var(--secondary-color-1);"><?= $admin_name ?></h4>
        <h6 class="light" style="word-break:break-all;text-align:center;"><?= htmlspecialchars($admin_email) ?></h6>
        <div class="d-flex flex-column align-items-center justify-content-center w-100 mt-2 gap-1">
            <button class="profile-btn" onclick="dissolve('../../php/logout.php')">Logout</button>
        </div>
    </div>
</div>
