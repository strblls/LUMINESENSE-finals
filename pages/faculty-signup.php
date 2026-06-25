<?php
    if (session_status() === PHP_SESSION_NONE) session_start();

    require_once __DIR__ . '/../php/db_connect.php';

    $old = $_SESSION['signup_form'] ?? [];
    unset($_SESSION['signup_form']);

    // Pull the list of departments for the dropdown.
    // The Principal creates these — Science, Math, TLE, etc.
    // If this list is empty, it means no departments exist yet,
    // which means the Principal hasn't set up the school yet.
    $departments = [];
    $deptResult = $conn->query("SELECT id, name FROM departments ORDER BY name");
    if ($deptResult) {
        while ($row = $deptResult->fetch_assoc()) {
            $departments[] = $row;
        }
    }
?>

<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
// ... rest of file
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

    <div class="parent-container-index">
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
                <form id="faculty-signup-form" action="../php/onboarding/faculty-signup-process.php" method="POST" enctype="multipart/form-data" onsubmit="return validateSignupForm();">
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

                    <!-- Department — matches faculty-signup-process.php's $_POST['department_id'] -->
                    <!-- This is a REQUIRED field; the backend rejects signup without it. -->
                    <div class="mb-3">
                        <label for="department_id">Department</label>
                        <select
                            class="form-control"
                            id="department_id"
                            name="department_id"
                            required>
                            <option value="" disabled <?= empty($old['department_id']) ? 'selected' : '' ?>>
                                Select your department
                            </option>
                            <?php foreach ($departments as $dept): ?>
                                <option
                                    value="<?= (int)$dept['id'] ?>"
                                    <?= (isset($old['department_id']) && (int)$old['department_id'] === (int)$dept['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($departments)): ?>
                            <small class="text-danger d-block mt-1">
                                No departments have been set up yet. Please contact your Principal before registering.
                            </small>
                        <?php endif; ?>
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
                    </div>

                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <div class="submit-container">
                            <button class="medium" type="submit">SIGN UP</button>
                            or<br>
                            <a class="medium" onclick="dissolve('faculty-login.php')">LOG IN</a>
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
        // Runs on submit. Returning true lets the form actually POST;
        // returning false stops it (used to show an alert instead).
        //
        // PREVIOUS BUG: this used to be showSignupModal() with a hardcoded
        // "return false" on the form tag, while the only real submit button
        // lived inside a commented-out modal. That meant clicking SIGN UP
        // never submitted anything. The modal is gone for now — this
        // function just validates and lets the native form submit happen.
        function validateSignupForm() {
            const pass    = document.getElementById('password').value;
            const confirm = document.getElementById('confirmPassword').value;
            const dept    = document.getElementById('department_id').value;

            if (!dept) {
                alert('Please select your department.');
                return false;
            }
            if (pass !== confirm) {
                alert('Passwords do not match! Please check again.');
                return false;
            }
            if (pass.length < 8) {
                alert('Password must be at least 8 characters long.');
                return false;
            }

            return true; // let the form submit normally
        }
    </script>
</body>

</html>