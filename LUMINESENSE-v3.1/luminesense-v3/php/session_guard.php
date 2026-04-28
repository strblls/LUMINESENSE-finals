<?php
// ============================================================
//  session_guard.php
//  LumineSense – Page Protection
//
//  Think of this as a security guard standing at the door
//  of the dashboard. If you don't have the right "badge"
//  (session), the guard sends you back to the login page.
//
//  HOW TO USE IT:
//  Put this at the very TOP of any page that needs login:
//
//  For Faculty pages:
//    <?php require_once '../../php/session_guard.php'; check_faculty(); ?>
//
//  For Admin pages:
//    <?php require_once '../../php/session_guard.php'; check_admin(); ?>
//
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Protect a Faculty-only page.
 * If not logged in as faculty, redirect to faculty login.
 */
function check_faculty() {
    if (
        !isset($_SESSION['faculty_logged_in']) ||
        $_SESSION['faculty_logged_in'] !== true ||
        $_SESSION['role'] !== 'faculty'
    ) {
        header('Location: ../../pages/faculty-login.php');
        exit;
    }
}

/**
 * Protect an Admin-only page.
 * If not logged in as admin, redirect to admin login.
 */
function check_admin() {
    if (
        !isset($_SESSION['admin_logged_in']) ||
        $_SESSION['admin_logged_in'] !== true ||
        $_SESSION['role'] !== 'admin'
    ) {
        header('Location: ../../pages/admin-login.php');
        exit;
    }
}

/**
 * Get the name of whoever is logged in.
 * Returns "Guest" if no session found.
 */
function get_logged_in_name() {
    if (isset($_SESSION['admin_name']))   return $_SESSION['admin_name'];
    if (isset($_SESSION['faculty_name'])) return $_SESSION['faculty_name'];
    return 'Guest';
}

/**
 * Get the role of whoever is logged in.
 * Returns "guest" if no session found.
 */
function get_role() {
    return $_SESSION['role'] ?? 'guest';
}
?>
