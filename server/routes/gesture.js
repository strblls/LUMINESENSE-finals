const router = require('express').Router();
const { spawn } = require('child_process');

let flaskProcess = null;

router.post('/start', (req, res) => {
    if (flaskProcess) return res.json({ status: 'already running' });

    flaskProcess = spawn('python', ['gesture-control.py'], {
        cwd: 'C:/xampp/htdocs/LUMINESENSE-finals',  //absolute path
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