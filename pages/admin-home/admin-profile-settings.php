<?php
$page_title = 'Profile Settings';
require_once '../../php/includes/admin-head.php';
require_once __DIR__ . '/../../php/handlers/admin-handlers.php';
/** @var string $admin_name  
 * @var string $admin_email
 * @var string $initials
 */
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../../css/global.css">
    <link rel="stylesheet" href="../../css/containers.css">
    <link rel="stylesheet" href="../../css/modals.css">

    <style>
        .info-action-btn {
            width: auto;
            white-space: nowrap;
            background-color: var(--primary-color);
            color: var(--secondary-color-1);
            border: 1px solid var(--secondary-color-2);
            transition: background-color 0.2s, transform 0.15s;
        }
        .info-action-btn:hover {
            background-color: var(--secondary-color-1);
            color: var(--primary-color);
            transform: scale(1.02);
        }

        /* ── Profile wrapper ── */
        .profile-wrapper {
            display: flex;
            justify-content: center;
            padding: 2rem;
            width: 100%;
        }

        .profile-main-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(47, 0, 79, 0.13);
            width: 100%;
            max-width: 1100px;
            overflow: hidden;
        }

        /* ── Profile header ── */
        .profile-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            padding: 2rem 2.5rem;
            border-bottom: 1.5px solid #eee;
        }

        .profile-avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: #d9d6d6;
            color: var(--secondary-color-1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .profile-user h2 {
            color: var(--secondary-color-1);
            margin-bottom: 0.2rem;
        }

        .profile-user p {
            color: #666;
            font-size: 0.95rem;
        }

        /* ── Content area ── */
        .profile-content {
            padding: 2rem 2.5rem;
        }

        /* ── Info cards ── */
        .info-card {
            background: #fff;
            border: 1.5px solid #e8e8e8;
            border-radius: 14px;
            padding: 1.5rem;
        }

        .info-card-header {
            margin-bottom: 1.2rem;
        }

        .info-card-header h3 {
            font-size: 1rem;
            color: var(--secondary-color-1);
        }

        .info-field {
            margin-bottom: 1rem;
        }

        .info-field .label {
            display: block;
            font-size: 0.8rem;
            font-weight: 500;
            color: #888;
            margin-bottom: 0.35rem;
        }

        .field-value {
            background: #f0f0f0;
            padding: 10px 12px;
            border-radius: 6px;
            color: #333;
            font-size: 0.92rem;
        }
    </style>
</head>

<body class="contrast-bg">

    <div class="parent-container">
        <?php include '../../php/includes/admin-topbar.php'; ?>

        <div class="child-container">
            <div class="profile-wrapper">
                <div class="profile-main-card">

                    <!-- Profile Header -->
                    <div class="profile-header">
                        <div class="profile-avatar"><?= htmlspecialchars($initials) ?></div>
                        <div class="profile-user">
                            <h2 class="bold mb-1"><?= htmlspecialchars($admin_name) ?></h2>
                            <p class="mb-0">Administrator</p>
                        </div>
                    </div>

                    <!-- Two-column content -->
                    <div class="profile-content row gx-4 gy-4">

                        <!-- Left: Contact Information -->
                        <div class="col-xl-5 col-lg-6">
                            <div class="info-card">
                                <div class="info-card-header d-flex align-items-center justify-content-between">
                                    <h3 class="bold mb-0">Contact Information</h3>
                                    <button class="light info-action-btn"
                                        style="padding: 6px 14px; font-size: 0.82rem; border-radius: 8px;"
                                        data-bs-toggle="modal" data-bs-target="#editContactModal">
                                        <i class="bi bi-pencil me-1"></i> Edit
                                    </button>
                                </div>
                                <div class="info-field">
                                    <span class="label">Email</span>
                                    <div class="field-value"><?= htmlspecialchars($admin_email) ?></div>
                                </div>
                                <div class="info-field">
                                    <span class="label">Account Created</span>
                                    <div class="field-value">May 8, 2026</div>
                                </div>
                                <div class="info-field">
                                    <span class="label">Address</span>
                                    <div class="field-value">N/A</div>
                                </div>
                            </div>
                        </div>

                        <!-- Right: Change Password -->
                        <div class="col-xl-7 col-lg-6">
                            <div class="info-card">
                                <div class="info-card-header">
                                    <h3 class="bold mb-0">Change Password</h3>
                                </div>
                                <form method="POST" action="../../php/change-password.php">
                                    <div class="mb-2">
                                        <label class="form-label" style="font-size:0.85rem; color:#888; font-weight:500;">Current Password</label>
                                        <input type="password" class="form-control" name="current_password"
                                            placeholder="Current password" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label" style="font-size:0.85rem; color:#888; font-weight:500;">New Password</label>
                                        <input type="password" class="form-control" name="new_password"
                                            placeholder="Min 8 characters" minlength="8" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label" style="font-size:0.85rem; color:#888; font-weight:500;">Confirm New Password</label>
                                        <input type="password" class="form-control" name="confirm_password"
                                            placeholder="Repeat new password" required>
                                    </div>
                                    <button type="submit" class="light info-action-btn w-100"
                                        style="padding: 10px; border-radius: 8px;">
                                        Save Password
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div><!-- /profile-content -->
                </div><!-- /profile-main-card -->
            </div><!-- /profile-wrapper -->

            <script src="../../script/animations.js"></script>
            <script src="../../script/toggles.js"></script>
            <script src="../../script/initialize-gesture.js"></script>

        </div><!-- /child-container -->
    </div><!-- /parent-container -->

    <?php include '../../php/includes/admin-sidebar.php'; ?>
    <?php include '../../php/includes/profile-offcanvas.php'; ?>

</body>

</html>