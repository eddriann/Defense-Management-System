<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/refresh_defense_status.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/manuscript_progress.php';
auth_check(['Student']);
refresh_defense_day_status($conn);

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Student';

$stmt = mysqli_prepare(
    $conn,
    "SELECT s.student_id, s.student_number, s.program, s.year_level, s.adviser_id,
            adviser.full_name AS adviser_name
     FROM students s
     JOIN users adviser ON adviser.user_id = s.adviser_id
     WHERE s.user_id = ?"
);
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$projects = [];
$upcoming_defenses = [];
$stats = ['projects' => 0, 'requests' => 0, 'defenses' => 0];
$can_submit_project = false;
$has_requestable_project = false;
$requires_manuscript_approval = false;
if ($student) {
    $student_id = (int)$student['student_id'];
    $_SESSION['student_id'] = $student_id;
    $_SESSION['student_number'] = $student['student_number'];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT p.project_id, p.title, p.project_type, p.status, p.updated_at,
                p.adviser_id, adviser.full_name AS adviser_name, pm.role_in_group,
                (SELECT m.status FROM manuscripts m WHERE m.project_id = p.project_id
                 ORDER BY m.submitted_at DESC, m.manuscript_id DESC LIMIT 1) AS latest_manuscript_status,
                (SELECT COUNT(*) FROM manuscript_comments mc
                 WHERE mc.manuscript_id = (
                     SELECT latest_manuscript.manuscript_id
                     FROM manuscripts latest_manuscript
                     WHERE latest_manuscript.project_id = p.project_id
                     ORDER BY latest_manuscript.submitted_at DESC, latest_manuscript.manuscript_id DESC
                     LIMIT 1
                 )) AS manuscript_comment_count,
                latest_result.schedule_id AS latest_result_schedule_id,
                latest_result.defense_type AS latest_result_defense_type,
                latest_result.final_score AS latest_result_score,
                latest_result.grade_result AS latest_grade_result,
                latest_result.final_result AS latest_panel_verdict,
                latest_result.grade_approved_at AS latest_grade_approved_at,
                EXISTS (
                    SELECT 1 FROM defense_requests dr
                    WHERE dr.project_id = p.project_id
                      AND dr.status IN ('Pending', 'Endorsed')
                ) AS has_active_request,
                EXISTS (
                    SELECT 1 FROM defense_schedules ds
                    WHERE ds.project_id = p.project_id
                      AND ds.status IN ('Scheduled', 'Ongoing')
                ) AS has_active_schedule,
                EXISTS (
                    SELECT 1 FROM defense_schedules completed_final
                    WHERE completed_final.project_id = p.project_id
                      AND completed_final.defense_type = 'Final'
                      AND completed_final.status = 'Completed'
                      AND completed_final.final_result = 'Defended'
                ) AS has_completed_final
         FROM project_members pm
         JOIN projects p ON p.project_id = pm.project_id
         JOIN users adviser ON adviser.user_id = p.adviser_id
         LEFT JOIN (
             SELECT ds.project_id, ds.schedule_id, ds.defense_type, ds.final_score,
                    ds.grade_result, ds.final_result, ds.grade_approved_at
             FROM defense_schedules ds
             JOIN (
                 SELECT project_id, MAX(schedule_id) AS schedule_id
                 FROM defense_schedules
                 WHERE final_score IS NOT NULL
                 GROUP BY project_id
             ) latest ON latest.schedule_id = ds.schedule_id
         ) latest_result ON latest_result.project_id = p.project_id
         WHERE pm.student_id = ?
         ORDER BY p.updated_at DESC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $student_id);
    mysqli_stmt_execute($stmt);
    $projects = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $can_submit_project = !$projects;
    foreach ($projects as $project) {
        if (
            $project['role_in_group'] === 'Leader' &&
            !(bool)$project['has_active_request'] &&
            !(bool)$project['has_active_schedule'] &&
            !(bool)$project['has_completed_final']
        ) {
            if ($project['latest_manuscript_status'] === 'Approved') {
                $has_requestable_project = true;
            } else {
                $requires_manuscript_approval = true;
            }
        }
    }
    $_SESSION['can_submit_project'] = $can_submit_project ? 1 : 0;
    $stats['projects'] = count($projects);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM defense_requests dr
         JOIN project_members pm ON pm.project_id = dr.project_id
         WHERE pm.student_id = ? AND dr.status IN ('Pending', 'Endorsed')"
    );
    mysqli_stmt_bind_param($stmt, 'i', $student_id);
    mysqli_stmt_execute($stmt);
    $stats['requests'] = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT ds.schedule_id, p.title, ds.defense_type, ds.scheduled_date,
                ts.start_time, ts.end_time, r.room_name
         FROM defense_schedules ds
         JOIN projects p ON p.project_id = ds.project_id
         JOIN project_members pm ON pm.project_id = p.project_id
         JOIN time_slots ts ON ts.slot_id = ds.slot_id
         JOIN rooms r ON r.room_id = ds.room_id
         WHERE pm.student_id = ? AND ds.scheduled_date >= CURDATE()
               AND ds.status IN ('Scheduled', 'Ongoing')
         ORDER BY ds.scheduled_date, ts.start_time
         LIMIT 5"
    );
    mysqli_stmt_bind_param($stmt, 'i', $student_id);
    mysqli_stmt_execute($stmt);
    $upcoming_defenses = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    $stats['defenses'] = count($upcoming_defenses);
}

$initials = '';
foreach (preg_split('/\s+/', trim($user_name)) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials, 0, 2);
$student_flash_error = $_SESSION['student_flash_error'] ?? '';
unset($_SESSION['student_flash_error']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | Defense Management System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261115-2">
    <link rel="stylesheet" href="../assets/css/modules.css?v=<?= filemtime(__DIR__ . '/../assets/css/modules.css') ?>&amp;rev=style-20261115-2">
    <script src="../assets/js/dashboard.js" defer></script>
    <link rel="icon" type="image/png" href="../assets/logo/favicon.png?v=20261115">
</head>

<body>
    <div class="app-wrapper">
        <?php $active_nav = 'dashboard';
        require __DIR__ . '/../includes/dashboard_sidebar.php'; ?>
        <main class="main-content container-fluid">
            <header class="dashboard-header">
                <div class="header-actions">
                    <button type="button" class="mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><?= dashboard_icon('menu') ?></button>
                    <div>
                        <h1>Student Dashboard</h1>
                        <p class="welcome-text">Welcome back, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>. Check your project and defense progress.</p>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="header-user"><strong><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars($student['program'] ?? 'Student account', ENT_QUOTES, 'UTF-8') ?></span></div>
                    <div class="user-avatar" aria-hidden="true"><?= htmlspecialchars($initials ?: 'ST', ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </header>

            <?php if ($student_flash_error !== ''): ?>
                <p class="dashboard-alert" role="alert"><?= htmlspecialchars($student_flash_error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if (!$student): ?>
                <section class="content-card card" role="alert">
                    <h2>Student profile not found</h2>
                    <p class="sub-note">Your account does not have a student profile yet. Please contact an administrator to complete your registration.</p>
                </section>
            <?php else: ?>
                <section class="stats-row" aria-label="Student overview">
                    <article class="stat-card card">
                        <p class="stat-label">My Projects</p>
                        <h2 class="stat-number stat-blue"><?= number_format($stats['projects']) ?></h2>
                        <p class="stat-sub">Projects you belong to</p>
                    </article>
                    <article class="stat-card card">
                        <p class="stat-label">Pending Requests</p>
                        <h2 class="stat-number stat-red"><?= number_format($stats['requests']) ?></h2>
                        <p class="stat-sub">Awaiting adviser review</p>
                    </article>
                    <article class="stat-card card">
                        <p class="stat-label">Upcoming Defenses</p>
                        <h2 class="stat-number stat-green"><?= number_format($stats['defenses']) ?></h2>
                        <p class="stat-sub">Scheduled from today onward</p>
                    </article>
                    <article class="stat-card card">
                        <p class="stat-label">My Adviser</p>
                        <h2 class="stat-number stat-number-compact stat-cyan"><?= htmlspecialchars($student['adviser_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="stat-sub"><?= htmlspecialchars($student['program'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($student['student_number'], ENT_QUOTES, 'UTF-8') ?></p>
                    </article>
                </section>

                <section class="lower-row">
                    <article class="content-card card">
                        <h2>My Projects</h2>
                        <p class="sub-note">Project details, status, and adviser information.</p>
                        <div class="table-wrap">
                            <table class="defense-table">
                                <thead>
                                    <tr>
                                        <th>Project</th>
                                        <th>Type</th>
                                        <th>Your Role</th>
                                        <th>Adviser</th>
                                        <th>Status</th>
                                        <th>Manuscript</th>
                                        <th>Latest Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$projects): ?>
                                        <tr>
                                            <td class="empty-state" colspan="7">You are not part of a project yet.</td>
                                        </tr>
                                        <?php else: foreach ($projects as $project): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($project['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($project['project_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($project['role_in_group'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($project['adviser_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><span class="status-badge <?= $project['status'] === 'Completed' ? 'status-completed' : '' ?>"><?= htmlspecialchars($project['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td>
                                                    <?php render_latest_manuscript_progress($project['latest_manuscript_status']); ?>
                                                    <?php if ((int)$project['manuscript_comment_count'] > 0): ?><span class="course-tag"><?= (int)$project['manuscript_comment_count'] ?> page comment<?= (int)$project['manuscript_comment_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
                                                    <?php if ($project['latest_manuscript_status'] !== 'Approved'): ?>
                                                        <form class="manuscript-upload-form" action="../projects/manuscripts.php" method="post" enctype="multipart/form-data">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">
                                                            <label for="dashboard-manuscript-<?= (int)$project['project_id'] ?>">Choose PDF manuscript</label>
                                                            <input type="file" id="dashboard-manuscript-<?= (int)$project['project_id'] ?>" name="manuscript" accept="application/pdf,.pdf" required>
                                                            <button type="submit">Upload manuscript</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="course-tag">Manuscript approved; uploads are closed.</span>
                                                    <?php endif; ?>
                                                    <a class="button-secondary button-compact" href="../projects/manuscripts.php?project_id=<?= (int)$project['project_id'] ?>">View comments</a>
                                                </td>
                                                <td>
                                                    <?php if ($project['latest_result_schedule_id'] !== null): ?>
                                                        <?php if ($project['latest_grade_approved_at'] !== null): ?>
                                                            <?= htmlspecialchars($project['latest_grade_result'] ?? 'Pending', ENT_QUOTES, 'UTF-8') ?>
                                                            <span class="course-tag"><?= htmlspecialchars($project['latest_result_score'], ENT_QUOTES, 'UTF-8') ?>/100 · <?= htmlspecialchars($project['latest_panel_verdict'] ?? 'Verdict pending', ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php else: ?>
                                                            <span class="course-tag">Evaluation score awaiting adviser approval</span>
                                                        <?php endif; ?>
                                                        <a class="button-secondary button-compact" href="../evaluations/results.php?schedule_id=<?= (int)$project['latest_result_schedule_id'] ?>">View evaluation</a>
                                                    <?php else: ?>
                                                        <span class="course-tag">No completed evaluation yet</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                    <?php endforeach;
                                    endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                    <aside class="content-card card">
                        <h2>Upcoming Defenses</h2>
                        <p class="sub-note">Your next scheduled defense sessions.</p>
                        <div class="upcoming-defense-list">
                            <?php if (!$upcoming_defenses): ?>
                                <p class="sub-note">No upcoming defense has been scheduled for your projects.</p>
                                <?php else: foreach ($upcoming_defenses as $defense): ?>
                                    <div class="upcoming-defense-item">
                                        <?= dashboard_icon('schedule') ?>
                                        <span><?= htmlspecialchars($defense['defense_type'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($defense['title'], ENT_QUOTES, 'UTF-8') ?><span class="course-tag"><?= htmlspecialchars(date('M j, Y', strtotime($defense['scheduled_date'])) . ' · ' . format_defense_countdown($defense['scheduled_date']) . ' · ' . date('g:i A', strtotime($defense['start_time'])) . ' - ' . date('g:i A', strtotime($defense['end_time'])) . ' · ' . $defense['room_name'], ENT_QUOTES, 'UTF-8') ?></span></span>
                                    </div>
                            <?php endforeach;
                            endif; ?>
                        </div>
                        <?php if (!$has_requestable_project && $requires_manuscript_approval): ?>
                            <p class="sub-note">Your adviser must approve the latest manuscript before you can request a defense.</p>
                        <?php elseif (!$has_requestable_project && $projects): ?>
                            <p class="sub-note">A defense request is already pending or scheduled for your project. The next defense request is created after the panel result is confirmed.</p>
                        <?php endif; ?>
                    </aside>
                </section>
            <?php endif; ?>
            <p class="dashboard-footer">Thesis and Capstone Defense Management System</p>
        </main>
    </div>
</body>

</html>