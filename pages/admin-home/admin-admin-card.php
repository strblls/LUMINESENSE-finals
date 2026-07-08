<?php
$page_title = 'Admin Review';
require_once '../../php/includes/admin-head.php';

$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/handlers/admin-handlers.php';

// ── Get admin id from URL ───────────────────────────────────────────────
$admin_review_id = (int)($_GET['id'] ?? 0);
if (!$admin_review_id) {
    header('Location: admin-faculty-management.php');
    exit;
}

// ── Fetch admin record ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, first_name, last_name, middle_initial, email,
           is_verified, approved_by, created_at, id_image
    FROM admins
    WHERE id = ?
");
$stmt->bind_param('i', $admin_review_id);
$stmt->execute();
$admin_rec = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin_rec) {
    header('Location: admin-faculty-management.php');
    exit;
}

// ── Fetch id_review_queue entry for this admin ──────────────────────────
$queue_id = null;
$ai_status = 'unreadable';
$ai_extracted_name = '';
$ai_confidence_note = '';
$stmt = $conn->prepare("
    SELECT id, ai_match_status, ai_extracted_name, ai_confidence_note
    FROM id_review_queue
    WHERE account_type = 'admin' AND account_id = ?
    ORDER BY id DESC LIMIT 1
");
$stmt->bind_param('i', $admin_review_id);
$stmt->execute();
$qrow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($qrow) {
    $queue_id = (int)$qrow['id'];
    $ai_status = $qrow['ai_match_status'] ?? 'unreadable';
    $ai_extracted_name = $qrow['ai_extracted_name'] ?? '';
    $ai_confidence_note = $qrow['ai_confidence_note'] ?? '';
}

// ── Handle approve / reject from this page ──────────────────────────────
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $a_name = $admin_rec['first_name'] . ' ' . $admin_rec['last_name'];
    $a_email = $admin_rec['email'];

    if ($action === 'admin_approve') {
        $stmt = $conn->prepare('UPDATE admins SET approved_by = ?, approved_at = NOW() WHERE id = ?');
        $stmt->bind_param('ii', $admin_id, $admin_review_id);
        $stmt->execute();
        $stmt->close();

        // Purge ID image file from disk
        if (!empty($admin_rec['id_image'])) {
            $img_path = realpath(__DIR__ . '/../../' . $admin_rec['id_image']);
            if ($img_path && file_exists($img_path)) {
                unlink($img_path);
            }
        }
        // Purge review queue row (encrypted blob cleared automatically)
        $conn->query("DELETE FROM id_review_queue WHERE account_type = 'admin' AND account_id = $admin_review_id");

        $mailerPath = $phpRoot . DIRECTORY_SEPARATOR . 'mailer.php';
        $vendorAutoload = dirname($phpRoot) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!empty($a_email) && file_exists($mailerPath) && file_exists($vendorAutoload)) {
            require_once $mailerPath;
            sendApprovalEmail($a_email, $a_name);
        }

        log_admin_action($conn, $admin_id, 'admin_approved', $a_name, 'Admin ID: ' . $admin_review_id);
        $message = 'approved';
    } elseif ($action === 'admin_reject') {
        // Delete ID image file from disk if it exists
        if (!empty($admin_rec['id_image'])) {
            $img_path = realpath(__DIR__ . '/../../' . $admin_rec['id_image']);
            if ($img_path && file_exists($img_path)) {
                unlink($img_path);
            }
        }
        $conn->query("DELETE FROM admin_login_logs WHERE admin_id = $admin_review_id");
        $conn->query("DELETE FROM id_review_queue WHERE account_type = 'admin' AND account_id = $admin_review_id");
        $stmt = $conn->prepare('DELETE FROM admins WHERE id = ?');
        $stmt->bind_param('i', $admin_review_id);
        $stmt->execute();
        $stmt->close();

        log_admin_action($conn, $admin_id, 'admin_rejected', $a_name, 'Admin rejected on review');
        header('Location: admin-faculty-management.php');
        exit;
    }

    // Refresh admin record after approve
    $stmt = $conn->prepare("SELECT id, first_name, last_name, middle_initial, email, is_verified, approved_by, created_at FROM admins WHERE id = ?");
    $stmt->bind_param('i', $admin_review_id);
    $stmt->execute();
    $admin_rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Review – LumineSense</title>

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" href="../../images/logo.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/admin-common.css">
    <link rel="stylesheet" href="../../css/admin-faculty-review.css">
    <link rel="stylesheet" href="../../css/tooltip.css">
</head>

<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container px-4 py-4">

            <!-- Back button -->
            <div class="mb-3 text-center">
                <button onclick="dissolve('admin-faculty-management.php?tab=pending-approvals')" class="light w-auto">
                    <i class="bi bi-arrow-left me-1"></i> Back to Faculty Management
                </button>
            </div>

            <?php
            $toast_msg = '';
            $toast_class = '';
            if ($message === 'approved') {
                $toast_msg = '✅ Admin account approved!';
                $toast_class = 'show';
            }
            ?>

            <div class="review-card">
                <!-- Header -->
                <div class="review-header d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0 fw-bold">
                            <?= htmlspecialchars($admin_rec['first_name'] . ' ' .
                                ($admin_rec['middle_initial'] ? $admin_rec['middle_initial'] . '. ' : '') .
                                $admin_rec['last_name']) ?>
                        </h2>
                        <small class="text-white-50"><?= htmlspecialchars($admin_rec['email']) ?></small>
                    </div>
                    <div>
                        <?php if ($admin_rec['approved_by']): ?>
                            <span class="status-badge status-badge-approved bold">
                                <i class="bi bi-check-circle me-1"></i> Approved
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-badge-pending bold">
                                Pending
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="review-body">
                    <div class="row g-4">

                        <!-- LEFT: ID Image -->
                        <div class="col-md-5">
                            <h6 class="fw-bold mb-2">Uploaded ID</h6>
                            <div class="id-image-box">
                                <?php if (!empty($admin_rec['id_image']) && file_exists(__DIR__ . '/../../' . $admin_rec['id_image'])): ?>
                                    <img src="../../<?= htmlspecialchars($admin_rec['id_image']) ?>" alt="Admin ID"
                                        onclick="openImageModal(this.src)">
                                <?php elseif ($queue_id): ?>
                                    <img src="../../api/review-id.php?queue_id=<?= $queue_id ?>" alt="Admin ID"
                                        onclick="openImageModal(this.src)"
                                        onerror="this.onerror=null;this.closest('.id-image-box').innerHTML='<p class=\'text-muted small\'>ID image unavailable (expired or purged).</p>'">
                                <?php else: ?>
                                    <p class="text-muted small">No ID image uploaded.</p>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted d-block mt-1 text-center">
                                Click image to enlarge
                            </small>
                        </div>

                        <!-- RIGHT: Info + AI Result -->
                        <div class="col-md-7">
                            <h6 class="fw-bold mb-2">Administrator Information</h6>
                            <div class="mb-3">
                                <div class="info-row">
                                    <span class="info-label">Full Name (Typed)</span>
                                    <span class="info-value">
                                        <?= htmlspecialchars($admin_rec['first_name'] . ' ' . $admin_rec['last_name']) ?>
                                    </span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?= htmlspecialchars($admin_rec['email']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Registered</span>
                                    <span class="info-value">
                                        <?= date('M j, Y g:i A', strtotime($admin_rec['created_at'])) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- AI Result -->
                            <?php if ($queue_id): ?>
                            <h6 class="fw-bold mb-2">API Verification Result</h6>
                            <div class="mb-3">
                                <?php
                                $badge_class = match ($ai_status) {
                                    'matched' => 'ai-matched',
                                    'mismatched' => 'ai-mismatched',
                                    default => 'ai-unreadable'
                                };
                                $badge_icon = match ($ai_status) {
                                    'matched' => '✅',
                                    'mismatched' => '⚠️',
                                    default => '❌'
                                };
                                $badge_text = match ($ai_status) {
                                    'matched' => 'Name Matched',
                                    'mismatched' => 'Name Mismatch',
                                    default => 'Unreadable ID'
                                };
                                ?>
                                <span class="ai-badge <?= $badge_class ?>">
                                    <?= $badge_icon ?> <?= $badge_text ?>
                                </span>

                                <?php if (!empty($ai_extracted_name)): ?>
                                    <div class="info-row mt-2">
                                        <span class="info-label">Name on ID (API Read)</span>
                                        <span class="info-value">
                                            <?= htmlspecialchars($ai_extracted_name) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($ai_confidence_note)): ?>
                                    <div class="mt-2 p-2 rounded" style="background:#f8f9fa; font-size:.85rem; color:#555;">
                                        <i class="bi bi-robot me-1"></i>
                                        <?= htmlspecialchars($ai_confidence_note) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Action buttons -->
                            <?php if (!$admin_rec['approved_by']): ?>
                                <div class="d-flex gap-2 mt-3">
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="action" value="admin_approve">
                                        <button type="submit" class="medium px-4 w-auto" title="Approve Admin"
                                            data-bs-toggle="tooltip" data-bs-placement="auto">
                                            <i class="bi bi-check-lg me-1"></i> Approve
                                        </button>
                                    </form>
                                    <button type="submit" class="light px-4 w-auto" title="Reject Admin"
                                        onclick="openRejectModal()" data-bs-toggle="tooltip" data-bs-placement="auto">
                                        <i class="bi bi-x-lg me-1"></i> Reject
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image enlarge modal -->
    <div class="toast-wrap">
        <div class="toast-msg <?= $toast_class ?>" id="toastMsg"><?= $toast_msg ?></div>
    </div>

    <div id="imgModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.85);
         z-index:9999; align-items:center; justify-content:center;" onclick="closeImageModal()">
        <img id="imgModalSrc" src="" style="max-width:90vw; max-height:90vh; border-radius:8px;">
    </div>

    <!-- Reject Admin Warning Modal -->
    <div class="modal fade" id="rejectAdminModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Reject Administrator</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#c0392b;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        Are you sure you want to reject
                        <strong><?= htmlspecialchars($admin_rec['first_name'] . ' ' . $admin_rec['last_name']) ?></strong>?
                        This will permanently delete this admin record.
                    </p>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="admin_reject">
                    <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium" style="background:#c0392b;">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/tooltip.js"></script>

    <script>
        function openImageModal(src) {
            document.getElementById('imgModalSrc').src = src;
            document.getElementById('imgModal').style.display = 'flex';
        }

        function closeImageModal() {
            document.getElementById('imgModal').style.display = 'none';
        }

        function openRejectModal() {
            new bootstrap.Modal(document.getElementById('rejectAdminModal')).show();
        }
    </script>
</body>

</html>
