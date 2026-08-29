// toggles.js
// Handles row switches and all-lights button.
// Persists every change to api/lights.php so the DB stays in sync.
// Hybrid instant path: if ESP_WS_URL (injected by faculty-home.php) is reachable on the same LAN,
// send the command via WebSocket to 192.168.137.126 for <50ms latency; always also persist to DB
// so luminesense-bet.site (https, remote) stays consistent via the 2s poll fallback.
// NOTE: https://luminesense-bet.site cannot ws:// to 192.168.137.126 from the public internet
// (mixed-content + private IP not routable). For true internet-instant, ESP must dial OUT
// as a WS client to a public relay — see note below.

const allLightsBtn = document.getElementById('all-lights');

// ── Instant ESP bridge (best-effort, non-blocking) ────────────────────────────
let _espWs = null, _espWsReady = false, _espWsTriedAt = 0;
function _tryEspWs(cmd) {
    try {
        // Only attempt when page exposes ESP_WS_URL and we are likely on the hotspot LAN.
        // From https://luminesense-bet.site the browser will block ws:// — we skip instantly.
        if (typeof ESP_WS_URL === 'undefined' || !ESP_WS_URL) return;
        if (location.protocol === 'https:' && ESP_WS_URL.startsWith('ws://')) {
            // Mixed-content would be blocked; don't spam console. DB persist will handle it.
            console.debug('[toggles] skip WS (https -> ws mixed-content), using DB only');
            return;
        }
        // Throttle probe: don't reconnect more than every 2s
        const now = Date.now();
        if (_espWs && _espWs.readyState === 1) {
            _espWs.send(cmd);
            console.debug('[toggles] WS instant ->', cmd);
            return;
        }
        if (now - _espWsTriedAt < 2000) return;
        _espWsTriedAt = now;
        _espWs = new WebSocket(ESP_WS_URL);
        _espWs.onopen = () => { _espWsReady = true; _espWs.send(cmd); console.debug('[toggles] WS open+send ->', cmd); };
        _espWs.onclose = () => { _espWsReady = false; };
        _espWs.onerror = () => { _espWsReady = false; /* silent fallback to DB */ };
        // Auto-close idle socket after 10s
        setTimeout(() => { try { if (_espWs && _espWs.readyState === 1) _espWs.close(); } catch(e){} }, 10000);
    } catch (e) { console.debug('[toggles] WS error', e); }
}
function _instantAndPersist(row, state, newGlobal) {
    // Map to ESP command format: ALL:ON / ROW1:OFF etc
    let cmd = null;
    if (row === 'all') cmd = state ? 'ALL:ON' : 'ALL:OFF';
    else if ([1,2,3,'1','2','3'].includes(row)) cmd = `ROW${row}:${state ? 'ON' : 'OFF'}`;
    if (cmd) _tryEspWs(cmd);
    return persistLight(row, state, newGlobal);
}

if (allLightsBtn) {

    const btnContainer = allLightsBtn.closest('div[id="allLightsContainer"]')
                      || allLightsBtn.closest('div[class^="all-lights"]');
    const statusText   = btnContainer ? btnContainer.parentElement.querySelector('h4.bold') : null;

    // Bulb image sets by row
    const row1Bulbs = document.querySelectorAll('.bulb-img[data-row="1"]');
    const row2Bulbs = document.querySelectorAll('.bulb-img[data-row="2"]');
    const row3Bulbs = document.querySelectorAll('.bulb-img[data-row="3"]');

const bulbOff = '../../images/bulb-off.png';
const bulbOn  = '../../images/bulb-on.png';

    // ── Helpers ───────────────────────────────────────────────────────────────
    function setRow(bulbs, state) {
        bulbs.forEach(img => img.src = state ? bulbOn : bulbOff);
    }

    async function persistLight(row, state, newGlobalLightStatus) {
        try {
            const cid = (typeof CLASSROOM_ID !== 'undefined') ? CLASSROOM_ID : 0;
            const form = new FormData();
            form.append('classroom_id', cid);
            form.append('row',   String(row));
            form.append('state', state ? 'on' : 'off');
            if (newGlobalLightStatus !== undefined) {
                form.append('new_global_light_status', newGlobalLightStatus);
            }
            await fetch('../../api/lights.php', { method: 'POST', body: form });
        } catch (e) {
            console.warn('persistLight error:', e);
        }
    }
    // Keep original persistLight for fallback; _instantAndPersist wraps it with WS attempt.

    // ── Row switches ──────────────────────────────────────────────────────────
    const rowConfig = [
        { switchId: 'row-1-switch', bulbs: row1Bulbs, row: 1 },
        { switchId: 'row-2-switch', bulbs: row2Bulbs, row: 2 },
        { switchId: 'row-3-switch', bulbs: row3Bulbs, row: 3 },
    ];

    function syncAllLightsStatus() {
        const sw1 = document.getElementById('row-1-switch');
        const sw2 = document.getElementById('row-2-switch');
        const sw3 = document.getElementById('row-3-switch');
        const allOn = (sw1 && sw1.checked) && (sw2 && sw2.checked) && (sw3 && sw3.checked);
        if (statusText) {
            statusText.textContent = allOn ? 'ON' : 'OFF';
            statusText.classList.replace(allOn ? 'off' : 'on', allOn ? 'on' : 'off');
        }
        if (btnContainer) {
            btnContainer.classList.replace(
                allOn ? 'all-lights-off' : 'all-lights-on',
                allOn ? 'all-lights-on'  : 'all-lights-off'
            );
        }
    }

    rowConfig.forEach(({ switchId, bulbs, row }) => {
        const sw = document.getElementById(switchId);
        if (!sw) return;
        sw.addEventListener('change', function () {
            setRow(bulbs, this.checked);
            const sw1 = document.getElementById('row-1-switch');
            const sw2 = document.getElementById('row-2-switch');
            const sw3 = document.getElementById('row-3-switch');
            const anyOn = (sw1 && sw1.checked) || (sw2 && sw2.checked) || (sw3 && sw3.checked);
            _instantAndPersist(row, this.checked, anyOn ? 'on' : 'off');
            syncAllLightsStatus();
            if (typeof syncRowPills === 'function') syncRowPills();
        });
    });

    // ── All-lights power button ───────────────────────────────────────────────
    allLightsBtn.addEventListener('click', () => {
        // Dynamically check if any row is currently checked
        const sw1 = document.getElementById('row-1-switch');
        const sw2 = document.getElementById('row-2-switch');
        const sw3 = document.getElementById('row-3-switch');
        
        const anyOn = (sw1 && sw1.checked) || (sw2 && sw2.checked) || (sw3 && sw3.checked);
        const targetState = !anyOn; // If any row is ON, click turns them all OFF. If all are OFF, turns them all ON.

        setRow(row1Bulbs, targetState);
        setRow(row2Bulbs, targetState);
        setRow(row3Bulbs, targetState);

        rowConfig.forEach(({ switchId }) => {
            const sw = document.getElementById(switchId);
            if (sw) sw.checked = targetState;
        });

        if (btnContainer) {
            btnContainer.classList.replace(
                targetState ? 'all-lights-off' : 'all-lights-on',
                targetState ? 'all-lights-on'  : 'all-lights-off'
            );
        }
        if (statusText) {
            statusText.textContent = targetState ? 'ON' : 'OFF';
            statusText.classList.replace(targetState ? 'off' : 'on', targetState ? 'on' : 'off');
        }

        // Sync the System Status panel badge
        const sLight = document.getElementById('statusLighting');
        if (sLight) {
            sLight.textContent = targetState ? 'ON' : 'OFF';
            sLight.className   = targetState ? 'text-success' : 'text-danger';
        }

        _instantAndPersist('all', targetState);
    });
}