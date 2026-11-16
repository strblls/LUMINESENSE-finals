(function () {
    'use strict';

    var pageName = window.location.pathname.split('/').pop();

    var DISABLED_KEY = 'lum_tutorial_disabled';

    function isGloballyDisabled() {
        return localStorage.getItem(DISABLED_KEY) === '1';
    }

    var tutorialData = {
        'faculty-home.php': [
            {
                selector: "#gestureSection",
                title: "Gesture Detection",
                desc: "Start the camera to use gestures when your classroom controls are available. Open Gesture Guide for the supported signs and how to confirm queued commands."
            },
            {
                selector: "#gestureSection [data-bs-target=\"#gestureHelpModal\"]",
                title: "Gesture Guide",
                desc: "Review the hand signs for individual lighting rows, all lights, and confirming or clearing commands."
            },
            {
                selector: "#lightingControlsWrapper",
                title: "Lighting Controls",
                desc: "Use the row switches or the master power button when lighting control is available for your assigned room."
            },
            {
                selector: "#timerDisplay",
                title: "Class Timer",
                desc: "Check the remaining time for the current class. Use the adjacent actions to request an extension or end the class early when available."
            },
            {
                selector: "#activityTimeline",
                title: "Recent Activity",
                desc: "Review recent classroom events and lighting changes."
            }
        ],
        'faculty-timetable.php': [
            {
                selector: '.faculty-timetable-heading',
                title: 'Weekly Timetable',
                desc: 'Your complete class schedule is displayed here. Use the buttons at the top to access Time Left, Class Details, Extension Requests, and more.',
                position: 'bottom'
            },
            {
                selector: '[data-panel="panelTimeLeft"]',
                title: 'Quick Actions',
                desc: 'Use these buttons to view Time Left info, Class Details, or submit and manage Extension Requests for your classes.',
                position: 'bottom'
            },
            {
                selector: '.weekly-schedule-grid',
                title: 'Schedule Grid',
                desc: 'The grid shows your weekly schedule across all days. Each column represents a day with its scheduled classes.',
                position: 'top'
            },
            {
                selector: '.day-card:nth-child(2) .slot-row:first-child, .slot-row',
                fallbackSelector: '.day-card:nth-child(2)',
                title: 'Schedule Slot',
                desc: 'Each slot shows the time, room, and subject. Click the eye icon to view details or the clock icon to request a time extension. If no slots exist, they will appear here once scheduled.',
                position: 'right'
            },
            {
                selector: '#exportPdfBtn',
                title: 'Export PDF',
                desc: 'Export your class schedule as a PDF document for printing or record-keeping.',
                position: 'left'
            }
        ],
        'faculty-head-timetable.php': [
            {
                selector: '#deptGrid .dept-info-card',
                title: 'Department Coverage',
                desc: 'View the subject areas assigned to this department. Use the eye icon to see details or the pencil icon to add or edit coverage (subject areas and subjects).',
                position: 'bottom'
            },
            {
                selector: '.dept-body > .m-2 > .dept-info-card:nth-child(2)',
                fallbackSelector: '.dept-info-card:last-child',
                title: 'Faculty Head Assignment',
                desc: 'Your role as Faculty Head includes assigning subject areas and subjects to yourself using the briefcase icon. These assignments are required before you can view or add your schedule.',
                position: 'bottom'
            },
            {
                selector: '.faculty-member-card',
                title: 'Faculty Members',
                desc: 'Browse all faculty members in your department. Use the eye icon to view their coverage, the briefcase icon to edit their subject area assignments, and the calendar icon to view or add their schedule once coverage is assigned.',
                position: 'left'
            },
            {
                selector: '#deptGrid',
                title: 'Prerequisite: Assign Coverage First',
                desc: 'Before any faculty member (including yourself) can be assigned a schedule, you must first assign coverage for the department. Use the Assign Coverage button (pencil icon) next to "Department Coverage" to add subject areas and subjects. Then use the Edit Assignment button (briefcase icon) next to each faculty member to delegate specific subject areas and subjects to them. After assigning coverage, use the calendar icon to manage the member’s schedule.',
                position: 'top'
            }
        ],
        'faculty-head-membersched.php': [
            {
                selector: '.weekly-schedule-grid',
                title: 'Weekly Schedule',
                desc: 'View the full weekly schedule for this faculty member across all days. Each column represents a day of the week.',
                position: 'top'
            },
            {
                selector: '.slot-content',
                fallbackSelector: '.weekly-schedule-grid',
                title: 'Schedule Slots',
                desc: 'Scheduled slots show the room and subject. Use the pencil or trash icon on a slot to edit or remove it.',
                position: 'right'
            },
            {
                selector: 'button[title="Add Schedule Slot"]',
                title: 'Add Schedule Slot',
                desc: 'Create a new schedule entry for this faculty member. Assign the day, time, room, and subject.',
                position: 'top'
            }
        ],
        'faculty-readings.php': [
            {
                selector: ".sensor-readings",
                targetIndex: 0,
                title: "Occupancy Logs",
                desc: "Review PIR motion events recorded in your classroom."
            },
            {
                selector: ".sensor-readings",
                targetIndex: 1,
                title: "Lighting Logs",
                desc: "Review recorded lighting changes and when they occurred."
            },
            {
                selector: ".sensor-readings",
                targetIndex: 2,
                title: "Gesture Logs",
                desc: "Review recorded gesture commands and their resulting actions."
            }
        ],

        /* ── Admin Pages ──────────────────────────────────────── */

        'admin-overview.php': [
            {
                selector: "#liveToggleBtn",
                title: "Live View",
                desc: "Turn Live on for the live dashboard. Turn it off to return to the Rooms & Faculty view."
            },
            {
                selector: "#hroomsList",
                title: "Rooms",
                desc: "Browse room status, assigned faculty, and lighting information. Select a room to focus the overview."
            },
            {
                selector: "#facultyList",
                title: "Faculty",
                desc: "Browse faculty accounts and any class active when this page loaded. Open a faculty card for details and the weekly schedule."
            },
            {
                selector: "#maximizeFacultyBtn",
                title: "Schedule and Session History",
                desc: "Open Maximize to inspect the faculty timetable by day or search past lighting sessions in History."
            },
            {
                selector: "#overviewLineChart",
                title: "Readings Chart",
                desc: "Use Period and Metric to compare voltage, current, and power for the selected rooms."
            }
        ],

        'faculty-profile-settings.php': [
            {
                selector: ".profile-sidebar",
                title: "Profile Settings",
                desc: "Choose a section to manage your faculty profile."
            },
            {
                selector: "[data-section=\"contact\"]",
                title: "Contact Information",
                desc: "Open Contact Information to review your profile details."
            },
            {
                selector: "[data-section=\"teaching\"]",
                title: "Teaching Information",
                desc: "Open Teaching Information to review your department and teaching assignments."
            },
            {
                selector: "[data-section=\"credentials\"]",
                title: "Change Credentials",
                desc: "Open Change Credentials to update your password or manage your classroom control PIN."
            },
            {
                selector: "[data-section=\"about\"]",
                title: "Help Icon",
                desc: "Open About System to show or hide the Help icon on this browser."
            }
        ],

        'room-light-view.php': [
            {
                selector: "#statusBadge",
                title: "Room Status",
                desc: "Check the status of the selected classroom."
            },
            {
                selector: "#facultySection",
                title: "Scheduled Faculty",
                desc: "See who is assigned to the room and check the time section for the scheduled slot."
            },
            {
                selector: "#allLightsBtn",
                title: "Lighting Controls",
                desc: "When controls are available, use the master button or the switches below to change the lighting rows."
            }
        ],

        'admin-homepage.php': [
            {
                selector: '.stat-row',
                title: 'Dashboard Overview',
                desc: 'Review the counts for rooms, running rooms, pending faculty approvals, and active extension requests.',
                position: 'bottom'
            },
            {
                selector: '#hierarchySection',
                title: 'Faculty Hierarchy',
                desc: 'Interactive org chart showing departments, their faculty heads, and assigned members. Drag to pan, click a department node for details.',
                position: 'top'
            },
            {
                selector: '#rooms-list',
                title: 'Rooms',
                desc: 'Quick-access list of all rooms in your institution. Click any room to view its details and current schedule.',
                position: 'top'
            },
            {
                selector: '#depts-list',
                title: 'Departments',
                desc: 'Browse all departments with their assigned subject areas. Click for more information about each department.',
                position: 'left'
            },
            {
                selector: '.mini-calendar',
                title: 'Calendar',
                desc: 'Monthly calendar view with schedule overlays. Navigate between months and click a date to see scheduled activities for that day.',
                position: 'left'
            },
            {
                selector: '#activityTimeline',
                title: 'Recent Activity',
                desc: 'Timeline of recent room events and admin actions across your institution. Monitor lighting changes, motion detection, and system activities.',
                position: 'left'
            }
        ],
        'admin-analytics.php': [
            {
                selector: "[data-panel=\"panelFilterInfo\"]",
                title: "Analytics Filters",
                desc: "Choose a room, period, and metric to scope the analytics."
            },
            {
                selector: "#savingsCard",
                title: "Energy Savings",
                desc: "Expand the card to compare current and previous energy use. A previous period with recorded energy is required to calculate a percentage."
            },
            {
                selector: "#vawGroup",
                title: "Live Readings",
                desc: "See the latest available voltage, current, power, energy, and lighting status."
            },
            {
                selector: "#lineGraphCard",
                title: "Reading Trends",
                desc: "Use the line and bar charts to compare readings over the selected period."
            },
            {
                selector: "#historyCard",
                title: "History",
                desc: "Review the recorded totals for the selected period."
            },
            {
                selector: "#archiveOpenBtn, #exitArchiveBtn",
                title: "Archives",
                desc: "Open an archived date to review historical analytics. Use Exit Archive to return to current data."
            },
            {
                selector: "button[title=\"Export CSV\"]",
                title: "Export Analytics",
                desc: "Use Export CSV or Export PDF to download analytics data."
            }
        ],
        'admin-faculty-card.php': [
            {
                selector: '.profile-header',
                title: 'Faculty Profile',
                desc: 'View detailed profile information for this faculty member, including their role and assigned room access permissions.',
                position: 'bottom'
            },
            {
                selector: '.faculty-info-card:has([onchange*="savePermission"])',
                title: 'Access Control',
                desc: 'Use the switches to grant or revoke lighting and gesture controls for this faculty member. Changes save automatically.',
                position: 'bottom'
            },
            {
                selector: '.weekly-schedule-grid',
                title: 'Weekly Schedule',
                desc: 'Full weekly schedule showing this faculty member\'s assigned classes across all days, with time and room details.',
                position: 'top'
            }
        ],
        'admin-faculty-management.php': [
            {
                selector: '#facultyHeading',
                title: 'Faculty Management',
                desc: 'Open Pending Approvals, Departments, or Faculty Directory to manage faculty accounts and assignments.',
                position: 'bottom'
            },
            {
                selector: '[data-tab="pending-approvals"]',
                title: 'Navigation Tabs',
                desc: 'Switch between Pending Approvals, Departments, and Faculty Directory to manage different aspects of faculty administration.',
                position: 'bottom'
            },
            {
                selector: '#panel-pending-approvals',
                title: 'Pending Approvals',
                desc: 'Review and approve pending faculty registrations and extension requests. Check the status of each request and use the available review actions.',
                position: 'bottom',
                onEnter: function () { window.switchToTab("pending-approvals"); }
            },
            {
                selector: '#panel-departments .departments-grid .room-card:first-child',
                fallbackSelector: '#panel-departments',
                title: 'Departments',
                desc: 'Browse, add, edit, or delete academic departments. Each card shows the department head, faculty count, and status. Use the search bar to filter.',
                position: 'bottom',
                onEnter: function () { window.switchToTab("departments"); }
            },
            {
                selector: '#panel-faculty-directory .faculty-grid .room-card:first-child',
                fallbackSelector: '#panel-faculty-directory',
                title: 'Faculty Directory',
                desc: 'Complete list of all faculty members with search and filter options. View details, revoke access, or delete accounts as needed.',
                position: 'bottom',
                onEnter: function () { window.switchToTab("faculty-directory"); }
            }
        ],
        'admin-faculty-review.php': [
            {
                selector: '.review-card',
                title: 'Faculty Review',
                desc: 'Review pending faculty registration details including their uploaded ID and personal information before making an approval decision.',
                position: 'bottom'
            },
            {
                selector: '.review-card .ai-badge',
                fallbackSelector: '.review-card',
                title: 'AI Verification',
                desc: 'AI-powered name matching compares the uploaded ID against the registered name. Results show Matched, Mismatched, or Unreadable.',
                position: 'bottom'
            },
            {
                selector: '.review-card button[title^="Approve"], .review-card button[title^="Reject"]',
                title: 'Approve or Reject',
                desc: 'Use Approve or Reject for a pending faculty registration after reviewing the submitted details.',
                position: 'top'
            }
        ],
        'admin-admin-card.php': [
            {
                selector: '.review-card',
                title: 'Admin Review',
                desc: 'Review pending administrator registration details including their uploaded ID and personal information before approving.',
                position: 'bottom'
            },
            {
                selector: '.review-card .ai-badge',
                fallbackSelector: '.review-card',
                title: 'AI Verification',
                desc: 'AI compares the uploaded ID image against the registered name to verify identity before admin account activation.',
                position: 'bottom'
            },
            {
                selector: '.review-card button[title^="Approve"], .review-card button[title^="Reject"]',
                title: 'Approve or Reject',
                desc: 'Use Approve or Reject for a pending administrator registration after reviewing the submitted details.',
                position: 'top'
            }
        ],
        'admin-profile-settings.php': [
            {
                selector: ".profile-sidebar",
                title: "Profile Settings",
                desc: "Choose a section to manage your admin profile or review system settings."
            },
            {
                selector: "[data-section=\"contact\"]",
                title: "Contact Information",
                desc: "Open Contact Information to view or edit your name and email."
            },
            {
                selector: "[data-section=\"credentials\"]",
                title: "Change Credentials",
                desc: "Open Change Credentials to update your password through the verification flow."
            },
            {
                selector: "[data-section=\"flush\"]",
                title: "Data Reset",
                desc: "Open the reset section to review the data affected and the confirmation required before scheduling a reset."
            },
            {
                selector: "[data-section=\"archives\"]",
                title: "Archives",
                desc: "Open Archives to review available archived records."
            },
            {
                selector: "[data-section=\"about\"]",
                title: "Help Icon",
                desc: "Open About System to show or hide the Help icon on this browser."
            }
        ],
        'admin-reports.php': [
            {
                selector: "[data-landing=\"faculty\"]",
                title: "Faculty Reports",
                desc: "Open Faculty Reports to review recorded activity by faculty member and export the results."
            },
            {
                selector: "[data-landing=\"status\"]",
                title: "Status Reports",
                desc: "Open Status Reports to review room activity, status summaries, and reported issues."
            },
            {
                selector: "#statusTable",
                fallbackSelector: "#reportToolbar",
                title: "Report Filters and Export",
                desc: "Use the report filters to narrow the results and the toolbar to export the current report."
            }
        ],
        'admin-timetable-manage.php': [
            {
                selector: '.room-selector-row',
                title: 'Schedule Management',
                desc: 'Select a room from the dropdown to view and manage its weekly schedule. Use the Add Schedule Slot button to create new time slots.',
                position: 'bottom'
            },
            {
                selector: '#schedTable',
                title: 'Schedule Table',
                desc: 'Check each day for scheduled time slots and assigned faculty. Use the row actions to edit or remove a slot.',
                position: 'top'
            }
        ]
    };

    var steps = tutorialData[pageName] || null;

    var floater, menu, overlay, bubble;
    var currentStep = 0;
    var tutorialActive = false;
    var currentHighlight = null;
    var highlightOriginals = {};

    function init() {
        if (isGloballyDisabled() || !steps) return;
        createFloater();
        document.addEventListener('click', function (event) {
            if (menu && !menu.contains(event.target) && !floater.contains(event.target)) hideMenu();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') return;
            if (tutorialActive) exitTutorial();
            else if (menu && menu.style.display === 'block') {
                hideMenu();
                floater.focus();
            }
        });
    }

    var hoverTimer = null;

    function createFloater() {
        floater = document.createElement('button');
        floater.type = 'button';
        floater.style.border = '0';
        floater.setAttribute('aria-label', 'Help');
        floater.setAttribute('aria-expanded', 'false');
        floater.id = 'tutorialFloater';
        floater.className = 'tutorial-floater';
        floater.innerHTML = '<i class="bi bi-info-circle"></i>';
        floater.addEventListener('mouseenter', function () {
            if (tutorialActive) return;
            if (hoverTimer) clearTimeout(hoverTimer);
            hoverTimer = setTimeout(showMenu, 200);
        });
        floater.addEventListener('mouseleave', function () {
            if (hoverTimer) clearTimeout(hoverTimer);
            hoverTimer = setTimeout(hideMenuUnlessFocused, 400);
        });
        floater.addEventListener('click', function () {
            if (hoverTimer) clearTimeout(hoverTimer);
            if (!tutorialActive) {
                showMenu();
                menu.querySelector('#tutorialStartBtn').focus();
            }
        });
        document.body.appendChild(floater);
    }

    function showMenu() {
        if (!menu) {
            menu = document.createElement('div');
            menu.id = 'tutorialMenu';
            menu.className = 'tutorial-menu';
            menu.innerHTML =
                '<div class="tutorial-menu-header">Help</div>' +
                '<button class="tutorial-menu-btn" id="tutorialStartBtn"><i class="bi bi-play-circle me-2"></i>Take a Tour</button>' +
                '<label class="tutorial-menu-toggle" id="tutorialDisableToggle">' +
                    '<input type="checkbox"' + (isGloballyDisabled() ? ' checked' : '') + '>' +
                    '<span class="ms-2">Don\'t show on other pages</span>' +
                '</label>';
            menu.querySelector('#tutorialStartBtn').addEventListener('click', function (e) {
                e.stopPropagation();
                hideMenu();
                startTutorial();
            });
            menu.querySelector('input[type="checkbox"]').addEventListener('change', function (e) {
                e.stopPropagation();
                toggleGlobalDisable(this.checked);
            });
            menu.addEventListener('mouseenter', function () {
                if (hoverTimer) clearTimeout(hoverTimer);
            });
            menu.addEventListener('mouseleave', function () {
                hoverTimer = setTimeout(hideMenuUnlessFocused, 400);
            });
            document.body.appendChild(menu);
        }
        menu.style.display = 'block';
        floater.setAttribute('aria-expanded', 'true');
    }

    function hideMenuUnlessFocused() {
        if (!menu || !menu.contains(document.activeElement)) hideMenu();
    }

    function hideMenu() {
        if (menu) menu.style.display = 'none';
        floater.setAttribute('aria-expanded', 'false');
    }

    function toggleGlobalDisable(disabled) {
        if (disabled) {
            localStorage.setItem(DISABLED_KEY, '1');
            if (floater) floater.style.display = 'none';
            hideMenu();
        } else {
            localStorage.removeItem(DISABLED_KEY);
            if (floater) floater.style.display = 'flex';
        }
    }

    function startTutorial() {
        if (!steps || steps.length === 0) return;
        tutorialActive = true;
        currentStep = 0;
        if (floater) floater.style.display = 'none';
        createOverlay();
        showStep(currentStep);
    }

    function exitTutorial() {
        tutorialActive = false;
        unlockFacultyPanels();
        removeOverlay();
        if (floater && !isGloballyDisabled()) {
            floater.style.display = 'flex';
            floater.focus();
        }
    }

    function createOverlay() {
        overlay = document.createElement('div');
        overlay.id = 'tutorialOverlay';
        overlay.className = 'tutorial-overlay';
        overlay.addEventListener('click', exitTutorial);
        document.body.appendChild(overlay);

        bubble = document.createElement('div');
        bubble.id = 'tutorialBubble';
        bubble.className = 'tutorial-bubble';
        document.body.appendChild(bubble);
    }

    function removeOverlay() {
        if (overlay) { overlay.remove(); overlay = null; }
        if (bubble) { bubble.remove(); bubble = null; }
        removeHighlight();
    }

    function removeHighlight() {
        if (currentHighlight) {
            var keys = Object.keys(highlightOriginals);
            for (var i = 0; i < keys.length; i++) {
                var prop = keys[i];
                currentHighlight.style[prop] = highlightOriginals[prop];
            }
            highlightOriginals = {};
            currentHighlight = null;
        }
    }

    function saveOriginal(el, prop) {
        if (!(prop in highlightOriginals)) {
            highlightOriginals[prop] = el.style[prop];
        }
    }

    var panelsLocked = false;

    function lockFacultyPanels() {
        if (panelsLocked) return;
        panelsLocked = true;
        var containers = document.querySelectorAll('.landing-panels, .tab-btn');
        for (var i = 0; i < containers.length; i++) {
            var el = containers[i];
            if (!el.hasAttribute('data-tut-ptr')) {
                el.setAttribute('data-tut-ptr', el.style.pointerEvents || '');
            }
            el.style.pointerEvents = 'none';
        }
    }

    function unlockFacultyPanels() {
        if (!panelsLocked) return;
        panelsLocked = false;
        var els = document.querySelectorAll('[data-tut-ptr]');
        for (var i = 0; i < els.length; i++) {
            els[i].style.pointerEvents = els[i].getAttribute('data-tut-ptr');
            els[i].removeAttribute('data-tut-ptr');
        }
    }

    function visibleTarget(selector, targetIndex) {
        var el = typeof targetIndex === 'number'
            ? document.querySelectorAll(selector)[targetIndex]
            : Array.from(document.querySelectorAll(selector)).find(isVisible);
        return el && isVisible(el) ? el : null;
    }

    function isVisible(el) {
        return el.getClientRects().length > 0 && getComputedStyle(el).visibility === 'visible';
    }

    function showStep(index, direction) {
        if (!steps || index < 0 || index >= steps.length) {
            exitTutorial();
            return;
        }
        var step = steps[index];
        removeHighlight();

        if (step.onEnter) step.onEnter();

        var el = visibleTarget(step.selector, step.targetIndex);
        if (!el && step.fallbackSelector) {
            el = visibleTarget(step.fallbackSelector);
        }
        if (!el) {
            var offset = direction || 1;
            if (index + offset < 0 && currentStep > index) {
                showStep(currentStep);
            } else {
                showStep(index + offset, offset);
            }
            return;
        }
        currentStep = index;

        // Lock panel interaction on admin-faculty-management page
        if (pageName === 'admin-faculty-management.php') {
            lockFacultyPanels();
        }

        // Highlight element
        saveOriginal(el, 'outline');
        saveOriginal(el, 'outlineOffset');
        saveOriginal(el, 'zIndex');
        var curPos = getComputedStyle(el).position;
        if (curPos === 'static') {
            saveOriginal(el, 'position');
            el.style.position = 'relative';
        }
        el.style.outline = '3px solid var(--secondary-color-4, #9b00e9)';
        el.style.outlineOffset = '2px';
        el.style.zIndex = '9997';

        var bg = getComputedStyle(el).backgroundColor;
        if (bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
            saveOriginal(el, 'backgroundColor');
            el.style.backgroundColor = '#fff';
        }
        var br = el.style.borderRadius || getComputedStyle(el).borderRadius;
        if (br === '0px' || br === '0px 0px 0px 0px') {
            saveOriginal(el, 'borderRadius');
            el.style.borderRadius = '10px';
        }
        currentHighlight = el;

        // Scroll into view
        el.scrollIntoView({ behavior: 'instant', block: 'center' });

        // Position bubble
        var rect = el.getBoundingClientRect();
        var pos = step.position || 'bottom';
        var bubbleHTML =
            '<div class="tutorial-bubble-header">' +
                '<span class="tutorial-step-counter">' + (index + 1) + ' / ' + steps.length + '</span>' +
                '<button class="tutorial-close-btn" id="tutorialExitBtn" aria-label="Close tour"><i class="bi bi-x-lg"></i></button>' +
            '</div>' +
            '<div class="tutorial-bubble-body">' +
                '<h4 class="tutorial-bubble-title">' + step.title + '</h4>' +
                '<p class="tutorial-bubble-desc">' + step.desc + '</p>' +
            '</div>' +
            '<div class="tutorial-bubble-footer">' +
                (index > 0 ? '<button class="tutorial-nav-btn tutorial-prev-btn" id="tutorialPrevBtn"><i class="bi bi-chevron-left"></i> Back</button>' : '<span></span>') +
                (index < steps.length - 1
                    ? '<button class="tutorial-nav-btn tutorial-next-btn" id="tutorialNextBtn">Next <i class="bi bi-chevron-right"></i></button>'
                    : '<button class="tutorial-nav-btn tutorial-done-btn" id="tutorialDoneBtn"><i class="bi bi-check-lg"></i> Done</button>') +
            '</div>';

        bubble.innerHTML = bubbleHTML;

        // Position
        bubble.style.maxWidth = 'calc(100vw - 16px)';
        var bubbleWidth = bubble.offsetWidth;
        var bubbleHeight = bubble.offsetHeight || 220;
        var top, left;

        switch (pos) {
            case 'top':
                top = rect.top - bubbleHeight - 12;
                left = rect.left + rect.width / 2 - bubbleWidth / 2;
                break;
            case 'bottom':
                top = rect.bottom + 12;
                left = rect.left + rect.width / 2 - bubbleWidth / 2;
                break;
            case 'left':
                top = rect.top + rect.height / 2 - bubbleHeight / 2;
                left = rect.left - bubbleWidth - 12;
                break;
            case 'right':
                top = rect.top + rect.height / 2 - bubbleHeight / 2;
                left = rect.right + 12;
                break;
            default:
                top = rect.bottom + 12;
                left = rect.left + rect.width / 2 - bubbleWidth / 2;
        }

        // Keep within viewport
        if (top < 8) top = 8;
        if (left < 8) left = 8;
        if (left + bubbleWidth > window.innerWidth - 8) left = window.innerWidth - bubbleWidth - 8;
        if (top + bubbleHeight > window.innerHeight - 8) {
            top = rect.top - bubbleHeight - 12;
            if (top < 8) top = 8;
        }

        bubble.style.top = top + 'px';
        bubble.style.left = left + 'px';

        // Wire buttons
        var exitBtn = document.getElementById('tutorialExitBtn');
        if (exitBtn) exitBtn.addEventListener('click', exitTutorial);

        var prevBtn = document.getElementById('tutorialPrevBtn');
        if (prevBtn) prevBtn.addEventListener('click', function () { showStep(index - 1, -1); });

        var nextBtn = document.getElementById('tutorialNextBtn');
        if (nextBtn) nextBtn.addEventListener('click', function () { showStep(index + 1); });

        var doneBtn = document.getElementById('tutorialDoneBtn');
        (nextBtn || doneBtn || exitBtn).focus({ preventScroll: true });
        if (doneBtn) doneBtn.addEventListener('click', function () {
            exitTutorial();
            // Show completion toast
            showToast('Tour complete! You can restart anytime using the info icon.');
        });
    }

    function showToast(msg) {
        var t = document.createElement('div');
        t.className = 'tutorial-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.classList.add('show'); }, 10);
        setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 400); }, 3000);
    }

    // Init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
