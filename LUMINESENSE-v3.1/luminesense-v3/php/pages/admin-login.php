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

    <title>Admin Login – LumineSense</title>
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

            <h4 class="pb-4 semibold">Administrator Login</h4>

            <!--
                NOTE on "Admin ID" field:
                The original HTML labeled the field "Admin ID" but used type="text".
                Based on the capstone, Admins register with an email and their account
                is verified by the ISO. So we use email as the login identifier.
                If your system uses a separate numeric Admin ID, change name="email"
                to name="admin_id" and update admin-login.php to match.
            -->

            <!-- SESSION MESSAGES -->
            <?php
                if (session_status() === PHP_SESSION_NONE) session_start();

                if (!empty($_SESSION['login_error'])) {
                    echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['login_error']) . '</div>';
                    unset($_SESSION['login_error']);
                }
                if (!empty($_SESSION['signup_success'])) {
                    echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['signup_success']) . '</div>';
                    unset($_SESSION['signup_success']);
                }
            ?>

            <div class="form-container">
                <form action="../php/admin-login.php" method="POST">

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

                    <div class="submit-container">
                        <button class="medium" type="submit">LOGIN</button>
                        or<br>
                        <a class="medium" onclick="dissolve('admin-signup.php')">SIGN-UP</a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="../script/animations.js"></script>
    <script src="../script/password.js"></script>
</body>

</html>
