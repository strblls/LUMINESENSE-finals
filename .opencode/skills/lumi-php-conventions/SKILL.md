---
name: lumi-php-conventions
description: LumineSense PHP and MySQL conventions. Use when writing or editing PHP in src/, api/, handlers/, or pages, and when changing the database schema or queries. Follows PSR-4, mysqli, prepared statements, auto-migrations, and Monolog.
---

# LumineSense PHP/MySQL Conventions

## Layout & autoloading

- `composer.json` maps PSR-4 `LumineSense\` → `src/`. New classes go in `src/` under a matching namespace (`LumineSense\Services\...`, `LumineSense\Auth\...`, etc.).
- `handlers/*.php` are legacy procedural scripts that parse `$_POST`/`$_GET` and redirect. Prefer putting new logic in `src/` classes; keep `handlers/` thin.
- Front-end pages live in `pages/`, API endpoints in `api/` (each returns `json_encode(['success' => bool, ...])`).

## Database access

- `src/Config/db_connect.php` boots env loading, Monolog error/exception/fatal handlers, and creates the `$conn` mysqli global. Require it before using `$conn`.
- Defaults come from `.env` via `loadEnv()`: `DB_HOST=localhost`, `DB_USER=root`, `DB_PASS=`, `DB_NAME=luminesense_db`. Session timezone is forced to `+08:00` at the connection.
- **Prepared statements** (`prepare`/`bind_param`) for ANY value derived from user input. Never concatenate values into SQL strings.
- Schema changes go through the runtime auto-migration helpers in `db_connect.php`:
  - New columns → `addColIfMissing($conn, $table, $column, $definition);`
  - New tables / FK repairs / events → idempotent `CREATE TABLE IF NOT EXISTS`, guarded `ALTER`, or `SHOW EVENTS` checks. Edits here re-run safely on every request.

## Logging & errors

- Use `LumineSense\Services\Logger` (Monolog): `Logger::info/notice/warning/error/critical($msg, $context)`.
- `logs/app-*.log` and `logs/error.log` are gitignored; don't print stack traces to the browser.
- `display_errors` is off in production paths — debug via logs, not `var_dump`.

## Sessions & auth

- Use the session helpers under `src/Session/`; always guard restricted pages/APIs with the matching auth check before trusting input.
- Faculty/admin roles are distinct — never mix permission checks between them.

## Secrets

- Never echo, log, or commit token values (`DEVICE_TOKEN`, `ESP32_TOKEN`, `VALID_ADMIN_CODE`, `ID_ENCRYPTION_KEY`, `VISION_API_KEY`, SMTP creds). They live only in `.env` (gitignored).
- `uploads/faculty_ids/` must never be served directly as web-accessible static files — route ID images through the API with auth + encryption.

## Style

- Match surrounding files. PHP 8.2+ features (`match`, `str_starts_with`, arrow fns, typed properties) are fine.
- No unused includes; `require_once` over `require`.
