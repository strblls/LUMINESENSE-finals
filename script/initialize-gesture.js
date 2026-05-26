// initialize-gesture.js
// Handles camera enable/disable, canvas skeleton overlays, and real-time client-side gesture → lighting control.

import { GestureRecognizer, FilesetResolver } from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/vision_bundle.mjs";

// ── Element refs ──────────────────────────────────────────────────────────────
const enableBtn = document.getElementById('enableCameraBtn');
const disableBtn = document.getElementById('disableCameraBtn');
const webcamVideo = document.getElementById('webcamVideo');
const webcamCanvas = document.getElementById('webcamCanvas');
const gestureResult = document.getElementById('gestureResult');
const accuracyBar = document.getElementById('accuracyBar');

let recognizer = null;
let stream = null;
let active = false;
let lastVideoTime = -1;

// ── Progress bar colour map ────────────────────────────────────────────────────
const PROGRESS_CLASSES = ['bg-success', 'bg-primary', 'bg-danger', 'bg-info', 'bg-warning', 'bg-dark', 'bg-secondary'];
const GESTURE_COLOUR = {
    Open_Palm: 'bg-success',
    Thumb_Up: 'bg-primary',
    Closed_Fist: 'bg-danger',
    Pointing_Up: 'bg-info',
    Victory: 'bg-warning',
    ILoveYou: 'bg-dark',
};

// ── Initialize MediaPipe Gesture Recognizer ───────────────────────────────────
async function initializeRecognizer() {
    if (recognizer) return;
    if (enableBtn) {
        enableBtn.disabled = true;
        enableBtn.textContent = 'Loading AI Model…';
    }
    const vision = await FilesetResolver.forVisionTasks(
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.8/wasm"
    );
    // Initialize recognizer with options, explicitly enabling video running mode!
    recognizer = await GestureRecognizer.createFromOptions(vision, {
        baseOptions: {
            modelAssetPath: "../../gesture_recognizer.task",
            delegate: "GPU"
        },
        runningMode: "VIDEO"
    });
}

function setProgressStyle(gesture, confidence) {
    if (!accuracyBar) return;
    accuracyBar.classList.remove(...PROGRESS_CLASSES);
    accuracyBar.classList.add(GESTURE_COLOUR[gesture] || 'bg-secondary');
    const w = Math.max(0, Math.min(100, Math.round(confidence)));
    accuracyBar.style.width = `${w}%`;
    accuracyBar.textContent = `${w}%`;
    accuracyBar.setAttribute('aria-valuenow', String(w));
}

// ── Gesture → Row State Machine with 900ms Debounce and 👍 Confirmation ──
const DEBOUNCE_MS = 900;
const CONFIRM_TIMEOUT_MS = 15000;
const GESTURE_ACCURACY_THRESHOLD = 80; // The threshold set by you
const GESTURE_DECAY_THRESHOLD = 70;    // Schmitt trigger lower limit once a gesture starts
const DROPOUT_TOLERANCE_MS = 350;       // Allow 350ms of flicker/dropout before resetting timer

const ROW_GESTURE = { Pointing_Up: 1, Victory: 2, ILoveYou: 3 };

let _lastGesture = 'No Gesture';
let _heldSince = null;
let _actioned = false;
let _selectedRow = null;
let _dropoutStart = null;   // Tracks when a gesture dropout began

let pendingAction = null; // null or { gesture: 'Open_Palm', action: 'all_on', label: 'All Lights ON', row: null }
let pendingTimeout = null;

// Smoothed prediction to filter frame-by-frame noise (hysteresis + EMA filter)
let _lastGestureRaw = 'No Gesture';
let _smoothedConfidence = 0;

function updatePillsState() {
    [1, 2, 3].forEach(r => {
        const p = document.getElementById(`rowPill${r}`);
        if (!p) return;
        p.classList.remove('active', 'pending', 'confirmed');

        if (pendingAction) {
            if (pendingAction.action === 'all_on' || pendingAction.action === 'all_off') {
                p.classList.add('pending');
            } else if (pendingAction.action === 'toggle_row' && pendingAction.row === r) {
                p.classList.add('pending');
            }
        } else if (_selectedRow === r) {
            p.classList.add('active');
        }
    });
}

function flashPill(row) {
    const p = document.getElementById(`rowPill${row}`);
    if (!p) return;
    p.classList.remove('active', 'pending');
    p.classList.add('confirmed');
    setTimeout(() => {
        p.classList.remove('confirmed');
        updatePillsState();
    }, 1200);
}

function flashAllPills() {
    [1, 2, 3].forEach(r => {
        const p = document.getElementById(`rowPill${r}`);
        if (p) {
            p.classList.remove('active', 'pending');
            p.classList.add('confirmed');
        }
    });
    setTimeout(() => {
        [1, 2, 3].forEach(r => {
            const p = document.getElementById(`rowPill${r}`);
            if (p) p.classList.remove('confirmed');
        });
        updatePillsState();
    }, 1200);
}

async function executePendingAction() {
    if (!pendingAction) return;

    const form = new FormData();
    if (typeof CLASSROOM_ID !== 'undefined') form.append('classroom_id', CLASSROOM_ID);
    form.append('triggered_by', 'gesture');

    const action = pendingAction.action;
    const row = pendingAction.row;
    const gesture = pendingAction.gesture;

    // Reset pendingAction BEFORE carrying out visual cues, so updatePillsState knows it's resolved
    const resolvedAction = pendingAction;
    pendingAction = null;
    clearPendingTimeout();

    if (action === 'all_on') {
        form.append('row', 'all'); form.append('state', 'on');
        document.querySelectorAll('.bulb-img').forEach(img => img.src = '../../images/bulb-on.png');
        ['row-1-switch', 'row-2-switch', 'row-3-switch'].forEach(id => {
            const sw = document.getElementById(id); if (sw) sw.checked = true;
        });
        _updateAllLightsBadge(true);
        flashAllPills();
        await fetch('../../api/lights.php', { method: 'POST', body: form });
        if (typeof logGestureEvent === 'function') logGestureEvent('Open_Palm – all ON');

    } else if (action === 'all_off') {
        form.append('row', 'all'); form.append('state', 'off');
        document.querySelectorAll('.bulb-img').forEach(img => img.src = '../../images/bulb-off.png');
        ['row-1-switch', 'row-2-switch', 'row-3-switch'].forEach(id => {
            const sw = document.getElementById(id); if (sw) sw.checked = false;
        });
        _updateAllLightsBadge(false);
        flashAllPills();
        await fetch('../../api/lights.php', { method: 'POST', body: form });
        if (typeof logGestureEvent === 'function') logGestureEvent('Closed_Fist – all OFF');

    } else if (action === 'toggle_row') {
        _selectedRow = row; // Keep track of the active selected row
        const sw = document.getElementById(`row-${row}-switch`);
        const newState = sw ? !sw.checked : true;
        if (sw) sw.checked = newState;
        document.querySelectorAll(`.bulb-img[data-row="${row}"]`).forEach(img => {
            img.src = newState ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
        });
        
        // Dynamically recalculate and update the overall badge immediately on gesture toggle
        const sw1 = document.getElementById('row-1-switch');
        const sw2 = document.getElementById('row-2-switch');
        const sw3 = document.getElementById('row-3-switch');
        const overallOn = (sw1 && sw1.checked) || (sw2 && sw2.checked) || (sw3 && sw3.checked);
        _updateAllLightsBadge(overallOn);

        flashPill(row);
        form.append('row', String(row));
        form.append('state', newState ? 'on' : 'off');
        await fetch('../../api/lights.php', { method: 'POST', body: form });
        if (typeof logGestureEvent === 'function') logGestureEvent(`Thumb_Up – row ${row} ${newState ? 'ON' : 'OFF'}`);
    }

    // Temporary nice UI visual notification
    if (gestureResult) {
        gestureResult.innerHTML = `<span class="text-success bold">✔ CONFIRMED: ${resolvedAction.label}</span>`;
    }
}

function clearPendingTimeout() {
    if (pendingTimeout) {
        clearTimeout(pendingTimeout);
        pendingTimeout = null;
    }
}

function startPendingTimeout() {
    clearPendingTimeout();
    pendingTimeout = setTimeout(() => {
        if (pendingAction) {
            if (gestureResult) {
                gestureResult.innerHTML = `<span class="text-danger bold">✘ Cancelled: ${pendingAction.label} (Timed out)</span>`;
            }
            pendingAction = null;
            updatePillsState();
        }
    }, CONFIRM_TIMEOUT_MS);
}

function _updateAllLightsBadge(isOn) {
    const badge = document.getElementById('allLightsStatus');
    const btnCont = document.getElementById('allLightsContainer');
    if (badge) { badge.textContent = isOn ? 'ON' : 'OFF'; badge.className = `bold ${isOn ? 'on' : 'off'}`; }
    if (btnCont) { btnCont.className = btnCont.className.replace(/all-lights-(on|off)/, `all-lights-${isOn ? 'on' : 'off'}`); }
    const sLight = document.getElementById('statusLighting');
    if (sLight) { sLight.textContent = isOn ? 'ON' : 'OFF'; sLight.className = isOn ? 'text-success' : 'text-danger'; }
}

function processGesture(gesture, confidence) {
    let activeGesture = gesture;

    // Apply Schmitt trigger (hysteresis) to prevent flickering near 80%
    const threshold = (_lastGesture && _lastGesture !== 'No Gesture' && _lastGesture === gesture)
        ? GESTURE_DECAY_THRESHOLD
        : GESTURE_ACCURACY_THRESHOLD;

    if (confidence < threshold) {
        activeGesture = 'No Gesture';
    }

    // Real-time console debugging to trace recognition and timer state
    if (gesture !== 'No Gesture') {
        const heldTime = _heldSince ? (Date.now() - _heldSince) : 0;
        console.log(`[MediaPipe Debug] Raw: "${gesture}" (${confidence.toFixed(1)}%), Active: "${activeGesture}", Last: "${_lastGesture}", Held: ${heldTime}ms`);
    }

    // Handle gesture transitions with a dropout grace period
    if (activeGesture !== _lastGesture) {
        if (activeGesture === 'No Gesture') {
            // Start grace period for temporary dropouts
            if (!_dropoutStart) {
                _dropoutStart = Date.now();
            }
            // Only reset state if the dropout lasts longer than the tolerance window
            if (Date.now() - _dropoutStart >= DROPOUT_TOLERANCE_MS) {
                _lastGesture = 'No Gesture';
                _heldSince = Date.now();
                _actioned = false;
                _dropoutStart = null;
            }
        } else {
            // Transitioned to a new valid gesture: reset hold state immediately
            _lastGesture = activeGesture;
            _heldSince = Date.now();
            _actioned = false;
            _dropoutStart = null;
            return;
        }
    } else {
        // Active gesture matches the last gesture: clear any active dropout timer
        _dropoutStart = null;
    }

    // Once held for DEBOUNCE_MS and it's a valid action gesture
    if (!_actioned && _lastGesture && _lastGesture !== 'No Gesture' && (Date.now() - _heldSince) >= DEBOUNCE_MS) {
        _actioned = true;

        if (_lastGesture === 'Thumb_Up') {
            if (pendingAction) {
                executePendingAction();
            } else {
                if (gestureResult) {
                    gestureResult.innerHTML = `<span class="text-info bold">No action pending to confirm!</span>`;
                }
            }
        } else if (_lastGesture === 'Open_Palm') {
            pendingAction = { gesture: 'Open_Palm', action: 'all_on', label: 'All Lights ON', row: null };
            updatePillsState();
            startPendingTimeout();
        } else if (_lastGesture === 'Closed_Fist') {
            pendingAction = { gesture: 'Closed_Fist', action: 'all_off', label: 'All Lights OFF', row: null };
            updatePillsState();
            startPendingTimeout();
        } else if (ROW_GESTURE[_lastGesture] !== undefined) {
            const rowNum = ROW_GESTURE[_lastGesture];
            const sw = document.getElementById(`row-${rowNum}-switch`);
            const currentState = sw && sw.checked;
            const targetStateLabel = currentState ? 'OFF' : 'ON';
            pendingAction = {
                gesture: _lastGesture,
                action: 'toggle_row',
                row: rowNum,
                label: `Turn Row ${rowNum} ${targetStateLabel}`
            };
            updatePillsState();
            startPendingTimeout();
        }
    }

    // Stage updates for UI when an action is pending
    if (pendingAction) {
        if (activeGesture === 'No Gesture') {
            if (gestureResult) {
                gestureResult.innerHTML = `<span class="text-warning bold">👍 Confirm ${pendingAction.label}?</span> <span style="font-size:0.75rem; color:#6c757d;">(Hold 👍 to confirm)</span>`;
            }
        } else if (activeGesture !== 'Thumb_Up') {
            if (gestureResult) {
                gestureResult.innerHTML = `<span class="text-warning bold">👍 Confirm ${pendingAction.label}?</span>`;
            }
        }
    }
}


function updateGestureView(gesture, confidence) {
    const cleanGesture = (gesture && gesture !== 'None') ? gesture : 'No Gesture';

    // Apply low-pass filter (Exponential Moving Average) continuously to stabilize fluctuations
    _smoothedConfidence = _smoothedConfidence * 0.65 + confidence * 0.35;
    _lastGestureRaw = cleanGesture;

    setProgressStyle(cleanGesture, _smoothedConfidence);
    processGesture(cleanGesture, _smoothedConfidence);

    if (!pendingAction) {
        if (gestureResult) {
            if (cleanGesture === 'No Gesture' || _smoothedConfidence < 30) {
                gestureResult.textContent = '—';
            } else {
                gestureResult.textContent = cleanGesture.replace(/_/g, ' ');
            }
        }
    }
}

// ── Draw skeleton landmarks on HTML Canvas ────────────────────────────────────
function drawLandmarks(landmarks) {
    if (!webcamCanvas) return;
    const ctx = webcamCanvas.getContext('2d');
    ctx.clearRect(0, 0, webcamCanvas.width, webcamCanvas.height);

    if (!landmarks || landmarks.length === 0) return;

    const width = webcamCanvas.width;
    const height = webcamCanvas.height;

    // Skeletal connection joints map
    const connections = [
        [0, 1], [1, 2], [2, 3], [3, 4],      // Thumb
        [0, 5], [5, 6], [6, 7], [7, 8],      // Index
        [5, 9], [9, 10], [10, 11], [11, 12],  // Middle
        [9, 13], [13, 14], [14, 15], [15, 16],// Ring
        [13, 17], [0, 17], [17, 18], [18, 19], [19, 20] // Pinky
    ];

    for (const hand of landmarks) {
        // Draw bones
        ctx.strokeStyle = '#2ecc71';
        ctx.lineWidth = 3;
        for (const [p1, p2] of connections) {
            const pt1 = hand[p1];
            const pt2 = hand[p2];
            ctx.beginPath();
            ctx.moveTo(pt1.x * width, pt1.y * height);
            ctx.lineTo(pt2.x * width, pt2.y * height);
            ctx.stroke();
        }

        // Draw joints
        for (const pt of hand) {
            ctx.beginPath();
            ctx.arc(pt.x * width, pt.y * height, 5, 0, 2 * Math.PI);
            ctx.fillStyle = '#e74c3c';
            ctx.fill();
        }
    }
}

// ── Real-Time Prediction Loop ─────────────────────────────────────────────────
async function predictLoop() {
    if (!active) return;

    if (webcamVideo.currentTime !== lastVideoTime) {
        lastVideoTime = webcamVideo.currentTime;

        // Sync canvas coordinates with video size
        if (webcamCanvas.width !== webcamVideo.videoWidth) {
            webcamCanvas.width = webcamVideo.videoWidth;
            webcamCanvas.height = webcamVideo.videoHeight;
        }

        try {
            const now = performance.now();
            const results = recognizer.recognizeForVideo(webcamVideo, now);

            let bestGesture = 'No Gesture';
            let bestConfidence = 0;

            if (results.gestures && results.gestures.length > 0) {
                for (const hand_gestures of results.gestures) {
                    if (hand_gestures && hand_gestures.length > 0) {
                        const top = hand_gestures[0];
                        const score = Math.round(top.score * 100);
                        if (score > bestConfidence) {
                            bestGesture = top.categoryName || top.category_name;
                            bestConfidence = score;
                        }
                    }
                }
            }

            updateGestureView(bestGesture, bestConfidence);
            const handLandmarks = results.landmarks || results.handLandmarks;
            drawLandmarks(handLandmarks);

        } catch (e) {
            console.error('Recognition error:', e);
        }
    }

    requestAnimationFrame(predictLoop);
}

// ── Start / Stop controls ─────────────────────────────────────────────────────
async function startWebcam() {
    try {
        if (enableBtn) {
            enableBtn.disabled = true;
            enableBtn.textContent = 'Starting camera…';
        }

        await initializeRecognizer();

        stream = await navigator.mediaDevices.getUserMedia({
            video: { width: 640, height: 480, facingMode: "user" }
        });

        webcamVideo.srcObject = stream;
        webcamVideo.style.display = 'block';
        webcamCanvas.style.display = 'block';

        if (enableBtn) enableBtn.style.display = 'none';
        if (disableBtn) disableBtn.style.display = 'block';

        const wc = document.getElementById('statusWebcam');
        if (wc) { wc.textContent = 'Active'; wc.className = 'text-success'; }

        active = true;

        // Robust start: trigger predictLoop on load, metadata, or immediately if ready
        webcamVideo.addEventListener('loadedmetadata', predictLoop);
        webcamVideo.addEventListener('loadeddata', predictLoop);
        webcamVideo.addEventListener('playing', predictLoop);

        if (webcamVideo.readyState >= 2) {
            predictLoop();
        }

    } catch (e) {
        console.error('startWebcam failed:', e);
        alert('Could not start camera.\n\nMake sure that:\n1. You have allowed camera permission for this site.\n2. No other application is using your webcam.');
        resetState();
    }
}

function resetState() {
    active = false;
    if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
    }
    webcamVideo.srcObject = null;
    webcamVideo.style.display = 'none';
    webcamCanvas.style.display = 'none';

    if (enableBtn) {
        enableBtn.disabled = false;
        enableBtn.style.display = 'block';
        enableBtn.innerHTML = '<i class="bi bi-camera-video me-1"></i>Enable Camera';
    }
    if (disableBtn) disableBtn.style.display = 'none';

    const wc = document.getElementById('statusWebcam');
    if (wc) { wc.textContent = 'Disabled'; wc.className = 'text-muted'; }

    updateGestureView('No Gesture', 0);
    const ctx = webcamCanvas.getContext('2d');
    ctx.clearRect(0, 0, webcamCanvas.width, webcamCanvas.height);
}

if (enableBtn) {
    enableBtn.addEventListener('click', startWebcam);
}
if (disableBtn) {
    disableBtn.addEventListener('click', resetState);
}