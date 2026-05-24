const allLightsBtn  = document.getElementById('all-lights');
const btnContainer  = allLightsBtn.closest('div[class^="all-lights"]');
const statusText    = btnContainer.parentElement.querySelector('h4.bold');

const row1Bulbs = document.querySelectorAll('.lighting-grid img:nth-of-type(-n+3)');
const row2Bulbs = document.querySelectorAll('.lighting-grid img:nth-of-type(n+4):nth-of-type(-n+6)');
const row3Bulbs = document.querySelectorAll('.lighting-grid img:nth-of-type(n+7):nth-of-type(-n+9)');

const bulbOff = '../../images/bulb-off.png';
const bulbOn  = '../../images/bulb-on.png';

function setRow(bulbs, state) {
    bulbs.forEach(img => img.src = state ? bulbOn : bulbOff);
}

async function toggleLight(row, state) {
    try {
        const res  = await fetch('http://127.0.0.1:3000/lighting/toggle', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ row, state })
        });
        const data = await res.json();
        console.log('Toggle result:', data);
    } catch (err) {
        console.error('Toggle failed:', err);
    }
}

// Row switches — sends to hardware AND updates bulb images
document.getElementById('row-1-switch').addEventListener('change', function () {
    toggleLight('1', this.checked ? 'on' : 'off');
    setRow(row1Bulbs, this.checked);
});

document.getElementById('row-2-switch').addEventListener('change', function () {
    toggleLight('2', this.checked ? 'on' : 'off');
    setRow(row2Bulbs, this.checked);
});

document.getElementById('row-3-switch').addEventListener('change', function () {
    toggleLight('3', this.checked ? 'on' : 'off');
    setRow(row3Bulbs, this.checked);
});

// All lights button
let isOn = false;

allLightsBtn.addEventListener('click', () => {
    isOn = !isOn;

    toggleLight('all', isOn ? 'on' : 'off');

    setRow(row1Bulbs, isOn);
    setRow(row2Bulbs, isOn);
    setRow(row3Bulbs, isOn);

    document.getElementById('row-1-switch').checked = isOn;
    document.getElementById('row-2-switch').checked = isOn;
    document.getElementById('row-3-switch').checked = isOn;

    btnContainer.classList.replace(
        isOn ? 'all-lights-off' : 'all-lights-on',
        isOn ? 'all-lights-on'  : 'all-lights-off'
    );

    statusText.textContent = isOn ? 'ON' : 'OFF';
    statusText.classList.replace(
        isOn ? 'off' : 'on',
        isOn ? 'on'  : 'off'
    );
});