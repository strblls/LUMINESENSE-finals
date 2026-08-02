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
                selector: '#gestureSection',
                title: 'Gesture Detection',
                desc: 'Control your classroom lights using hand gestures via webcam. Enable the camera and try gestures like pointing up (Row 1), victory sign (Row 2), ILY sign (Row 3), open palm (all on), or fist (all off).',
                position: 'bottom'
            },
            {
                selector: '#gestureSection .light[data-bs-target="#gestureHelpModal"]',
                title: 'Gesture Guide',
                desc: 'Click this button to open the Gesture Guide modal, which shows all available gestures with images and descriptions.',
                position: 'bottom'
            },
            {
                selector: '#lightingControlsWrapper',
                title: 'Lighting Grid',
                desc: 'Toggle individual rows of lights using the switches. The bulb icons show which rows are on. Use the power button to toggle all lights at once.',
                position: 'bottom'
            },
            {
                selector: '#timerDisplay',
                title: 'Time Left',
                desc: 'Shows the remaining time for your current class. Use the Extend button to request more time or End Early to finish the class ahead of schedule.',
                position: 'top'
            },
            {
                selector: '#activityTimeline',
                title: 'Recent Activities',
                desc: 'View a timeline of recent lighting changes, gesture commands, and sensor events in your classroom.',
                position: 'left'
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
                selector: '.faculty-timetable-heading > .d-flex:first-child',
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
                selector: '.dept-info-card:first-child',
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
                desc: 'Before any faculty member (including yourself) can be assigned a schedule, you must first assign coverage for the department. Use the Assign Coverage button (pencil icon) next to "Department Coverage" to add subject areas and subjects. Then use the Edit Assignment button (briefcase icon) next to each faculty member to delegate specific subject areas and subjects to them. Only after coverage is assigned will the calendar icon become available to manage schedules.',
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
                title: 'Schedule Slots',
                desc: 'Each slot displays the room and subject. Use the pencil icon to edit or the trash icon to delete a slot.',
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
                selector: '.main-container > div:nth-child(1) .activity-list.sensor-readings',
                title: 'Occupancy Logs',
                desc: 'View PIR sensor data showing when motion was detected in your classroom. Helps track room occupancy patterns.',
                position: 'bottom'
            },
            {
                selector: '.main-container > div:nth-child(2) .activity-list.sensor-readings',
                title: 'Lighting Logs',
                desc: 'See a history of manual lighting adjustments you made, including which rows were toggled and when.',
                position: 'bottom'
            },
            {
                selector: '.main-container > div:nth-child(3) .activity-list.sensor-readings',
                title: 'Gesture Logs',
                desc: 'Track all gesture-based commands received, including the gesture type and the resulting action taken.',
                position: 'bottom'
            }
        ],

        /* ── Admin Pages ──────────────────────────────────────── */

        'admin-homepage.php': [
            {
                selector: '.stat-row',
                title: 'Dashboard Overview',
                desc: 'At-a-glance summary of your institution: total rooms, rooms currently running, faculty members pending approval, and active extension requests.',
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
                selector: '.analytics-sidebar',
                title: 'Rooms & Filters',
                desc: 'Select a room to view its energy data, or click the filter panel to change the time period and metrics displayed in the charts below.',
                position: 'right'
            },
            {
                selector: '#vawGroup',
                title: 'Live Readings',
                desc: 'Real-time voltage, current, power, energy consumption, and lighting status for the selected room. Data refreshes every 3 seconds.',
                position: 'bottom'
            },
            {
                selector: '.chart-grid',
                title: 'Energy Charts',
                desc: 'Line and bar graphs showing energy trends over time. Switch between voltage, current, and power metrics using the filter panel.',
                position: 'top'
            },
            {
                selector: '.breakdown-header',
                title: 'Daily History',
                desc: 'Tabular breakdown of energy consumption per session, including occupied time and estimated cost for the selected period.',
                position: 'top'
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
                selector: '.faculty-info-card:nth-child(2)',
                title: 'Access Control',
                desc: 'Toggle lighting and gesture control permissions for this faculty member. Changes are saved automatically via API.',
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
                desc: 'Central hub for managing faculty accounts, departments, and pending approvals. Use the tabs below to navigate between sections.',
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
                desc: 'Review and approve pending faculty registrations and extension requests. Configure auto-accept grace periods as needed.',
                position: 'bottom',
                onEnter: 'switchToTab("pending-approvals")'
            },
            {
                selector: '#panel-departments .departments-grid .room-card:first-child',
                fallbackSelector: '#panel-departments',
                title: 'Departments',
                desc: 'Browse, add, edit, or delete academic departments. Each card shows the department head, faculty count, and status. Use the search bar to filter.',
                position: 'bottom',
                onEnter: 'switchToTab("departments");ensureTutorialCards()'
            },
            {
                selector: '#panel-faculty-directory .faculty-grid .room-card:first-child',
                fallbackSelector: '#panel-faculty-directory',
                title: 'Faculty Directory',
                desc: 'Complete list of all faculty members with search and filter options. View details, revoke access, or delete accounts as needed.',
                position: 'bottom',
                onEnter: 'switchToTab("faculty-directory");ensureTutorialCards()'
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
                selector: '.review-card button.medium',
                title: 'Approve or Reject',
                desc: 'Approve the registration to grant access, or reject with a confirmation dialog. Approved faculty can immediately log into the system.',
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
                selector: '.review-card button.medium',
                title: 'Approve or Reject',
                desc: 'Approve to activate the admin account, or reject with a confirmation dialog. Approved admins gain full system access.',
                position: 'top'
            }
        ],
        'admin-profile-settings.php': [
            {
                selector: '.profile-sidebar',
                title: 'Settings Navigation',
                desc: 'Use the sidebar to navigate between Contact Information, Change Credentials, and About System sections.',
                position: 'right'
            },
            {
                selector: '#section-contact',
                title: 'Contact Information',
                desc: 'View your profile details including name and email. Click the Edit button to update your information.',
                position: 'bottom'
            },
            {
                selector: '#section-credentials',
                title: 'Change Credentials',
                desc: 'Update your password with strength validation and confirm-match checking for security.',
                position: 'bottom'
            },
            {
                selector: '#section-about',
                title: 'About System',
                desc: 'View system information and manage your tutorial preferences. Toggle the help icon on admin pages on or off.',
                position: 'bottom'
            }
        ],
        'admin-reports.php': [
            {
                selector: '[data-tab="activity"]',
                title: 'Reports Overview',
                desc: 'Switch between Recent Activity and Room Activity tabs. Use the Export buttons to download reports as CSV or PDF.',
                position: 'bottom'
            },
            {
                selector: '#tab-activity #activityTimeline',
                fallbackSelector: '#tab-activity',
                title: 'Activity Timeline',
                desc: 'Filterable timeline of all room events and admin actions. Use the type and date filters to narrow down results.',
                position: 'bottom',
                onEnter: 'switchTab("activity")'
            },
            {
                selector: '#tab-rooms #roomTable',
                fallbackSelector: '#tab-rooms',
                title: 'Room Activity',
                desc: 'Summary table of room activity with expandable rows. Click any room to view detailed event logs for that room.',
                position: 'top',
                onEnter: 'switchTab("rooms")'
            }
        ],
        'admin-room-manage.php': [
            {
                selector: '.main-container.faculty-timetable-heading',
                title: 'Room Management',
                desc: 'Overview of all classrooms. Use the Guide button for instructions, the search bar to find rooms, and the Subject Area / Subject filter buttons to narrow down the list.',
                position: 'bottom'
            },
            {
                selector: '#roomsGrid .room-card:first-child',
                fallbackSelector: '#roomsGrid',
                title: 'Room Cards',
                desc: 'Each card shows room status (Occupied, Scheduled, or Vacant), current faculty, time slot, and lighting status. Use Edit, Delete, or Inspect to manage.',
                position: 'bottom'
            },
            {
                selector: '#roomSearch',
                title: 'Search & Filters',
                desc: 'Search rooms by name or faculty, or filter by Subject Area and Subject to quickly find specific classrooms.',
                position: 'bottom'
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
                desc: 'Grouped by day, the table shows all scheduled time slots with assigned faculty. Use the Edit and Delete buttons to modify entries.',
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
    var hiddenPinOverlays = [];
    var savedBlurStates = { gesture: null, lighting: null };

    function init() {
        if (isGloballyDisabled() || !steps) return;
        createFloater();
    }

    var hoverTimer = null;

    function createFloater() {
        floater = document.createElement('div');
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
            hoverTimer = setTimeout(hideMenu, 400);
        });
        floater.addEventListener('click', function () {
            if (tutorialActive) exitTutorial();
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
                hoverTimer = setTimeout(hideMenu, 400);
            });
            document.body.appendChild(menu);
        }
        menu.style.display = 'block';
    }

    function hideMenu() {
        if (menu) menu.style.display = 'none';
    }

    function toggleGlobalDisable(disabled) {
        if (disabled) {
            localStorage.setItem(DISABLED_KEY, '1');
            if (floater) floater.style.display = 'none';
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
        restoreBlurStates();
        unlockFacultyPanels();
        removeOverlay();
        if (floater && !isGloballyDisabled()) floater.style.display = 'flex';
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
        // Restore any hidden PIN overlays
        for (var i = 0; i < hiddenPinOverlays.length; i++) {
            hiddenPinOverlays[i].el.style.display = hiddenPinOverlays[i].display;
        }
        hiddenPinOverlays = [];
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

    function unblurControls() {
        var gc = document.getElementById('gestureControlsContent');
        var lc = document.getElementById('lightingControlsContent');
        if (savedBlurStates.gesture === null) {
            if (gc) {
                savedBlurStates.gesture = {
                    filter: gc.style.filter,
                    pointerEvents: gc.style.pointerEvents,
                    hasClass: gc.classList.contains('controls-blurred')
                };
            } else {
                savedBlurStates.gesture = false;
            }
        }
        if (savedBlurStates.lighting === null) {
            if (lc) {
                savedBlurStates.lighting = {
                    filter: lc.style.filter,
                    pointerEvents: lc.style.pointerEvents,
                    hasClass: lc.classList.contains('controls-blurred')
                };
            } else {
                savedBlurStates.lighting = false;
            }
        }
        if (gc) { gc.style.filter = 'none'; gc.style.pointerEvents = 'none'; gc.classList.remove('controls-blurred'); }
        if (lc) { lc.style.filter = 'none'; lc.style.pointerEvents = 'none'; lc.classList.remove('controls-blurred'); }
    }

    function restoreBlurStates() {
        if (savedBlurStates.gesture) {
            var gc = document.getElementById('gestureControlsContent');
            if (gc) {
                gc.style.filter = savedBlurStates.gesture.filter;
                gc.style.pointerEvents = savedBlurStates.gesture.pointerEvents;
                if (savedBlurStates.gesture.hasClass) gc.classList.add('controls-blurred');
            }
        }
        if (savedBlurStates.lighting) {
            var lc = document.getElementById('lightingControlsContent');
            if (lc) {
                lc.style.filter = savedBlurStates.lighting.filter;
                lc.style.pointerEvents = savedBlurStates.lighting.pointerEvents;
                if (savedBlurStates.lighting.hasClass) lc.classList.add('controls-blurred');
            }
        }
        savedBlurStates.gesture = null;
        savedBlurStates.lighting = null;
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

    function ensureTutorialCards() {
        var deptGrid = document.querySelector('#panel-departments .departments-grid');
        if (deptGrid && !deptGrid.querySelector('.room-card')) {
            deptGrid.innerHTML = '<div class="room-card" style="opacity:0.7;pointer-events:none;">' +
                '<div class="room-card-accent accent-vacant"></div>' +
                '<div class="room-card-body">' +
                '<div class="room-card-header"><div><h2 class="room-card-name">Sample Department</h2>' +
                '<div class="room-card-section">Computer Science</div></div>' +
                '<span class="room-status-badge badge-vacant">Active</span></div>' +
                '<hr class="room-card-divider">' +
                '<div class="room-info-row"><p class="d-flex align-items-center gap-2 mb-0">' +
                '<i class="bi bi-person-badge"></i> <span class="room-info-label">Head:</span> ' +
                '<span class="room-info-val">Dr. Sample</span></p></div></div></div>';
        }
        var facGrid = document.querySelector('#panel-faculty-directory .faculty-grid');
        if (facGrid && !facGrid.querySelector('.room-card')) {
            facGrid.innerHTML = '<div class="room-card" style="opacity:0.7;pointer-events:none;">' +
                '<div class="room-card-accent accent-vacant"></div>' +
                '<div class="room-card-body">' +
                '<div class="room-card-header"><div><h2 class="room-card-name">Sample Faculty</h2>' +
                '<div class="room-card-section">Professor</div></div>' +
                '<span class="room-status-badge badge-vacant">Approved</span></div>' +
                '<hr class="room-card-divider">' +
                '<div class="room-info-row"><p class="d-flex align-items-center gap-2 mb-0">' +
                '<i class="bi bi-person-badge"></i> <span class="room-info-label">Email:</span> ' +
                '<span class="room-info-val">sample@example.com</span></p></div></div></div>';
        }
    }

    function showStep(index) {
        if (!steps || index < 0 || index >= steps.length) {
            exitTutorial();
            return;
        }
        var step = steps[index];
        removeHighlight();

        // Execute step onEnter callback if present (before element lookup)
        if (step.onEnter) {
            try { var fn = new Function(step.onEnter); fn(); } catch (e) {}
        }

        var el = document.querySelector(step.selector);
        if (!el && step.fallbackSelector) {
            el = document.querySelector(step.fallbackSelector);
        }
        if (!el) {
            if (index < steps.length - 1) {
                showStep(index + 1);
            } else {
                exitTutorial();
            }
            return;
        }

        // Temporarily hide PIN overlays within the highlighted element
        hiddenPinOverlays = [];
        var pinSelectors = ['#gesturePinOverlay', '#lightingPinOverlay'];
        for (var p = 0; p < pinSelectors.length; p++) {
            var pinEl = el.querySelector(pinSelectors[p]);
            if (pinEl && pinEl.style.display !== 'none') {
                hiddenPinOverlays.push({ el: pinEl, display: pinEl.style.display });
                pinEl.style.display = 'none';
            }
        }

        // Unblur gesture/lighting controls on relevant home page steps
        if (pageName === 'faculty-home.php' && index <= 2) {
            unblurControls();
        } else {
            restoreBlurStates();
        }

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
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        // Position bubble
        var rect = el.getBoundingClientRect();
        var pos = step.position || 'bottom';
        var bubbleHTML =
            '<div class="tutorial-bubble-header">' +
                '<span class="tutorial-step-counter">' + (index + 1) + ' / ' + steps.length + '</span>' +
                '<button class="tutorial-close-btn" id="tutorialExitBtn"><i class="bi bi-x-lg"></i></button>' +
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
        var bubbleWidth = 340;
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
        if (prevBtn) prevBtn.addEventListener('click', function () { showStep(index - 1); });

        var nextBtn = document.getElementById('tutorialNextBtn');
        if (nextBtn) nextBtn.addEventListener('click', function () { showStep(index + 1); });

        var doneBtn = document.getElementById('tutorialDoneBtn');
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
