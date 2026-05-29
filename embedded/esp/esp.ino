/*
  ============================================================
  LUMINESENSE — ESP32 NodeMCU-32S
  ============================================================
  Responsibilities:
    - WiFi + database polling (XAMPP)
    - PIR sensor reading (GPIO13)
    - MOSFET gate control (GPIO26, GPIO27, GPIO25)
    - Serial2 bridge to/from Mega (GPIO16=RX, GPIO17=TX)
  ============================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// ── WiFi Credentials ───────────────────────────────────────
const char* WIFI_SSID     = "Converge_2.4GHz_SX3635";
const char* WIFI_PASSWORD = "QbcHSRKQ";

// ── XAMPP Server ───────────────────────────────────────────
const char* TOGGLE_URL      = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* SCHEDULE_URL    = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-schedule.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* PZEM_POST_URL   = "http://192.168.1.5/LUMINESENSE-finals/api/post_pzem.php";
const char* UPDATE_ROWS_URL = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-update-rows.php";

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN 26
#define ROW2_PIN 27
#define ROW3_PIN 25
#define PIR_PIN  13

// ── Serial2 to Mega ────────────────────────────────────────
#define MEGA_RX 16
#define MEGA_TX 17

// ── HTTP busy flag ─────────────────────────────────────────
bool httpBusy = false;

// ── Pending work flags ─────────────────────────────────────
// Instead of calling HTTP directly from handleMegaMessages,
// set a flag and let the loop handle it when HTTP is free
String pendingPzem          = "";
bool   pendingScheduleFetch = false;

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool pirState          = false;
bool lastPirState      = false;
bool pirOverrideActive = false;

// ── Timing ─────────────────────────────────────────────────
unsigned long lastDbPoll        = 0;
unsigned long lastScheduleFetch = 0;
#define DB_POLL_MS        5000
#define SCHEDULE_FETCH_MS 30000

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

    // WiFi
    WiFi.mode(WIFI_STA);
    delay(100);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    Serial.print(F("[WiFi] Connecting to "));
    Serial.println(WIFI_SSID);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 40) {
        delay(500);
        Serial.print(".");
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        Serial.println();
        Serial.print(F("[WiFi] Connected! IP: "));
        Serial.println(WiFi.localIP());

        // Fetch schedule immediately on boot so Mega gets it right away
        delay(500);
        fetchAndForwardSchedule();
    } else {
        Serial.println(F("[WiFi] Failed — running offline"));
    }

    Serial.println(F("=== ESP32 Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    handlePIR(now);
    handleMegaMessages();

    // Only one HTTP task runs per loop iteration — they take turns
    if (!httpBusy) {
        if (now - lastDbPoll >= DB_POLL_MS) {
            lastDbPoll = now;
            pollDatabase();
        }
        else if (now - lastScheduleFetch >= SCHEDULE_FETCH_MS || pendingScheduleFetch) {
            lastScheduleFetch    = now;
            pendingScheduleFetch = false;
            fetchAndForwardSchedule();
        }
        else if (pendingPzem != "") {
            forwardPzemToDb(pendingPzem);
            pendingPzem = "";
        }
    }
}

// ============================================================
// PIR HANDLER
// ============================================================
void handlePIR(unsigned long now) {
    pirState = digitalRead(PIR_PIN);

    if (pirState == HIGH && !pirOverrideActive) {
        Serial.println(F("[PIR] Motion detected!"));
        pirOverrideActive = true;
        Serial2.println("PIR:ON");
    }

    if (pirState == LOW && pirOverrideActive) {
        Serial.println(F("[PIR] Motion stopped"));
        pirOverrideActive = false;
        Serial2.println("PIR:OFF");
    }

    lastPirState = pirState;
}

// ============================================================
// HANDLE MESSAGES FROM MEGA
// ============================================================
void handleMegaMessages() {
    if (!Serial2.available()) return;

    String msg = Serial2.readStringUntil('\n');
    msg.trim();

    Serial.print(F("[RAW MSG] ")); Serial.println(msg);

    // JSON from Mega — queue for DB posting
    if (msg.startsWith("{")) {
        pendingPzem = msg;
        return;
    }

    msg.toUpperCase();
    Serial.print(F("[MEGA] ")); Serial.println(msg);

    if (msg == "FETCH:SCHEDULE") {
        // Queue the fetch — don't call directly, let loop handle when free
        pendingScheduleFetch = true;
        return;
    }

    if      (msg == "ACK:ROW1:ON")  { setRow(1, true);  }
    else if (msg == "ACK:ROW1:OFF") { setRow(1, false); }
    else if (msg == "ACK:ROW2:ON")  { setRow(2, true);  }
    else if (msg == "ACK:ROW2:OFF") { setRow(2, false); }
    else if (msg == "ACK:ROW3:ON")  { setRow(3, true);  }
    else if (msg == "ACK:ROW3:OFF") { setRow(3, false); }
    else if (msg == "ACK:ALL:ON")   { setAllRows(true); }
    else if (msg == "ACK:ALL:OFF")  { setAllRows(false);}
}

// ============================================================
// SET ROW
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
// POLL DATABASE FOR WEB TOGGLES
// ============================================================
void pollDatabase() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(TOGGLE_URL);
    http.setTimeout(3000);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        Serial.print(F("[DB] ")); Serial.println(payload);

        StaticJsonDocument<256> doc;
        DeserializationError err = deserializeJson(doc, payload);
        if (err) {
            Serial.println(F("[DB] JSON parse error"));
            http.end();
            httpBusy = false;
            return;
        }

        bool newR1 = doc["row1"] == 1;
        bool newR2 = doc["row2"] == 1;
        bool newR3 = doc["row3"] == 1;

        if (newR1 != row1State) Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF");
        if (newR2 != row2State) Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF");
        if (newR3 != row3State) Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF");
    } else {
        Serial.print(F("[DB] Poll failed, code: ")); Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// FETCH SCHEDULE AND FORWARD TO MEGA
// ============================================================
void fetchAndForwardSchedule() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(SCHEDULE_URL);
    http.setTimeout(3000);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        payload.trim();

        Serial.print(F("[SCHED] Payload: ")); Serial.println(payload);
        Serial.print(F("[SCHED] Length: "));  Serial.println(payload.length());

        if (payload.length() > 0) {
            Serial2.println("SCHEDULE:" + payload);
            Serial.println(F("[SCHED] Forwarded to Mega"));
        } else {
            Serial.println(F("[SCHED] Empty payload — no schedule today"));
        }
    } else {
        Serial.print(F("[SCHED] Fetch failed, code: ")); Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// FORWARD PZEM JSON TO DATABASE
// ============================================================
void forwardPzemToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(PZEM_POST_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/json");

    int httpCode = http.POST(jsonStr);
    if (httpCode == 200) {
        Serial.println(F("[PZEM] Posted to DB"));
    } else {
        Serial.print(F("[PZEM] Post failed, code: ")); Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// UPDATE ROW STATES IN DATABASE
// ============================================================
void updateRowsInDb(bool r1, bool r2, bool r3) {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
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
