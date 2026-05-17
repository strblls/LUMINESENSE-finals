<?php
// Simple database connection test
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'luminesense_db';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    echo "❌ Database connection FAILED: " . $conn->connect_error;
    echo "<br>Make sure MySQL is running and the credentials are correct.";
} else {
    echo "✅ Database connection SUCCESSFUL!";
    echo "<br>Connected to: $db";

    // Test if we can create the database
    $conn->query("CREATE DATABASE IF NOT EXISTS $db");
    $conn->select_db($db);
    echo "<br>✅ Database selected/created successfully";

    // Test creating a simple table
    $result = $conn->query("CREATE TABLE IF NOT EXISTS test_table (id INT PRIMARY KEY)");
    if ($result) {
        echo "<br>✅ Table creation successful";
    } else {
        echo "<br>❌ Table creation failed: " . $conn->error;
    }
}

$conn->close();
?>