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

    <!--CSS files-->
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/containers.css">
    <link rel="stylesheet" href="../css/registration.css">

    <title>Faculty Sign Up – LumineSense</title>
</head>

<body>
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

            <h4 class="pb-4 semibold">Faculty Sign Up</h4>

            <!--
                FIXES APPLIED:
                1. All separate <form> tags merged into ONE <form>.
                2. name="" added to every input — must match exactly what PHP reads:
                   last_name, first_name, middle_initial, email, password, confirm_password
                3. action points to faculty-signup.php.
                4. method="POST".
                5. The modal still works — it fires on button click, then PHP is called on CONFIRM.
            -->

            <!-- SESSION ERROR MESSAGES -->
            <?php
                if (session_status() === PHP_SESSION_NONE) session_start();

                if (!empty($_SESSION['signup_errors'])) {
                   foreach ($_SESSION['signup_errors'] as $err) {
                        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
                    }
                    unset($_SESSION['signup_errors']);
                }

                // Re-fill the form if they had errors so they don't retype everything
                $old = $_SESSION['signup_form'] ?? [];
                unset($_SESSION['signup_form']);
            ?>

            <div class="form-container">
                <!-- ONE form wrapping everything. action goes to PHP. -->
                <form id="faculty-signup-form" action="../php/faculty-signup.php" method="POST">

                    <div class="form-group mb-3">
                        <div>
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
                        <div>
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
                        <div>
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

                    <div class="mb-3">
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

                    <div class="submit-container">
                        <!--
                            The button type="button" (NOT submit) so it shows the modal first.
                            The CONFIRM button inside the modal is type="submit" which actually
                            submits the form to PHP.
                        -->
                        <button class="medium" type="button" onclick="showSignupModal()">SIGN UP</button>
                        or<br>
                        <a class="medium" onclick="dissolve('faculty-login.php')">LOG IN</a>
                    </div>

                    <!-- Confirmation Modal -->
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
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="../script/modals.js"></script>
    <script src="../script/animations.js"></script>
    <script src="../script/password.js"></script>
    <script>
        // Show the confirmation modal after basic client-side check
        function showSignupModal() {
            const pass    = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;

            if (pass !== confirm) {
                alert('Passwords do not match! Please check again.');
                return;
            }
            if (pass.length < 8) {
                alert('Password must be at least 8 characters long.');
                return;
            }

            document.getElementById('notify-modal').style.display = 'flex';
        }

        function hideSignupModal() {
            document.getElementById('notify-modal').style.display = 'none';
        }
    </script>
</body>

</html>
