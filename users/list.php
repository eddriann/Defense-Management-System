<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/bind_params.php');
$allowed_role = ['Admin'];
auth_check($allowed_role);

$search = request_filter_string($_GET, 'search');
$status_filter = $_GET['status'] ?? '';
$role_filter = $_GET['role'] ?? '';
if (!in_array($status_filter, ['', 'active', 'inactive'], true)) {
    $status_filter = '';
}
$valid_roles = ['Admin', 'Adviser', 'Panel Member', 'Student'];
if (!in_array($role_filter, array_merge([''], $valid_roles), true)) {
    $role_filter = '';
}

// One row per user; all of the user's roles are joined into a single column
$stmt = mysqli_prepare(
    $conn,
    "SELECT
        u.user_id,
        u.full_name,
        u.username,
        u.email,
        GROUP_CONCAT(r.role_name ORDER BY r.role_id SEPARATOR ', ') AS role_names,
        u.status,
        u.created_at,
        u.updated_at
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.user_id
    LEFT JOIN roles r ON r.role_id = ur.role_id
    WHERE (? = '' OR u.full_name LIKE CONCAT('%', ?, '%')
           OR u.username LIKE CONCAT('%', ?, '%') OR u.email LIKE CONCAT('%', ?, '%'))
      AND (? = '' OR u.status = ?)
      AND (? = '' OR EXISTS (
          SELECT 1 FROM user_roles role_filter
          INNER JOIN roles filtered_role ON filtered_role.role_id = role_filter.role_id
          WHERE role_filter.user_id = u.user_id AND filtered_role.role_name = ?
      ))
    GROUP BY u.user_id, u.full_name, u.username, u.email, u.status, u.created_at, u.updated_at
    ORDER BY u.user_id"
);
bind_stmt_params(
    $stmt,
    'ssssssss',
    [$search, $search, $search, $search, $status_filter, $status_filter, $role_filter, $role_filter]
);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$users = mysqli_fetch_all($result, MYSQLI_ASSOC);
$message = $_GET['redirect'] ?? '';

// check if user was added or edited
if ($message == 1) {
    $message = "User added successfully";
} elseif ($message == 2) {
    $message = "Edit Success";
} else {
    $message = '';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User List</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>User Management</h1>
    <?php if ($message !== ''): ?><p class="form-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <section class="content-card user-management-card">
        <div class="user-management-heading">
            <div>
                <h2>System accounts</h2>
                <p class="sub-note"><?= number_format(count($users)) ?> registered user<?= count($users) === 1 ? '' : 's' ?> · Review roles and account status.</p>
            </div>
            <a class="button-primary" href="add.php"><?= dashboard_icon('user-plus') ?>Add user</a>
        </div>
        <form method="get" class="filter-form">
            <label for="search">Search</label>
            <input type="search" name="search" id="search" placeholder="Name, username, or email" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <label for="role">Role</label>
            <select name="role" id="role">
                <option value="">All roles</option>
                <?php foreach ($valid_roles as $role): ?><option value="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" <?= $role_filter === $role ? 'selected' : '' ?>><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select>
            <label for="status">Account status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <?php foreach (['active', 'inactive'] as $status): ?><option value="<?= $status ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option><?php endforeach; ?>
            </select>
            <button type="submit">Filter</button>
            <a class="button-secondary" href="list.php">Clear</a>
        </form>
    <div class="table-wrap">
    <table class="user-table">
        <thead>
            <tr>
                <th>User</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Updated At</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><span class="user-table-username">@<?= htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><div class="role-badges">
                        <?php foreach (array_filter(array_map('trim', explode(',', $user['role_names'] ?? ''))) as $role_name):
                            $role_class = strtolower(str_replace(' ', '-', $role_name)); ?>
                            <span class="role-badge role-<?= htmlspecialchars($role_class, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($role_name, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endforeach; ?>
                    </div></td>
                    <td><span class="account-status status-<?= htmlspecialchars($user['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($user['status']), ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars(date('M j, Y', strtotime($user['updated_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php if ((int)$user['user_id'] === (int)$_SESSION['user_id']): ?><span class="sub-note">Current account</span><?php else: ?><a class="button-secondary" href="edit.php?id=<?= (int)$user['user_id'] ?>">Edit user</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </section>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>
