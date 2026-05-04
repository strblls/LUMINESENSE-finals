function setupToggle(toggleId, inputId) {
    const toggle = document.querySelector(`#${toggleId}`);
    const input = document.querySelector(`#${inputId}`);
    toggle.addEventListener('click', (e) => {
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        e.target.classList.toggle('bi-eye');
    });
}

setupToggle('togglePassword', 'password');
setupToggle('toggleConfirmPassword', 'confirmPassword');