    // OTP verification flow for password change
    (function() {
        var modal = document.getElementById('changePwOtpModal');
        var stepSend = document.getElementById('otpStepSend');
        var stepVerify = document.getElementById('otpStepVerify');
        var stepSuccess = document.getElementById('otpStepSuccess');
        var sendBtn = document.getElementById('sendOtpBtn');
        var verifyBtn = document.getElementById('verifyOtpBtn');
        var otpInput = document.getElementById('otpInput');
        var feedback = document.getElementById('otpFeedback');
        var resendBtn = document.getElementById('resendChangeOtpBtn');
        var pwForm = document.getElementById('pwChangeForm');
        var cooldown = 0;

        function resetModal() {
            stepSend.style.display = 'block';
            stepVerify.style.display = 'none';
            stepSuccess.style.display = 'none';
            otpInput.value = '';
            feedback.textContent = '';
            sendBtn.disabled = false;
            sendBtn.textContent = 'Send Code';
        }

        modal.addEventListener('hidden.bs.modal', function() {
            // Don't reset if already verified
            if (stepSuccess.style.display !== 'block') resetModal();
        });

        modal.addEventListener('shown.bs.modal', function() {
            resetModal();
        });

        sendBtn.addEventListener('click', function() {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Sending...';
            fetch('../../api/send-change-otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        stepSend.style.display = 'none';
                        stepVerify.style.display = 'block';
                        cooldown = 60;
                        tickResend();
                    } else {
                        feedback.textContent = d.message || 'Failed to send.';
                        sendBtn.disabled = false;
                        sendBtn.textContent = 'Send Code';
                    }
                })
                .catch(function() {
                    feedback.textContent = 'Network error.';
                    sendBtn.disabled = false;
                    sendBtn.textContent = 'Send Code';
                });
        });

        function tickResend() {
            if (cooldown <= 0) {
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend Code';
                return;
            }
            resendBtn.disabled = true;
            resendBtn.textContent = 'Resend Code (' + cooldown + 's)';
            cooldown--;
            setTimeout(tickResend, 1000);
        }

        resendBtn.addEventListener('click', function() {
            resendBtn.disabled = true;
            resendBtn.textContent = 'Sending...';
            fetch('../../api/send-change-otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        cooldown = 60;
                        tickResend();
                        feedback.textContent = 'Code resent.';
                        feedback.className = 'small mb-2 text-success';
                    } else {
                        feedback.textContent = d.message || 'Failed.';
                        feedback.className = 'small mb-2 text-danger';
                        resendBtn.disabled = false;
                        resendBtn.textContent = 'Resend Code';
                    }
                })
                .catch(function() {
                    feedback.textContent = 'Network error.';
                    feedback.className = 'small mb-2 text-danger';
                    resendBtn.disabled = false;
                    resendBtn.textContent = 'Resend Code';
                });
        });

        verifyBtn.addEventListener('click', function() {
            var otp = otpInput.value.trim();
            if (!/^\d{6}$/.test(otp)) {
                feedback.textContent = 'Enter a valid 6-digit code.';
                feedback.className = 'small mb-2 text-danger';
                return;
            }
            verifyBtn.disabled = true;
            verifyBtn.textContent = 'Verifying...';
            var body = new URLSearchParams();
            body.append('otp', otp);
            fetch('../../api/verify-change-otp.php', {
                method: 'POST',
                body: body
            }).then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    stepVerify.style.display = 'none';
                    stepSuccess.style.display = 'block';
                    pwForm.style.display = 'block';
                    setTimeout(function() {
                        var bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) bsModal.hide();
                    }, 1000);
                } else {
                    feedback.textContent = d.message || 'Invalid code.';
                    feedback.className = 'small mb-2 text-danger';
                    verifyBtn.disabled = false;
                    verifyBtn.textContent = 'Verify';
                }
            })
            .catch(function() {
                feedback.textContent = 'Network error.';
                feedback.className = 'small mb-2 text-danger';
                verifyBtn.disabled = false;
                verifyBtn.textContent = 'Verify';
            });
        });
    })();

    document.addEventListener('DOMContentLoaded', function() {
        var sidebarItems = document.querySelectorAll('.profile-sidebar .sidebar-item');
        var sections = {
            contact: document.getElementById('section-contact'),
            teaching: document.getElementById('section-teaching'),
            credentials: document.getElementById('section-credentials'),
            about: document.getElementById('section-about')
        };

        sidebarItems.forEach(function(item) {
            item.addEventListener('click', function() {
                var section = this.getAttribute('data-section');
                if (!section || !sections[section]) return;

                sidebarItems.forEach(function(si) { si.classList.remove('active'); });
                this.classList.add('active');

                Object.keys(sections).forEach(function(key) {
                    sections[key].classList.remove('active');
                });
                sections[section].classList.add('active');
            });
        });
    });