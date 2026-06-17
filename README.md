# LumineSense — System Updates and Architecture Refactoring

## 1. Architectural Refactoring: Procedural to OOP Controllers
To improve modularity, maintainability, and security, the entire procedural API layer has been refactored:
* **Removed Procedural APIs**: The old procedural PHP scripts previously located in the `/api/` directory (e.g., `api/accounts.php`, `api/analytics.php`, `api/classrooms.php`, `api/lights.php`, etc.) have been completely removed.
* **Introduced Class-Based Controllers**: API endpoints and request processing are now handled by class-based Object-Oriented controllers inside the new `app/controllers/` directory:
  * `AccountController.php`: Handles teacher approvals, status revocations, rejections, and deletions.
  * `AnalyticsController.php`: Manages energy usage and occupancy data logs.
  * `ClassroomController.php`: Manages classroom configurations and states.
  * `DashboardController.php`: Synthesizes state data for admin and teacher dashboards.
  * `Esp32Controller.php` & `PirController.php`: Handle ESP32 device check-ins, status uploads, and motion-detection signals.
  * `LightingController.php`: Directly controls individual rows and schedules.
  * `LogController.php`: Manages lighting event history.
  * `PzemController.php`: Processes power consumption data.
  * `ScheduleController.php`: Performs CRUD actions on room-occupancy scheduling.
* **Separated Business Logic into Services**: Core business logic has been encapsulated into services inside the `app/services/` directory:
  * `EnergyService.php`
  * `OccupancyService.php`
  * `ScheduleService.php`
  * `UserOnboardingService.php`

---

## 2. Updated User Roles and Administrative Hierarchy
The administrative logic has been split into a clearer hierarchy to support role-based permissions:
* **Roles Defined**:
  1. **Principal (`super_admin`)**: Retains global administrative control across all departments. Can permanently delete accounts. Principal accounts are created directly in the database.
  2. **Head Teacher (`head_teacher`)**: Self-registers using a `VALID_ADMIN_CODE` shared by the Principal. Can view, approve, reject, or revoke access only for Faculty members belonging to their respective department.
  3. **Faculty (Teacher)**: Registers under a specific department with their school ID. Needs approval from their Head Teacher to log in.
* **Signup & Login Process Improvements**:
  * `php/admin-signup-process.php`: Refactored to only allow registration of Head Teacher accounts (validating `admin_code` against `VALID_ADMIN_CODE` and assigning the selected `department_id`).
  * `php/admin-login-process.php`: Query updated to retrieve `admin_role` and `department_id` details via a `LEFT JOIN` on the `departments` table. Redirects administrators to their respective homepages based on role (`principal-homepage.php` vs. `head-faculty-homepage.php`).
  * `php/faculty-login-process.php`: Verifies that Faculty members are email-verified and approved by their Head Teacher (`approved_by` is not null). Pending teachers are gracefully redirected to `pending-approval.php`.
* **Login Pages**: Added dedicated logins `pages/principal-login.php` and `pages/head-faculty-login.php` to handle separate interfaces for both administrative tiers.

---

## 3. Automated Light Activation Trigger
* **Login Check**: When an approved and verified Faculty member logs in (`php/faculty-login-process.php`), the system automatically checks if they have a class scheduled in a classroom *at that exact moment* (matching day, start time, and current session duration).
* **Automatic Control**: If a scheduled class is running, the database state for that classroom's lights is automatically set to `'on'`, all light rows (`row1_status`, `row2_status`, `row3_status`) are activated, occupancy status is set to occupied, and the action is recorded in `lighting_logs` under the `'login'` trigger event.

---

## 4. AI Verification & Prototype Mode
* **Faculty Registration Validation**: During faculty registration (`php/faculty-signup-process.php`), teachers must upload their school ID. An Anthropic Claude Sonnet API call analyzes the image, extracts the printed name, and matches it against the user's typed name.
* **Prototype Bypass**: A `PROTOTYPE_MODE` flag was introduced in `config.php`. If set to `true`, the system skips the external API call to Claude Sonnet to prevent connection dependencies during local demonstrations. The ID image is still saved to disk, but the account is automatically flagged for manual review with a custom confidence note.

---

## 5. Security & Configuration Centralization
* **Token Centralization**: Created a central config file `app/config.php` containing defined security tokens (`DEVICE_TOKEN`, `ESP32_TOKEN`, `PIR_TOKEN`, `PZEM_TOKEN`) used to validate requests from Arduino/ESP32 devices and sensors, ensuring no secrets are hardcoded in operational scripts.
