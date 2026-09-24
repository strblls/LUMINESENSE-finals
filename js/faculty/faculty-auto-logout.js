// js/faculty/faculty-auto-logout.js
// Automatic logout sequence: when the faculty member's current class session
// ends (faculty-session-watch active: true -> false), show a warning overlay
// with a countdown, then log them out through handlers/logout.php.
//
// Loaded from the shared faculty topbar so it applies to every faculty page.

(function () {
    var POLL_MS = 5000;          // match existing status-poll cadence
    var LOGOUT_GRACE = 30;       // seconds shown before auto-logout fires
    var NEVER_ACTIVE_ARM_MS = 10 * 60 * 1000; // no-schedule logins: arm logout
                                 // escalation after this long without any
                                 // active class (Option A)
    var wasActive = false;       // only react to a real active->ended transition
    var neverActiveSince = null; // first poll tick with no active class yet
    var dismissed = false;       // user chose "Stay signed in" this session
    var seqTimer = null;
    var remaining = 0;

    var overlayEl = document.getElementById('autoLogoutOverlay');
    var countdownEl = document.getElementById('autoLogoutCountdown');
    var nowBtn = document.getElementById('autoLogoutNowBtn');
    var cancelBtn = document.getElementById('autoLogoutCancelBtn');

    function doLogout() {
        if (seqTimer) clearInterval(seqTimer);
        seqTimer = null;
        window.lumiPostClassArmed = false;
        sessionStorage.removeItem('lumi_postclass_armed');
        if (typeof dissolve === 'function') {
            dissolve('../../handlers/logout.php');
        } else {
            window.location.href = '../../handlers/logout.php';
        }
    }

    function cancelSequence() {
        if (seqTimer) clearInterval(seqTimer);
        seqTimer = null;
        if (overlayEl) overlayEl.style.display = 'none';
    }

    function dismissSequence() {
        dismissed = true;
        window.lumiPostClassArmed = true;
        sessionStorage.setItem('lumi_postclass_armed', '1');
        cancelSequence();
    }

    function startSequence() {
        if (seqTimer) return;
        remaining = LOGOUT_GRACE;
        if (countdownEl) countdownEl.textContent = String(remaining);
        if (overlayEl) overlayEl.style.display = 'flex';

        seqTimer = setInterval(function () {
            remaining--;
            if (countdownEl) countdownEl.textContent = String(Math.max(remaining, 0));
            if (remaining <= 0) doLogout();
        }, 1000);
    }

    async function poll() {
        try {
            var res = await fetch('../../api/faculty-session-watch.php', { cache: 'no-store' });
            if (res.status === 401) {
                window.lumiPostClassArmed = false;
                sessionStorage.removeItem('lumi_postclass_armed');
                return; // already logged out elsewhere
            }
            if (!res.ok) return;
            var data = await res.json();
            if (!data.success) return;

            if (data.active) {
                wasActive = true;
                dismissed = false; // a new session started - re-arm the watcher
                neverActiveSince = null;
                window.lumiPostClassArmed = false;
                sessionStorage.removeItem('lumi_postclass_armed');
                if (typeof window.lumiCancelFiveMinEscalation === 'function') {
                    window.lumiCancelFiveMinEscalation();
                }
                cancelSequence(); // extension granted / still running
            } else if (wasActive && !dismissed) {
                startSequence();
            } else if (!wasActive) {
                // Never had an active class this login (e.g. signed in with no
                // schedule): after sustained inactivity, arm the same 60s-PIN
                // → 5-min-warning → logout chain the post-class flow uses.
                // Verifying the PIN keeps working (hidePageTimeout clears the
                // 5-min timer); only a truly idle user reaches logout.
                if (neverActiveSince === null) neverActiveSince = Date.now();
                if (!window.lumiPostClassArmed &&
                    Date.now() - neverActiveSince >= NEVER_ACTIVE_ARM_MS) {
                    window.lumiPostClassArmed = true;
                    sessionStorage.setItem('lumi_postclass_armed', '1');
                }
            }
        } catch (e) { /* transient network error - retry on next tick */ }
    }

    if (nowBtn) nowBtn.addEventListener('click', doLogout);
    if (cancelBtn) cancelBtn.addEventListener('click', dismissSequence);

    // Expose the shared logout + post-class armed state so the 5-min
    // escalation in faculty-topbar.js can trigger/clear it.
    window.lumiDoLogout = doLogout;
    window.lumiPostClassArmed = sessionStorage.getItem('lumi_postclass_armed') === '1';

    // Skip entirely on pages with no faculty scope (shouldn't happen via topbar).
    if (!document.getElementById('autoLogoutOverlay')) return;

    poll();
    setInterval(poll, POLL_MS);
})();