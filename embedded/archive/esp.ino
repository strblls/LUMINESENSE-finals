#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

const char* WIFI_SSID     = "Converge_5GHz_SX3635";
const char* WIFI_PASSWORD = "QbcHSRKQ";
const char* STATUS_URL    = "http://192.168.1.2/LUMINESENSE-finals/api/esp32-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=1";
const char* PIR_URL       = "http://192.168.1.2/LUMINESENSE-finals/api/pir.php";
const char* PZEM_URL      = "http://192.168.1.2/LUMINESENSE-finals/api/pzem-update.php";

unsigned long lastPoll = 0;
const int POLL_INTERVAL = 1000;

// Last known states to avoid redundant commands to Mega
int lastRow1 = -1, lastRow2 = -1, lastRow3 = -1;

void setup() {
    Serial.begin(4800);   // talk to Arduino Mega via Serial
    Serial.println("ESP32 booting...");

    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    Serial.print("Connecting to WiFi");
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
    }
    Serial.println("\nWiFi Connected: " + WiFi.localIP().toString());
}

void loop() {
    // ── Poll light states from DB ──────────────────
    if (millis() - lastPoll >= POLL_INTERVAL) {
        lastPoll = millis();
        pollLightStates();
    }

    // ── Read JSON from Mega (PZEM data + PIR state) ─
    if (Serial.available()) {
        String line = Serial.readStringUntil('\n');
        line.trim();
        handleMegaMessage(line);
    }
}

void pollLightStates() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(STATUS_URL);
    int code = http.GET();

    if (code == 200) {
        String payload = http.getString();
        StaticJsonDocument<128> doc;
        deserializeJson(doc, payload);

        int r1 = doc["row1"] | 0;
        int r2 = doc["row2"] | 0;
        int r3 = doc["row3"] | 0;

        // Only send command to Mega if state changed
        if (r1 != lastRow1) {
            Serial.println(r1 ? "ROW1:ON" : "ROW1:OFF");
            lastRow1 = r1;
        }
        if (r2 != lastRow2) {
            Serial.println(r2 ? "ROW2:ON" : "ROW2:OFF");
            lastRow2 = r2;
        }
        if (r3 != lastRow3) {
            Serial.println(r3 ? "ROW3:ON" : "ROW3:OFF");
            lastRow3 = r3;
        }
    }
    http.end();
}

void handleMegaMessage(String line) {
    // Handle PZEM JSON from Mega
    if (line.startsWith("{")) {
        StaticJsonDocument<200> doc;
        DeserializationError err = deserializeJson(doc, line);
        if (err) return;

        String type = doc["type"] | "";

        // Forward PZEM data to web
        if (type == "pzem") {
            sendPzemToWeb(
                doc["voltage"] | 0.0,
                doc["current"] | 0.0,
                doc["power"]   | 0.0,
                doc["energy"]  | 0.0
            );
        }

        // Forward PIR state to web
        if (type == "sync" || type == "pzem") {
            bool pir = doc["pir"] | false;
            sendPirToWeb(pir ? 1 : 0);
        }
    }

    // Handle schedule request from Mega
    if (line == "FETCH:SCHEDULE") {
        fetchAndForwardSchedule();
    }
}

void sendPzemToWeb(float voltage, float current, float power, float energy) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(PZEM_URL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String body = "classroom_id=1"
                  "&voltage=" + String(voltage, 2) +
                  "&current=" + String(current, 3) +
                  "&power="   + String(power, 2) +
                  "&energy="  + String(energy, 4) +
                  "&arduino_token=LS_PIR_TOKEN_2025";

    http.POST(body);
    http.end();
}

void sendPirToWeb(int occupied) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(PIR_URL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String body = "classroom_id=1&occupied=" + String(occupied)
                + "&arduino_token=LS_PIR_TOKEN_2025";
    http.POST(body);
    http.end();
}

void fetchAndForwardSchedule() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin("http://192.168.1.2/LUMINESENSE-finals/api/esp32-schedule.php?classroom_id=1&token=LS_ESP32_TOKEN_2025");
    int code = http.GET();

    if (code == 200) {
        String payload = http.getString();
        // Forward to Mega as: SCHEDULE:08:00-10:00,13:00-15:30
        Serial.println("SCHEDULE:" + payload);
    }
    http.end();
}