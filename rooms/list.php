<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/roles.php');
require_once('../includes/bind_params.php');
$allowed_roles = ['Admin', 'Panel Member', 'Student'];
auth_check($allowed_roles);
$role_name = $_SESSION['role_name'] ?? '';
$search = request_filter_string($_GET, 'search');
$status_filter = $_GET['status'] ?? '';
if (!in_array($status_filter, ['', 'Active', 'Under Maintenance'], true)) {
    $status_filter = '';
}
$stmt = mysqli_prepare(
    $conn,
    "SELECT room_id, room_name, location, status FROM rooms
     WHERE (? = '' OR room_name LIKE CONCAT('%', ?, '%') OR location LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR status = ?)
     ORDER BY room_name"
);
bind_stmt_params($stmt, 'sssss', [$search, $search, $search, $status_filter, $status_filter]);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$rooms = mysqli_fetch_all($result, MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rooms List </title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Defense Rooms</h1>
    <?php if ($role_name === 'Admin'): ?><p><a class="button-primary" href="add.php">Add Room</a></p><?php endif; ?>
    <form method="get" class="filter-form">
        <label for="search">Room name or location</label>
        <input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="status">Status</label>
        <select name="status" id="status">
            <option value="">All statuses</option>
            <?php foreach (['Active', 'Under Maintenance'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <a class="button-secondary" href="list.php">Clear</a>
    </form>
    <table class="defense-table">
        <thead>
            <tr>
                <th>Room Name</th>
                <th>Location</th>
                <th>Daily Defense Capacity</th>
                <th>Status</th>
                <?php if ($role_name === 'Admin'): ?><th>Actions</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rooms as $room): ?>
                <tr>
                    <td><?= htmlspecialchars($room['room_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($room['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>4 groups (one per time slot)</td>
                    <td><?= htmlspecialchars($room['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <?php if ($role_name === 'Admin'): ?>
                        <td>
                            <a class="button-secondary button-compact" href="edit.php?id=<?= (int)$room['room_id'] ?>">Edit room</a>
                            <a class="button-secondary button-compact" href="availability.php?room_id=<?= (int)$room['room_id'] ?>">Availability</a>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <p><a class="button-secondary" href="<?= htmlspecialchars(dashboard_for($role_name), ENT_QUOTES, 'UTF-8') ?>">Dashboard</a></p>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>