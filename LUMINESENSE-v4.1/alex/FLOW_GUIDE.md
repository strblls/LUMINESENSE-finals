# LumineSense – Setup & Flow Testing Guide

## FOLDER STRUCTURE (put this inside C:/xampp/htdocs/luminesense/)

```
luminesense/
├── index.php                          ← landing page
├── images/logo.png
├── css/                               ← your existing CSS files
├── script/                            ← your existing JS files
├── php/
│   ├── db_connect.php
│   ├── session_guard.php
│   ├── admin-login.php                ← form handler
│   ├── admin-signup.php               ← form handler
│   ├── faculty-login.php              ← form handler
│   ├── faculty-signup.php             ← form handler
│   ├── logout.php
│   └── luminesense_db.sql             ← run once in phpMyAdmin
├── api/
│   ├── accounts.php                   ← approve/reject faculty
│   ├── classrooms.php                 ← add/delete rooms
│   ├── schedules.php                  ← timetable CRUD
│   ├── logs.php                       ← lighting event log (also Arduino endpoint)
│   ├── status.php                     ← current light status per room
│   └── analytics.php                  ← energy summary + chart data
└── pages/
    ├── admin-login.php
    ├── admin-signup.php
    ├── faculty-login.php
    ├── faculty-signup.php
    └── admin-home/
    │   └── admin-homepage.php         ← full admin dashboard (all tabs)
    └── faculty-home/
        └── faculty-homepage.php       ← faculty view
```

---

## STEP 1 — DATABASE SETUP (do once)

1. Open XAMPP → Start Apache + MySQL
2. Go to http://localhost/phpmyadmin
3. Click SQL tab → paste contents of php/luminesense_db.sql → click Go
4. Verify: left sidebar shows luminesense_db with tables:
   admins, faculty, classrooms, schedules, lighting_logs

Seeded test admin:
  Email:    admin@luminesense.edu.ph
  Password: Admin1234!
  Status:   is_verified = 1 (already approved, can log in immediately)

---

## STEP 2 — TEST ADMIN LOGIN

1. Go to http://localhost/luminesense/pages/admin-login.php
2. Enter: admin@luminesense.edu.ph / Admin1234!
3. Expected: redirected to pages/admin-home/admin-homepage.php
4. Should see 5 tabs: Overview, Faculty Accounts, Classrooms, Timetable, Logs, Analytics

If it fails:
  - Check XAMPP Apache + MySQL are both green
  - Check php/db_connect.php has correct DB_NAME = 'luminesense_db'
  - Open phpMyAdmin → verify the admin row has is_verified = 1

---

## STEP 3 — ADMIN CREATES A CLASSROOM (required before schedule/logs work)

1. Click Classrooms tab
2. Click + Add Classroom
3. Enter: Room 101, Medium, any description
4. Click Add → row appears in table

---

## STEP 4 — ADMIN ADDS A TIMETABLE SLOT

1. Click Timetable tab
2. Click + Add Slot
3. Select Room 101 → Monday → 08:00 → 10:00
4. Click Add → row appears
5. System will only allow PIR sensor triggers during this window

---

## STEP 5 — FACULTY SIGNS UP

1. Go to http://localhost/luminesense/pages/faculty-signup.php
2. Fill in name, email (e.g. teacher@test.com), password (min 8 chars)
3. Click Sign Up → modal appears → click Confirm
4. Expected: redirect to faculty-login.php with success message
5. Check phpMyAdmin → faculty table → new row with is_verified = 0

---

## STEP 6 — ADMIN APPROVES THE FACULTY ACCOUNT

1. Go to Admin Dashboard → Faculty Accounts tab
2. Should see the new faculty row with status: Pending
3. Click Approve
4. Row updates to: Verified | Approved By: Alexandra Ballesteros
5. Check phpMyAdmin → is_verified = 1, approved_by = admin ID, approved_at = timestamp

---

## STEP 7 — FACULTY LOGS IN

1. Go to http://localhost/luminesense/pages/faculty-login.php
2. Enter faculty email + password
3. Expected: redirected to pages/faculty-home/faculty-homepage.php
4. Should see: classroom status cards + today's schedule + gesture note

If still "pending" error: check Step 6 was completed.

---

## STEP 8 — VERIFY LOGS (simulate Arduino data)

Until the physical Arduino is connected, test the log API manually:
Open browser or use Postman:

POST to: http://localhost/luminesense/api/logs.php
Body (form-data):
  classroom_id = 1
  event_type   = on
  triggered_by = sensor

Then check Admin Dashboard → Logs tab → new entry appears.
Also check Overview tab → "Lights ON" count increments.

---

## API REFERENCE (for Arduino / frontend)

### POST api/logs.php  (Arduino calls this — no session needed)
  classroom_id   INT     required
  event_type     STRING  on | off | gesture | schedule | security_alert
  triggered_by   STRING  sensor | gesture | schedule | manual

### GET api/status.php  (dashboard polls this)
Returns current light status + active schedule per room.

### GET api/logs.php?room=1&type=on&date=2025-01-01&limit=50
Returns filtered log entries. Session required.

### GET api/analytics.php?range=7
Returns energy summary. range = 7, 14, or 30 days. Session required.

### POST api/accounts.php
  action      approve | reject | revoke | delete
  faculty_id  INT
Admin session required.

### GET api/accounts.php?filter=pending|verified|all
Admin session required.

### POST api/classrooms.php
  action       add | delete
  room_name    STRING (for add)
  room_size    small | medium | large (for add)
  classroom_id INT (for delete)

### POST api/schedules.php
  action       add | delete
  classroom_id INT (for add)
  day_of_week  Monday...Sunday (for add)
  start_time   HH:MM (for add)
  end_time     HH:MM (for add)
  schedule_id  INT (for delete)

---

## COMMON ERRORS

| Error | Fix |
|---|---|
| "DB connection failed" | XAMPP MySQL not running, or wrong DB_NAME in db_connect.php |
| "Unauthorized" from API | Session expired — log in again |
| Login goes to blank page | PHP error — check XAMPP error log at C:/xampp/apache/logs/error.log |
| "Pending approval" on faculty login | Admin hasn't approved the account yet (Step 6) |
| API returns HTML instead of JSON | PHP error in that file — check the error log |
| Sessions not persisting | Make sure you access via http://localhost/ NOT file:/// |

---

## ARDUINO INTEGRATION (when hardware is ready)

In your Arduino/ESP32 sketch, after a PIR trigger:

  HTTPClient http;
  http.begin("http://192.168.x.x/luminesense/api/logs.php");
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  int code = http.POST("classroom_id=1&event_type=on&triggered_by=sensor");

Replace 192.168.x.x with your PC's local IP address (ipconfig in cmd).
The Arduino and the PC must be on the same Wi-Fi network.
