<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/containers.css">
    <link rel="stylesheet" href="../css/registration.css">

    <title>Pending Approval – LumineSense</title>

    <style>
        .pending-card {
            max-width: 460px;
            margin: 0 auto;
            text-align: center;
            padding: 40px 32px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
        }
        .icon-ring {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #fff8e1;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.2rem;
        }
        .pending-card h4 { margin-bottom: 12px; font-weight: 700; }
        .pending-card p  { color: #555; line-height: 1.7; margin-bottom: 0; }
        .steps {
            text-align: left;
            background: #f8f9ff;
            border-radius: 10px;
            padding: 16px 20px;
            margin: 20px 0;
        }
        .steps li { margin-bottom: 8px; color: #444; font-size: .92rem; }
        .steps li .bi { color: #4a6cf7; margin-right: 6px; }
    </style>
</head>
<body>
<div class="parent-container">
    <div class="pending-card">
        <div class="image-background faculty" style="margin-bottom:16px;">
            <img src="../images/logo.png" alt="LumineSense Logo" style="height:60px;">
        </div>

        <div class="icon-ring">⏳</div>

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

        <a class="medium mt-3 d-inline-block" href="faculty-login.php"
           style="padding:10px 28px; border-radius:8px; text-decoration:none;">
            Back to Login
        </a>
    </div>
</div>
</body>
</html>