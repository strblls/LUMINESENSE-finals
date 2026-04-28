<?php
// ============================================================
//  admin-accounts.php
//  LumineSense – Faculty Account Management
//
//  Admins can:
//  - See all pending faculty accounts → Approve or Reject
//  - See all verified faculty accounts
//  - Revoke access from a verified account
// ============================================================

require_once '../../php/session_guard.php';
check_admin();
require_once '../../php/db_connect.php';

// ── Handle POST actions (approve / reject / revoke) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']     ?? '';
    $faculty_id = (int)($_POST['faculty_id'] ?? 0);

    if ($faculty_id > 0) {
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE faculty SET is_verified = 1 WHERE id = ?");
            $stmt->bind_param("i", $faculty_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['accounts_msg'] = ['type' => 'success', 'text' => 'Faculty account approved successfully.'];

        } elseif ($action === 'reject' || $action === 'revoke') {
            // For prototype: we DELETE the account on reject.
            // In production you may want a "rejected" status instead.
            $stmt = $conn->prepare("DELETE FROM faculty WHERE id = ?");
            $stmt->bind_param("i", $faculty_id);
            $stmt->execute();
            $stmt->close();
            $msg = $action === 'reject' ? 'Faculty account rejected and removed.' : 'Faculty access revoked.';
            $_SESSION['accounts_msg'] = ['type' => 'warning', 'text' => $msg];
        }
    }

    header('Location: admin-accounts.php');
    exit;
}

// ── Fetch pending accounts ─────────────────────────────────────
$pending = [];
$r = $conn->query("SELECT id, last_name, first_name, middle_initial, email, created_at FROM faculty WHERE is_verified = 0 ORDER BY created_at ASC");
if ($r) while ($row = $r->fetch_assoc()) $pending[] = $row;

// ── Fetch verified accounts ────────────────────────────────────
$verified = [];
$r = $conn->query("SELECT id, last_name, first_name, middle_initial, email, created_at FROM faculty WHERE is_verified = 1 ORDER BY last_name ASC");
if ($r) while ($row = $r->fetch_assoc()) $verified[] = $row;

$conn->close();

// Flash message
$msg = $_SESSION['accounts_msg'] ?? null;
unset($_SESSION['accounts_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Accounts – LumineSense Admin</title>

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
            <h1 class="topbar-title">Faculty Accounts</h1>
            <div class="topbar-right">
                <span><i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['admin_name']) ?></span>
            </div>
        </div>

        <div class="dashboard-content">

            <!-- Flash message -->
            <?php if ($msg): ?>
            <div class="alert-banner <?= $msg['type'] === 'warning' ? 'danger' : '' ?>" style="margin-bottom:20px;">
                <i class="bi bi-<?= $msg['type'] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <span><?= htmlspecialchars($msg['text']) ?></span>
            </div>
            <?php endif; ?>

            <!-- ── Pending Accounts ───────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6>
                        <i class="bi bi-person-fill-exclamation"></i> Pending Approval
                        <?php if (count($pending) > 0): ?>
                        <span style="background:#e0a800; color:#1a1a2e; font-size:0.68rem; font-weight:800; padding:1px 8px; border-radius:20px; margin-left:6px;"><?= count($pending) ?></span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($pending)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:24px 0;">
                        <i class="bi bi-check-circle" style="font-size:1.5rem; display:block; margin-bottom:6px; color:#ccc;"></i>
                        No pending accounts. All caught up!
                    </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Registered</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending as $f): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($f['last_name']) ?>,</strong>
                                    <?= htmlspecialchars($f['first_name']) ?>
                                    <?= htmlspecialchars($f['middle_initial']) ?>
                                </td>
                                <td><?= htmlspecialchars($f['email']) ?></td>
                                <td style="color:#888;"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                                <td><span class="badge-pending">Pending</span></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="faculty_id" value="<?= $f['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn-approve"
                                                onclick="return confirm('Approve <?= htmlspecialchars($f['first_name']) ?>\'s account?')">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display:inline; margin-left:6px;">
                                        <input type="hidden" name="faculty_id" value="<?= $f['id'] ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn-reject"
                                                onclick="return confirm('Reject and remove <?= htmlspecialchars($f['first_name']) ?>\'s account?')">
                                            <i class="bi bi-x-lg"></i> Reject
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

            <!-- ── Verified Accounts ─────────────────────── -->
            <div class="panel">
                <div class="panel-header">
                    <h6><i class="bi bi-people-fill"></i> Verified Faculty (<?= count($verified) ?>)</h6>
                </div>
                <div class="panel-body" style="padding:0;">
                    <?php if (empty($verified)): ?>
                    <p style="color:#aaa; font-size:0.85rem; text-align:center; padding:24px 0;">
                        No verified faculty yet.
                    </p>
                    <?php else: ?>
                    <table class="ls-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Registered</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verified as $f): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($f['last_name']) ?>,</strong>
                                    <?= htmlspecialchars($f['first_name']) ?>
                                    <?= htmlspecialchars($f['middle_initial']) ?>
                                </td>
                                <td><?= htmlspecialchars($f['email']) ?></td>
                                <td style="color:#888;"><?= date('M d, Y', strtotime($f['created_at'])) ?></td>
                                <td><span class="badge-verified">Verified</span></td>
                                <td>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="faculty_id" value="<?= $f['id'] ?>">
                                        <input type="hidden" name="action" value="revoke">
                                        <button type="submit" class="btn-reject"
                                                onclick="return confirm('Revoke access for <?= htmlspecialchars($f['first_name']) ?>?')">
                                            <i class="bi bi-person-dash"></i> Revoke
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

        </div>
    </div>
</div>
</body>
</html>
