#include <Arduino.h>
#line 1 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
/*
  ============================================================
  LUMINESENSE — Arduino Mega 2560 Master Controller
  ============================================================
  Components:
    - PZEM-004T V3.0   : AC Power Metering (Serial1, pins 18/19)
    - DS3231 RTC       : Real-Time Clock (I2C, pins 20/21)
    - Micro SD Reader  : CSV Data Logging (SPI, pins 50-53)
    - PIR Sensor       : Motion Detection (Pin 7)
    - ESP32            : WiFi Bridge (Serial2, pins 16/17)
    - MOSFETs          : LED Strip Row Control (Pins 2/3/4)
  ============================================================
*/

// ── Libraries ──────────────────────────────────────────────
#include <PZEM004Tv30.h>
#include <RTClib.h>
#include <SD.h>
#include <SPI.h>
#include <Wire.h>
#include <ArduinoJson.h>

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN       2      // MOSFET Gate Row 1
#define ROW2_PIN       3      // MOSFET Gate Row 2
#define ROW3_PIN       4      // MOSFET Gate Row 3
#define PIR_PIN        7      // PIR Sensor Output
#define SD_CS_PIN      53     // SD Card Chip Select

// ── Hardware Serial Assignments ────────────────────────────
// Serial  (pins 0/1)   : USB debug + Serial Monitor
// Serial1 (pins 18/19) : PZEM-004T
// Serial2 (pins 16/17) : ESP32 WiFi Bridge

// ── Object Initialization ──────────────────────────────────
PZEM004Tv30 pzem(&Serial1);   // PZEM on Hardware Serial1
RTC_DS3231  rtc;               // DS3231 on I2C (pins 20/21)

// ── Row State Tracking ─────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── Gate Control Logic ─────────────────────────────────────
// gateMode: "manual" = HTML controls, "auto" = PIR controls
String gateMode       = "manual";
bool   pirOverride    = false;   // true when PIR forces lights ON

// ── PIR State ──────────────────────────────────────────────
bool     pirState          = false;
bool     lastPirState      = false;
unsigned long pirTriggeredAt = 0;
#define  PIR_HOLD_MS       30000   // hold lights ON 30s after last motion

// ── PZEM Metrics Accumulator (for session logging) ─────────
float    sumVoltage    = 0;
float    sumCurrent    = 0;
float    sumPower      = 0;
float    totalEnergy   = 0;
float    sessionStartEnergy = 0;
int      pzemReadCount = 0;

// ── Session / Schedule State ───────────────────────────────
bool     sessionActive      = false;
DateTime sessionStartTime;
String   sessionDate        = "";
String   sessionStartStr    = "";

// ── Timing ─────────────────────────────────────────────────
unsigned long lastPzemRead     = 0;
unsigned long lastScheduleCheck = 0;
unsigned long lastJsonStream   = 0;
#define PZEM_INTERVAL_MS       2000    // read PZEM every 2s
#define SCHEDULE_CHECK_MS      10000   // check schedule every 10s
#define JSON_STREAM_MS         3000    // stream JSON every 3s

// ── SD Card ────────────────────────────────────────────────
bool     sdAvailable   = false;
#define  LOG_FILENAME   "power_log.csv"

// ── Schedule (loaded from XAMPP or hardcoded fallback) ─────
// Each entry: { startHour, startMin, endHour, endMin }
struct TimeSlot {
    uint8_t startH, startM, endH, endM;
};

// Hardcoded fallback schedule — replace with dynamic fetch
// from XAMPP if ESP32 is available
#define MAX_SLOTS 10
TimeSlot schedule[MAX_SLOTS];
int      scheduleCount = 0;
bool     scheduleLoaded = false;

// ============================================================
// SETUP
// ============================================================
#line 97 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void setup();
#line 161 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void loop();
#line 192 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void handleEsp32Commands();
#line 221 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void handlePIR(unsigned long now);
#line 262 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void cancelPirOverride();
#line 271 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void setRow(int row, bool state, String source);
#line 300 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void setAllRows(bool state, String source);
#line 310 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void readPZEM();
#line 348 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void streamPzemJson();
#line 377 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void syncStateToFrontend();
#line 392 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void sendStatusJson();
#line 410 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
bool isWithinSchedule(DateTime now);
#line 436 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void checkSchedule();
#line 455 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void startSession(DateTime startTime);
#line 478 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void endSession(DateTime endTime);
#line 538 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void requestScheduleFromServer();
#line 545 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void parseSchedulePayload(String payload);
#line 97 "C:\\xampp\\htdocs\\LUMINESENSE-finals\\embedded\\main\\main.ino"
void setup() {
    // USB Serial Monitor
    Serial.begin(9600);

    // PZEM on Serial1
    Serial1.begin(9600);

    // ESP32 on Serial2
    Serial2.begin(4800);

    // I2C for RTC
    Wire.begin();

    Serial.println(F("=== LUMINESENSE Booting... ==="));

    // ── Initialize RTC ──
    if (!rtc.begin()) {
        Serial.println(F("[RTC] FAILED — Check wiring!"));
    } else {
        Serial.println(F("[RTC] OK"));
        if (rtc.lostPower()) {
            Serial.println(F("[RTC] Lost power — setting compile time"));
            rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
        }
    }

    // ── Initialize SD Card ──
    if (!SD.begin(SD_CS_PIN)) {
        Serial.println(F("[SD] FAILED or no card — logging disabled"));
        sdAvailable = false;
    } else {
        Serial.println(F("[SD] OK"));
        sdAvailable = true;
        // Write CSV header if file doesn't exist
        if (!SD.exists(LOG_FILENAME)) {
            File f = SD.open(LOG_FILENAME, FILE_WRITE);
            if (f) {
                f.println(F("Date,Time,Session_Duration_min,Avg_Voltage_V,Avg_Current_A,Total_Energy_Wh"));
                f.close();
                Serial.println(F("[SD] CSV header written"));
            }
        }
    }

    // ── MOSFET Pins ──
    pinMode(ROW1_PIN, OUTPUT);
    pinMode(ROW2_PIN, OUTPUT);
    pinMode(ROW3_PIN, OUTPUT);
    digitalWrite(ROW1_PIN, LOW);
    digitalWrite(ROW2_PIN, LOW);
    digitalWrite(ROW3_PIN, LOW);

    // ── PIR Pin ──
    pinMode(PIR_PIN, INPUT);

    // ── Request schedule from XAMPP via ESP32 ──
    requestScheduleFromServer();

    Serial.println(F("=== LUMINESENSE Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    // 1. Handle incoming commands from ESP32 (web toggles)
    handleEsp32Commands();

    // 2. Read PIR sensor and apply gate logic
    handlePIR(now);

    // 3. Read PZEM metrics
    if (now - lastPzemRead >= PZEM_INTERVAL_MS) {
        lastPzemRead = now;
        readPZEM();
    }

    // 4. Stream PZEM JSON to ESP32 (for frontend gauges)
    if (now - lastJsonStream >= JSON_STREAM_MS) {
        lastJsonStream = now;
        streamPzemJson();
    }

    // 5. Periodically re-check schedule
    if (now - lastScheduleCheck >= SCHEDULE_CHECK_MS) {
        lastScheduleCheck = now;
        checkSchedule();
    }
}

// ============================================================
// HANDLE COMMANDS FROM ESP32 (Web HTML Toggles)
// ============================================================
void handleEsp32Commands() {
    if (Serial2.available()) {
        String cmd = Serial2.readStringUntil('\n');
        cmd.trim();
        cmd.toUpperCase();

        Serial.print(F("[ESP32 CMD] ")); Serial.println(cmd);

        // If PIR override is active, still accept manual OFF
        // commands as a full manual override to cancel PIR mode
        if (cmd == "ROW1:ON")  { setRow(1, true,  "manual"); }
        else if (cmd == "ROW1:OFF") { setRow(1, false, "manual"); cancelPirOverride(); }
        else if (cmd == "ROW2:ON")  { setRow(2, true,  "manual"); }
        else if (cmd == "ROW2:OFF") { setRow(2, false, "manual"); cancelPirOverride(); }
        else if (cmd == "ROW3:ON")  { setRow(3, true,  "manual"); }
        else if (cmd == "ROW3:OFF") { setRow(3, false, "manual"); cancelPirOverride(); }
        else if (cmd == "ALL:ON")   { setAllRows(true,  "manual"); }
        else if (cmd == "ALL:OFF")  { setAllRows(false, "manual"); cancelPirOverride(); }
        else if (cmd == "STATUS")   { sendStatusJson(); }
        else if (cmd.startsWith("SCHEDULE:")) {
            // Schedule data from XAMPP via ESP32
            parseSchedulePayload(cmd.substring(9));
        }
    }
}

// ============================================================
// PIR GATE LOGIC
// ============================================================
void handlePIR(unsigned long now) {
    pirState = digitalRead(PIR_PIN);

    // Motion detected — force all lights ON
    if (pirState == HIGH && lastPirState == LOW) {
        Serial.println(F("[PIR] Motion detected — forcing lights ON"));
        pirTriggeredAt = now;
        pirOverride    = true;
        gateMode       = "auto";

        // Check RTC and cross-examine with schedule
        DateTime currentTime = rtc.now();
        bool     inSchedule  = isWithinSchedule(currentTime);

        if (inSchedule) {
            Serial.println(F("[PIR] Motion within scheduled class — lights ON"));
            setAllRows(true, "auto");
            // Start session if not already active
            if (!sessionActive) startSession(currentTime);
        } else {
            Serial.println(F("[PIR] Motion outside schedule — lights ON (unscheduled)"));
            setAllRows(true, "auto");
        }

        // Sync state back to ESP32/frontend
        syncStateToFrontend();
    }

    // No motion — check if hold time expired
    if (pirOverride && pirState == LOW) {
        if (now - pirTriggeredAt >= PIR_HOLD_MS) {
            Serial.println(F("[PIR] Hold time expired — releasing PIR override"));
            cancelPirOverride();
            setAllRows(false, "auto");
            syncStateToFrontend();
        }
    }

    lastPirState = pirState;
}

void cancelPirOverride() {
    pirOverride = false;
    gateMode    = "manual";
    Serial.println(F("[PIR] Override cancelled — manual mode restored"));
}

// ============================================================
// ROW CONTROL
// ============================================================
void setRow(int row, bool state, String source) {
    // Gate: if PIR override active, block manual OFF
    if (pirOverride && source == "manual" && !state) {
        Serial.println(F("[GATE] PIR active — manual OFF blocked. Send ALL:OFF to override."));
        return;
    }

    switch (row) {
        case 1:
            row1State = state;
            digitalWrite(ROW1_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW1] ")); Serial.println(state ? "ON" : "OFF");
            Serial2.println(state ? "ACK:ROW1:ON" : "ACK:ROW1:OFF");
            break;
        case 2:
            row2State = state;
            digitalWrite(ROW2_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW2] ")); Serial.println(state ? "ON" : "OFF");
            Serial2.println(state ? "ACK:ROW2:ON" : "ACK:ROW2:OFF");
            break;
        case 3:
            row3State = state;
            digitalWrite(ROW3_PIN, state ? HIGH : LOW);
            Serial.print(F("[ROW3] ")); Serial.println(state ? "ON" : "OFF");
            Serial2.println(state ? "ACK:ROW3:ON" : "ACK:ROW3:OFF");
            break;
    }
}

void setAllRows(bool state, String source) {
    setRow(1, state, source);
    setRow(2, state, source);
    setRow(3, state, source);
    Serial2.println(state ? "ACK:ALL:ON" : "ACK:ALL:OFF");
}

// ============================================================
// PZEM READING
// ============================================================
void readPZEM() {
    float voltage = pzem.voltage();
    float current = pzem.current();
    float power   = pzem.power();
    float energy  = pzem.energy();
    float freq    = pzem.frequency();
    float pf      = pzem.pf();

    // Validate readings (NaN check)
    if (isnan(voltage) || isnan(current) || isnan(power)) {
        Serial.println(F("[PZEM] Read error — check wiring"));
        return;
    }

    // Accumulate for session averaging
    if (sessionActive) {
        sumVoltage += voltage;
        sumCurrent += current;
        sumPower   += power;
        pzemReadCount++;

        // Track total energy consumed during session
        if (sessionStartEnergy == 0) sessionStartEnergy = energy;
        totalEnergy = energy - sessionStartEnergy;
    }

    // Debug print
    Serial.print(F("[PZEM] V:"));    Serial.print(voltage);
    Serial.print(F(" A:"));          Serial.print(current);
    Serial.print(F(" W:"));          Serial.print(power);
    Serial.print(F(" Wh:"));         Serial.print(energy);
    Serial.print(F(" Hz:"));         Serial.print(freq);
    Serial.print(F(" PF:"));         Serial.println(pf);
}

// ============================================================
// STREAM PZEM JSON TO ESP32 (Frontend Gauges)
// ============================================================
void streamPzemJson() {
    float voltage = pzem.voltage();
    float current = pzem.current();
    float power   = pzem.power();
    float energy  = pzem.energy();

    if (isnan(voltage)) return;   // skip if no reading

    StaticJsonDocument<200> doc;
    doc["type"]    = "pzem";
    doc["voltage"] = voltage;
    doc["current"] = current;
    doc["power"]   = power;
    doc["energy"]  = energy;
    doc["row1"]    = row1State;
    doc["row2"]    = row2State;
    doc["row3"]    = row3State;
    doc["pir"]     = pirState;
    doc["gate"]    = gateMode;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
    Serial.print(F("[JSON] ")); Serial.println(jsonStr);
}

// ============================================================
// SYNC STATE BACK TO FRONTEND (after PIR triggers)
// ============================================================
void syncStateToFrontend() {
    StaticJsonDocument<128> doc;
    doc["type"]  = "sync";
    doc["row1"]  = row1State;
    doc["row2"]  = row2State;
    doc["row3"]  = row3State;
    doc["gate"]  = gateMode;
    doc["pir"]   = pirState;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
    Serial.print(F("[SYNC] ")); Serial.println(jsonStr);
}

void sendStatusJson() {
    StaticJsonDocument<200> doc;
    doc["type"]    = "status";
    doc["row1"]    = row1State;
    doc["row2"]    = row2State;
    doc["row3"]    = row3State;
    doc["gate"]    = gateMode;
    doc["pir"]     = pirState;
    doc["session"] = sessionActive;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

// ============================================================
// SCHEDULE MANAGEMENT
// ============================================================
bool isWithinSchedule(DateTime now) {
    if (!scheduleLoaded || scheduleCount == 0) {
        Serial.println(F("[SCHED] No schedule loaded"));
        return false;
    }

    int nowMins = now.hour() * 60 + now.minute();

    for (int i = 0; i < scheduleCount; i++) {
        int startMins = schedule[i].startH * 60 + schedule[i].startM;
        int endMins   = schedule[i].endH   * 60 + schedule[i].endM;

        if (nowMins >= startMins && nowMins < endMins) {
            Serial.print(F("[SCHED] In slot "));
            Serial.print(i);
            Serial.print(F(": "));
            Serial.print(schedule[i].startH); Serial.print(":");
            Serial.print(schedule[i].startM); Serial.print(F(" - "));
            Serial.print(schedule[i].endH);   Serial.print(":");
            Serial.println(schedule[i].endM);
            return true;
        }
    }
    return false;
}

void checkSchedule() {
    DateTime now = rtc.now();

    // Check if current session should end
    if (sessionActive) {
        bool stillInSchedule = isWithinSchedule(now);
        if (!stillInSchedule) {
            Serial.println(F("[SCHED] Class ended — logging session"));
            endSession(now);
        }
    }

    // Re-request schedule from server periodically
    requestScheduleFromServer();
}

// ============================================================
// SESSION LOGGING
// ============================================================
void startSession(DateTime startTime) {
    sessionActive      = true;
    sessionStartTime   = startTime;
    sessionStartEnergy = 0;
    sumVoltage         = 0;
    sumCurrent         = 0;
    sumPower           = 0;
    totalEnergy        = 0;
    pzemReadCount      = 0;

    // Build date/time strings
    char dateBuf[12];
    char timeBuf[10];
    sprintf(dateBuf, "%04d-%02d-%02d", startTime.year(), startTime.month(), startTime.day());
    sprintf(timeBuf, "%02d:%02d:%02d", startTime.hour(), startTime.minute(), startTime.second());

    sessionDate     = String(dateBuf);
    sessionStartStr = String(timeBuf);

    Serial.print(F("[SESSION] Started at ")); Serial.print(sessionDate);
    Serial.print(F(" ")); Serial.println(sessionStartStr);
}

void endSession(DateTime endTime) {
    if (!sessionActive || pzemReadCount == 0) {
        sessionActive = false;
        return;
    }

    // Calculate averages
    float avgVoltage = sumVoltage / pzemReadCount;
    float avgCurrent = sumCurrent / pzemReadCount;

    // Calculate session duration in minutes
    TimeSpan duration = endTime - sessionStartTime;
    int durationMins  = duration.totalseconds() / 60;

    // Build end time string
    char endTimeBuf[10];
    sprintf(endTimeBuf, "%02d:%02d:%02d", endTime.hour(), endTime.minute(), endTime.second());

    Serial.println(F("[SESSION] Ended — compiling log entry"));
    Serial.print(F("  Date: "));         Serial.println(sessionDate);
    Serial.print(F("  Start: "));        Serial.println(sessionStartStr);
    Serial.print(F("  End: "));          Serial.println(endTimeBuf);
    Serial.print(F("  Duration: "));     Serial.print(durationMins); Serial.println(F(" min"));
    Serial.print(F("  Avg Voltage: "));  Serial.println(avgVoltage);
    Serial.print(F("  Avg Current: "));  Serial.println(avgCurrent);
    Serial.print(F("  Total Energy: ")); Serial.println(totalEnergy);

    // Write to SD card
    if (sdAvailable) {
        File logFile = SD.open(LOG_FILENAME, FILE_WRITE);
        if (logFile) {
            // Format: Date,Time,Duration_min,AvgVoltage,AvgCurrent,TotalEnergy_Wh
            logFile.print(sessionDate);      logFile.print(",");
            logFile.print(sessionStartStr);  logFile.print(",");
            logFile.print(durationMins);     logFile.print(",");
            logFile.print(avgVoltage, 2);    logFile.print(",");
            logFile.print(avgCurrent, 3);    logFile.print(",");
            logFile.println(totalEnergy, 4);
            logFile.close();
            Serial.println(F("[SD] Session logged to CSV"));
        } else {
            Serial.println(F("[SD] Failed to open log file!"));
        }
    } else {
        Serial.println(F("[SD] Card unavailable — log not saved"));
    }

    // Reset session state
    sessionActive      = false;
    sessionStartEnergy = 0;
    sumVoltage         = 0;
    sumCurrent         = 0;
    pzemReadCount      = 0;
    totalEnergy        = 0;
}

// ============================================================
// SCHEDULE FETCH FROM XAMPP VIA ESP32
// ESP32 makes HTTP GET to XAMPP and forwards response to Mega
// ============================================================
void requestScheduleFromServer() {
    // Tell ESP32 to fetch the schedule from XAMPP
    // ESP32 should respond with: SCHEDULE:HH:MM-HH:MM,HH:MM-HH:MM,...
    Serial2.println("FETCH:SCHEDULE");
    Serial.println(F("[SCHED] Requested schedule from server via ESP32"));
}

void parseSchedulePayload(String payload) {
    // Expected format: HH:MM-HH:MM,HH:MM-HH:MM
    // Example: 08:00-10:00,13:00-15:30
    scheduleCount = 0;
    int idx = 0;

    while (payload.length() > 0 && idx < MAX_SLOTS) {
        int commaPos = payload.indexOf(',');
        String slot  = (commaPos == -1) ? payload : payload.substring(0, commaPos);
        payload      = (commaPos == -1) ? "" : payload.substring(commaPos + 1);

        // Parse HH:MM-HH:MM
        int dashPos = slot.indexOf('-', 3);   // find dash after start time
        if (dashPos == -1) continue;

        String startStr = slot.substring(0, dashPos);
        String endStr   = slot.substring(dashPos + 1);

        int colonS = startStr.indexOf(':');
        int colonE = endStr.indexOf(':');
        if (colonS == -1 || colonE == -1) continue;

        schedule[idx].startH = startStr.substring(0, colonS).toInt();
        schedule[idx].startM = startStr.substring(colonS + 1).toInt();
        schedule[idx].endH   = endStr.substring(0, colonE).toInt();
        schedule[idx].endM   = endStr.substring(colonE + 1).toInt();

        Serial.print(F("[SCHED] Slot ")); Serial.print(idx);
        Serial.print(F(": "));
        Serial.print(schedule[idx].startH); Serial.print(":");
        Serial.print(schedule[idx].startM); Serial.print(F(" - "));
        Serial.print(schedule[idx].endH);   Serial.print(":");
        Serial.println(schedule[idx].endM);

        idx++;
        scheduleCount = idx;
    }

    scheduleLoaded = (scheduleCount > 0);
    Serial.print(F("[SCHED] Loaded ")); Serial.print(scheduleCount); Serial.println(F(" slot(s)"));
}
