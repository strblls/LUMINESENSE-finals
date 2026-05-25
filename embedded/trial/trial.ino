/*
  Serial-Controlled 12V LED Strip — Independent Row Control
  Each row toggles independently without affecting others.

  Commands:
  '1' -> Toggle Row 1 ON/OFF
  '2' -> Toggle Row 2 ON/OFF
  '3' -> Toggle Row 3 ON/OFF
  '0' -> All rows OFF
  '4' -> All rows ON
  's' -> Status of all rows
*/

const int LED_STRIP_1 = 2;
const int LED_STRIP_2 = 3;
const int LED_STRIP_3 = 4;

// Track state of each row independently
bool row1State = false;
bool row2State = false;
bool row3State = false;

void setup() {
    Serial.begin(9600);

    pinMode(LED_STRIP_1, OUTPUT);
    pinMode(LED_STRIP_2, OUTPUT);
    pinMode(LED_STRIP_3, OUTPUT);

    allStripsOff();

    Serial.println("=== Independent Row Control ===");
    Serial.println("1 = Toggle Row 1");
    Serial.println("2 = Toggle Row 2");
    Serial.println("3 = Toggle Row 3");
    Serial.println("OFF = All OFF");
    Serial.println("ON  = All ON");
    Serial.println("s = Status");
    Serial.println("===============================");
}

void loop() {
    if (Serial.available() > 0) {
        char command = Serial.read();

        switch (command) {
            case '1':
                row1State = !row1State;
                digitalWrite(LED_STRIP_1, row1State ? HIGH : LOW);
                Serial.print("Row 1: ");
                Serial.println(row1State ? "ON" : "OFF");
                break;

            case '2':
                row2State = !row2State;
                digitalWrite(LED_STRIP_2, row2State ? HIGH : LOW);
                Serial.print("Row 2: ");
                Serial.println(row2State ? "ON" : "OFF");
                break;

            case '3':
                row3State = !row3State;
                digitalWrite(LED_STRIP_3, row3State ? HIGH : LOW);
                Serial.print("Row 3: ");
                Serial.println(row3State ? "ON" : "OFF");
                break;

            case 'OFF':
            case 'f':
                allStripsOff();
                Serial.println("All Rows OFF");
                break;

            case 'ON':
            case 'n':
                allStripsOn();
                Serial.println("All Rows ON");
                break;

            case 's':
            case 'S':
                printStatus();
                break;

            case '\n':
            case '\r':
                break;

            default:
                Serial.print("Unknown: ");
                Serial.println(command);
                break;
        }
    }
}

void allStripsOff() {
    digitalWrite(LED_STRIP_1, LOW);
    digitalWrite(LED_STRIP_2, LOW);
    digitalWrite(LED_STRIP_3, LOW);
    row1State = false;
    row2State = false;
    row3State = false;
}

void allStripsOn() {
    digitalWrite(LED_STRIP_1, HIGH);
    digitalWrite(LED_STRIP_2, HIGH);
    digitalWrite(LED_STRIP_3, HIGH);
    row1State = true;
    row2State = true;
    row3State = true;
}

void printStatus() {
    Serial.println("--- Status ---");
    Serial.print("Row 1: "); Serial.println(row1State ? "ON" : "OFF");
    Serial.print("Row 2: "); Serial.println(row2State ? "ON" : "OFF");
    Serial.print("Row 3: "); Serial.println(row3State ? "ON" : "OFF");
    Serial.println("--------------");
}