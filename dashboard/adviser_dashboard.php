<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';
auth_check(['Adviser']);
refresh_defense_day_status($conn);

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Adviser';

$count_sql = [
    'projects' => "SELECT COUNT(*) AS total FROM projects WHERE adviser_id = ?",
    'requests' => "SELECT COUNT(*) AS total FROM defense_requests dr JOIN projects p ON p.project_id = dr.project_id WHERE p.adviser_id = ? AND dr.status = 'Pending'",
    'defenses' => "SELECT COUNT(*) AS total FROM defense_schedules ds JOIN projects p ON p.project_id = ds.project_id WHERE p.adviser_id = ? AND ds.scheduled_date >= CURDATE() AND ds.status IN ('Scheduled', 'Ongoing')",
    'panel' => "SELECT COUNT(*) AS total FROM panel_assignments WHERE user_id = ?",
];
$stats = [];
foreach ($count_sql as $key => $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $stats[$key] = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
    mysqli_stmt_close($stmt);
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT p.project_id, p.title, p.project_type, p.status, p.updated_at,
            (SELECT GROUP_CONCAT(leader.full_name ORDER BY leader.full_name SEPARATOR ', ')
             FROM project_members pm
             JOIN students s ON s.student_id = pm.student_id
             JOIN users leader ON leader.user_id = s.user_id
             WHERE pm.project_id = p.project_id AND pm.role_in_group = 'Leader') AS group_leaders,
            next_defense.scheduled_date AS next_defense_date
     FROM projects p
     LEFT JOIN (
         SELECT project_id, MIN(scheduled_date) AS scheduled_date
         FROM defense_schedules
         WHERE scheduled_date >= CURDATE()
           AND status IN ('Scheduled', 'Ongoing')
         GROUP BY project_id
     ) next_defense ON next_defense.project_id = p.project_id
     WHERE p.adviser_id = ?
     ORDER BY p.updated_at DESC
     LIMIT 8"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$projects = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$initials = '';
foreach (preg_split('/\s+/', trim($user_name)) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials, 0, 2);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adviser Dashboard | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=2&amp;rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <div class="app-wrapper">
        <?php $active_nav = 'dashboard';
        require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
        <main class="main-content adviser-dashboard container-fluid">
            <header class="dashboard-header">
                <div class="header-actions">
                    <button type="button" class="mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><?= dashboard_icon('menu') ?></button>
                    <div>
                        <h1>Adviser Dashboard</h1>
                        <p class="welcome-text">Welcome back, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>. Here is your advising overview.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="header-user"><strong><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></strong><span>Adviser</span></div>
                    <div class="user-avatar" aria-hidden="true"><?= htmlspecialchars($initials ?: 'AD', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </header>

            <section class="stats-row" aria-label="Adviser overview">
                <article class="stat-card card">
                    <p class="stat-label">Advised Projects</p>
                    <h2 class="stat-number stat-blue"><?= number_format($stats['projects']) ?></h2>
                    <p class="stat-sub">Projects assigned to you</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Pending Requests</p>
                    <h2 class="stat-number stat-red"><?= number_format($stats['requests']) ?></h2>
                    <p class="stat-sub">Awaiting your review</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Upcoming Defenses</p>
                    <h2 class="stat-number stat-green"><?= number_format($stats['defenses']) ?></h2>
                    <p class="stat-sub">For your advised projects</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Panel Assignments</p>
                    <h2 class="stat-number stat-cyan"><?= number_format($stats['panel']) ?></h2>
                    <p class="stat-sub">Defenses where you are assigned</p>
                </article>
            </section>

            <section class="lower-row lower-row-single">
                <article class="content-card card">
                    <h2>Advisee Projects</h2>
                    <p class="sub-note">Recent project activity and current status.</p>
                    <div class="table-wrap">
                        <table class="defense-table">
                            <thead>
                                <tr>
                                    <th>Project</th>
                                    <th>Type</th>
                                    <th>Group Leader(s)</th>
                                    <th>Status</th>
                                    <th>Next Defense</th>
                                    <th>Updated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$projects): ?>
                                    <tr>
                                        <td class="empty-state" colspan="6">No projects have been assigned to you.</td>
                                    </tr>
                                    <?php else: foreach ($projects as $project): ?>
                                        <tr>
                                            <td><a href="../projects/manuscripts.php?project_id=<?= (int)$project['project_id'] ?>"><?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                            <td><?= htmlspecialchars($project['project_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($project['group_leaders'] ?: 'Not set', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="status-badge <?= $project['status'] === 'Completed' ? 'status-completed' : '' ?>"><?= htmlspecialchars($project['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= $project['next_defense_date'] !== null
                                                    ? htmlspecialchars(date('M j, Y', strtotime($project['next_defense_date'])) . ' · ' . format_defense_countdown($project['next_defense_date']), ENT_QUOTES, 'UTF-8')
                                                    : 'No upcoming defense' ?></td>
                                            <td><?= htmlspecialchars(date('M j, Y', strtotime($project['updated_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
            <p class="dashboard-footer">Thesis and Capstone Defense Management System</p>
        </main>
    </div>
</body>

</html>