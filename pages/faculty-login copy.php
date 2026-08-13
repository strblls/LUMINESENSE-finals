<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$loginError = $_SESSION['login_error'] ?? null;
$signupSuccess = $_SESSION['signup_success'] ?? null;
$loginSuccess = $_SESSION['login_success'] ?? null;

unset($_SESSION['login_error'], $_SESSION['signup_success'], $_SESSION['login_success']);
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
    <link rel="stylesheet" href="../css/pages/index.css">

    <title>Faculty Login - LumineSense</title>
</head>

<body>
    <div class="form-bg">
        <img src="../images/landing/bgforms.jpg" alt="" class="bg-base" loading="lazy">
        <img src="../images/landing/bgformsyellow.jpg" alt="" class="bg-base bg-hover" loading="lazy">

        <!--Div Border-->
        <div class="border-glow" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; border-radius: 10px; pointer-events: none;"></div>
        <div class="border-gradient" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; border-radius: 10px; pointer-events: none;"></div>

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

            <h4 class="pb-4 semibold">Faculty Login</h4>
            <!-- SESSION MESSAGES - shown when PHP redirects back with a message -->
            <?php
            if (!empty($loginSuccess)) {
                echo '<div class="alert alert-success">' . htmlspecialchars($loginSuccess) . '</div>';
            }
            if (!empty($loginError)) {
                echo '<div class="alert alert-danger">' . htmlspecialchars($loginError) . '</div>';
            }
            if (!empty($signupSuccess)) {
                echo '<div class="alert alert-success">' . htmlspecialchars($signupSuccess) . '</div>';
            }
            ?>

            <div class="form-container">
                <!-- ONE form tag wrapping everything -->
                <form action="../handlers/faculty-login-process.php" method="POST">

                    <div class="mb-3">
                        <label for="email">E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
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

                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">LOGIN</button>
                            or<br>
                            <a class="medium" onclick="dissolve('faculty-signup.php')">SIGN-UP</a>
                        </div>
                        <a class="small text-muted mt-2" onclick="dissolve('forgot-password.php')" style="cursor:pointer;">Forgot Password?</a>
                    </div>
                </form>

            </div>
        </div>
    </div>

    <!-- Archived Account Modal -->
    <div id="archivedModal" style="display:none; position:fixed; inset:0; z-index:9999; align-items:center; justify-content:center;">
        <div style="position:absolute; inset:0; background:rgba(0,0,0,.4);" onclick="closeArchivedModal()"></div>
        <div style="position:relative; z-index:10; background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,.18); max-width:420px; width:90%; padding:32px; border-top:4px solid #0d6efd; text-align:center;">
            <i class="bi bi-archive" style="font-size:2.5rem; color:#0d6efd; display:block; margin-bottom:12px;"></i>
            <h4 class="bold mb-2">Account Archived</h4>
            <p style="font-size:14px; color:#555; margin-bottom:8px;">Your faculty account has been archived as part of the end-of-semester system flush.</p>
            <p style="font-size:14px; color:#555; margin-bottom:20px;">Contact the administrator to reactivate your account.</p>
            <button class="medium w-100" onclick="closeArchivedModal()">OK</button>
        </div>
    </div>
    <script>
    (function() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('archived') === '1') {
            document.getElementById('archivedModal').style.display = 'flex';
        }
        window.closeArchivedModal = function() {
            document.getElementById('archivedModal').style.display = 'none';
            window.history.replaceState({}, '', window.location.pathname);
        };
    })();
    </script>

    <script src="../js/lib/animations.js"></script>
    <script src="../js/lib/password.js"></script>
    </div>
    <script>
        document.querySelector('.form-bg').addEventListener('mousemove', function(e) {
            this.querySelector('.bg-hover').classList.toggle('active', e.clientX < window.innerWidth / 2);
        });
        document.querySelector('.form-bg').addEventListener('mouseleave', function() {
            this.querySelector('.bg-hover').classList.remove('active');
        });
    </script>
</body>

</html>