    (function() {
        const page = window.location.pathname.split('/').pop();
        // Consumption Analytics subpage was commented out of the sidebar;
        // indices below match the remaining buttons in order.
        const map = {
            'admin-homepage.php': 0,
            'admin-overview.php': 1,
            'admin-room-manage.php': 1,
            'admin-reports.php': 2,
            'admin-faculty-management.php': 3,
            'admin-profile-settings.php': 4
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

    (function() {
        const sidebar = document.getElementById('sidebarOffcanvas');
        if (!sidebar) return;
        const items = sidebar.querySelectorAll('.sidebar-item');
        const baseMin = 64;
        let collapseTimer = null;
        sidebar.style.minWidth = baseMin + 'px';

        items.forEach(item => {
            const btn = item.querySelector('.nav-btn');
            const label = item.querySelector('.sidebar-label');
            const gap = 8;

            function expandForLabel() {
                if (!label || !btn) return;
                const prevDisplay = label.style.display;
                if (!label.offsetWidth) {
                    label.style.display = 'inline-block';
                    label.style.visibility = 'hidden';
                }
                const labelWidth = label.offsetWidth;
                if (!label.offsetWidth && prevDisplay === '') {
                    label.style.display = '';
                    label.style.visibility = '';
                }
                const btnWidth = btn.offsetWidth || 52;
                const desired = Math.ceil(btnWidth + gap + labelWidth + 16);
                sidebar.style.minWidth = Math.max(desired, baseMin) + 'px';
            }

            item.addEventListener('mouseenter', () => {
                if (collapseTimer) {
                    clearTimeout(collapseTimer);
                    collapseTimer = null;
                }
                expandForLabel();
            });

            item.addEventListener('mouseleave', () => {
                if (collapseTimer) clearTimeout(collapseTimer);
                collapseTimer = setTimeout(() => {
                    sidebar.style.minWidth = baseMin + 'px';
                }, 150);
            });

            if (btn) {
                btn.addEventListener('focus', () => {
                    if (collapseTimer) {
                        clearTimeout(collapseTimer);
                        collapseTimer = null;
                    }
                    expandForLabel();
                });
                btn.addEventListener('blur', () => {
                    if (collapseTimer) clearTimeout(collapseTimer);
                    collapseTimer = setTimeout(() => {
                        sidebar.style.minWidth = baseMin + 'px';
                    }, 150);
                });
            }
        });
    })();
