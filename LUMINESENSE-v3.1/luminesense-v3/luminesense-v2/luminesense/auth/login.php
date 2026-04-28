<?php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

// validation
if (empty($username) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Username and password required']);
    exit;
}

$conn = getConnection();

// login attempts (lockout after 3 tries in 5 mins)
$lockout_check = $conn->prepare("
    SELECT COUNT(*) as attempts 
    FROM alerts 
    WHERE alert_type = 'unauthorized_motion' 
    AND message LIKE ? 
    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
");
// (simplez anay)

//find user
$stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
    exit;
}

$user = $result->fetch_assoc();

//check password
if (!password_verify($password, $user['password'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
    exit;
}
//save login
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'];

echo json_encode([
    'status' => 'success',
    'role' => $user['role'],
    'redirect' => '../dashboard/index.php'
]);

$conn->close();
?>