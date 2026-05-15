<?php
/**
 * LumineSense – Email Verification Page
 * ---------------------------------------
 * Shared by both Admin and Faculty sign-up flows.
 *
 * GET  → shows the OTP input form
 * POST → validates the OTP; on success:
 *          Admin   → status = 'active'       → redirect to admin-login.php
 *          Faculty → status = 'pending_admin' → redirect to pending-approval.php
 */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../php/db_connect.php';
require_once '../php/mailer.php';

// Guard: must have gone through signup first
if (empty($_SESSION['pending_verification'])) {
    header('Location: ../index.php');
    exit;
}

$pv    = $_SESSION['pending_verification'];
$email = $pv['email'];
$role  = $pv['role'];   // 'admin' | 'faculty'
$name  = $pv['name'];

$table    = ($role === 'admin') ? 'admins' : 'faculty';
$errors   = [];
$success  = '';
$resent   = false;

// ── Handle RESEND request ─────────────────────────────────────────────────
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    $new_otp     = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $new_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

    $stmt = $conn->prepare("UPDATE $table SET otp_code = ?, otp_expires_at = ? WHERE email = ?");
    $stmt->bind_param('sss', $new_otp, $new_expires, $email);
    $stmt->execute();
    $stmt->close();

    sendVerificationEmail($email, $new_otp, $name);
    $resent = true;
}

// ── Handle POST (OTP submission) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entered_otp = trim($_POST['otp_code'] ?? '');

    if (empty($entered_otp)) {
        $errors[] = 'Please enter the verification code.';
    } elseif (!preg_match('/^\d{6}$/', $entered_otp)) {
        $errors[] = 'The code must be exactly 6 digits.';
    } else {
        // Fetch stored OTP + expiry
        $stmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM $table WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($db_otp, $db_expires);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || $db_otp === null) {
            $errors[] = 'We could not find your account. Please sign up again.';
        } elseif ($entered_otp !== $db_otp) {
            $errors[] = 'Incorrect code. Please check your email and try again.';
        } elseif ($db_expires === null || strtotime($db_expires) < time()) {
            $errors[] = 'This code has expired. Click "Resend Code" to get a new one.';
        } else {
            // ✅ OTP is correct and not expired
            if ($role === 'admin') {
                // Admin → email confirmed → is_verified = 1, can log in immediately
                $stmt = $conn->prepare("
                    UPDATE admins
                    SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL
                    WHERE email = ?
                ");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->close();

                unset($_SESSION['pending_verification']);
                $_SESSION['signup_success'] = 'Email verified! You can now log in.';
                header('Location: ../pages/admin-login.php');
                exit;

            } else {
                // Faculty → email confirmed → is_verified = 1
                // approved_by stays NULL until an admin approves them
                $stmt = $conn->prepare("
                    UPDATE faculty
                    SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL
                    WHERE email = ?
                ");
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->close();

                unset($_SESSION['pending_verification']);
                header('Location: ../pages/pending-approval.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
          crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
            crossorigin="anonymous"></script>

    <link rel="stylesheet" href="../css/global.css">
    <link rel="stylesheet" href="../css/containers.css">
    <link rel="stylesheet" href="../css/registration.css">

    <title>Verify Email – LumineSense</title>

    <style>
        /* ── OTP input row ── */
        .otp-inputs {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: 24px 0 8px;
        }
        .otp-inputs input {
            width: 52px;
            height: 60px;
            text-align: center;
            font-size: 1.6rem;
            font-weight: 700;
            border-radius: 10px;
            border: 2px solid #ccc;
            transition: border-color .2s;
        }
        .otp-inputs input:focus {
            outline: none;
            border-color: #4a6cf7;
        }
        .resend-row {
            text-align: center;
            margin-top: 6px;
            font-size: .88rem;
            color: #666;
        }
        .resend-row a {
            color: #4a6cf7;
            cursor: pointer;
            text-decoration: underline;
        }
        .email-hint {
            font-size: .9rem;
            color: #555;
            text-align: center;
            margin-bottom: 4px;
        }
        /* Countdown timer */
        #countdown { font-weight: 600; color: #e74c3c; }
    </style>
</head>
<body>
<div class="parent-container">
    <div class="registration-container">
        <div class="image-background <?= $role === 'faculty' ? 'faculty' : '' ?>">
            <img src="../images/logo.png" alt="LumineSense Logo">
        </div>

        <h4 class="pb-2 semibold">Verify Your Email</h4>

        <!-- Role badge -->
        <p class="text-center mb-1">
            <span class="badge <?= $role === 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                <?= ucfirst($role) ?> Account
            </span>
        </p>

        <p class="email-hint">
            A 6-digit code was sent to <strong><?= htmlspecialchars($email) ?></strong>.<br>
            It expires in <span id="countdown">15:00</span>.
        </p>

        <!-- Warnings / errors / success -->
        <?php if (!empty($_SESSION['email_warning'])): ?>
            <div class="alert alert-warning">
                <?= htmlspecialchars($_SESSION['email_warning']) ?>
                <?php unset($_SESSION['email_warning']); ?>
            </div>
        <?php endif; ?>

        <?php if ($resent): ?>
            <div class="alert alert-success">A new code has been sent to your email.</div>
        <?php endif; ?>

        <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
        <?php endforeach; ?>

        <!-- OTP form -->
        <div class="form-container">
            <form method="POST" action="" id="otp-form">
                <!-- Hidden field holds the combined OTP value -->
                <input type="hidden" name="otp_code" id="otp-hidden">

                <!-- Six individual digit boxes -->
                <div class="otp-inputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text"
                               inputmode="numeric"
                               maxlength="1"
                               class="otp-digit"
                               id="d<?= $i ?>"
                               autocomplete="off">
                    <?php endfor; ?>
                </div>

                <div class="resend-row">
                    Didn't receive it?
                    <a href="verify-email.php?resend=1">Resend Code</a>
                </div>

                <div class="submit-container mt-3">
                    <button class="medium w-100" type="submit" id="verify-btn" disabled>
                        VERIFY
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ── Auto-advance & backspace for OTP boxes ─────────────────────────── */
const digits  = Array.from(document.querySelectorAll('.otp-digit'));
const hidden  = document.getElementById('otp-hidden');
const btn     = document.getElementById('verify-btn');

digits.forEach((box, i) => {
    box.addEventListener('input', e => {
        const val = e.target.value.replace(/\D/, '');
        e.target.value = val;
        if (val && i < 5) digits[i + 1].focus();
        syncHidden();
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !e.target.value && i > 0) {
            digits[i - 1].focus();
        }
    });
    // Allow paste on first box
    box.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData)
                        .getData('text').replace(/\D/g, '').slice(0, 6);
        pasted.split('').forEach((ch, j) => {
            if (digits[j]) digits[j].value = ch;
        });
        syncHidden();
        digits[Math.min(pasted.length, 5)].focus();
    });
});

function syncHidden() {
    const code = digits.map(d => d.value).join('');
    hidden.value = code;
    btn.disabled = code.length < 6;
}

/* ── Countdown timer (15 min = 900 s) ──────────────────────────────── */
let remaining = 900;
const display = document.getElementById('countdown');

const timer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timer);
        display.textContent = 'Expired';
        display.style.color = '#e74c3c';
        btn.disabled = true;
        return;
    }
    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
    const s = String(remaining % 60).padStart(2, '0');
    display.textContent = `${m}:${s}`;
}, 1000);

/* ── Combine digits before submit ───────────────────────────────────── */
document.getElementById('otp-form').addEventListener('submit', syncHidden);
</script>
</body>
</html>