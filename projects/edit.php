<?php
include('../config/db.php');
include('../includes/auth_check.php');

require_once('../includes/roles.php');
require_once('../includes/csrf.php');

auth_check(['Admin', 'Adviser', 'Student']);
$project_id = (int)($_GET['id'] ?? $_POST['project_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare($conn, "SELECT project_id, title, project_type, adviser_id, created_by, status FROM projects WHERE project_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $project_id);
mysqli_stmt_execute($stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$project) {
    http_response_code(404);
    exit('Project not found.');
}
$is_admin = has_role('Admin');
$is_adviser = has_role('Adviser') && (int)$project['adviser_id'] === $user_id;
$leader_stmt = mysqli_prepare(
    $conn,
    "SELECT 1 FROM project_members pm
     INNER JOIN students s ON s.student_id = pm.student_id
     WHERE pm.project_id = ? AND pm.role_in_group = 'Leader' AND s.user_id = ?"
);
mysqli_stmt_bind_param($leader_stmt, 'ii', $project_id, $user_id);
mysqli_stmt_execute($leader_stmt);
$is_leader = mysqli_fetch_assoc(mysqli_stmt_get_result($leader_stmt)) !== null;
mysqli_stmt_close($leader_stmt);
$is_creator = has_role('Student') && ((int)$project['created_by'] === $user_id || $is_leader);
if (!$is_admin && !$is_adviser && !$is_creator) {
    http_response_code(403);
    exit('You are not authorized to edit this project.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $title = trim($_POST['title'] ?? '');
    $type = $_POST['project_type'] ?? '';
    $status = $_POST['status'] ?? $project['status'];
    $valid_statuses = ['Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped'];
    if (
        $title === '' || !in_array($type, ['Thesis', 'Capstone'], true) ||
        (($is_admin || $is_adviser) && !in_array($status, $valid_statuses, true))
    ) {
        $error = 'Enter a title and valid project values.';
    } else {
        if ($is_admin || $is_adviser) {
            $update = mysqli_prepare($conn, "UPDATE projects SET title = ?, project_type = ?, status = ? WHERE project_id = ?");
            mysqli_stmt_bind_param($update, 'sssi', $title, $type, $status, $project_id);
        } else {
            $update = mysqli_prepare(
                $conn,
                "UPDATE projects SET title = ?, project_type = ?
                 WHERE project_id = ? AND (created_by = ? OR EXISTS (
                     SELECT 1 FROM project_members pm
                     INNER JOIN students s ON s.student_id = pm.student_id
                     WHERE pm.project_id = projects.project_id AND pm.role_in_group = 'Leader'
                       AND s.user_id = ?
                 ))"
            );
            mysqli_stmt_bind_param($update, 'ssiii', $title, $type, $project_id, $user_id, $user_id);
        }
        mysqli_stmt_execute($update);
        mysqli_stmt_close($update);
        header('Location: list.php');
        exit();
    }
    $project['title'] = $title;
    $project['project_type'] = $type;
    $project['status'] = $status;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Edit Project</h1>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="project_id" value="<?= $project_id ?>">
        <label>Title <input name="title" required maxlength="255" value="<?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?>"></label><br>
        <label>Type <select name="project_type"><?php foreach (['Thesis', 'Capstone'] as $type): ?><option <?= $project['project_type'] === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select></label><br>
        <?php if ($is_admin || $is_adviser): ?>
            <label for="status">Project stage
                <select name="status" id="status">
                    <option value="Proposed" <?= $project['status'] === 'Proposed' ? 'selected' : '' ?>>Proposed — topic and scope under review</option>
                    <option value="In Progress" <?= $project['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress — research or development underway</option>
                    <option value="For Defense" <?= $project['status'] === 'For Defense' ? 'selected' : '' ?>>For Defense — ready for or awaiting a defense</option>
                    <option value="Completed" <?= $project['status'] === 'Completed' ? 'selected' : '' ?>>Completed — final defense and required revisions finished</option>
                    <option value="Dropped" <?= $project['status'] === 'Dropped' ? 'selected' : '' ?>>Dropped — project discontinued</option>
                </select>
                <small class="sub-note">Advisers and Admins can update the project stage as it progresses. Scheduling and defense-day workflows may update the stage automatically.</small>
            </label><br>
        <?php endif; ?>
        <button type="submit">Save Project</button>
    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>