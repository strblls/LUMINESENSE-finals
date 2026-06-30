<!-- TOPBAR -->
<?php
/** @var string $faculty_name */
/** @var string $faculty_email */
/** @var string $initials */
/** @var string $first_name */

// Check if the session variable is set and true
$is_head = $_SESSION['is_head'] ?? false;

// Initialize current schedule with default value if not set
$current_sched ??= 'No class right now';
?>

<link rel="stylesheet" href="../../css/faculty-settings.css">

<div class="topbar d-flex">
    <button type="button" id="sidebarTrigger">
        <i class="bi bi-list"></i>
    </button>
    <div class="col d-flex flex-column px-3 topbar-greeting">
        <h1 class="bold">Welcome, <?= $first_name ?>!</h1>
        <h5 class="light">Current Schedule: <?= $current_sched ?></h5>
    </div>
    <div class="d-flex align-items-center justify-content-center gap-2 mx-2">
        <div class="d-flex flex-column align-items-end topbar-user-info">
            <h4 class="mb-0"><?= htmlspecialchars($faculty_name) ?></h4>
            <?php if ($is_head): ?>
                <span class="bold status-badge faculty-head">Faculty Head</span>
            <?php else: ?>
                <span class="bold status-badge faculty-member">Faculty Member</span>
            <?php endif; ?>
        </div>
        <a href="faculty-profile-settings.php" class="avatar-icon d-flex align-items-center justify-content-center"
            style="text-decoration: none;">
            <h3 class="bold"><?= $initials ?></h3>
        </a>
        <button class="light w-auto" onclick="dissolve('../../php/logout.php')">Logout</button>
    </div>
</div>

<!-- Hidden schedule end data for audio notifications -->
<div id="scheduleEndData" data-end="<?= htmlspecialchars($active_schedule_end ?? '') ?>" style="display:none;"></div>

<!-- Audio notification toast -->
<div class="notif-toast-wrap" id="notificationToastWrap">
    <div class="notif-toast-msg" id="notificationToastMsg">
        <div class="notif-toast-header">
            <i class="bi bi-clock-fill"></i>
            <span id="notifTimeLabel">Time Remaining</span>
        </div>
        <div class="notif-toast-body" id="notifTimeMessage">5 minutes remaining in your class.</div>
    </div>
</div>

<script src="../../script/faculty-notification.js"></script>

<style>
.topbar-greeting,
.topbar-user-info {
    transition: opacity 0.3s ease;
}
.topbar-greeting.hidden,
.topbar-user-info.hidden {
    opacity: 0;
    pointer-events: none;
}
</style>

<script>
window.addEventListener('scroll', function () {
    var scrollThreshold = 100;
    var nearBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - scrollThreshold;
    document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function (el) {
        el.classList.toggle('hidden', nearBottom);
    });
});
</script>