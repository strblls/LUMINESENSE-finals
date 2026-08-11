# LumineSense Implementation Plan
## Generated: 2026-08-09

## Files Modified Summary

| # | File | Phase | Change |
|---|------|-------|--------|
| 1 | `src/Config/db_connect.php` | 1 | Archive tables, soft-delete columns, EVENT |
| 2 | `src/Cron/flush-executor.php` | 2 | Rewrite: archive before delete |
| 3 | `src/Handlers/flush-handler.php` | 3 | New actions: delete_archive, EVENT control |
| 4 | `src/Cron/check-flush-schedule.php` | 4 | Remove extension flush logic |
| 5 | `src/Services/mailer.php` | 5 | Archive/reactivation emails |
| 6 | `src/Auth/faculty-login-process.php` | 6 | is_archived check |
| 7 | `pages/faculty-login.php` | 7 | notify-modal for archived accounts |
| 8 | `src/Includes/admin-head.php` | 8 | Flush icon mappings |
| 9 | `pages/faculty-home/faculty-home.php` | 9 | Add timetable.css |
| 10 | `api/overview-live.php` | 10 | isActiveSession flag |
| 11 | `js/admin/admin-overview.js` | 10-11 | Sparkline reset + faculty functions |
| 12 | `pages/admin-home/admin-overview.php` | 11 | Faculty query + split pane HTML |
| 13 | `css/admin/overview.css` | 11 | Split pane + faculty card styles |
