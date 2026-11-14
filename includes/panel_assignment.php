<?php

function assign_available_panel_seat(mysqli $conn, int $schedule_id, string $panel_role): int
{
    if (!in_array($panel_role, ['Chairperson', 'Panel Member'], true)) {
        throw new InvalidArgumentException('Invalid panel seat.');
    }

    $schedule_stmt = mysqli_prepare(
        $conn,
        "SELECT ds.scheduled_date, ds.slot_id, ds.room_id, p.adviser_id
         FROM defense_schedules ds
         JOIN projects p ON p.project_id = ds.project_id
         WHERE ds.schedule_id = ? AND ds.status <> 'Cancelled'"
    );
    mysqli_stmt_bind_param($schedule_stmt, 'i', $schedule_id);
    mysqli_stmt_execute($schedule_stmt);
    $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($schedule_stmt));
    mysqli_stmt_close($schedule_stmt);
    if (!$schedule) {
        throw new RuntimeException('The defense schedule is unavailable.');
    }

    $required_role_id = $panel_role === 'Chairperson' ? 3 : 0;
    $candidate_sql =
        "SELECT u.user_id
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.user_id
         WHERE u.status = 'active'
           AND ur.role_id " . ($required_role_id > 0 ? '= ?' : 'IN (2, 3)') . "
           AND u.user_id <> ?
           AND NOT EXISTS (
               SELECT 1 FROM panel_assignments current_pa
               WHERE current_pa.schedule_id = ? AND current_pa.user_id = u.user_id
           )
           AND NOT EXISTS (
               SELECT 1 FROM panel_assignment_responses declined
               WHERE declined.schedule_id = ? AND declined.user_id = u.user_id
           )
           AND NOT EXISTS (
               SELECT 1
               FROM panel_assignments other_pa
               JOIN defense_schedules other_ds ON other_ds.schedule_id = other_pa.schedule_id
               WHERE other_pa.user_id = u.user_id
                 AND other_ds.status <> 'Cancelled'
                 AND other_ds.scheduled_date = ?
                 AND other_ds.slot_id = ?
           )
           AND NOT EXISTS (
               SELECT 1
               FROM defense_schedules advised_ds
               JOIN projects advised_project ON advised_project.project_id = advised_ds.project_id
               WHERE advised_project.adviser_id = u.user_id
                 AND advised_ds.schedule_id <> ?
                 AND advised_ds.status <> 'Cancelled'
                 AND advised_ds.scheduled_date = ?
                 AND advised_ds.slot_id = ?
           )
         GROUP BY u.user_id
         ORDER BY (
             SELECT COUNT(*)
             FROM panel_assignments load_pa
             JOIN defense_schedules load_ds ON load_ds.schedule_id = load_pa.schedule_id
             WHERE load_pa.user_id = u.user_id
               AND load_ds.status <> 'Cancelled'
               AND load_ds.scheduled_date >= DATE_SUB(?, INTERVAL 30 DAY)
         ), u.user_id
         LIMIT 1";
    $candidate_stmt = mysqli_prepare($conn, $candidate_sql);
    if ($required_role_id > 0) {
        mysqli_stmt_bind_param(
            $candidate_stmt,
            'iiiisiisis',
            $required_role_id,
            $schedule['adviser_id'],
            $schedule_id,
            $schedule_id,
            $schedule['scheduled_date'],
            $schedule['slot_id'],
            $schedule_id,
            $schedule['scheduled_date'],
            $schedule['slot_id'],
            $schedule['scheduled_date']
        );
    } else {
        mysqli_stmt_bind_param(
            $candidate_stmt,
            'iiisiisis',
            $schedule['adviser_id'],
            $schedule_id,
            $schedule_id,
            $schedule['scheduled_date'],
            $schedule['slot_id'],
            $schedule_id,
            $schedule['scheduled_date'],
            $schedule['slot_id'],
            $schedule['scheduled_date']
        );
    }
    mysqli_stmt_execute($candidate_stmt);
    $candidate = mysqli_fetch_assoc(mysqli_stmt_get_result($candidate_stmt));
    mysqli_stmt_close($candidate_stmt);
    if (!$candidate) {
        throw new RuntimeException('There are not enough available panel members for this date and room.');
    }

    $user_id = (int)$candidate['user_id'];
    $insert = mysqli_prepare(
        $conn,
        "INSERT INTO panel_assignments (schedule_id, user_id, panel_role)
         VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($insert, 'iis', $schedule_id, $user_id, $panel_role);
    mysqli_stmt_execute($insert);
    mysqli_stmt_close($insert);
    return $user_id;
}

function automatically_assign_panel(mysqli $conn, int $schedule_id): void
{
    $schedule_stmt = mysqli_prepare(
        $conn,
        "SELECT ds.scheduled_date, ds.slot_id, ds.room_id, p.adviser_id
         FROM defense_schedules ds
         JOIN projects p ON p.project_id = ds.project_id
         WHERE ds.schedule_id = ? AND ds.status <> 'Cancelled'"
    );
    mysqli_stmt_bind_param($schedule_stmt, 'i', $schedule_id);
    mysqli_stmt_execute($schedule_stmt);
    $schedule = mysqli_fetch_assoc(mysqli_stmt_get_result($schedule_stmt));
    mysqli_stmt_close($schedule_stmt);
    if (!$schedule || (int)$schedule['adviser_id'] < 1) {
        throw new RuntimeException('The defense schedule must have an assigned adviser.');
    }

    $adviser_id = (int)$schedule['adviser_id'];
    $remove_old_adviser = mysqli_prepare(
        $conn,
        "DELETE FROM panel_assignments
         WHERE schedule_id = ? AND panel_role = 'Adviser' AND user_id <> ?"
    );
    mysqli_stmt_bind_param($remove_old_adviser, 'ii', $schedule_id, $adviser_id);
    mysqli_stmt_execute($remove_old_adviser);
    mysqli_stmt_close($remove_old_adviser);

    $adviser_conflict = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM defense_schedules other_ds
         JOIN projects other_project ON other_project.project_id = other_ds.project_id
         WHERE other_ds.schedule_id <> ?
           AND other_ds.status <> 'Cancelled'
           AND other_ds.scheduled_date = ?
           AND other_ds.slot_id = ?
           AND (
               other_project.adviser_id = ?
               OR EXISTS (
                   SELECT 1 FROM panel_assignments other_pa
                   WHERE other_pa.schedule_id = other_ds.schedule_id
                     AND other_pa.user_id = ?
               )
           )
         LIMIT 1"
    );
    mysqli_stmt_bind_param(
        $adviser_conflict,
        'isiii',
        $schedule_id,
        $schedule['scheduled_date'],
        $schedule['slot_id'],
        $adviser_id,
        $adviser_id
    );
    mysqli_stmt_execute($adviser_conflict);
    $has_adviser_conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($adviser_conflict));
    mysqli_stmt_close($adviser_conflict);
    if ($has_adviser_conflict) {
        throw new RuntimeException('The project adviser has another defense at the same time.');
    }

    $adviser_role = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM user_roles ur
         JOIN roles r ON r.role_id = ur.role_id
         WHERE ur.user_id = ? AND r.role_name = 'Adviser'
         LIMIT 1"
    );
    mysqli_stmt_bind_param($adviser_role, 'i', $adviser_id);
    mysqli_stmt_execute($adviser_role);
    $has_adviser_role = mysqli_fetch_assoc(mysqli_stmt_get_result($adviser_role));
    mysqli_stmt_close($adviser_role);
    if (!$has_adviser_role) {
        throw new RuntimeException('The assigned project adviser must have the Adviser role.');
    }

    $existing_adviser = mysqli_prepare(
        $conn,
        'SELECT panel_role FROM panel_assignments WHERE schedule_id = ? AND user_id = ?'
    );
    mysqli_stmt_bind_param($existing_adviser, 'ii', $schedule_id, $adviser_id);
    mysqli_stmt_execute($existing_adviser);
    $adviser_assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($existing_adviser));
    mysqli_stmt_close($existing_adviser);
    if ($adviser_assignment && $adviser_assignment['panel_role'] !== 'Adviser') {
        throw new RuntimeException('The project adviser cannot fill a chairperson or panel-member seat.');
    }
    if (!$adviser_assignment) {
        $insert_adviser = mysqli_prepare(
            $conn,
            "INSERT INTO panel_assignments (schedule_id, user_id, panel_role)
             VALUES (?, ?, 'Adviser')"
        );
        mysqli_stmt_bind_param($insert_adviser, 'ii', $schedule_id, $adviser_id);
        mysqli_stmt_execute($insert_adviser);
        mysqli_stmt_close($insert_adviser);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT panel_role, COUNT(*) AS total
         FROM panel_assignments
         WHERE schedule_id = ?
         GROUP BY panel_role"
    );
    mysqli_stmt_bind_param($stmt, 'i', $schedule_id);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    $counts = ['Chairperson' => 0, 'Panel Member' => 0];
    foreach ($rows as $row) {
        if (isset($counts[$row['panel_role']])) {
            $counts[$row['panel_role']] = (int)$row['total'];
        }
    }
    if ($counts['Chairperson'] === 0) {
        assign_available_panel_seat($conn, $schedule_id, 'Chairperson');
    }
    while ($counts['Panel Member'] < 2) {
        assign_available_panel_seat($conn, $schedule_id, 'Panel Member');
        $counts['Panel Member']++;
    }
}

function getPanelEvaluationSummary(mysqli $conn, int $schedule_id): array
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS panel_count,
                SUM(pa.panel_role = 'Chairperson') AS chair_count,
                SUM(pa.panel_role = 'Panel Member') AS member_count,
                SUM(e.decision IS NOT NULL AND e.total_score IS NOT NULL) AS completed_count,
                AVG(e.total_score) AS average_score,
                MAX(CASE WHEN pa.panel_role = 'Chairperson' THEN e.decision END) AS chair_decision
         FROM panel_assignments pa
         LEFT JOIN evaluations e
           ON e.schedule_id = pa.schedule_id AND e.evaluator_id = pa.user_id
         WHERE pa.schedule_id = ?
           AND pa.panel_role IN ('Chairperson', 'Panel Member')"
    );
    mysqli_stmt_bind_param($stmt, 'i', $schedule_id);
    mysqli_stmt_execute($stmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
    mysqli_stmt_close($stmt);

    return [
        'panel_count' => (int)($summary['panel_count'] ?? 0),
        'chair_count' => (int)($summary['chair_count'] ?? 0),
        'member_count' => (int)($summary['member_count'] ?? 0),
        'completed_count' => (int)($summary['completed_count'] ?? 0),
        'average_score' => ($summary['average_score'] ?? null) === null
            ? null
            : (float)$summary['average_score'],
        'chair_decision' => $summary['chair_decision'] ?? null,
    ];
}

function getEvaluationGradeResult(float $score): string
{
    if ($score > 75.0) {
        return 'Passed';
    }
    if ($score === 75.0) {
        return 'Revisions Needed';
    }
    return 'Failed';
}
