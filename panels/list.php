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
if (!in_array($type_filter, ['', 'Proposal', 'Final'], true)) {
    $type_filter = '';
}
if (!in_array($status_filter, ['', 'Scheduled', 'Ongoing', 'Completed'], true)) {
    $status_filter = '';
}
$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date,
            GROUP_CONCAT(CONCAT(u.full_name, ' (', pa.panel_role, ')') ORDER BY pa.panel_role, u.full_name SEPARATOR ', ') AS panel,
            GROUP_CONCAT(DISTINCT CASE
                             WHEN response.user_id IS NOT NULL
                             THEN CONCAT(declined_user.full_name,
                                         CASE WHEN response.reason IS NULL OR response.reason = ''
                                              THEN '' ELSE CONCAT(' — ', response.reason) END)
                         END
                         ORDER BY declined_user.full_name SEPARATOR ', ') AS declined_panelists
     FROM defense_schedules ds
     INNER JOIN projects p ON p.project_id = ds.project_id
     LEFT JOIN panel_assignments pa ON pa.schedule_id = ds.schedule_id
     LEFT JOIN users u ON u.user_id = pa.user_id
     LEFT JOIN panel_assignment_responses response ON response.schedule_id = pa.schedule_id AND response.user_id = pa.user_id
     LEFT JOIN users declined_user ON declined_user.user_id = response.user_id
     WHERE ds.status <> 'Cancelled'
       AND (? = '' OR p.title LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR ds.defense_type = ?)
       AND (? = '' OR ds.status = ?)
     GROUP BY ds.schedule_id ORDER BY ds.scheduled_date DESC, ds.schedule_id DESC"
);
bind_stmt_params($stmt, 'ssssss', [$search, $search, $type_filter, $type_filter, $status_filter, $status_filter]);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Assignments</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Panel Assignments</h1>
    <form method="get" class="filter-form">
        <label for="search">Project</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Schedule status</label><select name="status" id="status"><option value="">All statuses</option><?php foreach (['Scheduled', 'Ongoing', 'Completed'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
        <button type="submit">Filter</button><a class="button-secondary" href="list.php">Clear</a>
    </form>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Project</th>
                <th>Defense</th>
                <th>Date</th>
                <th>Panel Members</th>
                <th>Declines awaiting review</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php $has_rows = false;
            while ($row = mysqli_fetch_assoc($result)): $has_rows = true; ?>
                <tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['panel'] ?? 'Not assigned', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?php if ($row['declined_panelists']): ?><span class="account-status status-inactive"><?= htmlspecialchars($row['declined_panelists'], ENT_QUOTES, 'UTF-8') ?></span><?php else: ?>None<?php endif; ?></td>
                    <td><a class="button-secondary button-compact" href="assign.php?schedule_id=<?= (int)$row['schedule_id'] ?>">Manage</a></td>
                </tr>
            <?php endwhile;
            if (!$has_rows): ?><tr>
                    <td colspan="6">No scheduled defenses.</td>
                </tr><?php endif; ?>
        </tbody>
    </table>
    <p><a class="button-secondary" href="../schedules/list.php">Schedules</a></p>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>