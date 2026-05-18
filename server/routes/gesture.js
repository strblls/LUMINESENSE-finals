const router = require('express').Router();
const { spawn } = require('child_process');
const http = require('http');

let flaskProcess = null;

function proxyFlaskJson(pathname) {
    return new Promise((resolve, reject) => {
        const request = http.get(`http://127.0.0.1:5000${pathname}`, (response) => {
            let body = '';

            response.setEncoding('utf8');
            response.on('data', (chunk) => {
                body += chunk;
            });
            response.on('end', () => {
                try {
                    resolve({ statusCode: response.statusCode, body: JSON.parse(body) });
                } catch (error) {
                    reject(error);
                }
            });
        });

        request.on('error', reject);
        request.setTimeout(1500, () => {
            request.destroy(new Error('Flask status request timed out'));
        });
    });
}

// Helper function to check if Flask is ready
function isFlaskReady() {
    return new Promise((resolve) => {
        const req = http.get('http://127.0.0.1:5000/video_feed', (res) => {
            req.destroy();
            resolve(res.statusCode === 200);
        });
        req.on('error', () => {
            resolve(false);
        });
        setTimeout(() => resolve(false), 1000);
    });
}

router.post('/start', async (req, res) => {
    if (flaskProcess) return res.json({ status: 'already running' });

    flaskProcess = spawn('python', ['gesture-control.py'], {
        cwd: 'C:/xampp/htdocs/LUMINESENSE-finals',
        stdio: 'inherit'
    });

    flaskProcess.on('error', err => {
        console.error('Failed to start Flask:', err);
        flaskProcess = null;
    });

    // Wait for Flask to be ready (up to 5 seconds)
    let attempts = 0;
    const checkReady = async () => {
        while (attempts < 50) {
            if (await isFlaskReady()) {
                return res.json({ status: 'started' });
            }
            attempts++;
            await new Promise(r => setTimeout(r, 100));
        }
        res.status(500).json({ status: 'failed', error: 'Flask did not start' });
    };

    checkReady();
});

router.get('/status', async (req, res) => {
    try {
        const response = await proxyFlaskJson('/status');

        if (response.statusCode !== 200) {
            return res.status(503).json({ status: 'unavailable' });
        }

        return res.json(response.body);
    } catch (error) {
        return res.status(503).json({ status: 'unavailable', error: error.message });
    }
});

router.post('/stop', (req, res) => {
    if (flaskProcess) { flaskProcess.kill(); flaskProcess = null; }
    res.json({ status: 'stopped' });
});

module.exports = router;