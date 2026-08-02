function openImageModal(src) {
    document.getElementById('imgModalSrc').src = src;
    document.getElementById('imgModal').style.display = 'flex';
}

function closeImageModal() {
    document.getElementById('imgModal').style.display = 'none';
}

function openRejectModal() {
    new bootstrap.Modal(document.getElementById('rejectFacultyModal')).show();
}
