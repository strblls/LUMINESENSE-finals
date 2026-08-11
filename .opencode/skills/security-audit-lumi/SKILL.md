---
name: security-audit-lumi
description: Security review checklist for LumineSense. Use when auditing the project for vulnerabilities, reviewing token/secret handling, uploads exposure, session hardening, or any code that touches credentials, IDs, or API keys. Report findings with file:line references.
---

# LumineSense Security Audit

Use this checklist when auditing. Work read-only: identify and document, do not fix unless explicitly asked. Report every finding with `file:line`.

## Known hotspots (verify each time)

1. **Secrets in docs** — `docs/PROJECT_DOCUMENTATION.md` (~line 461-473) has committed SMTP password, `VALID_ADMIN_CODE`, `DEVICE_TOKEN`, `ESP32_TOKEN`. Flag any plaintext secret in the repo.
2. **Token echo on auth failure** — `api/pzem_push.php` (~lines 27-34) echoes `expected_token` to unauthenticated callers. Confirm it no longer reveals valid tokens (or flag it).
3. **Uploads exposure** — `uploads/faculty_ids/` historically holds real ID photos web-servable. Check `.htaccess`, web-server config, or route access. ID images should be served only via an authenticated API (`review-id.php` pipeline), stored encrypted (AES-256-GCM with `ID_ENCRYPTION_KEY`).
4. **Hardcoded device creds** — `embedded/esp/esp.ino` (~lines 23-42) hardcoded WiFi password, device tokens, and `classroom_id=3`. Confirm firmware pulls config from WiFiManager or env, not literals.
5. **`.env` hygiene** — `.env` holds `ID_ENCRYPTION_KEY`, `VISION_API_KEY`, SMTP creds. Verify `.env`, `logs/`, `uploads/`, `.kilo/` are gitignored and not in `git ls-files`.
6. **Session hardening** — check `session_set_cookie_params` / `httponly`, `samesite`, `secure`, `session_regenerate_id` on login.
7. **SQL injection** — spot-check `api/` endpoints for string-concatenated SQL (must use prepared statements).
8. **AuthZ** — every `api/` endpoint must guard by role; check for endpoints that leak data without login.

## Data pipeline to respect

- Faculty ID flow: upload → Google Cloud Vision extraction (`VISION_API_KEY`) → `ai_match_status` on `faculty`/`id_review_queue` → encrypted blob, expires → admin review. Don't recommend weakening encryption or expiry.

## Reporting format

```
- [SEV/HIGH/MED] <one-line description>
  - Location: <file>:<line>
  - Detail: <what is wrong>
  - Fix: <suggested fix, deferred unless asked>
```

Never print actual secret values in your report — redact them.
