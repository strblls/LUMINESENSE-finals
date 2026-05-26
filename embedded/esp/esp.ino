/*
  ============================================================
  LUMINESENSE — ESP32 NodeMCU-32S
  ============================================================
  Responsibilities:
    - WiFi + database polling (XAMPP)
    - PIR sensor reading (P13 = GPIO13)
    - MOSFET gate control (P2, P15, P4)
    - Serial2 bridge to/from Mega (P16=RX, P17=TX)
  ============================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

// ── WiFi Credentials ───────────────────────────────────────
const char* WIFI_SSID     = "Converge_2.4GHz_SX3635";
const char* WIFI_PASSWORD = "QbcHSRKQ";

// ── XAMPP Server ───────────────────────────────────────────
const char* SERVER_IP     = "192.168.1.5";   // Your PC's local IP
const char* TOGGLE_URL    = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* SCHEDULE_URL  = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-schedule.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* PZEM_POST_URL = "http://192.168.1.5/LUMINESENSE-finals/api/post_pzem.php";
const char* UPDATE_ROWS_URL = "http://192.168.1.5/LUMINESENSE-finals/api/esp32-update-rows.php";

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN   26    // P26  - MOSFET Gate Row 1
#define ROW2_PIN   27   // P27 - MOSFET Gate Row 2
#define ROW3_PIN   25    // P4  - MOSFET Gate Row 3
#define PIR_PIN    13   // P13 - PIR Sensor

// ── Serial2 to Mega ────────────────────────────────────────
// P16 (GPIO16) = RX2 ← Mega TX2 (pin 16)
// P17 (GPIO17) = TX2 → Mega RX2 (pin 17)
#define MEGA_RX 16
#define MEGA_TX 17

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool          pirState       = false;
bool          lastPirState   = false;
bool pirActive = false;
bool pirOverrideActive = false;
unsigned long pirTriggeredAt = 0;
#define PIR_HOLD_MS 30000

// ── Timing ─────────────────────────────────────────────────
unsigned long lastDbPoll      = 0;
unsigned long lastScheduleFetch = 0;
#define DB_POLL_MS        2000
#define SCHEDULE_FETCH_MS 15000

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
    WiFi.mode(WIFI_STA);  // add this line
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
    } else {
        Serial.println(F("[WiFi] Failed — running offline"));
    }

    Serial.println(F("=== ESP32 Ready ==="));
}

void updateRowsInDb(bool r1, bool r2, bool r3) {
    if (WiFi.status() != WL_CONNECTED) return;
    HTTPClient http;
    http.begin(UPDATE_ROWS_URL);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    String body = "token=LS_ESP32_TOKEN_2025&classroom_id=3";
    body += "&row1=" + String(r1 ? "on" : "off");
    body += "&row2=" + String(r2 ? "on" : "off");
    body += "&row3=" + String(r3 ? "on" : "off");
    http.POST(body);
    http.end();
}

// void setup() {
//     Serial.begin(115200);
//     delay(1000);
    
//     pinMode(26, OUTPUT);
//     pinMode(27, OUTPUT);
//     pinMode(25, OUTPUT);
    
//     digitalWrite(26, LOW);
//     digitalWrite(27, LOW);
//     digitalWrite(25, LOW);
// }

// void loop() {
//     Serial.println("ROW1 ON");
//     digitalWrite(26, HIGH); delay(2000);
//     digitalWrite(26, LOW);  delay(1000);

//     Serial.println("ROW2 ON");
//     digitalWrite(27, HIGH); delay(2000);
//     digitalWrite(27, LOW);  delay(1000);

//     Serial.println("ROW3 ON");
//     digitalWrite(25, HIGH);  delay(2000);
//     digitalWrite(25, LOW);   delay(1000);
// } end of test loops

// MAIN LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    // 1. Read PIR and notify Mega
    handlePIR(now);

    // 2. Listen for commands from Mega
    handleMegaMessages();

    // 3. Poll DB for web toggle commands
    if (now - lastDbPoll >= DB_POLL_MS) {
        lastDbPoll = now;
        pollDatabase();
    }

    // 4. Fetch schedule and forward to Mega
    if (now - lastScheduleFetch >= SCHEDULE_FETCH_MS) {
        lastScheduleFetch = now;
        fetchAndForwardSchedule();
    }
}

// PIR HANDLER — reads sensor, notifies Mega
// ============================================================
void handlePIR(unsigned long now) {
    pirState = digitalRead(PIR_PIN);

    // Only send PIR:ON once, then ignore until it goes LOW first
    if (pirState == HIGH && !pirOverrideActive) {
        Serial.println(F("[PIR] Motion detected!"));
        pirTriggeredAt = now;
        pirOverrideActive = true;
        Serial2.println("PIR:ON");
    }

    // Reset when no motion
    if (pirState == LOW && pirOverrideActive) {
        Serial.println(F("[PIR] Motion stopped"));
        pirOverrideActive = false;
        Serial2.println("PIR:OFF");
    }

    lastPirState = pirState;
}

// HANDLE MESSAGES FROM MEGA
// Mega sends: ROW1:ON, ROW2:OFF, ALL:ON, etc.
// ============================================================
void handleMegaMessages() {
    if (Serial2.available()) {
        String msg = Serial2.readStringUntil('\n');
        msg.trim();
        msg.toUpperCase();

        Serial.print(F("[MEGA] ")); Serial.println(msg);

        // Handle schedule fetch request
        if (msg == "FETCH:SCHEDULE") {
            fetchAndForwardSchedule();
            return;
        }

        // Handle JSON from Mega (PZEM data) — forward to DB
        if (msg.startsWith("{")) {
            forwardPzemToDb(msg);
            return;
        }

        if (msg == "PIR:RELEASE") {
            pirActive = false;
            Serial.println(F("[PIR] Released — manual mode restored"));
        }

        // Handle row commands
        if      (msg == "ACK:ROW1:ON")  { setRow(1, true); }
        else if (msg == "ACK:ROW1:OFF") { setRow(1, false); }
        else if (msg == "ACK:ROW2:ON")  { setRow(2, true); }
        else if (msg == "ACK:ROW2:OFF") { setRow(2, false); }
        else if (msg == "ACK:ROW3:ON")  { setRow(3, true); }
        else if (msg == "ACK:ROW3:OFF") { setRow(3, false); }
        else if (msg == "ACK:ALL:ON")   { setAllRows(true); }
        else if (msg == "ACK:ALL:OFF")  { setAllRows(false); }
    }
}

// SET ROW — controls MOSFET gates directly
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
    updateRowsInDb(state, state, state);  // sync to DB!
}

// POLL DATABASE FOR WEB TOGGLES
// ============================================================
void pollDatabase() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(TOGGLE_URL);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        Serial.print(F("[DB] ")); Serial.println(payload);

        StaticJsonDocument<256> doc;
        DeserializationError err = deserializeJson(doc, payload);
        if (err) {
            Serial.println(F("[DB] JSON parse error"));
            http.end();
            return;
        }

        bool newR1 = doc["row1"] == 1;
        bool newR2 = doc["row2"] == 1;
        bool newR3 = doc["row3"] == 1;

        // Forward to Mega — let Mega decide based on state!
        if (newR1 != row1State) Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF");
        if (newR2 != row2State) Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF");
        if (newR3 != row3State) Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF");
    } else {
        Serial.print(F("[DB] Poll failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}

// FETCH SCHEDULE FROM XAMPP AND FORWARD TO MEGA
// ============================================================
void fetchAndForwardSchedule() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(SCHEDULE_URL);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        // Expected: "08:00-10:00,13:00-15:30"
        payload.trim();
        Serial2.println("SCHEDULE:" + payload);
        Serial.print(F("[SCHED] Forwarded to Mega: ")); Serial.println(payload);
    } else {
        Serial.print(F("[SCHED] Fetch failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}

// FORWARD PZEM DATA TO DATABASE
// ============================================================
void forwardPzemToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(PZEM_POST_URL);
    http.addHeader("Content-Type", "application/json");

    int httpCode = http.POST(jsonStr);
    if (httpCode == 200) {
        Serial.println(F("[PZEM] Data posted to DB"));
    } else {
        Serial.print(F("[PZEM] Post failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}
