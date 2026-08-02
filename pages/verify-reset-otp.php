<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['reset_email'])) {
    header('Location: forgot-password.php');
    exit;
}
$error = $_SESSION['reset_error'] ?? null;
unset($_SESSION['reset_error']);

// For resend cooldown
$lastSent = $_SESSION['reset_otp_sent_at'] ?? 0;
$cooldown = max(0, 60 - (time() - $lastSent));
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
    <link rel="icon" type="image/png" sizes="32x32" href="../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../images/icon.png">
    <link rel="stylesheet" href="../css/base/global.css">
    <link rel="stylesheet" href="../css/base/containers.css">
    <link rel="stylesheet" href="../css/pages/registration.css">
    <title>Verify Code - LumineSense</title>
</head>
<body>
    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center" onclick="dissolve('forgot-password.php')">
            <i class="bi bi-arrow-left"></i>
        </a>
    </div>

    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png" alt="LumineSense Logo">
            </div>
            <h4 class="pb-3 semibold">Enter Reset Code</h4>
            <p class="text-muted text-center small mb-3">A 6-digit code was sent to <strong><?= htmlspecialchars($_SESSION['reset_email']) ?></strong></p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form action="../handlers/verify-reset-otp-process.php" method="POST">
                    <div class="mb-4">
                        <label for="otp">Verification Code</label>
                        <input type="text" class="form-control text-center" id="otp" name="otp"
                            maxlength="6" pattern="\d{6}" inputmode="numeric"
                            placeholder="000000" style="font-size:1.5rem;letter-spacing:8px;" required>
                    </div>
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">VERIFY CODE</button>
                            <br>
                            <button type="button" class="medium text-muted" id="resendBtn"
                                style="background:none;border:none;font-size:13px;"
                                <?= $cooldown > 0 ? 'disabled' : '' ?>>
                                Resend Code <?= $cooldown > 0 ? "(${cooldown}s)" : '' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/lib/animations.js"></script>
    <script src="../js/verify-reset-otp.js"></script>
</body>
</html>
