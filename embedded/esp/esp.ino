#include <WiFi.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include <ESPmDNS.h>        // add this
#include <HTTPClient.h>     // add this for schedule fetch

const char* ssid      = "capstone";
const char* password  = "luminesense";
const char* hostname  = "luminesense";   // → luminesense.local

WebServer server(80);

void handleToggle() {
    if (server.hasArg("plain")) {
        String body = server.arg("plain");

        StaticJsonDocument<200> doc;
        deserializeJson(doc, body);

        String row   = doc["row"];
        String state = doc["state"];

        String cmd;
        if (row == "all") {
            cmd = "ALL:" + state;
        } else {
            cmd = "ROW" + row + ":" + state;
        }
        cmd.toUpperCase();

        Serial2.println(cmd);
        Serial.println("Sent to Mega: " + cmd);

        server.send(200, "application/json",
            "{\"status\":\"sent\",\"cmd\":\"" + cmd + "\"}");
    } else {
        server.send(400, "application/json", "{\"error\":\"no body\"}");
    }
}

void fetchScheduleFromXAMPP() {
    if (WiFi.status() != WL_CONNECTED) return;

    HTTPClient http;
    // Use your PC's local IP — PC runs XAMPP, not ESP32
    // Find it via: ipconfig → IPv4 Address
    http.begin("http://192.168.1.XXX/LUMINESENSE-finals/api/get_schedule.php");
    int httpCode = http.GET();

    if (httpCode == 200) {
        String payload = http.getString();
        Serial2.println("SCHEDULE:" + payload);
        Serial.print("[ESP32] Schedule forwarded: ");
        Serial.println(payload);
    } else {
        Serial.print("[ESP32] Schedule fetch failed: ");
        Serial.println(httpCode);
    }
    http.end();
}

void handleMegaRequests() {
    if (Serial2.available()) {
        String cmd = Serial2.readStringUntil('\n');
        cmd.trim();

        if (cmd == "FETCH:SCHEDULE") {
            fetchScheduleFromXAMPP();
        }
        // toggle commands are handled via HTTP POST to /toggle
        // so no extra handling needed here for those
    }
}

void setup() {
    Serial.begin(115200);
    Serial2.begin(4800);

    // Connect to WiFi
    WiFi.begin(ssid, password);
    Serial.print("Connecting to WiFi");
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED) {
        delay(500);
        Serial.print(".");
        attempts++;
        if (attempts > 20) {
            Serial.println("\nFailed to connect!");
            return;
        }
    }
    Serial.println("\nConnected!");
    Serial.print("IP: ");
    Serial.println(WiFi.localIP());   // still prints for reference

    // Start mDNS — ESP32 is now reachable at luminesense.local
    if (!MDNS.begin(hostname)) {
        Serial.println("[mDNS] Failed to start!");
    } else {
        Serial.println("[mDNS] Started — reachable at luminesense.local");
    }

    // Register HTTP service so mDNS advertises it
    MDNS.addService("http", "tcp", 80);

    server.on("/toggle", HTTP_POST, handleToggle);
    server.begin();
    Serial.println("ESP32 server started at http://luminesense.local");
}

void loop() {
    server.handleClient();
    handleMegaRequests();
}