<?php
include('../config/db.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
require_once('../includes/manuscript_upload.php');
include('../includes/auth_check.php');
auth_check(['Student']);

$user_id = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare(
    $conn,
    "SELECT s.student_id, s.adviser_id, u.full_name AS adviser_name
     FROM students s
     LEFT JOIN users u ON s.adviser_id = u.user_id
     WHERE s.user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$student_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$student_info) {
    http_response_code(403);
    exit('A student profile is required to submit a project.');
}

$student_id = (int)$student_info['student_id'];
$_SESSION['student_id'] = $student_id;

$eligibility = mysqli_prepare(
    $conn,
    "SELECT
        COUNT(pm.project_member_id) AS total_projects,
        SUM(CASE WHEN pm.role_in_group = 'Leader' THEN 1 ELSE 0 END) AS leader_count
     FROM project_members pm
     WHERE pm.student_id = ?"
);
mysqli_stmt_bind_param($eligibility, 'i', $student_id);
mysqli_stmt_execute($eligibility);
$eligibility_row = mysqli_fetch_assoc(mysqli_stmt_get_result($eligibility));
mysqli_stmt_close($eligibility);
$can_submit = (int)$eligibility_row['total_projects'] === 0;

if (!$can_submit) {
    http_response_code(403);
    $_SESSION['student_flash_error'] = 'You can submit a project only when you are not already in another project.';
    header('Location: ../dashboard/student_dashboard.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $title_value = $_POST['title'] ?? '';
    $title = is_string($title_value) ? trim($title_value) : '';
    $project_type = $_POST['project_type'] ?? '';
    $adviser_id = (int)$student_info['adviser_id'];

    if ($title === '' || !in_array($project_type, ['Capstone', 'Thesis'], true) || $adviser_id <= 0) {
        $error = 'Enter a title, select a project type, and ensure an adviser is assigned.';
    } else {
        try {
            $uploaded_file = store_manuscript_upload($_FILES['manuscript'] ?? []);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === '') {
            mysqli_begin_transaction($conn);
            try {
                $student_lock = mysqli_prepare($conn, "SELECT student_id FROM students WHERE student_id = ? FOR UPDATE");
                mysqli_stmt_bind_param($student_lock, 'i', $student_id);
                mysqli_stmt_execute($student_lock);
                mysqli_stmt_get_result($student_lock);
                mysqli_stmt_close($student_lock);

                $project_check = mysqli_prepare($conn, "SELECT 1 FROM project_members WHERE student_id = ? LIMIT 1");
                mysqli_stmt_bind_param($project_check, 'i', $student_id);
                mysqli_stmt_execute($project_check);
                $already_in_project = mysqli_fetch_assoc(mysqli_stmt_get_result($project_check));
                mysqli_stmt_close($project_check);
                if ($already_in_project) {
                    mysqli_rollback($conn);
                    unlink($uploaded_file['stored_path']);
                    $_SESSION['student_flash_error'] = 'You can submit a project only when you are not already in another project.';
                    header('Location: ../dashboard/student_dashboard.php');
                    exit();
                }

                $add_project = mysqli_prepare(
                    $conn,
                    "INSERT INTO projects (title, project_type, adviser_id, created_by)
                     VALUES (?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param($add_project, 'ssii', $title, $project_type, $adviser_id, $user_id);
                mysqli_stmt_execute($add_project);
                $project_id = mysqli_insert_id($conn);
                mysqli_stmt_close($add_project);

                $add_member = mysqli_prepare(
                    $conn,
                    "INSERT INTO project_members (project_id, student_id, role_in_group)
                     VALUES (?, ?, 'Leader')"
                );
                mysqli_stmt_bind_param($add_member, 'ii', $project_id, $student_id);
                mysqli_stmt_execute($add_member);
                mysqli_stmt_close($add_member);

                $add_manuscript = mysqli_prepare(
                    $conn,
                    "INSERT INTO manuscripts (project_id, uploaded_by, original_filename, stored_filename, file_size, status)
                     VALUES (?, ?, ?, ?, ?, 'Submitted')"
                );
                mysqli_stmt_bind_param(
                    $add_manuscript,
                    'iissi',
                    $project_id,
                    $user_id,
                    $uploaded_file['original_filename'],
                    $uploaded_file['stored_filename'],
                    $uploaded_file['file_size']
                );
                mysqli_stmt_execute($add_manuscript);
                mysqli_stmt_close($add_manuscript);

                mysqli_commit($conn);
                header('Location: list.php');
                exit();
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                if (is_file($uploaded_file['stored_path'])) {
                    unlink($uploaded_file['stored_path']);
                }
                throw $e;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Project</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Submit Project</h1>
    <?php if ($error !== ''): ?>
        <p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form action="" method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label for="title">Thesis/Capstone Title:
            <input type="text" name="title" id="title" required>
        </label><br><br>
        <label for="project_type">Project Type:
            <select name="project_type" id="project_type" required>
                <option value="" disabled selected>--- Select Project Type ---</option>
                <option value="Capstone">Capstone</option>
                <option value="Thesis">Thesis</option>
            </select>
        </label><br><br>
        <label for="manuscript">Initial Manuscript (PDF, max 20 MB):
            <input type="file" name="manuscript" id="manuscript" accept="application/pdf,.pdf" required>
        </label><br><br>
        <p>Adviser: <?= htmlspecialchars($student_info['adviser_name'] ?? 'No Adviser Assigned', ENT_QUOTES, 'UTF-8') ?></p>
        <input type="submit" value="Submit Project">
    </form>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>