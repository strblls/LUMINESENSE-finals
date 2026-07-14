# Table Tracking

| Date | Modules Affected | Notes |
| :--- | :---: | :--- |
| July 14 | Landing page, Faculty fixes, and Admin system flush | <ul><li>Landing page improved</li><li>Fixes in faculty flow and UI concerns (faculty-timetable)</li><li>System flush in admin: flush all schedules (per sem), flush all extensions (every week)</li></ul> |

## Backend Needs

- ~~Timezone in Recent Activities (faculty) inaccurate~~
- ~~API concerns in ID authentication~~
- ~~Seed 1 admin account as per policy~~
- Time extension autoapprovals interconnect with front-ends (e.g: automatic approve after pressing)
- ~~Relevance and inner workings of Issues Logged in admin-reports.php?~~
- Form validation in certain modals (Require some fields to be inputted before submitting/confirming/adding)
- ~~Review the ai_confidence_note because some fields says "AI could not read..." -> change to -> "API could not read""~~
- ~~Tab navigation in admin-reports.php~~ **__resolved Jun 30__**
- ~~Updating stat cards in admin-faculty-management.php~~ **__resolved June 25 -G__**
- ~~Status of Extension Requests (pending, disapproved) in faculty-timetable.php~~ **__resolved Jun 30__**
- ~~Assigned room and subject non-static and fully implemented in faculty-timetable.php~~ **__resolved June 24 -G__**
- ~~Current classes and next classes reflected in faculty-timetable.php~~ **__resolved Jun 30 -G__**
- ~~Faculty Timetable approved/disapproved status (specifically $ext_status variable) is staticcc if implemented pending lang guro ang mabilin (the logic is maging Pending ang button kung mag request si faculty member)~~ **__resolved Jun 29__**

## Future Refinements

- ~~Improve landing page~~
- ~~Organize/transport files for scripting and make OOP flexible for php (all those necessary)~~ __stricken for later(3x) implementation nlng for maintenance -G__

## Personal Issues

- ~~PHPMailer seems to have errors on me when developing -G~~ **__resolved June 24 -G__**



## ⚠️ Outdated ⚠️

| Date | Modules Affected | Notes |
| :--- | :---: | :--- |
| June 4 | Admin sidebar, homepage, and room manage | <ul><li>Admin sidebar edits</li><li>Admin homepage partial edits (lacking)</li><li>Admin room manage full edits</li></ul> |
| June 11 | Admin reports, homepage, CSS consistency | <ul><li>Admin sidebar edits</li><li>Main edits at admin-reports.php</li><li>NOTE: At this point: do not let AI ruin the consistency</li></ul> |
| June 12 | Admin reports subpage, Admin homepage | <ul><li>BACKEND/SCRIPT ISSUE: admin-reports.php - Tab Navigation not working </li><li>BACKEND/SCRIPT ISSUE: Stats cards in admin-faculty-management.php - automatic reflect once Departments are implemented </li></ul> |
| June 13 | Admin faculty | <ul><li>Tooltips added (tooltip.css and tooltip.js)</li><li>BACKEND/SCRIPT ISSUE: Instead of localhost to delete an account in Faculty Management base it off in Delete Modals in Room Management (maintain CSS)</li><li>BACKEND/SCRIPT ISSUE: Implement search bar and Delete Modal in Faculty Directory in admin-faculty-management.php</li><li>BACKEND/SCRIPT ISSUE: Implement Departments in admin-faculty-management.php</li></ul> |
| June 17 | Faculty Homepage, Faculty Timetable, Admin tooltips | <ul><li>On faculty timetable, the extend button will be replaced with a pending icon if requested (there is also approved and disapproved icons too-idk if this has been resolved yet)</li></ul> |
| June 17 | Directory Restructuring & Path Refactoring | <ul><li>Moved files from root to subdirectories (`css/`, `script/`, `pages/`, `python/`, `misc/`)</li><li>Refactored stand-alone `api/` scripts into OOP controllers under `app/controllers/`</li><li>Fixed all broken asset paths, fetch URLs, and spawned script paths across JS, Python, and ESP32 firmware</li></ul> |
| June 23 | Faculty Timetable | <ul><li>BACKEND NEEDS: Subject name per schedule as assigned by Faculty Head (FH)</li></ul> | 
| June 25 | Deployment | <ul><li>Fixed Hostinger: db_connect now has database name and etc. (sorry lex ginoverwrite ko tanan huhu -G)</li><li>Added localhost credentials in db_connect so that you may edit also in xampp-control</li><li>Updated newsqlhere.sql (updated database schema)</li></ul> | 
| June 27 | CSS Consistency | <ul><li>Fixed CSS on each page :3</ul> | 
| June 28 | CSS Styling | <ul><li>Admin Analytics page styling update</li> <li> added slicers, line graph and bar graph</li></ul> 
# View [DOCUMENTATION](PROJECT_DOCUMENTATION.md) for Newest Updates (June 30 2026)
