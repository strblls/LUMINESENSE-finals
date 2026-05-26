// toggles.js
// Handles row switches and all-lights button.
// Persists every change to api/lights.php so the DB stays in sync.

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

    async function persistLight(row, state) {
        try {
            const cid = (typeof CLASSROOM_ID !== 'undefined') ? CLASSROOM_ID : 0;
            const form = new FormData();
            form.append('classroom_id', cid);
            form.append('row',   String(row));
            form.append('state', state ? 'on' : 'off');
            await fetch('../../api/lights.php', { method: 'POST', body: form });
        } catch (e) {
            console.warn('persistLight error:', e);
        }
    }

    // ── Row switches ──────────────────────────────────────────────────────────
    const rowConfig = [
        { switchId: 'row-1-switch', bulbs: row1Bulbs, row: 1 },
        { switchId: 'row-2-switch', bulbs: row2Bulbs, row: 2 },
        { switchId: 'row-3-switch', bulbs: row3Bulbs, row: 3 },
    ];

    rowConfig.forEach(({ switchId, bulbs, row }) => {
        const sw = document.getElementById(switchId);
        if (!sw) return;
        sw.addEventListener('change', function () {
            setRow(bulbs, this.checked);
            persistLight(row, this.checked);
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

        persistLight('all', targetState);
    });
}