 <?php
require_once 'php/db_connect.php';

$res = $conn->query("DESCRIBE admins");
echo "=== admins structure ===\n";
while ($row = $res->fetch_assoc()) {
    print_r($row);
}

$res2 = $conn->query("SELECT * FROM admins");
echo "\n=== admins data ===\n";
while ($row = $res2->fetch_assoc()) {
    // hide password hash for safety/cleanliness, but let's see if it's there
    if (isset($row['password'])) {
        $row['password'] = substr($row['password'], 0, 10) . '...';
    }
    print_r($row);
}

$res3 = $conn->query("DESCRIBE id_review_queue");
echo "\n=== id_review_queue structure ===\n";
while ($row = $res3->fetch_assoc()) {
    print_r($row);
}

$res4 = $conn->query("SELECT * FROM id_review_queue");
echo "\n=== id_review_queue data ===\n";
while ($row = $res4->fetch_assoc()) {
    if (isset($row['encrypted_blob'])) {
        $row['encrypted_blob'] = 'BLOB(' . strlen($row['encrypted_blob']) . ' bytes)';
    }
    print_r($row);
}
