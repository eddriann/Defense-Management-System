<?php

function user_can_access_project_manuscript(mysqli $conn, int $project_id, int $user_id, string $role): bool
{
    if ($role === 'Admin') {
        return true;
    }

    if ($role === 'Adviser') {
        $stmt = mysqli_prepare($conn, 'SELECT 1 FROM projects WHERE project_id = ? AND adviser_id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $project_id, $user_id);
    } elseif ($role === 'Student') {
        $stmt = mysqli_prepare(
            $conn,
            'SELECT 1
             FROM project_members pm
             INNER JOIN students s ON s.student_id = pm.student_id
             WHERE pm.project_id = ? AND s.user_id = ?'
        );
        mysqli_stmt_bind_param($stmt, 'ii', $project_id, $user_id);
    } else {
        return false;
    }

    mysqli_stmt_execute($stmt);
    $allowed = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) !== null;
    mysqli_stmt_close($stmt);
    return $allowed;
}

function get_accessible_manuscript(mysqli $conn, int $manuscript_id, int $user_id, string $role): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        'SELECT m.manuscript_id, m.project_id, m.uploaded_by, m.original_filename,
                m.stored_filename, m.file_size, m.status, m.submitted_at, p.adviser_id
         FROM manuscripts m
         INNER JOIN projects p ON p.project_id = m.project_id
         WHERE m.manuscript_id = ?'
    );
    mysqli_stmt_bind_param($stmt, 'i', $manuscript_id);
    mysqli_stmt_execute($stmt);
    $manuscript = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$manuscript || !user_can_access_project_manuscript($conn, (int)$manuscript['project_id'], $user_id, $role)) {
        return null;
    }

    return $manuscript;
}

