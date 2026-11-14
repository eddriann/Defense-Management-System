<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
auth_check(['Panel Member', 'Adviser']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
csrf_validate_request();

$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_POST['schedule_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
if ($schedule_id < 1 || strlen($reason) > 500) {
    $_SESSION['panel_flash_error'] = 'The response was invalid. Please try again.';
    header('Location: ../dashboard/panel_dashboard.php');
    exit();
}

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT pa.panel_role
         FROM panel_assignments pa
         JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
         JOIN time_slots ts ON ts.slot_id = ds.slot_id
         WHERE pa.schedule_id = ? AND pa.user_id = ?
           AND (
               ds.scheduled_date > CURDATE()
               OR (ds.scheduled_date = CURDATE() AND ts.start_time > CURRENT_TIME)
           )
           AND ds.status IN ('Scheduled', 'Ongoing')
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 'ii', $schedule_id, $user_id);
    mysqli_stmt_execute($stmt);
    $assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$assignment) {
        throw new RuntimeException('This defense is no longer assigned to you or is not upcoming.');
    }
    if ($assignment['panel_role'] === 'Adviser') {
        throw new RuntimeException('The project adviser is required to attend this defense. Contact the administrator if the schedule needs to change.');
    }

    $stmt = mysqli_prepare($conn, 'SELECT 1 FROM evaluations WHERE schedule_id = ? AND evaluator_id = ?');
    mysqli_stmt_bind_param($stmt, 'ii', $schedule_id, $user_id);
    mysqli_stmt_execute($stmt);
    $has_evaluation = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($has_evaluation) {
        throw new RuntimeException('You have already submitted an evaluation for this defense.');
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO panel_assignment_responses (schedule_id, user_id, response, reason)
         VALUES (?, ?, 'Declined', ?)
         ON DUPLICATE KEY UPDATE
             response = 'Declined',
             reason = VALUES(reason),
             responded_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, 'iis', $schedule_id, $user_id, $reason);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    mysqli_commit($conn);
    $_SESSION['panel_flash_message'] = 'Your decline was sent to the administrator. Your assignment will only change after an administrator reviews it.';
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    $_SESSION['panel_flash_error'] = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'Could not record your availability response.';
}

header('Location: ../dashboard/panel_dashboard.php');
exit();
