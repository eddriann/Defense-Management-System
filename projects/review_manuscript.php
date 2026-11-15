<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/manuscript_access.php';
require_once __DIR__ . '/../includes/manuscript_status.php';

auth_check(['Admin', 'Adviser']);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
$manuscript_id = (int)($_GET['manuscript_id'] ?? $_POST['manuscript_id'] ?? 0);
$manuscript = get_accessible_manuscript($conn, $manuscript_id, $user_id, $role);
if (!$manuscript) {
    http_response_code(404);
    exit('Manuscript not found or you are not authorized to access it.');
}

$is_adviser = $role === 'Adviser';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    if (!$is_adviser) {
        http_response_code(403);
        exit('Only the project adviser can comment on a manuscript.');
    }

    $action_value = $_POST['action'] ?? '';
    $action = is_string($action_value) ? $action_value : '';
    if ($action === 'comment') {
        $page_number = filter_var($_POST['page_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $comment_value = $_POST['comment'] ?? '';
        $comment = is_string($comment_value) ? trim($comment_value) : '';
        $comment_length = function_exists('mb_strlen') ? mb_strlen($comment) : strlen($comment);
        if ($manuscript['status'] === 'Approved') {
            http_response_code(400);
            $error = 'An approved manuscript cannot receive additional comments.';
        } elseif ($page_number === false || $comment === '' || $comment_length > 4000) {
            $error = 'Enter a valid page number and a comment of up to 4,000 characters.';
        } else {
            mysqli_begin_transaction($conn);
            try {
                $lock = mysqli_prepare($conn, 'SELECT status FROM manuscripts WHERE manuscript_id = ? FOR UPDATE');
                mysqli_stmt_bind_param($lock, 'i', $manuscript_id);
                mysqli_stmt_execute($lock);
                $locked_manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
                mysqli_stmt_close($lock);
                if (!$locked_manuscript || $locked_manuscript['status'] === 'Approved') {
                    mysqli_rollback($conn);
                    $error = 'An approved manuscript cannot receive additional comments.';
                } else {
                    $insert = mysqli_prepare(
                        $conn,
                        'INSERT INTO manuscript_comments (manuscript_id, adviser_id, page_number, comment)
                         VALUES (?, ?, ?, ?)'
                    );
                    mysqli_stmt_bind_param($insert, 'iiis', $manuscript_id, $user_id, $page_number, $comment);
                    mysqli_stmt_execute($insert);
                    mysqli_stmt_close($insert);

                    $update = mysqli_prepare($conn, "UPDATE manuscripts SET status = 'In Revision' WHERE manuscript_id = ?");
                    mysqli_stmt_bind_param($update, 'i', $manuscript_id);
                    mysqli_stmt_execute($update);
                    mysqli_stmt_close($update);
                    mysqli_commit($conn);
                    header('Location: review_manuscript.php?manuscript_id=' . $manuscript_id . '&commented=1');
                    exit();
                }
            } catch (Throwable $exception) {
                mysqli_rollback($conn);
                throw $exception;
            }
        }
    } elseif ($action === 'approve') {
        if (!in_array($manuscript['status'], ['Submitted', 'Revision Submitted'], true)) {
            http_response_code(400);
            $error = 'Only a submitted manuscript or completed revision can be approved.';
        } else {
            $update = mysqli_prepare(
                $conn,
                "UPDATE manuscripts SET status = 'Approved'
                 WHERE manuscript_id = ? AND status IN ('Submitted', 'Revision Submitted')"
            );
            mysqli_stmt_bind_param($update, 'i', $manuscript_id);
            mysqli_stmt_execute($update);
            $approved = mysqli_stmt_affected_rows($update) === 1;
            mysqli_stmt_close($update);
            if (!$approved) {
                $error = 'The manuscript status changed before it could be approved. Reload and review it again.';
            } else {
                header('Location: review_manuscript.php?manuscript_id=' . $manuscript_id . '&approved=1');
                exit();
            }
        }
    }
}

$comment_stmt = mysqli_prepare(
    $conn,
    'SELECT mc.page_number, mc.comment, mc.created_at, u.full_name AS adviser_name
     FROM manuscript_comments mc
     INNER JOIN users u ON u.user_id = mc.adviser_id
     WHERE mc.manuscript_id = ?
     ORDER BY mc.page_number, mc.created_at'
);
mysqli_stmt_bind_param($comment_stmt, 'i', $manuscript_id);
mysqli_stmt_execute($comment_stmt);
$comments = mysqli_fetch_all(mysqli_stmt_get_result($comment_stmt), MYSQLI_ASSOC);
mysqli_stmt_close($comment_stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Manuscript | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>
<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Review manuscript</h1>
    <p><strong><?= htmlspecialchars($manuscript['original_filename'], ENT_QUOTES, 'UTF-8') ?></strong> · <?= htmlspecialchars(manuscript_status_label($manuscript['status']), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (isset($_GET['commented'])): ?><p class="form-success" role="status">Page comment saved; the manuscript is now marked Revision Required.</p><?php endif; ?>
    <?php if (isset($_GET['approved'])): ?><p class="form-success" role="status">Manuscript approved successfully.</p><?php endif; ?>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

    <div class="manuscript-review-layout">
        <section class="content-card manuscript-preview" id="manuscript-viewer"
            data-pdf-url="manuscript_file.php?manuscript_id=<?= (int)$manuscript_id ?>&amp;inline=1">
            <div class="manuscript-page-controls">
                <button type="button" class="button-secondary" id="previous-page" disabled>Previous page</button>
                <label for="page-number">Page</label>
                <input type="number" id="page-number" min="1" value="1" disabled>
                <span id="page-count" aria-live="polite">Loading pages…</span>
                <button type="button" class="button-secondary" id="next-page" disabled>Next page</button>
                <button type="button" class="button-secondary" id="fullscreen-preview" aria-pressed="false">Full screen</button>
            </div>
            <div class="manuscript-page-stage" id="manuscript-page-stage">
                <div class="manuscript-page-canvas-wrap" id="manuscript-page-canvas-wrap">
                    <canvas id="manuscript-page" aria-label="Manuscript page preview"></canvas>
                </div>
                <p id="manuscript-preview-error" role="alert" hidden></p>
            </div>
        </section>
        <aside>
            <?php if ($is_adviser && $manuscript['status'] !== 'Approved'): ?>
                <section class="content-card">
                    <h2>Add a page comment</h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="manuscript_id" value="<?= $manuscript_id ?>">
                        <input type="hidden" name="action" value="comment">
                        <label for="comment-page">Page number</label>
                        <input type="number" name="page_number" id="comment-page" min="1" value="1" required disabled>
                        <label for="comment">Comment</label>
                        <textarea name="comment" id="comment" maxlength="4000" required></textarea>
                        <button type="submit">Save comment</button>
                    </form>
                    <?php if (in_array($manuscript['status'], ['Submitted', 'Revision Submitted'], true)): ?>
                        <form method="post" onsubmit="return confirm('Approve this manuscript? The student will see it as approved.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="manuscript_id" value="<?= $manuscript_id ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit">Approve manuscript</button>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            <?php if ($is_adviser): ?>
                <section class="content-card">
                    <h2>Page comments</h2>
                    <?php if (!$comments): ?><p>No page comments yet.</p><?php else: ?>
                        <?php foreach ($comments as $comment): ?>
                            <article class="manuscript-comment">
                                <button type="button" class="button-secondary button-compact manuscript-comment-page" data-page="<?= (int)$comment['page_number'] ?>">Page <?= (int)$comment['page_number'] ?></button>
                                <p><?= nl2br(htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <small><?= htmlspecialchars(date('M j, Y g:i A', strtotime($comment['created_at'])), ENT_QUOTES, 'UTF-8') ?></small>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </aside>
    </div>
    <p><a class="button-secondary" href="manuscripts.php?project_id=<?= (int)$manuscript['project_id'] ?>">Back to submissions</a></p>
    <script type="module" src="../assets/js/manuscript_preview.js?v=4"></script>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>
</html>
