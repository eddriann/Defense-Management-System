<?php
include('../config/db.php');
require_once('../includes/auth_check.php');
require_once('../includes/refresh_defense_status.php');
require_once('../includes/bind_params.php');
auth_check(['Admin', 'Adviser', 'Panel Member', 'Student']);
refresh_defense_day_status($conn);

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
$search = request_filter_string($_GET, 'search');
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$from_date = request_filter_string($_GET, 'from');
$to_date = request_filter_string($_GET, 'to');
$is_student = $role === 'Student';
if ($is_student) {
    $search = '';
    $type_filter = '';
    $status_filter = '';
    $from_date = '';
    $to_date = '';
}
if (!in_array($type_filter, ['', 'Proposal', 'Final'], true)) {
    $type_filter = '';
}
if (!in_array($status_filter, ['', 'Scheduled', 'Ongoing', 'Completed', 'Cancelled'], true)) {
    $status_filter = '';
}
foreach (['from_date', 'to_date'] as $date_filter) {
    if ($$date_filter !== '' && !is_valid_filter_date($$date_filter)) {
        $$date_filter = '';
    }
}
$sql = "SELECT ds.schedule_id, ds.project_id, ds.defense_type, ds.scheduled_date, ds.status,
               p.title, ts.start_time, ts.end_time, r.room_name,
               GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS panel
        FROM defense_schedules ds
        INNER JOIN projects p ON p.project_id = ds.project_id
        INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
        INNER JOIN rooms r ON r.room_id = ds.room_id
        LEFT JOIN panel_assignments pa ON pa.schedule_id = ds.schedule_id
        LEFT JOIN users u ON u.user_id = pa.user_id";
$where = [];
$params = [];
$types = '';
if ($role === 'Admin') {
} elseif ($role === 'Adviser') {
    $where[] = 'p.adviser_id = ?';
    $params[] = $user_id;
    $types .= 'i';
} elseif ($role === 'Panel Member') {
    $sql .= " INNER JOIN panel_assignments mine ON mine.schedule_id = ds.schedule_id AND mine.user_id = ?";
    $params[] = $user_id;
    $types .= 'i';
} else {
    $student_stmt = mysqli_prepare($conn, "SELECT student_id FROM students WHERE user_id = ?");
    mysqli_stmt_bind_param($student_stmt, 'i', $user_id);
    mysqli_stmt_execute($student_stmt);
    $student_id = (int)(mysqli_fetch_assoc(mysqli_stmt_get_result($student_stmt))['student_id'] ?? 0);
    mysqli_stmt_close($student_stmt);
    $sql .= " INNER JOIN project_members mine ON mine.project_id = ds.project_id AND mine.student_id = ?";
    $params[] = $student_id;
    $types .= 'i';
}
$where[] = "(? = '' OR p.title LIKE CONCAT('%', ?, '%'))";
$where[] = "(? = '' OR ds.defense_type = ?)";
$where[] = "(? = '' OR ds.status = ?)";
$where[] = "(? = '' OR ds.scheduled_date >= ?)";
$where[] = "(? = '' OR ds.scheduled_date <= ?)";
$params = array_merge($params, [
    $search, $search, $type_filter, $type_filter, $status_filter, $status_filter,
    $from_date, $from_date, $to_date, $to_date,
]);
$types .= 'ssssssssss';
$stmt = mysqli_prepare($conn, $sql . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY ds.schedule_id ORDER BY ds.scheduled_date DESC, ds.schedule_id DESC');
bind_stmt_params($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$schedules = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Defense Schedules</title><link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2"><link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2"><script src="../assets/js/dashboard.js" defer></script><link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115"></head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Defense Schedules</h1>
    <?php if ($role === 'Admin'): ?><p><a class="button-secondary" href="../defense_request/review.php">Review Defense Requests</a> <a class="button-primary" href="add.php">Add Schedule</a></p><?php endif; ?>
    <?php if (!$is_student): ?><form method="get" class="filter-form compact-filter">
        <label for="search">Project</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Schedule status</label><select name="status" id="status"><option value="">All statuses</option><?php foreach (['Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
        <label for="from">From</label><input type="date" name="from" id="from" value="<?= htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8') ?>">
        <label for="to">To</label><input type="date" name="to" id="to" value="<?= htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filter</button><a class="button-secondary" href="list.php">Clear</a>
    </form><?php endif; ?>
    <table border="1" cellpadding="6">
        <thead><tr><th>Project</th><th>Defense</th><th>Date</th><th>Time</th><th>Room</th><th>Panel</th><th>Status</th><th class="screen-only">Results</th><?php if ($role === 'Admin'): ?><th class="screen-only">Actions</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if (!$schedules): ?><tr><td colspan="<?= $role === 'Admin' ? 9 : 8 ?>">No schedules found.</td></tr>
        <?php else: foreach ($schedules as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars(date('M j, Y', strtotime($row['scheduled_date'])), ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars(format_defense_countdown($row['scheduled_date']), ENT_QUOTES, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars(date('g:i A', strtotime($row['start_time'])) . ' - ' . date('g:i A', strtotime($row['end_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['panel'] ?? 'Not assigned', ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="table-actions-cell screen-only"><div class="table-actions"><a class="row-action" href="../evaluations/results.php?schedule_id=<?= (int)$row['schedule_id'] ?>">View</a></div></td>
                <?php if ($role === 'Admin'): ?><td class="table-actions-cell screen-only"><div class="table-actions"><a class="row-action" href="edit.php?id=<?= (int)$row['schedule_id'] ?>" aria-label="Edit schedule">Edit</a><a class="row-action" href="../panels/assign.php?schedule_id=<?= (int)$row['schedule_id'] ?>" aria-label="Assign panel">Panel</a></div></td><?php endif; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
