<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/manuscript_access.php';
require_once __DIR__ . '/../includes/manuscript_progress.php';
require_once __DIR__ . '/../includes/manuscript_upload.php';

auth_check(['Admin', 'Adviser', 'Student']);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
$project_id = (int)($_GET['project_id'] ?? $_POST['project_id'] ?? 0);

$project_stmt = mysqli_prepare($conn, 'SELECT project_id, title, adviser_id FROM projects WHERE project_id = ?');
mysqli_stmt_bind_param($project_stmt, 'i', $project_id);
mysqli_stmt_execute($project_stmt);
$project = mysqli_fetch_assoc(mysqli_stmt_get_result($project_stmt));
mysqli_stmt_close($project_stmt);

if (!$project || !user_can_access_project_manuscript($conn, $project_id, $user_id, $role)) {
    http_response_code($project ? 403 : 404);
    exit($project ? 'You are not authorized to access this project.' : 'Project not found.');
}

$latest_stmt = mysqli_prepare(
    $conn,
    'SELECT status FROM manuscripts WHERE project_id = ?
     ORDER BY submitted_at DESC, manuscript_id DESC LIMIT 1'
);
mysqli_stmt_bind_param($latest_stmt, 'i', $project_id);
mysqli_stmt_execute($latest_stmt);
$latest_manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($latest_stmt));
mysqli_stmt_close($latest_stmt);
$latest_manuscript_status = $latest_manuscript['status'] ?? '';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    if ($role !== 'Student') {
        http_response_code(403);
        exit('Only project students can upload manuscripts.');
    }

    if ($latest_manuscript_status === 'Approved') {
        $error = 'The latest manuscript is approved. No additional manuscript can be submitted.';
    } else {
        try {
            $uploaded_file = store_manuscript_upload($_FILES['manuscript'] ?? []);
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        }
    }

    if ($error === '') {
        $transaction_started = false;
        try {
            mysqli_begin_transaction($conn);
            $transaction_started = true;
            $latest_stmt = mysqli_prepare(
                $conn,
                'SELECT status FROM manuscripts WHERE project_id = ?
                 ORDER BY submitted_at DESC, manuscript_id DESC LIMIT 1 FOR UPDATE'
            );
            mysqli_stmt_bind_param($latest_stmt, 'i', $project_id);
            mysqli_stmt_execute($latest_stmt);
            $latest_manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($latest_stmt));
            mysqli_stmt_close($latest_stmt);

            if (($latest_manuscript['status'] ?? '') === 'Approved') {
                mysqli_rollback($conn);
                $transaction_started = false;
                if (is_file($uploaded_file['stored_path'])) {
                    unlink($uploaded_file['stored_path']);
                }
                $error = 'The latest manuscript is approved. No additional manuscript can be submitted.';
            } else {
                $initial_status = ($latest_manuscript['status'] ?? '') === 'In Revision'
                    ? 'Revision Submitted'
                    : 'Submitted';
                $insert = mysqli_prepare(
                    $conn,
                    "INSERT INTO manuscripts (project_id, uploaded_by, original_filename, stored_filename, file_size, status)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $insert,
                    'iissis',
                    $project_id,
                    $user_id,
                    $uploaded_file['original_filename'],
                    $uploaded_file['stored_filename'],
                    $uploaded_file['file_size'],
                    $initial_status
                );
                mysqli_stmt_execute($insert);
                mysqli_stmt_close($insert);
                mysqli_commit($conn);
                $transaction_started = false;
                header('Location: manuscripts.php?project_id=' . $project_id . '&uploaded=1');
                exit();
            }
        } catch (Throwable $exception) {
            if ($transaction_started) {
                mysqli_rollback($conn);
            }
            if (is_file($uploaded_file['stored_path'])) {
                unlink($uploaded_file['stored_path']);
            }
            throw $exception;
        }
    }
}

$is_student = $role === 'Student';
$stmt = mysqli_prepare(
    $conn,
    "SELECT m.manuscript_id, m.original_filename, m.file_size, m.status, m.submitted_at,
            uploader.full_name AS uploader_name,
            (SELECT COUNT(*) FROM manuscript_comments mc WHERE mc.manuscript_id = m.manuscript_id) AS comment_count,
            (SELECT MAX(mc.created_at) FROM manuscript_comments mc WHERE mc.manuscript_id = m.manuscript_id) AS latest_comment_at
     FROM manuscripts m
     INNER JOIN users uploader ON uploader.user_id = m.uploaded_by
     WHERE m.project_id = ?
     ORDER BY m.submitted_at DESC, m.manuscript_id DESC"
);
mysqli_stmt_bind_param($stmt, 'i', $project_id);
mysqli_stmt_execute($stmt);
$manuscripts = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manuscripts | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?v=<?= filemtime(__DIR__ . '/../assets/css/modules.css') ?>&amp;rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Manuscripts: <?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <?php if ($is_student): ?>
        <?php render_manuscript_progress($conn, $project_id, true); ?>
    <?php endif; ?>
    <?php if (isset($_GET['uploaded'])): ?><p class="form-success" role="status">Manuscript submitted successfully.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <?php if ($is_student && $latest_manuscript_status !== 'Approved'): ?>
        <section class="content-card">
            <h2>Submit a manuscript</h2>
            <p class="sub-note">PDF only, up to 20 MB. Your adviser can request revisions or approve a completed submission. Only your adviser and Admin can preview or download the submitted file.</p>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="project_id" value="<?= $project_id ?>">
                <label for="manuscript">PDF manuscript</label>
                <input type="file" id="manuscript" name="manuscript" accept="application/pdf,.pdf" required>
                <button type="submit">Submit manuscript</button>
            </form>
        </section>
    <?php elseif ($is_student): ?>
        <p class="form-success" role="status">Your latest manuscript is approved. Additional manuscript uploads are closed.</p>
    <?php endif; ?>

    <section class="content-card">
        <h2><?= $is_student ? 'Submission status and adviser comments' : 'Submitted files' ?></h2>
        <div class="table-wrap">
            <table class="defense-table">
                <thead>
                    <tr>
                        <?php if (!$is_student): ?><th>File</th><th>Submitted By</th><?php endif; ?>
                        <th>Submitted</th>
                        <th>Status</th>
                        <?php if ($is_student): ?><th>Page comments</th><?php else: ?><th>Size</th><th>Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$manuscripts): ?>
                        <tr><td class="empty-state" colspan="<?= $is_student ? 3 : 5 ?>">No manuscripts have been submitted.</td></tr>
                    <?php else: foreach ($manuscripts as $manuscript): ?>
                        <tr>
                            <?php if (!$is_student): ?>
                                <td><?= htmlspecialchars($manuscript['original_filename'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($manuscript['uploader_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($manuscript['submitted_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td>
                                                <span class="status-badge"><?= htmlspecialchars($manuscript['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </td>
                            <?php if ($is_student): ?>
                                <td><?= (int)$manuscript['comment_count'] ?> comment<?= (int)$manuscript['comment_count'] === 1 ? '' : 's' ?></td>
                            <?php else: ?>
                                <td><?= number_format((int)$manuscript['file_size'] / 1048576, 2) ?> MB</td>
                                <td><a class="row-action" href="review_manuscript.php?manuscript_id=<?= (int)$manuscript['manuscript_id'] ?>"><?= $role === 'Adviser' ? 'Review' : 'Preview' ?></a></td>
                            <?php endif; ?>
                        </tr>
                        <?php if ($is_student):
                            $comment_stmt = mysqli_prepare(
                                $conn,
                                "SELECT mc.page_number, mc.comment, mc.created_at, adviser.full_name AS adviser_name
                                 FROM manuscript_comments mc
                                 INNER JOIN users adviser ON adviser.user_id = mc.adviser_id
                                 WHERE mc.manuscript_id = ?
                                 ORDER BY mc.page_number, mc.created_at"
                            );
                            mysqli_stmt_bind_param($comment_stmt, 'i', $manuscript['manuscript_id']);
                            mysqli_stmt_execute($comment_stmt);
                            $comments = mysqli_fetch_all(mysqli_stmt_get_result($comment_stmt), MYSQLI_ASSOC);
                            mysqli_stmt_close($comment_stmt);
                            foreach ($comments as $comment): ?>
                                <tr>
                                    <td colspan="3"><strong>Page <?= (int)$comment['page_number'] ?></strong> · <?= nl2br(htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8')) ?><br><small><?= htmlspecialchars($comment['adviser_name'] . ' · ' . date('M j, Y g:i A', strtotime($comment['created_at'])), ENT_QUOTES, 'UTF-8') ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <p><a class="button-secondary" href="list.php">Back to projects</a></p>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
