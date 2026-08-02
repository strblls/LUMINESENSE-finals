<?php
$page_title = 'Faculty Dashboard';

require_once __DIR__ . "/../../src/Session/session_guard.php";
check_faculty();
require_once __DIR__ . "/../../src/Config/db_connect.php";
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . "/../../src/Includes/faculty-head.php";

/** @var $faculty_name string */
/** @var $faculty_email string */
/** @var $initials string */
/** @var $first_name string */
/** @var $faculty_id int */
/** @var $classroom_id int */
/** @var $logs array */
/** @var $gesture_logs array */
/** @var $schedules array */

$active_schedule = null;
$now   = date('H:i:s');
$today = date('l');

$fid      = (int)$faculty_id;
$today_e  = $conn->real_escape_string($today);
$now_e    = $conn->real_escape_string($now);

$r = $conn->query("
    SELECT s.id, s.classroom_id, s.start_time, s.end_time, s.extended_until, c.room_name
    FROM schedules s
    JOIN classrooms c ON c.id = s.classroom_id
    WHERE s.classroom_id = $classroom_id
      AND s.day_of_week = '$today_e'
      AND s.start_time <= '$now_e'
      AND (s.end_time >= '$now_e' OR s.extended_until >= '$now_e')
    LIMIT 1
");
if ($r && $row = $r->fetch_assoc()) {
    $active_schedule = $row;
}

// Check if faculty has set a PIN
$pin_check = $conn->prepare("SELECT pin_hash FROM faculty_permissions WHERE faculty_id = ?");
$pin_check->bind_param("i", $faculty_id);
$pin_check->execute();
$pin_result = $pin_check->get_result();
$has_pin = $pin_result && $pin_result->fetch_assoc() && !empty($pin_result->fetch_assoc()['pin_hash']);
// Fix: actually check the row properly
$pin_row = $pin_result->fetch_assoc();
$has_pin = $pin_row && !empty($pin_row['pin_hash']);

$schedules = [];
$sched_res = $conn->query("
    SELECT s.*, sub.name AS subject_name
    FROM schedules s
    LEFT JOIN subjects sub ON sub.id = s.subject_id
    WHERE s.classroom_id = $classroom_id
    ORDER BY FIELD(s.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
");
if ($sched_res) {
    while ($srow = $sched_res->fetch_assoc()) $schedules[] = $srow;
}
?>
    <link rel="stylesheet" href="../../css/pages/faculty-home.css">

    <script>
        const CLASSROOM_ID = <?= (int) $classroom_id ?>;
        const FACULTY_ID = <?= (int) $faculty_id ?>;
        const HAS_ACTIVE_SCHEDULE = <?= $active_schedule ? 'true' : 'false' ?>;
    </script>
    <script src="../../js/faculty/faculty-home.js"></script>

    <!-- Gesture detection script -->
    <script type="module" src="../../js/faculty/initialize-gesture.js?v=<?= time() ?>"></script>

    <!--  PIN SETUP MODAL (first login)-->
    <?php if (!$has_pin): ?>
    <div id="pinSetupOverlay" class="page-timeout-overlay">
        <div class="page-timeout-modal">
            <i class="bi bi-shield-lock" style="font-size:2.5rem;color:var(--secondary-color-4);margin-bottom:0.75rem;"></i>
            <h5 class="schedule-ended-title">Set Your PIN</h5>
            <p class="schedule-ended-text">Set a 4-digit personal PIN for quick access to controls.</p>
            <div class="mt-3 d-flex flex-column align-items-center gap-2">
                <input type="password" id="pinSetupInput" maxlength="4" pattern="\d*" inputmode="numeric"
                       class="form-control text-center" style="width:140px;font-size:1.5rem;letter-spacing:4px;" placeholder="â€¢â€¢â€¢â€¢">
                <input type="password" id="pinSetupConfirm" maxlength="4" pattern="\d*" inputmode="numeric"
                       class="form-control text-center" style="width:140px;font-size:1.5rem;letter-spacing:4px;" placeholder="Confirm">
                <div><span id="pinSetupError" class="text-danger small"></span></div>
                <button class="light" id="pinSetupSubmit">Save PIN</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!--  GESTURE HELP MODAL - 2-column grid, modal-xl, centered -->
    <div class="profile-details-modal gesture-help modal fade" id="gestureHelpModal" tabindex="-1" aria-labelledby="gestureHelpLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="gestureHelpLabel">
                        <i class="bi bi-hand-index-thumb me-2"></i>Gesture Guide
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0;">

                        <!-- 1 Finger - Row 1 -->
                        <div class="gesture-guide-row" style="border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/pointing-up.png" alt="Pointing up - 1 finger">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 1st row of lights ON/OFF</h4>
                                <strong>Pointing Up / 1 Finger</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Point only your index finger upward.</li>
                                        <li>All other fingers curled down.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 1st row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Open Palm - All ON -->
                        <div class="gesture-guide-row" style="border-bottom: none; border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/open-palm.png" alt="Open palm">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn all rows of lights ON</h4>
                                <strong>Open Palm</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Extend all five fingers wide and spread them open, facing the camera.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Victory - Row 2 -->
                        <div class="gesture-guide-row">
                            <div class="gesture-guide-img">
                                <img src="../../images/victory.png" alt="Victory - 2 fingers">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 2nd row of lights ON/OFF</h4>
                                <strong>Victory / 2 Fingers</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Raise index and middle fingers in a V shape, remaining fingers curled.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 2nd row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Closed Fist - All OFF -->
                        <div class="gesture-guide-row" style="border-bottom: none;">
                            <div class="gesture-guide-img">
                                <img src="../../images/closed-fist.png" alt="Closed fist">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn all rows of lights OFF</h4>
                                <strong>Closed Fist</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Curl all fingers tightly into a fist with no fingers extended.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- ILY - Row 3 -->
                        <div class="gesture-guide-row" style="border-right: 1px solid #dee2e6;">
                            <div class="gesture-guide-img">
                                <img src="../../images/ily.png" alt="ILY sign">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Turn 3rd row of lights ON/OFF</h4>
                                <strong>"I Love You" Sign</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Extend thumb, index, and pinky fingers. </li>
                                        <li>Middle and ring fingers must be curled down.</li>
                                        <li>Perform the confirmation gesture to formally execute gesture.</li>
                                        <li>Perform this gesture to turn the 3rd row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>

                        <!-- Thumbs Up - Toggle -->
                        <div class="gesture-guide-row">
                            <div class="gesture-guide-img">
                                <img src="../../images/thumbs-up.png" alt="Thumbs up">
                            </div>
                            <div class="gesture-guide-text">
                                <h4 class="bold">Confirmation Gesture</h4>
                                <strong>Thumbs Up</strong>
                                <span>To perform:
                                    <ul>
                                        <li>Close all fingers into a fist with only the thumb pointing upward.</li>
                                        <li>Use this gesture to confirm and execute the currently detected gesture command.</li>
                                        <li>For example, if the system detects a "pointing up" gesture, it will wait for you to perform the "thumbs up" gesture to confirm that you want to turn the 1st row of lights ON or OFF.</li>
                                    </ul>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!--  ACTIVITY DETAILS MODAL CHANGE 2: Added modal-dialog-centered -->
    <div class="profile-details-modal modal fade" id="activityDetailsModal" tabindex="-1" aria-labelledby="activityDetailsLabel"
        aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="activityDetailsLabel">
                        <i class="bi bi-clock-history me-2"></i>Recent Activity Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <?php if (empty($logs)): ?>
                        <p class="text-muted text-center py-4">No recent activity yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle" style="font-size:0.85rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3">Event</th>
                                        <th>Room</th>
                                        <th>Row Affected</th>
                                        <th>Triggered By</th>
                                        <th class="pe-3">Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <!-- Event type badge -->
                                            <td class="ps-3">
                                                <?php
                                                $type = $log['event_type'] ?? '';
                                                $badgeClass = match (true) {
                                                    str_contains($type, 'on')      => 'bg-success',
                                                    str_contains($type, 'off')     => 'bg-danger',
                                                    str_contains($type, 'gesture') => 'bg-primary',
                                                    default                        => 'bg-secondary'
                                                };
                                                ?>
                                                <span class="badge <?= $badgeClass ?> rounded-pill">
                                                    <?= ucfirst(str_replace('_', ' ', $type)) ?>
                                                </span>
                                            </td>

                                            <!-- Room -->
                                            <td><?= htmlspecialchars($log['room_name'] ?? '-') ?></td>

                                            <!-- Row affected -->
                                            <td>
                                                <?php $rowAffected = $log['row_affected'] ?? null; ?>
                                                <?php if ($rowAffected): ?>
                                                    <span class="badge bg-info text-dark rounded-pill">Row
                                                        <?= htmlspecialchars($rowAffected) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">All rows</span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Triggered by -->
                                            <td>
                                                <?php
                                                $by = strtolower(trim($log['triggered_by'] ?? 'manual'));
                                                $byBadge = match ($by) {
                                                    'gesture', 'pir' => ['bg-primary', 'bi-hand-index-thumb', 'Gesture'],
                                                    'manual'         => ['bg-secondary', 'bi-toggle-on',      'Manual'],
                                                    default          => ['bg-secondary', 'bi-toggle-on',      ucfirst($by)],
                                                };
                                                ?>
                                                <span class="badge <?= $byBadge[0] ?> rounded-pill">
                                                    <i class="bi <?= $byBadge[1] ?> me-1"></i>
                                                    <?= $byBadge[2] ?>
                                                </span>
                                            </td>

                                            <!-- Time -->
                                            <td class="pe-3 text-muted" style="white-space:nowrap;">
                                                <?= date('g:i A', strtotime($log['event_time'])) ?>
                                                <div style="font-size:0.72rem;">
                                                    <?= date('M j, Y', strtotime($log['event_time'])) ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!--  
         EXTEND SCHEDULE MODAL
      -->
    <?php if ($active_schedule): ?>
        <div class="profile-details-modal modal fade" id="extendModal" tabindex="-1" aria-labelledby="extendModalLabel" aria-hidden="true">
            <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="extendModalLabel">
                            <i class="bi bi-clock-history me-2"></i>Request Time Extension
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="extend-description">
                            <span class="emphasis">
                                Requesting extension for
                                <span id="extend-room"><?= htmlspecialchars($active_schedule['room_name']) ?></span>
                                from <span id="extend-start-time"><?= date('g:i A', strtotime($active_schedule['start_time'])) ?></span>
                                to <span id="extend-end-time"><?= date('g:i A', strtotime($active_schedule['end_time'])) ?></span>
                            </span>
                            <br>How many extra minutes do you need?
                        </p>
                        <div class="extend-modal-content d-flex gap-4">
                            <div class="extend-left-div">
                                <h2 class="time-elapsed-title">Time Elapsed</h2>
                                <h1 class="timer-display">
                                    <input type="text" class="timer-input" id="timer-hours" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-minutes" value="00" maxlength="2" />:
                                    <input type="text" class="timer-input" id="timer-seconds" value="00" maxlength="2" />
                                </h1>
                                <div class="timer-labels d-flex gap-3 justify-content-center">
                                    <h6 class="timer-label">HOURS</h6>
                                    <h6 class="timer-label">MINUTES</h6>
                                    <h6 class="timer-label">SECONDS</h6>
                                </div>
                                <p class="extend-description mt-3" id="extend-description">
                                    Extending current class at <?= htmlspecialchars($active_schedule['room_name']) ?> for <span id="extend-time-range"></span>
                                </p>
                            </div>
                            <div class="extend-right-div d-flex flex-column align-items-center gap-3">
                                <h2 class="time-elapsed-title">Extend Time</h2>
                                <p class="extend-description mb-0">Add desired time:</p>
                                <div class="d-flex flex-column gap-2" id="extendPills">
                                    <?php foreach ([15, 30, 45, 60] as $mins): ?>
                                        <button class="btn btn-outline-primary extend-pill" data-mins="<?= $mins ?>">
                                            +<?= $mins ?> min
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                        <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="medium w-100" id="submitExtendBtn" disabled>
                            Send Request
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirm Extend Modal -->
        <div class="profile-details-modal modal fade" id="confirmExtendModal" tabindex="-1" aria-labelledby="confirmExtendLabel" aria-hidden="true">
            <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title bold" id="confirmExtendLabel">
                            <i class="bi bi-check-circle me-2"></i>Confirm Extension Request
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Please review the details below before submitting your extension request.</p>
                        <div class="dept-info-card p-3">
                            <div class="mb-2"><strong>Room:</strong> <span id="confirmExtendRoom"><?= htmlspecialchars($active_schedule['room_name']) ?></span></div>
                            <div class="mb-2"><strong>Time:</strong> <span id="confirmExtendTime"><?= date('g:i A', strtotime($active_schedule['start_time'])) ?> - <?= date('g:i A', strtotime($active_schedule['end_time'])) ?></span></div>
                            <div class="mb-2"><strong>Extension:</strong> <span id="confirmExtendMins"></span></div>
                            <div><strong>Action:</strong> <span id="confirmExtendAction">submit</span></div>
                        </div>
                    </div>
                    <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                        <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="medium w-100" id="confirmExtendBtn">Confirm</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
                const SCHEDULE_EXTEND_ID = <?= (int) $active_schedule['id'] ?>;
                const CLASS_START_EXTEND = '<?= date('g:i A', strtotime($active_schedule['start_time'])) ?>';
                const CLASS_END_EXTEND = '<?= date('g:i A', strtotime($active_schedule['end_time'])) ?>';
                const ROOM_NAME_EXTEND = '<?= htmlspecialchars($active_schedule['room_name'], ENT_QUOTES) ?>';
        </script>
    <?php endif; ?>

    <div class="toast-container" id="toastContainer"></div>

    <!--  
         VIEW SCHEDULE MODAL
      -->
    <div class="modal fade" id="viewScheduleModal" tabindex="-1" aria-labelledby="viewScheduleLabel" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title bold" id="viewScheduleLabel">
                        <i class="bi bi-calendar-week me-2"></i>Class Schedule
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-column gap-3">
                        <?php if (!empty($schedules)): ?>
                            <?php
                            $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            usort($schedules, function ($a, $b) use ($dayOrder) {
                                $da = array_search($a['day_of_week'], $dayOrder);
                                $db = array_search($b['day_of_week'], $dayOrder);
                                return $da !== $db ? $da - $db : strcmp($a['start_time'], $b['start_time']);
                            });
                            $dayIcons = [
                                'Monday'    => 'bi-1-square-fill',
                                'Tuesday'   => 'bi-2-square-fill',
                                'Wednesday' => 'bi-3-square-fill',
                                'Thursday'  => 'bi-4-square-fill',
                                'Friday'    => 'bi-5-square-fill',
                                'Saturday'  => 'bi-6-square-fill',
                                'Sunday'    => 'bi-7-square-fill',
                            ];
                            $today = date('l');
                            foreach ($schedules as $sched):
                                $isToday  = ($sched['day_of_week'] === $today);
                                $icon     = $dayIcons[$sched['day_of_week']] ?? 'bi-calendar';
                                $start    = date('g:i A', strtotime($sched['start_time']));
                                $end      = date('g:i A', strtotime($sched['end_time']));
                            ?>
                                <div class="d-flex align-items-center gap-3 p-2 rounded-3
                                <?= $isToday ? 'bg-primary bg-opacity-10 border border-primary border-opacity-25' : 'bg-light' ?>">
                                    <i class="bi <?= $icon ?> <?= $isToday ? 'text-primary' : 'text-secondary' ?>"
                                        style="font-size:1.6rem; flex-shrink:0;"></i>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <strong><?= htmlspecialchars($sched['day_of_week']) ?></strong>
                                            <?php if ($isToday): ?>
                                                <span class="badge bg-primary rounded-pill" style="font-size:0.7rem;">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="bi bi-clock me-1"></i><?= $start ?> - <?= $end ?>
                                        </small>
                                        <?php if (!empty($sched['subject_name'])): ?>
                                            <div style="font-size:0.8rem;" class="text-secondary">
                                                <i class="bi bi-book me-1"></i><?= htmlspecialchars($sched['subject_name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-3 p-2 bg-light rounded-3 text-muted">
                                <i class="bi bi-calendar-x" style="font-size:1.6rem;"></i>
                                <div>No schedules found for this classroom.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- End Early Confirm Modal -->
    <div class="profile-details-modal modal fade" id="endEarlyModal" tabindex="-1" aria-hidden="true">
        <div class="d-flex justify-content-center modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background:#dc3545;">
                    <h5 class="modal-title bold"><i class="bi bi-stop-circle me-2"></i>End Class Early</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <i class="bi bi-exclamation-triangle" style="font-size:2.5rem;color:#dc3545;"></i>
                    <p class="mt-3 mb-1">End your current class in <strong id="endEarlyRoom"></strong> early?</p>
                    <p class="text-muted small">Lights in this room will be turned off and the schedule will be marked as finished.</p>
                </div>
                <div class="modal-footer d-flex flex-row flex-nowrap justify-content-between gap-2">
                    <button type="button" class="light bold w-100" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" style="display:contents;">
                        <input type="hidden" name="end_early" id="endEarlySchedId" value="">
                        <button type="submit" class="medium w-100" style="background:#dc3545;border-color:#dc3545;">Confirm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="../../js/lib/tooltip.js"></script>
    <script src="../../js/faculty/faculty-tutorial.js"></script>
</body>

</html>