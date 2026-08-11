---
name: lumi-verify
description: Verification and lint commands for LumineSense. Use when validating changes to PHP, JS, CSS, or pages, or after implementing/fixing any feature. Do NOT rely on docs for what the code does — code is the source of truth.
---

# LumineSense Verification

Run these checks after any code change. Never claim a change is complete without running them.

## PHP

```sh
php -l <file.php>
```

- Run on every edited `.php` file (pages, api, handlers, src).
- A fatal output line starting with `No syntax errors detected` means the file passes.

## JavaScript

```sh
node --check <file.js>
```

- Run on every edited `.js` file. Fails on syntax errors only.

## CSS

- No linter is configured. Verify selectors target real markup and the page still renders by hard-refreshing (Ctrl+Shift+R).

## Autoloading

```sh
composer dump-autoload
```

- Required whenever a new PHP class is added under `src/` (PSR-4 `LumineSense\`).

## DB connection / schema

- `src/Config/db_connect.php` is the authoritative schema: it creates tables and adds missing columns at runtime. After editing it, load any page that includes it, or run `php tests/php/connection_test.php` — no manual migration step exists.

## Smoke flow (manual)

1. Open the app at `http://localhost/LUMINESENSE-finals/` (XAMPP + MySQL must be running).
2. Log in as admin and as faculty; verify each touched page.
3. Check `logs/app-*.log` and `logs/error.log` after testing for new PHP warnings/exceptions (Monolog writes `LumineSense\Services\Logger` entries here).
4. If MCP `mysql_*` tools are available, sanity-check changed tables with `SHOW COLUMNS` / `SELECT` against the live DB.

## Rules

- Never skip verification "because it's a small change".
- Never assume a library is installed — check `composer.json`, `package.json`, or neighboring files first.
