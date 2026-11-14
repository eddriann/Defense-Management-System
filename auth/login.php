<?php
include('../config/db.php');
require_once('../includes/roles.php');
require_once('../includes/csrf.php');
start_app_session();
csrf_validate_request();

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if ($username !== "" && $password !== "") {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT user_id, username, password, full_name
         FROM users
         WHERE username = ? AND status = 'active'"
    );

    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($user = mysqli_fetch_assoc($result)) {
        $stored_password = (string)$user['password'];
        $password_valid = password_verify($password, $stored_password);
        $legacy_password = !$password_valid
            && preg_match('/^[a-f0-9]{32}$/i', $stored_password)
            && hash_equals(strtolower($stored_password), md5($password));

        if ($password_valid || $legacy_password) {
            if ($legacy_password || password_needs_rehash($stored_password, PASSWORD_DEFAULT)) {
                $new_hash = password_hash($password, PASSWORD_DEFAULT);
                $upgrade = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE user_id = ?");
                mysqli_stmt_bind_param($upgrade, 'si', $new_hash, $user['user_id']);
                mysqli_stmt_execute($upgrade);
                mysqli_stmt_close($upgrade);
            }

            $roles = load_user_roles($conn, (int)$user['user_id']);

            if (empty($roles)) {
                header("Location: ../index.php?redirect=0");
                exit();
            }

            session_regenerate_id(true);

            $user_id = (int)$user['user_id'];
            $_SESSION['user_id'] = $user_id;
            $_SESSION['roles'] = $roles;
            $_SESSION['role_name'] = $roles[0];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['last_activity'] = time();

            if (in_array('Student', $roles)) {
                $s_stmt = mysqli_prepare($conn, "SELECT student_id FROM students WHERE user_id = ?");
                mysqli_stmt_bind_param($s_stmt, "i", $user_id);
                mysqli_stmt_execute($s_stmt);
                $s_res = mysqli_fetch_assoc(mysqli_stmt_get_result($s_stmt));
                $_SESSION['student_id'] = $s_res['student_id'] ?? 0;
                mysqli_stmt_close($s_stmt);
            } else {
                unset($_SESSION['student_id']);
            }

            header("Location: " . dashboard_for($_SESSION['role_name']));
            exit();
        } else {
            header("Location: ../index.php?redirect=0");
            exit();
        }
    } else {
        header("Location: ../index.php?redirect=0");
        exit();
    }
} else {
    header("Location: ../index.php?redirect=0");
    exit();
}
