<?php
// ============================================================
//  admin-classrooms.php
//  LumineSense – Classroom Management
//
//  Admins can:
//  - See all classrooms
//  - Add a new classroom (name, size, description)
//  - Delete a classroom
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

// ── Handle POST: Add classroom ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $room_name   = trim(htmlspecialchars($_POST['room_name']    ?? ''));
    $room_size   = $_POST['room_size']    ?? 'medium';
    $description = trim(htmlspecialchars($_POST['description']  ?? ''));

    $valid_sizes = ['small','medium','large'];
    $errors = [];
    if (empty($room_name)) $errors[] = "Room name is required.";
    if (!in_array($room_size, $valid_sizes)) $errors[] = "Invalid room size.";

    if (empty($errors)) {
        $stmt = $conn->prepare("INSERT INTO classrooms (room_name, room_size, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $room_name, $room_size, $description);
        $stmt->execute();
        $stmt->close();
        $_SESSION['classroom_msg'] = ['type'=>'success', 'text'=>"Classroom \"$room_name\" added."];
    } else {
        $_SESSION['classroom_msg'] = ['type'=>'danger', 'text'=> implode(' ', $errors)];
    }
    header('Location: admin-classrooms.php');
    exit;
}

// ── Handle POST: Delete classroom ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $classroom_id = (int)($_POST['classroom_id'] ?? 0);
    if ($classroom_id > 0) {
        // Also delete related schedules + logs (cascade safety)
        $conn->query("DELETE FROM schedules WHERE classroom_id = $classroom_id");
        $conn->query("DELETE FROM lighting_logs WHERE classroom_id = $classroom_id");
        $stmt = $conn->prepare("DELETE FROM classrooms WHERE id = ?");
        $stmt->bind_param("i", $classroom_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['classroom_msg'] = ['type'=>'success', 'text'=>'Classroom removed.'];
    }
    header('Location: admin-classrooms.php');
    exit;
}

// ── Fetch all classrooms with schedule count ──────────────────
$classrooms = [];
$r = $conn->query("
    SELECT c.id, c.room_name, c.room_size, c.description, c.created_at,
           COUNT(s.id) AS schedule_count
    FROM classrooms c
    LEFT JOIN schedules s ON s.classroom_id = c.id
    GROUP BY c.id
    ORDER BY c.room_name ASC
");
if ($r) while ($row = $r->fetch_assoc()) $classrooms[] = $row;

$conn->close();

$msg = $_SESSION['classroom_msg'] ?? null;
unset($_SESSION['classroom_msg']);

$size_labels = ['small' => 'Small (7m×7m)', 'medium' => 'Medium (7m×9m)', 'large' => 'Large (9m×10m+)'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Classrooms – LumineSense Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/dashboard.css">
</head>
<body>
<div class="dashboard-wrapper">

    <?php include 'admin-sidebar.php'; ?>

    <div class="dashboard-main">

        <div class="dashboard-topbar">
            <h1 class="topbar-title">Classrooms</h1>
            <div class="topbar-right">
                <button class="medium" onclick="document.getElementById('add-room-modal').style.display='flex'"
                        style="font-size:0.82rem; padding:7px 16px;">
                    <i class="bi bi-plus-lg"></i> Add Classroom
                </button>
            </div>
        </div>

        <div class="dashboard-content">

            <?php if ($msg): ?>
            <div class="alert-banner <?= $msg['type'] === 'danger' ? 'danger' : '' ?>" style="margin-bottom:20px;">
                <i class="bi bi-<?= $msg['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <span><?= htmlspecialchars($msg['text']) ?></span>
            </div>
            <?php endif; ?>

            <!-- ── Classroom Table ────────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-door-open-fill"></i> All Classrooms (<?= count($classrooms) ?>)</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($classrooms)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:28px 0;">
                        No classrooms yet. Click <strong>Add Classroom</strong> to add the first one.
                    </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Room Name</th>
                                <th>Size</th>
                                <th>Description</th>
                                <th>Schedules</th>
                                <th>Added</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($classrooms as $c): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($c['room_name']) ?></strong></td>
                                <td>
                                    <span class="badge-info"><?= $size_labels[$c['room_size']] ?? $c['room_size'] ?></span>
                                </td>
                                <td style="color:#666; max-width:200px;">
                                    <?= $c['description'] ? htmlspecialchars($c['description']) : '<span style="color:#ccc;">—</span>' ?>
                                </td>
                                <td>
                                    <span class="badge-info"><?= $c['schedule_count'] ?> slot(s)</span>
                                </td>
                                <td style="color:#888;"><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="classroom_id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn-reject"
                                                onclick="return confirm('Delete \"<?= htmlspecialchars($c['room_name']) ?>\"? This also removes its schedules and logs.')">
                                            <i class="bi bi-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Classroom Dimension Reference ─────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-info-circle"></i> DepEd Classroom Size Reference</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Dimensions</th>
                                <th>Area</th>
                                <th>Capacity</th>
                                <th>Required Lux</th>
                                <th>Bulb Grid</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="badge-info">Small</span></td>
                                <td>7m × 7m</td>
                                <td>49 sq.m.</td>
                                <td>30–35 students</td>
                                <td>300 lux (3,000 lm)</td>
                                <td>2×2 (4 bulbs)</td>
                            </tr>
                            <tr>
                                <td><span class="badge-verified">Medium</span></td>
                                <td>7m × 9m</td>
                                <td>63 sq.m.</td>
                                <td>45–46 students</td>
                                <td>300–500 lux</td>
                                <td>3×3 (9 bulbs) ← prototype</td>
                            </tr>
                            <tr>
                                <td><span class="badge-pending">Large</span></td>
                                <td>9m × 10m+</td>
                                <td>90+ sq.m.</td>
                                <td>30+ (w/ equipment)</td>
                                <td>500+ lux</td>
                                <td>4×4 or larger</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ── Add Classroom Modal ────────────────────────────────── -->
<div class="ls-modal-overlay" id="add-room-modal" style="display:none;">
    <div class="ls-modal-box">
        <h5><i class="bi bi-door-open-fill"></i> Add Classroom</h5>
        <form method="POST">
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
                <label class="form-label" style="font-size:0.85rem; font-weight:600;">Room Name</label>
                <input type="text" name="room_name" class="form-control" placeholder="e.g. Room 101" required>
            </div>

            <div class="mb-3">
                <label class="form-label" style="font-size:0.85rem; font-weight:600;">Room Size</label>
                <select name="room_size" class="form-control" required>
                    <option value="small">Small (7m × 7m, 49 sq.m.)</option>
                    <option value="medium" selected>Medium (7m × 9m, 63 sq.m.) – Standard</option>
                    <option value="large">Large (9m × 10m+, 90+ sq.m.)</option>
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label" style="font-size:0.85rem; font-weight:600;">Description <span style="color:#aaa;">(optional)</span></label>
                <input type="text" name="description" class="form-control" placeholder="e.g. IT Lab – 2nd floor">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-reject"
                        onclick="document.getElementById('add-room-modal').style.display='none'">
                    Cancel
                </button>
                <button type="submit" class="btn-approve">
                    <i class="bi bi-plus-lg"></i> Add Classroom
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('add-room-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>
</body>
</html>
