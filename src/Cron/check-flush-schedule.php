<?php
/**
 * check-flush-schedule.php
 * Cron script - runs periodically (e.g. every minute via task scheduler).
 * Checks for confirmed flush schedules that are due and executes them.
 * Also sends confirmation reminder emails when entering the 7-day window.
 */

require_once __DIR__ . '/../Config/db_connect.php';
require_once __DIR__ . '/flush-executor.php';
require_once __DIR__ . '/../Config/config.php';

date_default_timezone_set('Asia/Manila');

$now = date('Y-m-d H:i:s');

// - Send confirmation reminders for flushes entering the 7-day window ----
$seven_days_from_now = date('Y-m-d H:i:s', strtotime('+7 days'));

$reminder_candidates = $conn->query("
    SELECT fs.id, fs.scheduled_datetime, fs.created_by,
           a.email, a.first_name, a.last_name
    FROM flush_schedules fs
    JOIN admins a ON a.id = fs.created_by
    WHERE fs.executed = 0
      AND fs.confirmed = 0
      AND fs.confirmation_sent = 0
      AND fs.scheduled_datetime <= '$seven_days_from_now'
      AND fs.scheduled_datetime > '$now'
");

while ($row = $reminder_candidates->fetch_assoc()) {
    $flush_id   = (int)$row['id'];
    $admin_email = $row['email'];
    $admin_name  = $row['first_name'] . ' ' . $row['last_name'];
    $scheduled   = $row['scheduled_datetime'];

    // Mark confirmation_sent = 1
    $conn->query("UPDATE flush_schedules SET confirmation_sent = 1 WHERE id = $flush_id");

    // Send email via PHPMailer
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($admin_email, $admin_name);
        $mail->isHTML(true);
        $mail->Subject = 'Action Required: System Flush Confirmation - LumineSense';
        $mail->Body    = "
            <p>Hi $admin_name,</p>
            <p>The system flush you scheduled is now within the <strong>7-day confirmation window</strong>.</p>
            <p><strong>Scheduled Date:</strong> $scheduled</p>
            <p>Please log in to the LumineSense admin panel to review and confirm the flush. If you do not confirm within 7 days, the flush will be auto-cancelled.</p>
            <p>Thank you,<br>LumineSense Team</p>
        ";

        $mail->send();
        echo "[" . date('Y-m-d H:i:s') . "] Reminder sent to $admin_email for flush #$flush_id\n";
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Email failed for flush #$flush_id: " . $mail->ErrorInfo . "\n";
    }
}

// Extension flush is now handled by MySQL EVENT (extension_flush_event).
// No PHP-based extension reset needed.

// - Execute confirmed flushes that are due -----------------
$due_flushes = $conn->query("
    SELECT * FROM flush_schedules
    WHERE executed = 0 AND confirmed = 1 AND scheduled_datetime <= '$now'
");

while ($flush = $due_flushes->fetch_assoc()) {
    $flush_id = (int)$flush['id'];
    $admin_id = (int)$flush['created_by'];

    echo "[" . date('Y-m-d H:i:s') . "] Executing flush #$flush_id\n";

    $executed_items = execute_flush(
        $conn, $admin_id, $flush_id,
        $flush['flush_departments'], $flush['flush_subject_areas'], $flush['flush_subjects']
    );

    echo "[" . date('Y-m-d H:i:s') . "] Flush #$flush_id completed: " . implode(', ', $executed_items) . "\n";
}

// - Auto-cancel unconfirmed flushes that have passed their scheduled date --
$overdue = $conn->query("
    SELECT id FROM flush_schedules
    WHERE executed = 0 AND confirmed = 0 AND scheduled_datetime <= '$now'
");
while ($row = $overdue->fetch_assoc()) {
    $fid = (int)$row['id'];
    $conn->query("DELETE FROM flush_schedules WHERE id = $fid");
    echo "[" . date('Y-m-d H:i:s') . "] Auto-cancelled unconfirmed flush #$fid (overdue)\n";
}

$conn->close();
