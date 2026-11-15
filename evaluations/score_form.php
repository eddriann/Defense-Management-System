<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
require_once('../includes/panel_assignment.php');
require_once('../includes/refresh_defense_status.php');
require_once('../includes/evaluation_window.php');
auth_check(['Panel Member']);
refresh_defense_day_status($conn);


$schedule_id = (int)($_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, ds.project_id, ds.status, ds.scheduled_date, ds.defense_type, p.title,
            (SELECT GROUP_CONCAT(member_user.full_name ORDER BY member_user.full_name SEPARATOR ', ')
             FROM project_members pm
             JOIN students group_student ON group_student.student_id = pm.student_id
             JOIN users member_user ON member_user.user_id = group_student.user_id
             WHERE pm.project_id = ds.project_id) AS group_members,
            ts.start_time, ts.end_time, r.room_name, pa.panel_role,
            ds.final_result, ds.finalized_at
     FROM panel_assignments pa
     INNER JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
     INNER JOIN projects p ON p.project_id = ds.project_id
     INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
     INNER JOIN rooms r ON r.room_id = ds.room_id
     LEFT JOIN panel_assignment_responses response
       ON response.schedule_id = pa.schedule_id AND response.user_id = pa.user_id
     WHERE pa.schedule_id = ? AND pa.user_id = ?
       AND pa.panel_role IN ('Chairperson', 'Panel Member')
       AND response.user_id IS NULL
       AND ds.status <> 'Cancelled'"
);
mysqli_stmt_bind_param($stmt, 'ii', $schedule_id, $user_id);
mysqli_stmt_execute($stmt);
$schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$schedule) {
    http_response_code(403);
    exit('You can only evaluate a defense assigned to you.');
}
$evaluation_window = getDefenseEvaluationWindowStatus(
    $schedule['scheduled_date'],
    $schedule['start_time'],
    $schedule['end_time']
);
$evaluation_stmt = mysqli_prepare(
    $conn,
    "SELECT total_score, decision, comments FROM evaluations WHERE schedule_id = ? AND evaluator_id = ?"
);
mysqli_stmt_bind_param($evaluation_stmt, 'ii', $schedule_id, $user_id);
mysqli_stmt_execute($evaluation_stmt);
$evaluation = mysqli_fetch_assoc(mysqli_stmt_get_result($evaluation_stmt)) ?: [];
mysqli_stmt_close($evaluation_stmt);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_request();
    $score_raw = trim($_POST['total_score'] ?? '');
    $decision = $_POST['decision'] ?? '';
    $comments = trim($_POST['comments'] ?? '');
    $score_is_valid = $score_raw !== '' && is_numeric($score_raw) &&
        (float)$score_raw >= 0 && (float)$score_raw <= 100;
    if ($evaluation_window !== 'open') {
        $error = $evaluation_window === 'not_open'
            ? 'The evaluation form opens at the scheduled defense start time.'
            : 'The evaluation window is closed because the scheduled defense time has ended.';
    } elseif (!$score_is_valid) {
        $error = 'Score must be a number from 0 to 100.';
    } elseif (!in_array($decision, ['Defended', 'Redefense'], true)) {
        $error = 'Choose whether the project is defended or needs a re-defense.';
    } elseif (strlen($comments) > 1000) {
        $error = 'Comments cannot exceed 1000 characters.';
    } elseif ($schedule['finalized_at'] !== null || $schedule['final_result'] !== null) {
        $error = 'All panel evaluations are complete; the result is locked pending Admin confirmation.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $assignment_lock = mysqli_prepare(
                $conn,
                "SELECT panel_assignment_id
                 FROM panel_assignments
                 WHERE schedule_id = ? AND user_id = ?
                   AND panel_role IN ('Chairperson', 'Panel Member')
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($assignment_lock, 'ii', $schedule_id, $user_id);
            mysqli_stmt_execute($assignment_lock);
            $locked_assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($assignment_lock));
            mysqli_stmt_close($assignment_lock);
            if (!$locked_assignment) {
                throw new RuntimeException('You are no longer assigned as a panelist for this defense.');
            }

            $declined_check = mysqli_prepare(
                $conn,
                'SELECT 1 FROM panel_assignment_responses WHERE schedule_id = ? AND user_id = ?'
            );
            mysqli_stmt_bind_param($declined_check, 'ii', $schedule_id, $user_id);
            mysqli_stmt_execute($declined_check);
            $has_declined = mysqli_fetch_assoc(mysqli_stmt_get_result($declined_check));
            mysqli_stmt_close($declined_check);
            if ($has_declined) {
                throw new RuntimeException('You declined this defense date and time and cannot evaluate this group.');
            }

            $schedule_lock = mysqli_prepare(
                $conn,
                'SELECT ds.final_result, ds.finalized_at, ds.scheduled_date,
                        ts.start_time, ts.end_time
                 FROM defense_schedules ds
                 JOIN time_slots ts ON ts.slot_id = ds.slot_id
                 WHERE ds.schedule_id = ?
                 FOR UPDATE'
            );
            mysqli_stmt_bind_param($schedule_lock, 'i', $schedule_id);
            mysqli_stmt_execute($schedule_lock);
            $locked_schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($schedule_lock));
            mysqli_stmt_close($schedule_lock);
            if (!$locked_schedule) {
                throw new RuntimeException('The defense schedule is no longer available.');
            }
            $locked_window = getDefenseEvaluationWindowStatus(
                $locked_schedule['scheduled_date'],
                $locked_schedule['start_time'],
                $locked_schedule['end_time']
            );
            if ($locked_window !== 'open') {
                throw new RuntimeException($locked_window === 'not_open'
                    ? 'The evaluation form opens at the scheduled defense start time.'
                    : 'The evaluation window is closed because the scheduled defense time has ended.');
            }
            if ($locked_schedule['finalized_at'] !== null || $locked_schedule['final_result'] !== null) {
                throw new RuntimeException('All panel evaluations are complete; the result is locked pending Admin confirmation.');
            }
            $score_value = $score_raw;
            $save = mysqli_prepare(
                $conn,
                "INSERT INTO evaluations (schedule_id, evaluator_id, total_score, decision, comments)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE total_score = VALUES(total_score), decision = VALUES(decision),
                     comments = VALUES(comments), submitted_at = CURRENT_TIMESTAMP"
            );
            mysqli_stmt_bind_param($save, 'iisss', $schedule_id, $user_id, $score_value, $decision, $comments);
            mysqli_stmt_execute($save);
            mysqli_stmt_close($save);

            $panel_summary = getPanelEvaluationSummary($conn, $schedule_id);
            if (
                $panel_summary['panel_count'] === 3 && $panel_summary['chair_count'] === 1 &&
                $panel_summary['member_count'] === 2 && $panel_summary['completed_count'] === 3 &&
                in_array($panel_summary['chair_decision'], ['Defended', 'Redefense'], true) &&
                $panel_summary['average_score'] !== null
            ) {
                $final_score = round($panel_summary['average_score'], 2);
                $final_result = $panel_summary['chair_decision'];
                $grade_result = getEvaluationGradeResult($final_score);
                $calculate_result = mysqli_prepare(
                    $conn,
                    'UPDATE defense_schedules
                     SET final_score = ?, final_result = ?, grade_result = ?
                     WHERE schedule_id = ? AND finalized_at IS NULL AND final_result IS NULL'
                );
                mysqli_stmt_bind_param($calculate_result, 'dssi', $final_score, $final_result, $grade_result, $schedule_id);
                mysqli_stmt_execute($calculate_result);
                $result_saved = mysqli_stmt_affected_rows($calculate_result) === 1;
                mysqli_stmt_close($calculate_result);
                if ($result_saved) {
                    $project_status = $panel_summary['chair_decision'] === 'Defended'
                        ? ($schedule['defense_type'] === 'Proposal' ? 'In Progress' : 'Completed')
                        : 'In Progress';
                    $update_project = mysqli_prepare(
                        $conn,
                        "UPDATE projects
                         SET status = ?
                         WHERE project_id = ? AND status NOT IN ('Completed', 'Dropped')"
                    );
                    mysqli_stmt_bind_param($update_project, 'si', $project_status, $schedule['project_id']);
                    mysqli_stmt_execute($update_project);
                    mysqli_stmt_close($update_project);
                }
            }
            mysqli_commit($conn);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            if (!$exception instanceof RuntimeException) {
                error_log('Panel evaluation save failed: ' . $exception->getMessage());
                $error = 'Could not save your evaluation. Please try again.';
            } else {
                $error = $exception->getMessage();
            }
        }
        if ($error === '') {
            header('Location: results.php?schedule_id=' . $schedule_id);
            exit();
        }
    }
    $evaluation = ['total_score' => $score_raw, 'decision' => $decision, 'comments' => $comments];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defense Evaluation</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-3">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Evaluate Defense</h1>
    <p><?= htmlspecialchars($schedule['title'], ENT_QUOTES, 'UTF-8') ?> —
        <?= htmlspecialchars($schedule['defense_type'], ENT_QUOTES, 'UTF-8') ?>,
        <?= htmlspecialchars($schedule['scheduled_date'] . ' · ' . format_defense_countdown($schedule['scheduled_date']) . ' · ' . date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])), ENT_QUOTES, 'UTF-8') ?>,
        <?= htmlspecialchars($schedule['room_name'], ENT_QUOTES, 'UTF-8') ?>.
        Your role: <?= htmlspecialchars($schedule['panel_role'], ENT_QUOTES, 'UTF-8') ?>.</p>
    <p>Group members: <?= htmlspecialchars($schedule['group_members'] ?: 'Not listed', ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($error !== ''): ?><p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($schedule['final_result'] === null && $evaluation_window === 'open'): ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <label for="total_score">Total score (0–100):
                <input type="number" id="total_score" name="total_score" min="0" max="100" step="0.01" required value="<?= htmlspecialchars((string)($evaluation['total_score'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label><br>
            <label for="decision">Your decision:
                <select id="decision" name="decision" required>
                    <option value="">Choose a result</option>
                    <option value="Defended" <?= ($evaluation['decision'] ?? '') === 'Defended' ? 'selected' : '' ?>>Defended</option>
                    <option value="Redefense" <?= ($evaluation['decision'] ?? '') === 'Redefense' ? 'selected' : '' ?>>Redefense required</option>
                </select>
            </label>
            <label for="comments">Comments:<br>
                <textarea id="comments" name="comments" maxlength="1000"><?= htmlspecialchars((string)($evaluation['comments'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
            </label><br>
            <button type="submit">Save Evaluation</button>
        </form>
    <?php elseif ($schedule['final_result'] !== null || $schedule['finalized_at'] !== null): ?>
        <p class="sub-note"><?= $schedule['finalized_at'] !== null
                                ? 'This evaluation is locked because the final result has been finalized.'
                                : 'All panel evaluations are complete. The result is locked pending Admin confirmation.' ?></p>
    <?php elseif ($evaluation_window === 'not_open'): ?>
        <p class="sub-note">The evaluation form opens at the scheduled defense start time.</p>
    <?php else: ?>
        <p class="sub-note">The evaluation window is closed because the scheduled defense time has ended.</p>
    <?php endif; ?>
    <p><a class="button-secondary" href="results.php?schedule_id=<?= $schedule_id ?>">View results</a></p>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>