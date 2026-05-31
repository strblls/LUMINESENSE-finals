<?php
$page_title = "Faculty Review";
require_once '../../php/includes/admin-head.php';

$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/handlers/admin-handlers.php';

// ── Get faculty id from URL ───────────────────────────────────────────────
$faculty_id = (int)($_GET['id'] ?? 0);
if (!$faculty_id) {
    header('Location: admin-faculty-management.php');
    exit;
}

// ── Fetch faculty record ──────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, first_name, last_name, middle_initial, email,
           is_verified, approved_by, faculty_id,
           id_image, ai_match_status, ai_extracted_name, ai_confidence_note,
           created_at
    FROM faculty
    WHERE id = ?
");
$stmt->bind_param('i', $faculty_id);
$stmt->execute();
$faculty = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$faculty) {
    header('Location: admin-faculty-management.php');
    exit;
}

// ── Handle approve / reject from this page ────────────────────────────────
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    $f_name  = $faculty['first_name'] . ' ' . $faculty['last_name'];
    $f_email = $faculty['email'];

    if ($action === 'approve') {
        $generated_faculty_id = 'F-' . str_pad($faculty_id, 3, '0', STR_PAD_LEFT) . '-' . date('Y');

        $stmt = $conn->prepare('UPDATE faculty SET approved_by = ?, approved_at = NOW(), faculty_id = ? WHERE id = ?');
        $stmt->bind_param('isi', $admin_id, $generated_faculty_id, $faculty_id);
        $stmt->execute();
        $stmt->close();

        if (!empty($f_email) && file_exists($phpRoot . '/mailer.php')) {
            require_once $phpRoot . '/mailer.php';
            sendApprovalEmail($f_email, $f_name);
        }

        log_admin_action($conn, $admin_id, 'faculty_approved', $f_name, 'Faculty ID: ' . $generated_faculty_id);
        $message = 'approved';

    } elseif ($action === 'reject') {
        $stmt = $conn->prepare('DELETE FROM faculty WHERE id = ?');
        $stmt->bind_param('i', $faculty_id);
        $stmt->execute();
        $stmt->close();

        // Delete uploaded ID image too
        if (!empty($faculty['id_image'])) {
            $img_path = realpath(__DIR__ . '/../../' . $faculty['id_image']);
            if ($img_path && file_exists($img_path)) unlink($img_path);
        }

        log_admin_action($conn, $admin_id, 'faculty_rejected', $f_name, 'Rejected on review');
        header('Location: admin-faculty-management.php');
        exit;
    }

    // Refresh faculty record after approve
    $stmt = $conn->prepare("SELECT * FROM faculty WHERE id = ?");
    $stmt->bind_param('i', $faculty_id);
    $stmt->execute();
    $faculty = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Review – LumineSense</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">

    <style>
        .review-card {
            max-width: 720px;
            margin: 0 auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            overflow: hidden;
        }
        .review-header {
            background: #1a1a2e;
            padding: 24px 32px;
            color: #fff;
        }
        .review-body { padding: 32px; }
        .id-image-box {
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            background: #f8f9fa;
            text-align: center;
            min-height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .id-image-box img {
            max-width: 100%;
            max-height: 300px;
            object-fit: contain;
            cursor: pointer;
        }
        .ai-badge {
            font-size: .85rem;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }
        .ai-matched    { background: #d1e7dd; color: #0f5132; }
        .ai-mismatched { background: #fff3cd; color: #664d03; }
        .ai-unreadable { background: #f8d7da; color: #842029; }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: .92rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #888; font-weight: 500; }
        .info-value { font-weight: 600; color: #1a1a2e; }
    </style>
</head>
<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container px-4 py-4">

            <!-- Back button -->
            <div class="mb-3">
                <a onclick="dissolve('admin-faculty-management.php')"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i> Back to Faculty Management
                </a>
            </div>

            <?php if ($message === 'approved'): ?>
                <div class="alert alert-success mb-3">
                    ✅ Faculty member approved! Faculty ID: <strong><?= htmlspecialchars($faculty['faculty_id']) ?></strong>. Approval email sent.
                </div>
            <?php endif; ?>

            <div class="review-card">
                <!-- Header -->
                <div class="review-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bold">
                            <?= htmlspecialchars($faculty['first_name'] . ' ' . 
                                ($faculty['middle_initial'] ? $faculty['middle_initial'] . '. ' : '') . 
                                $faculty['last_name']) ?>
                        </h5>
                        <small class="text-white-50"><?= htmlspecialchars($faculty['email']) ?></small>
                    </div>
                    <div>
                        <?php if ($faculty['approved_by']): ?>
                            <span class="badge bg-success fs-6">
                                <i class="bi bi-check-circle me-1"></i> Approved
                            </span>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark fs-6">
                                <i class="bi bi-hourglass-split me-1"></i> Pending
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
                                <?php if (!empty($faculty['id_image'])): ?>
                                    <img src="../../<?= htmlspecialchars($faculty['id_image']) ?>"
                                         alt="Faculty ID"
                                         onclick="openImageModal(this.src)">
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
                            <h6 class="fw-bold mb-2">Faculty Information</h6>
                            <div class="mb-3">
                                <div class="info-row">
                                    <span class="info-label">Full Name (Typed)</span>
                                    <span class="info-value">
                                        <?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?>
                                    </span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?= htmlspecialchars($faculty['email']) ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Registered</span>
                                    <span class="info-value">
                                        <?= date('M j, Y g:i A', strtotime($faculty['created_at'])) ?>
                                    </span>
                                </div>
                                <?php if ($faculty['faculty_id']): ?>
                                <div class="info-row">
                                    <span class="info-label">Faculty ID</span>
                                    <span class="info-value text-primary">
                                        <?= htmlspecialchars($faculty['faculty_id']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- AI Result -->
                            <h6 class="fw-bold mb-2">AI Verification Result</h6>
                            <div class="mb-3">
                                <?php
                                $status = $faculty['ai_match_status'] ?? 'unreadable';
                                $badge_class = match($status) {
                                    'matched'    => 'ai-matched',
                                    'mismatched' => 'ai-mismatched',
                                    default      => 'ai-unreadable'
                                };
                                $badge_icon = match($status) {
                                    'matched'    => '✅',
                                    'mismatched' => '⚠️',
                                    default      => '❌'
                                };
                                $badge_text = match($status) {
                                    'matched'    => 'Name Matched',
                                    'mismatched' => 'Name Mismatch',
                                    default      => 'Unreadable ID'
                                };
                                ?>
                                <span class="ai-badge <?= $badge_class ?>">
                                    <?= $badge_icon ?> <?= $badge_text ?>
                                </span>

                                <?php if (!empty($faculty['ai_extracted_name'])): ?>
                                    <div class="info-row mt-2">
                                        <span class="info-label">Name on ID (AI Read)</span>
                                        <span class="info-value">
                                            <?= htmlspecialchars($faculty['ai_extracted_name']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($faculty['ai_confidence_note'])): ?>
                                    <div class="mt-2 p-2 rounded" style="background:#f8f9fa; font-size:.85rem; color:#555;">
                                        <i class="bi bi-robot me-1"></i>
                                        <?= htmlspecialchars($faculty['ai_confidence_note']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Action buttons -->
                            <?php if (!$faculty['approved_by']): ?>
                                <div class="d-flex gap-2 mt-3">
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-success px-4">
                                            <i class="bi bi-check-lg me-1"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" class="mb-0"
                                          onsubmit="return confirm('Reject and permanently delete this faculty record?')">
                                        <input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-outline-danger px-4">
                                            <i class="bi bi-x-lg me-1"></i> Reject
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Image enlarge modal -->
    <div id="imgModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.85);
         z-index:9999; align-items:center; justify-content:center;" onclick="closeImageModal()">
        <img id="imgModalSrc" src="" style="max-width:90vw; max-height:90vh; border-radius:8px;">
    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script>
        function openImageModal(src) {
            document.getElementById('imgModalSrc').src = src;
            document.getElementById('imgModal').style.display = 'flex';
        }
        function closeImageModal() {
            document.getElementById('imgModal').style.display = 'none';
        }
    </script>
</body>
</html>