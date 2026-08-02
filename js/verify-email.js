/* ── Auto-advance & backspace for OTP boxes ──────────────────────────────── */
const digits  = Array.from(document.querySelectorAll('.otp-digit'));
const hidden  = document.getElementById('otp-hidden');

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
}

/* ── Validate on submit ──────────────────────────────────────────────────── */
document.getElementById('otp-form').addEventListener('submit', function(e) {
    syncHidden();
    if (hidden.value.length < 6) {
        e.preventDefault();
        new bootstrap.Modal(document.getElementById('emptyOtpModal')).show();
    }
});

/* ── Countdown timer (15 min = 900 s) ────────────────────────────────────── */
let remaining = 900;
const display = document.getElementById('countdown');

const timer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timer);
        display.textContent = 'Expired';
        display.style.color = '#e74c3c';
        return;
    }
    const m = String(Math.floor(remaining / 60)).padStart(2, '0');
    const s = String(remaining % 60).padStart(2, '0');
    display.textContent = `${m}:${s}`;
}, 1000);
