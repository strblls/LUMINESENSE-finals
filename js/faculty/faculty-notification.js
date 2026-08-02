(function () {
    'use strict';

    var dataEl = document.getElementById('scheduleEndData');
    var toastWrap = document.getElementById('notificationToastWrap');
    var timeLabel = document.getElementById('notifTimeLabel');
    var timeMessage = document.getElementById('notifTimeMessage');

    if (!dataEl || !toastWrap) return;

    var notified = {};
    var audioCtx = null;

    var THRESHOLDS = [
        { min: 30, label: '30 Minutes Remaining', msg: 'You have 30 minutes left in your class.' },
        { min: 15, label: '15 Minutes Remaining', msg: 'You have 15 minutes left in your class.' },
        { min: 5,  label: '5 Minutes Remaining',  msg: 'You have 5 minutes left in your class. Class will end soon.' }
    ];

    function playChime() {
        try {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.8, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.6);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.6);
        } catch (e) {}
    }

    function speak(message) {
        try {
            window.speechSynthesis.cancel();
            var utter = new SpeechSynthesisUtterance(message);
            utter.rate = 0.9;
            utter.pitch = 1;
            window.speechSynthesis.speak(utter);
        } catch (e) {}
    }

    function showNotification(label, message) {
        if (timeLabel) timeLabel.textContent = label;
        if (timeMessage) timeMessage.textContent = message;
        toastWrap.classList.remove('show');
        void toastWrap.offsetWidth;
        toastWrap.classList.add('show');
        playChime();
        setTimeout(function () {
            speak(message);
        }, 700);
        setTimeout(function () {
            toastWrap.classList.remove('show');
        }, 6000);
    }

    function checkTime() {
        var endTime = dataEl.dataset.end;
        if (!endTime) return;

        var now = new Date();
        var parts = endTime.split(':').map(Number);
        var end = new Date(now);
        end.setHours(parts[0], parts[1], parts[2], 0);
        var diff = Math.max(0, Math.floor((end - now) / 1000));
        var minutes = Math.floor(diff / 60);

        for (var i = 0; i < THRESHOLDS.length; i++) {
            var t = THRESHOLDS[i];
            if (!notified[t.min] && diff === t.min * 60) {
                notified[t.min] = true;
                showNotification(t.label, t.msg);
            }
        }

        if (minutes > 30) {
            notified = {};
        }
    }

    checkTime();
    setInterval(checkTime, 1000);
})();
