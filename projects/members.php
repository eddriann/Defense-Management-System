<?php
include('../config/db.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
include('../includes/auth_check.php');
auth_check(['Student']);

$project_id = (int)($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$owner_stmt = mysqli_prepare(
    $conn,
    "SELECT p.project_id
     FROM projects p
     WHERE p.project_id = ?
       AND (p.created_by = ? OR EXISTS (
            SELECT 1
            FROM project_members pm
            INNER JOIN students s ON s.student_id = pm.student_id
            WHERE pm.project_id = p.project_id AND pm.role_in_group = 'Leader'
              AND s.user_id = ?
       ))"
);
mysqli_stmt_bind_param($owner_stmt, 'iii', $project_id, $user_id, $user_id);
mysqli_stmt_execute($owner_stmt);
$owned_project = mysqli_fetch_assoc(mysqli_stmt_get_result($owner_stmt));
mysqli_stmt_close($owner_stmt);

if (!$owned_project) {
    http_response_code(403);
    exit('You can only manage members for a project you created.');
}

$error_message = '';
$member_count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total_members FROM project_members WHERE project_id = ?");
mysqli_stmt_bind_param($member_count_stmt, 'i', $project_id);
mysqli_stmt_execute($member_count_stmt);
$count_row = mysqli_fetch_assoc(mysqli_stmt_get_result($member_count_stmt));
$total_members = (int)$count_row['total_members'];
mysqli_stmt_close($member_count_stmt);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $members = $_POST['members'] ?? [];
    if (!is_array($members)) {
        $members = [];
    }
    $valid_members = array_values(array_unique(array_filter(
        array_map('intval', $members),
        static function ($id) {
            return $id > 0;
        }
    )));

    if (!$valid_members) {
        $error_message = 'Select at least one student to add.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $lock = mysqli_prepare(
                $conn,
                "SELECT p.project_id
                 FROM projects p
                 WHERE p.project_id = ?
                   AND (p.created_by = ? OR EXISTS (
                       SELECT 1
                       FROM project_members pm
                       INNER JOIN students s ON s.student_id = pm.student_id
                       WHERE pm.project_id = p.project_id AND pm.role_in_group = 'Leader'
                         AND s.user_id = ?
                   ))
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($lock, 'iii', $project_id, $user_id, $user_id);
            mysqli_stmt_execute($lock);
            $still_owned = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$still_owned) {
                throw new RuntimeException('Project ownership could not be verified.');
            }

            $count = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM project_members WHERE project_id = ?");
            mysqli_stmt_bind_param($count, 'i', $project_id);
            mysqli_stmt_execute($count);
            $total_members = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($count))['total'];
            mysqli_stmt_close($count);

            if ($total_members + count($valid_members) > 3) {
                $error_message = "Group cannot exceed 3 members. It currently has $total_members member(s).";
                mysqli_rollback($conn);
            } else {
                $available = mysqli_prepare(
                    $conn,
                    "SELECT student_id FROM students
                     WHERE student_id = ? AND NOT EXISTS (
                         SELECT 1 FROM project_members WHERE project_members.student_id = students.student_id
                     )"
                );
                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO project_members (project_id, student_id, role_in_group)
                     VALUES (?, ?, 'Member')"
                );
                foreach ($valid_members as $student_id) {
                    mysqli_stmt_bind_param($available, 'i', $student_id);
                    mysqli_stmt_execute($available);
                    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($available))) {
                        throw new RuntimeException('One or more selected students are no longer available.');
                    }
                    mysqli_stmt_bind_param($insert, 'ii', $project_id, $student_id);
                    mysqli_stmt_execute($insert);
                }
                mysqli_stmt_close($available);
                mysqli_stmt_close($insert);
                mysqli_commit($conn);
                header('Location: list.php');
                exit();
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            if ($e instanceof RuntimeException) {
                $error_message = $e->getMessage();
            } else {
                throw $e;
            }
        }
    }
}

// Fetch current group members for display
$members_stmt = mysqli_prepare(
    $conn,
    "SELECT s.student_id, s.student_number, u.full_name, pm.role_in_group
     FROM project_members pm
     JOIN students s ON s.student_id = pm.student_id
     JOIN users u ON u.user_id = s.user_id
     WHERE pm.project_id = ?
     ORDER BY pm.role_in_group ASC, u.full_name"
);
mysqli_stmt_bind_param($members_stmt, 'i', $project_id);
mysqli_stmt_execute($members_stmt);
$current_members = mysqli_fetch_all(mysqli_stmt_get_result($members_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($members_stmt);

$stmt = mysqli_prepare(
    $conn,
    "SELECT s.student_id, s.student_number, u.full_name
     FROM students s
     JOIN users u ON u.user_id = s.user_id
     WHERE NOT EXISTS (
         SELECT 1 FROM project_members pm WHERE pm.student_id = s.student_id
     )
     ORDER BY u.full_name"
);
mysqli_stmt_execute($stmt);
$students = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Group Members</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Manage Group Members</h1>
    <p>Current Group Count: <strong><?= $total_members ?> / 3</strong></p>
    <?php if ($error_message !== ''): ?>
        <p role="alert"><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>

    <?php if ($total_members >= 3): ?>
        <p>This group has reached the maximum limit of 3 members.</p>
        <h3>Current Group Members:</h3>
        <ul>
            <?php foreach ($current_members as $member): ?>
                <li>
                    <?= htmlspecialchars($member['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    (<em><?= htmlspecialchars($member['role_in_group'], ENT_QUOTES, 'UTF-8') ?></em>)
                </li>
            <?php endforeach; ?>
        </ul>
    <?php elseif (!$students): ?>
        <p>No eligible students are available to add.</p>
    <?php else: ?>
        <form action="members.php" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="project_id" value="<?= $project_id ?>">
            <label for="members">Select students (up to <?= 3 - $total_members ?>):</label>
            <select name="members[]" id="members" multiple required>
                <?php foreach ($students as $student): ?>
                    <option value="<?= (int)$student['student_id'] ?>">
                        <?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select><br><br>
            <input type="submit" value="Add Members">
        </form>
    <?php endif; ?>
    <br><a class="button-secondary" href="list.php">Back to Projects</a>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>