<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/csrf.php');
$allowed_role = ['Admin'];
auth_check($allowed_role);

$id = (int)($_GET['id'] ?? $_POST['userId'] ?? 0);
$error = '';
if ($id === (int)$_SESSION['user_id']) {
    http_response_code(403);
    exit('You cannot edit your own account through User Management.');
}

// The user_roles table stores the full set of roles.
$stmt = mysqli_prepare(
    $conn,
    "SELECT user_id, full_name, username, email, status, updated_at
     FROM users
     WHERE user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    header("Location: list.php");
    exit();
}

// Roles the user holds now
$stmt = mysqli_prepare($conn, "SELECT role_id FROM user_roles WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $id);
mysqli_stmt_execute($stmt);
$currentRoles = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'role_id'));
mysqli_stmt_close($stmt);

// Preserve Student and other roles; only Adviser and Panel Member roles are editable.
$isStudent = in_array(4, $currentRoles, true);

$selected = [];

// Runs a COUNT query; every "?" is bound to the same user id
function countOf(mysqli $conn, string $sql, int $id): int
{
    $stmt = mysqli_prepare($conn, $sql);
    $params = array_fill(0, substr_count($sql, '?'), $id);
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return (int)$row[0];
}

if (isset($_POST['submit'])) {
    csrf_validate_request();
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $status = $_POST['status'] ?? '';
    $newPassword = $_POST['password'] ?? '';

    // keep what the admin typed if validation fails
    $user['full_name'] = $fullname;
    $user['email'] = $email;
    $user['username'] = $username;
    $user['status'] = $status;

    $selected = $isStudent ? [] : array_values(array_intersect(array_map('intval', (array)($_POST['roles'] ?? [])), [2, 3]));
    $kept = array_values(array_diff($currentRoles, [2, 3]));
    $finalRoles = array_merge($kept, $selected);

    if ($fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $username === '' ||
        !in_array($status, ['active', 'inactive'], true)) {
        $error = 'Enter a full name, username, valid email, and account status.';
    } elseif ($id === (int)$_SESSION['user_id'] && $status !== 'active') {
        $error = 'You cannot deactivate your own administrator account.';
    } elseif (!$isStudent && empty($finalRoles)) {
        $error = 'A user must keep at least one role.';
    } else {
        if (!$isStudent) {
            $removing = array_diff(array_intersect($currentRoles, [2, 3]), $selected);
            $adding = array_diff($selected, $currentRoles);

            // Cannot drop Adviser while the user still advises students or projects
            if (in_array(2, $removing, true)) {
                $n = countOf(
                    $conn,
                    "SELECT (SELECT COUNT(*) FROM students WHERE adviser_id = ?) + (SELECT COUNT(*) FROM projects WHERE adviser_id = ?)",
                    $id
                );
                if ($n > 0) {
                    $error = 'This user is still an adviser of students/projects, so the Adviser role cannot be removed.';
                }
            }

            // A current chairperson must retain the Panel Member role.
            if ($error === '' && in_array(3, $removing, true)) {
                $n = countOf(
                    $conn,
                    "SELECT COUNT(*) FROM panel_assignments WHERE user_id = ? AND panel_role = 'Chairperson'",
                    $id
                );
                if ($n > 0) {
                    $error = 'This user is assigned as a Chairperson and cannot lose the Panel Member role.';
                }
            }
        }

        if ($error === '') {
            date_default_timezone_set('Asia/Manila');
            $updated_at = date('Y-m-d H:i:s');
            mysqli_begin_transaction($conn);
            try {
                // Only change the password when a new one was typed
                if ($newPassword !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE users SET full_name = ?, email = ?, username = ?, password = ?, status = ?, updated_at = ? WHERE user_id = ?"
                    );
                    mysqli_stmt_bind_param($update, 'ssssssi', $fullname, $email, $username, $hash, $status, $updated_at, $id);
                } else {
                    $update = mysqli_prepare(
                        $conn,
                        "UPDATE users SET full_name = ?, email = ?, username = ?, status = ?, updated_at = ? WHERE user_id = ?"
                    );
                    mysqli_stmt_bind_param($update, 'sssssi', $fullname, $email, $username, $status, $updated_at, $id);
                }
                mysqli_stmt_execute($update);

                if (!$isStudent) {
                    $del = mysqli_prepare($conn, "DELETE FROM user_roles WHERE user_id = ? AND role_id IN (2, 3)");
                    mysqli_stmt_bind_param($del, 'i', $id);
                    mysqli_stmt_execute($del);

                    $ins = mysqli_prepare($conn, "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    mysqli_stmt_bind_param($ins, 'ii', $id, $role_id);
                    foreach ($selected as $role_id) {
                        mysqli_stmt_execute($ins);
                    }
                }

                mysqli_commit($conn);
                header("Location: list.php?redirect=2");
                exit();
            } catch (mysqli_sql_exception $e) {
                mysqli_rollback($conn);
                $error = ($e->getCode() == 1062)
                    ? 'That username or email is already in use.'
                    : 'Could not save the changes.';
            }
        }
    }
}

$hasRole = function (int $r) use ($currentRoles, $selected) {
    // after a failed save, show what the admin ticked; otherwise the stored roles
    return isset($_POST['submit']) ? in_array($r, $selected, true) : in_array($r, $currentRoles, true);
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <?php if ($error !== ''): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="edit.php" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="userId" value="<?= (int)$user['user_id'] ?>">
        <label for="fullname">
            Full Name:
            <input type="text" name="fullname" value="<?= htmlspecialchars($user['full_name']) ?>">
        </label><br>
        <label for="email">
            Email:
            <input type="text" name="email" value="<?= htmlspecialchars($user['email']) ?>">
        </label><br>
        <label for="username">
            Username:
            <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>">
        </label><br>
        <label for="status">Account status:
            <select name="status" id="status" required>
                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label><br>
        <label for="password">
            New Password (leave blank to keep the current one):
            <input type="password" name="password">
        </label><br>

        <?php if ($isStudent): ?>
            <p>Role: Student (not editable here)</p>
        <?php else: ?>
            <p>Roles:</p>
            <label><input type="checkbox" name="roles[]" value="2" <?= $hasRole(2) ? 'checked' : '' ?>> Adviser</label><br>
            <label><input type="checkbox" name="roles[]" value="3" <?= $hasRole(3) ? 'checked' : '' ?>> Panel Member</label><br>
            <?php if (in_array(1, $currentRoles, true)): ?>
                <small>This user is also an Admin (kept as is).</small><br>
            <?php endif; ?>
        <?php endif; ?>

        <input type="submit" value="Save" name="submit">
    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>
