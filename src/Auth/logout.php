<?php
session_start();
if (!empty($_SESSION['faculty_logged_in']) && ($_SESSION['role'] ?? '') === 'faculty'
    && !empty($_SESSION['faculty_id']) && ($_GET['reason'] ?? '') !== 'idle') {
    $allowDbConnectionFailure = true;
    require_once __DIR__ . '/../Config/db_connect.php';
    $facultyId = (int)$_SESSION['faculty_id'];
    $stmt = !$conn->connect_error
        ? $conn->prepare("INSERT INTO faculty_account_events (faculty_id, event_type) VALUES (?, 'sign_out')")
        : false;
    if ($stmt) {
        $stmt->bind_param('i', $facultyId);
        $stmt->execute();
        $stmt->close();
    }
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: ../index.php'); exit;
