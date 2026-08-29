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
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <WiFiManager.h>
#include <time.h>
#include <sys/time.h>
#include <Preferences.h>
// Async server for near-zero latency lighting control (replaces any WebServer.h)
#include <AsyncTCP.h>
#include <ESPAsyncWebServer.h>

// ── Fallback WiFi credentials ──────────────────────────────
// If WiFiManager settings are reset, ESP32 tries these in order.
const char* FALLBACK_SSIDS[] = {
    "LAPTOP-UOHVHQ1N 0251",
};
const char* FALLBACK_PASS[] = {
    "betet2027",
};
#define NUM_FALLBACKS (sizeof(FALLBACK_SSIDS) / sizeof(FALLBACK_SSIDS[0]))

// ── Server configuration (runtime-configurable) ─────────────
// Production default (Hostinger). To develop against the laptop's XAMPP
// hotspot instead, open the WiFiManager portal and set:
//   server        = http://192.168.137.1/LUMINESENSE-finals
//   esp_token     = ESP32 query token (?token=...)     [default LS_ESP32_TOKEN_2025]
//   device_token  = Device header token (X-Device-Token) [default luminesense-secret-token]
//   classroom_id  = classroom row to poll/drive        [default 3]
// Values are persisted in NVS and survive reboots. NOTE: the saved NVS value
// overrides this compiled default, so after changing the default you must also
// type `resetcfg` in the Serial Monitor (clears NVS) or update the portal.
char serverBase[160] = "https://luminesense-bet.site";
char espToken[64]    = "LS_ESP32_TOKEN_2025";
char deviceToken[64] = "luminesense-secret-token";
int  classroomId     = 3;

Preferences cfgPrefs;

void loadConfig() {
    cfgPrefs.begin("lumi-cfg", true);
    String s = cfgPrefs.getString("server", "");
    if (s.length() > 0) { strncpy(serverBase, s.c_str(), sizeof(serverBase) - 1); serverBase[sizeof(serverBase) - 1] = '\0'; }
    s = cfgPrefs.getString("esp_token", "");
    if (s.length() > 0) { strncpy(espToken, s.c_str(), sizeof(espToken) - 1); espToken[sizeof(espToken) - 1] = '\0'; }
    s = cfgPrefs.getString("device_token", "");
    if (s.length() > 0) { strncpy(deviceToken, s.c_str(), sizeof(deviceToken) - 1); deviceToken[sizeof(deviceToken) - 1] = '\0'; }
    classroomId = cfgPrefs.getInt("classroom_id", 3);
    cfgPrefs.end();
}

void saveConfig(const char* server, const char* espTok, const char* devTok, int cid) {
    cfgPrefs.begin("lumi-cfg", false);
    if (server && strlen(server) > 0) cfgPrefs.putString("server", server);
    if (espTok && strlen(espTok) > 0) cfgPrefs.putString("esp_token", espTok);
    if (devTok && strlen(devTok) > 0) cfgPrefs.putString("device_token", devTok);
    cfgPrefs.putInt("classroom_id", cid);
    cfgPrefs.end();
    loadConfig();
}

// URL builders — built at call time so config changes take effect immediately.
String toggleUrl()       { return String(serverBase) + "/api/esp32-light-status.php?token=" + espToken + "&classroom_id=" + classroomId; }
String scheduleUrl()     { return String(serverBase) + "/api/esp32-schedule.php?token=" + espToken + "&classroom_id=" + classroomId; }
String scheduleFlagUrl() { return String(serverBase) + "/api/esp32-schedule-flag.php?token=" + espToken + "&classroom_id=" + classroomId; }
String configUrl()       { return String(serverBase) + "/api/esp32-config.php?token=" + espToken; }
String timeUrl()         { return String(serverBase) + "/api/esp32-time.php?token=" + espToken; }
String pzemPostUrl()     { return String(serverBase) + "/api/pzem_push.php"; }
String updateRowsUrl()   { return String(serverBase) + "/api/esp32-update-rows.php"; }
String pirLogUrl()       { return String(serverBase) + "/api/pir-log.php"; }
String tiltLogUrl()      { return String(serverBase) + "/api/tilt-log.php"; }
String sessionUrl()      { return String(serverBase) + "/api/post_session.php"; }
String archiveSyncUrl()  { return String(serverBase) + "/api/archive-sync.php"; }

// HTTP begin that supports both plain HTTP and HTTPS. Uses WiFiClientSecure
// with setInsecure() so any valid TLS host (e.g. Hostinger) can be reached.
WiFiClientSecure secureHttpClient;
WiFiClient       plainHttpClient;

bool beginHttp(HTTPClient& http, const String& url) {
    if (url.startsWith("https://")) {
        secureHttpClient.setInsecure();
        return http.begin(secureHttpClient, url);
    }
    return http.begin(plainHttpClient, url);
}

// ── Pin Definitions ────────────────────────────────────────
#define ROW1_PIN 25
#define ROW2_PIN 26
#define ROW3_PIN 27
#define PIR_PIN  13

// ── Serial2 to Mega ────────────────────────────────────────
// GPIO16/17 were damaged by 5V from Mega TX — moved to GPIO4/2
#define MEGA_RX 4   // ESP32 RX (was 16)
#define MEGA_TX 2   // ESP32 TX (was 17)

// ── Async WebServer + WebSocket (headless network-to-serial bridge) ─
// No WebServer.h — strictly AsyncTCP + ESPAsyncWebServer
AsyncWebServer server(80);      // async HTTP server on port 80
AsyncWebSocket ws("/ws");       // WebSocket endpoint at ws://192.168.137.200/ws

// ── HTTP busy flag ─────────────────────────────────────────
bool httpBusy = false;

// ── Pending work flags ─────────────────────────────────────
// Instead of calling HTTP directly from handleMegaMessages,
// set a flag and let the loop handle it when HTTP is free
String pendingPzem          = "";
String esp32Buffer = "";
bool   pendingScheduleFetch = false;
int    pendingPirLog        = -1;  // -1 = none, 0/1 = state to log
int    pendingTiltLog       = -1;  // -1 = none, 0/1 = tilt state to log
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

// ── Schedule cache (mirrored from Mega for local PIR timeout) ──
#define MAX_SLOTS 10
struct Slot { int startH, startM, endH, endM; };
Slot schedSlots[MAX_SLOTS];
int  schedSlotCount = 0;

// ── Row State ──────────────────────────────────────────────
bool row1State = false;
bool row2State = false;
bool row3State = false;
bool lightOverride = false;

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
unsigned long lastNtpResync = 0;
#define FLAG_POLL_MS 3000
#define DB_POLL_MS        800  // faster remote reflection: was 2000ms, now ~0.8s for near-instant via luminesense-bet.site
#define SCHEDULE_FETCH_MS 30000
#define CONFIG_FETCH_MS   300000
#define PIR_INACTIVITY_MS 300000ul // 5 minutes
#define NTP_RESYNC_MS     3600000ul // 1 hour

// ── Async WebSocket helpers (instant Serial2 forwarding) ────
// Forward a lighting command to Mega with zero HTTP overhead — the fast path
inline void forwardToMega(const String &cmd) {
    Serial2.println(cmd); // instant, non-blocking
    Serial.print(F("[WS->MEGA] ")); Serial.println(cmd);
}

// Broadcast current row state to all WS clients (for externally-hosted UI sync)
void broadcastState() {
    StaticJsonDocument<128> doc;
    doc["type"] = "state";
    doc["row1"] = row1State;
    doc["row2"] = row2State;
    doc["row3"] = row3State;
    doc["override"] = lightOverride;
    String out; serializeJson(doc, out);
    ws.textAll(out);
}

// WebSocket event handler — listens for WS_TEXT (e.g. ALL:ON) and bridges to Mega
void onWsEvent(AsyncWebSocket *srv, AsyncWebSocketClient *client, AwsEventType type, void *arg, uint8_t *data, size_t len) {
    if (type == WS_EVT_CONNECT) {
        Serial.printf("[WS] Client #%u connected from %s\n", client->id(), client->remoteIP().toString().c_str());
        // Send current state instantly so externally-hosted UI is in sync
        StaticJsonDocument<128> doc;
        doc["type"] = "state";
        doc["row1"] = row1State;
        doc["row2"] = row2State;
        doc["row3"] = row3State;
        doc["override"] = lightOverride;
        String out; serializeJson(doc, out);
        client->text(out);
    } else if (type == WS_EVT_DISCONNECT) {
        Serial.printf("[WS] Client #%u disconnected\n", client->id());
    } else if (type == WS_EVT_DATA) {
        AwsFrameInfo *info = (AwsFrameInfo*)arg;
        if (info->final && info->index == 0 && info->len == len && info->opcode == WS_TEXT) {
            data[len] = 0;
            String cmd = String((char*)data);
            cmd.trim();
            if (cmd.length() == 0) return;
            String upper = cmd; upper.toUpperCase(); // Mega expects ALL:ON, ROW1:OFF
            forwardToMega(upper); // near-zero latency bridge — this is the instant path
            ws.textAll(upper); // echo to all browsers for sync
        }
    }
}

// UI hosted externally (luminesense-bet.site) + local health probe — ESP remains remote-hosted but adds instant WS
const char INDEX_HTML[] PROGMEM = R"rawliteral(
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>LumineSense — Lights</title>
<style>body{font-family:system-ui,sans-serif;max-width:480px;margin:2rem auto;padding:0 1rem}button{padding:.8rem 1.2rem;margin:.3rem;font-size:1rem;border:0;border-radius:.5rem;cursor:pointer}
.on{background:#16a34a;color:#fff}.off{background:#dc2626;color:#fff}.row{display:flex;gap:.5rem;align-items:center;margin:.6rem 0}
#log{font:12px monospace;background:#111;color:#0f0;padding:.6rem;height:160px;overflow:auto;white-space:pre-wrap}</style>
</head><body>
<h2>LumineSense — Instant Lighting (Local)</h2>
<div class="row"><b>ALL</b> <button class="on" onclick="send('ALL:ON')">ON</button> <button class="off" onclick="send('ALL:OFF')">OFF</button></div>
<div class="row"><b>ROW1</b> <button class="on" onclick="send('ROW1:ON')">ON</button> <button class="off" onclick="send('ROW1:OFF')">OFF</button></div>
<div class="row"><b>ROW2</b> <button class="on" onclick="send('ROW2:ON')">ON</button> <button class="off" onclick="send('ROW2:OFF')">OFF</button></div>
<div class="row"><b>ROW3</b> <button class="on" onclick="send('ROW3:ON')">ON</button> <button class="off" onclick="send('ROW3:OFF')">OFF</button></div>
<div id="status">WS: connecting...</div>
<pre id="log"></pre>
<script>
let ws, statusEl=document.getElementById('status'), logEl=document.getElementById('log');
function log(m){ logEl.textContent += m+"\n"; logEl.scrollTop=logEl.scrollHeight; }
function connect(){
  const proto=location.protocol==='https:'?'wss://':'ws://';
  ws=new WebSocket(proto+location.host+'/ws');
  ws.onopen=()=>{ statusEl.textContent='WS: connected'; log('WS open'); };
  ws.onclose=()=>{ statusEl.textContent='WS: disconnected — retrying...'; log('WS close'); setTimeout(connect,1500); };
  ws.onerror=(e)=> log('WS error');
  ws.onmessage=(e)=> log('<< '+e.data);
}
function send(cmd){ if(ws&&ws.readyState===1){ ws.send(cmd); log('>> '+cmd);} else log('WS not ready'); }
connect();
</script>
</body></html>
)rawliteral";

void initAsyncServer() {
    ws.onEvent(onWsEvent);          // attach WS event handler
    server.addHandler(&ws);         // mount /ws on async server
    // Keep UI locally for diagnostics; remote UI is on luminesense-bet.site (DB polling remains the internet path)
    server.on("/", HTTP_GET, [](AsyncWebServerRequest *request){
        request->send_P(200, "text/html", INDEX_HTML);
    });
    server.on("/health", HTTP_GET, [](AsyncWebServerRequest *request){
        request->send(200, "application/json", "{\"ok\":true}");
    });
    server.begin(); // non-blocking — no handleClient() needed
    Serial.println(F("[HTTP] AsyncWebServer on :80 + WS /ws (remote-hosted + local instant)"));
}

// ── NVS time persistence ────────────────────────────────────
// NTP may be unreachable when the laptop hotspot has no internet.
// We persist the last-known epoch so the ESP32 keeps a usable clock
// across reboots, and fetch time from the local XAMPP server as a
// fallback source instead of depending on pool.ntp.org.
Preferences timePrefs;

void saveLastKnownEpoch(unsigned long epoch) {
    if (epoch == 0) return;
    timePrefs.begin("lumi-time", false);
    timePrefs.putULong("epoch", epoch);
    timePrefs.end();
}

unsigned long loadLastKnownEpoch() {
    timePrefs.begin("lumi-time", true);
    unsigned long epoch = timePrefs.getULong("epoch", 0);
    timePrefs.end();
    return epoch;
}

// Fetch current epoch from the local server (api/esp32-time.php).
bool fetchTimeFromServer() {
    if (WiFi.status() != WL_CONNECTED) return false;
    HTTPClient http;
    http.setTimeout(5000);
    String url = timeUrl();
    beginHttp(http, url);
    int code = http.GET();
    bool ok = false;
    if (code == HTTP_CODE_OK) {
        String payload = http.getString();
        StaticJsonDocument<256> doc;
        DeserializationError err = deserializeJson(doc, payload);
        if (!err && doc["success"] == true && doc["epoch"].is<unsigned long>()) {
            unsigned long epoch = doc["epoch"].as<unsigned long>();
            if (epoch > 1000000000ul) { // sanity: year > 2001
                struct timeval tv = { (time_t)epoch, 0 };
                settimeofday(&tv, NULL);
                saveLastKnownEpoch(epoch);
                ok = true;
                Serial.println(F("[TIME] Synced from local server"));
            }
        }
    }
    http.end();
    return ok;
}

// Restore the last-known time from NVS (offline boot fallback).
bool restoreSavedTime() {
    unsigned long epoch = loadLastKnownEpoch();
    if (epoch > 1000000000ul) {
        struct timeval tv = { (time_t)epoch, 0 };
        settimeofday(&tv, NULL);
        Serial.println(F("[TIME] Restored saved time from NVS"));
        return true;
    }
    return false;
}

// Try to (re)establish the clock: NTP first, then local server, then NVS.
void ensureTime() {
    struct tm timeinfo;
    if (getLocalTime(&timeinfo)) return; // clock already valid
    if (fetchTimeFromServer()) return;
    if (restoreSavedTime()) return;
    Serial.println(F("[TIME] No time source available"));
}

// ============================================================
// SETUP
// ============================================================
void setup() {
    Serial.begin(115200);
    delay(1000);

    // Load persisted server/token config before WiFiManager may change it.
    loadConfig();

    // Serial2 to Mega — 115200 for near-zero latency (Mega must also use 115200 on Serial2)
    Serial2.begin(115200, SERIAL_8N1, MEGA_RX, MEGA_TX);
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
    // Force clean STA state before any WiFi work. Keep DHCP (remote-hosted) — no static IP
    WiFi.mode(WIFI_STA);
    WiFi.persistent(false);
    WiFi.disconnect(true);
    delay(500);

    WiFiManager wm;
    // wm.resetSettings(); // uncomment to forget saved WiFi
    wm.setConfigPortalTimeout(180);
    wm.setConnectTimeout(30);
    wm.setCleanConnect(true); // let WM also clean state before its own begin()

    // Runtime server config fields (shown in the config portal).
    char cidDefault[8];
    snprintf(cidDefault, sizeof(cidDefault), "%d", classroomId);
    WiFiManagerParameter customServer("server", "Server base URL", serverBase, sizeof(serverBase));
    WiFiManagerParameter customEspTok("esp_token", "ESP32 query token (?token=)", espToken, sizeof(espToken));
    WiFiManagerParameter customDevTok("device_token", "Device header token (X-Device-Token)", deviceToken, sizeof(deviceToken));
    WiFiManagerParameter customCid("classroom_id", "Classroom ID", cidDefault, sizeof(cidDefault));
    wm.addParameter(&customServer);
    wm.addParameter(&customEspTok);
    wm.addParameter(&customDevTok);
    wm.addParameter(&customCid);

    Serial.println(F("[WiFi] Trying fallback credentials..."));
    bool connected = false;
    for (int i = 0; i < NUM_FALLBACKS && !connected; i++) {
        Serial.print(F("[WiFi] Attempting: "));
        Serial.println(FALLBACK_SSIDS[i]);
        WiFi.disconnect(true);
        delay(500);
        WiFi.mode(WIFI_STA);
        WiFi.begin(FALLBACK_SSIDS[i], FALLBACK_PASS[i]);
        int attempts = 0;
        while (WiFi.status() != WL_CONNECTED && attempts < 20) {
            delay(500);
            Serial.print(".");
            attempts++;
            wl_status_t st = WiFi.status();
            if (st == WL_CONNECT_FAILED) {
                Serial.println();
                Serial.println(F("[WiFi] WL_CONNECT_FAILED — wrong password?"));
                break;
            }
            if (st == WL_NO_SSID_AVAIL) {
                Serial.println();
                Serial.println(F("[WiFi] WL_NO_SSID_AVAIL — AP not found (is hotspot on? 2.4 GHz band enabled?)"));
                break;
            }
        }
        Serial.println();
        connected = (WiFi.status() == WL_CONNECTED);
        if (connected) {
            Serial.print(F("[WiFi] Connected to "));
            Serial.println(FALLBACK_SSIDS[i]);
        } else {
            Serial.print(F("[WiFi] Failed to connect to "));
            Serial.print(FALLBACK_SSIDS[i]);
            Serial.print(F(" status="));
            Serial.println((int)WiFi.status());
        }
    }

    if (!connected) {
        Serial.println(F("[WiFi] All fallbacks failed — starting WiFiManager portal..."));
        // Critical: fallback loop leaves STA in CONNECTING state on failure.
        // Without this cleanup WiFiManager's next WiFi.begin() fails with
        // "E wifi:sta is connecting, return error / cannot set config".
        WiFi.disconnect(true);
        delay(1000);
        WiFi.mode(WIFI_STA);
        connected = wm.autoConnect("LumineSense-Setup", "luminesense123");
        if (!connected) {
            // Clean again so loop()'s WiFi.reconnect() starts from IDLE
            WiFi.disconnect(true);
            delay(500);
            WiFi.mode(WIFI_STA);
        }
    }

    if (connected) {
        Serial.println();
        Serial.print(F("[WiFi] Connected! IP: "));
        Serial.println(WiFi.localIP());
        WiFi.setAutoReconnect(true);
        delay(500);

        // Persist whatever the portal fields hold now (changed fields win;
        // defaults are saved when the portal was never shown).
        saveConfig(customServer.getValue(), customEspTok.getValue(),
                   customDevTok.getValue(), atoi(customCid.getValue()));
        Serial.print(F("[CFG] server="));     Serial.println(serverBase);
        Serial.print(F("[CFG] classroom_id=")); Serial.println(classroomId);

        fetchAndForwardSchedule();
        fetchAndForwardConfig();

        // NTP time sync — send accurate time to Mega. NTP first, then fall
        // back to the local server / last-known time so schedule logic keeps
        // working even when the hotspot has no internet.
        configTime(8 * 3600, 0, "pool.ntp.org", "time.nist.gov");
        struct tm timeinfo;
        int ntpRetries = 0;
        while (!getLocalTime(&timeinfo) && ntpRetries < 20) {
            delay(500);
            ntpRetries++;
        }
        if (ntpRetries < 20) {
            saveLastKnownEpoch(mktime(&timeinfo));
            syncTimeToMega();
        } else {
            Serial.println(F("[NTP] Time sync failed — using local fallback"));
            ensureTime();
            if (getLocalTime(&timeinfo)) {
                syncTimeToMega();
            }
        }
    } else {
        Serial.println(F("[WiFi] Config portal timed out — running offline"));
    }

    // Start async server + WebSocket regardless of WiFi state (AP mode still serves UI)
    initAsyncServer();

    Serial.println(F("=== ESP32 Ready ==="));
}

// ============================================================
// MAIN LOOP
// ============================================================
void loop() {
    ws.cleanupClients(); // free closed WS clients — only blocking-safe call needed
    unsigned long now = millis();

    // Serial command: type "resetwifi" in Serial Monitor to clear saved WiFi
    if (Serial.available()) {
        String cmd = Serial.readStringUntil('\n');
        cmd.trim();
        if (cmd == "resetwifi") {
            WiFi.disconnect(true);
            WiFiManager wm;
            wm.resetSettings();
            Serial.println(F("[WiFi] Settings cleared. Restarting..."));
            delay(1000);
            ESP.restart();
        }
        if (cmd == "resetcfg") {
            cfgPrefs.begin("lumi-cfg", false);
            cfgPrefs.clear();
            cfgPrefs.end();
            Serial.println(F("[CFG] Runtime config cleared (server/token/classroom). Restarting..."));
            delay(1000);
            ESP.restart();
        }
    }

    // PIR inactivity timeout: no motion for 5 min during schedule → turn lights OFF
    if (pirOverrideActive && pirInactiveSince > 0 &&
        now - pirInactiveSince >= PIR_INACTIVITY_MS) {
        if (isWithinSchedule()) {
            Serial.println(F("[PIR] Inactivity timeout — sending ALL:OFF to Mega"));
            Serial2.println("ALL:OFF");
        } else {
            Serial.println(F("[PIR] Inactivity timeout — outside schedule, skipping"));
        }
        pirOverrideActive = false;
        pirInactiveSince = 0;
        pendingPirLog = 0;
    }

    // heartbeat print — once per 2s instead of every single loop
    static unsigned long lastHeartbeat = 0;
    static unsigned long lastReconnectAttempt = 0;
    if (now - lastHeartbeat >= 2000) {
        lastHeartbeat = now;
        Serial.print(F("[HEARTBEAT] busy="));
        Serial.print(httpBusy);
        Serial.print(F(" wifi="));
        if (WiFi.status() != WL_CONNECTED) {
            Serial.print(F("DISCONNECTED status="));
            Serial.print((int)WiFi.status());
            // Throttle reconnect: ESP-IDF needs time between attempts.
            // Spamming WiFi.reconnect() while still CONNECTING triggers
            // the same "sta is connecting" error seen at boot.
            if (now - lastReconnectAttempt >= 10000) {
                lastReconnectAttempt = now;
                Serial.print(F(" — reconnecting..."));
                // If stuck in CONNECTING for >10s, force clean restart
                if (WiFi.status() == WL_IDLE_STATUS || WiFi.status() == WL_DISCONNECTED) {
                    WiFi.reconnect();
                } else {
                    WiFi.disconnect(true);
                    delay(200);
                    WiFi.mode(WIFI_STA);
                    WiFi.reconnect();
                }
            } else {
                Serial.print(F(" (waiting)"));
            }
        } else {
            Serial.print(F("connected"));
        }
        Serial.print(F(" ip="));
        Serial.print(WiFi.localIP());
        Serial.print(F(" gateway="));
        Serial.println(WiFi.gatewayIP());
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
        } else if (pendingTiltLog != -1) {
            forwardTiltLog(pendingTiltLog);
            pendingTiltLog = -1;
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
        } else if (now - lastNtpResync >= NTP_RESYNC_MS) {
            lastNtpResync = now;
            // Persist the current time for offline reboots, and re-sync the
            // clock (NTP may still be unreachable — the local server is the
            // reliable fallback in that case).
            struct tm t;
            if (getLocalTime(&t)) saveLastKnownEpoch(mktime(&t));
            else ensureTime();
            syncTimeToMega();
        }
    }
}

// ============================================================
// SCHEDULE CHECK (local copy for PIR timeout)
// ============================================================
bool isWithinSchedule() {
    if (schedSlotCount == 0) return false;
    struct tm t;
    if (!getLocalTime(&t)) return false;
    int nowMins = t.tm_hour * 60 + t.tm_min;
    for (int i = 0; i < schedSlotCount; i++) {
        int startMins = schedSlots[i].startH * 60 + schedSlots[i].startM;
        int endMins   = schedSlots[i].endH   * 60 + schedSlots[i].endM;
        if (nowMins >= startMins && nowMins < endMins) return true;
    }
    return false;
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
                else if (msg == "LOG_TILT:1") {
                    pendingTiltLog = 1;  // manhandling alert → api/tilt-log.php
                }
                else if (msg == "LOG_TILT:0") {
                    pendingTiltLog = 0;  // sensor settled → log the clear
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
    beginHttp(http, toggleUrl());
    http.setTimeout(5000);
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
        bool newOverride = doc["light_override"] == 1;

        if (newR1 != row1State) { setRow(1, newR1); Serial2.println(newR1 ? "ROW1:ON" : "ROW1:OFF"); }
        if (newR2 != row2State) { setRow(2, newR2); Serial2.println(newR2 ? "ROW2:ON" : "ROW2:OFF"); }
        if (newR3 != row3State) { setRow(3, newR3); Serial2.println(newR3 ? "ROW3:ON" : "ROW3:OFF"); }
        if (newOverride != lightOverride) {
            lightOverride = newOverride;
            Serial2.println(lightOverride ? "LIGHT_OVERRIDE=1" : "LIGHT_OVERRIDE=0");
            Serial.print(F("[DB] light_override=")); Serial.println(lightOverride);
        }
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
    beginHttp(http, scheduleUrl());
    http.setTimeout(5000);
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        payload.trim();

        Serial.print(F("[SCHED] Payload: ")); Serial.println(payload);
        Serial.print(F("[SCHED] Length: "));  Serial.println(payload.length());

        if (payload.length() > 0) {
            // Parse schedule slots for local PIR timeout
            schedSlotCount = 0;
            String remaining = payload;
            while (remaining.length() > 0 && schedSlotCount < MAX_SLOTS) {
                int comma = remaining.indexOf(',');
                String slot = (comma == -1) ? remaining : remaining.substring(0, comma);
                remaining = (comma == -1) ? "" : remaining.substring(comma + 1);

                int dash = slot.indexOf('-', 3);
                if (dash == -1) continue;

                String startStr = slot.substring(0, dash);
                String endStr   = slot.substring(dash + 1);
                int colonS = startStr.indexOf(':');
                int colonE = endStr.indexOf(':');
                if (colonS == -1 || colonE == -1) continue;

                schedSlots[schedSlotCount].startH = startStr.substring(0, colonS).toInt();
                schedSlots[schedSlotCount].startM = startStr.substring(colonS + 1).toInt();
                schedSlots[schedSlotCount].endH   = endStr.substring(0, colonE).toInt();
                schedSlots[schedSlotCount].endM   = endStr.substring(colonE + 1).toInt();
                schedSlotCount++;
            }
            Serial.print(F("[SCHED] Cached ")); Serial.print(schedSlotCount); Serial.println(F(" slot(s) locally"));

            for (int i = 0; i < 3; i++) {
                Serial2.println("SCHEDULE:" + payload);
                delay(200);
            }
            Serial.println(F("[SCHED] Forwarded to Mega (3x)"));
        } else {
            schedSlotCount = 0;
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
    beginHttp(http, configUrl());
    http.setTimeout(5000);
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
        doc["classroom_id"] = classroomId;
    }

    String outJson;
    serializeJson(doc, outJson);

    HTTPClient http;
    beginHttp(http, pzemPostUrl());
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", deviceToken);

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

    String json = "{\"classroom_id\":" + String(classroomId) + ",\"state\":" + String(state) + "}";

    HTTPClient http;
    beginHttp(http, pirLogUrl());
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", deviceToken);

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
// FORWARD TILT LOG TO DATABASE
// ============================================================
void forwardTiltLog(int state) {
    if (WiFi.status() != WL_CONNECTED) return;
    if (httpBusy) return;
    httpBusy = true;

    String json = "{\"classroom_id\":" + String(classroomId) + ",\"state\":" + String(state) + "}";

    HTTPClient http;
    beginHttp(http, tiltLogUrl());
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", deviceToken);

    int httpCode = http.POST(json);
    if (httpCode == 200) {
        Serial.println(F("[TILT_LOG] Logged to DB OK"));
    } else {
        Serial.print(F("[TILT_LOG] Post failed, code: "));
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

    String json = "{\"classroom_id\":" + String(classroomId) + ",\"session_date\":\"" + sDate +
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
    beginHttp(http, sessionUrl());
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", deviceToken);

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
    beginHttp(http, updateRowsUrl());
    http.setTimeout(5000);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");

    String body = "token=" + String(espToken) + "&classroom_id=" + String(classroomId);
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
    beginHttp(http, scheduleFlagUrl());
    http.setTimeout(5000);
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

    archiveBatchPending = "{\"classroom_id\":" + String(classroomId) + ",\"archive_date\":\"" + archivePendingDate +
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
    beginHttp(http, archiveSyncUrl());
    http.setTimeout(10000);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-Device-Token", deviceToken);

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