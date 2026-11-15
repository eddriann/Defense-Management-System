<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';
require_once __DIR__ . '/../includes/evaluation_window.php';
auth_check(['Panel Member', 'Adviser']);
refresh_defense_day_status($conn);

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role_name'] ?? '';
if ($user_role === 'Adviser') {
    $panel_access = mysqli_prepare($conn, 'SELECT 1 FROM panel_assignments WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($panel_access, 'i', $user_id);
    mysqli_stmt_execute($panel_access);
    $has_panel_assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($panel_access));
    mysqli_stmt_close($panel_access);
    if (!$has_panel_assignment) {
        http_response_code(403);
        exit('You need an assigned panel role to access this page.');
    }
}
$user_name = $_SESSION['full_name'] ?? 'Panel Member';

$stmt = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total,
            SUM(ds.scheduled_date >= CURDATE() AND ds.status IN ('Scheduled', 'Ongoing')) AS upcoming,
            SUM(ds.status <> 'Cancelled' AND NOT EXISTS (
                SELECT 1 FROM evaluations e
                WHERE e.schedule_id = ds.schedule_id AND e.evaluator_id = pa.user_id
                  AND e.total_score IS NOT NULL AND e.decision IS NOT NULL
            )) AS pending_evaluations
     FROM panel_assignments pa
     JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
     WHERE pa.user_id = ? AND pa.panel_role IN ('Chairperson', 'Panel Member')"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
mysqli_stmt_close($stmt);
$stats = [
    'assigned' => (int)($stats['total'] ?? 0),
    'upcoming' => (int)($stats['upcoming'] ?? 0),
    'pending' => (int)($stats['pending_evaluations'] ?? 0),
];

$stmt = mysqli_prepare(
    $conn,
    "SELECT ds.schedule_id, p.project_id, p.title AS project_title, p.project_type,
            ds.defense_type, ds.scheduled_date, ts.start_time,
            ts.end_time, r.room_name, r.location, ds.status AS schedule_status,
            ds.final_result, ds.grade_result, ds.finalized_at,
            pa.panel_role,
            (SELECT m.manuscript_id FROM manuscripts m
             WHERE m.project_id = p.project_id AND m.status = 'Approved'
             ORDER BY m.submitted_at DESC, m.manuscript_id DESC LIMIT 1) AS approved_manuscript_id,
            (SELECT GROUP_CONCAT(member_user.full_name ORDER BY member_user.full_name SEPARATOR ', ')
             FROM project_members pm
             JOIN students s ON s.student_id = pm.student_id
             JOIN users member_user ON member_user.user_id = s.user_id
             WHERE pm.project_id = p.project_id) AS group_members,
            EXISTS (
                SELECT 1 FROM evaluations evaluation
                WHERE evaluation.schedule_id = ds.schedule_id
                  AND evaluation.evaluator_id = pa.user_id
                  AND evaluation.total_score IS NOT NULL
                  AND evaluation.decision IS NOT NULL
            ) AS evaluation_submitted,
            EXISTS (
                SELECT 1 FROM panel_assignment_responses response
                WHERE response.schedule_id = pa.schedule_id
                  AND response.user_id = pa.user_id
            ) AS decline_pending
     FROM panel_assignments pa
     JOIN defense_schedules ds ON ds.schedule_id = pa.schedule_id
     JOIN projects p ON p.project_id = ds.project_id
     JOIN time_slots ts ON ts.slot_id = ds.slot_id
     JOIN rooms r ON r.room_id = ds.room_id
     WHERE pa.user_id = ?
     ORDER BY ds.scheduled_date DESC, ts.start_time DESC, ds.schedule_id DESC
     LIMIT 20"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$assigned_defenses = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$initials = '';
foreach (preg_split('/\s+/', trim($user_name)) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials, 0, 2);
$flash_message = $_SESSION['panel_flash_message'] ?? '';
$flash_error = $_SESSION['panel_flash_error'] ?? '';
unset($_SESSION['panel_flash_message'], $_SESSION['panel_flash_error']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Member Dashboard | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261115-3">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <div class="app-wrapper">
        <?php $active_nav = 'dashboard';
        require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
        <main class="main-content panel-dashboard container-fluid">
            <header class="dashboard-header">
                <div class="header-actions">
                    <button type="button" class="mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><?= dashboard_icon('menu') ?></button>
                    <div>
                        <h1>Panel Member Dashboard</h1>
                        <p class="welcome-text">Welcome back, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>. Review your assigned defense sessions.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="header-user"><strong><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></strong><span>Panel Member</span></div>
                    <div class="user-avatar" aria-hidden="true"><?= htmlspecialchars($initials ?: 'PM', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </header>

            <?php if ($flash_message !== ''): ?><p class="dashboard-alert" role="status"><?= htmlspecialchars($flash_message, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($flash_error !== ''): ?><p class="dashboard-alert" role="alert"><?= htmlspecialchars($flash_error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>

            <section class="stats-row" aria-label="Panel member overview">
                <article class="stat-card card">
                    <p class="stat-label">Assigned Defenses</p>
                    <h2 class="stat-number stat-blue"><?= number_format($stats['assigned']) ?></h2>
                    <p class="stat-sub">All assigned sessions</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Upcoming Defenses</p>
                    <h2 class="stat-number stat-cyan"><?= number_format($stats['upcoming']) ?></h2>
                    <p class="stat-sub">Scheduled from today onward</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Pending Evaluations</p>
                    <h2 class="stat-number stat-red"><?= number_format($stats['pending']) ?></h2>
                    <p class="stat-sub">Evaluations not submitted</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Panel Role</p>
                    <h2 class="stat-number stat-number-compact stat-green"><?= $user_role === 'Adviser' ? 'Adviser / Panelist' : 'Panel Member' ?></h2>
                    <p class="stat-sub">Your assigned role is shown per defense</p>
                </article>
            </section>

            <section class="content-card card">
                <h2>Assigned Defense Sessions</h2>
                <p class="sub-note">Open an evaluation for each assigned defense. If you cannot attend a scheduled date and time, decline that assignment so an available replacement panelist can be found.</p>
                <?php if (in_array('Panel Member', $_SESSION['roles'] ?? [], true)): ?>
                    <p><a class="button-secondary button-compact" href="../evaluations/pdf.php">Generate my evaluation PDF</a></p>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="defense-table">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Project / Defense</th>
                                <th>Role</th>
                                <th>Students</th>
                                <th>Room</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$assigned_defenses): ?>
                                <tr>
                                    <td class="empty-state" colspan="7">No defense schedules have been assigned to you yet.</td>
                                </tr>
                                <?php else: foreach ($assigned_defenses as $defense): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($defense['scheduled_date'])), ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars(format_defense_countdown($defense['scheduled_date']), ENT_QUOTES, 'UTF-8') ?></span><span class="course-tag"><?= htmlspecialchars(date('g:i A', strtotime($defense['start_time'])) . ' - ' . date('g:i A', strtotime($defense['end_time'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <?= htmlspecialchars($defense['project_title'], ENT_QUOTES, 'UTF-8') ?>
                                            <span class="course-tag"><?= htmlspecialchars($defense['defense_type'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($defense['project_type'], ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($defense['approved_manuscript_id'] !== null && in_array($defense['panel_role'], ['Chairperson', 'Panel Member', 'Adviser'], true)): ?>
                                                <a class="button-secondary button-compact" href="../projects/manuscript_file.php?manuscript_id=<?= (int)$defense['approved_manuscript_id'] ?>&amp;schedule_id=<?= (int)$defense['schedule_id'] ?>&amp;inline=1" target="_blank" rel="noopener">View approved manuscript</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="panel-role-badge <?= $defense['panel_role'] === 'Chairperson' ? 'role-chair' : '' ?>"><?= htmlspecialchars($defense['panel_role'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars($defense['group_members'] ?: 'Not listed', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($defense['room_name'], ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars($defense['location'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><span class="status-badge <?= $defense['schedule_status'] === 'Completed' ? 'status-completed' : ($defense['schedule_status'] === 'Cancelled' ? 'status-cancelled' : '') ?>"><?= htmlspecialchars($defense['schedule_status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td>
                                            <?php $evaluation_window = getDefenseEvaluationWindowStatus($defense['scheduled_date'], $defense['start_time'], $defense['end_time']); ?>
                                            <?php if (in_array($defense['panel_role'], ['Chairperson', 'Panel Member'], true)): ?>
                                                <?php if ($defense['final_result'] === null): ?>
                                                    <?php if ($evaluation_window === 'open'): ?>
                                                        <?php if ($defense['decline_pending']): ?>
                                                            <span class="course-tag">Declined · awaiting Admin review</span>
                                                        <?php else: ?>
                                                            <a class="button-primary" href="../evaluations/score_form.php?schedule_id=<?= (int)$defense['schedule_id'] ?>"><?= dashboard_icon('evaluation') ?><?= $defense['evaluation_submitted'] ? 'Update evaluation' : 'Open evaluation form' ?></a>
                                                        <?php endif; ?>
                                                    <?php elseif ($evaluation_window === 'not_open'): ?>
                                                        <span class="course-tag">Evaluation opens at <?= htmlspecialchars(date('g:i A', strtotime($defense['start_time'])), ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php else: ?>
                                                        <span class="course-tag">Evaluation window closed</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="course-tag">Evaluation complete. Individual results are hidden from panel members.</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($defense['decline_pending']): ?><span class="course-tag">Decline sent · awaiting Admin review</span><?php endif; ?>
                                            <a class="button-secondary button-compact" href="../evaluations/results.php?schedule_id=<?= (int)$defense['schedule_id'] ?>">View results</a>
                                            <?php if (
                                                $evaluation_window === 'not_open' &&
                                                !$defense['decline_pending'] && !$defense['evaluation_submitted'] && $defense['panel_role'] !== 'Adviser' &&
                                                in_array($defense['schedule_status'], ['Scheduled', 'Ongoing'], true) &&
                                                ($defense['scheduled_date'] > date('Y-m-d') ||
                                                    ($defense['scheduled_date'] === date('Y-m-d') && strtotime($defense['scheduled_date'] . ' ' . $defense['start_time']) > time()))
                                            ): ?>
                                                <form method="post" action="../panels/respond.php" class="decline-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="schedule_id" value="<?= (int)$defense['schedule_id'] ?>">
                                                    <input type="text" name="reason" maxlength="500" placeholder="Reason (optional)" aria-label="Reason for declining this date and time">
                                                    <button class="button-secondary" type="submit">Decline this date and time</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <p class="dashboard-footer">Thesis and Capstone Defense Management System</p>
        </main>
    </div>
</body>

</html>