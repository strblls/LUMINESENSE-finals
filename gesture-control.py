# from flask import Flask, Response
# import cv2
# import mediapipe as mp
# from mediapipe.tasks import python
# from mediapipe.tasks.python import vision

# app = Flask(__name__)

# # New MediaPipe API
# BaseOptions = mp.tasks.BaseOptions
# GestureRecognizer = mp.tasks.vision.GestureRecognizer
# GestureRecognizerOptions = mp.tasks.vision.GestureRecognizerOptions
# VisionRunningMode = mp.tasks.vision.RunningMode

# cap = cv2.VideoCapture(0)
# mp_draw = mp.solutions.drawing_utils
# mp_hands = mp.solutions.hands
# hands = mp_hands.Hands(
#     static_image_mode=False,
#     max_num_hands=1,
#     min_detection_confidence=0.7
# )

# def generate_frames():
#     while True:
#         success, frame = cap.read()
#         if not success or frame is None:
#             continue

#         frame_rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
#         results = hands.process(frame_rgb)

#         if results.multi_hand_landmarks:
#             for hand_landmarks in results.multi_hand_landmarks:
#                 mp_draw.draw_landmarks(frame, hand_landmarks, mp_hands.HAND_CONNECTIONS)

#         _, buffer = cv2.imencode('.jpg', frame)
#         frame_bytes = buffer.tobytes()
#         yield (b'--frame\r\n'
#                b'Content-Type: image/jpeg\r\n\r\n' + frame_bytes + b'\r\n')

# @app.route('/video_feed')
# def video_feed():
#     return Response(generate_frames(),
#                     mimetype='multipart/x-mixed-replace; boundary=frame')

# if __name__ == '__main__':
#     app.run(host='0.0.0.0', port=5000, debug=False)

from flask import Flask, Response
import cv2
import mediapipe as mp
from mediapipe.tasks import python as mp_python
from mediapipe.tasks.python import vision

app = Flask(__name__)
cap = cv2.VideoCapture(0)

# Drawing utilities
draw_utils  = mp.tasks.vision.drawing_utils
draw_styles = mp.tasks.vision.drawing_styles
HandLandmarksConnections = mp.tasks.vision.HandLandmarksConnections

# Latest detected gesture
latest_gesture  = '—'
latest_accuracy = 0.0

def on_result(result, output_image, timestamp_ms):
    global latest_gesture, latest_accuracy
    if result.gestures:
        gesture  = result.gestures[0][0]
        latest_gesture  = gesture.category_name
        latest_accuracy = gesture.score

# Build recognizer
options = vision.GestureRecognizerOptions(
    base_options=mp_python.BaseOptions(
        model_asset_path='gesture_recognizer.task'
    ),
    running_mode=vision.RunningMode.LIVE_STREAM,
    result_callback=on_result,
    num_hands=1
)
recognizer = vision.GestureRecognizer.create_from_options(options)

frame_timestamp = 0

def generate_frames():
    global frame_timestamp
    while True:
        success, frame = cap.read()
        if not success or frame is None:
            continue

        frame_timestamp += 1
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
        recognizer.recognize_async(mp_image, frame_timestamp)

        # Overlay gesture label on frame
        label = f'{latest_gesture} ({int(latest_accuracy * 100)}%)'
        cv2.putText(frame, label, (10, 40),
                    cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)

        _, buffer = cv2.imencode('.jpg', frame)
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + buffer.tobytes() + b'\r\n')

@app.route('/video_feed')
def video_feed():
    return Response(generate_frames(),
                    mimetype='multipart/x-mixed-replace; boundary=frame')

@app.route('/latest_gesture')
def get_latest_gesture():
    return {'gesture': latest_gesture, 'accuracy': latest_accuracy}

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000, debug=False)

import time

def generate_frames():
    global frame_timestamp
    while True:
        success, frame = cap.read()
        if not success or frame is None:
            continue

        frame_timestamp += 1
        rgb = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
        mp_image = mp.Image(image_format=mp.ImageFormat.SRGB, data=rgb)
        recognizer.recognize_async(mp_image, frame_timestamp)

        # Overlay gesture label
        label = f'{latest_gesture} ({int(latest_accuracy * 100)}%)'
        cv2.putText(frame, label, (10, 40),
                    cv2.FONT_HERSHEY_SIMPLEX, 1, (0, 255, 0), 2)

        _, buffer = cv2.imencode('.jpg', frame, [cv2.IMWRITE_JPEG_QUALITY, 50])  # lower quality = lighter
        yield (b'--frame\r\n'
               b'Content-Type: image/jpeg\r\n\r\n' + buffer.tobytes() + b'\r\n')

        time.sleep(0.05)  # ~20fps instead of max speed