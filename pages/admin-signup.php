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
        <a class="medium d-flex justify-content-center align-items-center" onclick="dissolve('../index.html')"><i class="bi bi-house"></i></a>
    </div>
    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png">
            </div>
            <h4 class="pb-4 semibold">Administrator Sign Up</h4>

            <!-- SESSION MESSAGES -->
            <?php
                if (session_status() === PHP_SESSION_NONE) session_start();

                if (!empty($_SESSION['signup_errors'])) {
                    foreach ($_SESSION['signup_errors'] as $error) {
                        echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
                    }
                    unset($_SESSION['signup_errors']);
                }
                if (!empty($_SESSION['signup_success'])) {
                    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['signup_success']) . '</div>';
                    unset($_SESSION['signup_success']);
                }
            ?>

            <div class="form-container">
                <form action="../php/admin-signup-process.php" method="POST">
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Family Name" required>
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" required>
                    </div>
                    <div class="form-group">
                        <label for="middle_initial">M.I.</label>
                        <input type="text" class="form-control" id="middle_initial" name="middle_initial" placeholder="M.I." maxlength="1">
                    </div>
                    <div class="form-group">
                        <label for="admin_code">Admin Code</label>
                        <input type="text" class="form-control" id="admin_code" name="admin_code" placeholder="Enter admin code" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Admin E-mail</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                            <i class="bi bi-eye-slash" id="togglePassword"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                            <i class="bi bi-eye-slash" id="toggleConfirmPassword"></i>
                        </div>
                    </div>
                    <div class="submit-container">
                        <button class="medium" type="submit">SIGN UP</button>
                        or<br>
                        <button type="button" class="medium" onclick="dissolve('admin-login.php')">LOG IN</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="../script/modals.js"></script>
    <script src="../script/animations.js"></script>
    <script src="../script/password.js"></script>
</body>

</html>