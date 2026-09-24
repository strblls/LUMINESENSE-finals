// toggles.js
// Handles row switches and all-lights button.
// Persists every change to api/lights.php so the DB stays in sync.
// Remote-hosted: luminesense-bet.site is source of truth; ESP polls it via pollDatabase().

// ── Shared lighting-grid busy overlay (faculty grid only) ───────────────────
// Block + queue: while a toggle/gesture POST is in flight the grid shows a
// buffering overlay and ignores extra input. Hides on API response.
// Exposed on window so the gesture module (initialize-gesture.js) reuses it.
window.__lightGridBusy = window.__lightGridBusy || (function () {
    let count = 0;
    const DEFAULT_LABEL = 'Updating lights...';
    function els() {
        return {
            overlay: document.getElementById('lightGridBusyOverlay'),
            label: document.getElementById('lightGridBusyLabel'),
            content: document.getElementById('lightingControlsContent'),
            switches: ['row-1-switch', 'row-2-switch', 'row-3-switch']
                .map(id => document.getElementById(id))
                .filter(Boolean),
        };
    }
    function begin(label) {
        count += 1;
        const { overlay, label: labelEl, content, switches } = els();
        if (labelEl) labelEl.textContent = label || DEFAULT_LABEL;
        if (overlay) overlay.style.display = 'flex';
        if (content) content.classList.add('is-busy');
        switches.forEach(sw => {
            if (sw.dataset.busyPrev === undefined) sw.dataset.busyPrev = sw.disabled ? '1' : '0';
            sw.disabled = true;
        });
    }
    function end() {
        count = Math.max(0, count - 1);
        if (count > 0) return;
        const { overlay, label: labelEl, content, switches } = els();
        if (overlay) overlay.style.display = 'none';
        if (labelEl) labelEl.textContent = DEFAULT_LABEL;
        if (content) content.classList.remove('is-busy');
        switches.forEach(sw => {
            sw.disabled = sw.dataset.busyPrev === '1';
            delete sw.dataset.busyPrev;
        });
    }
    function isBusy() { return count > 0; }
    return { begin, end, isBusy };
})();

const allLightsBtn = document.getElementById('all-lights');

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
        const busy = window.__lightGridBusy;
        if (busy) busy.begin();
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 8000);
        try {
            const cid = (typeof CLASSROOM_ID !== 'undefined') ? CLASSROOM_ID : 0;
            const form = new FormData();
            form.append('classroom_id', cid);
            form.append('row',   String(row));
            form.append('state', state ? 'on' : 'off');
            if (newGlobalLightStatus !== undefined) {
                form.append('new_global_light_status', newGlobalLightStatus);
            }
            const res = await fetch('../../api/lights.php', { method: 'POST', body: form, signal: ctrl.signal });
            if (!res.ok && typeof showToast === 'function') {
                showToast('Light update failed. Will retry on next sync.', 'error');
            }
        } catch (e) {
            console.warn('persistLight error:', e);
            if (typeof showToast === 'function' && e && e.name !== 'AbortError') {
                showToast('Network error updating lights.', 'error');
            } else if (typeof showToast === 'function') {
                showToast('Light update timed out. Will retry on next sync.', 'error');
            }
        } finally {
            clearTimeout(timer);
            if (busy) busy.end();
        }
    }

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
            const busy = window.__lightGridBusy;
            if (busy && busy.isBusy()) {
                // Block re-entry while a request is in flight: revert and wait.
                this.checked = !this.checked;
                return;
            }
            setRow(bulbs, this.checked);
            const sw1 = document.getElementById('row-1-switch');
            const sw2 = document.getElementById('row-2-switch');
            const sw3 = document.getElementById('row-3-switch');
            const anyOn = (sw1 && sw1.checked) || (sw2 && sw2.checked) || (sw3 && sw3.checked);
            persistLight(row, this.checked, anyOn ? 'on' : 'off');
            syncAllLightsStatus();
            if (typeof syncRowPills === 'function') syncRowPills();
        });
    });

    // ── All-lights power button ───────────────────────────────────────────────
    allLightsBtn.addEventListener('click', () => {
        const busy = window.__lightGridBusy;
        if (busy && busy.isBusy()) return; // block while a request is in flight
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

        persistLight('all', targetState);
    });
}