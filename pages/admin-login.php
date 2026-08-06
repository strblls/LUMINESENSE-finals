<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loginError = $_SESSION['login_error'] ?? null;
$signupSuccessModal = $_SESSION['signup_success_modal'] ?? null;
$loginSuccess = $_SESSION['login_success'] ?? null;

unset($_SESSION['login_error'], $_SESSION['signup_success_modal'], $_SESSION['login_success']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--Bootstrap and JS CDN-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <link rel="icon" type="image/png" sizes="32x32" href="../images/icon.png">
    <link rel="shortcut icon" type="image/png" href="../images/icon.png">
    <!--CSS files-->
    <link rel="stylesheet" href="../css/base/global.css">
    <link rel="stylesheet" href="../css/base/containers.css">
    <link rel="stylesheet" href="../css/pages/registration.css">

    <title>Admin Login - LumineSense</title>
</head>

<body>
    <img src="../images/landing/bgforms.jpg" alt="" class="auth-bg" loading="lazy">
    <img src="../images/landing/bgforms%20yellow.jpg" alt="" class="auth-bg hover" loading="lazy">

    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center"
           onclick="dissolve('../index.php')">
            <i class="bi bi-house"></i>
        </a>
    </div>

    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png" alt="LumineSense Logo">
            </div>

            <h4 class="pb-4 semibold">Administrator Login</h4>

            <!-- SESSION MESSAGES -->
            <?php if (!empty($loginSuccess)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($loginSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
            <?php endif; ?>

            <?php if (!empty($signupSuccessModal)): ?>
                <div class="modal fade" id="signupSuccessModal" tabindex="-1" aria-labelledby="signupSuccessModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="signupSuccessModalLabel">Signup Successful</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <?= htmlspecialchars($signupSuccessModal) ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Continue to Login</button>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var modalEl = document.getElementById('signupSuccessModal');
                        var modal = new bootstrap.Modal(modalEl);
                        modal.show();
                    });
                </script>
            <?php endif;
            ?>

            <div class="form-container">
                <form action="../handlers/admin-login-process.php" method="POST">

                    <div class="mb-3">
                        <label for="email">Admin E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="Enter your admin email"
                            autocomplete="email"
                            required>
                    </div>

                    <div class="mb-3">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required>
                            <i class="bi bi-eye-slash" id="togglePassword"></i>
                        </div>
                    </div>

                    <div class="submit-container admin-login">
                        <button type="submit" class="medium">LOGIN</button>
                        or<br>
                        <a type="button" class="medium" onclick="dissolve('admin-signup.php')">SIGN-UP</a>
                        <a class="small text-muted d-block mt-2" onclick="dissolve('forgot-password.php')" style="cursor:pointer;">Forgot Password?</a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="../js/lib/animations.js"></script>
    <script src="../js/lib/password.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var bg = document.querySelector('.auth-bg.hover');
            window.addEventListener('mousemove', function (e) {
                bg.classList.toggle('active', e.clientX < window.innerWidth / 2);
            });
            window.addEventListener('mouseleave', function () {
                bg.classList.remove('active');
            });
        });
    </script>
</body>

</html>