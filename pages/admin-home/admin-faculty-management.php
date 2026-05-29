<?php
$page_title = "Faculty Management";
require_once '../../php/includes/admin-head.php';
/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
/** @var int $admin_id */

$phpRoot = realpath(__DIR__ . '/../../php');
require_once $phpRoot . '/handlers/faculty-approvals-handler.php';
require_once $phpRoot . '/handlers/admin-handlers.php';

/** @var string $message */
/** @var int $total_faculty */
/** @var int $pending_count */
/** @var int $ext_pending */
/** @var array $faculty_list */
/** @var array $extensions */

require_once '../../php/handlers/admin-handlers.php';
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Management & Approvals</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
    .toast-wrap {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 9999;
    }
    .toast-msg {
        background: var(--secondary-color-1);
        color: #fff;
        padding: 12px 20px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        box-shadow: 0 6px 20px rgba(0,0,0,.25);
        display: none;
    }
    .toast-msg.show {
        display: block;
        animation: fadeInUp 0.3s ease, fadeOut 0.4s ease 2.2s forwards;
    }
    @keyframes fadeInUp {
        from { opacity:0; transform:translateY(12px); }
        to   { opacity:1; transform:translateY(0); }
    }
    @keyframes fadeOut { to { opacity:0; } }
</style>
</head>
<body class="contrast-bg">
    <?php include '../../php/includes/admin-topbar.php'; ?>

    <?php if (!empty($message)): ?>
        <div class="toast-wrap"><div class="toast-msg show" id="toastMsg"><?= htmlspecialchars($message) ?></div></div>
    <?php else: ?>
        <div class="toast-wrap"><div class="toast-msg" id="toastMsg"></div></div>
    <?php endif; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container px-4 py-4">
            <div class="row g-4 mb-4">
                
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm p-4 bg-white h-100">
                        <h5 class="bold mb-3 text-warning"><i class="fa-solid fa-user-clock me-2"></i> Registration Approvals Pending</h5>
                        <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
                            <?php 
                            $has_pending = false;
                            foreach ($faculty_list as $faculty): 
                                if ($faculty['status_label'] === 'pending'): 
                                    $has_pending = true;
                            ?>
                                <div class="d-flex align-items-center justify-content-between p-3 mb-2 border border-warning-subtle rounded bg-warning-subtle bg-opacity-10">
                                    <div>
                                        <h6 class="bold mb-0"><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></h6>
                                        <span class="text-muted small"><?= htmlspecialchars($faculty['email']) ?></span>
                                    </div>
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-sm btn-success px-3"><i class="fa-solid fa-check me-1"></i> Approve</button>
                                    </form>
                                </div>
                            <?php 
                                endif; 
                            endforeach; 
                            if (!$has_pending): 
                            ?>
                                <p class="text-muted text-center py-4 small">No pending registrations require attention right now.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm p-4 bg-white h-100">
                        <h5 class="bold mb-3 text-info"><i class="bi bi-clock-history me-2"></i> Schedule Extensions Pending</h5>
                        <div class="style-scrollbar" style="max-height: 300px; overflow-y: auto;">
                            <?php 
                            $has_ext = false;
                            foreach ($extensions as $ext): 
                                if ($ext['status'] === 'pending'): 
                                    $has_ext = true;
                            ?>
                                <div class="p-3 border rounded mb-2 bg-light">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="bold mb-0 text-dark"><?= htmlspecialchars($ext['faculty_name']) ?></h6>
                                        <span class="badge bg-info text-dark">+<?= $ext['extend_mins'] ?> mins</span>
                                    </div>
                                    <p class="text-secondary small mb-2">
                                        <?= $ext['room_name'] ?> · <?= $ext['day_of_week'] ?> · 
                                        <?= date('g:i A', strtotime($ext['start_time'])) ?> – 
                                        <?= date('g:i A', strtotime($ext['end_time'])) ?>
                                    </p>
                                    <div class="d-flex gap-2 justify-content-end">
                                        <form method="POST" class="mb-0">
                                            <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_reject">
                                            <button type="submit" class="btn btn-xs btn-outline-danger py-1 px-2">Deny</button>
                                        </form>
                                        <form method="POST" class="mb-0">
                                            <input type="hidden" name="extension_id" value="<?= $ext['id'] ?>"><input type="hidden" name="action" value="ext_approve">
                                            <button type="submit" class="btn btn-xs btn-primary py-1 px-2">Grant</button>
                                        </form>
                                    </div>
                                </div>
                            <?php 
                                endif; 
                            endforeach; 
                            if (!$has_ext): 
                            ?>
                                <p class="text-muted text-center py-4 small">No allocation adjustments are currently requested.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm p-4 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="bold mb-0"><i class="fa-solid fa-users text-primary me-2"></i> Complete Faculty Directory</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active" onclick="filterList('all')">All Records</button>
                        <button type="button" class="btn btn-outline-success" onclick="filterList('approved')">Approved</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="filterList('unverified')">Unverified</button>
                    </div>
                </div>

                <div class="style-scrollbar" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($faculty_list)): ?>
                        <p class="text-muted text-center py-4">No records found inside the active index.</p>
                    <?php else: foreach ($faculty_list as $faculty): ?>
                        <div class="faculty-list-item d-flex align-items-center justify-content-between p-3 mb-2 border rounded" data-status="<?= $faculty['status_label'] ?>">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center text-secondary bold" style="width: 40px; height: 40px;">
                                    <?= strtoupper(substr($faculty['first_name'], 0, 1) . substr($faculty['last_name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h6 class="bold mb-0"><?= htmlspecialchars($faculty['first_name'] . ' ' . $faculty['last_name']) ?></h6>
                                    <span class="text-muted small"><?= htmlspecialchars($faculty['email']) ?></span>
                                </div>
                                <div>
                                    <a href="admin-faculty-card.php?id=<?= $faculty['id'] ?>"
                                    class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-eye me-1"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="d-flex align-items-center gap-3">
                                <?php if ($faculty['status_label'] === 'approved'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="fa-solid fa-circle-check me-1"></i> Approved Account</span>
                                    <form method="POST" class="mb-0">
                                        <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>"><input type="hidden" name="action" value="revoke">
                                        <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none small p-0">Revoke Access</button>
                                    </form>
                                <?php elseif ($faculty['status_label'] === 'pending'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">Awaiting Approval</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1">Email Pending Verification</span>
                                <?php endif; ?>

                                <form method="POST" class="mb-0" onsubmit="return confirm('Permanently wipe this record?');">
                                    <input type="hidden" name="faculty_id" value="<?= $faculty['id'] ?>"><input type="hidden" name="action" value="delete">
                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fa-regular fa-trash-can"></i></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div> </div>
    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const toast = document.getElementById('toastMsg');
            if(toast && toast.classList.contains('show')) {
                setTimeout(() => toast.classList.remove('show'), 3500);
            }
        });

        function filterList(status) {
            const buttons = document.querySelectorAll('.btn-group button');
            buttons.forEach(btn => btn.classList.remove('active'));
            event.currentTarget.classList.add('active');

            document.querySelectorAll('.faculty-list-item').forEach(item => {
                if (status === 'all' || item.dataset.status === status) {
                    item.style.setProperty('display', 'flex', 'important');
                } else {
                    item.style.setProperty('display', 'none', 'important');
                }
            });
        }
    </script>
</body>
</html>