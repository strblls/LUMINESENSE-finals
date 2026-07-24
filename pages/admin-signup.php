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
    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/containers.css">
    <link rel="stylesheet" href="../css/registration.css">

    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

    <title>Admin Sign Up – LumineSense</title>
</head>

<body>
    <div class="return-container">
        <a class="medium d-flex justify-content-center align-items-center" onclick="dissolve('../index.php')">
            <i class="bi bi-house"></i>
        </a>
    </div>
    <div class="parent-container">
        <div class="registration-container">
            <div class="image-background">
                <img src="../images/logo.png">
            </div>
            <h4 class="pb-4 semibold">Administrator Sign Up</h4>

            <!-- SESSION MESSAGES -->
            <?php
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
                <form action="../php/admin-signup-process.php" method="POST" enctype="multipart/form-data">

                    <!-- Full Name Row -->
                    <div class="mb-3">
                        <div class="d-flex gap-2">
                            <div class="d-flex flex-column flex-grow-1">
                                <small class="text-muted mb-1">Last Name</small>
                                <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Family Name" value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" required>
                            </div>
                            <div class="d-flex flex-column flex-grow-1">
                                <small class="text-muted mb-1">First Name</small>
                                <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" required>
                            </div>
                            <div class="d-flex flex-column" style="width: 70px;">
                                <small class="text-muted mb-1">M.I.</small>
                                <input type="text" class="form-control" id="middle_initial" name="middle_initial" placeholder="M.I." maxlength="1" value="<?= htmlspecialchars($old['middle_initial'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email">Admin E-mail</label>
                        <input type="email" class="form-control" id="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label for="password">Password</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                            <i class="bi bi-eye-slash" id="togglePassword"></i>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrapper">
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                            <i class="bi bi-eye-slash" id="toggleConfirmPassword"></i>
                        </div>
                    </div>

                    <!-- ID Image Upload -->
                    <div class="mb-3">
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

                    <div class="submit-container" style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; width:100%;">
                        <button class="medium" type="submit" style="width: auto; padding: 6px 40px;">SIGN UP</button>
                        or<br>
                        <a type="button" class="medium" onclick="dissolve('admin-login.php')">LOG IN</a>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <script src="../script/modals.js"></script>
    <script src="../script/animations.js"></script>
    <script src="../script/password.js"></script>
    <script>
        let ocrDone = true;
        let ocrRunning = false;

        function preprocessImage(file) {
            return new Promise(function (resolve) {
                var img = new Image();
                img.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = img.width;
                    canvas.height = img.height;
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);

                    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                    var data = imageData.data;
                    for (var i = 0; i < data.length; i += 4) {
                        var gray = data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114;
                        var val = gray > 160 ? 255 : 0;
                        data[i] = data[i + 1] = data[i + 2] = val;
                    }
                    ctx.putImageData(imageData, 0, 0);
                    resolve(canvas);
                };
                img.src = URL.createObjectURL(file);
            });
        }

        document.getElementById('id_image').addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            ocrDone = false;
            ocrRunning = true;
            const statusEl = document.getElementById('ocr_status');
            const progressWrap = document.getElementById('ocr_progress_wrap');
            const progressBar = document.getElementById('ocr_progress');
            statusEl.textContent = 'Preprocessing image...';
            statusEl.className = 'text-info small mt-1';
            progressWrap.style.display = 'block';
            progressWrap.style.backgroundColor = '#f9edfa';
            progressBar.style.width = '0%';

            preprocessImage(file).then(function (canvas) {
                statusEl.textContent = 'Scanning ID...';
                return Tesseract.recognize(canvas, 'eng', {
                    logger: function (m) {
                        if (m.status === 'recognizing text') {
                            var pct = Math.round(m.progress * 100);
                            progressBar.style.width = pct + '%';
                            progressBar.setAttribute('aria-valuenow', pct);
                        }
                    }
                });
            }).then(function ({ data: { text } }) {
                const trimmed = text.trim();
                ocrDone = true;
                ocrRunning = false;
                document.getElementById('ocr_text').value = trimmed;
                if (trimmed) {
                    progressBar.style.width = '100%';
                    progressBar.setAttribute('aria-valuenow', 100);
                    statusEl.textContent = 'ID scanned successfully.';
                    statusEl.className = 'text-success small mt-1';
                } else {
                    progressBar.style.width = '100%';
                    progressBar.setAttribute('aria-valuenow', 100);
                    statusEl.textContent = 'No text could be read. Manual review will be required.';
                    statusEl.className = 'text-warning small mt-1';
                }
            }).catch(function (err) {
                ocrDone = true;
                ocrRunning = false;
                progressBar.style.width = '100%';
                progressBar.setAttribute('aria-valuenow', 100);
                statusEl.textContent = 'Failed to scan ID. Manual review will be required.';
                statusEl.className = 'text-danger small mt-1';
                console.error('Tesseract error:', err);
            });
        });

        document.querySelector('form').addEventListener('submit', function (e) {
            if (ocrRunning) {
                e.preventDefault();
                alert('Please wait — ID scan is still in progress.');
            }
        });
    </script>
</body>

</html>