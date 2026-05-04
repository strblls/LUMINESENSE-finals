const allLightsBtn = document.getElementById('all-lights');
const statusText   = document.querySelector('h4.bold');
const btnContainer = allLightsBtn.closest('div[class^="all-lights"]');

let isOn = false;

allLightsBtn.addEventListener('click', () => {
    isOn = !isOn;

    if (isOn) {
        btnContainer.classList.replace('all-lights-off', 'all-lights-on');
        statusText.textContent = 'ON';
        statusText.classList.replace('off', 'on');
    } else {
        btnContainer.classList.replace('all-lights-on', 'all-lights-off');
        statusText.textContent = 'OFF';
        statusText.classList.replace('on', 'off');
    }
});