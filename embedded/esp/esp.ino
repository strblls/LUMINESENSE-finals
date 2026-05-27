/*
  ============================================================
  LUMINESENSE — ESP32 NodeMCU-32S
  ============================================================
  Responsibilities:
    - WiFi + database polling (XAMPP)
    - PIR sensor reading (P13 = GPIO13)
    - MOSFET gate control (P26, P27, P25)
    - Serial2 bridge to/from Mega (P16=RX, P17=TX)
  ============================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>
#include <Preferences.h>

// ── Flash Storage ──────────────────────────────────────────
Preferences prefs;
char serverIP[40] = "192.168.1.5";  // default fallback

// ── URLs (built dynamically after IP is loaded) ────────────
String TOGGLE_URL;
String SCHEDULE_URL;
String PZEM_POST_URL;
String UPDATE_ROWS_URL;
String SESSION_URL;

void buildUrls() {
    String base    = "http://" + String(serverIP) + "/LUMINESENSE-finals/api";
    TOGGLE_URL     = base + "/esp32-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
    SCHEDULE_URL   = base + "/esp32-schedule.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
    PZEM_POST_URL  = base + "/post_pzem.php";
    UPDATE_ROWS_URL= base + "/esp32-update-rows.php";
    SESSION_URL    = base + "/post_session.php";
}

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN  26
#define ROW2_PIN  27
#define ROW3_PIN  25
#define PIR_PIN   13

// ── Serial2 to Mega ────────────────────────────────────────
#define MEGA_RX 16
#define MEGA_TX 17

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool          pirState        = false;
bool          lastPirState    = false;
bool          pirActive       = false;
bool          pirOverrideActive = false;
unsigned long pirTriggeredAt  = 0;
#define PIR_HOLD_MS 30000

// ── Timing ─────────────────────────────────────────────────
unsigned long lastDbPoll        = 0;
unsigned long lastScheduleFetch = 0;
#define DB_POLL_MS        2000
#define SCHEDULE_FETCH_MS 15000

// ── Forward declarations ───────────────────────────────────
void buildUrls();
void handlePIR(unsigned long now);
void handleMegaMessages();
void pollDatabase();
void fetchAndForwardSchedule();
void forwardPzemToDb(String jsonStr);
void postSessionToDb(String jsonStr);
void updateRowsInDb(bool r1, bool r2, bool r3);
void setRow(int row, bool state);
void setAllRows(bool state);

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

    // ── Load saved server IP from flash ────────────────────
    prefs.begin("luminesense", false);
    String savedIP = prefs.getString("server_ip", "192.168.1.5");
    savedIP.toCharArray(serverIP, 40);
    prefs.end();

    // ── WiFiManager setup ──────────────────────────────────
    WiFiManager wm;

    // Custom field for server IP shown in the portal
    WiFiManagerParameter ipField("server_ip", "Server IP (your PC's IP)", serverIP, 40);
    wm.addParameter(&ipField);

    // Save server IP to flash when user hits Save
    wm.setSaveParamsCallback([&]() {
        prefs.begin("luminesense", false);
        prefs.putString("server_ip", ipField.getValue());
        prefs.end();
        strncpy(serverIP, ipField.getValue(), 40);
        Serial.print(F("[Config] Server IP saved: "));
        Serial.println(serverIP);
    });

    wm.setConfigPortalTimeout(180);  // 3 min timeout, then continue offline
    wm.setTitle("LumineSense Setup");

    bool connected = wm.autoConnect("LUMINESENSE-Setup", "luminesense");

    if (!connected) {
        Serial.println(F("[WiFi] Config timeout — running offline"));
    } else {
        Serial.print(F("[WiFi] Connected! IP: "));
        Serial.println(WiFi.localIP());
    }

    // ── Build all URLs from the loaded/saved server IP ─────
    buildUrls();

    Serial.println(F("=== ESP32 Ready ==="));
}

// ============================================================
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

// ============================================================
// PIR HANDLER
// ============================================================
void handlePIR(unsigned long now) {
    pirState = digitalRead(PIR_PIN);

    if (pirState == HIGH && !pirOverrideActive) {
        Serial.println(F("[PIR] Motion detected!"));
        pirTriggeredAt  = now;
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
    if (Serial2.available()) {
        String msg = Serial2.readStringUntil('\n');
        msg.trim();
        msg.toUpperCase();

        Serial.print(F("[MEGA] ")); Serial.println(msg);

        if (msg == "FETCH:SCHEDULE") {
            fetchAndForwardSchedule();
            return;
        }

        // JSON from Mega — route to correct endpoint
        if (msg.startsWith("{")) {
            if (msg.indexOf("\"TYPE\":\"SESSION\"") >= 0) {
                postSessionToDb(msg);
            } else {
                forwardPzemToDb(msg);
            }
            return;
        }

        if (msg == "PIR:RELEASE") {
            pirActive = false;
            Serial.println(F("[PIR] Released — manual mode restored"));
            return;
        }

        // Row commands — apply and sync to DB
        if      (msg == "ACK:ROW1:ON")  { setRow(1, true);  updateRowsInDb(true,      row2State, row3State); }
        else if (msg == "ACK:ROW1:OFF") { setRow(1, false); updateRowsInDb(false,     row2State, row3State); }
        else if (msg == "ACK:ROW2:ON")  { setRow(2, true);  updateRowsInDb(row1State, true,      row3State); }
        else if (msg == "ACK:ROW2:OFF") { setRow(2, false); updateRowsInDb(row1State, false,     row3State); }
        else if (msg == "ACK:ROW3:ON")  { setRow(3, true);  updateRowsInDb(row1State, row2State, true);      }
        else if (msg == "ACK:ROW3:OFF") { setRow(3, false); updateRowsInDb(row1State, row2State, false);     }
        else if (msg == "ACK:ALL:ON")   { setAllRows(true); }
        else if (msg == "ACK:ALL:OFF")  { setAllRows(false); }
    }
}

// ============================================================
// SET ROW — controls MOSFET gates
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
// UPDATE ROW STATES IN DATABASE
// ============================================================
void updateRowsInDb(bool r1, bool r2, bool r3) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(UPDATE_ROWS_URL.c_str());
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String body = "token=LS_ESP32_TOKEN_2025&classroom_id=3";
    body += "&row1=" + String(r1 ? "on" : "off");
    body += "&row2=" + String(r2 ? "on" : "off");
    body += "&row3=" + String(r3 ? "on" : "off");

    int httpCode = http.POST(body);
    if (httpCode == 200) {
        Serial.println(F("[ROWS] DB updated"));
    } else {
        Serial.print(F("[ROWS] Update failed, code: ")); Serial.println(httpCode);
    }
    http.end();
}

// ============================================================
// POLL DATABASE FOR WEB TOGGLES
// ============================================================
void pollDatabase() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(TOGGLE_URL.c_str());
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

        if (newR1 != row1State) Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF");
        if (newR2 != row2State) Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF");
        if (newR3 != row3State) Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF");
    } else {
        Serial.print(F("[DB] Poll failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}

// ============================================================
// FETCH SCHEDULE AND FORWARD TO MEGA
// ============================================================
void fetchAndForwardSchedule() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(SCHEDULE_URL.c_str());
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        payload.trim();
        Serial2.println("SCHEDULE:" + payload);
        Serial.print(F("[SCHED] Forwarded to Mega: ")); Serial.println(payload);
    } else {
        Serial.print(F("[SCHED] Fetch failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}

// ============================================================
// FORWARD PZEM DATA TO DATABASE
// ============================================================
void forwardPzemToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    http.begin(PZEM_POST_URL.c_str());
    http.addHeader("Content-Type", "application/json");

    int httpCode = http.POST(jsonStr);
    if (httpCode == 200) {
        Serial.println(F("[PZEM] Posted to DB"));
    } else {
        Serial.print(F("[PZEM] Post failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}

// ============================================================
// POST SESSION SUMMARY TO DATABASE
// ============================================================
void postSessionToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println(F("[SESSION] No WiFi — skipping post"));
        return;
    }

    HTTPClient http;
    http.begin(SESSION_URL.c_str());
    http.addHeader("Content-Type", "application/json");

    int httpCode = http.POST(jsonStr);
    if (httpCode == 200) {
        Serial.println(F("[SESSION] Posted to DB"));
    } else {
        Serial.print(F("[SESSION] Post failed, code: ")); Serial.println(httpCode);
    }

    http.end();
}