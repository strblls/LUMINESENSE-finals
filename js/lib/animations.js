// Page-to-page transition
window.addEventListener('pageshow', (event) => {
    document.body.style.opacity = '1';
});

function dissolve(url) {
    document.body.style.transition = 'opacity 0.5s ease';
    document.body.style.opacity = '0';
    setTimeout(() => {
        window.location.href = url;
    }, 500);
}

// Sidebar hover behavior uses Bootstrap's offcanvas API.
(function setupSidebarHover() {
    const trigger = document.getElementById('sidebarTrigger');
    const sidebarEl = document.getElementById('sidebarOffcanvas');
    if (!trigger || !sidebarEl || !window.bootstrap?.Offcanvas) return;

    const sidebar = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);
    let closeTimer = null;

    const openSidebar = () => {
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }
        sidebar.show();
    };

    const closeSidebar = () => {
        if (closeTimer) clearTimeout(closeTimer);
        closeTimer = setTimeout(() => sidebar.hide(), 120);
    };

    trigger.addEventListener('mouseenter', openSidebar);
    trigger.addEventListener('focus', openSidebar);
    sidebarEl.addEventListener('mouseenter', openSidebar);
    sidebarEl.addEventListener('mouseleave', closeSidebar);
    trigger.addEventListener('mouseleave', closeSidebar);
    trigger.addEventListener('click', event => event.preventDefault());
})();