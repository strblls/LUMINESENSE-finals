/*
  ============================================================
  LUMINESENSE — ESP32 NodeMCU-32S
  ============================================================
  Responsibilities:
    - WiFi + database polling (XAMPP)
    - PIR sensor reading (GPIO13)
    - MOSFET gate control (GPIO26, GPIO27, GPIO25)
    - Serial2 bridge to/from Mega (GPIO4=RX, GPIO2=TX)
  ============================================================
*/

#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>
#include <time.h>

// ── Server base ────────────────────────────────────────────
// Change SERVER_BASE to test against a local XAMPP server, e.g.
//   "http://192.168.1.10/LUMINESENSE-finals"
#define SERVER_BASE "https://luminesense-bet.site"

// ── Server URLs ────────────────────────────────────────────
const char* TOGGLE_URL       = SERVER_BASE "/api/esp32-light-status.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* SCHEDULE_URL     = SERVER_BASE "/api/esp32-schedule.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* PZEM_POST_URL    = SERVER_BASE "/api/pzem_push.php";
const char* UPDATE_ROWS_URL  = SERVER_BASE "/api/esp32-update-rows.php";
const char* SCHEDULE_FLAG_URL= SERVER_BASE "/api/esp32-schedule-flag.php?token=LS_ESP32_TOKEN_2025&classroom_id=3";
const char* CONFIG_URL      = SERVER_BASE "/api/esp32-config.php?token=LS_ESP32_TOKEN_2025";
const char* PIR_LOG_URL     = SERVER_BASE "/api/pir-log.php";
const char* SESSION_URL     = SERVER_BASE "/api/post_session.php";
const char* ARCHIVE_SYNC_URL = SERVER_BASE "/api/archive-sync.php";

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN 25
#define ROW2_PIN 26
#define ROW3_PIN 27
#define PIR_PIN  13

// ── Serial2 to Mega ────────────────────────────────────────
// GPIO16/17 were damaged by 5V from Mega TX — moved to GPIO4/2
#define MEGA_RX 4   // ESP32 RX (was 16)
#define MEGA_TX 2   // ESP32 TX (was 17)

// ── HTTP busy flag ─────────────────────────────────────────
bool httpBusy = false;

// ── Pending work flags ─────────────────────────────────────
// Instead of calling HTTP directly from handleMegaMessages,
// set a flag and let the loop handle it when HTTP is free
String pendingPzem          = "";
String esp32Buffer = "";
bool   pendingScheduleFetch = false;
int    pendingPirLog        = -1;  // -1 = none, 0/1 = state to log
String pendingReconcile     = "";

// ── Archive sync state ─────────────────────────────────────
// The Mega streams per-minute CSVs via ARCHIVE:READ. We buffer rows
// into JSON batches of ~500 and POST them to archive-sync.php.
String archiveListQueue     = "";   // dates (comma-separated) left to fetch
bool   archiveSyncInProgress = false;
bool   archiveReadRequested = false; // ARCHIVE:READ sent, awaiting DATA_BEGIN
bool   archiveDataActive    = false; // currently receiving a day's rows
String archivePendingDate   = "";
String archiveRowsJson      = "";
String archiveBatchPending  = "";   // fully-built JSON batch waiting to POST
int    archiveBatchCount    = 0;
int    archiveRowTotal      = 0;
String lastArchiveSyncDay   = "";
#define ARCHIVE_BATCH_ROWS 500

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool pirState          = false;
bool lastPirState      = false;
bool pirOverrideActive = false;
unsigned long pirInactiveSince = 0;

// ── Timing ─────────────────────────────────────────────────
unsigned long lastDbPoll        = 0;
unsigned long lastScheduleFetch = 0;
unsigned long lastFlagPoll = 0;
unsigned long lastConfigFetch = 0;
#define FLAG_POLL_MS 5000
#define DB_POLL_MS        200
#define SCHEDULE_FETCH_MS 30000
#define CONFIG_FETCH_MS   300000
#define PIR_INACTIVITY_MS 300000ul // 5 minutes

// ============================================================
// SETUP
// ============================================================
void setup() {
    Serial.begin(115200);
    delay(1000);

    // Serial2 to Mega
    Serial2.begin(9600, SERIAL_8N1, MEGA_RX, MEGA_TX);
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
    pinMode(PIR_PIN, INPUT);

    // WiFi Manager — all inside setup()!
    WiFiManager wm;
    // wm.resetSettings(); // uncomment to forget saved WiFi
    wm.setConfigPortalTimeout(180); 
    wm.setConnectTimeout(30);

    Serial.println(F("[WiFi] Starting WiFiManager..."));

    bool connected = wm.autoConnect("LumineSense-Setup", "luminesense123");

    if (connected) {
        Serial.println();
        Serial.print(F("[WiFi] Connected! IP: "));
        Serial.println(WiFi.localIP());
        WiFi.setAutoReconnect(true);
        delay(500);
        fetchAndForwardSchedule();
        fetchAndForwardConfig();

        // NTP time sync — send accurate time to Mega
        configTime(8 * 3600, 0, "pool.ntp.org", "time.nist.gov");
        struct tm timeinfo;
        int ntpRetries = 0;
        while (!getLocalTime(&timeinfo) && ntpRetries < 20) {
            delay(500);
            ntpRetries++;
        }
        if (ntpRetries < 20) {
            syncTimeToMega();
        } else {
            Serial.println(F("[NTP] Time sync failed — will retry in loop"));
        }
    } else {
        Serial.println(F("[WiFi] Config portal timed out — running offline"));
    }

    Serial.println(F("=== ESP32 Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    // PIR inactivity timeout: no motion from any source for 5 min → OFF
    if (pirOverrideActive && pirInactiveSince > 0 &&
        now - pirInactiveSince >= PIR_INACTIVITY_MS) {
        Serial.println(F("[PIR] Inactivity timeout — logging stopped"));
        pirOverrideActive = false;
        pirInactiveSince = 0;
        pendingPirLog = 0;
    }

    // heartbeat print — once per 2s instead of every single loop
    static unsigned long lastHeartbeat = 0;
    if (now - lastHeartbeat >= 2000) {
        lastHeartbeat = now;
        Serial.print(F("[HEARTBEAT] busy="));
        Serial.print(httpBusy);
        Serial.print(F(" wifi="));
        if (WiFi.status() != WL_CONNECTED) {
            Serial.print(F("DISCONNECTED—reconnecting..."));
            WiFi.reconnect();
        } else {
            Serial.print(F("connected"));
        }
        Serial.print(F(" ip="));
        Serial.println(WiFi.localIP());
    }

    handlePIR(now);
    handleMegaMessages();
    driveArchiveSync();
    checkArchiveDayRollover();

    // Only one HTTP task runs per loop iteration — they take turns.
    // pollDatabase() is first priority for fastest light-control response.
    if (!httpBusy) {
        if (now - lastDbPoll >= DB_POLL_MS) {
            lastDbPoll = now;
            pollDatabase();
        } else if (archiveBatchPending != "") {
            String j = archiveBatchPending;
            archiveBatchPending = "";
            postArchive(j);
        } else if (pendingPzem != "") {
            forwardPzemToDb(pendingPzem);
            pendingPzem = "";
        } else if (pendingReconcile != "") {
            forwardReconcile(pendingReconcile);
            pendingReconcile = "";
        } else if (pendingPirLog != -1) {
            forwardPirLog(pendingPirLog);
            pendingPirLog = -1;
        } else if (now - lastFlagPoll >= FLAG_POLL_MS) {
            lastFlagPoll = now;
            checkScheduleFlag();
        } else if (now - lastScheduleFetch >= SCHEDULE_FETCH_MS || pendingScheduleFetch) {
            lastScheduleFetch    = now;
            pendingScheduleFetch = false;
            fetchAndForwardSchedule();
        } else if (now - lastConfigFetch >= CONFIG_FETCH_MS) {
            lastConfigFetch = now;
            fetchAndForwardConfig();
        }
    }
}

// ============================================================
// PIR HANDLER
// ============================================================
void handlePIR(unsigned long now) {
    static unsigned long lastPirChange = 0;
    bool reading = digitalRead(PIR_PIN);

    if (reading == pirState) return;

    if (now - lastPirChange < 2000) return;

    lastPirChange = now;
    pirState = reading;

    if (pirState == HIGH) {
        if (!pirOverrideActive) {
            Serial.println(F("[PIR] Motion detected!"));
            pirOverrideActive = true;
        }
        pendingPirLog = 1;
        pirInactiveSince = 0;
    }

    if (pirState == LOW && pirOverrideActive) {
        Serial.println(F("[PIR] Motion stopped — 5 min timeout started"));
        pirInactiveSince = now;
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

            Serial.print(F("[RAW MSG] ")); Serial.println(msg);

            if (msg.startsWith("{")) {
                pendingPzem = msg;
                // DON'T return — just continue the while loop
            } else if (msg.startsWith("RECONCILE:") || msg.startsWith("reconcile:")) {
                pendingReconcile = msg.substring(10);
                Serial.println(F("[MEGA] Reconcile pending"));
            } else if (msg.startsWith("ARCHIVE_LIST:")) {
                handleArchiveList(msg.substring(13));
            } else if (msg.startsWith("ARCHIVE_DATA_BEGIN:")) {
                archivePendingDate = msg.substring(19);
                archiveDataActive  = true;
                archiveReadRequested = false;
                archiveRowsJson    = "";
                archiveBatchCount  = 0;
                Serial.print(F("[ARCHIVE] Receiving "));
                Serial.println(archivePendingDate);
            } else if (msg.startsWith("ARCHIVE_DATA_END")) {
                flushArchiveBatch(true);
                archiveDataActive = false;
                Serial.print(F("[ARCHIVE] Day done. Total rows buffered: "));
                Serial.println(archiveRowTotal);
            } else if (archiveDataActive) {
                appendArchiveRow(msg);
            } else {
                msg.toUpperCase();
                Serial.print(F("[MEGA] ")); Serial.println(msg);

                if      (msg == "ACK:ROW1:ON")    { setRow(1, true);  }
                else if (msg == "ACK:ROW1:OFF")   { setRow(1, false); }
                else if (msg == "ACK:ROW2:ON")    { setRow(2, true);  }
                else if (msg == "ACK:ROW2:OFF")   { setRow(2, false); }
                else if (msg == "ACK:ROW3:ON")    { setRow(3, true);  }
                else if (msg == "ACK:ROW3:OFF")   { setRow(3, false); }
                else if (msg == "ACK:ALL:ON")     { setAllRows(true); }
                else if (msg == "ACK:ALL:OFF")    { setAllRows(false);}
                else if (msg == "FETCH:SCHEDULE") { pendingScheduleFetch = true; }
                else if (msg == "LOG_PIR:1") {
                    pirOverrideActive = true;
                    pirInactiveSince = 0;   // reset inactivity timer
                    pendingPirLog = 1;
                }
                else if (msg == "LOG_PIR:0") {
                    if (pirOverrideActive)
                        pirInactiveSince = millis(); // start 5-min timer — does NOT immediately kill schedule
                }
            }
        } else {
            esp32Buffer += c;
        }
    }
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
    http.setTimeout(1500);
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

        if (newR1 != row1State) { setRow(1, newR1); Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF"); }
        if (newR2 != row2State) { setRow(2, newR2); Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF"); }
        if (newR3 != row3State) { setRow(3, newR3); Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF"); }
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
            for (int i = 0; i < 3; i++) {
                Serial2.println("SCHEDULE:" + payload);
                delay(200);
            }
            Serial.println(F("[SCHED] Forwarded to Mega (3x)"));
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
// FETCH CONFIG AND FORWARD TO MEGA
// ============================================================
void fetchAndForwardConfig() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(CONFIG_URL);
    http.setTimeout(3000);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        payload.trim();
        Serial.print(F("[CONFIG] Payload: ")); Serial.println(payload);

        StaticJsonDocument<128> doc;
        DeserializationError err = deserializeJson(doc, payload);
        if (!err) {
            int timeoutMin = doc["pir_inactivity_timeout"] | 5;
            unsigned long timeoutMs = (unsigned long)timeoutMin * 60 * 1000;
            Serial2.println("CONFIG:PIR_TIMEOUT=" + String(timeoutMs));
            Serial.print(F("[CONFIG] Forwarded PIR_TIMEOUT="));
            Serial.println(timeoutMs);
        }
    } else {
        Serial.print(F("[CONFIG] Fetch failed, code: ")); Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// SYNC NTP TIME TO MEGA
// ============================================================
void syncTimeToMega() {
    struct tm timeinfo;
    if (!getLocalTime(&timeinfo)) {
        Serial.println(F("[NTP] getLocalTime() failed"));
        return;
    }

    char buf[32];
    snprintf(buf, sizeof(buf), "TIME:%d,%d,%d,%d,%d,%d",
             timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday,
             timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
    Serial2.println(buf);
    Serial.print(F("[NTP] Sent to Mega: ")); Serial.println(buf);
}

// ============================================================
// FORWARD PZEM JSON TO DATABASE
// ============================================================
void forwardPzemToDb(String jsonStr) {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    // Parse what Mega sent so we can add classroom_id
    StaticJsonDocument<256> doc;
    DeserializationError err = deserializeJson(doc, jsonStr);
    if (err) {
        Serial.println(F("[PZEM] JSON parse error — dropping"));
        httpBusy = false;
        return;
    }

    // Add classroom_id if Mega didn't include it
    if (!doc.containsKey("classroom_id")) {
        doc["classroom_id"] = 3;
    }

    String outJson;
    serializeJson(doc, outJson);

    HTTPClient http;
    http.begin(PZEM_POST_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", "luminesense-secret-token");

    int httpCode = http.POST(outJson);
    if (httpCode == 200) {
        Serial.println(F("[PZEM] Posted to DB OK"));
    } else {
        Serial.print(F("[PZEM] Post failed, code: "));
        Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}


// ============================================================
// FORWARD PIR LOG TO DATABASE
// ============================================================
void forwardPirLog(int state) {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    String json = "{\"classroom_id\":3,\"state\":" + String(state) + "}";

    HTTPClient http;
    http.begin(PIR_LOG_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", "luminesense-secret-token");

    int httpCode = http.POST(json);
    if (httpCode == 200) {
        Serial.println(F("[PIR_LOG] Logged to DB OK"));
    } else {
        Serial.print(F("[PIR_LOG] Post failed, code: "));
        Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}


// ============================================================
// FORWARD RECONCILE TO DATABASE
// ============================================================
void forwardReconcile(String data) {
    if (WiFi.status() != WL_CONNECTED) return;
    // data format: date,startTime,avgV,avgC,totalWh,pzemCount
    // Example: 2026-07-25,10:30:00,220.50,1.234,12.3456,42
    int c1 = data.indexOf(',');
    if (c1 == -1) return;
    int c2 = data.indexOf(',', c1 + 1);
    if (c2 == -1) return;
    int c3 = data.indexOf(',', c2 + 1);
    if (c3 == -1) return;
    int c4 = data.indexOf(',', c3 + 1);
    if (c4 == -1) return;
    int c5 = data.indexOf(',', c4 + 1);
    if (c5 == -1) return;

    String sDate    = data.substring(0, c1);
    String sTime    = data.substring(c1 + 1, c2);
    String sAvgV    = data.substring(c2 + 1, c3);
    String sAvgC    = data.substring(c3 + 1, c4);
    String sTotWh   = data.substring(c4 + 1, c5);
    String sCount   = data.substring(c5 + 1);

    String json = "{\"classroom_id\":3,\"session_date\":\"" + sDate +
                  "\",\"start_time\":\"" + sTime +
                  "\",\"duration_mins\":0" +
                  ",\"avg_voltage\":" + sAvgV +
                  ",\"avg_current\":" + sAvgC +
                  ",\"total_energy_wh\":" + sTotWh +
                  ",\"pzem_read_count\":" + sCount +
                  ",\"trigger_source\":\"reconcile\"}";

    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(SESSION_URL);
    http.setTimeout(3000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", "luminesense-secret-token");

    int httpCode = http.POST(json);
    if (httpCode == 200) {
        Serial.println(F("[RECONCILE] Orphaned session closed"));
    } else {
        Serial.print(F("[RECONCILE] Failed, code: "));
        Serial.println(httpCode);
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

void checkScheduleFlag() {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    HTTPClient http;
    http.begin(SCHEDULE_FLAG_URL);
    http.setTimeout(3000);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        StaticJsonDocument<64> doc;
        DeserializationError err = deserializeJson(doc, payload);
        if (!err && doc["dirty"] == true) {
            Serial.println(F("[FLAG] Schedule changed — fetching now!"));
            http.end();
            httpBusy = false;
            fetchAndForwardSchedule();
            return;
        }
        } else {
        Serial.print(F("[FLAG] Check failed, code: ")); Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}

// ============================================================
// PER-MINUTE ARCHIVE SYNC (SD → Server)
// ============================================================
String todayDateStr() {
    struct tm t;
    if (!getLocalTime(&t)) return "";
    char b[12];
    snprintf(b, sizeof(b), "%04d-%02d-%02d", t.tm_year + 1900, t.tm_mon + 1, t.tm_mday);
    return String(b);
}

void handleArchiveList(String list) {
    list.trim();
    archiveListQueue = list;
    if (list.length() > 0) {
        archiveSyncInProgress = true;
        Serial.print(F("[ARCHIVE] Dates to sync: "));
        Serial.println(list);
    } else {
        Serial.println(F("[ARCHIVE] No archived dates on SD"));
    }
}

void requestArchiveSync() {
    if (archiveSyncInProgress) return;
    Serial2.println("ARCHIVE:LIST");
    Serial.println(F("[ARCHIVE] Requested date list from Mega"));
}

void checkArchiveDayRollover() {
    String tDay = todayDateStr();
    if (tDay.length() != 10) return;
    if (lastArchiveSyncDay != tDay) {
        lastArchiveSyncDay = tDay;
        if (!archiveSyncInProgress) requestArchiveSync();
    }
}

void driveArchiveSync() {
    if (!archiveSyncInProgress) return;
    if (httpBusy) return;
    if (archiveDataActive || archiveReadRequested) return;

    if (archiveListQueue.length() == 0) {
        archiveSyncInProgress = false;
        Serial.print(F("[ARCHIVE] Sync complete. Total rows: "));
        Serial.println(archiveRowTotal);
        return;
    }

    String date = archiveListQueue;
    int comma = date.indexOf(',');
    if (comma != -1) date = archiveListQueue.substring(0, comma);
    archiveListQueue = archiveListQueue.substring(date.length());
    if (archiveListQueue.startsWith(",")) archiveListQueue = archiveListQueue.substring(1);
    archiveListQueue.trim();

    if (date == todayDateStr()) {
        Serial.println(F("[ARCHIVE] Skipping today (still recording)"));
        return; // loop pops the next date
    }

    Serial2.println("ARCHIVE:READ:" + date);
    archiveReadRequested = true;
    Serial.print(F("[ARCHIVE] Requesting "));
    Serial.println(date);
}

void appendArchiveRow(String row) {
    int c1 = row.indexOf(',');
    if (c1 == -1) return;
    int c2 = row.indexOf(',', c1 + 1);
    int c3 = row.indexOf(',', c2 + 1);
    int c4 = row.indexOf(',', c3 + 1);
    int c5 = row.indexOf(',', c4 + 1);
    if (c2 == -1 || c3 == -1 || c4 == -1 || c5 == -1) return;

    String minute = row.substring(0, c1);
    String v = row.substring(c1 + 1, c2);
    String a = row.substring(c2 + 1, c3);
    String w = row.substring(c3 + 1, c4);
    String e = row.substring(c4 + 1, c5);
    String cnt = row.substring(c5 + 1);
    cnt.trim();
    if (minute.length() < 4) return;

    if (archiveRowsJson.length() > 0) archiveRowsJson += ",";
    archiveRowsJson += "{\"minute\":\"" + minute + "\",\"avg_voltage\":" + v +
                       ",\"avg_current\":" + a + ",\"avg_power\":" + w +
                       ",\"energy_wh\":" + e + ",\"reading_count\":" + cnt + "}";
    archiveBatchCount++;
    archiveRowTotal++;

    if (archiveBatchCount >= ARCHIVE_BATCH_ROWS) flushArchiveBatch(false);
}

void flushArchiveBatch(bool force) {
    if (archiveRowsJson.length() == 0) {
        archiveBatchCount = 0;
        return;
    }
    if (!force && archiveBatchCount < ARCHIVE_BATCH_ROWS) return;

    archiveBatchPending = "{\"classroom_id\":3,\"archive_date\":\"" + archivePendingDate +
                          "\",\"rows\":[" + archiveRowsJson + "]}";
    archiveRowsJson = "";
    archiveBatchCount = 0;
}

void postArchive(String json) {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println(F("[ARCHIVE] No WiFi — dropping batch"));
        return;
    }
    httpBusy = true;

    HTTPClient http;
    http.begin(ARCHIVE_SYNC_URL);
    http.setTimeout(10000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", "luminesense-secret-token");

    int httpCode = http.POST(json);
    if (httpCode == 200) {
        Serial.println(F("[ARCHIVE] Batch posted OK"));
    } else {
        Serial.print(F("[ARCHIVE] Post failed, code: "));
        Serial.println(httpCode);
    }

    http.end();
    httpBusy = false;
}