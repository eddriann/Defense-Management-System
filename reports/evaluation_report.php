<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/bind_params.php');
auth_check(['Admin']);
$search = request_filter_string($_GET, 'search');
$type_filter = $_GET['type'] ?? '';
$result_filter = $_GET['result'] ?? '';
$from_date = request_filter_string($_GET, 'from');
$to_date = request_filter_string($_GET, 'to');
if (!in_array($type_filter, ['', 'Proposal', 'Final'], true)) {
    $type_filter = '';
}
if (!in_array($result_filter, ['', 'Passed', 'Revisions Needed', 'Failed'], true)) {
    $result_filter = '';
}
foreach (['from_date', 'to_date'] as $date_filter) {
    if ($$date_filter !== '' && !is_valid_filter_date($$date_filter)) {
        $$date_filter = '';
    }
}
$stmt = mysqli_prepare(
    $conn,
    "SELECT p.title, ds.defense_type, ds.scheduled_date, ds.final_result, ds.grade_result,
            u.full_name AS evaluator, e.total_score, e.decision, e.comments, e.submitted_at
     FROM evaluations e
     INNER JOIN defense_schedules ds ON ds.schedule_id = e.schedule_id
     INNER JOIN projects p ON p.project_id = ds.project_id
     INNER JOIN users u ON u.user_id = e.evaluator_id
     INNER JOIN panel_assignments pa
       ON pa.schedule_id = e.schedule_id AND pa.user_id = e.evaluator_id
      AND pa.panel_role IN ('Chairperson', 'Panel Member')
     WHERE (? = '' OR p.title LIKE CONCAT('%', ?, '%')
            OR u.full_name LIKE CONCAT('%', ?, '%'))
       AND (? = '' OR ds.defense_type = ?)
       AND (? = '' OR ds.grade_result = ?)
       AND (? = '' OR ds.scheduled_date >= ?)
       AND (? = '' OR ds.scheduled_date <= ?)
     ORDER BY ds.scheduled_date DESC, p.title, u.full_name"
);
bind_stmt_params(
    $stmt,
    'sssssssssss',
    [
        $search, $search, $search, $type_filter, $type_filter, $result_filter, $result_filter,
        $from_date, $from_date, $to_date, $to_date,
    ]
);
mysqli_stmt_execute($stmt);
$rows = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Report</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body><?php require __DIR__ . '/../includes/module_page_start.php'; ?><h1>Evaluation Report</h1>
    <p><a class="button-primary" href="../evaluations/pdf.php">Generate evaluation PDF</a></p>
    <form method="get" class="filter-form">
        <label for="search">Project or evaluator</label><input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="type">Defense type</label><select name="type" id="type"><option value="">All types</option><?php foreach (['Proposal', 'Final'] as $type): ?><option value="<?= $type ?>" <?= $type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?></select>
        <label for="result">Grade result</label><select name="result" id="result"><option value="">All outcomes</option><?php foreach (['Passed', 'Revisions Needed', 'Failed'] as $outcome): ?><option value="<?= htmlspecialchars($outcome, ENT_QUOTES, 'UTF-8') ?>" <?= $result_filter === $outcome ? 'selected' : '' ?>><?= htmlspecialchars($outcome, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
        <label for="from">From</label><input type="date" name="from" id="from" value="<?= htmlspecialchars($from_date, ENT_QUOTES, 'UTF-8') ?>">
        <label for="to">To</label><input type="date" name="to" id="to" value="<?= htmlspecialchars($to_date, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Filter</button><a class="button-secondary" href="evaluation_report.php">Clear</a>
    </form>
    <table border="1" cellpadding="6">
        <thead>
            <tr>
                <th>Project</th>
                <th>Defense</th>
                <th>Date</th>
                <th>Evaluator</th>
                <th>Decision</th>
                <th>Score</th>
                <th>Grade Result</th>
                <th>Panel Verdict</th>
                <th>Comments</th>
                <th>Submitted</th>
            </tr>
        </thead>
        <tbody>
            <?php $any = false;
            while ($row = mysqli_fetch_assoc($rows)): $any = true; ?><tr>
                    <td><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['defense_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['scheduled_date'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['evaluator'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['decision'] ?? 'Not submitted', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars((string)$row['total_score'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['grade_result'] ?? 'Not finalized', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($row['final_result'] ?? 'Not finalized', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= nl2br(htmlspecialchars($row['comments'] ?? '', ENT_QUOTES, 'UTF-8')) ?></td>
                    <td><?= htmlspecialchars($row['submitted_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr><?php endwhile;
                    if (!$any): ?><tr>
                    <td colspan="10">No evaluations submitted.</td>
                </tr><?php endif; ?>
        </tbody>
    </table><?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>