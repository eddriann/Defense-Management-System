<?php
$module_route = basename(dirname($_SERVER['SCRIPT_NAME'])) . '/' . basename($_SERVER['SCRIPT_NAME']);
$module_nav = [
    'users/list.php' => 'users',
    'users/add.php' => 'add-user',
    'users/edit.php' => 'users',
    'users/account.php' => 'account',
    'projects/list.php' => 'projects',
    'projects/add.php' => 'add-project',
    'projects/edit.php' => 'projects',
    'projects/members.php' => 'projects',
    'projects/manuscripts.php' => 'projects',
    'projects/review_manuscript.php' => 'projects',
    'defense_request/list.php' => 'requests',
    'defense_request/review.php' => 'review',
    'defense_request/submit.php' => 'requests',
    'schedules/list.php' => 'schedules',
    'schedules/add.php' => 'schedules',
    'schedules/edit.php' => 'schedules',
    'panels/list.php' => 'panels',
    'panels/assign.php' => 'panels',
    'rooms/list.php' => 'rooms',
    'rooms/availability.php' => 'room-availability',
    'rooms/add.php' => 'rooms',
    'rooms/edit.php' => 'rooms',
    'evaluations/results.php' => 'schedules',
    'evaluations/score_form.php' => 'schedules',
    'reports/status_report.php' => 'status-report',
    'reports/schedule_report.php' => 'schedule-report',
    'reports/panel_report.php' => 'panel-report',
    'reports/evaluation_report.php' => 'evaluation-report',
];
$active_nav = $module_nav[$module_route] ?? '';
if ($module_route === 'defense_request/review.php' && ($current_role ?? $_SESSION['role_name'] ?? '') === 'Admin') {
    $active_nav = 'requests';
}
?>
<div class="app-wrapper">
    <?php require __DIR__ . '/dashboard_sidebar.php'; ?>
    <main class="main-content module-content container-fluid">
        <button type="button" class="mobile-menu" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><?= dashboard_icon('menu') ?> <span>Menu</span></button>
