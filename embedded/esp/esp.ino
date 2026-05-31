/*
  ============================================================
  LUMINESENSE — ESP32 NodeMCU-32S
  ============================================================
  Responsibilities:
    - WiFiManager (connect to any WiFi)
    - Poll DB for row toggles every 3s
    - Forward PZEM JSON from Mega to DB
    - Fetch schedule and forward to Mega every 30s
    - Check schedule flag for changes every 5s
    - PIR detection → send PIR:ON/OFF to Mega
    - Control MOSFETs (ROW1/2/3)
  ============================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>

// ── Server URLs ────────────────────────────────────────────
const char* TOGGLE_URL       = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* SCHEDULE_URL     = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-schedule.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* PZEM_POST_URL    = "http://192.168.1.5/LUMINESENSE-finals/api/pzem_push.php";
const char* UPDATE_ROWS_URL  = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-update-rows.php";
const char* SCHEDULE_FLAG_URL= "http://192.168.1.5/LUMINESENSE-finals/api/esp32-schedule-flag.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";

// ── Pins ───────────────────────────────────────────────────
#define ROW1_PIN 26
#define ROW2_PIN 27
#define ROW3_PIN 25
#define PIR_PIN  13
#define MEGA_RX  16
#define MEGA_TX  17

// ── Timing ─────────────────────────────────────────────────
#define DB_POLL_MS        3000
#define SCHEDULE_FETCH_MS 30000
#define FLAG_POLL_MS      5000

// ── State ──────────────────────────────────────────────────
bool httpBusy          = false;
bool row1State         = false;
bool row2State         = false;
bool row3State         = false;
bool pirState          = false;
bool pirOverrideActive = false;

String pendingPzem        = "";
String esp32Buffer        = "";
bool   pendingScheduleFetch = false;

unsigned long lastDbPoll        = 0;
unsigned long lastScheduleFetch = 0;
unsigned long lastFlagPoll      = 0;

// ── Forward declarations ───────────────────────────────────
void pollDatabase();
void fetchAndForwardSchedule();
void forwardPzemToDb(String);
void updateRowsInDb(bool, bool, bool);
void checkScheduleFlag();
void setRow(int, bool);
void setAllRows(bool);

// ============================================================
// SETUP
// ============================================================
void setup() {
    Serial.begin(115200);
    delay(1000);

    // Serial2 to Mega
    Serial2.begin(4800, SERIAL_8N1, MEGA_RX, MEGA_TX);
    delay(500);
    Serial2.flush();

    // MOSFET pins
    pinMode(ROW1_PIN, OUTPUT);
    pinMode(ROW2_PIN, OUTPUT);
    pinMode(ROW3_PIN, OUTPUT);
    digitalWrite(ROW1_PIN, LOW);
    digitalWrite(ROW2_PIN, LOW);
    digitalWrite(ROW3_PIN, LOW);

    // PIR pin
    pinMode(PIR_PIN, INPUT_PULLDOWN);

    // WiFiManager
    WiFiManager wm;
    wm.setConfigPortalTimeout(180);
    wm.setConnectTimeout(30);
    Serial.println(F("[WiFi] Starting WiFiManager..."));

    bool connected = wm.autoConnect("LumineSense-Setup", "luminesense123");

    if (connected) {
        Serial.print(F("[WiFi] Connected! IP: "));
        Serial.println(WiFi.localIP());
        delay(500);
        fetchAndForwardSchedule();
    } else {
        Serial.println(F("[WiFi] Timed out — running offline"));
    }

    Serial.println(F("=== ESP32 Ready ==="));
}

// ============================================================
// LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    handlePIR();
    handleMegaMessages();

    if (!httpBusy) {
        if (pendingPzem != "") {
            String toSend = pendingPzem;
            pendingPzem = "";
            forwardPzemToDb(toSend);
        }
        else if (now - lastDbPoll >= DB_POLL_MS) {
            lastDbPoll = now;
            pollDatabase();
        }
        else if (now - lastFlagPoll >= FLAG_POLL_MS) {
            lastFlagPoll = now;
            checkScheduleFlag();
        }
        else if (now - lastScheduleFetch >= SCHEDULE_FETCH_MS || pendingScheduleFetch) {
            lastScheduleFetch    = now;
            pendingScheduleFetch = false;
            fetchAndForwardSchedule();
        }
    }
}

// ============================================================
// PIR HANDLER
// ============================================================
void handlePIR() {
    bool reading = digitalRead(PIR_PIN);

    if (reading == HIGH && !pirOverrideActive) {
        Serial.println(F("[PIR] Motion detected!"));
        pirOverrideActive = true;
        pirState = true;
        Serial2.println("PIR:ON");
    }

    if (reading == LOW && pirOverrideActive) {
        Serial.println(F("[PIR] Motion stopped"));
        pirOverrideActive = false;
        pirState = false;
        Serial2.println("PIR:OFF");
    }
}

// ============================================================
// HANDLE MESSAGES FROM MEGA
// ============================================================
void handleMegaMessages() {
    while (Serial2.available()) {
        char c = Serial2.read();
        if (c == '\r') continue;

        if (c == '\n') {
            esp32Buffer.trim();
            if (esp32Buffer.length() == 0) {
                esp32Buffer = "";
                continue;
            }

            String msg = esp32Buffer;
            esp32Buffer = "";

            Serial.print(F("[RAW] ")); Serial.println(msg);

            // JSON from Mega — queue for DB posting
            if (msg.startsWith("{")) {
                pendingPzem = msg;
                continue;
            }

            msg.toUpperCase();

            if      (msg == "ACK:ROW1:ON")    { setRow(1, true);           }
            else if (msg == "ACK:ROW1:OFF")   { setRow(1, false);          }
            else if (msg == "ACK:ROW2:ON")    { setRow(2, true);           }
            else if (msg == "ACK:ROW2:OFF")   { setRow(2, false);          }
            else if (msg == "ACK:ROW3:ON")    { setRow(3, true);           }
            else if (msg == "ACK:ROW3:OFF")   { setRow(3, false);          }
            else if (msg == "ACK:ALL:ON")     { setAllRows(true);          }
            else if (msg == "ACK:ALL:OFF")    { setAllRows(false);         }
            else if (msg == "FETCH:SCHEDULE") { pendingScheduleFetch = true; }

        } else {
            esp32Buffer += c;
        }
    }
}

// ============================================================
// ROW CONTROL
// ============================================================
void setRow(int row, bool state) {
    switch (row) {
        case 1:
            row1State = state;
            digitalWrite(ROW1_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW1] ")); Serial.println(state ? "ON" : "OFF");
            break;
        case 2:
            row2State = state;
            digitalWrite(ROW2_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW2] ")); Serial.println(state ? "ON" : "OFF");
            break;
        case 3:
            row3State = state;
            digitalWrite(ROW3_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW3] ")); Serial.println(state ? "ON" : "OFF");
            break;
    }
}

void setAllRows(bool state) {
    setRow(1, state);
    setRow(2, state);
    setRow(3, state);
    updateRowsInDb(state, state, state);
}

// ============================================================
// HTTP — POLL DATABASE FOR ROW TOGGLES
// ============================================================
void pollDatabase() {
    if (WiFi.status() != WL_CONNECTED) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(TOGGLE_URL);
    http.setTimeout(3000);
    int code = http.GET();

    if (code == 200) {
        String payload = http.getString();
        Serial.print(F("[DB] ")); Serial.println(payload);

        StaticJsonDocument<256> doc;
        if (!deserializeJson(doc, payload)) {
            bool newR1 = doc["row1"] == 1;
            bool newR2 = doc["row2"] == 1;
            bool newR3 = doc["row3"] == 1;

            if (newR1 != row1State) Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF");
            if (newR2 != row2State) Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF");
            if (newR3 != row3State) Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF");
        }
    } else {
        Serial.print(F("[DB] Failed, code: ")); Serial.println(code);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// HTTP — FETCH SCHEDULE AND FORWARD TO MEGA
// ============================================================
void fetchAndForwardSchedule() {
    if (WiFi.status() != WL_CONNECTED) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(SCHEDULE_URL);
    http.setTimeout(3000);
    int code = http.GET();

    if (code == 200) {
        String payload = http.getString();
        payload.trim();
        Serial.print(F("[SCHED] ")); Serial.println(payload);

        if (payload.length() > 0) {
            Serial2.println("SCHEDULE:" + payload);
            Serial.println(F("[SCHED] Forwarded to Mega"));
        } else {
            Serial.println(F("[SCHED] No schedule today"));
        }
    } else {
        Serial.print(F("[SCHED] Failed, code: ")); Serial.println(code);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// HTTP — FORWARD PZEM JSON TO DATABASE
// ============================================================
void forwardPzemToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) return;
    httpBusy = true;

    StaticJsonDocument<256> doc;
    if (deserializeJson(doc, jsonStr)) {
        Serial.println(F("[PZEM] JSON parse error — dropping"));
        httpBusy = false;
        return;
    }

    if (!doc.containsKey("classroom_id")) doc["classroom_id"] = 3;

    String outJson;
    serializeJson(doc, outJson);

    HTTPClient http;
    http.begin(PZEM_POST_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", "luminesense-secret-token");

    int code = http.POST(outJson);
    Serial.print(F("[PZEM] ")); Serial.println(code == 200 ? "Posted OK" : "Failed, code: " + String(code));

    http.end();
    httpBusy = false;
}

// ============================================================
// HTTP — UPDATE ROW STATES IN DATABASE
// ============================================================
void updateRowsInDb(bool r1, bool r2, bool r3) {
    if (WiFi.status() != WL_CONNECTED) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(UPDATE_ROWS_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String body = "token=LS_ESP32_TOKEN_2025&classroom_id=3";
    body += "&row1=" + String(r1 ? "on" : "off");
    body += "&row2=" + String(r2 ? "on" : "off");
    body += "&row3=" + String(r3 ? "on" : "off");

    http.POST(body);
    http.end();
    httpBusy = false;
}

// ============================================================
// HTTP — CHECK SCHEDULE FLAG
// ============================================================
void checkScheduleFlag() {
    if (WiFi.status() != WL_CONNECTED) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(SCHEDULE_FLAG_URL);
    http.setTimeout(3000);
    int code = http.GET();

    if (code == 200) {
        String payload = http.getString();
        StaticJsonDocument<64> doc;
        if (!deserializeJson(doc, payload) && doc["dirty"] == true) {
            Serial.println(F("[FLAG] Schedule changed — fetching!"));
            http.end();
            httpBusy = false;
            fetchAndForwardSchedule();
            return;
        }
    } else {
        Serial.print(F("[FLAG] Failed, code: ")); Serial.println(code);
    }

    http.end();
    httpBusy = false;
}