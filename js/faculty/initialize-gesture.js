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
const loadingOverlay = document.getElementById('gestureLoadingOverlay');

let recognizer = null;
let stream = null;
let active = false;
let lastVideoTime = -1;
let _landmarksFirstDrawn = false;

// ── Chroma Key & Enhancement toggles ──────────────────────────────────────────
let chromaKeyEnabled = true;
let enhanceEnabled = true;
const procCanvas = document.createElement('canvas');
const procCtx = procCanvas.getContext('2d');

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
            modelAssetPath: "../../models/gesture_recognizer.task",
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

// ── Gesture → Stackable Command Queue with 👍 Confirmation & Two-Hand Input ──
const DEBOUNCE_MS = 400;
const CONFIRM_TIMEOUT_MS = 15000;
const GESTURE_ACCURACY_THRESHOLD = 70; // was 80
const GESTURE_DECAY_THRESHOLD = 60;    // was 70
const DROPOUT_TOLERANCE_MS = 350;       // Allow 350ms of flicker/dropout before resetting timer
const ROW_GESTURE = { Pointing_Up: 1, Victory: 2, ILoveYou: 3 };
const MAX_STACK_SIZE = 4;               // Max queued commands before confirmation
const HAND_STALE_MS = 1500;             // Forget a hand after this long without detection

// Per-hand state machines so both hands can queue commands independently.
const handStates = new Map();
let _stackId = 0;

// Stacked (queued) commands, executed in FIFO order on the final 👍 confirmation.
let pendingStack = [];  // [{ id, gesture, action, label, row }]
let pendingTimeout = null;
let _lastStackFeedbackAt = 0; // Tracks when the last transient queue message was shown

function getHandState(key) {
    let st = handStates.get(key);
    if (!st) {
        st = { lastGesture: 'No Gesture', heldSince: null, actioned: false, dropoutStart: null, lastSeen: Date.now() };
        handStates.set(key, st);
    }
    return st;
}

function showStackFeedback(html) {
    if (!gestureResult) return;
    gestureResult.innerHTML = html;
    _lastStackFeedbackAt = Date.now();
}

// Smoothed prediction to filter frame-by-frame noise (hysteresis + EMA filter)
let _lastGestureRaw = 'No Gesture';
let _smoothedConfidence = 0;

function updatePillsState() {
    const pendingRows = new Set();
    let allAffected = false;

    for (const it of pendingStack) {
        if (it.action === 'all_on' || it.action === 'all_off') allAffected = true;
        else if (it.action === 'toggle_row') pendingRows.add(it.row);
    }

    [1, 2, 3].forEach(r => {
        const p = document.getElementById(`rowPill${r}`);
        if (!p) return;
        p.classList.remove('pending', 'confirmed');

        if (allAffected || pendingRows.has(r)) {
            p.classList.add('pending');
        }
    });
    if (typeof window.syncRowPills === 'function') window.syncRowPills();
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

function pushAction(gesture, action, label, row) {
    pendingStack.push({ id: ++_stackId, gesture, action, label, row });
    updatePillsState();
    renderStackQueue();
    startPendingTimeout();
}

function renderStackQueue() {
    const q = document.getElementById('pendingStackQueue');
    const wrap = document.getElementById('stackQueueWrap');
    const count = document.getElementById('stackQueueCount');

    if (!q) return;

    if (pendingStack.length === 0) {
        q.innerHTML = '';
        if (wrap) wrap.style.display = 'none';
        if (count) count.textContent = `0/${MAX_STACK_SIZE}`;
        return;
    }

    if (wrap) wrap.style.display = '';
    if (count) count.textContent = `${pendingStack.length}/${MAX_STACK_SIZE}`;

    const chipClass = it => {
        if (it.action === 'all_on') return 'chip-on';
        if (it.action === 'all_off') return 'chip-off';
        return 'chip-row';
    };

    q.innerHTML = pendingStack.map((it, idx) => {
        const cls = chipClass(it);
        return `<span class="stack-chip ${cls}" title="${it.gesture.replace(/_/g, ' ')}"><span class="chip-index">${idx + 1}</span>${it.label}</span>`;
    }).join('');
}

async function executePendingStack() {
    if (!pendingStack.length) return;

    const stack = pendingStack.slice();
    pendingStack = [];
    clearPendingTimeout();

    // Read the current switch states as the baseline, then simulate the whole
    // stack locally to compute the correct final state of every row.
    const getSwitch = r => {
        const sw = document.getElementById(`row-${r}-switch`);
        return sw ? sw.checked : false;
    };
    const initial = { 1: getSwitch(1), 2: getSwitch(2), 3: getSwitch(3) };
    const final = { ...initial };

    for (const it of stack) {
        if (it.action === 'all_on') {
            final[1] = final[2] = final[3] = true;
        } else if (it.action === 'all_off') {
            final[1] = final[2] = final[3] = false;
        } else if (it.action === 'toggle_row') {
            final[it.row] = !final[it.row];
        }
    }

    const overallOn = final[1] || final[2] || final[3];

    // Update UI switches, bulbs and badge to the final state
    for (let r = 1; r <= 3; r++) {
        const sw = document.getElementById(`row-${r}-switch`);
        if (sw) sw.checked = final[r];
        document.querySelectorAll(`.bulb-img[data-row="${r}"]`).forEach(img => {
            img.src = final[r] ? '../../images/bulb-on.png' : '../../images/bulb-off.png';
        });
    }
    _updateAllLightsBadge(overallOn);

    // Visual flash for affected rows
    const flashRows = new Set();
    let flashAll = false;
    for (const it of stack) {
        if (it.action === 'toggle_row') flashRows.add(it.row);
        else flashAll = true;
    }
    if (flashAll) {
        flashAllPills();
    } else {
        flashRows.forEach(flashPill);
    }

    // Persist only the rows whose final state actually changed
    const changedRows = [1, 2, 3].filter(r => initial[r] !== final[r]);
    for (const r of changedRows) {
        const form = new FormData();
        if (typeof CLASSROOM_ID !== 'undefined') form.append('classroom_id', CLASSROOM_ID);
        form.append('triggered_by', 'gesture');
        form.append('row', String(r));
        form.append('state', final[r] ? 'on' : 'off');
        form.append('new_global_light_status', overallOn ? 'on' : 'off');
        await fetch('../../api/lights.php', { method: 'POST', body: form });
    }

    if (typeof logGestureEvent === 'function') {
        logGestureEvent(`Stack (${stack.length}): ${stack.map(it => it.label).join(' + ')}`);
    }

    renderStackQueue();
    updatePillsState();
    showStackFeedback(`<span class="text-success bold">✔ CONFIRMED: ${stack.length} command${stack.length > 1 ? 's' : ''} executed</span>`);
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
        if (pendingStack.length) {
            const n = pendingStack.length;
            showStackFeedback(`<span class="text-danger bold">✘ Cancelled: ${n} queued command${n > 1 ? 's' : ''} (Timed out)</span>`);
            pendingStack = [];
            updatePillsState();
            renderStackQueue();
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

function processHand(handKey, gesture, confidence) {
    const st = getHandState(handKey);
    st.lastSeen = Date.now();

    let activeGesture = gesture;

    // Apply Schmitt trigger (hysteresis) to prevent flickering near the threshold
    const threshold = (st.lastGesture && st.lastGesture !== 'No Gesture' && st.lastGesture === gesture)
        ? GESTURE_DECAY_THRESHOLD
        : GESTURE_ACCURACY_THRESHOLD;

    if (confidence < threshold) {
        activeGesture = 'No Gesture';
    }

    // Real-time console debugging to trace recognition and timer state per hand
    if (gesture !== 'No Gesture') {
        const heldTime = st.heldSince ? (Date.now() - st.heldSince) : 0;
        console.log(`[MediaPipe Debug][${handKey}] Raw: "${gesture}" (${confidence.toFixed(1)}%), Active: "${activeGesture}", Last: "${st.lastGesture}", Held: ${heldTime}ms`);
    }

    // Handle gesture transitions with a dropout grace period
    if (activeGesture !== st.lastGesture) {
        if (activeGesture === 'No Gesture') {
            // Start grace period for temporary dropouts
            if (!st.dropoutStart) {
                st.dropoutStart = Date.now();
            }
            // Only reset state if the dropout lasts longer than the tolerance window
            if (Date.now() - st.dropoutStart >= DROPOUT_TOLERANCE_MS) {
                st.lastGesture = 'No Gesture';
                st.heldSince = Date.now();
                st.actioned = false;
                st.dropoutStart = null;
            }
        } else {
            // Transitioned to a new valid gesture: reset hold state immediately
            st.lastGesture = activeGesture;
            st.heldSince = Date.now();
            st.actioned = false;
            st.dropoutStart = null;
            return;
        }
    } else {
        // Active gesture matches the last gesture: clear any active dropout timer
        st.dropoutStart = null;
    }

    // Once held for DEBOUNCE_MS, this hand registers its command
    if (!st.actioned && st.lastGesture && st.lastGesture !== 'No Gesture' && (Date.now() - st.heldSince) >= DEBOUNCE_MS) {
        st.actioned = true;
        handleActionGesture(handKey, st.lastGesture);
    }
}

function handleActionGesture(handKey, gesture) {
    // 👍 from either hand confirms the whole stack
    if (gesture === 'Thumb_Up') {
        if (pendingStack.length) {
            executePendingStack();
        } else {
            showStackFeedback(`<span class="text-info bold">No command queued to confirm!</span>`);
        }
        return;
    }

    // ✊ clears a non-empty stack; otherwise queues "all lights OFF"
    if (gesture === 'Closed_Fist') {
        if (pendingStack.length) {
            const n = pendingStack.length;
            pendingStack = [];
            clearPendingTimeout();
            updatePillsState();
            renderStackQueue();
            showStackFeedback(`<span class="text-danger bold">✘ Cleared ${n} queued command${n > 1 ? 's' : ''} (✊ Fist)</span>`);
        } else {
            pushAction('Closed_Fist', 'all_off', 'All Lights OFF', null);
        }
        return;
    }

    if (pendingStack.length >= MAX_STACK_SIZE) {
        showStackFeedback(`<span class="text-warning bold">Stack full (${MAX_STACK_SIZE}/${MAX_STACK_SIZE}) — 👍 confirm or ✊ clear</span>`);
        return;
    }

    if (gesture === 'Open_Palm') {
        pushAction('Open_Palm', 'all_on', 'All Lights ON', null);
        showStackFeedback(`<span class="text-confirm bold">📥 Queued: All Lights ON (${pendingStack.length}/${MAX_STACK_SIZE})</span>`);
    } else if (ROW_GESTURE[gesture] !== undefined) {
        const rowNum = ROW_GESTURE[gesture];
        // Compute the row's effective state AFTER the already-queued commands,
        // so repeating a row gesture alternates its target (ON/OFF/ON/...).
        const effectiveState = simulateRowState(rowNum);
        const targetStateLabel = effectiveState ? 'OFF' : 'ON';
        pushAction(gesture, 'toggle_row', `Row ${rowNum} ${targetStateLabel}`, rowNum);
        showStackFeedback(`<span class="text-confirm bold">📥 Queued: Row ${rowNum} ${targetStateLabel} (${pendingStack.length}/${MAX_STACK_SIZE})</span>`);
    }
}

// Simulates what a row's state will be after all currently queued commands run.
function simulateRowState(row) {
    const sw = document.getElementById(`row-${row}-switch`);
    let state = sw ? sw.checked : false;
    for (const it of pendingStack) {
        if (it.action === 'all_on') {
            state = true;
        } else if (it.action === 'all_off') {
            state = false;
        } else if (it.action === 'toggle_row' && it.row === row) {
            state = !state;
        }
    }
    return state;
}

function updateGestureView(handDetections) {
    // Best detection across all hands drives the progress bar & gesture image
    let best = null;
    for (const h of handDetections) {
        if (!best || h.confidence > best.confidence) best = h;
    }

    const cleanGesture = best ? ((best.gesture && best.gesture !== 'None') ? best.gesture : 'No Gesture') : 'No Gesture';
    const confidence = best ? best.confidence : 0;

    // Apply low-pass filter (Exponential Moving Average) continuously to stabilize fluctuations
    _smoothedConfidence = _smoothedConfidence * 0.65 + confidence * 0.35;
    _lastGestureRaw = cleanGesture;

    setProgressStyle(cleanGesture, _smoothedConfidence);

    // Process each detected hand independently (two-hand input support)
    if (handDetections.length > 0) {
        for (const h of handDetections) {
            processHand(h.key, h.gesture, h.confidence);
        }
    } else {
        // No hands on screen: let per-hand dropout logic settle
        for (const key of [...handStates.keys()]) {
            processHand(key, 'No Gesture', 0);
        }
    }

    // Forget hands that have not been seen for a while
    const now = Date.now();
    for (const [key, st] of handStates) {
        if (now - st.lastSeen > HAND_STALE_MS) handStates.delete(key);
    }

    updateGestureImage(cleanGesture);

    if (!pendingStack.length) {
        if (gestureResult) {
            if (cleanGesture === 'No Gesture' || _smoothedConfidence < 30) {
                gestureResult.textContent = '—';
            } else {
                gestureResult.textContent = cleanGesture.replace(/_/g, ' ');
            }
        }
    } else if (now - _lastStackFeedbackAt > 900) {
        // Idle prompt while a stack is waiting for the final 👍
        if (gestureResult) {
            const n = pendingStack.length;
            gestureResult.innerHTML = `<span class="text-confirm bold">👍 Confirm ${n} queued command${n > 1 ? 's' : ''}? (Hold 👍)</span>`;
        }
    }
}

// ── Gesture image map ─────────────────────────────────────────────────────────
const GESTURE_IMAGES = {
    Pointing_Up: '../../images/pointing-up.png',
    Victory: '../../images/victory.png',
    ILoveYou: '../../images/ily.png',
    Open_Palm: '../../images/open-palm.png',
    Closed_Fist: '../../images/closed-fist.png',
    Thumb_Up: '../../images/thumbs-up.png',
};

function updateGestureImage(gesture) {
    const list = document.getElementById('gestureImageList');
    const img = document.getElementById('gestureImage');
    const heading = document.getElementById('gestureListHeading');
    if (!list || !img || !heading) return;

    if (pendingStack.length) {
        img.src = GESTURE_IMAGES.Thumb_Up;
        img.style.display = '';
        list.style.display = 'none';
        heading.textContent = `Confirm Action (${pendingStack.length}/${MAX_STACK_SIZE})`;
        return;
    }

    if (gesture && gesture !== 'No Gesture' && GESTURE_IMAGES[gesture]) {
        img.src = GESTURE_IMAGES[gesture];
        img.style.display = '';
        list.style.display = 'none';
        heading.textContent = 'Detected: ' + gesture.replace(/_/g, ' ');
        return;
    }

    img.style.display = 'none';
    list.style.display = '';
    heading.textContent = 'Available Gestures';
}

// ── Convex Hull (Monotone Chain) ──────────────────────────────────────────────
function cross(o, a, b) {
    return (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
}
function convexHull(points) {
    const sorted = [...points].sort((a, b) => a.x - b.x || a.y - b.y);
    const lower = [];
    for (const p of sorted) {
        while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], p) <= 0) lower.pop();
        lower.push(p);
    }
    const upper = [];
    for (const p of sorted.reverse()) {
        while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], p) <= 0) upper.pop();
        upper.push(p);
    }
    lower.pop(); upper.pop();
    return lower.concat(upper);
}

// ── Update toggle button UI ───────────────────────────────────────────────────
function updateToggleUI() {
    const ct = document.getElementById('chromaKeyToggle');
    if (ct) ct.classList.toggle('active', chromaKeyEnabled);
    const et = document.getElementById('enhanceToggle');
    if (et) et.classList.toggle('active', enhanceEnabled);
}

window.toggleChromaKey = function () {
    chromaKeyEnabled = !chromaKeyEnabled;
    updateToggleUI();
};
window.toggleEnhance = function () {
    enhanceEnabled = !enhanceEnabled;
    updateToggleUI();
};

// ── Draw skeleton landmarks with chroma-key spotlight overlay ─────────────────
function drawLandmarks(landmarks) {
    if (!webcamCanvas) return;
    const ctx = webcamCanvas.getContext('2d');
    ctx.clearRect(0, 0, webcamCanvas.width, webcamCanvas.height);

    if (!landmarks || landmarks.length === 0) return;

    // Hide loading overlay on first successful landmark draw
    if (!_landmarksFirstDrawn) {
        _landmarksFirstDrawn = true;
        if (loadingOverlay) loadingOverlay.style.display = 'none';
    }

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

    // ── Chroma key spotlight effect ──
    if (chromaKeyEnabled) {
        // 1. Darken everything
        ctx.fillStyle = 'rgba(0, 0, 0, 0.55)';
        ctx.fillRect(0, 0, width, height);

        // 2. Cut out hand silhouettes to reveal original video
        ctx.save();
        ctx.globalCompositeOperation = 'destination-out';
        for (const hand of landmarks) {
            const hull = convexHull(hand);
            ctx.beginPath();
            ctx.moveTo(hull[0].x * width, hull[0].y * height);
            for (let i = 1; i < hull.length; i++) {
                ctx.lineTo(hull[i].x * width, hull[i].y * height);
            }
            ctx.closePath();
            ctx.fill();
        }
        ctx.restore();

        // 3. Draw glowing cyan outline + subtle fill per hand
        for (const hand of landmarks) {
            const hull = convexHull(hand);
            const pts = hull.map(p => ({ x: p.x * width, y: p.y * height }));

            // Glowing outline
            ctx.save();
            ctx.shadowColor = 'rgba(0, 200, 255, 0.6)';
            ctx.shadowBlur = 18;
            ctx.strokeStyle = 'rgba(0, 200, 255, 0.5)';
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(pts[0].x, pts[0].y);
            for (let i = 1; i < pts.length; i++) {
                ctx.lineTo(pts[i].x, pts[i].y);
            }
            ctx.closePath();
            ctx.stroke();
            ctx.restore();

            // Subtle cyan tint inside hand
            ctx.fillStyle = 'rgba(0, 200, 255, 0.06)';
            ctx.beginPath();
            ctx.moveTo(pts[0].x, pts[0].y);
            for (let i = 1; i < pts.length; i++) {
                ctx.lineTo(pts[i].x, pts[i].y);
            }
            ctx.closePath();
            ctx.fill();
        }
    }

    // ── Skeleton + joints (always drawn) ──
    for (const hand of landmarks) {
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

            // Optional preprocessing: contrast/brightness/saturation boost
            let inputSource = webcamVideo;
            if (enhanceEnabled) {
                if (procCanvas.width !== webcamVideo.videoWidth) {
                    procCanvas.width = webcamVideo.videoWidth;
                    procCanvas.height = webcamVideo.videoHeight;
                }
                procCtx.filter = 'contrast(1.25) brightness(1.1) saturate(1.05)';
                procCtx.drawImage(webcamVideo, 0, 0);
                inputSource = procCanvas;
            }

            const results = recognizer.recognizeForVideo(inputSource, now);

            // Collect one detection per hand so both hands can queue commands
            const handDetections = [];
            const handLandmarks = results.landmarks || results.handLandmarks || [];

            if (results.gestures && results.gestures.length > 0) {
                for (let i = 0; i < results.gestures.length; i++) {
                    const hand_gestures = results.gestures[i];
                    if (!hand_gestures || hand_gestures.length === 0) continue;

                    const top = hand_gestures[0];
                    const score = Math.round(top.score * 100);
                    const gestureName = (top.categoryName || top.category_name || '').replace(/\s+/g, '');

                    // Stable per-hand identity: prefer handedness label, fall back to
                    // the horizontal centroid of the hand landmarks.
                    let handKey = '';
                    if (results.handedness && results.handedness[i] && results.handedness[i][0]) {
                        handKey = results.handedness[i][0].categoryName
                            || results.handedness[i][0].displayName
                            || '';
                    }
                    if (!handKey) {
                        const lm = handLandmarks[i];
                        if (lm && lm.length) {
                            let sx = 0;
                            for (const p of lm) sx += p.x;
                            handKey = (sx / lm.length) < 0.5 ? 'Left' : 'Right';
                        }
                    }
                    if (!handKey) handKey = `hand_${i}`;

                    handDetections.push({ key: handKey, gesture: gestureName, confidence: score });
                }
            }

            updateGestureView(handDetections);
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

        _landmarksFirstDrawn = false;
        if (loadingOverlay) loadingOverlay.style.display = '';

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
        if (loadingOverlay) loadingOverlay.style.display = 'none';
        alert('Could not start camera.\n\nMake sure that:\n1. You have allowed camera permission for this site.\n2. No other application is using your webcam.');
        resetState();
    }
}

function resetState() {
    active = false;
    if (loadingOverlay) loadingOverlay.style.display = 'none';
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

    // Reset any queued commands and per-hand state machines
    clearPendingTimeout();
    pendingStack = [];
    handStates.clear();
    renderStackQueue();
    updatePillsState();

    updateGestureView([]);
    const ctx = webcamCanvas.getContext('2d');
    ctx.clearRect(0, 0, webcamCanvas.width, webcamCanvas.height);
}

// Expose for external callers (session timeout, etc.)
window.resetCameraState = resetState;
window.isGestureCameraActive = function () { return active; };

if (enableBtn) {
    enableBtn.addEventListener('click', startWebcam);
}
if (disableBtn) {
    disableBtn.addEventListener('click', resetState);
}