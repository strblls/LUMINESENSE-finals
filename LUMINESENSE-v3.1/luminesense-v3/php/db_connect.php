<?php
// ============================================================
//  db_connect.php
//  LumineSense – Database Connection
//  XAMPP / MySQL running locally on localhost
//  Database name: luminesense_db
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // default XAMPP username
define('DB_PASS', '');           // default XAMPP password (empty)
define('DB_NAME', 'luminesense_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    // In prototype phase we show the raw error so we can debug easily.
    // In production, replace this with a user-friendly page.
    die("Database connection failed: " . $conn->connect_error);
}

// Always use UTF-8 so Filipino names with special characters display correctly
$conn->set_charset("utf8mb4");
?>
