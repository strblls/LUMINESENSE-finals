import time
from threading import Lock

from flask import Flask, Response, jsonify
import cv2
import mediapipe as mp

app = Flask(__name__)
mp_hands = mp.solutions.hands
hands = mp_hands.Hands(
    max_num_hands=2,
    min_detection_confidence=0.7,
    min_tracking_confidence=0.6,
)
mp_draw = mp.solutions.drawing_utils
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


def is_finger_extended(landmarks, tip_index, pip_index):
    return landmarks[tip_index].y < landmarks[pip_index].y


def is_thumb_extended(landmarks, handedness_label):
    thumb_tip = landmarks[4]
    thumb_ip = landmarks[3]

    if handedness_label == 'Left':
        return thumb_tip.x > thumb_ip.x

    return thumb_tip.x < thumb_ip.x


def is_thumb_up(landmarks, handedness_label):
    thumb_tip = landmarks[4]
    thumb_ip = landmarks[3]
    wrist = landmarks[0]
    index_mcp = landmarks[5]
    pinky_mcp = landmarks[17]
    palm_width = abs(index_mcp.x - pinky_mcp.x) + 1e-6

    direction_is_vertical = thumb_tip.y < thumb_ip.y and thumb_tip.y < wrist.y
    centered_over_palm = abs(thumb_tip.x - wrist.x) < palm_width * 0.7

    return direction_is_vertical and centered_over_palm


def score_conditions(conditions):
    if not conditions:
        return 0

    matches = sum(1 for condition in conditions if condition)
    return round((matches / len(conditions)) * 100)


def classify_gesture(landmarks, handedness_label='Right'):
    thumb_extended = is_thumb_extended(landmarks, handedness_label)
    index_extended = is_finger_extended(landmarks, 8, 6)
    middle_extended = is_finger_extended(landmarks, 12, 10)
    ring_extended = is_finger_extended(landmarks, 16, 14)
    pinky_extended = is_finger_extended(landmarks, 20, 18)
    thumb_up = is_thumb_up(landmarks, handedness_label)

    candidates = [
        ('Open_Palm', score_conditions([
            thumb_extended,
            index_extended,
            middle_extended,
            ring_extended,
            pinky_extended,
        ])),
        ('Thumb_Up', score_conditions([
            thumb_up,
            not index_extended,
            not middle_extended,
            not ring_extended,
            not pinky_extended,
        ])),
        ('Closed_Fist', score_conditions([
            not thumb_extended,
            not index_extended,
            not middle_extended,
            not ring_extended,
            not pinky_extended,
        ])),
        ('Pointing_Up', score_conditions([
            index_extended,
            not thumb_extended,
            not middle_extended,
            not ring_extended,
            not pinky_extended,
        ])),
        ('Victory', score_conditions([
            index_extended,
            middle_extended,
            not thumb_extended,
            not ring_extended,
            not pinky_extended,
        ])),
        ('ILoveYou', score_conditions([
            thumb_extended,
            index_extended,
            pinky_extended,
            not middle_extended,
            not ring_extended,
        ])),
    ]

    return max(candidates, key=lambda item: item[1])


def generate_frames():
    global cap
    if cap is None:
        cap = cv2.VideoCapture(0)

    while True:
        success, frame = cap.read()

        if not success or frame is None:
            continue

        frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        results = hands.process(frame_rgb)

        best_gesture = 'No Gesture'
        best_confidence = 0

        if results.multi_hand_landmarks:
            handedness_list = results.multi_handedness or []

            for hand_index, hand_landmarks in enumerate(results.multi_hand_landmarks):
                handedness_label = 'Right'
                if hand_index < len(handedness_list):
                    handedness_label = handedness_list[hand_index].classification[0].label

                gesture_name, confidence = classify_gesture(hand_landmarks.landmark, handedness_label)

                if confidence > best_confidence:
                    best_gesture = gesture_name
                    best_confidence = confidence

                mp_draw.draw_landmarks(frame, hand_landmarks, mp_hands.HAND_CONNECTIONS)

        if best_confidence >= 75 and best_gesture in AVAILABLE_GESTURES:
            set_gesture_state(best_gesture, best_confidence)
        else:
            set_gesture_state('No Gesture', 0)

        _, buffer = cv2.imencode('.jpg', frame)
        frame = buffer.tobytes()
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + frame + b'\r\n')


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