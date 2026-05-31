// !! MUST be first — before all includes !!
#define SERIAL_RX_BUFFER_SIZE 256

/*
  ============================================================
  LUMINESENSE — Arduino Mega 2560
  ============================================================
  Responsibilities:
    - PZEM-004T V3.0  : AC Power Metering (Serial1)
    - DS3231 RTC      : Real-Time Clock (I2C)
    - Micro SD        : CSV Logging (SPI, pin 53)
    - ESP32 bridge    : WiFi + LED control (Serial2)

  STATE MACHINE:
    OUTSIDE   → PIR ignored, faculty CAN toggle
    SCHEDULED → PIR turns lights ON, faculty CAN toggle
    COOLDOWN  → 30s grace period, PIR resets ONCE
    LOCKED    → lights OFF, locked until next schedule
  ============================================================
*/

#include <PZEM004Tv30.h>
#include <RTClib.h>
#include <SD.h>
#include <SPI.h>
#include <Wire.h>
#include <ArduinoJson.h>

// ── Pins ───────────────────────────────────────────────────
#define SD_CS_PIN 53

// ── Objects ────────────────────────────────────────────────
PZEM004Tv30 pzem(Serial1);
RTC_DS3231  rtc;

// ── State Machine ──────────────────────────────────────────
enum SystemState { STATE_OUTSIDE, STATE_SCHEDULED, STATE_COOLDOWN, STATE_LOCKED };
SystemState sysState = STATE_OUTSIDE;

// ── WiFi / SD Sync ─────────────────────────────────────────
bool wifiConnected = false;
bool sdSyncDone    = false;

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR ────────────────────────────────────────────────────
bool pirState     = false;
bool pirResetUsed = false;
bool roomOccupied    = false;  

// ── Cooldown ───────────────────────────────────────────────
unsigned long cooldownStart = 0;
#define COOLDOWN_MS 30000

// ── PZEM Metrics ───────────────────────────────────────────
double sumVoltage         = 0;
double sumCurrent         = 0;
double sumPower           = 0;
double totalEnergy        = 0;
double sessionStartEnergy = 0;
int    pzemReadCount      = 0;

// ── Session ────────────────────────────────────────────────
bool     sessionActive   = false;
DateTime sessionStartTime;
String   sessionDate     = "";
String   sessionStartStr = "";

// ── Timing ─────────────────────────────────────────────────
unsigned long lastPzemRead      = 0;
unsigned long lastScheduleCheck = 0;
unsigned long lastJsonStream    = 0;
#define PZEM_INTERVAL_MS  6000
#define SCHEDULE_CHECK_MS 30000
#define JSON_STREAM_MS    8000

// ── SD Card ────────────────────────────────────────────────
bool sdAvailable = false;
#define LOG_FILENAME "power_log.csv"

// ── Schedule ───────────────────────────────────────────────
struct TimeSlot { uint8_t startH, startM, endH, endM; };
#define MAX_SLOTS 10
TimeSlot schedule[MAX_SLOTS];
int    scheduleCount  = 0;
bool   scheduleLoaded = false;
String serial2Buffer  = "";

// ── Forward declarations ───────────────────────────────────
void handleEsp32Messages();
void checkSchedule();
void readPZEM();
void streamPzemJson();
void syncStateToFrontend();
void sendRowCommand(String, bool);
void startSession(DateTime);
void endSession(DateTime);
void requestScheduleFromServer();
void parseSchedulePayload(String);
bool isWithinSchedule(DateTime);

// ============================================================
// SETUP
// ============================================================
void setup() {
    Serial.begin(9600);
    Serial2.begin(4800);
    Wire.begin();

    Serial.println(F("=== LUMINESENSE Mega Booting... ==="));

    // RTC
    if (!rtc.begin()) {
        Serial.println(F("[RTC] FAILED"));
    } else {
        Serial.println(F("[RTC] OK"));
        // if (rtc.lostPower()) {
            rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
            Serial.println(F("[RTC] Time synced from compile time"));
    }

    // SD Card
    if (!SD.begin(SD_CS_PIN)) {
        Serial.println(F("[SD] FAILED or no card"));
        sdAvailable = false;
    } else {
        Serial.println(F("[SD] OK"));
        sdAvailable = true;
        if (!SD.exists(LOG_FILENAME)) {
            File f = SD.open(LOG_FILENAME, FILE_WRITE);
            if (f) {
                f.println(F("Date,Time,Duration_min,Avg_V,Avg_A,Energy_Wh"));
                f.close();
            }
        }
    }

    requestScheduleFromServer();

    // Load schedule from SD if WiFi not ready yet
    delay(3000);
    if (!scheduleLoaded && sdAvailable) {
        File f = SD.open("schedule.txt", FILE_READ);
        if (f) {
            String saved = f.readStringUntil('\n');
            f.close();
            saved.trim();
            if (saved.length() > 0) {
                Serial.println(F("[SD] Loading schedule from SD fallback"));
                parseSchedulePayload(saved);
            }
        } else {
            Serial.println(F("[SD] No saved schedule found"));
        }
    }

    Serial.println(F("=== LUMINESENSE Mega Ready ==="));
}

// ============================================================
// LOOP
// ============================================================
void loop() {
    unsigned long now = millis();

    handleEsp32Messages();

    // Read PZEM
    if (now - lastPzemRead >= PZEM_INTERVAL_MS) {
        lastPzemRead = now;
        handleEsp32Messages();
        readPZEM();
        handleEsp32Messages();
    }

    // Stream PZEM JSON to ESP32
    if (now - lastJsonStream >= JSON_STREAM_MS) {
        lastJsonStream = now;
        handleEsp32Messages();
        streamPzemJson();
        handleEsp32Messages();
    }

    // Check schedule
    if (now - lastScheduleCheck >= SCHEDULE_CHECK_MS) {
        lastScheduleCheck = now;
        handleEsp32Messages();
        checkSchedule();
    }

    // Sync SD logs when WiFi just reconnected
    if (wifiConnected && !sdSyncDone) {
        sdSyncDone = true;
        syncSdLogsToEsp();
    }

    // Cooldown expiry
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
    while (Serial2.available()) {
        char c = Serial2.read();
        if (c == '\r') continue;

        if (c == '\n') {
            serial2Buffer.trim();

            if (serial2Buffer.length() == 0) {
                serial2Buffer = "";
                continue;
            }

            String msg = serial2Buffer;
            serial2Buffer = "";

            Serial.print(F("[RAW] ")); Serial.println(msg);

            // Schedule payload — handle before toUpperCase
            if (msg.startsWith("SCHEDULE:") || msg.startsWith("schedule:")) {
                parseSchedulePayload(msg.substring(9));
                // checkSchedule();
                continue;
            }
            if (msg.startsWith("SCHED:") || msg.startsWith("sched:")) {
                parseSchedulePayload(msg.substring(6));
                continue;
            }

            msg.toUpperCase();
            Serial.print(F("[ESP32] ")); Serial.println(msg);

            // ── PIR ──────────────────────────────────────────────
            if (msg == "PIR:ON") {
                pirState = true;
                if (sysState == STATE_SCHEDULED) {
                    if (!roomOccupied) {
                        // First detection — turn lights on
                        roomOccupied = true;
                        if (!row1State && !row2State && !row3State) {
                            Serial.println(F("[PIR] First motion — lights ON"));
                            sendRowCommand("ALL", true);
                            if (!sessionActive) startSession(rtc.now());
                            syncStateToFrontend();
                        } else {
                            Serial.println(F("[PIR] First motion — lights already on"));
                        }
                    } else {
                        Serial.println(F("[PIR] Room already occupied — ignored"));
                    }
                } else if (sysState == STATE_COOLDOWN && !pirResetUsed) {
                    Serial.println(F("[PIR] Cooldown reset"));
                    pirResetUsed  = true;
                    cooldownStart = millis();
                } else {
                    Serial.println(F("[PIR] Ignored — outside schedule"));
                }
            }

            // ── Row toggles from web dashboard ───────────────────
            else if (msg == "ROW1:ON"  || msg == "ROW1:OFF" ||
                     msg == "ROW2:ON"  || msg == "ROW2:OFF" ||
                     msg == "ROW3:ON"  || msg == "ROW3:OFF" ||
                     msg == "ALL:ON"   || msg == "ALL:OFF") {

                if (sysState == STATE_SCHEDULED || sysState == STATE_OUTSIDE) {
                    int    colonPos = msg.indexOf(':');
                    String row      = msg.substring(0, colonPos);
                    bool   state    = msg.endsWith("ON");
                    sendRowCommand(row, state);
                } else {
                    Serial.println(F("[GATE] Toggle blocked — cooldown/locked"));
                }
            }

            // ── Status request ────────────────────────────────────
            else if (msg == "STATUS") {
                syncStateToFrontend();
            } else if (msg == "WIFI:ON") {
                wifiConnected = true;
                sdSyncDone    = false;
                Serial.println(F("[WIFI] Connected"));
            }
            else if (msg == "WIFI:OFF") {
                wifiConnected = false;
                Serial.println(F("[WIFI] Disconnected"));
            }

        } else {
            serial2Buffer += c;
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

    if      (row == "ROW1") row1State = state;
    else if (row == "ROW2") row2State = state;
    else if (row == "ROW3") row3State = state;
    else if (row == "ALL")  row1State = row2State = row3State = state;
}

// ============================================================
// SCHEDULE
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

    // Debug
    Serial.print(F("[RTC] "));
    Serial.print(now.year());  Serial.print("-");
    Serial.print(now.month()); Serial.print("-");
    Serial.print(now.day());   Serial.print(" ");
    Serial.print(now.hour());  Serial.print(":");
    Serial.println(now.minute());

    Serial.print(F("[STATE] "));
    switch (sysState) {
        case STATE_OUTSIDE:   Serial.println(F("OUTSIDE"));   break;
        case STATE_SCHEDULED: Serial.println(F("SCHEDULED")); break;
        case STATE_COOLDOWN:  Serial.println(F("COOLDOWN"));  break;
        case STATE_LOCKED:    Serial.println(F("LOCKED"));    break;
    }

    if (inSchedule && (sysState == STATE_OUTSIDE || sysState == STATE_LOCKED)) {
        Serial.println(F("[SCHED] Schedule started — SCHEDULED"));
        sysState     = STATE_SCHEDULED;
        pirResetUsed = false;
        roomOccupied = false;
        // Session starts on PIR detection, not schedule start

    } else if (inSchedule && sysState == STATE_COOLDOWN) {
        Serial.println(F("[SCHED] Back in schedule from cooldown"));
        sysState = STATE_SCHEDULED;

    } else if (!inSchedule && sysState == STATE_SCHEDULED) {
        // Check if next slot starts within 5 minutes
        bool hasNextSlot = false;
        int nowMins = now.hour() * 60 + now.minute();
        for (int i = 0; i < scheduleCount; i++) {
            int nextStart = schedule[i].startH * 60 + schedule[i].startM;
            if (nextStart > nowMins && nextStart - nowMins <= 5) {
                hasNextSlot = true;
                break;
            }
        }

        if (hasNextSlot) {
            Serial.println(F("[SCHED] Next slot ≤5 min — staying SCHEDULED"));
        } else {
            Serial.println(F("[SCHED] Schedule ended — COOLDOWN"));
            sysState      = STATE_COOLDOWN;
            cooldownStart = millis();
            pirResetUsed  = false;
            roomOccupied  = false;  // ← ADD THIS
            if (sessionActive) endSession(now);
        }
    }

    // Request fresh schedule from ESP32
    requestScheduleFromServer();
}

void requestScheduleFromServer() {
    Serial2.println("FETCH:SCHEDULE");
}

void parseSchedulePayload(String payload) {
    // ── Save raw payload to SD before parsing ──────────────
    if (sdAvailable) {
        SD.remove("schedule.txt");
        File f = SD.open("schedule.txt", FILE_WRITE);
        if (f) {
            f.println(payload);
            f.close();
            Serial.println(F("[SD] Schedule saved to SD"));
        }
    }
    // ── End SD save ────────────────────────────────────────

    scheduleCount = 0;  // ← your existing code continues here
    int idx = 0;

    while (payload.length() > 0 && idx < MAX_SLOTS) {
        int    commaPos = payload.indexOf(',');
        String slot     = (commaPos == -1) ? payload : payload.substring(0, commaPos);
        payload         = (commaPos == -1) ? "" : payload.substring(commaPos + 1);

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
    Serial.print(F("[SCHED] Loaded "));
    Serial.print(scheduleCount);
    Serial.println(F(" slot(s)"));
}

// ============================================================
// PZEM
// ============================================================
void readPZEM() {
    double voltage = pzem.voltage();
    double current = pzem.current();
    double power   = pzem.power();
    double energy  = pzem.energy();
    double freq    = pzem.frequency();
    double pf      = pzem.pf();

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
    Serial.print(F(" Wh:"));      Serial.println(energy);
}

void streamPzemJson() {
    double voltage = pzem.voltage();
    double current = pzem.current();
    double power   = pzem.power();
    double energy  = pzem.energy();

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

// ============================================================
// SESSION
// ============================================================
void startSession(DateTime startTime) {
    pzem.resetEnergy();
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

    Serial.print(F("[SESSION] Started: "));
    Serial.print(sessionDate); Serial.print(F(" ")); Serial.println(sessionStartStr);
}
void syncSdLogsToEsp() {
    if (!sdAvailable) return;
    if (!SD.exists(LOG_FILENAME)) return;

    File f = SD.open(LOG_FILENAME, FILE_READ);
    if (!f) return;

    Serial.println(F("[SD] Syncing logs to ESP32..."));
    bool firstLine = true;
    int  count     = 0;

    while (f.available()) {
        String line = f.readStringUntil('\n');
        line.trim();
        if (firstLine) { firstLine = false; continue; } // skip header
        if (line.length() == 0) continue;

        // Format: Date,Time,Duration_min,Avg_V,Avg_A,Energy_Wh
        int c1 = line.indexOf(',');
        int c2 = line.indexOf(',', c1 + 1);
        int c3 = line.indexOf(',', c2 + 1);
        int c4 = line.indexOf(',', c3 + 1);
        int c5 = line.indexOf(',', c4 + 1);
        if (c1==-1||c2==-1||c3==-1||c4==-1||c5==-1) continue;

        StaticJsonDocument<200> doc;
        doc["type"]        = "sd_log";
        doc["classroom_id"] = 3;
        doc["date"]        = line.substring(0, c1);
        doc["time"]        = line.substring(c1 + 1, c2);
        doc["duration"]    = line.substring(c2 + 1, c3).toInt();
        doc["voltage"]     = line.substring(c3 + 1, c4).toFloat();
        doc["current"]     = line.substring(c4 + 1, c5).toFloat();
        doc["energy"]      = line.substring(c5 + 1).toFloat();

        String jsonStr;
        serializeJson(doc, jsonStr);
        Serial2.println(jsonStr);
        Serial.print(F("[SD] Sent row: ")); Serial.println(jsonStr);
        count++;
        delay(300); // give ESP32 time to process
    }
    f.close();

    Serial.print(F("[SD] Sent ")); Serial.print(count); Serial.println(F(" rows"));

    // Clear log — keep header only
    SD.remove(LOG_FILENAME);
    File fresh = SD.open(LOG_FILENAME, FILE_WRITE);
    if (fresh) {
        fresh.println(F("Date,Time,Duration_min,Avg_V,Avg_A,Energy_Wh"));
        fresh.close();
        Serial.println(F("[SD] Log cleared after sync"));
    }
}

void endSession(DateTime endTime) {
    if (!sessionActive || pzemReadCount == 0) {
        sessionActive = false;
        return;
    }

    double   avgVoltage   = sumVoltage / pzemReadCount;
    double   avgCurrent   = sumCurrent / pzemReadCount;
    TimeSpan duration     = endTime - sessionStartTime;
    int      durationMins = duration.totalseconds() / 60;

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

    sessionActive      = false;
    sessionStartEnergy = 0;
    sumVoltage = sumCurrent = sumPower = 0;
    pzemReadCount = 0;
    totalEnergy   = 0;
}