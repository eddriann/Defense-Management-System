<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/manuscript_access.php';

auth_check(['Admin', 'Adviser', 'Panel Member']);
$role = $_SESSION['role_name'] ?? '';
$manuscript_id = (int)($_GET['manuscript_id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];
if ($role === 'Panel Member') {
    $schedule_id = (int)($_GET['schedule_id'] ?? 0);
    $stmt = mysqli_prepare(
        $conn,
        "SELECT m.manuscript_id, m.project_id, m.original_filename, m.stored_filename
         FROM manuscripts m
         INNER JOIN defense_schedules ds ON ds.project_id = m.project_id
         INNER JOIN panel_assignments pa ON pa.schedule_id = ds.schedule_id
         WHERE m.manuscript_id = ? AND m.status = 'Approved'
           AND ds.schedule_id = ? AND pa.user_id = ?
           AND pa.panel_role IN ('Chairperson', 'Panel Member', 'Adviser')
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'iii', $manuscript_id, $schedule_id, $user_id);
    mysqli_stmt_execute($stmt);
    $manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
} else {
    $manuscript = get_accessible_manuscript($conn, $manuscript_id, $user_id, $role);
}
if (!$manuscript) {
    http_response_code(404);
    exit('Manuscript not found or you are not authorized to access it.');
}

if (!preg_match('/\A[a-f0-9]{32}\.pdf\z/i', $manuscript['stored_filename'])) {
    throw new RuntimeException('The stored manuscript filename is invalid.');
}
$file_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR .
    'manuscripts' . DIRECTORY_SEPARATOR . $manuscript['stored_filename'];
if (!is_file($file_path) || !is_readable($file_path)) {
    http_response_code(404);
    exit('Manuscript file is unavailable.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($file_path));
header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="manuscript.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($file_path);
exit;
