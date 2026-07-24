<?php
if (session_status() === PHP_SESSION_NONE) session_start();
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
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/containers.css">
    <link rel="stylesheet" href="../css/registration.css">
    <title>Forgot Password – LumineSense</title>
</head>
<body>
    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center" onclick="dissolve('../index.php')">
            <i class="bi bi-house"></i>
        </a>
    </div>

    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png" alt="LumineSense Logo">
            </div>
            <h4 class="pb-3 semibold">Forgot Password</h4>
            <p class="text-muted text-center small mb-3">Enter your email and select your account type to receive a reset code.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="form-container">
                <form action="../php/forgot-password-process.php" method="POST">
                    <div class="mb-3">
                        <label for="email">E-mail</label>
                        <input type="email" class="form-control" id="email" name="email"
                            placeholder="Enter your registered email" required>
                    </div>
                    <div class="mb-4">
                        <label>Account Type</label>
                        <div class="d-flex gap-3 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleFaculty" value="faculty" checked>
                                <label class="form-check-label" for="roleFaculty">Faculty</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="role" id="roleAdmin" value="admin">
                                <label class="form-check-label" for="roleAdmin">Admin</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">SEND RESET CODE</button>
                            <br>
                            <a class="medium" onclick="dissolve('../index.php')">Back to Login</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../script/animations.js"></script>
</body>
</html>
