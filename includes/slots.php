<?php

if (!function_exists('getTakenSlots')) {
    // Returns ['2026-10-17' => [1, 3], ...] for dates between $from and $to (inclusive)
    function getTakenSlots(mysqli $conn, string $from, string $to): array
    {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT preferred_date AS d, slot_id
             FROM defense_requests
             WHERE status IN ('Pending', 'Endorsed')
               AND preferred_date BETWEEN ? AND ?
             UNION
             SELECT scheduled_date, slot_id
             FROM defense_schedules
             WHERE status IN ('Scheduled', 'Ongoing')
               AND scheduled_date BETWEEN ? AND ?"
        );
        mysqli_stmt_bind_param($stmt, 'ssss', $from, $to, $from, $to);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        $taken = [];
        foreach ($rows as $row) {
            $taken[$row['d']][] = (int)$row['slot_id'];
        }
        return $taken;
    }
}

if (!function_exists('isSlotAvailable')) {
    // Verifies if a specific time slot is free on a specific date
    function isSlotAvailable(mysqli $conn, string $date, int $slot_id): bool
    {
        $used = getTakenSlots($conn, $date, $date)[$date] ?? [];
        return !in_array($slot_id, $used, true);
    }
}
