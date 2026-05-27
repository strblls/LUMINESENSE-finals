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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profile Settings</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        /* ── Toast ── */
        .toast-wrap {
            position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        }
        .toast-msg {
            background: var(--secondary-color-1);
            color: #fff;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: .85rem;
            font-weight: 600;
            box-shadow: 0 6px 20px rgba(0,0,0,.25);
            display: none;
        }
        .toast-msg.show {
            display: block;
            animation: fadeInUp .3s ease, fadeOut .4s ease 2.4s forwards;
        }
        .toast-msg.error { background: #c0392b; }
        @keyframes fadeInUp {
            from { opacity:0; transform:translateY(12px); }
            to   { opacity:1; transform:translateY(0); }
        }
        @keyframes fadeOut { to { opacity:0; } }

        /* ── Page layout ── */
        .profile-wrapper {
            display: flex;
            justify-content: center;
            padding: 2rem;
            width: 100%;
            box-sizing: border-box;
        }

        .profile-main-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(47,0,79,.13);
            width: 100%;
            max-width: 1100px;
            overflow: hidden;
        }

        /* ── Header ── */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 2rem 2.5rem;
            border-bottom: 1.5px solid #eee;
        }

        .profile-avatar {
            width: 90px; height: 90px;
            border-radius: 50%;
            background: #d9d6d6;
            color: var(--secondary-color-1);
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; font-weight: 700; flex-shrink: 0;
        }

        .profile-user h2 { color: var(--secondary-color-1); margin-bottom: .2rem; }
        .profile-user p  { color: #666; font-size: .95rem; }

        /* ── Content ── */
        .profile-content { padding: 2rem 2.5rem; }

        /* ── Info cards ── */
        .info-card {
            background: #fff;
            border: 1.5px solid #e8e8e8;
            border-radius: 14px;
            padding: 1.5rem;
            height: 100%;
        }

        .info-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.2rem;
        }

        .info-card-header h3 { font-size: 1rem; color: var(--secondary-color-1); }

        .info-field { margin-bottom: 1rem; }

        .info-field .label {
            display: block; font-size: .8rem; font-weight: 500;
            color: #888; margin-bottom: .35rem;
        }

        .field-value {
            background: #f0f0f0;
            padding: 10px 12px;
            border-radius: 6px;
            color: #333;
            font-size: .92rem;
        }

        /* ── Action button ── */
        .info-action-btn {
            background-color: var(--primary-color);
            color: var(--secondary-color-1);
            border: 1px solid var(--secondary-color-2);
            padding: 6px 14px;
            font-size: .82rem;
            border-radius: 8px;
            white-space: nowrap;
            transition: background-color .2s, transform .15s;
            cursor: pointer;
        }
        .info-action-btn:hover {
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            transform: scale(1.02);
        }

        /* ── Password strength bar ── */
        .strength-bar-wrap {
            height: 4px;
            background: #eee;
            border-radius: 2px;
            margin-top: 6px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            border-radius: 2px;
            transition: width .3s, background .3s;
        }
        .strength-label { font-size: 11px; color: #888; margin-top: 3px; }
    </style>
</head>

<body class="contrast-bg">

    <!-- Toast -->
    <div class="toast-wrap">
        <div class="toast-msg <?= $flash && $flash['type'] === 'error' ? 'error' : '' ?> <?= $flash ? 'show' : '' ?>" id="toastMsg">
            <?= htmlspecialchars($flash['msg'] ?? '') ?>
        </div>
    </div>

    <div class="parent-container">
        <?php include '../../php/includes/admin-topbar.php'; ?>

        <div class="child-container">
            <div class="profile-wrapper">
                <div class="profile-main-card">

                    <!-- Header -->
                    <div class="profile-header">
                        <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="profile-user">
                            <h2 class="bold mb-1"><?= htmlspecialchars($admin_name) ?></h2>
                            <p class="mb-0 text-muted">Administrator</p>
                        </div>
                    </div>

                    <!-- Two-column content -->
                    <div class="profile-content row gx-4 gy-4">

                        <!-- Left: Contact Info -->
                        <div class="col-xl-5 col-lg-6">
                            <div class="info-card">
                                <div class="info-card-header">
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

                        <!-- Right: Change Password -->
                        <div class="col-xl-7 col-lg-6">
                            <div class="info-card">
                                <div class="info-card-header">
                                    <h3 class="bold mb-0">Change Password</h3>
                                </div>
                                <form method="POST" action="../../php/handlers/change-password.php" id="pwForm">
                                    <div class="mb-3">
                                        <label class="info-field label">Current Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="current_password"
                                                id="currentPw" placeholder="Current password" required>
                                            <button class="btn btn-outline-secondary" type="button"
                                                onclick="togglePw('currentPw', this)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mb-1">
                                        <label class="info-field label">New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="new_password"
                                                id="newPw" placeholder="Min 8 characters" minlength="8"
                                                oninput="checkStrength(this.value)" required>
                                            <button class="btn btn-outline-secondary" type="button"
                                                onclick="togglePw('newPw', this)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="strength-bar-wrap">
                                            <div class="strength-bar" id="strengthBar"></div>
                                        </div>
                                        <div class="strength-label" id="strengthLabel"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="info-field label">Confirm New Password</label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" name="confirm_password"
                                                id="confirmPw" placeholder="Repeat new password" required>
                                            <button class="btn btn-outline-secondary" type="button"
                                                onclick="togglePw('confirmPw', this)">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                        <div class="strength-label" id="matchLabel"></div>
                                    </div>
                                    <button type="submit" class="info-action-btn w-100"
                                        style="padding:10px; border-radius:8px;">
                                        Save Password
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div><!-- /profile-content -->
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
                    <div class="modal-footer">
                        <button type="button" class="light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="medium">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

    <script src="../../script/animations.js"></script>
    <script src="../../script/toggles.js"></script>

    <script>
        // ── Toast auto-dismiss ──
        document.addEventListener('DOMContentLoaded', function () {
            const toast = document.getElementById('toastMsg');
            if (toast && toast.classList.contains('show')) {
                setTimeout(() => toast.classList.remove('show'), 3500);
            }

            // ── Confirm-match on blur ──
            document.getElementById('confirmPw').addEventListener('input', function () {
                const match = this.value === document.getElementById('newPw').value;
                const lbl = document.getElementById('matchLabel');
                lbl.textContent = this.value ? (match ? '✓ Passwords match' : '✗ Passwords do not match') : '';
                lbl.style.color = match ? '#16a34a' : '#dc2626';
            });
        });

        // ── Show/hide password ──
        function togglePw(id, btn) {
            const inp = document.getElementById(id);
            const isText = inp.type === 'text';
            inp.type = isText ? 'password' : 'text';
            btn.querySelector('i').className = isText ? 'bi bi-eye' : 'bi bi-eye-slash';
        }

        // ── Password strength ──
        function checkStrength(val) {
            const bar   = document.getElementById('strengthBar');
            const label = document.getElementById('strengthLabel');
            let score   = 0;
            if (val.length >= 8)               score++;
            if (/[A-Z]/.test(val))             score++;
            if (/[0-9]/.test(val))             score++;
            if (/[^A-Za-z0-9]/.test(val))      score++;

            const levels = [
                { pct: '25%', color: '#ef4444', text: 'Weak' },
                { pct: '50%', color: '#f97316', text: 'Fair' },
                { pct: '75%', color: '#eab308', text: 'Good' },
                { pct: '100%', color: '#16a34a', text: 'Strong' },
            ];
            const lvl = levels[score - 1] || { pct: '0%', color: '#eee', text: '' };
            bar.style.width     = val ? lvl.pct    : '0%';
            bar.style.background = val ? lvl.color : '#eee';
            label.textContent   = val ? lvl.text   : '';
            label.style.color   = lvl.color;
        }
    </script>
</body>
</html>