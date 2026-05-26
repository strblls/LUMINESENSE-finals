/*
  ============================================================
  LUMINESENSE — Arduino Mega 2560 Master Controller
  ============================================================
  Components:
    - PZEM-004T V3.0   : AC Power Metering (Serial1, pins 18/19)
    - DS3231 RTC       : Real-Time Clock (I2C, pins 20/21)
    - Micro SD Reader  : CSV Data Logging (SPI, pin 53)
    - ESP32            : WiFi Bridge + LED Control (Serial2, pins 16/17)

  SYSTEM STATES:
    STATE_OUTSIDE   : Outside schedule — PIR ignored, faculty CAN toggle
    STATE_SCHEDULED : Within schedule  — PIR turns lights ON, faculty CAN toggle
    STATE_COOLDOWN  : After schedule   — 30s countdown, PIR resets ONCE, faculty CANNOT toggle
    STATE_LOCKED    : After cooldown   — lights OFF, locked until next schedule
  ============================================================
*/

#include <PZEM004Tv30.h>
#include <RTClib.h>
#include <SD.h>
#include <SPI.h>
#include <Wire.h>
#include <ArduinoJson.h>

// ── Pin Definitions ────────────────────────────────────────
#define SD_CS_PIN   53

// ── Object Initialization ──────────────────────────────────
PZEM004Tv30 pzem(Serial1);
RTC_DS3231  rtc;

// ── System State Machine ───────────────────────────────────
enum SystemState {
    STATE_OUTSIDE,    // outside schedule
    STATE_SCHEDULED,  // within schedule
    STATE_COOLDOWN,   // after schedule ends — 30s countdown
    STATE_LOCKED      // lights off, locked until next schedule
};
SystemState sysState = STATE_OUTSIDE;

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool pirState      = false;
bool pirResetUsed  = false;   // cooldown PIR reset used?

// ── Cooldown Timer ─────────────────────────────────────────
unsigned long cooldownStart = 0;
#define COOLDOWN_MS 30000   // 30 seconds

// ── PZEM Metrics ───────────────────────────────────────────
float    sumVoltage         = 0;
float    sumCurrent         = 0;
float    sumPower           = 0;
float    totalEnergy        = 0;
float    sessionStartEnergy = 0;
int      pzemReadCount      = 0;

// ── Session State ──────────────────────────────────────────
bool     sessionActive    = false;
DateTime sessionStartTime;
String   sessionDate      = "";
String   sessionStartStr  = "";

// ── Timing ─────────────────────────────────────────────────
unsigned long lastPzemRead      = 0;
unsigned long lastScheduleCheck = 0;
unsigned long lastJsonStream    = 0;
#define PZEM_INTERVAL_MS     2000
#define SCHEDULE_CHECK_MS    5000
#define JSON_STREAM_MS       3000

// ── SD Card ────────────────────────────────────────────────
bool sdAvailable = false;
#define LOG_FILENAME "power_log.csv"

// ── Schedule ───────────────────────────────────────────────
struct TimeSlot {
    uint8_t startH, startM, endH, endM;
};
#define MAX_SLOTS 10
TimeSlot schedule[MAX_SLOTS];
int  scheduleCount  = 0;
bool scheduleLoaded = false;

// ============================================================
// SETUP
// ============================================================
void setup() {
    Serial.begin(9600);
    Serial2.begin(4800);
    Wire.begin();

    Serial.println(F("=== LUMINESENSE Mega Booting... ==="));

    if (!rtc.begin()) {
        Serial.println(F("[RTC] FAILED"));
            } else {
                Serial.println(F("[RTC] OK"));
                Serial.println(F("[RTC] Time set"));
            }

    if (rtc.lostPower()) {
        // If the RTC battery died or it's new, sync it to the computer's clock
        rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
        Serial.println(F("[RTC] Time synced from compile time"));
    }

    if (!SD.begin(SD_CS_PIN)) {
        Serial.println(F("[SD] FAILED or no card"));
        sdAvailable = false;
    } else {
        Serial.println(F("[SD] OK"));
        sdAvailable = true;
        if (!SD.exists(LOG_FILENAME)) {
            File f = SD.open(LOG_FILENAME, FILE_WRITE);
            if (f) {
                f.println(F("Date,Time,Session_Duration_min,Avg_Voltage_V,Avg_Current_A,Total_Energy_Wh"));
                f.close();
            }
        }
    }

    requestScheduleFromServer();
    Serial.println(F("=== LUMINESENSE Mega Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    handleEsp32Messages();

    if (now - lastPzemRead >= PZEM_INTERVAL_MS) {
        lastPzemRead = now;
        readPZEM();
    }

    if (now - lastJsonStream >= JSON_STREAM_MS) {
        lastJsonStream = now;
        streamPzemJson();
    }

    if (now - lastScheduleCheck >= SCHEDULE_CHECK_MS) {
        lastScheduleCheck = now;
        checkSchedule();
    }

    // Cooldown countdown
    if (sysState == STATE_COOLDOWN) {
        if (millis() - cooldownStart >= COOLDOWN_MS) {
            Serial.println(F("[STATE] Cooldown expired — LOCKED"));
            sendRowCommand("ALL", false);
            sysState = STATE_LOCKED;
            syncStateToFrontend();
        }
    }
}

// ============================================================
// HANDLE MESSAGES FROM ESP32
// ============================================================
void handleEsp32Messages() {
    if (Serial2.available()) {
        String msg = Serial2.readStringUntil('\n');
        msg.trim();
        msg.toUpperCase();

        Serial.print(F("[ESP32] ")); Serial.println(msg);

        // ── PIR Events ──
        if (msg == "PIR:ON") {
            pirState = true;

            if (sysState == STATE_SCHEDULED) {
                Serial.println(F("[PIR] Motion in schedule — lights ON"));
                sendRowCommand("ALL", true);
                if (!sessionActive) startSession(rtc.now());
                syncStateToFrontend();
            }
            else if (sysState == STATE_COOLDOWN && !pirResetUsed) {
                Serial.println(F("[PIR] Motion in cooldown — reset countdown (once)"));
                pirResetUsed  = true;
                cooldownStart = millis();   // reset the 30s timer
            }
            else {
                Serial.println(F("[PIR] Motion ignored"));
            }
        }
        else if (msg == "PIR:OFF") {
            pirState = false;
            Serial.println(F("[PIR] Motion stopped"));
        }

        // ── Manual Toggles (only in SCHEDULED or OUTSIDE) ──
        else if (msg == "ROW1:ON"  || msg == "ROW1:OFF" ||
                 msg == "ROW2:ON"  || msg == "ROW2:OFF" ||
                 msg == "ROW3:ON"  || msg == "ROW3:OFF" ||
                 msg == "ALL:ON"   || msg == "ALL:OFF") {

            if (sysState == STATE_SCHEDULED) {
                // Parse and apply
                int colonPos = msg.indexOf(':');
                String row   = msg.substring(0, colonPos);
                bool   state = msg.endsWith("ON");
                sendRowCommand(row, state);
            } else {
                Serial.println(F("[GATE] Toggle blocked — cooldown/locked"));
            }
        }

        else if (msg == "STATUS") { sendStatusJson(); }
        else if (msg.startsWith("SCHEDULE:")) {
            parseSchedulePayload(msg.substring(9));
        } else if (msg.startsWith("SCHED:")) {
            parseSchedulePayload(msg.substring(6));
        }
    }
}

// ============================================================
// SEND ROW COMMAND TO ESP32
// ============================================================
void sendRowCommand(String row, bool state) {
    String cmd = "ACK:" + row + (state ? ":ON" : ":OFF");
    Serial2.println(cmd);
    Serial.print(F("[CMD] ")); Serial.println(cmd);

    if (row == "ROW1") row1State = state;
    else if (row == "ROW2") row2State = state;
    else if (row == "ROW3") row3State = state;
    else if (row == "ALL") {
        row1State = row2State = row3State = state;
    }
}

// ============================================================
// SCHEDULE CHECK
// ============================================================
bool isWithinSchedule(DateTime now) {
    if (!scheduleLoaded || scheduleCount == 0) return false;
    int nowMins = now.hour() * 60 + now.minute();
    for (int i = 0; i < scheduleCount; i++) {
        int startMins = schedule[i].startH * 60 + schedule[i].startM;
        int endMins   = schedule[i].endH   * 60 + schedule[i].endM;
        if (nowMins >= startMins && nowMins < endMins) return true;
    }
    return false;
}

void checkSchedule() {
    DateTime now        = rtc.now();
    bool     inSchedule = isWithinSchedule(now);

    Serial.print(F("[RTC NOW] "));
    Serial.print(now.year()); Serial.print("-");
    Serial.print(now.month()); Serial.print("-");
    Serial.print(now.day()); Serial.print(" ");
    Serial.print(now.hour()); Serial.print(":");
    Serial.println(now.minute());

    Serial.print(F("[STATE] ")); 
    switch(sysState) {
        case STATE_OUTSIDE:   Serial.println(F("OUTSIDE"));   break;
        case STATE_SCHEDULED: Serial.println(F("SCHEDULED")); break;
        case STATE_COOLDOWN:  Serial.println(F("COOLDOWN"));  break;
        case STATE_LOCKED:    Serial.println(F("LOCKED"));    break;
    }

    if (inSchedule && (sysState == STATE_OUTSIDE || sysState == STATE_LOCKED)) {
        // New schedule started — unlock!
        Serial.println(F("[SCHED] Schedule started — SCHEDULED"));
        sysState     = STATE_SCHEDULED;
        pirResetUsed = false;
        if (!sessionActive) startSession(now);
    }
    else if (inSchedule && sysState == STATE_COOLDOWN) {
        // Schedule still active during cooldown — back to scheduled
        sysState = STATE_SCHEDULED;
    }
    else if (!inSchedule && sysState == STATE_SCHEDULED) {
        // Schedule just ended — start cooldown
        Serial.println(F("[SCHED] Schedule ended — COOLDOWN started"));
        sysState      = STATE_COOLDOWN;
        cooldownStart = millis();
        pirResetUsed  = false;
        if (sessionActive) endSession(now);
    }
    // STATE_LOCKED and STATE_COOLDOWN — do nothing, stay locked

    requestScheduleFromServer();
}

void requestScheduleFromServer() {
    Serial2.println("FETCH:SCHEDULE");
}

void parseSchedulePayload(String payload) {
    scheduleCount = 0;
    int idx = 0;

    while (payload.length() > 0 && idx < MAX_SLOTS) {
        int commaPos = payload.indexOf(',');
        String slot  = (commaPos == -1) ? payload : payload.substring(0, commaPos);
        payload      = (commaPos == -1) ? "" : payload.substring(commaPos + 1);

        int dashPos = slot.indexOf('-', 3);
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
        idx++;
        scheduleCount = idx;
    }

    scheduleLoaded = (scheduleCount > 0);
    Serial.print(F("[SCHED] Loaded ")); Serial.print(scheduleCount); Serial.println(F(" slot(s)"));
}

// ============================================================
// PZEM
// ============================================================
void readPZEM() {
    float voltage = pzem.voltage();
    float current = pzem.current();
    float power   = pzem.power();
    float energy  = pzem.energy();
    float freq    = pzem.frequency();
    float pf      = pzem.pf();

    if (isnan(voltage) || isnan(current) || isnan(power) || voltage == 0.0) {
        Serial.println(F("[PZEM] Read error"));
        return;
    }

    if (sessionActive) {
        sumVoltage += voltage;
        sumCurrent += current;
        sumPower   += power;
        pzemReadCount++;
        if (sessionStartEnergy == 0) sessionStartEnergy = energy;
        totalEnergy = energy - sessionStartEnergy;
    }

    Serial.print(F("[PZEM] V:")); Serial.print(voltage);
    Serial.print(F(" A:"));       Serial.print(current);
    Serial.print(F(" W:"));       Serial.print(power);
    Serial.print(F(" Wh:"));      Serial.print(energy);
    Serial.print(F(" Hz:"));      Serial.print(freq);
    Serial.print(F(" PF:"));      Serial.println(pf);
}

void streamPzemJson() {
    float voltage = pzem.voltage();
    float current = pzem.current();
    float power   = pzem.power();
    float energy  = pzem.energy();

    if (isnan(voltage) || voltage == 0.0) return;

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
    doc["state"]   = (int)sysState;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

void syncStateToFrontend() {
    StaticJsonDocument<128> doc;
    doc["type"]  = "sync";
    doc["row1"]  = row1State;
    doc["row2"]  = row2State;
    doc["row3"]  = row3State;
    doc["state"] = (int)sysState;
    doc["pir"]   = pirState;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

void sendStatusJson() {
    StaticJsonDocument<200> doc;
    doc["type"]    = "status";
    doc["row1"]    = row1State;
    doc["row2"]    = row2State;
    doc["row3"]    = row3State;
    doc["state"]   = (int)sysState;
    doc["pir"]     = pirState;
    doc["session"] = sessionActive;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

// ============================================================
// SESSION LOGGING
// ============================================================
void startSession(DateTime startTime) {
    sessionActive      = true;
    sessionStartTime   = startTime;
    sessionStartEnergy = 0;
    sumVoltage = sumCurrent = sumPower = totalEnergy = 0;
    pzemReadCount = 0;

    char dateBuf[12], timeBuf[10];
    sprintf(dateBuf, "%04d-%02d-%02d", startTime.year(), startTime.month(), startTime.day());
    sprintf(timeBuf, "%02d:%02d:%02d", startTime.hour(), startTime.minute(), startTime.second());
    sessionDate     = String(dateBuf);
    sessionStartStr = String(timeBuf);

    Serial.print(F("[SESSION] Started: ")); Serial.print(sessionDate);
    Serial.print(F(" ")); Serial.println(sessionStartStr);
}

void endSession(DateTime endTime) {
    if (!sessionActive || pzemReadCount == 0) { sessionActive = false; return; }

    float avgVoltage  = sumVoltage / pzemReadCount;
    float avgCurrent  = sumCurrent / pzemReadCount;
    TimeSpan duration = endTime - sessionStartTime;
    int durationMins  = duration.totalseconds() / 60;

    Serial.println(F("[SESSION] Ended"));
    Serial.print(F("  Duration: ")); Serial.print(durationMins); Serial.println(F(" min"));

    if (sdAvailable) {
        File logFile = SD.open(LOG_FILENAME, FILE_WRITE);
        if (logFile) {
            logFile.print(sessionDate);     logFile.print(",");
            logFile.print(sessionStartStr); logFile.print(",");
            logFile.print(durationMins);    logFile.print(",");
            logFile.print(avgVoltage, 2);   logFile.print(",");
            logFile.print(avgCurrent, 3);   logFile.print(",");
            logFile.println(totalEnergy, 4);
            logFile.close();
            Serial.println(F("[SD] Logged"));
        }
    }

    sessionActive = false;
    sessionStartEnergy = sumVoltage = sumCurrent = 0;
    pzemReadCount = 0;
    totalEnergy   = 0;
}
