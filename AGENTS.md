# LumineSense — Agent Guide

LumineSense is a smart-classroom system: PHP 8.2 web app (faculty scheduling, room energy analytics, lighting control, ID verification) backed by MySQL/MariaDB, plus embedded firmware (Arduino Mega + ESP32) and Python/Node microservices.

**The code is the source of truth.** `docs/` is known to be stale; verify against the implementation before trusting or updating it.

## Agent routing

Use the table below to pick the right subagent for a task.

| Task / keyword | Agent |
| :--- | :--- |
| Find code, map a feature, "how does X work" | `explore` |
| PHP in `src/`, `api/`, `handlers/`, pages | `php-expert` |
| HTML/CSS/JS, Bootstrap, Chart.js, Gantt/UI | `frontend-dev` |
| SQL, schema, migrations, live DB inspection | `sql-schema` |
| Security, secrets, uploads, session hardening | `security-auditor` |
| Runtime bugs, error logs, "worked before" | `debug-troubleshooter` |
| Reviewing diffs/PRs | `code-reviewer` |
| Writing/running tests, smoke tests, QA | `testing-harness` |
| Docs / README updates | `docs-writer` |
| Arduino Mega / ESP32 firmware | `embedded-firmware` |
| Python/OpenCV/MediaPipe + Node gateway | `python-microservice` |
| Commits, merges, releases, repo hygiene | `release-manager` |

## Layout

- `pages/` — PHP views (`admin-home/`, `faculty-home/`, `faculty-head/`, login/signup).
- `api/` — JSON endpoints (`schedules.php`, `analytics.php`, `live-pzem.php`, `esp32-*`, ...). Return `{ success: bool, ... }`.
- `src/` — PSR-4 `LumineSense\` classes (`Auth/`, `Services/`, `Session/`, `Config/`, `Cron/`, `Handlers/`, `Includes/`).
- `handlers/` — legacy procedural POST handlers; keep thin, prefer `src/`.
- `css/`, `js/` — plain assets per page; no build step.
- `database/schema.sql` — schema snapshot; **`src/Config/db_connect.php` is authoritative** (runtime migrations).
- `embedded/` — `mega/` (PZEM-004T metering) + `esp/` (WiFiManager, PIR, lighting).
- `microservices/` — Python (Flask/OpenCV/MediaPipe). `server/` — Node 18+/Express 5 gateway (port 3000).
- `tests/php/` — hand-rolled PHP tests (no harness yet).

## Conventions

- PSR-4 `LumineSense\` → `src/`; `composer.json` deps: phpmailer, dompdf, monolog.
- DB via `src/Config/db_connect.php` (`$conn` mysqli). **Prepared statements for any user input.**
- Schema changes = `addColIfMissing(...)` / idempotent `CREATE TABLE IF NOT EXISTS` in `db_connect.php`.
- Logging via `LumineSense\Services\Logger` (Monolog) → `logs/`. Never `var_dump` to browser.
- Timezone `+08:00` everywhere. Env via `.env` + `src/Config/load-env.php`.
- API endpoints must guard by role and return JSON.

## Verification

- PHP: `php -l <file>`
- JS: `node --check <file>`
- New `src/` class: `composer dump-autoload`
- After changes, check `logs/app-*.log` / `logs/error.log`.
- Full run requires XAMPP (Apache + MySQL) at `http://localhost/LUMINESENSE-finals/`.

## Secret hygiene

Never print, log, echo, or commit: `DEVICE_TOKEN`, `ESP32_TOKEN`, `VALID_ADMIN_CODE`, `ID_ENCRYPTION_KEY`, `VISION_API_KEY`, SMTP credentials, or `.env` contents. Never serve `uploads/faculty_ids/` statically.
