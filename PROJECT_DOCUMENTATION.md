# LumineSense — Project Documentation

## Overview

LumineSense is a smart classroom lighting management system with gesture recognition, PIR-based occupancy detection, energy monitoring (PZEM-004T), and web-based scheduling. It integrates an Arduino Mega 2560, ESP32 NodeMCU, and a full-stack web application (PHP/MySQL frontend + Node.js/Python middleware).

**Production URL:** `https://luminesense-bet.site`  
**Local Dev URL:** `http://localhost/LUMINESENSE-finals/`

---

## Table of Contents

1. [Technology Stack](#1-technology-stack)
2. [Project Architecture](#2-project-architecture)
3. [Directory Structure](#3-directory-structure)
4. [Core Features & Data Flow](#4-core-features--data-flow)
5. [Newly Added Files & Changes](#5-newly-added-files--changes)
6. [API Endpoints Reference](#6-api-endpoints-reference)
7. [Database Schema](#7-database-schema)
8. [Embedded Firmware Summary](#8-embedded-firmware-summary)
9. [Configuration & Setup](#9-configuration--setup)
10. [Recent Commit History](#10-recent-commit-history)

---

## 1. Technology Stack

| Layer | Technology |
|-------|-----------|
| **Frontend** | HTML5, CSS3, Bootstrap 5.3, JavaScript (ES Modules) |
| **Backend** | PHP 8.2+, MySQL/MariaDB 10.4 |
| **Middleware** | Node.js 18+ with Express 5 |
| **Computer Vision** | Python 3.10+, Flask, OpenCV, MediaPipe, NumPy |
| **Embedded** | Arduino Mega 2560 (C++), ESP32 NodeMCU-32S (C++) |
| **Database** | MySQL/MariaDB via `luminesense_db` |
| **Email** | PHPMailer 7.1 via Hostinger SMTP |
| **ML/AI** | MediaPipe Gesture Recognizer |

---

## 2. Project Architecture

```
[Web Browser]
    |---> Bootstrap 5.3 UI served by PHP (XAMPP/Apache)
    |---> AJAX polling (every 3s) to PHP API endpoints
    |---> MJPEG stream via Node.js :3000 --> Python Flask :5000 (gesture camera)
    |---> Manual light toggles --> api/lights.php --> classrooms table

[Node.js Server :3000]
    |---> /gesture/* ---> spawns/kills Python Flask gesture server
    |---> /lighting/toggle ---> proxies to Python Flask :5001

[Python Flask :5000 - gesture-control.py]
    |---> MediaPipe gesture recognition via webcam
    |---> Sends POST to api/lights.php (triggered_by=gesture)
    |---> Streams MJPEG over HTTP to browser

[Python Flask :5001 - lighting.py]
    |---> Receives toggle commands from Node.js
    |---> Forwards to ESP32 at http://luminesense.local/toggle

[ESP32 NodeMCU-32S]
    |---> WiFiManager for network configuration
    |---> Polls XAMPP APIs every 3s for row states
    |---> Fetches schedule every 30s, checks dirty flag every 5s
    |---> Controls MOSFET gates (GPIO 26/27/25) for LED rows
    |---> Reads PIR sensor (GPIO 13), 2s debounce
    |---> Serial2 bridge to Arduino Mega (GPIO16 RX / GPIO17 TX)

[Arduino Mega 2560]
    |---> State machine: OUTSIDE / SCHEDULED / COOLDOWN / LOCKED
    |---> Reads PZEM-004T V3.0 for power metrics (Serial1)
    |---> DS3231 RTC (I2C) for schedule-aware control
    |---> Logs power sessions to SD card CSV
    |---> Commands ESP32 via Serial2
```

---

## 3. Directory Structure

```
LUMINESENSE-finals/
├── index.php                          # Landing page (Faculty/Admin login choice)
├── README.md                          # Development task tracking
├── composer.json / composer.lock      # PHP dependencies (PHPMailer)
├── newsqlhere.sql                     # Full database dump (June 27, 2026)
├── gesture_recognizer.task            # MediaPipe ML model
├── gesture-control.py                 # Python Flask gesture server (port 5000)
├── lighting.py                        # Python Flask lighting relay (port 5001)
│
├── api/                               # 28 PHP REST API endpoints
│   ├── accounts.php                   # Faculty approval CRUD
│   ├── admin-status.php               # Admin dashboard live data
│   ├── ajaz-live-pzem.php             # Live PZEM poll (analytics)
│   ├── analytics.php                  # Energy analytics (7 data sections)
│   ├── auto-approve-extensions.php    # Cron: auto-approve extensions
│   ├── change_password.php            # Password change
│   ├── check-room-successor.php       # Check next schedule in room
│   ├── classrooms.php                 # Classroom CRUD
│   ├── esp32-schedule-flag.php        # Schedule dirty flag (ESP32 poll)
│   ├── esp32-schedule.php             # Schedule text (ESP32 poll)
│   ├── esp32-status.php               # Row states as integers (ESP32)
│   ├── esp32-update-rows.php          # Row state push from ESP32
│   ├── faculty-status.php             # Faculty dashboard live data
│   ├── lights.php                     # Toggle lights (row/all)
│   ├── live-pzem.php                  # Live PZEM readings (dashboard)
│   ├── logs.php                       # Lighting event logs
│   ├── permissions.php                # Faculty permissions toggle
│   ├── pin.php                        # Faculty PIN save/verify/change
│   ├── pir.php                        # PIR sensor webhook
│   ├── post_pzem.php                  # PZEM reading POST (from Mega)
│   ├── post_session.php               # Session POST (from Mega)
│   ├── pzem_push.php                  # Live PZEM upsert (device-token secured)
│   ├── pzem-update.php                # Legacy PZEM update
│   ├── request-extension.php          # Submit extension requests
│   ├── schedules.php                  # Schedule CRUD
│   └── status.php                     # All-classroom status snapshot
│
├── css/                               # 22 CSS stylesheets
│   ├── global.css                     # Base reset & shared utilities
│   ├── landing.css                    # Landing page
│   ├── containers.css                 # Shared layout containers
│   ├── modals.css / tooltip.css       # Shared components
│   ├── registration.css               # Login/signup forms
│   ├── admin-*.css (10 files)         # Admin-specific pages
│   └── faculty-*.css (4 files)        # Faculty-specific pages
│
├── script/                            # 12 JavaScript files
│   ├── initialize-gesture.js          # MediaPipe client-side gesture (most complex)
│   ├── admin-analytics.js             # Analytics charts & live polling
│   ├── admin-timetable.js             # Schedule calendar interactions
│   ├── analytics-gauge.js             # SVG gauge component
│   ├── animations.js                  # Page dissolve transitions
│   ├── calendar-data.js               # Schedule calendar display
│   ├── faculty-notification.js        # Notification polling
│   ├── modals.js                      # Modal helpers + SweetAlert2
│   ├── password.js                    # Password strength meter
│   ├── toggles.js                     # Switch toggle logic
│   └── tooltip.js                     # Tooltip component
│
├── pages/                             # 25 PHP frontend pages
│   ├── admin-login.php / admin-signup.php
│   ├── faculty-login.php / faculty-signup.php
│   ├── verify-email.php / pending-approval.php
│   └── admin-home/ (10 files)         # Admin dashboard pages
│       ├── admin-homepage.php         # Main dashboard
│       ├── admin-room-manage.php      # Classroom CRUD
│       ├── admin-timetable-manage.php # Schedule management
│       ├── admin-faculty-management.php # Faculty management
│       ├── admin-faculty-review.php   # Faculty ID review
│       ├── admin-analytics.php        # Charts & analytics
│       ├── admin-reports.php          # Reports viewer
│       ├── admin-profile-settings.php # Admin profile
│       ├── admin-faculty-card.php     # Faculty detail card
│       ├── room-light-view.php        # Room lighting detail
│       └── ajax-room-data.php         # Room data endpoint
│   └── faculty-home/ (8 files)        # Faculty dashboard pages
│       ├── faculty-home.php           # Main dashboard + lighting controls
│       ├── faculty-timetable.php      # Weekly schedule
│       ├── faculty-timetable1.php      # Alternative timetable
│       ├── faculty-head-timetable.php # Dept head schedule mgmt
│       ├── faculty-head-membersched.php # Member schedule detail
│       ├── faculty-readings.php       # PZEM readings
│       └── faculty-profile-settings.php # Faculty profile
│
├── php/                               # 30 PHP backend files
│   ├── config.php                     # SMTP, tokens, admin code
│   ├── db_connect.php                 # DB connection + auto-schema
│   ├── mailer.php                     # PHPMailer wrapper
│   ├── logout.php                     # Session destroy
│   ├── session_guard.php              # check_admin() / check_faculty()
│   ├── admin-login-process.php / admin-signup-process.php
│   ├── faculty-login-process.php / faculty-signup-process.php
│   ├── change-password.php
│   ├── cron/auto-lights-off.php       # Cron: auto lights off
│   └── handlers/ (8 files)            # Business logic handlers
│       ├── admin-handlers.php         # Department CRUD
│       ├── admin-profile-handler.php  # Admin profile updates
│       ├── analytics-handler.php      # Analytics data prep
│       ├── faculty-approvals-handler.php # Approve/reject faculty
│       ├── faculty-head-handler.php   # Dept head operations
│       ├── lighting-handler.php       # Lighting event logging
│       ├── room-handler.php           # Classroom CRUD
│       └── schedule-handler.php       # Schedule CRUD
│   └── includes/ (8 files)            # Shared templates
│       ├── admin-head.php / admin-sidebar.php / admin-topbar.php
│       ├── faculty-head.php / faculty-sidebar.php / faculty-topbar.php
│       └── profile-offcanvas.php / f-profile-offcanvas.php
│
├── server/                            # Node.js Express server
│   ├── server.js                      # Express app (port 3000)
│   ├── package.json
│   └── routes/
│       ├── gesture.js                 # Gesture start/stop/status
│       └── lighting.js                # Lighting toggle proxy
│
├── embedded/                          # Arduino/ESP32 firmware
│   ├── main/main.ino                  # Arduino Mega 2560 (634 lines)
│   ├── esp/esp.ino                    # ESP32 NodeMCU-32S (407 lines)
│   └── libraries/                     # Bundled Arduino libraries
│       ├── ArduinoJson/
│       └── SD/
│
├── images/                            # Static assets
│   ├── logo.png, team-logo.png
│   ├── bulb-on.png, bulb-off.png
│   └── gesture icons (7 PNGs)
│
├── uploads/faculty_ids/              # Uploaded faculty ID images
└── vendor/                            # Composer dependencies (PHPMailer)
```

---

## 4. Core Features & Data Flow

### 4.1 Authentication & Role Management
- **Two roles:** Admin and Faculty
- Email OTP verification via PHPMailer (Hostinger SMTP)
- Admin must approve new faculty accounts
- Department heads can manage schedules for their department members

### 4.2 Lighting Control
- Three independent LED rows per classroom, each controlled via MOSFET gates (ESP32 GPIO 26/27/25)
- Toggle via web UI, gesture recognition, or PIR occupancy
- All toggles go through `api/lights.php` which updates `classrooms.rowX_status` and logs to `lighting_logs`

### 4.3 Gesture Recognition
- **Browser-side** (primary): MediaPipe GestureRecognizer via JS CDN, 6 gestures mapped to lighting actions
- **Python-side** (fallback): Flask server on port 5000, spawned by Node.js
- Gestures: `Pointing_Up` (Row1), `Victory` (Row2), `ILoveYou` (Row3), `Thumb_Up` (confirm), `Open_Palm` (all on), `Closed_Fist` (all off)
- Features: 900ms debounce, 3-frame confirmation buffer, 15s timeout, dropout tolerance (350ms), hysteresis thresholding

### 4.4 PIR Occupancy
- ESP32 reads PIR sensor (GPIO 13), 2s debounce
- Forwards to Mega via Serial2
- In `STATE_SCHEDULED`: PIR turns lights ON automatically
- In `STATE_COOLDOWN`: PIR can reset cooldown timer once

### 4.5 Energy Monitoring
- PZEM-004T V3.0 connected to Arduino Mega (Serial1)
- Reads every 6s: voltage, current, power, energy, frequency, power factor
- Mega streams JSON to ESP32 every 8s; ESP32 pushes to `pzem_push.php` via HTTP
- Live readings displayed on faculty dashboard, logged to `power_sessions`

### 4.6 Schedule Management
- Weekly schedules per classroom with day-of-week, start/end time
- Admin creates/edits schedules; Department heads can manage schedules for their faculty members
- ESP32 fetches schedule every 30s; Mega parses and runs state machine
- Extension requests allow faculty to extend current schedule (pending admin approval or auto-approved in grace period)

### 4.7 Extension Requests
- Faculty can request time extensions during active schedules
- Auto-approval if within grace period; otherwise pending admin review
- Checks for conflicting successor schedule before approving

---

## 5. Newly Added Files & Changes

### 5.1 New Files (Last 5 Commits)

| File | Type | Purpose |
|------|------|---------|
| `css/containers.css` | Stylesheet | Shared layout container styles (cards, panels, grid) |
| `css/faculty-head-timetable.css` | Stylesheet | Styling for department head timetable management |
| `pages/admin-home/admin-faculty-card.php` | PHP Page | Faculty detail card component with status badges and action buttons |
| `pages/faculty-home/faculty-head-membersched.php` | PHP Page | Department head view of individual member schedules |
| `pages/faculty-home/faculty-head-timetable.php` | PHP Page | Department head timetable management interface |
| `php/handlers/faculty-head-handler.php` | PHP Handler | Backend logic for department head operations (subject area/subject management, schedule CRUD for dept members) |
| `php/handlers/admin-handlers.php` | PHP Handler | Department CRUD logic (add/edit/delete departments, faculty-department assignments, subject area management) |
| `session-test.php` | Utility | Session debugging/test script |

### 5.2 Modified Files (Last 5 Commits)

| File | Changes Made |
|------|-------------|
| `README.md` | Updated task tracking table |
| `pages/admin-home/admin-faculty-management.php` | Integrated department management UI, faculty-department assignment, stat card live updates |
| `php/db_connect.php` | Hostinger deployment fixes, database name configuration, auto-schema with department and subject area tables |
| `newsqlhere.sql` | Full updated database dump with new tables (`departments`, `subject_area`, `subjects`, `junction_faculty_department`, `junction_faculty_subject`, `junction_faculty_subjectarea`) |

### 5.3 Feature Changes Summary (June 23–26)

1. **Department Head Role** — New `faculty-head-handler.php` allows department heads to:
   - Manage subject areas and subjects within their department
   - Create/update/delete schedules for faculty members in their department
   - View member schedules in `faculty-head-membersched.php`

2. **Department Management** — New `admin-handlers.php` enables admins to:
   - Create, edit, and delete departments
   - Assign faculty to departments
   - Manage subject areas per department
   - Faculty management page now reflects department assignments in stat cards

3. **Faculty Card Component** — `admin-faculty-card.php` provides a reusable:
   - Faculty detail card with profile info
   - Department and subject area badges
   - Quick action buttons (approve/reject/delete)

4. **Database Expansion** — Six new junction tables added: `departments`, `subject_area`, `subjects`, `junction_faculty_department`, `junction_faculty_subject`, `junction_faculty_subjectarea`

5. **Hostinger Deployment Fixes** — `db_connect.php` updated with:
   - Automatic localhost vs production detection
   - Database name configuration
   - Auto-CREATE TABLE for all new tables

---

## 6. API Endpoints Reference

### 6.1 Classroom & Status
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/classrooms.php` | GET/POST | List classrooms (with schedule info) / Add/Delete |
| `api/status.php` | GET | All classrooms with light status + active schedule |
| `api/admin-status.php` | GET | Admin dashboard counts + recent activity |
| `api/faculty-status.php` | GET | Faculty dashboard: room status, active schedule, recent logs |

### 6.2 Lighting Control
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/lights.php` | POST | Toggle row(s) on/off, logs event |
| `api/pir.php` | POST | PIR sensor webhook (needs `token=LS_PIR_TOKEN_2025`) |
| `api/logs.php` | GET/POST | Read/write lighting event logs |

### 6.3 ESP32 Communication
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `api/esp32-status.php` | GET | `?token=LS_ESP32_TOKEN_2025` | Row states as integers |
| `api/esp32-schedule.php` | GET | `?token=LS_ESP32_TOKEN_2025` | Today's schedule, comma-separated |
| `api/esp32-schedule-flag.php` | GET | `?token=LS_ESP32_TOKEN_2025` | `{dirty: bool}` |
| `api/esp32-update-rows.php` | POST | `?token=LS_ESP32_TOKEN_2025` | Push row states from ESP32 |

### 6.4 Power Monitoring
| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `api/post_pzem.php` | POST | — | Insert PZEM reading + update classroom + manage sessions |
| `api/pzem_push.php` | POST | `X-Device-Token` | Upsert into `pzem_live` table |
| `api/live-pzem.php` | GET | — | Live PZEM readings for dashboard |
| `api/ajaz-live-pzem.php` | GET | — | Live PZEM poll for analytics |
| `api/post_session.php` | POST | — | Session summary from Mega |

### 6.5 Faculty Management
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/accounts.php` | GET/POST | List faculty / Approve/Reject/Revoke/Delete |
| `api/permissions.php` | POST | Toggle `lighting_control` / `gesture_control` |

### 6.6 Schedules & Extensions
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/schedules.php` | GET/POST | Schedule CRUD with overlap detection |
| `api/request-extension.php` | POST | Submit extension request |
| `api/auto-approve-extensions.php` | GET | Cron: auto-approve pending extensions |
| `api/check-room-successor.php` | GET | Check if successor schedule exists |

### 6.7 Authentication
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/change_password.php` | POST | Admin password change with bcrypt verify |
| `api/pin.php` | GET/POST | Faculty PIN: save/verify/change |

### 6.8 Analytics
| Endpoint | Method | Description |
|----------|--------|-------------|
| `api/analytics.php` | GET | 7-section analytics: summary, daily energy, heatmap, trigger breakdown, per-room, per-session, active |

### 6.9 Node.js Routes
| Endpoint | Method | Description |
|----------|--------|-------------|
| `POST /gesture/start` | POST | Spawns Python Flask gesture server |
| `GET /gesture/status` | GET | Proxy to Flask status |
| `POST /gesture/stop` | POST | Kills Flask process |
| `POST /lighting/toggle` | POST | Proxy toggle to Flask lighting relay |

---

## 7. Database Schema

### Tables (22 total)

| Table | Purpose |
|-------|---------|
| `admins` | System administrators |
| `faculty` | Faculty members |
| `classrooms` | Rooms with lighting state & PZEM readings |
| `schedules` | Class schedules per room |
| `lighting_logs` | Every light toggle event |
| `extension_requests` | Schedule extension requests |
| `faculty_permissions` | Feature access (lighting/gesture control) |
| `power_sessions` | Energy usage session summaries |
| `pzem_readings` | Raw PZEM-004T data points |
| `pzem_live` | Single-row-per-room live readings |
| `admin_logs` | Admin action audit trail |
| `admin_login_logs` | Admin login history |
| `departments` | Academic departments |
| `subjects` | Subject master list |
| `subject_area` | Subject area groupings per department |
| `junction_faculty_department` | Many-to-many faculty↔department |
| `junction_faculty_subject` | Many-to-many faculty↔subject |
| `junction_faculty_subjectarea` | Many-to-many faculty↔subject_area |
| `system_settings` | Key-value configuration |
| `id_review_queue` | Faculty ID review queue |
| `id_review_access_log` | ID review access tracking |

**Auto-schema:** `php/db_connect.php` creates/updates all tables automatically via `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE ADD COLUMN IF NOT EXISTS` on every include.

---

## 8. Embedded Firmware Summary

### Arduino Mega 2560 (`embedded/main/main.ino`)
- **State Machine:** OUTSIDE → SCHEDULED → COOLDOWN → LOCKED
- **Schedule Parsing:** Receives `SCHEDULE:` commands from ESP32, e.g., `SCHEDULE:08:00-10:00,13:00-15:30`
- **PZEM:** Reads every 6s via Serial1 (Modbus-like protocol)
- **RTC:** DS3231 for schedule-aware logic (I2C)
- **SD Card Logging:** Writes `power_log.csv` with session data
- **Serial2:** Bidirectional bridge to ESP32 (JSON packets + command tokens)

### ESP32 NodeMCU-32S (`embedded/esp/esp.ino`)
- **WiFi:** WiFiManager library, AP mode `LumineSense-Setup` / `luminesense123`
- **HTTP Polling:** Every 3s for row states, 30s for schedule, 5s for dirty flag
- **GPIO:** MOSFET gates on 26/27/25, PIR on 13
- **PIR:** 2s debounce, forwards `PIR:ON` / `PIR:OFF` to Mega
- **PZEM Relay:** Forwards Mega JSON to `api/pzem_push.php` with device token
- **mDNS:** Responds to `http://luminesense.local`

---

## 9. Configuration & Setup

### 9.1 Key Constants (`php/config.php`)

```php
define('MAIL_HOST', 'smtp.hostinger.com');
define('MAIL_USERNAME', 'lumi-admin@luminesense-bet.site');
define('MAIL_PASSWORD', 'Luminesense123!');
define('VALID_ADMIN_CODE', 'LUMINESENSE_ADMIN_2025');
define('DEVICE_TOKEN', 'luminesense-secret-token');
```

### 9.2 API Auth Tokens

| Token | Value | Used By |
|-------|-------|---------|
| ESP32 Token | `LS_ESP32_TOKEN_2025` | Query param `?token=` |
| Device Token | `luminesense-secret-token` | Header `X-Device-Token` |
| PIR Token | `LS_PIR_TOKEN_2025` | Query param `?token=` |

### 9.3 ESP32 Network
- **AP SSID:** `LumineSense-Setup`
- **AP Password:** `luminesense123`
- **mDNS:** `http://luminesense.local`
- **Hardcoded Classroom ID:** `3` (SEL 1)

### 9.4 Database Connection
- Automatic detection of localhost vs Hostinger production
- Database name: `luminesense_db` (local), `u935862620_luminesense_db` (Hostinger)
- Full dump available in `newsqlhere.sql`

### 9.5 Running the Servers

```bash
# Node.js (port 3000)
cd server && npm install && node server.js

# Python gesture server (port 5000, spawned by Node.js on demand)
python gesture-control.py

# Python lighting relay (port 5001)
python lighting.py
```

---

## 10. Recent Commit History

```
713ddbb README.md                                    (Jun 26)
4dc573b README.md                                    (Jun 25)
6f25d7e README.md                                    (Jun 25)
0e77bfc README.md                                    (Jun 25)
0d6c257 README.md                                    (Jun 25)
10d6aff Hostinger fixed                              (Jun 25)
8895221 Hostinger fixed see README                   (Jun 25)
611dcb7 hostinger troubleshooting                    (Jun 25)
87981ae checklng                                     (Jun 25)
cf08458 Merge pull request #12 from ver2pulls-ver3   (Jun 25)
ca99a2e Faculty head timetable + department mgmt     (Jun 24)
95b250e PHP Mailer                                   (Jun 23)
aef8785 Merge pull request #11 login-process-verif   (Jun 23)
eb37df8 Fix admin login query error                  (Jun 22)
```

### Key Feature Milestones

| Date | Milestone |
|------|-----------|
| Jun 26 | README task tracking |
| Jun 25 | Hostinger deployment, db_connect production fixes |
| Jun 24 | Faculty head schedule management, department CRUD, faculty-department assignment |
| Jun 23 | PHPMailer working, login verification flow |
| Jun 22 | Admin login query fix |
| Jun 17 | Directory restructuring, OOP preparation |
| Jun 13 | Tooltip system, faculty management enhancements |
| Jun 11 | Admin reports, CSS consistency pass |
| Jun 4 | Admin sidebar, homepage, room management |
| May/Jun | Gesture recognition, ESP32/Arduino firmware, PZEM integration |
