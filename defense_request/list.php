<?php
include('../config/db.php');
require_once('../includes/auth_check.php');
require_once('../includes/bind_params.php');
$allowed_roles = ['Admin', 'Adviser', 'Student'];
auth_check($allowed_roles);

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
if (!in_array($role, ['Admin', 'Adviser', 'Student'], true)) {
    http_response_code(403);
    exit('Your active role cannot view defense requests.');
}
$search = request_filter_string($_GET, 'search');
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$is_student = $role === 'Student';
if ($is_student) {
    $search = '';
    $status_filter = '';
    $type_filter = '';
}
if (!in_array($status_filter, ['', 'Pending', 'Endorsed', 'Approved', 'Rejected'], true)) {
    $status_filter = '';
}
if (!in_array($type_filter, ['', 'Proposal', 'Final'], true)) {
    $type_filter = '';
}
$base_sql = "
    SELECT DISTINCT dr.request_id, dr.project_id, p.title, dr.defense_type,
           dr.preferred_date, ts.start_time, ts.end_time, dr.status, dr.remarks, dr.created_at,
           u.full_name AS requester, r.room_name
    FROM defense_requests dr
    INNER JOIN projects p ON p.project_id = dr.project_id
    INNER JOIN time_slots ts ON ts.slot_id = dr.slot_id
    INNER JOIN users u ON u.user_id = dr.requested_by
    LEFT JOIN defense_schedules ds ON ds.request_id = dr.request_id AND ds.status <> 'Cancelled'
    LEFT JOIN rooms r ON r.room_id = ds.room_id";
$where = [];
$params = [];
$types = '';

if ($role === 'Adviser') {
    $where[] = 'p.adviser_id = ?';
    $params[] = $user_id;
    $types .= 'i';
} elseif ($role === 'Student') {
    $student_stmt = mysqli_prepare($conn, "SELECT student_id FROM students WHERE user_id = ?");
    mysqli_stmt_bind_param($student_stmt, 'i', $user_id);
    mysqli_stmt_execute($student_stmt);
    $student_row = mysqli_fetch_assoc(mysqli_stmt_get_result($student_stmt));
    mysqli_stmt_close($student_stmt);
    $student_id = (int)($student_row['student_id'] ?? 0);
    $base_sql .= " INNER JOIN project_members pm ON pm.project_id = p.project_id";
    $where[] = 'pm.student_id = ?';
    $params[] = $student_id;
    $types .= 'i';
}
$where[] = "(? = '' OR p.title LIKE CONCAT('%', ?, '%'))";
$where[] = "(? = '' OR dr.status = ?)";
$where[] = "(? = '' OR dr.defense_type = ?)";
$params = array_merge($params, [$search, $search, $status_filter, $status_filter, $type_filter, $type_filter]);
$types .= 'ssssss';
$stmt = mysqli_prepare($conn, $base_sql . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY dr.created_at DESC');
bind_stmt_params($stmt, $types, $params);
mysqli_stmt_execute($stmt);
$requests = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense Requests</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Defense Requests</h1>
    <?php if (!$is_student): ?><form method="get" class="filter-form compact-filter">
        <label for="search">Project</label>
        <input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label>
        <select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="status">Request status</label>
        <select name="status" id="status"><option value="">All statuses</option><?php foreach (['Pending', 'Endorsed', 'Approved', 'Rejected'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select>
        <button type="submit">Filter</button>
        <a class="button-secondary" href="list.php">Clear</a>
    </form><?php endif; ?>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Project</th>
                <th>Type</th>
                <th>Requested By</th>
                <th>Date / Slot</th>
                <th>Room</th>
                <th>Status</th>
                <th>Remarks</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$requests): ?>
                <tr>
                    <td colspan="8">No defense requests found.</td>
                </tr>
                <?php else: foreach ($requests as $request): ?>
                    <tr>
                        <td><?= htmlspecialchars($request['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['requester'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(date('M j, Y', strtotime($request['preferred_date'])) . ' / ' . date('g:i A', strtotime($request['start_time'])) . ' - ' . date('g:i A', strtotime($request['end_time'])), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['room_name'] ?? 'Pending room assignment', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($request['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
            <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <?php if (!$is_student): ?>
        <p><a class="button-secondary" href="../dashboard/<?= $role === 'Admin' ? 'admin_dashboard.php' : 'adviser_dashboard.php' ?>">Dashboard</a></p>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>