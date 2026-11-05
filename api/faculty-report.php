<?php
require_once __DIR__ . '/../src/Session/session_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function facultyReportError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if (empty($_SESSION['admin_logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    facultyReportError(403, 'Administrator access is required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    facultyReportError(405, 'GET only.');
}
require_once __DIR__ . '/../src/Config/db_connect.php';
session_write_close();

function facultyReportDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function facultyReportRows(mysqli $conn, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $conn->prepare($sql);
    if (!$stmt || ($types !== '' && !$stmt->bind_param($types, ...$params))
        || !$stmt->execute() || !($result = $stmt->get_result())) {
        throw new RuntimeException('Faculty report query failed.');
    }
    $stmt->close();
    return $result;
}

function facultyReportRate(int $count, int $total): ?float
{
    return $total > 0 ? round(100 * $count / $total, 1) : null;
}

$timezone = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $timezone);
$now = new DateTimeImmutable('now', $timezone);
$start = facultyReportDate($_GET['start'] ?? $today->modify('-29 days')->format('Y-m-d'), $timezone);
$end = facultyReportDate($_GET['end'] ?? $today->format('Y-m-d'), $timezone);
$roomId = filter_var($_GET['room_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$detailId = filter_var($_GET['faculty_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if (!$start || !$end || $start > $end || $roomId === false || $detailId === false) {
    facultyReportError(400, 'Choose a valid date range, room, and faculty member.');
}
$from = $start->format('Y-m-d');
$until = $end->modify('+1 day')->format('Y-m-d');
$window = [$from, $until, $roomId, $roomId];

try {
    $rooms = [];
    foreach (facultyReportRows($conn, 'SELECT id, room_name FROM classrooms ORDER BY room_name, id') as $room) {
        $rooms[(int)$room['id']] = $room['room_name'];
    }
    if ($roomId && !isset($rooms[$roomId])) {
        facultyReportError(404, 'Room not found.');
    }

    $faculty = [];
    foreach (facultyReportRows($conn, "
        SELECT id, CONCAT_WS(' ', first_name, last_name) AS name
        FROM faculty WHERE COALESCE(is_archived, 0) = 0
        ORDER BY last_name, first_name, id
    ") as $person) {
        $id = (int)$person['id'];
        $faculty[$id] = [
            'id' => $id, 'name' => $person['name'],
            'active_days' => $roomId ? null : 0, 'sign_ins' => $roomId ? null : 0, 'sign_outs' => $roomId ? null : 0,
            'gesture_count' => 0, 'top_gesture' => null, 'ui_toggles' => 0, 'ui_share' => null,
            'early_endings' => 0, 'matched_early_endings' => 0, 'completed_classes' => 0,
            'early_rate' => null, 'median_minutes_early' => null,
            'extension_requests' => 0, 'classes_with_extensions' => 0, 'extension_rate' => null,
        ];
    }
    if ($detailId && !isset($faculty[$detailId])) {
        facultyReportError(404, 'Current faculty member not found.');
    }

    $events = [];
    $addEvent = static function (int $id, string $time, ?int $room, string $type, string $description, ?string $source = null) use (&$events, $detailId, $rooms): void {
        if ($id === $detailId) {
            $events[] = ['time' => $time, 'room' => $rooms[$room] ?? null, 'type' => $type, 'description' => $description, 'source' => $source];
        }
    };

    if (!$roomId) {
        foreach (facultyReportRows($conn, "
            SELECT a.faculty_id, a.event_type, a.event_time
            FROM faculty_account_events a JOIN faculty f ON f.id = a.faculty_id
            WHERE COALESCE(f.is_archived, 0) = 0 AND a.event_time >= ? AND a.event_time < ?
        ", 'ss', [$from, $until]) as $event) {
            $id = (int)$event['faculty_id'];
            $signIn = $event['event_type'] === 'sign_in';
            $faculty[$id][$signIn ? 'sign_ins' : 'sign_outs']++;
            $activeDates[$id][substr($event['event_time'], 0, 10)] = true;
            $label = $signIn ? 'Sign-in' : 'Sign-out';
            $addEvent($id, $event['event_time'], null, 'Account', $label);
        }
        foreach ($activeDates ?? [] as $id => $dates) {
            $faculty[$id]['active_days'] = count($dates);
        }
    }

    $gestureCounts = [];
    $manualGestureRows = [];
    foreach (facultyReportRows($conn, "
        SELECT l.faculty_id, l.classroom_id, l.event_type, l.triggered_by, l.event_time, l.gesture_name
        FROM lighting_logs l JOIN faculty f ON f.id = l.faculty_id
        WHERE COALESCE(f.is_archived, 0) = 0 AND l.event_time >= ? AND l.event_time < ?
          AND (? = 0 OR l.classroom_id = ?)
          AND (l.event_type = 'gesture' OR l.triggered_by IN ('gesture', 'manual', 'faculty_end_early'))
    ", 'ssii', $window) as $event) {
        $id = (int)$event['faculty_id'];
        $room = (int)$event['classroom_id'];
        $time = $event['event_time'];
        if ($event['event_type'] === 'gesture') {
            $faculty[$id]['gesture_count']++;
            $name = $event['gesture_name'];
            if ($name !== null) {
                $gestureCounts[$id][$name] = ($gestureCounts[$id][$name] ?? 0) + 1;
            }
            $addEvent($id, $time, $room, 'Gesture', $name ?? 'Confirmed gesture stack', 'Gesture');
        } elseif ($event['triggered_by'] === 'manual' && in_array($event['event_type'], ['on', 'off'], true)) {
            $faculty[$id]['ui_toggles']++;
            $addEvent($id, $time, $room, 'UI toggle', 'Lights ' . $event['event_type'], 'UI (manual)');
        } elseif ($event['triggered_by'] === 'gesture' && in_array($event['event_type'], ['on', 'off'], true)) {
            $manualGestureRows[$id] = true;
            $addEvent($id, $time, $room, 'Gesture toggle', 'Lights ' . $event['event_type'], 'Gesture');
        } elseif ($event['triggered_by'] === 'faculty_end_early') {
            $faculty[$id]['early_endings']++;
            $addEvent($id, $time, $room, 'Early ending', 'Class ended early');
            $earlyEvents[] = ['id' => $id, 'room' => $room, 'time' => $time];
        }
    }

    foreach (facultyReportRows($conn, "
        SELECT s.id, s.classroom_id, s.faculty_id, s.day_of_week, s.start_time, s.end_time,
               DATE(s.created_at) AS available_from, NULL AS available_until
        FROM schedules s WHERE (? = 0 OR s.classroom_id = ?)
        UNION ALL
        SELECT s.original_id, s.classroom_id, s.faculty_id, s.day_of_week, s.start_time, s.end_time,
               NULL, DATE(s.archived_at)
        FROM archived_schedules s WHERE (? = 0 OR s.classroom_id = ?)
    ", 'iiii', [$roomId, $roomId, $roomId, $roomId]) as $schedule) {
        $schedules[(int)$schedule['classroom_id']][$schedule['day_of_week']][] = $schedule;
    }

    $matchSchedules = static function (int $room, string $time) use (&$schedules, $timezone): array {
        $when = new DateTimeImmutable($time, $timezone);
        $date = $when->format('Y-m-d');
        $clock = $when->format('H:i:s');
        return array_filter($schedules[$room][$when->format('l')] ?? [], static function (array $schedule) use ($date, $clock): bool {
            return ($schedule['available_from'] === null || $date >= $schedule['available_from'])
                && ($schedule['available_until'] === null || $date <= $schedule['available_until'])
                && $clock >= $schedule['start_time'] && $clock <= $schedule['end_time'];
        });
    };

    $classKey = static fn(array $schedule, string $time): string => $schedule['id'] . ':' . substr($time, 0, 10);
    $recordedCompletions = [];
    $matchedEarly = [];
    foreach ($earlyEvents ?? [] as $event) {
        $matches = array_values(array_filter($matchSchedules($event['room'], $event['time']),
            static fn(array $schedule): bool => (int)$schedule['faculty_id'] === $event['id']));
        if (count($matches) === 1) {
            $key = $classKey($matches[0], $event['time']);
            $recordedCompletions[$event['id']][$key] = true;
            if (isset($matchedEarly[$event['id']][$key])) {
                continue;
            }
            $matchedEarly[$event['id']][$key] = true;
            $scheduledEnd = new DateTimeImmutable(substr($event['time'], 0, 10) . ' ' . $matches[0]['end_time'], $timezone);
            $endedAt = new DateTimeImmutable($event['time'], $timezone);
            $earlyMinutes[$event['id']][] = ($scheduledEnd->getTimestamp() - $endedAt->getTimestamp()) / 60;
        }
    }

    foreach (facultyReportRows($conn, "
        SELECT classroom_id, event_time FROM class_logs
        WHERE event_type = 'class_end' AND triggered_by = 'PIR' AND event_time >= ? AND event_time < ?
          AND (? = 0 OR classroom_id = ?)
    ", 'ssii', $window) as $event) {
        $matches = array_values($matchSchedules((int)$event['classroom_id'], $event['event_time']));
        if (count($matches) === 1) {
            $schedule = $matches[0];
            $id = (int)$schedule['faculty_id'];
            $recordedCompletions[$id][$classKey($schedule, $event['event_time'])] = true;
            $addEvent($id, $event['event_time'], (int)$event['classroom_id'], 'Class ending', 'PIR inactivity timeout');
        }
    }

    $scheduledClasses = [];
    $detailClasses = [];
    $lastClassDate = min($end, $today);
    foreach ($schedules ?? [] as $days) {
        foreach ($days as $day => $slots) {
            $weekday = array_search($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true);
            if ($weekday === false) {
                continue;
            }
            foreach ($slots as $schedule) {
                $id = (int)$schedule['faculty_id'];
                if (!isset($faculty[$id])) {
                    continue;
                }
                $first = max($start, new DateTimeImmutable($schedule['available_from'] ?? $from, $timezone));
                $last = min($lastClassDate, new DateTimeImmutable($schedule['available_until'] ?? $until, $timezone));
                $offset = ($weekday + 8 - (int)$first->format('N')) % 7;
                $first = $first->modify("+$offset days");
                if ($first <= $last) {
                    $scheduledClasses[$id] = ($scheduledClasses[$id] ?? 0) + 1 + intdiv((int)$first->diff($last)->days, 7);
                    $classWindows[$id][$schedule['id']][] = [$first->format('Y-m-d'), $last->format('Y-m-d'), $day];
                    if ($id === $detailId) {
                        for ($date = $first; $date <= $last; $date = $date->modify('+7 days')) {
                            $dayString = $date->format('Y-m-d');
                            $classStart = new DateTimeImmutable($dayString . ' ' . $schedule['start_time'], $timezone);
                            if ($classStart > $now) {
                                continue;
                            }
                            $classEnd = new DateTimeImmutable($dayString . ' ' . $schedule['end_time'], $timezone);
                            $detailClasses[$classKey($schedule, $dayString)] = [
                                'start' => $classStart->getTimestamp(), 'end' => $classEnd->getTimestamp(),
                                'room' => (int)$schedule['classroom_id'],
                            ];
                        }
                    }
                }
            }
        }
    }

    foreach (facultyReportRows($conn, "
        SELECT e.faculty_id, e.schedule_id, e.requested_at, e.extend_mins, e.status, s.classroom_id
        FROM extension_requests e JOIN schedules s ON s.id = e.schedule_id
        JOIN faculty f ON f.id = e.faculty_id
        WHERE COALESCE(f.is_archived, 0) = 0 AND e.requested_at >= ? AND e.requested_at < ?
          AND (? = 0 OR s.classroom_id = ?)
        UNION ALL
        SELECT e.faculty_id, e.schedule_id, e.requested_at, e.extend_mins, e.status,
               COALESCE(s.classroom_id, a.classroom_id)
        FROM archived_extension_requests e
        JOIN faculty f ON f.id = e.faculty_id
        LEFT JOIN schedules s ON s.id = e.schedule_id
        LEFT JOIN archived_schedules a ON a.id = (
            SELECT a2.id FROM archived_schedules a2
            WHERE a2.original_id = e.schedule_id AND a2.archived_at >= e.requested_at
            ORDER BY a2.archived_at, a2.id LIMIT 1
        )
        WHERE COALESCE(f.is_archived, 0) = 0 AND e.requested_at >= ? AND e.requested_at < ?
          AND (? = 0 OR COALESCE(s.classroom_id, a.classroom_id) = ?)
    ", 'ssiissii', [...$window, ...$window]) as $event) {
        $id = (int)$event['faculty_id'];
        $faculty[$id]['extension_requests']++;
        $date = substr($event['requested_at'], 0, 10);
        $day = (new DateTimeImmutable($date, $timezone))->format('l');
        $key = $event['schedule_id'] . ':' . $date;
        $extensionClasses[$id][$key] = true;
        foreach ($classWindows[$id][$event['schedule_id']] ?? [] as [$first, $last, $weekday]) {
            if ($date >= $first && $date <= $last && $day === $weekday) {
                $matchedExtensions[$id][$key] = true;
                break;
            }
        }
        $addEvent($id, $event['requested_at'], $event['classroom_id'] === null ? null : (int)$event['classroom_id'],
            'Extension request', $event['extend_mins'] . ' min, ' . ucfirst($event['status'] ?? 'unknown'));
    }

    foreach ($faculty as $id => &$person) {
        if (isset($gestureCounts[$id])) {
            ksort($gestureCounts[$id]);
            arsort($gestureCounts[$id]);
            $person['top_gesture'] = array_key_first($gestureCounts[$id]);
        }
        $person['gesture_breakdown'] = [];
        foreach ($gestureCounts[$id] ?? [] as $name => $count) {
            $person['gesture_breakdown'][] = ['name' => $name, 'count' => $count];
        }
        if ($person['gesture_count'] || empty($manualGestureRows[$id])) {
            $person['ui_share'] = facultyReportRate($person['ui_toggles'], $person['ui_toggles'] + $person['gesture_count']);
        }
        $person['classes_with_extensions'] = count($extensionClasses[$id] ?? []);
        $scheduledCount = $scheduledClasses[$id] ?? 0;
        if ($person['classes_with_extensions'] === count($matchedExtensions[$id] ?? [])) {
            $person['extension_rate'] = facultyReportRate($person['classes_with_extensions'], $scheduledCount);
        }
        $completed = count($recordedCompletions[$id] ?? []);
        $person['completed_classes'] = $completed;
        $person['matched_early_endings'] = count($matchedEarly[$id] ?? []);
        $person['early_rate'] = facultyReportRate($person['matched_early_endings'], $completed);
        $minutes = $earlyMinutes[$id] ?? [];
        sort($minutes);
        $count = count($minutes);
        if ($count > 0) {
            $person['median_minutes_early'] = round(($minutes[intdiv($count - 1, 2)] + $minutes[intdiv($count, 2)]) / 2, 1);
        }
    }
    unset($person);

    $pir = [];
    foreach (facultyReportRows($conn, "
        SELECT classroom_id, created_at FROM pir_logs
        WHERE state = 1 AND created_at >= ? AND created_at < ? AND (? = 0 OR classroom_id = ?)
    ", 'ssii', $window) as $event) {
        $room = (int)$event['classroom_id'];
        $hour = substr($event['created_at'], 11, 2) . ':00';
        $key = $room . ':' . $hour;
        $pir[$key] ??= ['room' => $rooms[$room], 'hour' => $hour, 'scheduled' => 0, 'unscheduled' => 0];
        $pir[$key][$matchSchedules($room, $event['created_at']) ? 'scheduled' : 'unscheduled']++;
    }
    usort($pir, static fn(array $a, array $b): int => ($b['scheduled'] + $b['unscheduled']) <=> ($a['scheduled'] + $a['unscheduled']));
    usort($events, static fn(array $a, array $b): int => strcmp($b['time'], $a['time']));
    $gestures = $faculty[$detailId]['gesture_breakdown'] ?? [];
    $unnamed = ($faculty[$detailId]['gesture_count'] ?? 0) - array_sum($gestureCounts[$detailId] ?? []);
    $activeTime = ['scheduled' => count($detailClasses), 'matched' => 0, 'unmatched' => 0, 'buckets' => []];
    if ($detailId) {
        $classDays = [];
        foreach ($detailClasses as $key => $class) {
            $classStart = (new DateTimeImmutable('@' . $class['start']))->setTimezone($timezone);
            $classDays[$class['room']][$classStart->format('Y-m-d')][$key] = $class;
            $classDays[$class['room']][$classStart->modify('-10 minutes')->format('Y-m-d')][$key] = $class;
        }
        $firstOns = [];
        foreach (facultyReportRows($conn, "
            SELECT classroom_id, event_time, triggered_by FROM lighting_logs
            WHERE faculty_id = ? AND event_type = 'on' AND triggered_by IN ('gesture', 'manual')
              AND event_time >= ? AND event_time < ? AND (? = 0 OR classroom_id = ?)
            ORDER BY event_time, id
        ", 'issii', [$detailId, ...$window]) as $action) {
            $time = (new DateTimeImmutable($action['event_time'], $timezone))->getTimestamp();
            $day = substr($action['event_time'], 0, 10);
            $current = [];
            $upcoming = [];
            foreach ($classDays[(int)$action['classroom_id']][$day] ?? [] as $key => $class) {
                if ($time >= $class['start'] && $time < $class['end']) {
                    $current[$key] = $class;
                } elseif ($time < $class['start'] && $time >= $class['start'] - 600) {
                    $upcoming[$key] = $class;
                }
            }
            $matches = $current ?: $upcoming;
            if (count($matches) !== 1) {
                $activeTime['unmatched']++;
                continue;
            }
            $key = array_key_first($matches);
            $firstOns[$key] ??= [$time - $matches[$key]['start'], $action['triggered_by']];
        }
        $activeTime['matched'] = count($firstOns);
        $buckets = [];
        foreach ($firstOns as [$elapsed, $source]) {
            $bucket = $elapsed < 0 ? -1 : intdiv($elapsed, 600) * 10;
            $buckets[$bucket] ??= ['minute' => $bucket, 'gesture' => 0, 'manual' => 0];
            $buckets[$bucket][$source]++;
        }
        if ($buckets) {
            for ($minute = 0; $minute <= max(array_keys($buckets)); $minute += 10) {
                $buckets[$minute] ??= ['minute' => $minute, 'gesture' => 0, 'manual' => 0];
            }
            ksort($buckets);
        }
        $activeTime['buckets'] = array_values($buckets);
    }

    echo json_encode([
        'success' => true, 'start' => $from, 'end' => $end->format('Y-m-d'),
        'rooms' => array_map(static fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($rooms), $rooms),
        'faculty' => array_values($faculty), 'pir' => $pir, 'events' => $events, 'gestures' => $gestures,
        'unknown_gestures' => $unnamed, 'active_time' => $activeTime,
        'notices' => [
            'Account activity and individual gesture names are recorded from the launch of Faculty Reports. Older confirmed gesture stacks count as one event each.',
            'The early-ending rate covers recorded class endings that match one scheduled class. The extension rate uses scheduled occurrences from the available timetable.',
            'PIR totals count motion detections. Scheduled periods are based on the available timetable records.',
        ],
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Faculty report failed: ' . get_class($error));
    facultyReportError(500, 'Faculty report data could not be loaded.');
}
