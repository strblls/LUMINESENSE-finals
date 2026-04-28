<?php
// ============================================================
//  admin-sidebar.php
//  LumineSense – Admin Sidebar (included in every admin page)
//
//  HOW TO USE:  <?php include 'admin-sidebar.php'; ?>
//
//  It automatically highlights the active link by comparing
//  the current filename to the nav link hrefs.
// ============================================================

$current_page = basename($_SERVER['PHP_SELF']);
function is_active($page) {
    global $current_page;
    return $current_page === $page ? 'active' : '';
}
?>
<aside class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <img src="../../images/logo.png" alt="LumineSense">
        <span>LumineSense</span>
    </div>

    <!-- Logged-in user info -->
    <div class="sidebar-user">
        <strong><?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?></strong>
        <span class="role-badge">Administrator</span>
    </div>

    <!-- Navigation -->
    <nav>
        <span class="nav-section-label">Main</span>

        <a href="admin-homepage.php" class="<?= is_active('admin-homepage.php') ?>">
            <i class="bi bi-speedometer2"></i>
            <span>Overview</span>
        </a>

        <a href="admin-classrooms.php" class="<?= is_active('admin-classrooms.php') ?>">
            <i class="bi bi-door-open-fill"></i>
            <span>Classrooms</span>
        </a>

        <a href="admin-schedule.php" class="<?= is_active('admin-schedule.php') ?>">
            <i class="bi bi-calendar-week-fill"></i>
            <span>Timetable</span>
        </a>

        <span class="nav-section-label">Monitoring</span>

        <a href="admin-logs.php" class="<?= is_active('admin-logs.php') ?>">
            <i class="bi bi-clock-history"></i>
            <span>Activity Logs</span>
        </a>

        <a href="admin-alerts.php" class="<?= is_active('admin-alerts.php') ?>">
            <i class="bi bi-shield-exclamation"></i>
            <span>Security Alerts</span>
        </a>

        <a href="admin-analytics.php" class="<?= is_active('admin-analytics.php') ?>">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Energy Analytics</span>
        </a>

        <span class="nav-section-label">Management</span>

        <a href="admin-accounts.php" class="<?= is_active('admin-accounts.php') ?>">
            <i class="bi bi-people-fill"></i>
            <span>Faculty Accounts</span>
            <?php
            // Show badge if there are pending accounts
            global $conn;
            if (!isset($conn)) {
                require_once '../../php/db_connect.php';
            }
            $pending = 0;
            $rp = $conn->query("SELECT COUNT(*) AS cnt FROM faculty WHERE is_verified = 0");
            if ($rp) $pending = $rp->fetch_assoc()['cnt'];
            if ($pending > 0):
            ?>
            <span style="margin-left:auto; background:#e0a800; color:#1a1a2e; font-size:0.68rem; font-weight:800; padding:1px 7px; border-radius:20px;"><?= $pending ?></span>
            <?php endif; ?>
        </a>

    </nav>

    <!-- Logout -->
    <div class="sidebar-footer">
        <a href="../../php/logout.php">
            <i class="bi bi-box-arrow-left"></i>
            <span>Logout</span>
        </a>
    </div>

</aside>
