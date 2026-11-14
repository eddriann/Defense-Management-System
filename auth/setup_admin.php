<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found.');
}

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php setup_admin.php <username> <email> <full-name>\n");
    exit(2);
}

$username = trim($argv[1]);
$email = trim($argv[2]);
$full_name = trim($argv[3]);
$password = getenv('INITIAL_ADMIN_PASSWORD');
if (
    $username === '' || $full_name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || !is_string($password) || strlen($password) < 12
) {
    fwrite(STDERR, "Provide a username, valid email, full name, and INITIAL_ADMIN_PASSWORD of at least 12 characters.\n");
    exit(2);
}

require_once __DIR__ . '/../config/db.php';
$count = mysqli_query($conn, 'SELECT COUNT(*) AS total FROM users');
if ((int)mysqli_fetch_assoc($count)['total'] !== 0) {
    fwrite(STDERR, "Administrator bootstrap is only available before the first account is created.\n");
    exit(1);
}

$role = mysqli_query($conn, "SELECT role_id FROM roles WHERE role_name = 'Admin'");
$role_id = (int)(mysqli_fetch_assoc($role)['role_id'] ?? 0);
if ($role_id <= 0) {
    fwrite(STDERR, "Admin role is missing. Apply database/schema.sql first.\n");
    exit(1);
}

mysqli_begin_transaction($conn);
try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO users (username, password, email, full_name) VALUES (?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'ssss', $username, $hash, $email, $full_name);
    mysqli_stmt_execute($stmt);
    $user_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $assign = mysqli_prepare($conn, 'INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    mysqli_stmt_bind_param($assign, 'ii', $user_id, $role_id);
    mysqli_stmt_execute($assign);
    mysqli_stmt_close($assign);
    mysqli_commit($conn);
    fwrite(STDOUT, "Initial administrator created. Remove INITIAL_ADMIN_PASSWORD from the environment.\n");
} catch (Throwable $e) {
    mysqli_rollback($conn);
    throw $e;
} finally {
    putenv('INITIAL_ADMIN_PASSWORD');
}
