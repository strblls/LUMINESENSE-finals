    (function() {
        var btn = document.getElementById('resendBtn');
        var cooldown = window.lumiResetOtpCooldown || 0;

        function tick() {
            if (cooldown <= 0) {
                btn.disabled = false;
                btn.textContent = 'Resend Code';
                return;
            }
            btn.textContent = 'Resend Code (' + cooldown + 's)';
            cooldown--;
            setTimeout(tick, 1000);
        }
        if (cooldown > 0) tick();

        btn.addEventListener('click', function() {
            btn.disabled = true;
            btn.textContent = 'Sending...';
            fetch('../api/resend-reset-otp.php', { method: 'POST' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        cooldown = 60;
                        tick();
                    } else {
                        alert(d.message || 'Failed to resend.');
                        btn.disabled = false;
                        btn.textContent = 'Resend Code';
                    }
                })
                .catch(function() {
                    alert('Network error.');
                    btn.disabled = false;
                    btn.textContent = 'Resend Code';
                });
        });
    })();
