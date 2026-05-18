const enableBtn = document.getElementById('enableCameraBtn');
const img       = document.getElementById('gestureStream');

enableBtn.addEventListener('click', async function () {
    try {
        await navigator.mediaDevices.getUserMedia({ video: true });

        enableBtn.disabled    = true;
        enableBtn.textContent = 'Starting...';

        const res  = await fetch('http://127.0.0.1:3000/gesture/start', { method: 'POST' });
        const data = await res.json();

        if (data.status === 'started' || data.status === 'already running') {
            img.src = 'http://127.0.0.1:5000/video_feed';
            img.style.display = 'block';
            enableBtn.style.display = 'none';

            // Add error handler
            img.onerror = () => {
                console.error('Stream failed to load');
                enableBtn.disabled = false;
                enableBtn.textContent = 'Enable Camera';
                enableBtn.style.display = 'block';
                img.style.display = 'none';
                alert('Camera stream lost. Please try again.');
            };
        }

    } catch (error) {
        console.error('Error:', error);
        enableBtn.disabled    = false;
        enableBtn.textContent = 'Enable Camera';
        alert('Could not start gesture detection. Is the Node server running?');
    }
});