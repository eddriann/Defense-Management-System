<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/bind_params.php');
auth_check(['Admin']);
$search = request_filter_string($_GET, 'search');
$role_filter = $_GET['role'] ?? '';
if (!in_array($role_filter, ['', 'Chairperson', 'Panel Member'], true)) {
    $role_filter = '';
}
$stmt = mysqli_prepare(
    $conn,
    "SELECT u.full_name, u.email, pa.panel_role, COUNT(DISTINCT pa.schedule_id) AS assignment_count,
            COUNT(DISTINCT e.evaluation_id) AS evaluations_count,
            AVG(e.total_score) AS average_score
     FROM panel_assignments pa
     INNER JOIN users u ON u.user_id = pa.user_id
     LEFT JOIN evaluations e
       ON e.schedule_id = pa.schedule_id AND e.evaluator_id = pa.user_id
      AND pa.panel_role IN ('Chairperson', 'Panel Member')
     WHERE (? = '' OR u.full_name LIKE CONCAT('%', ?, '%') OR u.email LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR pa.panel_role = ?)
     GROUP BY u.user_id, u.full_name, u.email, pa.panel_role
     ORDER BY u.full_name, pa.panel_role"
);
bind_stmt_params($stmt, 'sssss', [$search, $search, $search, $role_filter, $role_filter]);
mysqli_stmt_execute($stmt);
$rows = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Report</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body><?php require __DIR__ . '/../includes/module_page_start.php'; ?><h1>Panel Assignment Report</h1>
    <form method="get" class="filter-form">
        <label for="search">Panelist</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="role">Assignment role</label><select name="role" id="role"><option value="">All roles</option><?php foreach (['Chairperson', 'Panel Member'] as $role): ?><option value="<?= $role ?>" <?= $role_filter === $role ? 'selected' : '' ?>><?= $role ?></option><?php endforeach; ?></select>
        <button type="submit">Filter</button><a class="button-secondary" href="panel_report.php">Clear</a>
    </form>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Panelist</th>
                <th>Email</th>
                <th>Assignment Role</th>
                <th>Defenses</th>
                <th>Evaluations Submitted</th>
                <th>Average Score</th>
            </tr>
        </thead>
        <tbody>
            <?php $any = false;
            while ($row = mysqli_fetch_assoc($rows)): $any = true; ?><tr>
                    <td><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['panel_role'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$row['assignment_count'] ?></td>
                    <td><?= (int)$row['evaluations_count'] ?></td>
                    <td><?= $row['average_score'] === null ? '—' : htmlspecialchars(number_format((float)$row['average_score'], 2), ENT_QUOTES, 'UTF-8') ?></td>
                </tr><?php endwhile;
                    if (!$any): ?><tr>
                    <td colspan="6">No panel assignments found.</td>
                </tr><?php endif; ?>
        </tbody>
    </table><?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>