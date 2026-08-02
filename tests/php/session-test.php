<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

echo "<h3>Session Contents:</h3>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

require_once 'php/db_connect.php';

echo "<h3>Testing Database Connection:</h3>";
$faculty_id = $_SESSION['faculty_id'] ?? 0;
echo "Faculty ID from session: " . $faculty_id . "<br>";

// Test get departments
$stmt = $conn->prepare("SELECT id, name, description FROM departments WHERE head_faculty_id = ? AND status = 'active' ORDER BY name");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$res = $stmt->get_result();
echo "Number of departments found: " . $res->num_rows . "<br>";
while ($row = $res->fetch_assoc()) {
    echo "- " . $row['name'] . "<br>";
}
$stmt->close();
?>