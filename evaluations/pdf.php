<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/bind_params.php';
require_once __DIR__ . '/../includes/simple_pdf.php';

auth_check(['Admin', 'Panel Member']);
$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role_name'] ?? '';
$is_admin = $role === 'Admin';
$selected_schedule_id = (int)($_GET['schedule_id'] ?? 0);
$error = '';

if ($is_admin) {
    $schedule_query = mysqli_query(
        $conn,
        "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date
         FROM defense_schedules ds
         JOIN projects p ON p.project_id = ds.project_id
         WHERE ds.status <> 'Cancelled'
         ORDER BY p.title, ds.scheduled_date DESC, ds.schedule_id DESC"
    );
    $available_schedules = mysqli_fetch_all($schedule_query, MYSQLI_ASSOC);
} else {
    $schedule_stmt = mysqli_prepare(
        $conn,
        "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date
         FROM panel_assignments pa
         JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
         JOIN projects p ON p.project_id = ds.project_id
         WHERE pa.user_id = ? AND pa.panel_role IN ('Chairperson', 'Panel Member')
           AND ds.status <> 'Cancelled'
           AND EXISTS (
               SELECT 1 FROM evaluations assigned_evaluation
               WHERE assigned_evaluation.schedule_id = ds.schedule_id
                 AND assigned_evaluation.evaluator_id = pa.user_id
                 AND assigned_evaluation.total_score IS NOT NULL
                 AND assigned_evaluation.decision IS NOT NULL
           )
         ORDER BY p.title, ds.scheduled_date DESC, ds.schedule_id DESC"
    );
    mysqli_stmt_bind_param($schedule_stmt, 'i', $user_id);
    mysqli_stmt_execute($schedule_stmt);
    $available_schedules = mysqli_fetch_all(mysqli_stmt_get_result($schedule_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($schedule_stmt);
}

if (isset($_GET['download']) || isset($_GET['preview'])) {
    $schedule_stmt = mysqli_prepare(
        $conn,
        "SELECT ds.schedule_id, ds.project_id, p.title, ds.defense_type, ds.scheduled_date,
                ts.start_time, ts.end_time, r.room_name, adviser.full_name AS adviser_name
         FROM defense_schedules ds
         JOIN projects p ON p.project_id = ds.project_id
         JOIN users adviser ON adviser.user_id = p.adviser_id
         JOIN time_slots ts ON ts.slot_id = ds.slot_id
         JOIN rooms r ON r.room_id = ds.room_id
         WHERE ds.schedule_id = ? AND ds.status <> 'Cancelled'
           AND (? = 1 OR EXISTS (
               SELECT 1 FROM panel_assignments access_pa
               WHERE access_pa.schedule_id = ds.schedule_id AND access_pa.user_id = ?
                 AND access_pa.panel_role IN ('Chairperson', 'Panel Member')
           ))"
    );
    $admin_flag = $is_admin ? 1 : 0;
    mysqli_stmt_bind_param($schedule_stmt, 'iii', $selected_schedule_id, $admin_flag, $user_id);
    mysqli_stmt_execute($schedule_stmt);
    $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($schedule_stmt));
    mysqli_stmt_close($schedule_stmt);

    if (!$schedule) {
        http_response_code(404);
        exit('The selected defense is unavailable or you are not assigned to it.');
    }

    $members_stmt = mysqli_prepare(
        $conn,
        "SELECT member_user.full_name
         FROM project_members pm
         JOIN students s ON s.student_id = pm.student_id
         JOIN users member_user ON member_user.user_id = s.user_id
         WHERE pm.project_id = ?
         ORDER BY FIELD(pm.role_in_group, 'Leader'), member_user.full_name"
    );
    mysqli_stmt_bind_param($members_stmt, 'i', $schedule['project_id']);
    mysqli_stmt_execute($members_stmt);
    $group_members = mysqli_fetch_all(mysqli_stmt_get_result($members_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($members_stmt);

    if ($is_admin) {
        $panel_stmt = mysqli_prepare(
            $conn,
            "SELECT pa.panel_role, u.full_name, e.total_score, e.decision, e.comments
             FROM panel_assignments pa
             JOIN users u ON u.user_id = pa.user_id
             LEFT JOIN evaluations e ON e.schedule_id = pa.schedule_id AND e.evaluator_id = pa.user_id
             WHERE pa.schedule_id = ? AND pa.panel_role IN ('Chairperson', 'Panel Member')
             ORDER BY CASE WHEN pa.panel_role = 'Chairperson' THEN 0 ELSE 1 END, u.full_name"
        );
        mysqli_stmt_bind_param($panel_stmt, 'i', $selected_schedule_id);
    } else {
        $panel_stmt = mysqli_prepare(
            $conn,
            "SELECT pa.panel_role, u.full_name, e.total_score, e.decision, e.comments
             FROM panel_assignments pa
             JOIN users u ON u.user_id = pa.user_id
             LEFT JOIN evaluations e ON e.schedule_id = pa.schedule_id AND e.evaluator_id = pa.user_id
             WHERE pa.schedule_id = ? AND pa.user_id = ?
               AND pa.panel_role IN ('Chairperson', 'Panel Member')"
        );
        mysqli_stmt_bind_param($panel_stmt, 'ii', $selected_schedule_id, $user_id);
    }
    mysqli_stmt_execute($panel_stmt);
    $panel_evaluations = mysqli_fetch_all(mysqli_stmt_get_result($panel_stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($panel_stmt);

    $submitted_count = 0;
    foreach ($panel_evaluations as $panel_evaluation) {
        if ($panel_evaluation['total_score'] !== null && $panel_evaluation['decision'] !== null) {
            $submitted_count++;
        }
    }
    if (!$panel_evaluations) {
        http_response_code(403);
        exit('No eligible panel evaluation is available for this defense.');
    }
    if ($is_admin && $submitted_count !== count($panel_evaluations)) {
        $error = 'The Admin report is available after every assigned panelist has submitted a score and decision.';
    } elseif (!$is_admin && $submitted_count !== 1) {
        $error = 'Your evaluation report is available after you submit your score and decision.';
    } else {
        $pdf = new SimplePdfDocument(__DIR__ . '/../assets/logo/logo.jpg');
        $pdf->addPage();
        $draw_page_header = static function (SimplePdfDocument $pdf, int $page_number) use ($schedule): float {
            $pdf->logo(90, 32, 32, 32);
            $pdf->text(143, 49, 'Computer Arts and Technological College, Inc.', 11.5, true, [0.02, 0.30, 0.72]);
            $pdf->text(185, 66, 'Rizal Street, Binanuahan West, Legazpi City', 8.5);
            $title_lines = explode("\n", wordwrap($schedule['title'], 72, "\n", true));
            $title_y = 132.0;
            foreach ($title_lines as $title_line) {
                $pdf->text(48, $title_y, $title_line, 13, true);
                $title_y += 16;
            }
            $pdf->text(
                48,
                $title_y + 2,
                $schedule['defense_type'] . ' Defense | ' .
                    date('F j, Y', strtotime($schedule['scheduled_date'])) . ' | ' .
                    date('g:i A', strtotime($schedule['start_time'])) . ' - ' .
                    date('g:i A', strtotime($schedule['end_time'])) . ' | ' . $schedule['room_name'],
                9
            );
            $pdf->text(520, 815, 'Page ' . $page_number, 8);
            return $title_y + 28;
        };
        $page_number = 1;
        $y = $draw_page_header($pdf, $page_number);

        $draw_wrapped = static function (
            SimplePdfDocument $pdf,
            string $text,
            float $x,
            float &$y,
            int $max_chars,
            float $font_size = 10,
            bool $bold = false
        ) use (&$page_number, $draw_page_header): void {
            $lines = explode("\n", wordwrap($text, $max_chars, "\n", true));
            foreach ($lines as $line) {
                if ($y > 760) {
                    $pdf->addPage();
                    $page_number++;
                    $y = $draw_page_header($pdf, $page_number);
                }
                $pdf->text($x, $y, $line, $font_size, $bold);
                $y += $font_size + 5;
            }
        };

        $pdf->text(48, $y, 'Group members', 10, true);
        $y += 17;
        if ($group_members) {
            foreach ($group_members as $member) {
                $draw_wrapped($pdf, '- ' . $member['full_name'], 58, $y, 82);
            }
        } else {
            $draw_wrapped($pdf, 'No group members listed.', 58, $y, 82);
        }
        if ($is_admin) {
            $y += 5;
            $pdf->text(48, $y, 'Adviser:', 10, true);
            $y += 15;
            $draw_wrapped($pdf, $schedule['adviser_name'], 58, $y, 82);
            $y += 7;
        } else {
            $y += 7;
        }

        foreach ($panel_evaluations as $panel_evaluation) {
            if ($y > 690) {
                $pdf->addPage();
                $page_number++;
                $y = $draw_page_header($pdf, $page_number);
            }
            $pdf->line(48, $y, 547, $y, 0.5);
            $y += 19;
            $heading = $is_admin
                ? $panel_evaluation['panel_role'] . ' — ' . $panel_evaluation['full_name']
                : $panel_evaluation['full_name'];
            $draw_wrapped($pdf, $heading, 48, $y, 88, 11, true);
            $draw_wrapped($pdf, 'Score: ' . $panel_evaluation['total_score'] . ' / 100', 48, $y, 88);
            $draw_wrapped($pdf, 'Decision: ' . $panel_evaluation['decision'], 48, $y, 88);
            $draw_wrapped($pdf, 'Comments:', 48, $y, 88, 10, true);
            $comments = trim((string)($panel_evaluation['comments'] ?? ''));
            $draw_wrapped($pdf, $comments !== '' ? $comments : 'No comments provided.', 58, $y, 82, 9);

            if ($y > 745) {
                $pdf->addPage();
                $page_number++;
                $y = $draw_page_header($pdf, $page_number);
            }
            $y += 12;
            $pdf->line(98, $y, 320, $y, 0.7);
            $y += 16;
            $draw_wrapped($pdf, $panel_evaluation['full_name'], 98, $y, 60, 9);
            $y += 12;
        }

        $content = $pdf->build();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($content));
        $disposition = isset($_GET['preview']) ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="evaluation-report-' . $selected_schedule_id . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        echo $content;
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Evaluation PDF</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1>Generate Evaluation PDF</h1>
    <?php if ($error !== ''): ?><p class="form-error-summary" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <p><?= $is_admin
        ? 'Select a defense to create a general report. All assigned panelists must have submitted their scores and decisions.'
        : 'Select one of your assigned defenses. The PDF contains only your own submitted evaluation.' ?></p>
    <?php if ($available_schedules): ?>
        <form method="get" class="content-card">
            <label for="schedule_id">Group and defense</label>
            <select id="schedule_id" name="schedule_id" required>
                <option value="">Choose a group</option>
                <?php foreach ($available_schedules as $available): ?>
                    <option value="<?= (int)$available['schedule_id'] ?>" <?= $selected_schedule_id === (int)$available['schedule_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($available['title'] . ' — ' . $available['defense_type'] . ' Defense — ' . date('M j, Y', strtotime($available['scheduled_date'])), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Select defense</button>
            <?php if ($selected_schedule_id > 0): ?>
                <button type="submit" name="preview" value="1" formtarget="_blank">Preview PDF</button>
                <button type="submit" name="download" value="1">Download PDF</button>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <p class="sub-note"><?= $is_admin ? 'There are no defense schedules available.' : 'No defenses have been assigned to you as a panelist.' ?></p>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>
