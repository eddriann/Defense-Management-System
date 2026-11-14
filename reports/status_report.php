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
if (!in_array($type_filter, ['', 'Thesis', 'Capstone'], true)) {
    $type_filter = '';
}
if (!in_array($status_filter, ['', 'Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped'], true)) {
    $status_filter = '';
}
$stmt = mysqli_prepare(
    $conn,
    "SELECT p.project_id, p.title, p.project_type, p.status, p.created_at, p.updated_at,
            u.full_name AS adviser,
            GROUP_CONCAT(DISTINCT student_user.full_name ORDER BY student_user.full_name SEPARATOR ', ') AS members
     FROM projects p
     INNER JOIN users u ON u.user_id = p.adviser_id
     LEFT JOIN project_members pm ON pm.project_id = p.project_id
     LEFT JOIN students s ON s.student_id = pm.student_id
     LEFT JOIN users student_user ON student_user.user_id = s.user_id
     WHERE (? = '' OR p.title LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR p.project_type = ?)
       AND (? = '' OR p.status = ?)
     GROUP BY p.project_id ORDER BY FIELD(p.status, 'Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped'), p.title"
);
bind_stmt_params($stmt, 'ssssss', [$search, $search, $type_filter, $type_filter, $status_filter, $status_filter]);
mysqli_stmt_execute($stmt);
$rows = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Status Report</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body><?php require __DIR__ . '/../includes/module_page_start.php'; ?><h1>Project Status Report</h1>
    <form method="get" class="filter-form">
        <label for="search">Project title</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Project type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Thesis', 'Capstone'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Status</label><select name="status" id="status"><option value="">All statuses</option><?php foreach (['Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped'] as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
        <button type="submit">Filter</button><a class="button-secondary" href="status_report.php">Clear</a>
    </form>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Project</th>
                <th>Type</th>
                <th>Status</th>
                <th>Adviser</th>
                <th>Members</th>
                <th>Created</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php $any = false;
            while ($row = mysqli_fetch_assoc($rows)): $any = true; ?><tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['project_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['adviser'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['members'] ?? 'No members', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr><?php endwhile;
                    if (!$any): ?><tr>
                    <td colspan="7">No projects found.</td>
                </tr><?php endif; ?>
        </tbody>
    </table><?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>