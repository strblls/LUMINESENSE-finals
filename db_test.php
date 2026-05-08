<?php
require_once 'php/db_connect.php';

echo "<h1>Database Test</h1>";

if ($conn->connect_error) {
    echo "<p style='color:red'>Connection failed: " . $conn->connect_error . "</p>";
} else {
    echo "<p style='color:green'>Database connection successful!</p>";

    // Check tables
    $tables = ['admins', 'faculty', 'classrooms', 'schedules', 'lighting_logs'];
    foreach ($tables as $table) {
        $result = $conn->query("SHOW TABLES LIKE '$table'");
        if ($result->num_rows > 0) {
            echo "<p>$table table exists.</p>";
        } else {
            echo "<p style='color:red'>$table table does not exist.</p>";
        }
    }

    // Check admin count
    $result = $conn->query("SELECT COUNT(*) as count FROM admins");
    $row = $result->fetch_assoc();
    echo "<p>Admin accounts: " . $row['count'] . "</p>";

    if ($row['count'] > 0) {
        $result = $conn->query("SELECT email, is_verified FROM admins");
        echo "<h2>Admin Accounts:</h2><ul>";
        while ($row = $result->fetch_assoc()) {
            echo "<li>" . htmlspecialchars($row['email']) . " (verified: " . $row['is_verified'] . ")</li>";
        }
        echo "</ul>";
    }
}

$conn->close();
?>