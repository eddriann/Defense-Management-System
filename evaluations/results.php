<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
require_once('../includes/availability.php');
require_once('../includes/panel_assignment.php');
require_once('../includes/refresh_defense_status.php');
auth_check(['Admin', 'Adviser', 'Panel Member', 'Student']);
refresh_defense_day_status($conn);
$user_id = (int)$_SESSION['user_id'];
$schedule_id = (int)($_GET['schedule_id'] ?? $_POST['schedule_id'] ?? 0);
$active_role = $_SESSION['role_name'] ?? '';
$is_admin = $active_role === 'Admin';
$is_adviser = $active_role === 'Adviser';
$is_student = $active_role === 'Student';
$is_panel_workspace = $active_role === 'Panel Member';

function findAutomaticFollowUpSlot(
    mysqli $conn,
    int $project_id,
    int $adviser_id,
    string $defense_type,
    string $from,
    string $through,
    int $created_by
): ?array {
    $slots = mysqli_fetch_all(
        mysqli_query($conn, 'SELECT slot_id FROM time_slots ORDER BY start_time'),
        MYSQLI_ASSOC
    );
    $rooms = getActiveRooms($conn);
    $tomorrow = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
    $start = new DateTimeImmutable(max($from, $tomorrow));
    $end = new DateTimeImmutable($through);

    for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
        $date_string = $date->format('Y-m-d');
        $booked_rooms = getBookedRooms($conn, $date_string, $date_string);

        foreach ($slots as $slot) {
            $slot_id = (int)$slot['slot_id'];
            $student_conflict = mysqli_prepare(
                $conn,
                "SELECT 1
                  FROM defense_schedules ds
                 JOIN projects old_project ON old_project.project_id = ds.project_id
                 WHERE ds.scheduled_date = ? AND ds.slot_id = ?
                   AND ds.status IN ('Scheduled', 'Ongoing')
                   AND (
                       ds.project_id = ?
                       OR EXISTS (
                           SELECT 1
                           FROM project_members current_pm
                           JOIN project_members old_pm ON old_pm.student_id = current_pm.student_id
                           WHERE current_pm.project_id = ?
                             AND old_pm.project_id = old_project.project_id
                       )
                   )
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($student_conflict, 'siii', $date_string, $slot_id, $project_id, $project_id);
            mysqli_stmt_execute($student_conflict);
            $has_student_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($student_conflict));
            mysqli_stmt_close($student_conflict);
            if ($has_student_conflict) {
                continue;
            }

            foreach (freeRooms($rooms, $booked_rooms, $date_string, $slot_id) as $room) {
                $room_id = (int)$room['room_id'];
                $schedule_status = 'Scheduled';
                $probe = mysqli_prepare(
                    $conn,
                    'INSERT INTO defense_schedules
                        (project_id, defense_type, scheduled_date, slot_id, room_id, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                mysqli_stmt_bind_param(
                    $probe,
                    'issiisi',
                    $project_id,
                    $defense_type,
                    $date_string,
                    $slot_id,
                    $room_id,
                    $schedule_status,
                    $created_by
                );
                mysqli_stmt_execute($probe);
                $probe_schedule_id = mysqli_insert_id($conn);
                mysqli_stmt_close($probe);

                try {
                    automatically_assign_panel($conn, $probe_schedule_id);
                } catch (RuntimeException $exception) {
                    $delete_probe = mysqli_prepare($conn, 'DELETE FROM defense_schedules WHERE schedule_id = ?');
                    mysqli_stmt_bind_param($delete_probe, 'i', $probe_schedule_id);
                    mysqli_stmt_execute($delete_probe);
                    mysqli_stmt_close($delete_probe);

                    if (!in_array($exception->getMessage(), [
                        'There are not enough available panel members for this date and room.',
                        'The project adviser has another defense at the same time.',
                    ], true)) {
                        throw $exception;
                    }
                    continue;
                }

                $delete_probe = mysqli_prepare($conn, 'DELETE FROM defense_schedules WHERE schedule_id = ?');
                mysqli_stmt_bind_param($delete_probe, 'i', $probe_schedule_id);
                mysqli_stmt_execute($delete_probe);
                mysqli_stmt_close($delete_probe);
                return [
                    'date' => $date_string,
                    'slot_id' => $slot_id,
                    'room_id' => $room_id,
                ];
            }
        }
    }

    return null;
}

$message = '';
$error = '';
$created_follow_up_schedule_id = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    csrf_validate_request();
    $recommendations = trim($_POST['recommendations'] ?? '');

    if (strlen($recommendations) > 1000) {
        $error = 'Recommendations cannot exceed 1000 characters.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $lock = mysqli_prepare(
                $conn,
                "SELECT ds.project_id, ds.defense_type, ds.scheduled_date, ds.status,
                       ds.final_score, ds.final_result, ds.grade_result, ds.finalized_at, p.adviser_id
                FROM defense_schedules ds
                 JOIN projects p ON p.project_id = ds.project_id
                 WHERE ds.schedule_id = ?
                 FOR UPDATE"
            );
            mysqli_stmt_bind_param($lock, 'i', $schedule_id);
            mysqli_stmt_execute($lock);
            $locked_schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);
            if (!$locked_schedule || $locked_schedule['status'] === 'Cancelled') {
                throw new RuntimeException('This defense schedule cannot be finalized.');
            }
            if ($locked_schedule['finalized_at'] !== null) {
                throw new RuntimeException('This defense result has already been finalized.');
            }
            if ($locked_schedule['scheduled_date'] > date('Y-m-d')) {
                throw new RuntimeException('A defense result can only be finalized after its scheduled date.');
            }

            $panel_summary = getPanelEvaluationSummary($conn, $schedule_id);
            if (
                $panel_summary['panel_count'] !== 3 || $panel_summary['chair_count'] !== 1 ||
                $panel_summary['member_count'] !== 2 || $panel_summary['completed_count'] !== 3 ||
                !in_array($panel_summary['chair_decision'], ['Defended', 'Redefense'], true) ||
                $panel_summary['average_score'] === null
            ) {
                throw new RuntimeException('The final result is available after the Chairperson and both panel members submit their evaluations.');
            }
            $result = $panel_summary['chair_decision'];
            $score = round($panel_summary['average_score'], 2);
            $grade_result = getEvaluationGradeResult($score);
            if (
                $locked_schedule['final_result'] !== null &&
                ($locked_schedule['final_result'] !== $result ||
                    abs((float)$locked_schedule['final_score'] - $score) >= 0.005 ||
                    ($locked_schedule['grade_result'] !== null &&
                        $locked_schedule['grade_result'] !== $grade_result))
            ) {
                throw new RuntimeException('The recorded panel result no longer matches its evaluations.');
            }

            $follow_up_type = null;
            $days_after = null;
            $window_days = null;
            if ($result === 'Redefense') {
                $follow_up_type = $locked_schedule['defense_type'];
                $days_after = 14;
                $window_days = $locked_schedule['defense_type'] === 'Proposal' ? 14 : 21;
            } elseif ($locked_schedule['defense_type'] === 'Proposal') {
                $follow_up_type = 'Final';
                $days_after = 30;
                $window_days = 45;
            }

            $follow_up_slot = null;
            if ($follow_up_type !== null) {
                $existing_follow_up = mysqli_prepare(
                    $conn,
                    "SELECT 1
                     FROM defense_requests
                     WHERE project_id = ? AND status IN ('Pending', 'Endorsed')
                     UNION ALL
                     SELECT 1
                     FROM defense_schedules
                     WHERE project_id = ? AND schedule_id <> ? AND status IN ('Scheduled', 'Ongoing')
                     LIMIT 1"
                );
                mysqli_stmt_bind_param(
                    $existing_follow_up,
                    'iii',
                    $locked_schedule['project_id'],
                    $locked_schedule['project_id'],
                    $schedule_id
                );
                mysqli_stmt_execute($existing_follow_up);
                $has_existing_follow_up = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_follow_up));
                mysqli_stmt_close($existing_follow_up);
                if ($has_existing_follow_up) {
                    throw new RuntimeException('This project already has a pending request or active defense schedule.');
                }
                $window_start = (new DateTimeImmutable($locked_schedule['scheduled_date']))
                    ->modify('+' . $days_after . ' days')
                    ->format('Y-m-d');
                $window_end = (new DateTimeImmutable($locked_schedule['scheduled_date']))
                    ->modify('+' . $window_days . ' days')
                    ->format('Y-m-d');
                if (
                    $result === 'Redefense' &&
                    $locked_schedule['defense_type'] === 'Proposal' &&
                    $window_start <= date('Y-m-d')
                ) {
                    throw new RuntimeException(
                        'The exact two-week Redefense date has passed, so this Proposal cannot be automatically scheduled for that date.'
                    );
                }
                $follow_up_slot = findAutomaticFollowUpSlot(
                    $conn,
                    (int)$locked_schedule['project_id'],
                    (int)$locked_schedule['adviser_id'],
                    $follow_up_type,
                    $window_start,
                    $window_end,
                    $user_id
                );
                if (!$follow_up_slot) {
                    throw new RuntimeException(
                        'No conflict-free room, time slot, and panel are available on the required follow-up date or within the follow-up window. The result remains pending Admin confirmation.'
                    );
                }
            }

            $complete = mysqli_prepare(
                $conn,
                "UPDATE defense_schedules
                 SET status = 'Completed', final_score = ?, final_result = ?, grade_result = ?, recommendations = ?,
                     finalized_by = ?, finalized_at = CURRENT_TIMESTAMP
                 WHERE schedule_id = ? AND finalized_at IS NULL
                   AND (final_result IS NULL OR final_result = ?)"
            );
            mysqli_stmt_bind_param(
                $complete,
                'dsssiis',
                $score,
                $result,
                $grade_result,
                $recommendations,
                $user_id,
                $schedule_id,
                $result
            );
            mysqli_stmt_execute($complete);
            if (mysqli_stmt_affected_rows($complete) !== 1) {
                mysqli_stmt_close($complete);
                throw new RuntimeException('This defense result was finalized by another administrator.');
            }
            mysqli_stmt_close($complete);

            if ($follow_up_slot !== null) {
                $room_check = mysqli_prepare(
                    $conn,
                    "SELECT r.room_id, r.room_name
                     FROM rooms r
                     WHERE r.room_id = ? AND r.status = 'Active'
                       AND (
                           SELECT COUNT(*)
                           FROM defense_schedules daily
                           WHERE daily.room_id = r.room_id
                             AND daily.scheduled_date = ?
                             AND daily.status <> 'Cancelled'
                       ) < 4
                       AND NOT EXISTS (
                           SELECT 1
                           FROM defense_schedules booked
                           WHERE booked.room_id = r.room_id
                             AND booked.scheduled_date = ?
                             AND booked.slot_id = ?
                             AND booked.status <> 'Cancelled'
                       )
                       AND NOT EXISTS (
                           SELECT 1
                           FROM room_unavailability unavailable
                           WHERE unavailable.room_id = r.room_id
                             AND unavailable.unavailable_date = ?
                             AND (unavailable.slot_id IS NULL OR unavailable.slot_id = ?)
                       )
                     FOR UPDATE"
                );
                mysqli_stmt_bind_param(
                    $room_check,
                    'issisi',
                    $follow_up_slot['room_id'],
                    $follow_up_slot['date'],
                    $follow_up_slot['date'],
                    $follow_up_slot['slot_id'],
                    $follow_up_slot['date'],
                    $follow_up_slot['slot_id']
                );
                mysqli_stmt_execute($room_check);
                $room_available = mysqli_fetch_assoc(mysqli_stmt_get_result($room_check));
                mysqli_stmt_close($room_check);
                if (!$room_available) {
                    throw new RuntimeException('The automatically selected room is no longer available. The result remains pending Admin confirmation.');
                }

                $schedule_status = 'Scheduled';
                $create_schedule = mysqli_prepare(
                    $conn,
                    "INSERT INTO defense_schedules
                        (project_id, defense_type, scheduled_date, slot_id, room_id, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $create_schedule,
                    'issiisi',
                    $locked_schedule['project_id'],
                    $follow_up_type,
                    $follow_up_slot['date'],
                    $follow_up_slot['slot_id'],
                    $follow_up_slot['room_id'],
                    $schedule_status,
                    $user_id
                );
                mysqli_stmt_execute($create_schedule);
                $new_schedule_id = mysqli_insert_id($conn);
                mysqli_stmt_close($create_schedule);
                automatically_assign_panel($conn, $new_schedule_id);
                $created_follow_up_schedule_id = $new_schedule_id;

                $project_status = 'For Defense';
                $update_project = mysqli_prepare($conn, 'UPDATE projects SET status = ? WHERE project_id = ?');
                mysqli_stmt_bind_param($update_project, 'si', $project_status, $locked_schedule['project_id']);
                mysqli_stmt_execute($update_project);
                mysqli_stmt_close($update_project);
                refresh_defense_day_status($conn);
                $message = sprintf(
                    'Result saved. The %s defense was automatically scheduled for %s in %s (schedule #%d), with a panel assigned.',
                    $follow_up_type,
                    date('F j, Y', strtotime($follow_up_slot['date'])),
                    $room_available['room_name'],
                    $new_schedule_id
                );
            } else {
                $update_project = mysqli_prepare(
                    $conn,
                    "UPDATE projects SET status = 'Completed' WHERE project_id = ?"
                );
                mysqli_stmt_bind_param($update_project, 'i', $locked_schedule['project_id']);
                mysqli_stmt_execute($update_project);
                mysqli_stmt_close($update_project);
                $message = 'Final defense result saved and the project marked completed.';
            }

            mysqli_commit($conn);
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            $error = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Could not finalize the defense result or automatically schedule its follow-up defense.';
            if (!$exception instanceof RuntimeException) {
                error_log('Defense result finalization failed: ' . $exception->getMessage());
            }
        }
    }
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, ds.project_id, ds.status, ds.final_score, ds.final_result, ds.grade_result, ds.recommendations,
            ds.scheduled_date, ds.finalized_at, ds.grade_approved_by, ds.grade_approved_at,
            p.title, p.adviser_id, ds.defense_type, approver.full_name AS grade_approver_name
     FROM defense_schedules ds
     INNER JOIN projects p ON p.project_id = ds.project_id
     LEFT JOIN users approver ON approver.user_id = ds.grade_approved_by
     WHERE ds.schedule_id = ?
       AND (? = 1 OR EXISTS (
           SELECT 1 FROM panel_assignments pa
           WHERE pa.schedule_id = ds.schedule_id AND pa.user_id = ?
       ) OR EXISTS (
           SELECT 1 FROM projects p2
           WHERE p2.project_id = ds.project_id AND p2.adviser_id = ?
       ) OR EXISTS (
           SELECT 1 FROM project_members pm
           INNER JOIN students s ON s.student_id = pm.student_id
           WHERE pm.project_id = ds.project_id AND s.user_id = ?
       ))"
);
$admin_flag = $is_admin ? 1 : 0;
mysqli_stmt_bind_param($stmt, 'iiiii', $schedule_id, $admin_flag, $user_id, $user_id, $user_id);
mysqli_stmt_execute($stmt);
$schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$schedule) {
    http_response_code(404);
    exit('Evaluation results not found.');
}

$panel_summary = getPanelEvaluationSummary($conn, $schedule_id);
$is_assigned_adviser = $is_adviser && (int)$schedule['adviser_id'] === $user_id;
$grade_release_ready =
    $schedule['status'] !== 'Cancelled' &&
    $panel_summary['panel_count'] === 3 &&
    $panel_summary['chair_count'] === 1 &&
    $panel_summary['member_count'] === 2 &&
    $panel_summary['completed_count'] === 3 &&
    $panel_summary['average_score'] !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_grade_release'])) {
    csrf_validate_request();
    if (!$is_assigned_adviser) {
        http_response_code(403);
        exit('Only the project adviser can approve grade release.');
    }
    if (!$grade_release_ready) {
        $error = 'Grade release can be approved after all three panel members submit their scores and decisions.';
    } elseif ($schedule['grade_approved_at'] !== null) {
        $message = 'Grade release has already been approved.';
    } else {
        $approve = mysqli_prepare(
            $conn,
            'UPDATE defense_schedules
             SET grade_approved_by = ?, grade_approved_at = CURRENT_TIMESTAMP
             WHERE schedule_id = ? AND grade_approved_at IS NULL AND status <> \'Cancelled\''
        );
        mysqli_stmt_bind_param($approve, 'ii', $user_id, $schedule_id);
        mysqli_stmt_execute($approve);
        $approved = mysqli_stmt_affected_rows($approve) === 1;
        mysqli_stmt_close($approve);
        if (!$approved) {
            $error = 'Grade release could not be approved. Refresh the page and check the defense status.';
        } else {
            $schedule['grade_approved_by'] = $user_id;
            $schedule['grade_approved_at'] = date('Y-m-d H:i:s');
            $schedule['grade_approver_name'] = $_SESSION['full_name'] ?? 'Project adviser';
            $message = 'Grade release approved. Students can now view the evaluation scores.';
        }
    }
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT pa.user_id AS evaluator_id, pa.panel_role,
            CASE WHEN e.total_score IS NOT NULL AND e.decision IS NOT NULL THEN 1 ELSE 0 END AS evaluation_submitted,
            CASE WHEN e.total_score IS NOT NULL AND e.decision IS NOT NULL
                       AND (? = 0 OR pa.user_id = ?) THEN e.total_score END AS total_score,
            CASE WHEN e.total_score IS NOT NULL AND e.decision IS NOT NULL
                       AND (? = 0 OR pa.user_id = ?) THEN e.decision END AS decision,
            CASE WHEN ? = 1 OR e.total_score IS NULL OR e.decision IS NULL
                       OR (? = 1 AND pa.user_id <> ?) THEN NULL ELSE e.comments END AS comments,
            CASE WHEN e.total_score IS NOT NULL AND e.decision IS NOT NULL THEN e.submitted_at END AS submitted_at,
            u.full_name AS evaluator
     FROM panel_assignments pa
     INNER JOIN users u ON u.user_id = pa.user_id
     LEFT JOIN evaluations e ON e.schedule_id = pa.schedule_id AND e.evaluator_id = pa.user_id
     WHERE pa.schedule_id = ? AND pa.panel_role IN ('Chairperson', 'Panel Member')
     ORDER BY CASE WHEN pa.panel_role = 'Chairperson' THEN 0 ELSE 1 END, u.full_name"
);
$panel_flag = $is_panel_workspace ? 1 : 0;
$hide_comments = $is_student ? 1 : 0;
mysqli_stmt_bind_param(
    $stmt,
    'iiiiiiii',
    $panel_flag,
    $user_id,
    $panel_flag,
    $user_id,
    $hide_comments,
    $panel_flag,
    $user_id,
    $schedule_id
);
mysqli_stmt_execute($stmt);
$evaluations = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
$grade_result = null;
if (
    $panel_summary['panel_count'] === 3 && $panel_summary['completed_count'] === 3 &&
    $panel_summary['average_score'] !== null
) {
    $display_score = round($panel_summary['average_score'], 2);
    $grade_result = getEvaluationGradeResult($display_score);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Results</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Evaluation Results</h1>
    <p><?= htmlspecialchars($schedule['title'] . ' — ' . $schedule['defense_type'], ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($is_admin || $is_panel_workspace): ?><p><a class="button-secondary button-compact" href="pdf.php?schedule_id=<?= $schedule_id ?>">Generate evaluation PDF</a></p><?php endif; ?>
    <p>Defense date: <?= htmlspecialchars(date('M j, Y', strtotime($schedule['scheduled_date'])) . ' · ' . format_defense_countdown($schedule['scheduled_date']), ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($message !== ''): ?><p class="form-success" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if ($created_follow_up_schedule_id !== null): ?><p><a class="button-primary" href="../schedules/list.php">View scheduled follow-up defense</a></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Panel role</th>
                <th>Evaluator</th>
                <th>Decision</th>
                <th>Score</th><?php if (!$is_student): ?><th>Comments</th><?php endif; ?><th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$evaluations): ?><tr>
                    <td colspan="<?= $is_student ? 5 : 6 ?>">No panel members are assigned to this defense.</td>
                </tr>
                <?php else: foreach ($evaluations as $evaluation): ?>
                    <tr>
                        <td><?= htmlspecialchars($evaluation['panel_role'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($evaluation['evaluator'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?php if (!$evaluation['evaluation_submitted']): ?>Not submitted<?php elseif ($is_panel_workspace && (int)$evaluation['evaluator_id'] !== $user_id): ?>Hidden<?php else: ?><?= htmlspecialchars($evaluation['decision'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></td>
                        <td><?php if (!$evaluation['evaluation_submitted']): ?>—<?php elseif ($is_panel_workspace && (int)$evaluation['evaluator_id'] !== $user_id): ?>Hidden<?php elseif ($is_student && $schedule['grade_approved_at'] === null): ?>Awaiting adviser approval<?php else: ?><?= htmlspecialchars((string)$evaluation['total_score'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></td>
                        <?php if (!$is_student): ?><td><?= nl2br(htmlspecialchars($evaluation['comments'] ?? '', ENT_QUOTES, 'UTF-8')) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars($evaluation['submitted_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
            <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <p>Final score:
        <?php if ($is_panel_workspace): ?>
            Hidden from panel members
        <?php elseif ($is_student && $schedule['grade_approved_at'] === null): ?>
            <?= $grade_release_ready ? 'Awaiting adviser approval' : 'Not finalized' ?>
        <?php elseif ($is_student && $schedule['grade_approved_at'] !== null && $panel_summary['average_score'] !== null): ?>
            <?= htmlspecialchars(number_format($panel_summary['average_score'], 2), ENT_QUOTES, 'UTF-8') ?>
        <?php else: ?>
            <?= htmlspecialchars((string)($schedule['final_score'] ?? 'Not finalized'), ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </p>
    <p>Grade result:
        <?php if ($is_panel_workspace): ?>
            Hidden from panel members
        <?php elseif ($is_student && $schedule['grade_approved_at'] === null): ?>
            <?= $grade_release_ready ? 'Awaiting adviser approval' : 'Not finalized' ?>
        <?php elseif ($is_student && $schedule['grade_approved_at'] !== null): ?>
            <?= htmlspecialchars($grade_result ?? 'Not finalized', ENT_QUOTES, 'UTF-8') ?>
        <?php else: ?>
            <?= htmlspecialchars((string)($schedule['grade_result'] ?? 'Not finalized'), ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </p>
    <p>Panel verdict:
        <?= $is_panel_workspace
            ? 'Hidden from panel members'
            : htmlspecialchars((string)($schedule['final_result'] ?? 'Not finalized'), ENT_QUOTES, 'UTF-8') ?>
    </p>
    <?php if (!empty($schedule['recommendations'])): ?><p>Recommendations: <?= nl2br(htmlspecialchars($schedule['recommendations'], ENT_QUOTES, 'UTF-8')) ?></p><?php endif; ?>
    <?php if ($schedule['final_result'] === null): ?>
        <p>Panel evaluations submitted: <?= $panel_summary['completed_count'] ?> of 3. The grade result uses the average of all three panel scores; the Chairperson’s decision determines the Defended or Redefense verdict.</p>
    <?php elseif ($schedule['finalized_at'] === null): ?>
        <p class="sub-note">Panel evaluations are complete. The grade result is based on the average of the three panel scores; the Chairperson’s verdict still determines whether the defense is Defended or Redefense. Admin confirmation is pending before automatic follow-up scheduling.</p>
    <?php endif; ?>
    <?php if ($schedule['grade_approved_at'] !== null): ?>
        <p class="sub-note">Grade release approved by <?= htmlspecialchars($schedule['grade_approver_name'] ?? 'the project adviser', ENT_QUOTES, 'UTF-8') ?> on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($schedule['grade_approved_at'])), ENT_QUOTES, 'UTF-8') ?>.</p>
    <?php elseif ($is_assigned_adviser && $grade_release_ready): ?>
        <h2>Release evaluation scores to students</h2>
        <p>The three panel evaluations are complete. Approving will let the project students view the panel scores and grade result.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <button type="submit" name="approve_grade_release" value="1">Approve grade release</button>
        </form>
    <?php elseif ($is_assigned_adviser && $schedule['status'] !== 'Cancelled'): ?>
        <p class="sub-note">Grade release becomes available after all three panel members submit their scores and decisions.</p>
    <?php endif; ?>
    <?php if (
        $is_admin && in_array($schedule['final_result'], ['Defended', 'Redefense'], true) &&
        $schedule['finalized_at'] === null && $schedule['scheduled_date'] <= date('Y-m-d') && $schedule['status'] !== 'Cancelled' &&
        $panel_summary['panel_count'] === 3 && $panel_summary['chair_count'] === 1 &&
        $panel_summary['member_count'] === 2 && $panel_summary['completed_count'] === 3
    ): ?>
        <h2>Finalize defense result</h2>
        <p>The final score is <?= htmlspecialchars(number_format($panel_summary['average_score'], 2), ENT_QUOTES, 'UTF-8') ?>. The grade outcome is <?= htmlspecialchars($grade_result, ENT_QUOTES, 'UTF-8') ?>; the Chairperson’s verdict is <?= htmlspecialchars($panel_summary['chair_decision'], ENT_QUOTES, 'UTF-8') ?>. Confirming a failed Proposal defense automatically schedules a Redefense exactly 14 days later, based on room and panel availability. Other Redefenses are scheduled 2–3 weeks later, and a Final Defense is scheduled 30–45 days after a defended Proposal.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="schedule_id" value="<?= $schedule_id ?>">
            <label for="recommendations">Recommendations
                <textarea id="recommendations" name="recommendations" maxlength="1000"></textarea>
            </label>
            <button type="submit">Confirm result and schedule follow-up</button>
        </form>
    <?php elseif ($is_admin && $schedule['final_result'] === null && $schedule['status'] !== 'Cancelled'): ?>
        <p class="sub-note">The final result can be confirmed after the defense date and after all three panel members have submitted evaluations.</p>
    <?php elseif ($schedule['finalized_at'] !== null): ?>
        <p class="sub-note">Finalized <?= htmlspecialchars(date('M j, Y g:i A', strtotime($schedule['finalized_at'])), ENT_QUOTES, 'UTF-8') ?>. Follow-up defenses are listed in Defense Schedules.</p>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>