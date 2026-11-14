<?php

if (!defined('ROLE_PRIORITY')) {
    // Order used to pick the "active" role at login for users who hold several roles
    define('ROLE_PRIORITY', ['Admin', 'Adviser', 'Panel Member', 'Student']);
}

if (!function_exists('load_user_roles')) {
    // Returns every role name the user holds, sorted by ROLE_PRIORITY
    function load_user_roles(mysqli $conn, int $user_id): array
    {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT r.role_name
             FROM user_roles ur
             INNER JOIN roles r ON r.role_id = ur.role_id
             WHERE ur.user_id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        $roles = array_column($rows, 'role_name');
        usort($roles, function ($a, $b) {
            return array_search($a, ROLE_PRIORITY, true) <=> array_search($b, ROLE_PRIORITY, true);
        });
        return $roles;
    }
}

if (!function_exists('has_role')) {
    // True if the logged-in user holds this role (any of their roles, not only the active one)
    function has_role(string $role): bool
    {
        return in_array($role, $_SESSION['roles'] ?? [], true);
    }
}

if (!function_exists('dashboard_for')) {
    // Paths are relative to a sibling folder of /dashboard (auth/, users/, ...)
    function dashboard_for(string $role): string
    {
        switch ($role) {
            case 'Admin':
                return '../dashboard/admin_dashboard.php';
            case 'Adviser':
                return '../dashboard/adviser_dashboard.php';
            case 'Panel Member':
                return '../dashboard/panel_dashboard.php';
            case 'Student':
                return '../dashboard/student_dashboard.php';
            default:
                return '../index.php?redirect=0';
        }
    }
}
