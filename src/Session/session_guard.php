<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function check_admin() {
    if (empty($_SESSION['admin_logged_in']) || $_SESSION['role'] !== 'admin') {
        header('Location: ../pages/admin-login.php');
        exit;
    }
}

function check_faculty() {
    if (empty($_SESSION['faculty_logged_in']) || $_SESSION['role'] !== 'faculty') {
        header('Location: ../pages/faculty-login.php');
        exit;
    }
}
