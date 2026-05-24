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
    let isOn = false;
    allLightsBtn.addEventListener('click', () => {
        isOn = !isOn;

        setRow(row1Bulbs, isOn);
        setRow(row2Bulbs, isOn);
        setRow(row3Bulbs, isOn);

        rowConfig.forEach(({ switchId }) => {
            const sw = document.getElementById(switchId);
            if (sw) sw.checked = isOn;
        });

        if (btnContainer) {
            btnContainer.classList.replace(
                isOn ? 'all-lights-off' : 'all-lights-on',
                isOn ? 'all-lights-on'  : 'all-lights-off'
            );
        }
        if (statusText) {
            statusText.textContent = isOn ? 'ON' : 'OFF';
            statusText.classList.replace(isOn ? 'off' : 'on', isOn ? 'on' : 'off');
        }

        // Sync the System Status panel badge
        const sLight = document.getElementById('statusLighting');
        if (sLight) {
            sLight.textContent = isOn ? 'ON' : 'OFF';
            sLight.className   = isOn ? 'text-success' : 'text-danger';
        }

        persistLight('all', isOn);
    });
}