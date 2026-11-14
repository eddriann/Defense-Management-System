<?php
include('../config/db.php');
require_once('../includes/auth_check.php');
require_once('../includes/csrf.php');

auth_check(['Admin', 'Adviser', 'Panel Member', 'Student']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
csrf_validate_request();
$role = $_POST['role'] ?? '';
if (is_string($role) && has_role($role)) {
    $_SESSION['role_name'] = $role;
    header("Location: " . dashboard_for($role));
    exit();
}

http_response_code(403);
header('Location: ../index.php');
exit();
