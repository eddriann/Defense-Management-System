<?php
function format_defense_countdown(string $scheduled_date): string
{
    $today = new DateTimeImmutable('today');
    $defense_day = new DateTimeImmutable($scheduled_date);
    $days = (int)$today->diff($defense_day)->format('%r%a');

    if ($days === 0) {
        return 'Today';
    }
    if ($days === 1) {
        return 'Tomorrow';
    }
    if ($days > 1) {
        return 'In ' . $days . ' days';
    }
    if ($days === -1) {
        return 'Yesterday';
    }
    return abs($days) . ' days ago';
}

function refresh_defense_day_status(mysqli $conn): void
{
    $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');

    $complete_schedules = mysqli_prepare(
        $conn,
        "UPDATE defense_schedules ds
         INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
         SET ds.status = 'Completed'
         WHERE ds.status IN ('Scheduled', 'Ongoing')
           AND TIMESTAMP(ds.scheduled_date, ts.end_time) <= ?"
    );
    mysqli_stmt_bind_param($complete_schedules, 's', $now);
    mysqli_stmt_execute($complete_schedules);
    mysqli_stmt_close($complete_schedules);

    $start_schedules = mysqli_prepare(
        $conn,
        "UPDATE defense_schedules ds
         INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
         SET ds.status = 'Ongoing'
         WHERE ds.status = 'Scheduled'
           AND TIMESTAMP(ds.scheduled_date, ts.start_time) <= ?
           AND TIMESTAMP(ds.scheduled_date, ts.end_time) > ?"
    );
    mysqli_stmt_bind_param($start_schedules, 'ss', $now, $now);
    mysqli_stmt_execute($start_schedules);
    mysqli_stmt_close($start_schedules);

    $scheduled_projects = mysqli_prepare(
        $conn,
        "UPDATE projects p
         SET p.status = 'For Defense'
         WHERE p.status <> 'Dropped'
           AND EXISTS (
               SELECT 1 FROM defense_schedules ds
               WHERE ds.project_id = p.project_id
                 AND ds.status IN ('Scheduled', 'Ongoing')
           )"
    );
    mysqli_stmt_execute($scheduled_projects);
    mysqli_stmt_close($scheduled_projects);

    $completed_final_projects = mysqli_prepare(
        $conn,
        "UPDATE projects p
         SET p.status = 'Completed'
         WHERE p.status <> 'Dropped'
           AND NOT EXISTS (
               SELECT 1 FROM defense_schedules active
               WHERE active.project_id = p.project_id
                 AND active.status IN ('Scheduled', 'Ongoing')
           )
           AND EXISTS (
               SELECT 1
               FROM defense_schedules ds
               INNER JOIN time_slots ts ON ts.slot_id = ds.slot_id
               WHERE ds.project_id = p.project_id
                 AND ds.defense_type = 'Final'
                 AND ds.status = 'Completed'
                 AND TIMESTAMP(ds.scheduled_date, ts.end_time) <= ?
                 AND (ds.final_result IS NULL OR ds.final_result IN ('Defended', 'Passed'))
                 AND NOT EXISTS (
                     SELECT 1
                     FROM defense_schedules latest
                     WHERE latest.project_id = p.project_id
                       AND latest.defense_type = 'Final'
                       AND latest.status <> 'Cancelled'
                       AND (
                           latest.scheduled_date > ds.scheduled_date
                           OR (latest.scheduled_date = ds.scheduled_date
                               AND latest.schedule_id > ds.schedule_id)
                       )
                 )
           )"
    );
    mysqli_stmt_bind_param($completed_final_projects, 's', $now);
    mysqli_stmt_execute($completed_final_projects);
    mysqli_stmt_close($completed_final_projects);

    $resume_projects = mysqli_prepare(
        $conn,
        "UPDATE projects p
         SET p.status = 'In Progress'
         WHERE p.status = 'For Defense'
           AND NOT EXISTS (
               SELECT 1 FROM defense_schedules active
               WHERE active.project_id = p.project_id
                 AND active.status IN ('Scheduled', 'Ongoing')
           )
           AND EXISTS (
               SELECT 1 FROM defense_schedules ds
               WHERE ds.project_id = p.project_id
           )"
    );
    mysqli_stmt_execute($resume_projects);
    mysqli_stmt_close($resume_projects);
}
