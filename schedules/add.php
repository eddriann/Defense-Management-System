<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/availability.php';
require_once __DIR__ . '/../includes/panel_assignment.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';
auth_check(['Admin']);
refresh_defense_day_status($conn);

$user_id = (int)$_SESSION['user_id'];
$projects = mysqli_query($conn, 'SELECT project_id, title FROM projects ORDER BY title');
$slots = mysqli_query($conn, 'SELECT slot_id, start_time, end_time FROM time_slots ORDER BY start_time');
$rooms = mysqli_query($conn, "SELECT room_id, room_name FROM rooms WHERE status = 'Active' ORDER BY room_name");
$valid_dates = getValidDefenseDates($conn, date('Y-m-d'), 84);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $project_id = (int)($_POST['project_id'] ?? 0);
    $defense_type = $_POST['defense_type'] ?? '';
    $date = $_POST['scheduled_date'] ?? '';
    $slot_id = (int)($_POST['slot_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);
    if ($project_id < 1 || $slot_id < 1 || $room_id < 1 ||
        !in_array($date, $valid_dates, true) ||
        !in_array($defense_type, ['Proposal', 'Final'], true)) {
        $error = 'Choose an available date, time slot, room, project, and defense type.';
    } else {
        try {
            mysqli_begin_transaction($conn);

            $project_stmt = mysqli_prepare($conn, 'SELECT adviser_id FROM projects WHERE project_id = ? FOR UPDATE');
            mysqli_stmt_bind_param($project_stmt, 'i', $project_id);
            mysqli_stmt_execute($project_stmt);
            $project = mysqli_fetch_assoc(mysqli_stmt_get_result($project_stmt));
            mysqli_stmt_close($project_stmt);
            if (!$project) {
                throw new RuntimeException('The selected project no longer exists.');
            }

            $conflict = mysqli_prepare(
                $conn,
                "SELECT 1
                 FROM defense_schedules ds
                 JOIN projects old_project ON old_project.project_id = ds.project_id
                 WHERE ds.scheduled_date = ? AND ds.slot_id = ? AND ds.status <> 'Cancelled'
                   AND (
                       ds.project_id = ?
                       OR old_project.adviser_id = ?
                       OR EXISTS (
                           SELECT 1
                           FROM project_members current_pm
                           JOIN project_members old_pm ON old_pm.student_id = current_pm.student_id
                           WHERE current_pm.project_id = ?
                             AND old_pm.project_id = old_project.project_id
                       )
                   )
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($conflict, 'siiii', $date, $slot_id, $project_id, $project['adviser_id'], $project_id);
            mysqli_stmt_execute($conflict);
            $has_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict));
            mysqli_stmt_close($conflict);
            if ($has_conflict) {
                throw new RuntimeException('The students or adviser already have a defense in that time slot.');
            }

            $room_lock = mysqli_prepare($conn, "SELECT room_id FROM rooms WHERE room_id = ? AND status = 'Active' FOR UPDATE");
            mysqli_stmt_bind_param($room_lock, 'i', $room_id);
            mysqli_stmt_execute($room_lock);
            $room_locked = mysqli_fetch_assoc(mysqli_stmt_get_result($room_lock));
            mysqli_stmt_close($room_lock);
            if (!$room_locked) {
                throw new RuntimeException('Choose an active room.');
            }

            $insert = mysqli_prepare(
                $conn,
                "INSERT INTO defense_schedules
                 (project_id, defense_type, scheduled_date, slot_id, room_id, status, created_by)
                 SELECT ?, ?, ?, ?, ?, ?, ?
                 FROM rooms r JOIN time_slots ts ON ts.slot_id = ?
                 WHERE r.room_id = ? AND r.status = 'Active'
                   AND NOT EXISTS (
                       SELECT 1 FROM defense_schedules booked
                       WHERE booked.room_id = r.room_id
                         AND booked.scheduled_date = ? AND booked.slot_id = ?
                         AND booked.status <> 'Cancelled'
                   )
                   AND NOT EXISTS (
                       SELECT 1 FROM room_unavailability unavailable
                       WHERE unavailable.room_id = r.room_id
                         AND unavailable.unavailable_date = ?
                         AND (unavailable.slot_id IS NULL OR unavailable.slot_id = ?)
                   )"
            );
            $schedule_status = 'Scheduled';
            mysqli_stmt_bind_param(
                $insert,
                'issiisiiisisi',
                $project_id,
                $defense_type,
                $date,
                $slot_id,
                $room_id,
                $schedule_status,
                $user_id,
                $slot_id,
                $room_id,
                $date,
                $slot_id,
                $date,
                $slot_id
            );
            mysqli_stmt_execute($insert);
            if (mysqli_stmt_affected_rows($insert) !== 1) {
                mysqli_stmt_close($insert);
                throw new RuntimeException('The selected room and time slot are no longer available.');
            }
            $schedule_id = mysqli_insert_id($conn);
            mysqli_stmt_close($insert);

            automatically_assign_panel($conn, $schedule_id);
            $project_update = mysqli_prepare(
                $conn,
                "UPDATE projects SET status = ?
                 WHERE project_id = ? AND status <> 'Dropped'"
            );
            $project_status = 'For Defense';
            mysqli_stmt_bind_param($project_update, 'si', $project_status, $project_id);
            mysqli_stmt_execute($project_update);
            mysqli_stmt_close($project_update);
            refresh_defense_day_status($conn);

            mysqli_commit($conn);
            header('Location: list.php');
            exit();
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : ($exception instanceof mysqli_sql_exception && $exception->getCode() === 1062
                    ? 'That room is already booked for the selected date and time.'
                    : 'Could not create the schedule. Confirm a complete, conflict-free panel is available.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Defense Schedule</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>
<body>
<?php require __DIR__ . '/../includes/module_page_start.php'; ?>
<h1>Create Defense Schedule</h1>
<?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post">
    <?= csrf_field() ?>
    <label for="project_id">Project
        <select name="project_id" id="project_id" required><option value="">Choose a project</option>
            <?php while ($row = mysqli_fetch_assoc($projects)): ?><option value="<?= (int)$row['project_id'] ?>"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></option><?php endwhile; ?>
        </select>
    </label>
    <label for="defense_type">Defense type
        <select name="defense_type" id="defense_type" required><option value="Proposal">Proposal</option><option value="Final">Final</option></select>
    </label>
    <label for="scheduled_date">Available defense date
        <select name="scheduled_date" id="scheduled_date" required>
            <option value="">Choose an available date</option>
            <?php foreach ($valid_dates as $valid_date): ?><option value="<?= htmlspecialchars($valid_date, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('l, F j, Y', strtotime($valid_date)), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
        </select>
    </label>
    <label for="slot_id">Time slot
        <select name="slot_id" id="slot_id" required><option value="">Choose a time slot</option>
            <?php while ($row = mysqli_fetch_assoc($slots)): ?><option value="<?= (int)$row['slot_id'] ?>"><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></option><?php endwhile; ?>
        </select>
    </label>
    <label for="room_id">Room
        <select name="room_id" id="room_id" required><option value="">Choose an active room</option>
            <?php while ($row = mysqli_fetch_assoc($rooms)): ?><option value="<?= (int)$row['room_id'] ?>"><?= htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endwhile; ?>
        </select>
    </label>
    <button type="submit" <?= !$valid_dates ? 'disabled' : '' ?>>Create schedule and assign panel</button>
</form>
<?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
