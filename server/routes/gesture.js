const router = require('express').Router();
const { spawn } = require('child_process');

let flaskProcess = null;

router.post('/start', (req, res) => {
    if (flaskProcess) return res.json({ status: 'already running' });

    flaskProcess = spawn('C:/Users/ACER/AppData/Local/Programs/Python/Python314/python.exe', ['gesture-control.py'], {
        cwd: 'C:/xampp_fr/htdocs/LUMINESENSE_VERSIONS/LUMINESENSE-finals',
        stdio: 'inherit'
    });

    flaskProcess.on('error', err => {
        console.error('Failed to start Flask:', err);
        flaskProcess = null;
    });

    setTimeout(() => res.json({ status: 'started' }), 2000);
});

router.post('/stop', (req, res) => {
    if (flaskProcess) { flaskProcess.kill(); flaskProcess = null; }
    res.json({ status: 'stopped' });
});

module.exports = router;