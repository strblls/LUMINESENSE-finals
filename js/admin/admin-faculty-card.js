function showToast(msg) {
    const t = document.getElementById('toastMsg');
    t.textContent = msg;
    t.classList.remove('show');
    void t.offsetWidth;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2700);
}

function updateStatus(val) {
    showToast('Status updated to: ' + val);
}

function savePermission(permission, value) {
    const form = new FormData();
    form.append('faculty_id', window.lumiFacultyId || 0);
    form.append('permission', permission);
    form.append('value', value ? 1 : 0);

    fetch('../../api/permissions.php', {
            method: 'POST',
            body: form
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) showToast('Permission updated!');
            else showToast('Failed to update permission.');
        });
}
