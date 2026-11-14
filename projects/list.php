<?php
include('../config/db.php');
include('../includes/auth_check.php');
require_once('../includes/refresh_defense_status.php');
require_once('../includes/roles.php');
require_once('../includes/bind_params.php');
require_once('../includes/csrf.php');
require_once('../includes/manuscript_progress.php');
$allowed_roles = ['Admin', 'Adviser', 'Student'];
auth_check($allowed_roles);
refresh_defense_day_status($conn);

$user_id = $_SESSION['user_id'] ?? 0;
$role_name = $_SESSION['role_name'] ?? '';
$student_id = $_SESSION['student_id'] ?? 0;
$student_id = (int)$student_id;
if ($role_name === 'Student') {
    $profile_stmt = mysqli_prepare($conn, 'SELECT student_id FROM students WHERE user_id = ?');
    mysqli_stmt_bind_param($profile_stmt, 'i', $user_id);
    mysqli_stmt_execute($profile_stmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($profile_stmt));
    mysqli_stmt_close($profile_stmt);
    $student_id = (int)($profile['student_id'] ?? 0);
}

$is_admin = ($role_name === 'Admin');
$is_adviser = ($role_name === 'Adviser');
$is_group_view = $is_admin || $is_adviser;
$search = request_filter_string($_GET, 'search');
$project_type_filter = $_GET['project_type'] ?? '';
$status_filter = $_GET['status'] ?? '';
if (!in_array($project_type_filter, ['', 'Thesis', 'Capstone'], true)) {
    $project_type_filter = '';
}
$valid_project_statuses = ['Proposed', 'In Progress', 'For Defense', 'Completed', 'Dropped'];
if (!in_array($status_filter, array_merge([''], $valid_project_statuses), true)) {
    $status_filter = '';
}
$filter_values = [];
$filter_types = '';

if ($is_group_view) {
    $sql =
        "SELECT
            p.project_id,
            p.title,
            p.project_type,
            p.status,
            leader_user.full_name AS leader,
            (
                SELECT GROUP_CONCAT(u_mem.full_name SEPARATOR ', ')
                FROM project_members pm_sub
                JOIN students s_sub ON pm_sub.student_id = s_sub.student_id
                JOIN users u_mem ON s_sub.user_id = u_mem.user_id
                WHERE pm_sub.project_id = p.project_id
            ) AS group_members,
            (SELECT m.status FROM manuscripts m WHERE m.project_id = p.project_id
             ORDER BY m.submitted_at DESC, m.manuscript_id DESC LIMIT 1) AS latest_manuscript_status,
            (SELECT m.manuscript_id FROM manuscripts m WHERE m.project_id = p.project_id
             ORDER BY m.submitted_at DESC, m.manuscript_id DESC LIMIT 1) AS latest_manuscript_id
        FROM projects p
        LEFT JOIN project_members pm_lead ON p.project_id = pm_lead.project_id AND pm_lead.role_in_group = 'Leader'
        LEFT JOIN students s_lead ON pm_lead.student_id = s_lead.student_id
        LEFT JOIN users leader_user ON s_lead.user_id = leader_user.user_id
        WHERE (? = 0 OR p.adviser_id = ?)
          AND (? = '' OR p.title LIKE CONCAT('%', ?, '%'))
          AND (? = '' OR p.project_type = ?)
          AND (? = '' OR p.status = ?)";
    $stmt = mysqli_prepare($conn, $sql);
    $adviser_filter = $is_adviser ? $user_id : 0;
    $filter_values = [
        $adviser_filter, $user_id, $search, $search,
        $project_type_filter, $project_type_filter, $status_filter, $status_filter,
    ];
    $filter_types = 'iissssss';
} else {
    //  Student view query
    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT
            projects.project_id,
            projects.title,
            projects.project_type,
            projects.status,
            projects.created_by,
            leader_user.full_name AS leader,
            leader_user.user_id AS leader_user_id,
            adviser_user.full_name AS adviser_name,
            latest_result.schedule_id AS latest_result_schedule_id,
            latest_result.defense_type AS latest_result_defense_type,
            latest_result.final_score AS latest_result_score,
            latest_result.grade_result AS latest_grade_result,
            latest_result.final_result AS latest_panel_verdict,
            latest_result.grade_approved_at AS latest_grade_approved_at,
            (SELECT m.status FROM manuscripts m WHERE m.project_id = projects.project_id
              ORDER BY m.submitted_at DESC, m.manuscript_id DESC LIMIT 1) AS latest_manuscript_status
            ,(SELECT COUNT(*) FROM manuscript_comments mc
              WHERE mc.manuscript_id = (
                  SELECT latest_manuscript.manuscript_id
                  FROM manuscripts latest_manuscript
                  WHERE latest_manuscript.project_id = projects.project_id
                  ORDER BY latest_manuscript.submitted_at DESC, latest_manuscript.manuscript_id DESC
                  LIMIT 1
              )) AS manuscript_comment_count
        FROM projects
        LEFT JOIN project_members ON projects.project_id = project_members.project_id
        LEFT JOIN project_members pm_lead ON projects.project_id = pm_lead.project_id AND pm_lead.role_in_group = 'Leader'
        LEFT JOIN students s_lead ON pm_lead.student_id = s_lead.student_id
        LEFT JOIN users AS leader_user ON s_lead.user_id = leader_user.user_id
        LEFT JOIN users AS adviser_user ON projects.adviser_id = adviser_user.user_id
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
        ) latest_result ON latest_result.project_id = projects.project_id
        WHERE (projects.created_by = ? OR project_members.student_id = ?)
          AND (? = '' OR projects.title LIKE CONCAT('%', ?, '%'))
          AND (? = '' OR projects.project_type = ?)
          AND (? = '' OR projects.status = ?)"
    );
    $filter_values = [
        $user_id, $student_id, $search, $search,
        $project_type_filter, $project_type_filter, $status_filter, $status_filter,
    ];
    $filter_types = 'iissssss';
}

bind_stmt_params($stmt, $filter_types, $filter_values);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$projects = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Projects List</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>&amp;rev=style-20261112-2">
    <link rel="stylesheet" href="../assets/css/modules.css?v=<?= filemtime(__DIR__ . '/../assets/css/modules.css') ?>&amp;rev=style-20261112-2">
    <script src="../assets/js/dashboard.js" defer></script>
</head>

<body>
    <?php require __DIR__ . '/../includes/module_page_start.php'; ?>
    <h1><?= $is_admin ? 'All Student Groups' : ($is_adviser ? 'My Advisee Groups' : 'My Projects'); ?></h1>
    <?php if (isset($_GET['created']) && $_GET['created'] === '1'): ?><p class="form-success" role="status">The student group and project were created successfully.</p><?php endif; ?>

    <form method="get" class="filter-form">
        <label for="search">Project title</label>
        <input type="search" name="search" id="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <label for="project_type">Type</label>
        <select name="project_type" id="project_type">
            <option value="">All types</option>
            <?php foreach (['Thesis', 'Capstone'] as $type): ?><option value="<?= $type ?>" <?= $project_type_filter === $type ? 'selected' : '' ?>><?= $type ?></option><?php endforeach; ?>
        </select>
        <label for="status">Status</label>
        <select name="status" id="status">
            <option value="">All statuses</option>
            <?php foreach ($valid_project_statuses as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= $status_filter === $status ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <a class="button-secondary" href="list.php">Clear</a>
    </form>

    <table border="1" cellpadding="8" cellspacing="0">
        <thead>
            <tr>
                <th>Thesis/Capstone Title</th>
                <th>Project Type</th>
                <th>Leader</th>
                <?php if ($is_group_view): ?>
                    <th>Members</th>
                    <th>Manuscript</th>
                <?php else: ?>
                    <th>Adviser Name</th>
                    <th>Latest Grade</th>
                    <th>Manuscript status</th>
                <?php endif; ?>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($projects)): ?>
                <tr>
                    <td colspan="<?= $is_group_view ? 7 : 8 ?>" style="text-align: center;">No records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($projects as $project): ?>
                    <tr>
                        <td><?= htmlspecialchars($project['title']) ?></td>
                        <td><?= htmlspecialchars($project['project_type']) ?></td>
                        <td><?= htmlspecialchars($project['leader'] ?? 'Unknown') ?></td>

                        <?php if ($is_group_view): ?>
                            <td><?= htmlspecialchars($project['group_members'] ?? 'None') ?></td>
                            <td>
                                <?= htmlspecialchars($project['latest_manuscript_status'] ?? 'Not submitted', ENT_QUOTES, 'UTF-8') ?>
                            </td>
                        <?php else: ?>
                            <td><?= htmlspecialchars($project['adviser_name'] ?? 'None Assigned') ?></td>
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
                            <td>
                                <?php render_latest_manuscript_progress($project['latest_manuscript_status']); ?>
                                <?php if ((int)$project['manuscript_comment_count'] > 0): ?>
                                    <span class="course-tag"><?= (int)$project['manuscript_comment_count'] ?> page comment<?= (int)$project['manuscript_comment_count'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                                <?php if ($project['latest_manuscript_status'] !== 'Approved'): ?>
                                    <form class="manuscript-upload-form" action="manuscripts.php" method="post" enctype="multipart/form-data">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="project_id" value="<?= (int)$project['project_id'] ?>">
                                        <label for="manuscript-<?= (int)$project['project_id'] ?>">Choose PDF manuscript</label>
                                        <input type="file" id="manuscript-<?= (int)$project['project_id'] ?>" name="manuscript" accept="application/pdf,.pdf" required>
                                        <button type="submit">Upload manuscript</button>
                                    </form>
                                <?php else: ?>
                                    <span class="course-tag">Manuscript approved; uploads are closed.</span>
                                <?php endif; ?>
                                <a class="row-action" href="manuscripts.php?project_id=<?= (int)$project['project_id'] ?>">View comments and submissions</a>
                            </td>
                        <?php endif; ?>

                        <td><?= htmlspecialchars($project['status']) ?></td>

                        <td>
                            <div class="table-actions">
                                <?php if ($is_admin): ?>
                                    <a class="row-action" href="edit.php?id=<?= (int)$project['project_id'] ?>">Manage</a>
                                    <a class="row-action" href="manuscripts.php?project_id=<?= (int)$project['project_id'] ?>">Submitted files</a>
                                <?php elseif ($is_adviser): ?>
                                    <a class="row-action" href="edit.php?id=<?= (int)$project['project_id'] ?>">Edit</a>
                                    <?php if ($project['latest_manuscript_id'] !== null): ?>
                                        <a class="row-action" href="manuscript_file.php?manuscript_id=<?= (int)$project['latest_manuscript_id'] ?>&amp;inline=1" target="_blank" rel="noopener">View file</a>
                                        <a class="row-action" href="review_manuscript.php?manuscript_id=<?= (int)$project['latest_manuscript_id'] ?>">Review</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ((int)$project['created_by'] === (int)$user_id || (int)($project['leader_user_id'] ?? 0) === (int)$user_id): ?>
                                        <a class="row-action" href="edit.php?id=<?= (int)$project['project_id'] ?>">Edit</a>
                                    <?php endif; ?>
                                    <?php if ((int)$project['created_by'] === (int)$user_id || (int)($project['leader_user_id'] ?? 0) === (int)$user_id): ?>
                                        <a class="row-action" href="members.php?project_id=<?= $project['project_id'] ?>">Members</a>
                                    <?php endif; ?>
                                    <?php if ((int)($project['leader_user_id'] ?? 0) === (int)$user_id && $project['latest_manuscript_status'] === 'Approved'): ?>
                                        <a class="row-action" href="../defense_request/submit.php?project_id=<?= $project['project_id'] ?>">Request defense</a>
                                    <?php elseif ((int)($project['leader_user_id'] ?? 0) === (int)$user_id): ?>
                                        <span class="course-tag">Approve the manuscript before requesting a defense.</span>
                                    <?php elseif ((int)$project['created_by'] !== (int)$user_id): ?>
                                        <em>Member View</em>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ($is_admin || $is_adviser): ?>
        <p><a class="button-secondary" href="../dashboard/<?= $is_admin ? 'admin_dashboard.php' : 'adviser_dashboard.php' ?>">Dashboard</a></p>
    <?php endif; ?>

    <?php require __DIR__ . '/../includes/module_page_end.php'; ?>
</body>

</html>