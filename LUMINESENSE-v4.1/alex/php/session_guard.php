<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function check_admin() {
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
        header('Location: ../admin-login-page.php');
        exit;
    }
}

function check_faculty() {
    if (empty($_SESSION['faculty_logged_in']) || $_SESSION['role'] !== 'faculty') {
        header('Location: ../faculty-login-page.php');
        exit;
    }
}
