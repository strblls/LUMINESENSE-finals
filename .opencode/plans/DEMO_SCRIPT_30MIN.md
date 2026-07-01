# LumineSense — 30-Minute Professor Demo Script

## Demo Prerequisites (prepare before the session)

### Database Setup
```sql
-- Insert 3 demo rooms
INSERT INTO classrooms (room_name, room_size, description) VALUES
('Demo Room A', 'medium', 'Ground floor - Handover demo room'),
('Demo Room B', 'large',  'Second floor - Extension demo room'),
('Demo Room C', 'small',  'Library wing - Overflow room');

-- Insert test faculty accounts (all verified & approved)
INSERT INTO faculty (first_name, last_name, email, password, is_verified, approved_by)
VALUES
('Juan',  'Dela Cruz',  'juan@demo.com',  '$2y$10$...hashed_pw...', 1, 1),
('Maria', 'Santos',     'maria@demo.com', '$2y$10$...hashed_pw...', 1, 1),
('Pedro', 'Reyes',      'pedro@demo.com', '$2y$10$...hashed_pw...', 1, 1);

-- Enable permissions for all
INSERT INTO faculty_permissions (faculty_id, lighting_control, gesture_control) VALUES
(2, 1, 1), (3, 1, 1), (4, 1, 1);
```

### Browser tabs to open
1. **Admin** — `admin-login.php` (logged in, on Faculty Management page)
2. **Department Head** — `faculty-login.php` (logged as Juan, on timetable page)
3. **Faculty A** — `faculty-login.php` (logged as Maria, on home page)
4. **Faculty B** — `faculty-login.php` (logged as Pedro, on home page)
5. **Room Management** — `admin-room-manage.php` (logged as admin)

---

## Demo Flow (30 minutes)

---

### PART 1: Department Assignment (5 min)

**Goal:** Show how admin creates departments, assigns heads, and organizes faculty.

| Step | Action | Screen | Script |
|------|--------|--------|--------|
| **1.1** | Navigate to **Faculty Management** page | Admin Tab | _"This is the Faculty Management dashboard. It shows stats cards for total departments, faculty, and pending approvals. Below are the Department cards."_ |
| **1.2** | Click **"+ Add Department"** card | Admin Tab | _"Let's create a STEM Department. We'll assign Juan Dela Cruz as the Department Head and include Maria Santos as a member."_ |
| **1.3** | Fill form: Name = `STEM Department`, search for `Juan` in head dropdown → select radio, search for `Maria` in members → check box. Click **Add Department**. | Admin Tab | Point out: _"The head is also auto-added as a member. The green status badge shows it's active."_ |
| **1.4** | Click the **eye icon** on the new card | Admin Tab | _"The View modal shows the department hierarchy: Head → Members."_ |
| **1.5** | Click **Edit (pencil)** → change description → add a Subject Area name → **Save** | Admin Tab | _"We can also attach a subject area during department creation or editing."_ |
| **1.6** | Click **"Pending Extensions"** card, show grace period dropdown | Admin Tab | _"Grace period for auto-approval is configured here — we'll use this later."_ |

**Key talking points:**
- `departments` table with FK to `faculty.head_faculty_id`
- `junction_faculty_department` bridge table for many-to-many
- `ON DELETE CASCADE` on both FK directions
- Department status auto-set to `pending` if no head, `active` once assigned

---

### PART 2: Subject & Subject Area Assignment (5 min)

**Goal:** Show Department Head creating subject areas and subjects, then assigning coverage to faculty.

| Step | Action | Screen | Script |
|------|--------|--------|--------|
| **2.1** | Log out admin. **Log in as Juan Dela Cruz** (faculty login) → Click **"Head Management"** in sidebar | Head Tab | _"Only faculty flagged as department heads see this tab. Juan sees only his departments."_ |
| **2.2** | Click **"Edit Coverage"** (pencil icon on the dept card under Subject Areas column) | Head Tab | _"This opens the subject area editor. We see current SAs as chips with close buttons."_ |
| **2.3** | Type `Physics` in input → press Enter (chip appears) → Type `Chemistry` → press Enter → Click `Physics` chip (highlighted yellow) → type `Thermodynamics` → press Enter | Head Tab | _"Subject areas are created at the department level. Subjects live under subject areas. Clicking a SA enables its subject input."_ |
| **2.4** | Click **Save** → Confirm in modal | Head Tab | _"Changes are batched: deletions, then new SAs, then new subjects — all in one transaction."_ |
| **2.5** | Find **Maria Santos** card → Click **"Edit Assignment"** (briefcase icon) | Head Tab | _"Now we assign coverage to a faculty member. Left column = currently assigned SAs/subjects. Right column = available items to add."_ |
| **2.6** | Click `Physics` in right column (green border) → Search subjects → click `Thermodynamics` → **Save** | Head Tab | _"We're telling the system: Maria can teach Physics → Thermodynamics. This will matter when we schedule her classes."_ |
| **2.7** | Click **"View"** (eye) on Maria's card to confirm | Head Tab | _The read-only view confirms the assignment._ |

**Key talking points:**
- Three junction tables: `junction_faculty_subjectarea`, `junction_faculty_subject`, `junction_faculty_department`
- Coverage is a prerequisite for scheduling — departments without at least one SA+subject get a warning
- Chips with close = schedule for removal; chips with plus = restore. All changes submitted atomically

---

### PART 3: Faculty Scheduling (6 min)

**Goal:** Department Head schedules classes for faculty members.

| Step | Action | Screen | Script |
|------|--------|--------|--------|
| **3.1** | On Maria's card, click **calendar icon** → redirected to `faculty-head-membersched.php` | Head Tab | _"The system first checks if the department has coverage before allowing scheduling. Maria has Physics → Thermodynamics, so we pass."_ |
| **3.2** | Click **"+ Add Schedule Slot"** button | Head Tab | _"The modal shows: day of week, time range, room dropdown (all rooms available), and subjects filtered to only Maria's assigned subjects."_ |
| **3.3** | Set: Day=`Monday`, Start=`09:00`, End=`10:30`, Room=`Demo Room A`, Subject=`Thermodynamics` → **Save** | Head Tab | _"Notice the subject dropdown only shows Thermodynamics — because that's the only subject we assigned Maria. The room shows all classrooms."_ |
| **3.4** | Add another slot: Day=`Monday`, Start=`10:30`, End=`12:00`, Room=`Demo Room A`, Subject=`Thermodynamics` → **Save** | Head Tab | _"Two back-to-back slots — the overlap detector only blocks same-room, same-time conflicts, so consecutive slots are fine."_ |
| **3.5** | Now add schedule for **Pedro Reyes**: click back → click Pedro's calendar icon → Add: Day=`Monday`, Start=`12:00`, End=`13:30`, Room=`Demo Room A` → **Save** | Head Tab | _"Pedro gets the next slot in Demo Room A — this sets up our handover demonstration later."_ |
| **3.6** | Show the weekly grid → hover over Maria's slot → show Edit/Delete buttons | Head Tab | _"Only schedules created BY this department head can be edited. Slots by other heads show a 'Restricted' tooltip. The grid is 7-day (Sun-Sat)."_ |
| **3.7** | Edit Maria's first slot: click edit → change end to `11:00` → **Save** | Head Tab | _"Overlap detection runs on update too, excluding the slot being edited."_ |

**Key talking points:**
- Department heads can only schedule for members of their departments (verified via `member_in_any_head_department()`)
- Schedules track `subject_id` (admin schedules don't)
- The overlap check is room-based only — no faculty time-conflict detection
- `schedules.created_by` = head's faculty_id; ownership is enforced on edit/delete

---

### PART 4: Faculty Handover via Dummy Rooms (7 min)

**Goal:** Demonstrate how Maria and Pedro "hand over" Demo Room A using extension requests and end-early.

| Step | Action | Screen | Script |
|------|--------|--------|--------|
| **4.1** | Switch to **Maria's dashboard** (Faculty A tab, logged as Maria) | Faculty A Tab | _"Maria's dashboard shows Demo Room A with lighting controls. The countdown timer shows her schedule: Monday 9:00-10:30."_ |
| **4.2** | Click **"Extend"** button in the Time Left panel | Faculty A Tab | _"A confirmation appears. Let's request +15 minutes."_ |
| **4.3** | Notice **Room Conflict Modal** appears (red header) showing Pedro's schedule at 12:00 | Faculty A Tab | _"The system checked `check-room-successor.php` BEFORE showing the extension modal. Since Pedro has a slot starting at 12:00, and Maria's extension would go to 10:45, there's no conflict yet — BUT if we tried to extend to 12:00+, it would block."_ |
| **4.4** | Let's actually extend: change request to +15 mins → **Send Request** | Faculty A Tab | _"Only 15 minutes. The successor check uses `COALESCE(extended_until, end_time)` as the boundary — since 10:45 < 12:00, the check passes."_ |
| **4.5** | Now click **"End Early"** button | Faculty A Tab | _"This is the handover signal. It sets `extended_until = CURTIME()`, turns off all lights, and sets `schedule_dirty = 1` for the ESP32 to detect."_ |
| **4.6** | Switch to **Room Management** (Admin tab) → click **"Open Dummy Room"** on Demo Room A | Admin Popup | _"The Dummy Room popup shows a simplified lighting dashboard. It mirrors the actual room status — rows on/off, master toggle. After End Early, everything is OFF."_ |
| **4.7** | Navigate to the room detailed view on **Pedro's dashboard** (Faculty B tab) | Faculty B Tab | _"Pedro isn't yet in his schedule window (12:00), so his controls show 'Access Locked — No active schedule.' The handover is complete when the timer naturally reaches Pedro's start time."_ |
| **4.8** | Back on **Admin → Faculty Management**, show the **"Stat Cards"** updating | Admin Tab | _"The stats cards reflect real-time counts: rooms, pending extensions, approved faculty."_ |

**Key talking points:**
- "Handover" is implicit — `extended_until` vs `end_time` + successor check
- `schedule_dirty` flag is polled by ESP32 every 5s for real hardware
- End Early = hard handover; Extension = soft handover
- Room-light-view.php acts as a "dummy room" for demo purposes

---

### PART 5: Auto-Accept & Extension Requests (7 min)

**Goal:** Demonstrate the full extension lifecycle: request, auto-approval, admin approval, daily limits.

| Step | Action | Screen | Script |
|------|--------|--------|--------|
| **5.1** | Admin sets **Grace Period = 30 min** in "Pending Extensions" card | Admin Tab | _"Auto-accept grace period: when a class has ≤30 min remaining, extension requests are auto-approved."_ |
| **5.2** | As **Maria** (Faculty A), click **Extend** → The elapsed timer shows ~25 min elapsed already (since she's mid-class) → Click **+15 min pill** | Faculty A Tab | _"The pills ADD to elapsed time. It calculates the new end time and shows it in the confirmation."_ |
| **5.3** | Click **Send Request** → Notice modal shows **auto-approved** (status checkmark) | Faculty A Tab | _"Because the remaining class time (10:30 - current_time ≈ 5 min) is ≤ 30 min grace period, the system auto-approves. `reviewed_by` stays NULL to distinguish from admin-approved."_ |
| **5.4** | Log in as **Pedro** (Faculty B, different room) → go to timetable → click **Extend** on a slot that is NOT near its end | Faculty B Tab | _"This one stays PENDING because it doesn't meet the grace window conditions."_ |
| **5.5** | Switch to **Admin** tab → shown "Pending Extensions" card has Pedro's request with **Grant/Deny** buttons | Admin Tab | _"Admin sees all pending requests. Click Grant → `extended_until` is set, `schedule_dirty = 1` is flagged."_ |
| **5.6** | **Grant** Pedro's request → Show the success flash message | Admin Tab | _"The handler calculates `end_time + extend_mins` and stores the result in `schedules.extended_until`."_ |
| **5.7** | Switch back to **Pedro's timetable** → the slot now shows a green check badge | Faculty B Tab | _"The badge system: no icon = no request, hourglass = pending, green check = approved, red X = rejected."_ |
| **5.8** | Try to request a 3rd extension for the same day → **Daily Limit Modal** appears | Any Tab | _"Max 3 extensions per day per faculty (across all pending+approved for that day-of-week)."_ |

**Key talking points:**
- Auto-approval conditions: (1) grace > 0, (2) today's schedule, (3) class in session, (4) ≤ grace_minutes remaining
- `auto-approve-extensions.php` runs on every timetable page load (cron-like without cron)
- `schedules.extended_until` overrides `end_time` in all schedule queries (via `COALESCE`)
- Extension daily limit: `SELECT 3 - COUNT(*)` where status IN ('pending','approved')
- Room successor check prevents extending into another faculty's slot

---

## Architecture Summary (2 min)

When the professor asks _"So what's the architecture?"_ after or during the demo:

```
Browser UI (Bootstrap 5.3 + JS)
    → PHP pages (XAMPP/Apache)
    → REST API endpoints (api/*.php)
    → MySQL (classrooms, faculty, departments, schedules)
    → Node.js :3000 → Python Flask :5000 (gesture camera)
    → ESP32 → Arduino Mega (PZEM-004T, RTC, PIR, MOSFETs)
```

**Key files per feature:**

| Feature | Files |
|---------|-------|
| Department assignment | `admin-faculty-management.php`, `admin-handlers.php` |
| Subject areas | `faculty-head-timetable.php`, `faculty-head-handler.php` |
| Faculty scheduling | `faculty-head-membersched.php`, `faculty-head-handler.php` |
| Room management | `admin-room-manage.php`, `room-light-view.php` |
| Extension requests | `faculty-timetable.php`, `request-extension.php`, `faculty-approvals-handler.php` |
| Auto-approve | `auto-approve-extensions.php` |
| DB schema | `db_connect.php` (auto-migration), `newsqlhere.sql` (dump) |

---

## Common Q&A Preparation

**Q: What prevents two faculty in the same room at the same time?**
> The overlap check in `faculty-head-handler.php`: `WHERE classroom_id=? AND day=? AND start_time < ? AND end_time > ?`. It's room-scoped, not faculty-scoped.

**Q: How does end-to-end handover work with real hardware?**
> The ESP32 polls `esp32-schedule-flag.php` every 5s. When `schedule_dirty=1`, it re-fetches the schedule and the Mega state machine transitions.

**Q: Can a faculty member be in multiple departments?**
> Yes — `junction_faculty_department` is many-to-many. A department head can only manage faculty in their OWN departments.

**Q: Does auto-approval bypass the successor check?**
> No — the successor check runs BEFORE the request is inserted. Auto-approval only applies to the `status` field after insertion.

**Q: Where is the grace period stored?**
> `system_settings` table with key `grace_minutes`. Also cached in `$_SESSION['ext_grace_minutes']`.
