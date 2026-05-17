const enableBtn  = document.getElementById('enableCameraBtn');
const disableBtn = document.getElementById('disableCameraBtn');
const img        = document.getElementById('gestureStream');
const gestureLabel = document.getElementById('gestureLabel');
const gestureAccuracy = document.getElementById('gestureAccuracy');
const gestureProgress = document.getElementById('gestureProgress');

const statusUrl = 'http://127.0.0.1:3000/gesture/status';
let statusTimer = null;

const progressClasses = [
    'bg-success',
    'bg-primary',
    'bg-danger',
    'bg-info',
    'bg-warning',
    'bg-dark',
    'bg-secondary'
]

function formatGestureLabel(label) {
    if (!label || label === 'No Gesture') {
        return 'No Gesture';
    }

    return label;
}

function setProgressStyle(gesture, confidence) {
    const styleMap = {
        Open_Palm: 'bg-success',
        Thumb_Up: 'bg-primary',
        Closed_Fist: 'bg-danger',
        Pointing_Up: 'bg-info',
        Victory: 'bg-warning',
        ILoveYou: 'bg-dark'
    };

    gestureProgress.classList.remove(...progressClasses);
    gestureProgress.classList.add(styleMap[gesture] || 'bg-secondary');

    const width = Math.max(0, Math.min(100, confidence));
    gestureProgress.style.width = `${width}%`;
    gestureProgress.setAttribute('aria-valuenow', String(width));
    gestureProgress.textContent = `${width}%`;
    gestureAccuracy.textContent = `${width}%`;
}

function updateGestureView(status) {
    const gesture = formatGestureLabel(status?.gesture);
    const confidence = Number(status?.confidence ?? 0);

    gestureLabel.textContent = gesture;
    setProgressStyle(gesture, confidence);
}

async function refreshGestureStatus() {
    try {
        const res = await fetch(statusUrl);
        if (!res.ok) {
            return;
        }

        const data = await res.json();
        updateGestureView(data);
    } catch (error) {
        console.error('Error reading gesture status:', error);
    }
}

function startStatusPolling() {
    if (statusTimer) {
        clearInterval(statusTimer);
    }

    refreshGestureStatus();
    statusTimer = setInterval(refreshGestureStatus, 500);
}

function stopStatusPolling() {
    if (statusTimer) {
        clearInterval(statusTimer);
        statusTimer = null;
    }
}

enableBtn.style.display = 'block';

function resetEnableState() {
    enableBtn.disabled = false;
    enableBtn.textContent = 'Enable Camera';
    enableBtn.style.display = 'block';
    disableBtn.style.display = 'none';
}

function setRunningState() {
    img.src = 'http://127.0.0.1:5000/video_feed';
    img.style.display = 'block';
    enableBtn.style.display = 'none';
    disableBtn.style.display = 'block';
    disableBtn.disabled = false;
    startStatusPolling();
}

enableBtn.addEventListener('click', async function () {
    try {
        const permissionStream = await navigator.mediaDevices.getUserMedia({ video: true });
        permissionStream.getTracks().forEach(track => track.stop());

        enableBtn.disabled    = true;
        enableBtn.textContent = 'Starting...';

        const res  = await fetch('http://127.0.0.1:3000/gesture/start', { method: 'POST' });
        const data = await res.json();

        if (data.status === 'started' || data.status === 'already running') {
            setRunningState();
            return;
        }

        throw new Error(data.error || 'Unexpected response from gesture server');

    } catch (error) {
        console.error('Error:', error);
        resetEnableState();
        alert('Could not start gesture detection. Is the Node server running?');
    }
});

disableBtn.addEventListener('click', async function () {
    try {
        const res = await fetch('http://127.0.0.1:3000/gesture/stop', { method: 'POST' });
        const data = await res.json();

        if (data.status === 'stopped') {
            img.src = '';
            img.style.display = 'none';
            stopStatusPolling();
            updateGestureView({ gesture: 'No Gesture', confidence: 0 });
            resetEnableState();
        }
    } catch (error) {
        console.error('Error stopping gesture detection:', error);
        alert('Could not stop gesture detection.');
    }
});