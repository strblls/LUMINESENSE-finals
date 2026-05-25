import os
import time
import requests
import threading
from threading import Lock

from flask import Flask, Response, jsonify
import cv2
import mediapipe as mp
from mediapipe.tasks import python as mp_python
from mediapipe.tasks.python import vision

app = Flask(__name__)
API_URL      = "http://localhost/LUMINESENSE-finals/api/lights.php"
CLASSROOM_ID = 1
COOLDOWN     = 1.5

last_trigger = 0
last_gesture = None

row_states = {1: False, 2: False, 3: False}
active_row  = 1

gesture_buffer = []
GESTURE_CONFIRM_FRAMES = 3

script_dir = os.path.dirname(os.path.abspath(__file__))
model_path = os.path.join(script_dir, 'gesture_recognizer.task')

base_options = mp_python.BaseOptions(model_asset_path=model_path)
options = vision.GestureRecognizerOptions(
    base_options=base_options,
    running_mode=vision.RunningMode.IMAGE
)
recognizer = vision.GestureRecognizer.create_from_options(options)

cap = None

AVAILABLE_GESTURES = [
    'Open_Palm',
    'Thumb_Up',
    'Closed_Fist',
    'Pointing_Up',
    'Victory',
    'ILoveYou',
]

gesture_state = {
    'gesture': 'No Gesture',
    'confidence': 0,
    'updatedAt': None,
}

state_lock = Lock()
frame_lock  = threading.Lock()
current_frame = None


def set_gesture_state(gesture, confidence):
    with state_lock:
        gesture_state['gesture'] = gesture
        gesture_state['confidence'] = int(max(0, min(100, confidence)))
        gesture_state['updatedAt'] = time.time()


def send_command(row, state):
    try:
        requests.post(API_URL, data={
            'classroom_id': CLASSROOM_ID,
            'row':          str(row),
            'state':        state,
            'triggered_by': 'gesture'
        }, timeout=2)
        print(f"Sent: row={row} state={state}")
    except Exception as e:
        print(f"API error: {e}")


def handle_gesture(best_gesture):
    global last_trigger, last_gesture, row_states, active_row
    now = time.time()
    if best_gesture != last_gesture and (now - last_trigger) > COOLDOWN:
        last_trigger = now
        last_gesture = best_gesture

        if best_gesture == 'Pointing_Up':
            row_states[1] = not row_states[1]
            send_command(1, 'on' if row_states[1] else 'off')
        elif best_gesture == 'Victory':
            row_states[2] = not row_states[2]
            send_command(2, 'on' if row_states[2] else 'off')
        elif best_gesture == 'ILoveYou':
            row_states[3] = not row_states[3]
            send_command(3, 'on' if row_states[3] else 'off')
        elif best_gesture == 'Thumb_Up':
            row_states[active_row] = not row_states[active_row]
            send_command(active_row, 'on' if row_states[active_row] else 'off')
            active_row = (active_row % 3) + 1
        elif best_gesture == 'Open_Palm':
            for r in [1, 2, 3]:
                row_states[r] = True
            send_command('all', 'on')
        elif best_gesture == 'Closed_Fist':
            for r in [1, 2, 3]:
                row_states[r] = False
            send_command('all', 'off')


def capture_loop():
    global current_frame, cap
    cap = cv2.VideoCapture(0)
    while True:
        success, frame = cap.read()
        if not success or frame is None:
            continue

        height, width, _ = frame.shape
        frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=frame_rgb)

        try:
            results = recognizer.recognize(mp_image)
        except Exception:
            results = None

        best_gesture = 'No Gesture'
        best_confidence = 0

        if results and results.gestures:
            for hand_gestures in results.gestures:
                if hand_gestures:
                    top_gesture = hand_gestures[0]
                    if int(top_gesture.score * 100) > best_confidence:
                        best_gesture = top_gesture.category_name
                        best_confidence = int(top_gesture.score * 100)

        if results and results.hand_landmarks:
            for hand_landmarks in results.hand_landmarks:
                connections = [
                    (0,1),(1,2),(2,3),(3,4),
                    (0,5),(5,6),(6,7),(7,8),
                    (5,9),(9,10),(10,11),(11,12),
                    (9,13),(13,14),(14,15),(15,16),
                    (13,17),(0,17),(17,18),(18,19),(19,20)
                ]
                for p1, p2 in connections:
                    pt1 = (int(hand_landmarks[p1].x * width), int(hand_landmarks[p1].y * height))
                    pt2 = (int(hand_landmarks[p2].x * width), int(hand_landmarks[p2].y * height))
                    cv2.line(frame, pt1, pt2, (46, 204, 113), 2)
                for lm in hand_landmarks:
                    cx, cy = int(lm.x * width), int(lm.y * height)
                    cv2.circle(frame, (cx, cy), 5, (231, 76, 60), -1)

        if best_confidence >= 50 and best_gesture in AVAILABLE_GESTURES:
            gesture_buffer.append(best_gesture)
            if len(gesture_buffer) > GESTURE_CONFIRM_FRAMES:
                gesture_buffer.pop(0)
            if len(gesture_buffer) == GESTURE_CONFIRM_FRAMES and \
               all(g == best_gesture for g in gesture_buffer):
                set_gesture_state(best_gesture, best_confidence)
                handle_gesture(best_gesture)
        else:
            gesture_buffer.clear()
            set_gesture_state('No Gesture', 0)
            global last_gesture
            last_gesture = None

        _, buffer = cv2.imencode('.jpg', frame)
        with frame_lock:
            current_frame = buffer.tobytes()


def generate_frames():
    while True:
        with frame_lock:
            frame = current_frame
        if frame is None:
            time.sleep(0.05)
            continue
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + frame + b'\r\n')
        time.sleep(0.033)


@app.after_request
def add_cors_headers(response):
    response.headers['Access-Control-Allow-Origin'] = '*'
    response.headers['Access-Control-Allow-Headers'] = '*'
    response.headers['Access-Control-Allow-Methods'] = '*'
    return response


@app.route('/video_feed')
def video_feed():
    return Response(generate_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')


@app.route('/status')
def status():
    with state_lock:
        payload = dict(gesture_state)
    payload['availableGestures'] = AVAILABLE_GESTURES
    payload['running'] = cap is not None
    return jsonify(payload)


if __name__ == '__main__':
    t = threading.Thread(target=capture_loop, daemon=True)
    t.start()
    app.run(host='127.0.0.1', port=5000, debug=False, threaded=True)