/**
 * topbar-fade.js
 * Shared topbar fade logic for admin & faculty pages.
 *
 * Fades out the topbar greeting / user info once the user has scrolled past
 * 25% of the current viewport height (window.innerHeight * 0.25). The trigger
 * is viewport-relative, so it is independent of the total page height.
 */
(function () {
    'use strict';

    function updateTopbarFade() {
        var scrolledPastQuarterViewport = window.scrollY > window.innerHeight * 0.25;
        document.querySelectorAll('.topbar-greeting, .topbar-user-info').forEach(function (el) {
            el.classList.toggle('hidden', scrolledPastQuarterViewport);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', updateTopbarFade);
    } else {
        updateTopbarFade();
    }

    window.addEventListener('scroll', updateTopbarFade, { passive: true });
})();