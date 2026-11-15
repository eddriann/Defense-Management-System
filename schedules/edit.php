<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/csrf.php');
require_once('../includes/availability.php');
require_once('../includes/panel_assignment.php');
require_once('../includes/refresh_defense_status.php');
auth_check(['Admin']);
refresh_defense_day_status($conn);
$id = (int)($_GET['id'] ?? $_POST['schedule_id'] ?? 0);
$stmt = mysqli_prepare($conn, "SELECT schedule_id, project_id, defense_type, scheduled_date, slot_id, room_id, status FROM defense_schedules WHERE schedule_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$schedule) {
    http_response_code(404);
    exit('Schedule not found.');
}
$projects = mysqli_query($conn, "SELECT project_id, title FROM projects ORDER BY title");
$slots = mysqli_query($conn, "SELECT slot_id, start_time, end_time FROM time_slots ORDER BY start_time");
$rooms = mysqli_query($conn, "SELECT room_id, room_name FROM rooms WHERE status = 'Active' ORDER BY room_name");
$valid_dates = getValidDefenseDates($conn, date('Y-m-d'), 84, $id);
if (!in_array($schedule['scheduled_date'], $valid_dates, true)) {
    $valid_dates[] = $schedule['scheduled_date'];
    sort($valid_dates);
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $project_id = (int)($_POST['project_id'] ?? 0);
    $slot_id = (int)($_POST['slot_id'] ?? 0);
    $room_id = (int)($_POST['room_id'] ?? 0);
    $type = $_POST['defense_type'] ?? '';
    $date = $_POST['scheduled_date'] ?? '';
    $status = $_POST['status'] ?? '';
    if ($project_id <= 0 || $slot_id <= 0 || $room_id <= 0 ||
        !in_array($type, ['Proposal', 'Final'], true) ||
        !in_array($status, ['Scheduled', 'Ongoing', 'Completed', 'Cancelled'], true) ||
        !in_array($date, $valid_dates, true)) {
        $error = 'Provide valid schedule details.';
    } else {
        try {
            mysqli_begin_transaction($conn);
            $conflict = mysqli_prepare(
                $conn,
                "SELECT 1
                 FROM defense_schedules ds
                 JOIN projects old_project ON old_project.project_id = ds.project_id
                 JOIN projects new_project ON new_project.project_id = ?
                 WHERE ds.schedule_id <> ? AND ds.scheduled_date = ? AND ds.slot_id = ?
                   AND ds.status <> 'Cancelled'
                   AND (
                       ds.project_id = new_project.project_id
                       OR old_project.adviser_id = new_project.adviser_id
                       OR EXISTS (
                           SELECT 1
                           FROM project_members current_pm
                           JOIN project_members old_pm ON old_pm.student_id = current_pm.student_id
                           WHERE current_pm.project_id = new_project.project_id
                             AND old_pm.project_id = old_project.project_id
                       )
                   )
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($conflict, 'iisi', $project_id, $id, $date, $slot_id);
            mysqli_stmt_execute($conflict);
            $has_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict));
            mysqli_stmt_close($conflict);
            if ($has_conflict) {
                throw new RuntimeException('The students or adviser already have a defense in that time slot.');
            }

            $panel_conflict = mysqli_prepare(
                $conn,
                "SELECT 1
                 FROM panel_assignments pa
                 JOIN defense_schedules other_ds ON other_ds.schedule_id = pa.schedule_id
                 WHERE pa.panel_role <> 'Adviser'
                   AND pa.schedule_id = ? AND other_ds.schedule_id <> ?
                   AND other_ds.status <> 'Cancelled'
                   AND other_ds.scheduled_date = ?
                   AND other_ds.slot_id = ?
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($panel_conflict, 'iisi', $id, $id, $date, $slot_id);
            mysqli_stmt_execute($panel_conflict);
            $has_panel_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($panel_conflict));
            mysqli_stmt_close($panel_conflict);
            if ($has_panel_conflict) {
                throw new RuntimeException('An assigned panelist has another defense at the same time.');
            }

            $room_lock = mysqli_prepare($conn, "SELECT room_id FROM rooms WHERE room_id = ? AND status = 'Active' FOR UPDATE");
            mysqli_stmt_bind_param($room_lock, 'i', $room_id);
            mysqli_stmt_execute($room_lock);
            $room_locked = mysqli_fetch_assoc(mysqli_stmt_get_result($room_lock));
            mysqli_stmt_close($room_lock);
            if (!$room_locked) {
                throw new RuntimeException('Choose an active room.');
            }
            $room_closure = mysqli_prepare(
                $conn,
                'SELECT 1 FROM room_unavailability
                 WHERE room_id = ? AND unavailable_date = ?
                   AND (slot_id IS NULL OR slot_id = ?)
                 LIMIT 1'
            );
            mysqli_stmt_bind_param($room_closure, 'isi', $room_id, $date, $slot_id);
            mysqli_stmt_execute($room_closure);
            $room_unavailable = mysqli_fetch_assoc(mysqli_stmt_get_result($room_closure));
            mysqli_stmt_close($room_closure);
            if ($room_unavailable) {
                throw new RuntimeException('The selected room is blocked for that date or time slot.');
            }

            $update = mysqli_prepare(
                $conn,
                "UPDATE defense_schedules
                 SET project_id = ?, defense_type = ?, scheduled_date = ?, slot_id = ?, room_id = ?, status = ?
                 WHERE schedule_id = ? AND EXISTS (SELECT 1 FROM rooms r WHERE r.room_id = ? AND r.status = 'Active')
                   AND EXISTS (SELECT 1 FROM time_slots ts WHERE ts.slot_id = ?)
                   AND NOT EXISTS (
                       SELECT 1 FROM room_unavailability unavailable
                       WHERE unavailable.room_id = ? AND unavailable.unavailable_date = ?
                         AND (unavailable.slot_id IS NULL OR unavailable.slot_id = ?)
                   )"
            );
            mysqli_stmt_bind_param($update, 'issiisiiiisi', $project_id, $type, $date, $slot_id, $room_id, $status, $id, $room_id, $slot_id, $room_id, $date, $slot_id);
            mysqli_stmt_execute($update);
            mysqli_stmt_close($update);
            if ($status !== 'Cancelled') {
                automatically_assign_panel($conn, $id);
                $project_status = 'For Defense';
                $project_update = mysqli_prepare(
                    $conn,
                    "UPDATE projects SET status = ?
                     WHERE project_id = ? AND status <> 'Dropped'"
                );
                mysqli_stmt_bind_param($project_update, 'si', $project_status, $project_id);
                mysqli_stmt_execute($project_update);
                mysqli_stmt_close($project_update);
            }
            refresh_defense_day_status($conn);
            mysqli_commit($conn);
            header('Location: list.php');
            exit();
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            $error = $e->getCode() === 1062
                ? 'That room is already booked for the selected date and time.'
                : 'Could not update the schedule.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Edit Schedule</title><link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3"><link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3"><script src="../assets/js/dashboard.js" defer></script><link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115"></head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Edit Defense Schedule</h1>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="schedule_id" value="<?= $id ?>">
        <label>Project <select name="project_id"><?php while ($row = mysqli_fetch_assoc($projects)): ?><option value="<?= (int)$row['project_id'] ?>" <?= (int)$row['project_id'] === (int)$schedule['project_id'] ? 'selected' : '' ?>><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></option><?php endwhile; ?></select></label><br>
        <label>Defense type <select name="defense_type"><?php foreach (['Proposal', 'Final'] as $value): ?><option <?= $schedule['defense_type'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label><br>
        <label>Date <select name="scheduled_date" required><?php foreach ($valid_dates as $valid_date): ?><option value="<?= htmlspecialchars($valid_date, ENT_QUOTES, 'UTF-8') ?>" <?= $valid_date === $schedule['scheduled_date'] ? 'selected' : '' ?>><?= htmlspecialchars(date('l, F j, Y', strtotime($valid_date)), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label><br>
        <label>Time slot <select name="slot_id"><?php while ($row = mysqli_fetch_assoc($slots)): ?><option value="<?= (int)$row['slot_id'] ?>" <?= (int)$row['slot_id'] === (int)$schedule['slot_id'] ? 'selected' : '' ?>><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></option><?php endwhile; ?></select></label><br>
        <label>Room <select name="room_id"><?php while ($row = mysqli_fetch_assoc($rooms)): ?><option value="<?= (int)$row['room_id'] ?>" <?= (int)$row['room_id'] === (int)$schedule['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8') ?></option><?php endwhile; ?></select></label><br>
        <label>Status <select name="status"><?php foreach (['Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $value): ?><option <?= $schedule['status'] === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label><br>
        <button type="submit">Save Schedule</button>
    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
