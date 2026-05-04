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

// For sidebar (left) in homepages
const trigger = document.getElementById('sidebarTrigger');
const sidebarEl = document.getElementById('sidebarOffcanvas');
const sidebar = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);

trigger.addEventListener('mouseenter', () => sidebar.show());
sidebarEl.addEventListener('mouseleave', () => sidebar.hide());

// For sidebar (right) in homepages
const trigger2 = document.getElementById('sidebarTrigger2');
const sidebarEl2 = document.getElementById('profileOffcanvas');
const sidebar2 = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl2);

trigger2.addEventListener('mouseenter', () => sidebar2.show());
sidebarEl2.addEventListener('mouseleave', () => sidebar2.hide());