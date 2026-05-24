<?php
?>
<!-- SIDEBAR LEFT -->
<div class="offcanvas offcanvas-start" tabindex="-1" id="sidebarOffcanvas"
    aria-labelledby="sidebarOffcanvasLabel">
    <div class="offcanvas-header justify-content-center">
        <img src="../../images/logo.png" class="logo" onclick="dissolve('faculty-homepage.php')">
    </div>
    <div class="offcanvas-body align-items-center d-flex flex-column">
        <button class="wb-2" onclick="dissolve('faculty-lighting.php')"><i class="bi bi-lightbulb"></i></button>
        <button class="wb-2" onclick="dissolve('faculty-readings.php')"><i class="bi bi-broadcast"></i></button>
        <button class="wb-2" onclick="dissolve('faculty-timetable.php')"><i class="bi bi-calendar-event"></i></button>
        <button class="wb-2" onclick="dissolve('faculty-profile-settings.php')"><i class="bi bi-gear"></i></button>
    </div>
    <div class="offcanvas-footer">
        <img src="../../images/team-logo.png" class="logo">
    </div>
</div>

<script>
(function () {
    const page = window.location.pathname.split('/').pop();
    const map = {
        'faculty-lighting.php':           0,
        'faculty-readings.php':          2,
        'faculty-timetable.php':        1,
        'faculty-profile-settings.php':   3,
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