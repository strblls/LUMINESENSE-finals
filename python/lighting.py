from flask import Flask, request, jsonify
import requests

app      = Flask(__name__)
ESP32_IP = "http://luminesense.local"   # ← never changes again

@app.route('/lighting/toggle', methods=['POST'])
def toggle():
    data  = request.get_json()
    row   = data.get('row')
    state = data.get('state')

    print(f"Command received: ROW{row}:{state.upper()}")

    try:
        res = requests.post(
            f"{ESP32_IP}/toggle",
            json={ 'row': row, 'state': state },
            timeout=3
        )
        return jsonify({ 'status': 'ok', 'esp32': res.json() })
    except Exception as e:
        return jsonify({ 'status': 'error', 'message': str(e) }), 500

if __name__ == '__main__':
    app.run(port=5001)