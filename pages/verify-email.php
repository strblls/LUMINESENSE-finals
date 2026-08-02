<?php
/**
 * LumineSense - Email Verification Page
 * ---------------------------------------
 * Shared by both Admin and Faculty sign-up flows.
 *
 * GET  â†’ shows the OTP input form
 * POST â†’ validates the OTP; on success:
 *          Admin   â†’ status = 'active'       â†’ redirect to admin-login.php
 *          Faculty â†’ status = 'pending_admin' â†’ redirect to pending-approval.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../src/Config/db_connect.php";
require_once __DIR__ . "/../src/Services/mailer.php";

// Guard: must have gone through signup first
if (empty($_SESSION['pending_verification'])) {
    header('Location: ../index.php');
    exit;
}

$pv    = $_SESSION['pending_verification'];
$email = $pv['email'];
$role  = $pv['role'];   // 'admin' | 'faculty'
$name  = $pv['name'];

$table    = ($role === 'admin') ? 'admins' : 'faculty';
$errors   = [];
$success  = '';
$resent   = false;

// - Handle RESEND request -------------------------
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    $new_otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $new_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = $conn->prepare("UPDATE $table SET otp_code = ?, otp_expires_at = ? WHERE email = ?");
    $stmt->bind_param('sss', $new_otp, $new_expires, $email);
    $stmt->execute();
    $stmt->close();

    sendVerificationEmail($email, $new_otp, $name);
    
    // âœ… Redirect to clean URL so ?resend=1 is gone
    header('Location: verify-email.php?resent=done');
    exit;
}

$resent = isset($_GET['resent']) && $_GET['resent'] === 'done';

// - Handle POST (OTP submission) ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = trim($_POST['otp_code'] ?? '');

    if (empty($entered_otp)) {
        $errors[] = 'Please enter the verification code.';
    } elseif (!preg_match('/^\d{6}$/', $entered_otp)) {
        $errors[] = 'The code must be exactly 6 digits.';
    } else {
        // Fetch stored OTP + expiry
        $stmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM $table WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($db_otp, $db_expires);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || $db_otp === null) {
            $errors[] = 'We could not find your account. Please sign up again.';
        } elseif ($entered_otp !== str_pad((string)$db_otp, 6, '0', STR_PAD_LEFT)) {
            $errors[] = 'Incorrect code. Please check your email and try again.';
        } elseif ($db_expires === null || strtotime($db_expires) < time()) {
            $errors[] = 'This code has expired. Click "Resend Code" to get a new one.';
        } else {
            // âœ… OTP is correct and not expired
            if ($role === 'admin') {
                // Admin â†’ email confirmed â†’ is_verified = 1, pending admin approval
                $stmt = $conn->prepare("
                    UPDATE admins
                    SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL
                    WHERE email = ?
                ");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->close();

                unset($_SESSION['pending_verification']);
                header('Location: ../pages/pending-approval.php?type=admin');
                exit;

            } else {
                // Faculty â†’ email confirmed â†’ is_verified = 1
                // approved_by stays NULL until an admin approves them
                $stmt = $conn->prepare("
                    UPDATE faculty
                    SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL
                    WHERE email = ?
                ");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->close();

                unset($_SESSION['pending_verification']);
                header('Location: ../pages/pending-approval.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>

    <!--Relative links-->
    <link rel="icon" href="../images/icon.png">
    <link rel="stylesheet" href="../css/base/global.css">
    <link rel="stylesheet" href="../css/base/containers.css">
    <link rel="stylesheet" href="../css/pages/registration.css">
    <link rel="stylesheet" href="../css/admin/common.css">
    <link rel="stylesheet" href="../css/faculty/settings.css">
    <link rel="stylesheet" href="../css/base/modals.css">

    <title>Verify Email - LumineSense</title>

    <link rel="stylesheet" href="../css/pages/verify-email.css">
</head>
<body>
<div class="parent-container">
    <div class="registration-container">
        <div class="image-background <?= $role === 'faculty' ? 'faculty' : '' ?>">
            <img src="../images/logo.png" alt="LumineSense Logo">
        </div>

        <h4 class="pb-2 semibold">Verify Your Email</h4>

        <!-- Role badge (topbar style) -->
        <div class="text-center mb-3">
            <span class="bold status-badge <?= $role === 'admin' ? 'admin' : 'faculty-member' ?>">
                <?= $role === 'admin' ? 'Administrator' : 'Faculty' ?>
            </span>
        </div>

        <p class="email-hint">
            A 6-digit code was sent to <strong><?= htmlspecialchars($email) ?></strong>.<br>
            It expires in <span id="countdown">15:00</span>.
        </p>

        <!-- Warnings / errors / success -->
        <?php if (!empty($_SESSION['email_warning'])): ?>
            <div class="alert alert-warning">
                <?= htmlspecialchars($_SESSION['email_warning']) ?>
                <?php unset($_SESSION['email_warning']); ?>
            </div>
        <?php endif; ?>

        <?php if ($resent): ?>
            <div class="alert alert-success">
                âœ… A new code has been sent! <strong>Please check your email for the latest code and discard any previous ones.</strong>
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <!-- OTP form -->
        <div class="form-container">
            <form method="POST" action="" id="otp-form" class="d-flex flex-column align-items-center">                
                <!-- Hidden field holds the combined OTP value -->
                <input type="hidden" name="otp_code" id="otp-hidden">

                <!-- Six individual digit boxes -->
                <div class="otp-inputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text"
                               inputmode="numeric"
                               maxlength="1"
                               class="otp-digit"
                               id="d<?= $i ?>"
                               autocomplete="off">
                    <?php endfor; ?>
                </div>

                <div class="resend-row">
                    Didn't receive it?
                    <a href="verify-email.php?resend=1">Resend Code</a>
                </div>

                <div class="submit-container mt-3">
                    <button class="medium w-100" type="submit" id="verify-btn">
                        VERIFY
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Empty fields warning modal -->
<div class="modal fade" id="emptyOtpModal" tabindex="-1" aria-hidden="true">
    <div class="room-details-modal modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Incomplete Code</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center p-4">
                <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:var(--accent-yellow);"></i>
                <p class="mt-3 mb-0">Please fill in all 6 digits before verifying.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="light" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="../js/verify-email.js"></script>
</body>
</html>