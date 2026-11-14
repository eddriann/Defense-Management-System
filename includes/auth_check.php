<?php
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/session.php';

function auth_check(array $allowed_roles): void
{
    global $conn;
    start_app_session();

    if (!isset($_SESSION['user_id'])) {
        header('Location: ../index.php');
        exit();
    }
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Authentication requires a connected database.');
    }

    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../index.php?redirect=expired');
        exit();
    }

    $user_id = (int)$_SESSION['user_id'];
    $stmt = mysqli_prepare($conn, "SELECT status FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $account = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$account || $account['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../index.php');
        exit();
    }

    $roles = load_user_roles($conn, $user_id);
    if (!$roles) {
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: ../index.php');
        exit();
    }
    $_SESSION['roles'] = $roles;
    if (!in_array($_SESSION['role_name'] ?? '', $allowed_roles, true)) {
        foreach ($allowed_roles as $allowed_role) {
            if (in_array($allowed_role, $roles, true)) {
                $_SESSION['role_name'] = $allowed_role;
                break;
            }
        }
    }
    $_SESSION['last_activity'] = time();

    if (!array_intersect($allowed_roles, $roles)) {
        http_response_code(403);
        exit('You are not authorized to access this page.');
    }
}
