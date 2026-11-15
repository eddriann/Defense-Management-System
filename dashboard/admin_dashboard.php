<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';
auth_check(['Admin']);
refresh_defense_day_status($conn);

$admin_name = $_SESSION['full_name'] ?? 'Administrator';

$stats = [];
$count_queries = [
    'users' => "SELECT COUNT(*) AS total FROM users WHERE status = 'active'",
    'projects' => "SELECT COUNT(*) AS total FROM projects WHERE status IN ('Proposed', 'In Progress', 'For Defense')",
    'requests' => "SELECT COUNT(*) AS total FROM defense_requests WHERE status = 'Endorsed'",
    'schedules' => "SELECT COUNT(*) AS total FROM defense_schedules WHERE scheduled_date >= CURDATE() AND status IN ('Scheduled', 'Ongoing')",
];
foreach ($count_queries as $key => $sql) {
    $count_result = mysqli_query($conn, $sql);
    $stats[$key] = (int)mysqli_fetch_assoc($count_result)['total'];
}
$schedule_result = mysqli_query(
    $conn,
    "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date,
            ts.start_time, ts.end_time, r.room_name,
            adviser.full_name AS adviser_name,
            (SELECT GROUP_CONCAT(CONCAT(panel_user.full_name, ' (', pa.panel_role, ')')
                                 ORDER BY FIELD(pa.panel_role, 'Chairperson', 'Panel Member', 'Adviser'), panel_user.full_name
                                 SEPARATOR ', ')
             FROM panel_assignments pa
             JOIN users panel_user ON panel_user.user_id = pa.user_id
             WHERE pa.schedule_id = ds.schedule_id) AS panel
     FROM defense_schedules ds
     JOIN projects p ON p.project_id = ds.project_id
     JOIN users adviser ON adviser.user_id = p.adviser_id
     JOIN time_slots ts ON ts.slot_id = ds.slot_id
     JOIN rooms r ON r.room_id = ds.room_id
     WHERE ds.scheduled_date >= CURDATE() AND ds.status IN ('Scheduled', 'Ongoing')
     ORDER BY ds.scheduled_date, ts.start_time
     LIMIT 8"
);
$upcoming_schedules = mysqli_fetch_all($schedule_result, MYSQLI_ASSOC);

$initials = '';
foreach (preg_split('/\s+/', trim($admin_name)) as $part) {
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
    <title>Admin Dashboard | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <div class="app-wrapper">
        <?php $active_nav = 'dashboard';
        require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
        <main class="main-content admin-dashboard container-fluid">
            <header class="dashboard-header">
                <div class="header-actions">
                    <button type="button" class="mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><?= dashboard_icon('menu') ?></button>
                    <div>
                        <h1>Admin Dashboard</h1>
                        <p class="welcome-text">Welcome back, <?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?>. Here is the system overview.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="header-user">
                        <strong><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></strong>
                        <span>Administrator</span>
                    </div>
                    <div class="user-avatar" aria-hidden="true"><?= htmlspecialchars($initials ?: 'AD', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </header>

            <section class="stats-row" aria-label="System overview">
                <article class="stat-card card">
                    <p class="stat-label">Active Users</p>
                    <h2 class="stat-number stat-blue"><?= number_format($stats['users']) ?></h2>
                    <p class="stat-sub">Active accounts in the system</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Active Projects</p>
                    <h2 class="stat-number stat-cyan"><?= number_format($stats['projects']) ?></h2>
                    <p class="stat-sub">Proposed, in progress, or for defense</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Endorsed Requests</p>
                    <h2 class="stat-number stat-red"><?= number_format($stats['requests']) ?></h2>
                    <p class="stat-sub">Ready for administrator review</p>
                </article>
                <article class="stat-card card">
                    <p class="stat-label">Upcoming Defenses</p>
                    <h2 class="stat-number stat-green"><?= number_format($stats['schedules']) ?></h2>
                    <p class="stat-sub">Scheduled from today onward</p>
                </article>
            </section>

            <section class="lower-row lower-row-single admin-dashboard-content">
                <article class="content-card card">
                    <h2>Upcoming Defenses</h2>
                    <p class="sub-note">The next scheduled defenses across all projects.</p>
                    <div class="table-wrap">
                        <table class="defense-table">
                            <thead>
                                <tr>
                                    <th>Project / Defense</th>
                                    <th>Date / Time</th>
                                    <th>Room</th>
                                    <th>Adviser</th>
                                    <th>Panel</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$upcoming_schedules): ?>
                                    <tr>
                                        <td class="empty-state" colspan="5">There are no upcoming defenses.</td>
                                    </tr>
                                    <?php else: foreach ($upcoming_schedules as $schedule): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($schedule['title'], ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars($schedule['defense_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= htmlspecialchars(date('M j, Y', strtotime($schedule['scheduled_date'])), ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars(format_defense_countdown($schedule['scheduled_date']), ENT_QUOTES, 'UTF-8') ?></span><span class="course-tag"><?= htmlspecialchars(date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><?= htmlspecialchars($schedule['room_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($schedule['adviser_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($schedule['panel'] ?: 'Not assigned', ENT_QUOTES, 'UTF-8') ?></td>
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