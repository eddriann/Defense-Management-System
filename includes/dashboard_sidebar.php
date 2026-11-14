<?php
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dashboard_icons.php';

$dashboard_nav = [
    'Admin' => [
        ['dashboard', 'dashboard', 'Dashboard', '../dashboard/admin_dashboard.php'],
        ['users', 'users', 'User Management', '../users/list.php'],
        ['add-user', 'user-plus', 'Add User', '../users/add.php'],
        ['projects', 'project', 'Student Groups', '../projects/list.php'],
        ['requests', 'request', 'Review Requests', '../defense_request/review.php'],
        ['schedules', 'schedule', 'Defense Schedules', '../schedules/list.php'],
        ['panels', 'panels', 'Panel Assignments', '../panels/list.php'],
        ['rooms', 'rooms', 'Rooms', '../rooms/list.php'],
        ['room-availability', 'rooms', 'Room Availability', '../rooms/availability.php'],
        ['status-report', 'report', 'Project Status Report', '../reports/status_report.php'],
        ['schedule-report', 'schedule', 'Schedule Report', '../reports/schedule_report.php'],
        ['panel-report', 'users', 'Panel Report', '../reports/panel_report.php'],
        ['evaluation-report', 'evaluation', 'Evaluation Report', '../reports/evaluation_report.php'],
    ],
    'Adviser' => [
        ['dashboard', 'dashboard', 'Dashboard', '../dashboard/adviser_dashboard.php'],
        ['projects', 'project', 'Advised Projects', '../projects/list.php'],
        ['review', 'request', 'Endorse Requests', '../defense_request/review.php'],
        ['requests', 'request', 'Defense Requests', '../defense_request/list.php'],
        ['schedules', 'schedule', 'Defense Schedules', '../schedules/list.php'],
        ['account', 'users', 'Account Settings', '../users/account.php'],
    ],
    'Panel Member' => [
        ['dashboard', 'dashboard', 'Dashboard', '../dashboard/panel_dashboard.php'],
        ['schedules', 'schedule', 'Assigned Defenses', '../schedules/list.php'],
        ['account', 'users', 'Account Settings', '../users/account.php'],
    ],
    'Student' => [
        ['dashboard', 'dashboard', 'Dashboard', '../dashboard/student_dashboard.php'],
        ['projects', 'project', 'My Projects', '../projects/list.php'],
        ['add-project', 'user-plus', 'Add Project', '../projects/add.php'],
        ['requests', 'request', 'Defense Requests', '../defense_request/list.php'],
        ['schedules', 'schedule', 'Defense Schedules', '../schedules/list.php'],
        ['account', 'users', 'Account Settings', '../users/account.php'],
    ],
];
$current_role = $_SESSION['role_name'] ?? '';
$current_nav = $dashboard_nav[$current_role] ?? [];
?>
<aside class="sidebar d-flex flex-column" id="app-sidebar">
    <div class="sidebar-header">
        <img src="../assets/logo/logo.jpg" alt="" class="sidebar-logo">
        <h2 class="sidebar-title">Defense Management System</h2>
    </div>
    <nav class="sidebar-nav nav flex-column" aria-label="Main navigation">
        <ul class="nav flex-column">
            <?php foreach ($current_nav as [$key, $icon, $label, $href]): ?>
                <li class="nav-item <?= ($active_nav ?? '') === $key ? 'active' : '' ?>">
                    <a class="nav-link" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" <?= ($active_nav ?? '') === $key ? 'aria-current="page"' : '' ?>>
                        <?= dashboard_icon($icon) ?><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="sidebar-bottom">
            <?php if (count($_SESSION['roles'] ?? []) > 1): ?>
                <div class="role-switch">
                    <span class="role-switch-label">Switch workspace</span>
                    <?php foreach ($_SESSION['roles'] as $switch_role): if ($switch_role === $current_role) {
                            continue;
                        } ?>
                        <form method="post" action="../auth/switch_role.php">
                            <?= csrf_field() ?>
                            <button class="role-switch-button" type="submit" name="role" value="<?= htmlspecialchars($switch_role, ENT_QUOTES, 'UTF-8') ?>">
                                <?= dashboard_icon('switch') ?><?= htmlspecialchars($switch_role, ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <a href="../auth/logout.php" class="logout-link"><?= dashboard_icon('logout') ?>Log Out</a>
        </div>
    </nav>
</aside>