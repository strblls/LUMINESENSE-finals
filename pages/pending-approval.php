<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!--External links-->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <!--Relative links-->
    <link rel="icon" href="../images/icon.png">
    <link rel="stylesheet" href="../css/base/global.css">
    <link rel="stylesheet" href="../css/base/containers.css">
    <link rel="stylesheet" href="../css/pages/registration.css">

    <title>Pending Approval - LumineSense</title>

    <link rel="stylesheet" href="../css/pages/pending-approval.css">
</head>
<body>
<div class="parent-container justify-content-center align-items-center">
    <div class="pending-card">

        <div class="icon-ring image-background faculty"><img src="../images/logo.png" alt="LumineSense Logo" style="height:60px;"></div>

        <h4>Email Verified!</h4>
        <p>
            Your email address has been confirmed.<br>
            <strong>One more step:</strong> an Administrator needs to approve your account before you can log in.
        </p>

        <ul class="steps list-unstyled">
            <li><i class="bi bi-check-circle-fill text-success"></i> Email verified</li>
            <li><i class="bi bi-hourglass-split"></i> Waiting for Admin approval</li>
            <li><i class="bi bi-lock"></i> Log in once approved</li>
        </ul>

        <p style="font-size:.88rem; color:#888;">
            Please reach out to your school's facility manager or information officer and let them know your registered email so they can approve your account.
        </p>

        <?php $login_page = ($_GET['type'] ?? '') === 'admin' ? 'admin-login.php' : 'faculty-login.php'; ?>
        <button class="medium mt-3 d-inline-block" href="<?= $login_page ?>"
                onclick="dissolve('<?= $login_page ?>')">
            Back to Login
        </button>
    </div>
</div>

<script src="../js/lib/animations.js"></script>
</body>
</html>