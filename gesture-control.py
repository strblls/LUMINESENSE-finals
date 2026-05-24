import os
import time
from threading import Lock

from flask import Flask, Response, jsonify
import cv2
import mediapipe as mp
from mediapipe.tasks import python as mp_python
from mediapipe.tasks.python import vision

app = Flask(__name__)

# ── Modern MediaPipe Tasks Setup (Works Natively in Python 3.14) ──────────────
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


def set_gesture_state(gesture, confidence):
    with state_lock:
        gesture_state['gesture'] = gesture
        gesture_state['confidence'] = int(max(0, min(100, confidence)))
        gesture_state['updatedAt'] = time.time()


def generate_frames():
    global cap
    if cap is None:
        cap = cv2.VideoCapture(0)

    while True:
        success, frame = cap.read()

        if not success or frame is None:
            continue

        height, width, _ = frame.shape
        frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        
        # Convert to MediaPipe Image
        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=frame_rgb)
        
        # Run Gesture Recognizer
        results = recognizer.recognize(mp_image)

        best_gesture = 'No Gesture'
        best_confidence = 0

        if results.gestures:
            for hand_gestures in results.gestures:
                if hand_gestures:
                    top_gesture = hand_gestures[0]
                    category_name = top_gesture.category_name
                    score = int(top_gesture.score * 100)
                    if score > best_confidence:
                        best_gesture = category_name
                        best_confidence = score

        # Draw hand landmarks manually to avoid deprecated mp.solutions.drawing_utils
        if results.hand_landmarks:
            for hand_landmarks in results.hand_landmarks:
                # Skeletal connection lines
                connections = [
                    (0, 1), (1, 2), (2, 3), (3, 4),      # Thumb
                    (0, 5), (5, 6), (6, 7), (7, 8),      # Index
                    (5, 9), (9, 10), (10, 11), (11, 12),  # Middle
                    (9, 13), (13, 14), (14, 15), (15, 16),# Ring
                    (13, 17), (0, 17), (17, 18), (18, 19), (19, 20) # Pinky
                ]
                for p1, p2 in connections:
                    pt1 = (int(hand_landmarks[p1].x * width), int(hand_landmarks[p1].y * height))
                    pt2 = (int(hand_landmarks[p2].x * width), int(hand_landmarks[p2].y * height))
                    cv2.line(frame, pt1, pt2, (46, 204, 113), 2)  # Sleek green line

                for lm in hand_landmarks:
                    cx, cy = int(lm.x * width), int(lm.y * height)
                    cv2.circle(frame, (cx, cy), 5, (231, 76, 60), -1)  # Vivid red dots

        if best_confidence >= 60 and best_gesture in AVAILABLE_GESTURES:
            set_gesture_state(best_gesture, best_confidence)
        else:
            set_gesture_state('No Gesture', 0)

        _, buffer = cv2.imencode('.jpg', frame)
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + buffer.tobytes() + b'\r\n')


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
    app.run(host='127.0.0.1', port=5000, debug=False)