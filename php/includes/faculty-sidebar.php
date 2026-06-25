<?php

// Check if the logged-in faculty member is a Department Head
$is_head = $_SESSION['is_head'] ?? false;
?>

<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas">
    <div class="offcanvas-header justify-content-start">
        <img src="../../images/logo.png" class="logo" alt="Logo">
    </div>
    <div class="offcanvas-body align-items-start justify-content-start d-flex flex-column gap-2">
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Home" onclick="dissolve('faculty-home.php')">
                <i class="bi bi-house-door"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Home</h3>
        </div>
        <!-- <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Readings" onclick="dissolve('faculty-readings.php')">
                <i class="bi bi-broadcast"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Readings</h3>
        </div> -->
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Timetable" onclick="dissolve('faculty-timetable.php')">
                <i class="bi bi-calendar-event"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Timetable</h3>
        </div>
        <?php if ($is_head): ?>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Head Timetable" onclick="dissolve('faculty-head-timetable.php')">
                <i class="bi bi-calendar3-range-fill"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Manage Schedules</h3>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-row justify-content-center align-items-center gap-2 sidebar-item">
            <button class="nav-btn" title="Profile Settings" onclick="dissolve('faculty-profile-settings.php')">
                <i class="bi bi-gear"></i>
            </button>
            <h3 class="bold mb-0 sidebar-label">Settings</h3>
        </div>
    </div>
    <div class="offcanvas-footer align-items-start justify-content-start d-flex">
        <img src="../../images/team-logo.png" alt="Team Logo" style="width:4rem;">
    </div>
</div>

<script>
    (function() {
        // 1. Get the current page filename
        const page = window.location.pathname.split('/').pop();
        if (!page) return;

        // 2. Find all sidebar items and loop through them
        const sidebarItems = document.querySelectorAll('#sidebarOffcanvas .sidebar-item');
        
        sidebarItems.forEach(item => {
            const btn = item.querySelector('.nav-btn');
            if (!btn) return;

            // 3. Extract the target page from the onclick attribute (e.g., dissolve('faculty-home.php'))
            const onclickText = btn.getAttribute('onclick') || '';
            
            // Check if the current filename is referenced inside the button's click event
            if (onclickText.includes(page)) {
                // Apply the active styling seamlessly regardless of button order!
                btn.style.backgroundColor = 'var(--secondary-color-4)';
                btn.style.boxShadow = '0 0 0 3px rgba(155,0,233,0.3)';
            }
        });
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