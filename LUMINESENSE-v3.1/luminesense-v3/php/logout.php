<?php
// ============================================================
//  logout.php
//  LumineSense – Logout (works for both Faculty and Admin)
//
//  What this does (very simply):
//  Imagine your session is like a visitor badge you get when
//  you enter a building. logout.php rips that badge off and
//  throws it away so nobody else can use it.
//
//  HOW TO CALL THIS:
//  Just link to it:  <a href="../../php/logout.php">Logout</a>
//  It always redirects back to the home page (index.php).
// ============================================================

session_start();

// Forget everything stored in the session
$_SESSION = [];

// Destroy the session cookie in the browser too
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,           // Set expiry in the past → deletes the cookie
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Completely destroy the session on the server
session_destroy();

// Send the user back to the landing page
header('Location: ../../index.php');
exit;
?>
