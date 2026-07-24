<?php
$page_title = 'Profile Settings';
require_once '../../php/includes/admin-head.php';
require_once __DIR__ . '/../../php/handlers/admin-handlers.php';
/** @var string $admin_name  
 * @var string $admin_email
 * @var string $initials
 * @var int    $admin_id
 */

// ── Handle flash messages from redirects ──────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── Check if seeded admin ─────────────────────────────────────────────────
$admin_is_seeded = false;
$seed_check = $conn->query("SELECT is_seeded FROM admins WHERE id = " . (int)$admin_id);
if ($seed_check && $seed_row = $seed_check->fetch_assoc()) {
    $admin_is_seeded = !empty($seed_row['is_seeded']);
}

// ── Fetch pending flush schedule (if any) ──────────────────────────────────
$flush_schedule = null;
if ($admin_is_seeded) {
    $fs = $conn->query("SELECT * FROM flush_schedules WHERE created_by = $admin_id AND executed = 0 ORDER BY id DESC LIMIT 1");
    if ($fs) $flush_schedule = $fs->fetch_assoc();
}

// ── Fetch pending extension reset (independent of system flush) ────────────
$extension_reset_dt = null;
if ($admin_is_seeded) {
    $er = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'extension_reset_datetime'");
    if ($er && $row = $er->fetch_assoc()) {
        $extension_reset_dt = $row['setting_value'];
    }
}

// ── Check if in confirmation window ────────────────────────────────────────
$in_confirmation_window = false;
$days_remaining = 0;
if ($flush_schedule && !$flush_schedule['confirmed']) {
    $scheduled_ts = strtotime($flush_schedule['scheduled_datetime']);
    $now_ts = time();
    $seven_days_before = $scheduled_ts - (7 * 86400);
    if ($now_ts >= $seven_days_before && $now_ts < $scheduled_ts) {
        $in_confirmation_window = true;
        $days_remaining = max(1, floor(($scheduled_ts - $now_ts) / 86400));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile Settings</title>

    <!-- External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Relative links -->
    <link rel="icon" href="../../images/icon.png">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">
    <link rel="stylesheet" href="../../css/admin-common.css">
    <link rel="stylesheet" href="../../css/admin-settings.css">
    <link rel="stylesheet" href="../../css/admin-profile-settings.css">
</head>

<body class="contrast-bg">

    <!-- Toast -->
    <div class="toast-wrap">
        <div class="toast-msg <?= $flash && $flash['type'] === 'error' ? 'error' : '' ?> <?= $flash ? 'show' : '' ?>" id="toastMsg">
            <?= htmlspecialchars($flash['msg'] ?? '') ?>
        </div>
    </div>

    <?php include '../../php/includes/admin-topbar.php'; ?>

    <div class="parent-container">
        <?php include '../../php/includes/admin-sidebar.php'; ?>

        <div class="child-container homepage-modal">
            <div class="profile-wrapper">
                <div class="profile-main-card" style="border-radius: 10px;">
                    <div class="profile-layout">

                        <!-- Sidebar -->
                        <div class="profile-sidebar">
                            <div class="sidebar-item active" data-section="contact">
                                <i class="bi bi-person-lines-fill"></i> Contact Information
                            </div>
                            <?php if (!$admin_is_seeded): ?>
                            <div class="sidebar-item" data-section="credentials">
                                <i class="bi bi-shield-lock"></i> Change Credentials
                            </div>
                            <?php endif; ?>
                            <?php if ($admin_is_seeded): ?>
                            <div class="sidebar-item" data-section="flush">
                                <i class="bi bi-exclamation-triangle-fill"></i> System Flush
                            </div>
                            <?php endif; ?>
                            <div class="sidebar-item" data-section="about">
                                <i class="bi bi-info-circle"></i> About System
                            </div>
                        </div>

                        <!-- Content Area -->
                        <div class="profile-content-area">

                            <!-- Contact Section (default) -->
                            <div id="section-contact" class="section-content active">
                                <div class="profile-header">
                                    <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                                    <div class="profile-user">
                                        <h2 class="bold mb-1"><?= htmlspecialchars($admin_name) ?></h2>
                                        <span class="bold status-badge admin">Administrator</span>
                                    </div>
                                </div>
                                <hr>
                                <div class="info-card">
                                    <div class="info-card-header d-flex align-items-start justify-content-between">
                                        <h3 class="bold mb-0">Contact Information</h3>
                                        <button class="info-action-btn"
                                            data-bs-toggle="modal" data-bs-target="#editContactModal">
                                            <i class="bi bi-pencil me-1"></i> Edit
                                        </button>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Full Name</span>
                                        <div class="field-value" id="displayName"><?= htmlspecialchars($admin_name) ?></div>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Email Address</span>
                                        <div class="field-value" id="displayEmail"><?= htmlspecialchars($admin_email) ?></div>
                                    </div>
                                    <div class="info-field">
                                        <span class="label">Role</span>
                                        <div class="field-value">Administrator</div>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$admin_is_seeded): ?>
                            <!-- Change Credentials Section -->
                            <div id="section-credentials" class="section-content">
                                <div class="info-card">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-3">Change Password</h3>
                                    </div>
                                    <?php if (!empty($_SESSION['pw_success'])): ?>
                                        <div class="alert alert-success">&#9989; <?= htmlspecialchars($_SESSION['pw_success']) ?>
                                        </div>
                                        <?php unset($_SESSION['pw_success']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($_SESSION['pw_error'])): ?>
                                        <div class="alert alert-danger">&#9888;&#65039; <?= htmlspecialchars($_SESSION['pw_error']) ?></div>
                                        <?php unset($_SESSION['pw_error']); ?>
                                    <?php endif; ?>

                                    <!-- OTP trigger button -->
                                    <button type="button" class="light info-action-btn w-100 mb-2" data-bs-toggle="modal" data-bs-target="#adminChangePwOtpModal">
                                        Change Password
                                    </button>

                                    <!-- Password form — hidden until OTP verified -->
                                    <form id="adminPwChangeForm" method="POST" action="../../api/change_password.php" style="display:none;">
                                        <div class="mb-2">
                                            <label class="form-label">New Password</label>
                                            <input type="password" class="form-control" name="new_password"
                                                placeholder="Min 8 characters" minlength="8" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Confirm New Password</label>
                                            <input type="password" class="form-control" name="confirm_password"
                                                placeholder="Repeat new password" required>
                                        </div>
                                        <button type="submit" class="light info-action-btn w-100">
                                            Save Password
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if ($admin_is_seeded): ?>
                            <!-- ═══ SYSTEM FLUSH SECTION ═══ -->
                            <div id="section-flush" class="section-content">
                                <?php if ($flush_schedule && $in_confirmation_window && !$flush_schedule['confirmed']): ?>
                                <!-- Confirmation Prompt (7-day window) -->
                                <div class="flush-confirm-card" id="flushConfirmBanner">
                                    <div class="d-flex align-items-start gap-3">
                                        <i class="bi bi-bell-fill" style="font-size:1.5rem;color:#dc3545;flex-shrink:0;"></i>
                                        <div class="flex-fill">
                                            <h5 class="bold mb-2">Confirmation Required</h5>
                                            <p class="mb-1">The system flush is scheduled for <strong><?= date('F j, Y g:i A', strtotime($flush_schedule['scheduled_datetime'])) ?></strong>.</p>
                                            <p class="mb-3"><strong><?= $days_remaining ?> day(s)</strong> remaining to confirm.</p>
                                            <div class="d-flex gap-2">
                                                <?php if ($days_remaining > 2): ?>
                                                <button class="info-action-btn" onclick="dismissReminder(<?= $flush_schedule['id'] ?>)">
                                                    <i class="bi bi-check2 me-1"></i> Understood
                                                </button>
                                                <?php endif; ?>
                                                <button class="medium w-auto px-3" onclick="confirmFlush(<?= $flush_schedule['id'] ?>)">
                                                    <i class="bi bi-check-lg me-1"></i> Confirm Flush
                                                </button>
                                                <button class="light w-auto px-3" onclick="cancelFlush(<?= $flush_schedule['id'] ?>)">
                                                    <i class="bi bi-x-lg me-1"></i> Cancel Flush
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if ($flush_schedule): ?>
                                <!-- State B: Flush Scheduled -->
                                <div id="flushStatusCard" class="flush-status-card">
                                    <div class="flush-status-header">
                                        <h4 class="bold mb-0"><i class="bi bi-clock-history me-2"></i>Scheduled Flush</h4>
                                        <span class="flush-badge <?= $flush_schedule['confirmed'] ? 'confirmed' : 'pending' ?>">
                                            <?= $flush_schedule['confirmed'] ? 'Confirmed' : 'Awaiting Confirmation' ?>
                                        </span>
                                    </div>
                                    <div class="flush-countdown">
                                        <div class="countdown-item">
                                            <span class="countdown-label">Scheduled Date</span>
                                            <span class="countdown-value"><?= date('F j, Y', strtotime($flush_schedule['scheduled_datetime'])) ?></span>
                                        </div>
                                        <div class="countdown-item">
                                            <span class="countdown-label">Scheduled Time</span>
                                            <span class="countdown-value"><?= date('g:i A', strtotime($flush_schedule['scheduled_datetime'])) ?></span>
                                        </div>
                                        <div class="countdown-item">
                                            <span class="countdown-label">Time Until Flush</span>
                                            <span class="countdown-value" id="flushCountdown"></span>
                                        </div>
                                    </div>
                                    <div class="flush-items-list">
                                        <h5 class="bold mb-2">Items to be Flushed:</h5>
                                        <div class="flush-item checked"><i class="bi bi-check-lg"></i> All faculty schedules</div>

                                        <div class="flush-item <?= $flush_schedule['flush_departments'] ? 'checked' : 'unchcked' ?>">
                                            <i class="bi <?= $flush_schedule['flush_departments'] ? 'bi-check-lg' : 'bi-dash-lg' ?>"></i>
                                            Departments <?= $flush_schedule['flush_departments'] ? '(includes subject areas &amp; subjects)' : '' ?>
                                        </div>
                                        <div class="flush-item <?= $flush_schedule['flush_subject_areas'] ? 'checked' : 'unchcked' ?>">
                                            <i class="bi <?= $flush_schedule['flush_subject_areas'] ? 'bi-check-lg' : 'bi-dash-lg' ?>"></i>
                                            Subject Areas <?= $flush_schedule['flush_subject_areas'] ? '(includes subjects)' : '' ?>
                                        </div>
                                        <div class="flush-item <?= $flush_schedule['flush_subjects'] ? 'checked' : 'unchcked' ?>">
                                            <i class="bi <?= $flush_schedule['flush_subjects'] ? 'bi-check-lg' : 'bi-dash-lg' ?>"></i>
                                            Subjects
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2 mt-3">
                                        <?php if (!$flush_schedule['confirmed']): ?>
                                        <button class="medium w-auto px-3" onclick="confirmFlush(<?= $flush_schedule['id'] ?>)">
                                            <i class="bi bi-check-lg me-1"></i> Confirm Now
                                        </button>
                                        <?php endif; ?>
                                        <button class="light w-auto px-3" onclick="cancelFlush(<?= $flush_schedule['id'] ?>)">
                                            <i class="bi bi-x-lg me-1"></i> Cancel Flush
                                        </button>
                                    </div>
                                </div>
                                <?php else: ?>
                                <!-- State A: No Flush Scheduled -->
                                <div id="flushSetupForm" class="flush-setup-card">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-0">Configure System Flush</h3>
                                        <div class="d-flex gap-2" style="position:relative;">
                                            <div class="hover-info-trigger">
                                                <i class="bi bi-info-circle"></i>
                                                <div class="hover-info-panel">
                                                    <strong>End-of-Semester System Flush</strong><br>
                                                    Schedule an automated reset of system data at the end of the semester.
                                                    All selected data will be permanently deleted on the scheduled date.
                                                </div>
                                            </div>
                                            <div class="hover-info-trigger">
                                                <i class="bi bi-layers-fill"></i>
                                                <div class="hover-info-panel">
                                                    <strong>Pyramid hierarchy:</strong> Departments → Subject Areas → Subjects.
                                                    Higher levels include lower levels.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <form id="flushForm">
                                    <div class="flush-options">
                                        <label class="flush-option disabled">
                                            <input type="checkbox" checked disabled>
                                            <div class="flush-option-text">
                                                <span class="flush-option-title">Flush All Schedules</span>
                                                <span class="flush-option-desc">All faculty class schedules will be permanently deleted</span>
                                            </div>
                                        </label>

                                            <label class="flush-option">
                                                <input type="checkbox" name="flush_departments" id="flushDepts" value="1" onchange="onFlushCascade()">
                                                <div class="flush-option-text">
                                                    <span class="flush-option-title">Flush All Departments</span>
                                                    <span class="flush-option-desc">Includes all subject areas and subjects under them</span>
                                                </div>
                                            </label>
                                            <label class="flush-option" id="flushSubjectAreasOption">
                                                <input type="checkbox" name="flush_subject_areas" id="flushSubjectAreas" value="1" onchange="onFlushCascade()">
                                                <div class="flush-option-text">
                                                    <span class="flush-option-title">Flush All Subject Areas</span>
                                                    <span class="flush-option-desc">Includes all subjects under them</span>
                                                </div>
                                            </label>
                                            <label class="flush-option" id="flushSubjectsOption">
                                                <input type="checkbox" name="flush_subjects" id="flushSubjects" value="1" onchange="onFlushCascade()">
                                                <div class="flush-option-text">
                                                    <span class="flush-option-title">Flush All Subjects</span>
                                                    <span class="flush-option-desc">Subject areas referencing these subjects will also be deleted</span>
                                                </div>
                                            </label>
                                        </div>
                                        <hr>
                                        <div class="flush-datetime-row">
                                            <div class="flush-datetime-field">
                                                <label class="form-label bold">Scheduled Date</label>
                                                <input type="date" class="form-control" id="flushDate"
                                                    min="<?= date('Y-m-d', strtotime('+5 months')) ?>"
                                                    onchange="onFlushDateChange()">
                                                <small class="text-muted">Minimum: <?= date('F j, Y', strtotime('+5 months')) ?></small>
                                            </div>
                                            <div class="flush-datetime-field">
                                                <label class="form-label bold">Scheduled Time</label>
                                                <input type="time" class="form-control" id="flushTime"
                                                    value="23:59">
                                                <small class="text-muted">When the flush executes</small>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-center pt-3">
                                        <button type="button" class="medium w-auto px-3" onclick="scheduleFlush()">
                                            <i class="bi bi-calendar-check me-2"></i>Schedule System Flush
                                        </button>
                                        </div>
                                    </form>
                                </div>

                                <!-- Reset All Extensions Card -->
                                <?php if ($extension_reset_dt): ?>
                                <!-- State: Extension reset already scheduled -->
                                <div class="flush-setup-card" style="margin-top: 1rem;">
                                    <div class="info-card-header">
                                        <h4 class="bold mb-0"><i class="bi bi-clock-history me-2"></i>Extension Reset Scheduled</h4>
                                    </div>
                                    <div class="flush-countdown">
                                        <div class="countdown-item">
                                            <span class="countdown-label">Scheduled Date</span>
                                            <span class="countdown-value"><?= date('F j, Y', strtotime($extension_reset_dt)) ?></span>
                                        </div>
                                        <div class="countdown-item">
                                            <span class="countdown-label">Scheduled Time</span>
                                            <span class="countdown-value"><?= date('g:i A', strtotime($extension_reset_dt)) ?></span>
                                        </div>
                                    </div>
                                    <p class="text-muted small mt-2 mb-0">All schedule extensions will be cleared at the scheduled time.</p>
                                </div>
                                <?php else: ?>
                                <!-- State: No extension reset scheduled -->
                                <div class="flush-setup-card" style="margin-top: 1rem;">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-0">Configure Extension Reset</h3>
                                    </div>
                                    <p class="text-muted small mb-3">
                                        All schedule extensions will be cleared at the scheduled date and time.
                                    </p>
                                    <div id="flushExtSubform">
                                        <div class="flush-ext-form-inner">
                                            <div class="flush-ext-field">
                                                <label class="form-label bold">Flush Extensions On</label>
                                                <input type="date" class="form-control" id="flushExtDate"
                                                    value="<?= date('Y-m-d', strtotime('next Saturday')) ?>">
                                            </div>
                                            <div class="flush-ext-field">
                                                <label class="form-label bold">At</label>
                                                <input type="time" class="form-control" id="flushExtTime" value="23:59">
                                            </div>
                                        </div>
                                        <small class="text-muted">Default: end of week (Saturday 11:59 PM)</small>
                                    </div>
                                    <div class="d-flex justify-content-center pt-3">
                                    <button type="button" class="medium w-auto px-3" onclick="scheduleExtensionsFlush()">
                                        <i class="bi bi-calendar-check me-2"></i>Schedule Extension Reset
                                    </button>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- About System Section -->
                            <div id="section-about" class="section-content">
                                <div class="info-card d-flex flex-column align-items-center text-center py-5">
                                    <img src="../../images/logo.png" alt="LumineSense Logo" style="max-width:200px;margin-bottom:1.5rem;">
                                    <h3 class="bold mb-1">LumineSense</h3>
                                    <p class="text-muted mb-4" style="font-size:14px;">Smart Classroom Management System</p>
                                    <img src="../../images/team-logo.png" alt="Team Logo" style="max-width:120px;margin-bottom:0.75rem;">
                                    <p class="text-muted mb-0" style="font-size:12px;">All Rights Reserved &copy; <?= date('Y') ?></p>
                                </div>
                                <div class="info-card mt-3">
                                    <div class="info-card-header">
                                        <h3 class="bold mb-0"><i class="bi bi-sliders me-2"></i>Preferences</h3>
                                    </div>
                                    <div class="info-field d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="label mb-0">Help Icon (Page Tutorials)</span>
                                            <p class="text-muted small mb-0">Show the help icon on admin pages to access step-by-step tutorials.</p>
                                        </div>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="tutorialToggle" checked>
                                        </div>
                                    </div>
                                </div>
                                <script>
                                (function() {
                                    var toggle = document.getElementById('tutorialToggle');
                                    var key = 'lum_tutorial_disabled';
                                    toggle.checked = localStorage.getItem(key) !== '1';
                                    toggle.addEventListener('change', function() {
                                        if (this.checked) {
                                            localStorage.removeItem(key);
                                        } else {
                                            localStorage.setItem(key, '1');
                                        }
                                    });
                                })();
                                </script>
                            </div>

                        </div><!-- /profile-content-area -->
                    </div><!-- /profile-layout -->
                </div><!-- /profile-main-card -->
            </div><!-- /profile-wrapper -->
        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <!-- ═══ EDIT CONTACT MODAL ═══ -->
    <div class="modal fade" id="editContactModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold">Edit Contact Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="../../php/handlers/admin-profile-handler.php">
                    <input type="hidden" name="action" value="update_contact">
                    <div class="modal-body d-flex flex-column gap-3">
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Full Name</label>
                            <input type="text" name="admin_name" class="form-control"
                                value="<?= htmlspecialchars($admin_name) ?>"
                                placeholder="Your full name" required>
                        </div>
                        <div>
                            <label class="form-label" style="font-size:.85rem;font-weight:600;">Email Address</label>
                            <input type="email" name="admin_email" class="form-control"
                                value="<?= htmlspecialchars($admin_email) ?>"
                                placeholder="your@email.com" required>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-end align-items-center gap-2">
                        <button type="button" class="light w-auto px-3" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium w-auto px-3">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <!-- ═══ FLUSH CONFIRMATION MODAL ═══ -->
    <div class="modal fade" id="flushConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="room-details-modal modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-warning">
                    <h5 class="modal-title">Confirm System Flush</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center p-4">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size:2.5rem;color:#c0004e;"></i>
                    <p class="mt-3 mb-0" style="font-size:15px;">
                        You are about to schedule a system flush for:<br>
                        <strong id="flushConfirmDate"></strong>
                    </p>
                    <hr>
                    <p class="small text-muted mb-0" id="flushConfirmSub"></p>
                </div>
                <div class="modal-footer d-flex flex-nowrap flex-row justify-content-between gap-2">
                    <button type="button" class="light w-auto px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="medium w-auto px-3" id="flushConfirmSubmit" style="background:#c0392b;">
                        <i class="bi bi-check-lg me-1"></i>Confirm &amp; Schedule
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- OTP Verification Modal -->
    <div class="modal fade" id="adminChangePwOtpModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold">Verify Identity</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <div id="adminOtpStepSend">
                        <p class="text-muted small">A verification code will be sent to your registered email.</p>
                        <button type="button" class="medium w-auto px-4" id="adminSendOtpBtn">Send Code</button>
                    </div>
                    <div id="adminOtpStepVerify" style="display:none;">
                        <p class="text-muted small">Enter the 6-digit code sent to your email.</p>
                        <input type="text" id="adminOtpInput" class="form-control text-center mx-auto mb-2"
                            maxlength="6" pattern="\d{6}" inputmode="numeric"
                            placeholder="000000" style="font-size:1.5rem;letter-spacing:8px;max-width:200px;" autocomplete="off">
                        <div id="adminOtpFeedback" class="small mb-2"></div>
                        <button type="button" class="medium w-auto px-4" id="adminVerifyOtpBtn">Verify</button>
                        <br>
                        <button type="button" class="btn btn-link btn-sm text-muted mt-1" id="adminResendChangeOtpBtn">Resend Code</button>
                    </div>
                    <div id="adminOtpStepSuccess" style="display:none;">
                        <p class="text-success"><i class="bi bi-check-circle-fill"></i> Verified!</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // OTP verification flow for admin password change
    (function() {
        var modal = document.getElementById('adminChangePwOtpModal');
        var stepSend = document.getElementById('adminOtpStepSend');
        var stepVerify = document.getElementById('adminOtpStepVerify');
        var stepSuccess = document.getElementById('adminOtpStepSuccess');
        var sendBtn = document.getElementById('adminSendOtpBtn');
        var verifyBtn = document.getElementById('adminVerifyOtpBtn');
        var otpInput = document.getElementById('adminOtpInput');
        var feedback = document.getElementById('adminOtpFeedback');
        var resendBtn = document.getElementById('adminResendChangeOtpBtn');
        var pwForm = document.getElementById('adminPwChangeForm');
        var cooldown = 0;

        function resetModal() {
            stepSend.style.display = 'block';
            stepVerify.style.display = 'none';
            stepSuccess.style.display = 'none';
            otpInput.value = '';
            feedback.textContent = '';
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Code';
        }

        modal.addEventListener('hidden.bs.modal', function() {
            if (stepSuccess.style.display !== 'block') resetModal();
        });

        modal.addEventListener('shown.bs.modal', function() {
            resetModal();
        });

        sendBtn.addEventListener('click', function() {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            fetch('../../api/send-change-otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        stepSend.style.display = 'none';
                        stepVerify.style.display = 'block';
                        cooldown = 60;
                        tickResend();
                    } else {
                        feedback.textContent = d.message || 'Failed to send.';
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send Code';
                    }
                })
                .catch(function() {
                    feedback.textContent = 'Network error.';
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send Code';
                });
        });

        function tickResend() {
            if (cooldown <= 0) {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend Code';
                return;
            }
            resendBtn.disabled = true;
            resendBtn.textContent = 'Resend Code (' + cooldown + 's)';
            cooldown--;
            setTimeout(tickResend, 1000);
        }

        resendBtn.addEventListener('click', function() {
            resendBtn.disabled = true;
            resendBtn.textContent = 'Sending...';
            fetch('../../api/send-change-otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        cooldown = 60;
                        tickResend();
                        feedback.textContent = 'Code resent.';
                        feedback.className = 'small mb-2 text-success';
                    } else {
                        feedback.textContent = d.message || 'Failed.';
                        feedback.className = 'small mb-2 text-danger';
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'Resend Code';
                    }
                })
                .catch(function() {
                    feedback.textContent = 'Network error.';
                    feedback.className = 'small mb-2 text-danger';
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                });
        });

        verifyBtn.addEventListener('click', function() {
            var otp = otpInput.value.trim();
            if (!/^\d{6}$/.test(otp)) {
                feedback.textContent = 'Enter a valid 6-digit code.';
                feedback.className = 'small mb-2 text-danger';
                return;
            }
            verifyBtn.disabled = true;
            verifyBtn.textContent = 'Verifying...';
            var body = new URLSearchParams();
            body.append('otp', otp);
            fetch('../../api/verify-change-otp.php', {
                method: 'POST',
                body: body
            }).then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    stepVerify.style.display = 'none';
                    stepSuccess.style.display = 'block';
                    pwForm.style.display = 'block';
                    setTimeout(function() {
                        var bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) bsModal.hide();
                    }, 1000);
                } else {
                    feedback.textContent = d.message || 'Invalid code.';
                    feedback.className = 'small mb-2 text-danger';
                    verifyBtn.disabled = false;
                    verifyBtn.textContent = 'Verify';
                }
            })
            .catch(function() {
                feedback.textContent = 'Network error.';
                feedback.className = 'small mb-2 text-danger';
                verifyBtn.disabled = false;
                verifyBtn.textContent = 'Verify';
            });
        });
    })();
    </script>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>
    <script src="../../script/admin-flush-settings.js"></script>

    <script>
        // ── Toast auto-dismiss ──
        document.addEventListener('DOMContentLoaded', function () {
            const toast = document.getElementById('toastMsg');
            if (toast && toast.classList.contains('show')) {
                setTimeout(() => toast.classList.remove('show'), 3500);
            }

            // ── Sidebar navigation ──
            const sidebarItems = document.querySelectorAll('.profile-sidebar .sidebar-item');
            const sections = {};
            document.querySelectorAll('.section-content').forEach(function (el) {
                sections[el.id.replace('section-', '')] = el;
            });

            sidebarItems.forEach(function (item) {
                item.addEventListener('click', function () {
                    const section = this.getAttribute('data-section');
                    if (!section || !sections[section]) return;

                    sidebarItems.forEach(function (si) { si.classList.remove('active'); });
                    this.classList.add('active');

                    Object.keys(sections).forEach(function (key) {
                        sections[key].classList.remove('active');
                    });
                    sections[section].classList.add('active');
                });
            });

                // ── Countdown timer for scheduled flush ──
            const countdownEl = document.getElementById('flushCountdown');
            if (countdownEl) {
                const scheduledDate = <?= $flush_schedule ? strtotime($flush_schedule['scheduled_datetime']) * 1000 : 0 ?>;
                if (scheduledDate) {
                    function updateCountdown() {
                        const now = new Date().getTime();
                        const diff = scheduledDate - now;
                        if (diff <= 0) {
                            countdownEl.textContent = 'Flush is due';
                            return;
                        }
                        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
                        const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                        const mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const secs = Math.floor((diff % (1000 * 60)) / 1000);
                        countdownEl.textContent = days + 'd ' + hours + 'h ' + mins + 'm ' + secs + 's';
                    }
                    updateCountdown();
                    setInterval(updateCountdown, 1000);
                }
            }

        });
    </script>
</body>
</html>
