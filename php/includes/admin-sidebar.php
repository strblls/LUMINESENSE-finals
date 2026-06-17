<?php

/** @var string $initials */
/** @var string $admin_name */
/** @var string $admin_email */
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-start">
        <img src="../../images/logo.png" class="logo" alt="Logo">
    </div>
    <div class="offcanvas-body align-items-start justify-content-start d-flex flex-column gap-2">
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Home" onclick="dissolve('admin-homepage.php')">
                <i class="bi bi-house-door"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Home</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Room Management" onclick="dissolve('admin-room-manage.php')">
                <i class="fa-solid fa-person-shelter"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Rooms</h3>
        </div>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Analytics" onclick="dissolve('admin-analytics.php')">
                <i class="bi bi-clipboard2-data"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Analytics</h3>
        </div>
        <!-- <button class="nav-btn" title="Reports" onclick="dissolve('admin-reports.php')">
            <i class="bi bi-exclamation-triangle"></i>
        </button> -->
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Faculty" onclick="dissolve('admin-faculty-management.php')">
                <i class="bi bi-people"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Faculty</h3>
        </div>
        <!-- <button class="nav-btn" title="Profile Settings" onclick="dissolve('admin-profile-settings.php')">
            <i class="bi bi-gear"></i>
        </button> -->
    </div>
    <div class="offcanvas-footer align-items-start justify-content-start d-flex">
        <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
    </div>
</div>

<script>
    (function() {
        const page = window.location.pathname.split('/').pop();
        const map = {
            'admin-homepage.php': 0,
            'admin-room-manage.php': 1,
            'admin-analytics.php': 2,
            'admin-faculty-management.php': 3,
            'admin-faculty-card.php': 3,
            'admin-profile-settings.php': 4,
            'admin-reports.php': null,
        };
        const index = map[page];
        if (index !== null && index !== undefined) {
            const btns = document.querySelectorAll('#sidebarOffcanvas .nav-btn');
            if (btns[index]) {
                btns[index].style.backgroundColor = 'var(--secondary-color-4)';
                btns[index].style.boxShadow = '0 0 0 3px rgba(155,0,233,0.3)';
            }
        }
    })();
</script>

<script>
    (function() {
        const sidebar = document.getElementById('sidebarOffcanvas');
        if (!sidebar) return;
        const items = sidebar.querySelectorAll('.sidebar-item');
        const baseMin = 64; // collapsed min width in px
        let collapseTimer = null;
        // ensure initial state
        sidebar.style.minWidth = baseMin + 'px';

        items.forEach(item => {
            const btn = item.querySelector('.nav-btn');
            const label = item.querySelector('.sidebar-label');
            const gap = 8; // distance between button and label

            function expandForLabel() {
                if (!label || !btn) return;
                // measure label width even if visually hidden
                const prevDisplay = label.style.display;
                if (!label.offsetWidth) {
                    // temporarily show invisibly to measure
                    label.style.display = 'inline-block';
                    label.style.visibility = 'hidden';
                }
                const labelWidth = label.offsetWidth;
                // revert temp styles
                if (!label.offsetWidth && prevDisplay === '') {
                    label.style.display = '';
                    label.style.visibility = '';
                }
                const btnWidth = btn.offsetWidth || 52;
                const desired = Math.ceil(btnWidth + gap + labelWidth + 16); // extra padding
                sidebar.style.minWidth = Math.max(desired, baseMin) + 'px';
            }

            item.addEventListener('mouseenter', () => {
                if (collapseTimer) { clearTimeout(collapseTimer); collapseTimer = null; }
                expandForLabel();
            });

            item.addEventListener('mouseleave', () => {
                if (collapseTimer) clearTimeout(collapseTimer);
                collapseTimer = setTimeout(() => { sidebar.style.minWidth = baseMin + 'px'; }, 150);
            });

            if (btn) {
                btn.addEventListener('focus', () => {
                    if (collapseTimer) { clearTimeout(collapseTimer); collapseTimer = null; }
                    expandForLabel();
                });
                btn.addEventListener('blur', () => {
                    if (collapseTimer) clearTimeout(collapseTimer);
                    collapseTimer = setTimeout(() => { sidebar.style.minWidth = baseMin + 'px'; }, 150);
                });
            }
        });
    })();
</script>