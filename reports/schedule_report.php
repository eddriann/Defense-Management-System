<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/bind_params.php');
require_once('../includes/refresh_defense_status.php');
auth_check(['Admin']);
refresh_defense_day_status($conn);
$search = request_filter_string($_GET, 'search');
$type_filter = $_GET['type'] ?? '';
$status_filter = $_GET['status'] ?? '';
$from_date = request_filter_string($_GET, 'from');
$to_date = request_filter_string($_GET, 'to');
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
$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date, ts.start_time, ts.end_time,
            r.room_name, r.location, ds.status,
            GROUP_CONCAT(DISTINCT CONCAT(u.full_name, ' (', pa.panel_role, ')') ORDER BY pa.panel_role, u.full_name SEPARATOR ', ') AS panel
     FROM defense_schedules ds
     INNER JOIN projects p ON p.project_id = ds.project_id
     INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
     INNER JOIN rooms r ON r.room_id = ds.room_id
     LEFT JOIN panel_assignments pa ON pa.schedule_id = ds.schedule_id
     LEFT JOIN users u ON u.user_id = pa.user_id
     WHERE (? = '' OR p.title LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR ds.defense_type = ?)
       AND (? = '' OR ds.status = ?)
       AND (? = '' OR ds.scheduled_date >= ?)
       AND (? = '' OR ds.scheduled_date <= ?)
     GROUP BY ds.schedule_id ORDER BY ds.scheduled_date DESC, ts.start_time"
);
bind_stmt_params(
    $stmt,
    'ssssssssss',
    [$search, $search, $type_filter, $type_filter, $status_filter, $status_filter, $from_date, $from_date, $to_date, $to_date]
);
mysqli_stmt_execute($stmt);
$rows = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Report</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body><?php require __DIR__ . '/../includes/module_page_start.php'; ?><h1>Schedule Report</h1>
    <form method="get" class="filter-form">
        <label for="search">Project</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Status</label><select name="status" id="status"><option value="">All statuses</option><?php foreach (['Scheduled', 'Ongoing', 'Completed', 'Cancelled'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
        <label for="from">From</label><input type="date" name="from" id="from" value="<?= htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8') ?>">
        <label for="to">To</label><input type="date" name="to" id="to" value="<?= htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filter</button><a class="button-secondary" href="schedule_report.php">Clear</a>
    </form>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Project</th>
                <th>Defense</th>
                <th>Date</th>
                <th>Time</th>
                <th>Room</th>
                <th>Location</th>
                <th>Panel</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $any = false;
            while ($row = mysqli_fetch_assoc($rows)): $any = true; ?><tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= date('g:i A', strtotime($row['start_time'])) ?> - <?= date('g:i A', strtotime($row['end_time'])) ?></td>
                    <td><?= htmlspecialchars($row['room_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['panel'] ?? 'Not assigned', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr><?php endwhile;
                    if (!$any): ?><tr>
                    <td colspan="8">No schedules found.</td>
                </tr><?php endif; ?>
        </tbody>
    </table><?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>