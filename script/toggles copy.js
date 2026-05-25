const allLightsBtn = document.getElementById('all-lights');

if (allLightsBtn) {
        const btnContainer = allLightsBtn.closest('div[class^="all-lights"]');
const statusText = btnContainer.parentElement.querySelector('h4.bold');

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

    document.getElementById('row-1-switch').addEventListener('change', function () {
        setRow(row1Bulbs, this.checked);
    });
    document.getElementById('row-2-switch').addEventListener('change', function () {
        setRow(row2Bulbs, this.checked);
    });
    document.getElementById('row-3-switch').addEventListener('change', function () {
        setRow(row3Bulbs, this.checked);
    });

    let isOn = false;
    allLightsBtn.addEventListener('click', () => {
        isOn = !isOn;
        setRow(row1Bulbs, isOn);
        setRow(row2Bulbs, isOn);
        setRow(row3Bulbs, isOn);
        document.getElementById('row-1-switch').checked = isOn;
        document.getElementById('row-2-switch').checked = isOn;
        document.getElementById('row-3-switch').checked = isOn;
        btnContainer.classList.replace(
            isOn ? 'all-lights-off' : 'all-lights-on',
            isOn ? 'all-lights-on' : 'all-lights-off'
        );
        statusText.textContent = isOn ? 'ON' : 'OFF';
        statusText.classList.replace(
            isOn ? 'off' : 'on',
            isOn ? 'on' : 'off'
        );
    });
}