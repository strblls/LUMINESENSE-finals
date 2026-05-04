const allLightsBtn = document.getElementById('all-lights');
const statusText = document.querySelector('h4.bold');
const btnContainer = allLightsBtn.closest('div[class^="all-lights"]');

const row1Bulbs = document.querySelectorAll('.lighting-grid img:nth-child(-n+3)');
const row2Bulbs = document.querySelectorAll('.lighting-grid img:nth-child(n+5):nth-child(-n+7)');
const row3Bulbs = document.querySelectorAll('.lighting-grid img:nth-child(n+9):nth-child(-n+11)');

const bulbOff = '../../images/bulb-off.png';
const bulbOn = '../../images/bulb-on.png';

function setRow(bulbs, state) {
    bulbs.forEach(img => img.src = state ? bulbOn : bulbOff);
}

//Row switches
document.getElementById('row-1-switch').addEventListener('change', function () {
    setRow(row1Bulbs, this.checked);
});
document.getElementById('row-2-switch').addEventListener('change', function () {
    setRow(row2Bulbs, this.checked);
});
document.getElementById('row-3-switch').addEventListener('change', function () {
    setRow(row3Bulbs, this.checked);
});

//all-lights button
let isOn = false;
allLightsBtn.addEventListener('click', () => {
    isOn = !isOn;

    //toggle all bulbs
    setRow(row1Bulbs, isOn);
    setRow(row2Bulbs, isOn);
    setRow(row3Bulbs, isOn);

    //Sync row switches
    document.getElementById('row-1-switch').checked = isOn;
    document.getElementById('row-2-switch').checked = isOn;
    document.getElementById('row-3-switch').checked = isOn;

    //update button CSS
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