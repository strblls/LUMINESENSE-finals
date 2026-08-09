// !! MUST be first — before all includes !!
#define SERIAL_RX_BUFFER_SIZE 256

/*
  ============================================================
  LUMINESENSE — Arduino Mega 2560 Master Controller
  ============================================================
  Components:
    - PZEM-004T V3.0   : AC Power Metering (Serial1, pins 18/19)
    - DS3231 RTC       : Real-Time Clock (I2C, pins 20/21)
    - Micro SD Reader  : CSV Data Logging (SPI, pin 53)
    - ESP32            : WiFi Bridge (Serial2, pins 16/17)
    - MOSFETs          : LED strip control (pins 2, 3, 4)
    - PIR Sensor       : Occupancy detection (pin 5)

  SYSTEM STATES:
    STATE_OUTSIDE   : Outside schedule — PIR ignored, faculty CAN toggle, auto-off after inactivity timeout
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
#define SD_CS_PIN 53
#define ROW1_PIN 8
#define ROW2_PIN 3
#define ROW3_PIN 10
#define PIR_PIN  5

// ── Object Initialization ──────────────────────────────────
PZEM004Tv30 pzem(Serial1);
RTC_DS3231 rtc;

// ── System State Machine ───────────────────────────────────
enum SystemState
{
    STATE_OUTSIDE,
    STATE_SCHEDULED,
    STATE_COOLDOWN,
    STATE_LOCKED
};
SystemState sysState = STATE_OUTSIDE;

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;

// ── PIR State ──────────────────────────────────────────────
bool pirState = false;
bool pirResetUsed = false;
bool lightOverride = false;

// ── PIR Inactivity Timeout (auto-off after no motion) ──────
unsigned long lastPirActivity = 0;
bool pirLockoutActive = false;
unsigned long pirInactivityTimeoutMs = 300000; // default 5 min, updated via CONFIG

// ── Cooldown Timer ─────────────────────────────────────────
unsigned long cooldownStart = 0;
#define COOLDOWN_MS 30000

// ── PZEM Metrics ───────────────────────────────────────────
double sumVoltage = 0;
double sumCurrent = 0;
double sumPower = 0;
double totalEnergy = 0;
double sessionStartEnergy = 0;
int pzemReadCount = 0;

// ── Session State ──────────────────────────────────────────
bool sessionActive = false;
DateTime sessionStartTime;
String sessionDate = "";
String sessionStartStr = "";

// ── Timing ─────────────────────────────────────────────────
unsigned long lastPzemRead = 0;
unsigned long lastScheduleCheck = 0;
unsigned long lastJsonStream = 0;
#define PZEM_INTERVAL_MS 6000
#define SCHEDULE_CHECK_MS 30000
#define JSON_STREAM_MS 8000

// ── SD Card ────────────────────────────────────────────────
bool sdAvailable = false;
#define POWER_LOG_FILENAME "power_log.csv"
#define STATE_FILENAME "state.dat"
#define SCHEDULE_CACHE_FILENAME "schedule.csv"
#define ARCHIVE_DIR "archive"
#define ARCHIVE_HEADER "minute,avg_voltage,avg_current,avg_power,energy_wh,reading_count"

// ── Per-minute archive accumulation ───────────────────────
String archiveDateStr = "";
int    archMinuteKey  = -1;   // HH*60+MM of the minute being accumulated
double archSumV = 0, archSumC = 0, archSumW = 0, archEnergyWh = 0;
int    archCount = 0;

// ── Schedule ───────────────────────────────────────────────
struct TimeSlot
{
    uint8_t startH, startM, endH, endM;
};
#define MAX_SLOTS 10
TimeSlot schedule[MAX_SLOTS];
int scheduleCount = 0;
bool scheduleLoaded = false;
String serial2Buffer = "";

// ============================================================
// SETUP
// ============================================================
void setup()
{
    Serial.begin(9600);
    Serial2.begin(9600);
    Wire.begin();
    Serial1.begin(9600);

    pinMode(ROW1_PIN, OUTPUT);
    pinMode(ROW2_PIN, OUTPUT);
    pinMode(ROW3_PIN, OUTPUT);
    pinMode(PIR_PIN, INPUT);
    digitalWrite(ROW1_PIN, LOW);
    digitalWrite(ROW2_PIN, LOW);
    digitalWrite(ROW3_PIN, LOW);

    Serial.println(F("=== LUMINESENSE Mega Booting... ==="));

    if (!rtc.begin())
    {
        Serial.println(F("[RTC] FAILED"));
    }
    else
    {
        Serial.println(F("[RTC] OK"));
    }

    if (rtc.lostPower())
    {
        rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
        Serial.println(F("[RTC] Time synced from compile time"));
    }

    if (!SD.begin(SD_CS_PIN))
    {
        Serial.println(F("[SD] FAILED or no card"));
        sdAvailable = false;
    }
    else
    {
        Serial.println(F("[SD] OK"));
        sdAvailable = true;
        if (!SD.exists(POWER_LOG_FILENAME))
        {
            File f = SD.open(POWER_LOG_FILENAME, FILE_WRITE);
            if (f)
            {
                f.println(F("Date,Time,Session_Duration_min,Avg_Voltage_V,Avg_Current_A,Total_Energy_Wh"));
                f.close();
            }
        }
        if (!SD.exists(ARCHIVE_DIR))
        {
            SD.mkdir(ARCHIVE_DIR);
        }
    }

    // Load schedule and state from SD cache
    loadScheduleCache();
    loadState();

    // Validate state against current schedule — force OFF if outside schedule
    if (!isWithinSchedule(rtc.now()) && sysState != STATE_COOLDOWN)
    {
        Serial.println(F("[BOOT] Outside schedule — forcing lights OFF"));
        sysState = STATE_OUTSIDE;
        row1State = row2State = row3State = false;
        digitalWrite(ROW1_PIN, LOW);
        digitalWrite(ROW2_PIN, LOW);
        digitalWrite(ROW3_PIN, LOW);
        saveState();
    }

    // Ask ESP32 for fresh schedule and config on boot
    requestScheduleFromServer();
    lastPirActivity = millis();
    Serial.println(F("=== LUMINESENSE Mega Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop()
{
    unsigned long now = millis();

    handleEsp32Messages();
    handlePIR(now);

    if (now - lastPzemRead >= PZEM_INTERVAL_MS)
    {
        lastPzemRead = now;
        handleEsp32Messages();
        readPZEM();
        handleEsp32Messages();
    }

    if (now - lastJsonStream >= JSON_STREAM_MS)
    {
        lastJsonStream = now;
        handleEsp32Messages();
        streamPzemJson();
        handleEsp32Messages();
    }

    if (now - lastScheduleCheck >= SCHEDULE_CHECK_MS)
    {
        lastScheduleCheck = now;
        handleEsp32Messages();
        checkSchedule();
    }

    if (sysState == STATE_COOLDOWN)
    {
        if (millis() - cooldownStart >= COOLDOWN_MS)
        {
            Serial.println(F("[STATE] Cooldown expired — LOCKED"));
            setAllRows(false);
            sysState = STATE_LOCKED;
            saveState();
            syncStateToFrontend();
            Serial2.println("ACK:ALL:OFF");
        }
    }

    // Day rollover — flush the pending per-minute archive row to a new day's file
    DateTime rollNow = rtc.now();
    char dayBuf[12];
    sprintf(dayBuf, "%04d-%02d-%02d", rollNow.year(), rollNow.month(), rollNow.day());
    if (archiveDateStr.length() > 0 && archiveDateStr != String(dayBuf))
    {
        flushArchiveMinute();
        archiveDateStr = String(dayBuf);
    }

    // Debug serial commands from USB Serial Monitor
    handleSerialCommands();
}

void handleSerialCommands()
{
    static String serialBuffer = "";
    while (Serial.available())
    {
        char c = Serial.read();
        if (c == '\r') continue;
        if (c == '\n')
        {
            serialBuffer.trim();
            if (serialBuffer.length() == 0) { serialBuffer = ""; continue; }
            String cmd = serialBuffer;
            serialBuffer = "";
            cmd.toUpperCase();

            if (cmd == "STATUS")
            {
                printDebugStatus();
            }
            else if (cmd == "ALL:OFF")
            {
                Serial.println(F("[CMD] ALL:OFF"));
                setAllRows(false);
                pirLockoutActive = false;
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ALL:ON")
            {
                Serial.println(F("[CMD] ALL:ON"));
                setAllRows(true);
                pirLockoutActive = false;
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW1:OFF")
            {
                Serial.println(F("[CMD] ROW1:OFF"));
                setRow(1, false);
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW1:ON")
            {
                Serial.println(F("[CMD] ROW1:ON"));
                setRow(1, true);
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW2:OFF")
            {
                Serial.println(F("[CMD] ROW2:OFF"));
                setRow(2, false);
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW2:ON")
            {
                Serial.println(F("[CMD] ROW2:ON"));
                setRow(2, true);
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW3:OFF")
            {
                Serial.println(F("[CMD] ROW3:OFF"));
                setRow(3, false);
                saveState();
                syncStateToFrontend();
            }
            else if (cmd == "ROW3:ON")
            {
                Serial.println(F("[CMD] ROW3:ON"));
                setRow(3, true);
                saveState();
                syncStateToFrontend();
            }
            else
            {
                Serial.print(F("[CMD] Unknown: "));
                Serial.println(cmd);
            }
        }
        else
        {
            serialBuffer += c;
        }
    }
}

void printDebugStatus()
{
    Serial.println(F("═══ DEBUG STATUS ═══"));
    Serial.print(F("State: "));
    switch (sysState)
    {
    case STATE_OUTSIDE:   Serial.println(F("OUTSIDE"));   break;
    case STATE_SCHEDULED: Serial.println(F("SCHEDULED")); break;
    case STATE_COOLDOWN:  Serial.println(F("COOLDOWN"));  break;
    case STATE_LOCKED:    Serial.println(F("LOCKED"));    break;
    }
    Serial.print(F("Rows: 1="));
    Serial.print(row1State ? "ON" : "OFF");
    Serial.print(F(" 2="));
    Serial.print(row2State ? "ON" : "OFF");
    Serial.print(F(" 3="));
    Serial.println(row3State ? "ON" : "OFF");
    Serial.print(F("PIR: "));
    Serial.println(pirState ? "HIGH" : "LOW");
    Serial.print(F("Schedule: loaded="));
    Serial.print(scheduleLoaded);
    Serial.print(F(" count="));
    Serial.println(scheduleCount);
    DateTime now = rtc.now();
    Serial.print(F("RTC: "));
    Serial.print(now.year());
    Serial.print("-");
    Serial.print(now.month());
    Serial.print("-");
    Serial.print(now.day());
    Serial.print(" ");
    Serial.print(now.hour());
    Serial.print(":");
    Serial.print(now.minute());
    Serial.print(":");
    Serial.println(now.second());
    Serial.print(F("inSchedule="));
    Serial.println(isWithinSchedule(now));
    Serial.print(F("PIR inactivity timeout: "));
    Serial.print(pirInactivityTimeoutMs / 1000);
    Serial.println(F("s"));
    Serial.print(F("Time since last PIR: "));
    Serial.print((millis() - lastPirActivity) / 1000);
    Serial.println(F("s"));
    Serial.println(F("═════════════════════"));
}

// ============================================================
// SET ROW (direct MOSFET control)
// ============================================================
void setRow(int row, bool state)
{
    switch (row)
    {
        case 1: row1State = state; digitalWrite(ROW1_PIN, state ? HIGH : LOW); break;
        case 2: row2State = state; digitalWrite(ROW2_PIN, state ? HIGH : LOW); break;
        case 3: row3State = state; digitalWrite(ROW3_PIN, state ? HIGH : LOW); break;
    }
    Serial.print(F("[ROW")); Serial.print(row);
    Serial.print(F("] ")); Serial.println(state ? "ON" : "OFF");
}

void setAllRows(bool state)
{
    setRow(1, state);
    setRow(2, state);
    setRow(3, state);
}

// ============================================================
// HANDLE PIR SENSOR
// ============================================================
void handlePIR(unsigned long now)
{
    static unsigned long lastPirChange = 0;
    bool reading = digitalRead(PIR_PIN);

    if (reading == pirState) return;

    if (now - lastPirChange < 2000) return;

    lastPirChange = now;
    pirState = reading;

    if (pirState == HIGH)
    {
        Serial2.println("LOG_PIR:1"); // tell ESP32: motion here, log it + reset inactivity timer
        Serial.println(F("[PIR] Motion detected"));
        lastPirActivity = millis();

        if (sysState == STATE_OUTSIDE)
        {
            Serial.println(F("[PIR] Motion outside schedule — ignored"));
            pirLockoutActive = false;
            return;
        }

        if (sysState == STATE_SCHEDULED)
        {
            if (!row1State && !row2State && !row3State && !lightOverride)
            {
                Serial.println(F("[PIR] Motion — lights ON"));
                setAllRows(true);
                if (!sessionActive)
                    startSession(rtc.now());
                saveState();
                syncStateToFrontend();
            }
            else if (lightOverride)
            {
                Serial.println(F("[PIR] Motion — skipped (user override active)"));
            }
            else
            {
                Serial.println(F("[PIR] Motion ignored — lights already on"));
            }
        }
        else if (sysState == STATE_COOLDOWN && !pirResetUsed)
        {
            Serial.println(F("[PIR] Cooldown reset"));
            pirResetUsed = true;
            cooldownStart = millis();
        }
        else
        {
            Serial.println(F("[PIR] Ignored"));
        }
    }
    else
    {
        Serial2.println("LOG_PIR:0"); // tell ESP32: motion stopped → start 5-min inactivity timer
        Serial.println(F("[PIR] Motion stopped"));
    }
}

// ============================================================
// HANDLE MESSAGES FROM ESP32
// ============================================================
void handleEsp32Messages()
{
    while (Serial2.available())
    {
        char c = Serial2.read();

        if (c == '\r')
            continue; // ignore CR from println

        if (c == '\n')
        {
            serial2Buffer.trim();

            if (serial2Buffer.length() == 0)
            {
                serial2Buffer = "";
                continue; // empty line — stay in loop
            }

            String msg = serial2Buffer;
            serial2Buffer = "";

            Serial.print(F("[RAW] "));
            Serial.println(msg);

            // Handle SCHEDULE before toUpperCase — payload has colons/numbers
            if (msg.startsWith("SCHEDULE:") || msg.startsWith("schedule:"))
            {
                parseSchedulePayload(msg.substring(9));
                checkSchedule();
                continue;
            }
            if (msg.startsWith("SCHED:") || msg.startsWith("sched:"))
            {
                parseSchedulePayload(msg.substring(6));
                continue;
            }

            // Handle CONFIG before toUpperCase — payload has key=value
            if (msg.startsWith("CONFIG:") || msg.startsWith("config:"))
            {
                parseConfigPayload(msg.substring(7));
                continue;
            }

            // Handle TIME before toUpperCase — payload has commas/numbers
            if (msg.startsWith("TIME:"))
            {
                String t = msg.substring(5);
                int y = t.substring(0, t.indexOf(',')).toInt(); t = t.substring(t.indexOf(',') + 1);
                int mo = t.substring(0, t.indexOf(',')).toInt(); t = t.substring(t.indexOf(',') + 1);
                int d = t.substring(0, t.indexOf(',')).toInt(); t = t.substring(t.indexOf(',') + 1);
                int h = t.substring(0, t.indexOf(',')).toInt(); t = t.substring(t.indexOf(',') + 1);
                int mn = t.substring(0, t.indexOf(',')).toInt(); t = t.substring(t.indexOf(',') + 1);
                int s = t.toInt();
                if (y >= 2025) {
                    rtc.adjust(DateTime(y, mo, d, h, mn, s));
                    Serial.println(F("[RTC] Time synced from NTP"));
                    checkSchedule();
                }
                continue;
            }

            // Handle ARCHIVE commands before toUpperCase — payload has dates
            if (msg.startsWith("ARCHIVE:LIST") || msg.startsWith("archive:list"))
            {
                sendArchiveList();
                continue;
            }
            if (msg.startsWith("ARCHIVE:READ:") || msg.startsWith("archive:read:"))
            {
                sendArchiveDay(msg.substring(13));
                continue;
            }

            msg.toUpperCase();
            Serial.print(F("[ESP32] "));
            Serial.println(msg);
                if (msg == "ROW1:ON") {
                    setRow(1, true);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ROW1:OFF") {
                    setRow(1, false);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ROW2:ON") {
                    setRow(2, true);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ROW2:OFF") {
                    setRow(2, false);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ROW3:ON") {
                    setRow(3, true);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ROW3:OFF") {
                    setRow(3, false);
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ALL:OFF") {
                    Serial.println(F("[ESP32] ALL:OFF"));
                    setAllRows(false);
                    saveState();
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "ALL:ON") {
                    Serial.println(F("[ESP32] ALL:ON"));
                    setAllRows(true);
                    saveState();
                    syncStateToFrontend();
                    continue;
                }
                if (msg == "LIGHT_OVERRIDE=1") {
                    lightOverride = true;
                    Serial.println(F("[ESP32] light_override=1"));
                    continue;
                }
                if (msg == "LIGHT_OVERRIDE=0") {
                    lightOverride = false;
                    Serial.println(F("[ESP32] light_override=0"));
                    continue;
                }
            // while loop continues naturally to next byte
        }
        else
        {
            serial2Buffer += c;
        }
    }
}

// ============================================================
// SCHEDULE CHECK
// ============================================================
bool isWithinSchedule(DateTime now)
{
    if (!scheduleLoaded || scheduleCount == 0)
        return false;
    int nowMins = now.hour() * 60 + now.minute();
    for (int i = 0; i < scheduleCount; i++)
    {
        int startMins = schedule[i].startH * 60 + schedule[i].startM;
        int endMins = schedule[i].endH * 60 + schedule[i].endM;
        if (nowMins >= startMins && nowMins < endMins)
            return true;
    }
    return false;
}

void checkSchedule()
{
    DateTime now = rtc.now();
    bool inSchedule = isWithinSchedule(now);

    Serial.print(F("[RTC NOW] "));
    Serial.print(now.year());
    Serial.print("-");
    Serial.print(now.month());
    Serial.print("-");
    Serial.print(now.day());
    Serial.print(" ");
    Serial.print(now.hour());
    Serial.print(":");
    Serial.println(now.minute());

    Serial.print(F("[STATE] "));
    switch (sysState)
    {
    case STATE_OUTSIDE:
        Serial.println(F("OUTSIDE"));
        break;
    case STATE_SCHEDULED:
        Serial.println(F("SCHEDULED"));
        break;
    case STATE_COOLDOWN:
        Serial.println(F("COOLDOWN"));
        break;
    case STATE_LOCKED:
        Serial.println(F("LOCKED"));
        break;
    }

    Serial.print(F("[SCHED] loaded="));
    Serial.print(scheduleLoaded);
    Serial.print(F(" count="));
    Serial.print(scheduleCount);
    Serial.print(F(" inSchedule="));
    Serial.println(inSchedule);

    if (inSchedule && (sysState == STATE_OUTSIDE || sysState == STATE_LOCKED))
    {
        Serial.println(F("[SCHED] Schedule started — SCHEDULED"));
        sysState = STATE_SCHEDULED;
        pirResetUsed = false;
        pirLockoutActive = false;
        lastPirActivity = millis();
        saveState();
        // Session only starts when PIR detects motion, not on schedule start
    }
    else if (inSchedule && sysState == STATE_COOLDOWN)
    {
        sysState = STATE_SCHEDULED;
        saveState();
    }
    else if (!inSchedule && sysState == STATE_SCHEDULED)
    {
        // Check if next schedule slot starts within 5 minutes
        bool hasNextSlot = false;
        int nowMins = now.hour() * 60 + now.minute();
        for (int i = 0; i < scheduleCount; i++)
        {
            int nextStartMins = schedule[i].startH * 60 + schedule[i].startM;
            if (nextStartMins > nowMins && nextStartMins - nowMins <= 5)
            {
                hasNextSlot = true;
                break;
            }
        }

        if (hasNextSlot)
        {
            // Next class starts soon — stay scheduled, keep session running
            Serial.println(F("[SCHED] Next slot in ≤5 min — staying SCHEDULED"));
            sysState = STATE_SCHEDULED;
            saveState();
        }
        else
        {
            // No immediate next class — enter cooldown grace period
            Serial.println(F("[SCHED] Schedule ended — COOLDOWN started"));
            sysState = STATE_COOLDOWN;
            cooldownStart = millis();
            pirResetUsed = false;
            lightOverride = false;
            saveState();
            if (sessionActive)
                endSession(now);
        }
    }

    // Ask ESP32 to re-fetch schedule
    requestScheduleFromServer();
}

void requestScheduleFromServer()
{
    Serial2.println("FETCH:SCHEDULE");
}

void parseSchedulePayload(String payload)
{
    scheduleCount = 0;
    int idx = 0;

    while (payload.length() > 0 && idx < MAX_SLOTS)
    {
        int commaPos = payload.indexOf(',');
        String slot = (commaPos == -1) ? payload : payload.substring(0, commaPos);
        payload = (commaPos == -1) ? "" : payload.substring(commaPos + 1);

        int dashPos = slot.indexOf('-', 3);
        if (dashPos == -1)
            continue;

        String startStr = slot.substring(0, dashPos);
        String endStr = slot.substring(dashPos + 1);
        int colonS = startStr.indexOf(':');
        int colonE = endStr.indexOf(':');
        if (colonS == -1 || colonE == -1)
            continue;

        schedule[idx].startH = startStr.substring(0, colonS).toInt();
        schedule[idx].startM = startStr.substring(colonS + 1).toInt();
        schedule[idx].endH = endStr.substring(0, colonE).toInt();
        schedule[idx].endM = endStr.substring(colonE + 1).toInt();
        idx++;
        scheduleCount = idx;
    }

    scheduleLoaded = (scheduleCount > 0);
    Serial.print(F("[SCHED] Loaded "));
    Serial.print(scheduleCount);
    Serial.println(F(" slot(s)"));
    saveScheduleCache();
}

void parseConfigPayload(String payload)
{
    int eqPos = payload.indexOf('=');
    if (eqPos == -1) return;

    String key = payload.substring(0, eqPos);
    key.toUpperCase();
    String val = payload.substring(eqPos + 1);

    if (key == "PIR_TIMEOUT")
    {
        pirInactivityTimeoutMs = (unsigned long)val.toInt();
        Serial.print(F("[CONFIG] PIR_TIMEOUT set to "));
        Serial.print(pirInactivityTimeoutMs);
        Serial.println(F(" ms"));
    }
}

// ============================================================
// PZEM
// ============================================================
void readPZEM()
{
    double voltage = pzem.voltage();
    double current = pzem.current();
    double power = pzem.power();
    double energy = pzem.energy();
    double freq = pzem.frequency();
    double pf = pzem.pf();

    if (isnan(voltage) || isnan(current) || isnan(power))
    {
        Serial.println(F("[PZEM] Comm fail — check AC power & wiring"));
        return;
    }

    if (voltage == 0.0)
    {
        Serial.println(F("[PZEM] AC voltage reads 0 — check AC input to PZEM"));
        return;
    }

    // Per-minute archive accumulation (SD) — runs regardless of session
    DateTime pzNow = rtc.now();
    int minKey = pzNow.hour() * 60 + pzNow.minute();
    if (archMinuteKey != -1 && minKey != archMinuteKey)
    {
        flushArchiveMinute();
    }
    if (archMinuteKey == -1)
    {
        archMinuteKey = minKey;
        char archDayBuf[12];
        sprintf(archDayBuf, "%04d-%02d-%02d", pzNow.year(), pzNow.month(), pzNow.day());
        archiveDateStr = String(archDayBuf);
    }
    archSumV += voltage;
    archSumC += current;
    archSumW += power;
    archEnergyWh += power * (3.0 / 3600.0);
    archCount++;

    if (sessionActive)
    {
        sumVoltage += voltage;
        sumCurrent += current;
        sumPower += power;
        pzemReadCount++;
        if (sessionStartEnergy == 0)
            sessionStartEnergy = energy;
        totalEnergy = energy - sessionStartEnergy;
    }

    Serial.print(F("[PZEM] V:"));
    Serial.print(voltage);
    Serial.print(F(" A:"));
    Serial.print(current);
    Serial.print(F(" W:"));
    Serial.print(power);
    Serial.print(F(" Wh:"));
    Serial.print(energy);
    Serial.print(F(" Hz:"));
    Serial.print(freq);
    Serial.print(F(" PF:"));
    Serial.println(pf);
}

void streamPzemJson()
{
    double voltage = pzem.voltage();
    double current = pzem.current();
    double power = pzem.power();
    double energy = pzem.energy();

    if (isnan(voltage) || voltage == 0.0)
        return;

    StaticJsonDocument<200> doc;
    doc["type"] = "pzem";
    doc["voltage"] = voltage;
    doc["current"] = current;
    doc["power"] = power;
    doc["energy"] = energy;
    doc["row1"] = row1State;
    doc["row2"] = row2State;
    doc["row3"] = row3State;
    doc["pir"] = pirState;
    doc["state"] = (int)sysState;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

void syncStateToFrontend()
{
    StaticJsonDocument<128> doc;
    doc["type"] = "sync";
    doc["row1"] = row1State;
    doc["row2"] = row2State;
    doc["row3"] = row3State;
    doc["state"] = (int)sysState;
    doc["pir"] = pirState;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

void sendStatusJson()
{
    StaticJsonDocument<200> doc;
    doc["type"] = "status";
    doc["row1"] = row1State;
    doc["row2"] = row2State;
    doc["row3"] = row3State;
    doc["state"] = (int)sysState;
    doc["pir"] = pirState;
    doc["session"] = sessionActive;

    String jsonStr;
    serializeJson(doc, jsonStr);
    Serial2.println(jsonStr);
}

// ============================================================
// PER-MINUTE ARCHIVE (SD) + ARCHIVE SERIAL PROTOCOL
// ============================================================
void flushArchiveMinute()
{
    if (archCount == 0)
    {
        archMinuteKey = -1;
        return;
    }
    if (sdAvailable)
    {
        String path = String(ARCHIVE_DIR) + "/" + archiveDateStr + ".csv";
        bool exists = SD.exists(path);
        File f = SD.open(path, FILE_WRITE);
        if (f)
        {
            if (!exists)
                f.println(F(ARCHIVE_HEADER));
            int h = archMinuteKey / 60, m = archMinuteKey % 60;
            char minuteBuf[6];
            sprintf(minuteBuf, "%02d:%02d", h, m);
            f.print(minuteBuf);
            f.print(',');
            f.print(archSumV / archCount, 2);
            f.print(',');
            f.print(archSumC / archCount, 3);
            f.print(',');
            f.print(archSumW / archCount, 2);
            f.print(',');
            f.print(archEnergyWh, 4);
            f.print(',');
            f.println(archCount);
            f.close();
        }
    }
    archSumV = archSumC = archSumW = archEnergyWh = 0;
    archCount = 0;
    archMinuteKey = -1;
}

void sendArchiveList()
{
    String dates = "";
    if (sdAvailable && SD.exists(ARCHIVE_DIR))
    {
        File dir = SD.open(ARCHIVE_DIR);
        if (dir)
        {
            while (true)
            {
                File entry = dir.openNextFile();
                if (!entry)
                    break;
                if (!entry.isDirectory())
                {
                    String name = entry.name();
                    int slash = name.lastIndexOf('/');
                    if (slash != -1)
                        name = name.substring(slash + 1);
                    if (name.length() == 14 && name.endsWith(".csv"))
                    {
                        String d = name.substring(0, 10);
                        if (d.length() > 0)
                        {
                            if (dates.length() > 0)
                                dates += ',';
                            dates += d;
                        }
                    }
                }
                entry.close();
            }
            dir.close();
        }
    }
    Serial2.println("ARCHIVE_LIST:" + dates);
    Serial.print(F("[ARCHIVE] List sent: "));
    Serial.println(dates);
}

void sendArchiveDay(String date)
{
    String path = String(ARCHIVE_DIR) + "/" + date + ".csv";
    if (!sdAvailable || !SD.exists(path))
    {
        Serial2.println("ARCHIVE_DATA_BEGIN:" + date);
        Serial2.println(F("ARCHIVE_DATA_END"));
        return;
    }
    File f = SD.open(path, FILE_READ);
    if (!f)
    {
        Serial2.println("ARCHIVE_DATA_BEGIN:" + date);
        Serial2.println(F("ARCHIVE_DATA_END"));
        return;
    }
    Serial2.println("ARCHIVE_DATA_BEGIN:" + date);
    int lines = 0;
    while (f.available())
    {
        String line = f.readStringUntil('\n');
        line.trim();
        if (line.length() == 0)
            continue;
        if (line.startsWith("minute,"))
            continue;
        Serial2.println(line);
        lines++;
    }
    f.close();
    Serial2.println(F("ARCHIVE_DATA_END"));
    Serial.print(F("[ARCHIVE] Sent "));
    Serial.print(lines);
    Serial.print(F(" rows for "));
    Serial.println(date);
}


void startSession(DateTime startTime)
{
    pzem.resetEnergy();
    sessionActive = true;
    sessionStartTime = startTime;
    sessionStartEnergy = 0;
    sumVoltage = sumCurrent = sumPower = totalEnergy = 0;
    pzemReadCount = 0;

    char dateBuf[12], timeBuf[10];
    sprintf(dateBuf, "%04d-%02d-%02d", startTime.year(), startTime.month(), startTime.day());
    sprintf(timeBuf, "%02d:%02d:%02d", startTime.hour(), startTime.minute(), startTime.second());
    sessionDate = String(dateBuf);
    sessionStartStr = String(timeBuf);

    saveState();
    Serial.print(F("[SESSION] Started: "));
    Serial.print(sessionDate);
    Serial.print(F(" "));
    Serial.println(sessionStartStr);
}

void endSession(DateTime endTime)
{
    if (!sessionActive || pzemReadCount == 0)
    {
        sessionActive = false;
        return;
    }

    double avgVoltage = sumVoltage / pzemReadCount;
    double avgCurrent = sumCurrent / pzemReadCount;
    TimeSpan duration = endTime - sessionStartTime;
    int durationMins = duration.totalseconds() / 60;

    Serial.println(F("[SESSION] Ended"));
    Serial.print(F("  Duration: "));
    Serial.print(durationMins);
    Serial.println(F(" min"));

    if (sdAvailable)
    {
        File logFile = SD.open(POWER_LOG_FILENAME, FILE_WRITE);
        if (logFile)
        {
            logFile.print(sessionDate);
            logFile.print(",");
            logFile.print(sessionStartStr);
            logFile.print(",");
            logFile.print(durationMins);
            logFile.print(",");
            logFile.print(avgVoltage, 2);
            logFile.print(",");
            logFile.print(avgCurrent, 3);
            logFile.print(",");
            logFile.println(totalEnergy, 4);
            logFile.close();
            Serial.println(F("[SD] Logged"));
        }
    }

    sessionActive = false;
    saveState();
    sessionStartEnergy = 0;
    sumVoltage = sumCurrent = sumPower = 0;
    pzemReadCount = 0;
    totalEnergy = 0;
}

// ============================================================
// SD CARD — SCHEDULE CACHE
// ============================================================
void saveScheduleCache()
{
    if (!sdAvailable) return;
    File f = SD.open(SCHEDULE_CACHE_FILENAME, FILE_WRITE);
    if (!f) return;
    f.println(F("H1,M1,H2,M2"));
    for (int i = 0; i < scheduleCount; i++)
    {
        f.print(schedule[i].startH); f.print(",");
        f.print(schedule[i].startM); f.print(",");
        f.print(schedule[i].endH);   f.print(",");
        f.println(schedule[i].endM);
    }
    f.close();
}

void loadScheduleCache()
{
    if (!sdAvailable) return;
    if (!SD.exists(SCHEDULE_CACHE_FILENAME)) return;
    File f = SD.open(SCHEDULE_CACHE_FILENAME, FILE_READ);
    if (!f) return;
    scheduleCount = 0;
    int idx = 0;
    bool firstLine = true;
    while (f.available() && idx < MAX_SLOTS)
    {
        String line = f.readStringUntil('\n');
        line.trim();
        if (line.length() == 0) continue;
        if (firstLine) { firstLine = false; continue; }
        int c1 = line.indexOf(',');
        if (c1 == -1) continue;
        int c2 = line.indexOf(',', c1 + 1);
        if (c2 == -1) continue;
        int c3 = line.indexOf(',', c2 + 1);
        if (c3 == -1) continue;
        schedule[idx].startH = (uint8_t)line.substring(0, c1).toInt();
        schedule[idx].startM = (uint8_t)line.substring(c1 + 1, c2).toInt();
        schedule[idx].endH   = (uint8_t)line.substring(c2 + 1, c3).toInt();
        schedule[idx].endM   = (uint8_t)line.substring(c3 + 1).toInt();
        idx++;
    }
    f.close();
    scheduleCount = idx;
    scheduleLoaded = (scheduleCount > 0);
    Serial.print(F("[SD] Loaded "));
    Serial.print(scheduleCount);
    Serial.println(F(" slot(s) from schedule cache"));
}

// ============================================================
// SD CARD — STATE PERSISTENCE
// ============================================================
void saveState()
{
    if (!sdAvailable) return;
    File f = SD.open(STATE_FILENAME, FILE_WRITE);
    if (!f) return;
    f.print(F("STATE=")); f.println((int)sysState);
    f.print(F("ROW1=")); f.println(row1State ? '1' : '0');
    f.print(F("ROW2=")); f.println(row2State ? '1' : '0');
    f.print(F("ROW3=")); f.println(row3State ? '1' : '0');
    f.print(F("SESSION=")); f.println(sessionActive ? '1' : '0');
    if (sessionActive)
    {
        f.print(F("SDATE=")); f.println(sessionDate);
        f.print(F("STIME=")); f.println(sessionStartStr);
        f.print(F("SY="));   f.println(sessionStartTime.year());
        f.print(F("SMO="));  f.println(sessionStartTime.month());
        f.print(F("SD="));   f.println(sessionStartTime.day());
        f.print(F("SH="));   f.println(sessionStartTime.hour());
        f.print(F("SMN="));  f.println(sessionStartTime.minute());
        f.print(F("SS="));   f.println(sessionStartTime.second());
        f.print(F("SV="));   f.println(sumVoltage, 4);
        f.print(F("SC="));   f.println(sumCurrent, 4);
        f.print(F("SP="));   f.println(sumPower, 4);
        f.print(F("TWH="));  f.println(totalEnergy, 4);
        f.print(F("SEN="));  f.println(sessionStartEnergy, 4);
        f.print(F("PCNT=")); f.println(pzemReadCount);
    }
    f.close();
}

void loadState()
{
    if (!sdAvailable) return;
    if (!SD.exists(STATE_FILENAME)) return;
    File f = SD.open(STATE_FILENAME, FILE_READ);
    if (!f) return;
    bool haveSession = false;
    int sY = 0, sMo = 1, sD = 1, sH = 0, sMn = 0, sS = 0;
    String sDate = "", sTime = "";
    double sSumV = 0, sSumC = 0, sSumP = 0, sTotWh = 0, sSEn = 0;
    int sCnt = 0;
    while (f.available())
    {
        String line = f.readStringUntil('\n');
        line.trim();
        if (line.length() == 0) continue;
        int eqPos = line.indexOf('=');
        if (eqPos == -1) continue;
        String key = line.substring(0, eqPos);
        String val = line.substring(eqPos + 1);
        if (key == "STATE") sysState = (SystemState)val.toInt();
        else if (key == "ROW1") { row1State = (val == "1"); digitalWrite(ROW1_PIN, row1State ? HIGH : LOW); }
        else if (key == "ROW2") { row2State = (val == "1"); digitalWrite(ROW2_PIN, row2State ? HIGH : LOW); }
        else if (key == "ROW3") { row3State = (val == "1"); digitalWrite(ROW3_PIN, row3State ? HIGH : LOW); }
        else if (key == "SESSION") haveSession = (val == "1");
        else if (key == "SDATE") sDate = val;
        else if (key == "STIME") sTime = val;
        else if (key == "SY")   sY = val.toInt();
        else if (key == "SMO")  sMo = val.toInt();
        else if (key == "SD")   sD = val.toInt();
        else if (key == "SH")   sH = val.toInt();
        else if (key == "SMN")  sMn = val.toInt();
        else if (key == "SS")   sS = val.toInt();
        else if (key == "SV")   sSumV = val.toDouble();
        else if (key == "SC")   sSumC = val.toDouble();
        else if (key == "SP")   sSumP = val.toDouble();
        else if (key == "TWH")  sTotWh = val.toDouble();
        else if (key == "SEN")  sSEn = val.toDouble();
        else if (key == "PCNT") sCnt = val.toInt();
    }
    f.close();
    Serial.println(F("[SD] State restored from SD"));
    if (haveSession && sY >= 2025)
    {
        sessionActive = true;
        sessionStartTime = DateTime(sY, sMo, sD, sH, sMn, sS);
        sessionDate = sDate;
        sessionStartStr = sTime;
        sumVoltage = sSumV;
        sumCurrent = sSumC;
        sumPower = sSumP;
        totalEnergy = sTotWh;
        sessionStartEnergy = sSEn;
        pzemReadCount = sCnt;
        Serial.println(F("[SD] Active session restored — reconcile needed"));
        sendSessionReconcile();
    }
}

void sendSessionReconcile()
{
    double avgV = (pzemReadCount > 0) ? (sumVoltage / pzemReadCount) : 0;
    double avgC = (pzemReadCount > 0) ? (sumCurrent / pzemReadCount) : 0;
    char buf[160];
    snprintf(buf, sizeof(buf), "RECONCILE:%s,%s,%.2f,%.3f,%.4f,%d",
             sessionDate.c_str(), sessionStartStr.c_str(),
             avgV, avgC, totalEnergy, pzemReadCount);
    Serial2.println(buf);
    Serial.println(F("[SD] Reconcile sent to ESP32"));
}
