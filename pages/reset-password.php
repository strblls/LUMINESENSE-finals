<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['reset_allowed'])) {
    header('Location: forgot-password.php');
    exit;
}
$error = $_SESSION['reset_error'] ?? null;
unset($_SESSION['reset_error']);
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
    <title>Reset Password - LumineSense</title>
</head>
<body>
    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center" onclick="dissolve('verify-reset-otp.php')">
            <i class="bi bi-arrow-left"></i>
        </a>
    </div>

    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png" alt="LumineSense Logo">
            </div>
            <h4 class="pb-3 semibold">Reset Password</h4>
            <p class="text-muted text-center small mb-3">Enter your new password.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form action="../handlers/reset-password-process.php" method="POST">
                    <div class="mb-3">
                        <label for="new_password">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password"
                            placeholder="Min 8 characters" minlength="8" required>
                    </div>
                    <div class="mb-4">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                            placeholder="Repeat new password" required>
                    </div>
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">RESET PASSWORD</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../js/lib/animations.js"></script>
</body>
</html>
