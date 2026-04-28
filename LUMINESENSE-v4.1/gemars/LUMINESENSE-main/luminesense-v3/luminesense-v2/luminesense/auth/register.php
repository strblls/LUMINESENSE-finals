<?php
require_once '../includes/db.php';

/*ADMINS ONLY!!!!!!! 
   Example: creating an admin account for the first time
   Run this ONCE (Future selves) Protect this file after.*/

$conn = getConnection();

$username = 'admin';
$password = password_hash('adminAKO123', PASSWORD_DEFAULT); 
$role = 'admin';

$stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $username, $password, $role);

if ($stmt->execute()) {
    echo "Admin created successfully!";
} else {
    echo "Error: " . $stmt->error;
}
$conn->close();
?>