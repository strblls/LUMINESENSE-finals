<?php
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $old = $_SESSION['signup_form'] ?? [];
    unset($_SESSION['signup_form']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>

    <!--Relative links-->
    <link rel="icon" href="../images/icon.png">
    <link rel="stylesheet" href="../css/base/global.css">
    <link rel="stylesheet" href="../css/base/containers.css">
    <link rel="stylesheet" href="../css/pages/registration.css">
    <link rel="stylesheet" href="../css/pages/index.css">

    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

    <title>Faculty Sign Up - LumineSense</title>
</head>

<body>
    <div class="form-bg">
        <img src="../images/landing/bgforms.jpg" alt="" class="bg-base" loading="lazy">
        <img src="../images/landing/bgforms-yellow.jpg" alt="" class="bg-base bg-hover" loading="lazy">

        <!--Div Border-->
        <div class="border-glow" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; border-radius: 10px; pointer-events: none;"></div>
        <div class="border-gradient" style="position: absolute; inset: 0; box-sizing: border-box; z-index: 1; border-radius: 10px; pointer-events: none;"></div>

        <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center"
           onclick="dissolve('../index.php')">
            <i class="bi bi-house"></i>
        </a>
    </div>

    <div class="parent-container-index faculty-signup">
        <div class="registration-container">
            <div class="image-background faculty">
                <img src="../images/logo.png" alt="LumineSense Logo">
            </div>

            <h4 class="pb-2 semibold">Faculty Sign Up</h4>
            <?php
                if (!empty($_SESSION['signup_errors'])) {
                   foreach ($_SESSION['signup_errors'] as $err) {
                        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
                    }
                    unset($_SESSION['signup_errors']);
                }
            ?>

            <div class="form-container">
                <form id="faculty-signup-form" action="../handlers/faculty-signup-process.php" method="POST" enctype="multipart/form-data" onsubmit="showSignupModal(); return false;">
                    <div class="form-group mb-3">
                        <div class="child-1">
                            <label for="fname">Last Name</label>
                            <input
                                type="text"
                                class="form-control"
                                id="fname"
                                name="last_name"
                                placeholder="Family Name"
                                value="<?= htmlspecialchars($old['last_name'] ?? '') ?>"
                                required>
                        </div>
                        <div class="child-2">
                            <label for="lname">First Name</label>
                            <input
                                type="text"
                                class="form-control"
                                id="lname"
                                name="first_name"
                                placeholder="First Name"
                                value="<?= htmlspecialchars($old['first_name'] ?? '') ?>"
                                required>
                        </div>
                        <div class="child-3">
                            <label for="middle">M.I.</label>
                            <input
                                type="text"
                                class="form-control"
                                id="middle"
                                name="middle_initial"
                                placeholder="M.I."
                                maxlength="5"
                                value="<?= htmlspecialchars($old['middle_initial'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email">Faculty E-mail</label>
                        <input
                            type="email"
                            class="form-control"
                            id="email"
                            name="email"
                            placeholder="Enter your email"
                            autocomplete="email"
                            value="<?= htmlspecialchars($old['email'] ?? '') ?>"
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
                                placeholder="Enter your password (min 8 characters)"
                                autocomplete="new-password"
                                minlength="8"
                                required>
                            <i class="bi bi-eye-slash" id="togglePassword"></i>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label for="confirmPassword">Confirm Password</label>
                        <div class="password-wrapper">
                            <input
                                type="password"
                                class="form-control"
                                id="confirmPassword"
                                name="confirm_password"
                                placeholder="Confirm your password"
                                autocomplete="new-password"
                                required>
                            <i class="bi bi-eye-slash" id="toggleConfirmPassword"></i>
                        </div>
                    </div>

                    <!-- ID Image Upload -->
                    <div class="mb-3 mt-3">
                        <label for="id_image">Upload School/Government ID</label>
                        <small class="text-muted d-block mb-1">
                            Make sure your name is clearly visible on the ID.
                        </small>
                        <input
                            type="file"
                            class="form-control"
                            id="id_image"
                            name="id_image"
                            accept="image/jpeg, image/png, image/webp"
                            required>
                        <div class="progress mt-1" style="height:8px;display:none;" id="ocr_progress_wrap">
                            <div class="progress-bar" id="ocr_progress" role="progressbar" style="width:0%;background-color:var(--secondary-color-1);" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                        <small id="ocr_status" class="mt-1 d-block"></small>
                        <input type="hidden" name="ocr_text" id="ocr_text" value="">
                    </div>

                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">SIGN UP</button>
                            or<br>
                            <a class="medium" onclick="dissolve('faculty-login.php')">LOG IN</a>
                        </div>
                    </div>

                    <!-- Confirmation Modal
                    <div class="notify-modal" id="notify-modal" style="display:none;">
                        <div class="modal-box">
                            <div id="modal-header">
                                <h5><strong>!</strong> Validation Required</h5>
                            </div>
                            <div id="modal-body">
                                <i class="bi bi-exclamation-triangle" id="cautionTriangle"></i>
                                <h5>Validate your account e-mail to the <strong>Administrator</strong> for verification and authentication.</h5>
                            </div>
                            <div id="modal-footer">
                                <button class="medium" type="submit">CONFIRM & SIGN UP</button>
                                <button class="medium" type="button" onclick="hideSignupModal()">CANCEL</button>
                            </div>
                        </div>
                    </div> -->

                </form>
            </div>
        </div>
    </div>

    <script src="../js/lib/modals.js"></script>
    <script src="../js/lib/animations.js"></script>
    <script src="../js/lib/password.js"></script>
    <script src="../js/faculty-signup.js"></script>
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
