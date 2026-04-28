<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset=" UTF-8">
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

    <title>Admin Sign Up – LumineSense</title>
</head>

<body>
    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center" 
            onclick="dissolve('../index.html')">
            <i class="bi bi-house"></i>
        </a>
    </div>
    
    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png">
            </div>
            <h4 class="pb-4 semibold">Administrator Sign Up</h4>

            <?php
                if (session_status() === PHP_SESSION_NONE) session_start();

                if (!empty($_SESSION['signup_errors'])) {
                    foreach ($_SESSION['signup_errors'] as $err) {
                        echo '<div class="alert alert-danger">' . htmlspecialchars($err) . '</div>';
                    }
                    unset($_SESSION['signup_errors']);
                }

                $old = $_SESSION['signup_form'] ?? [];
                unset($_SESSION['signup_form']);
            ?>

            <div class="form-container">
                <div class="form-group"> <!--ALERT: PHP|INPUT VALIDATION-->
                    <form> <!--ALERT: PHP-->
                        <label for="fname">Last Name</label>
                        <input type="text" class="form-control" id="fname" placeholder="Family Name" required>
                    </form>
                    <form> <!--ALERT: PHP-->
                        <label for="lname">First Name</label>
                        <input type="text" class="form-control" id="lname" placeholder="First Name" required>
                    </form>
                    <form> <!--ALERT: PHP-->
                        <label for="middle">M.I.</label>
                        <input type="text" class="form-control" id="middle" placeholder="M.I.">
                    </form>
                </div>
                <form> <!--ALERT: PHP-->
                    <label for="email">Admin Code</label>
                    <input type="text" class="form-control" id="email" placeholder="Enter admin code" required autocomplete="off">
                </form>
                <form> <!--ALERT: PHP-->
                    <label for="email">Admin E-mail</label>
                    <input type="email" class="form-control" id="email" placeholder="Enter your email" required>
                </form>
                <form> <!--ALERT: PHP-->
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="password" placeholder="Enter your password" required>
                        <i class="bi bi-eye-slash" id="togglePassword"></i>
                    </div>
                </form>
                <form> <!--ALERT: PHP-->
                    <label for="confirmPassword">Confirm Password</label>
                    <div class="password-wrapper">
                        <input type="password" class="form-control" id="confirmPassword" placeholder="Confirm your password" required>
                        <i class="bi bi-eye-slash" id="toggleConfirmPassword"></i>
                    </div>
                </form>

                <div class="submit-container">
                    <button class="medium" type="submit" onclick="dissolve('admin-login.html')">SIGN IN</button>
                    or<br>
                    <a class="medium" onclick="dissolve('admin-login.html')">LOG IN</a>
                </div>

                <!-- Confirmation Modal -->
                <div class="notify-modal" id="notify-modal" style="display:none;">
                    <div class="modal-box">
                        <div id="modal-header">
                            <h5><strong>!</strong> Validation Required</h5>
                        </div>
                        <div id="modal-body">
                            <i class="bi bi-exclamation-triangle" id="cautionTriangle"></i>
                            <h5>Validate your Admin ID from the <strong>Information Systems Office</strong> for validation and authentication.</h5>
                        </div>
                        <div id="modal-footer">
                            <button class="medium" type="submit">CONFIRM & SIGN UP</button>
                            <button class="medium" type="button" onclick="hideSignupModal()">CANCEL</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="../script/modals.js"></script>
    <script src="../script/animations.js"></script>
    <script src="../script/password.js"></script>
    <script>
         function hideSignupModal() {
            document.getElementById('notify-modal').style.display = 'none';
        }
    </script>
</body>

</html>