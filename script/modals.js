function toggleModal(className) {
    const modal = document.querySelector(`.${className}`);
    modal.classList.toggle('active');
}

function showSignupModal() {
    const pass = document.getElementById('password').value;
    const confirm = document.getElementById('confirmPassword').value;

    if (pass !== confirm) {
        alert('Passwords do not match! Please check again.');
        return;
    }
    if (pass.length < 8) {
        alert('Password must be at least 8 characters long.');
        return;
    }

    document.getElementById('notify-modal').style.display = 'flex';
}

function hideSignupModal() {
    document.getElementById('notify-modal').style.display = 'none';
}