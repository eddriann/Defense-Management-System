<?php
// Only active schedules and room closures block rooms; pending requests do not.

if (!function_exists('getActiveRooms')) {
    // All rooms that can be booked, grouped nicely by location
    function getActiveRooms(mysqli $conn): array
    {
        $res = mysqli_query(
            $conn,
            "SELECT room_id, room_name, COALESCE(NULLIF(location, ''), 'No location') AS location
             FROM rooms
             WHERE status = 'Active'
             ORDER BY location, room_name"
        );
        return mysqli_fetch_all($res, MYSQLI_ASSOC);
    }
}

if (!function_exists('getBookedRooms')) {
    // Returns [date => [slot_id => [room_id, ...]]] for dates between $from and $to
    function getBookedRooms(mysqli $conn, string $from, string $to): array
    {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT scheduled_date AS d, slot_id, room_id
             FROM defense_schedules
             WHERE scheduled_date BETWEEN ? AND ?
               AND status <> 'Cancelled'"
        );
        mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        $booked = [];
        $daily_counts = [];
        foreach ($rows as $row) {
            $booked[$row['d']][(int)$row['slot_id']][] = (int)$row['room_id'];
            $daily_counts[$row['d']][(int)$row['room_id']] =
                ($daily_counts[$row['d']][(int)$row['room_id']] ?? 0) + 1;
        }
        $unavailable_stmt = mysqli_prepare(
            $conn,
            'SELECT room_id, unavailable_date, slot_id
             FROM room_unavailability
             WHERE unavailable_date BETWEEN ? AND ?'
        );
        mysqli_stmt_bind_param($unavailable_stmt, 'ss', $from, $to);
        mysqli_stmt_execute($unavailable_stmt);
        $unavailable_rows = mysqli_fetch_all(mysqli_stmt_get_result($unavailable_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($unavailable_stmt);
        foreach ($unavailable_rows as $row) {
            $booked['_unavailable'][$row['unavailable_date']][(int)$row['room_id']][] =
                $row['slot_id'] === null ? 0 : (int)$row['slot_id'];
        }
        $booked['_daily_counts'] = $daily_counts;
        return $booked;
    }
}

if (!function_exists('freeRooms')) {
    // Rooms that are free in this slot and have not exceeded four daily defenses.
    function freeRooms(array $rooms, array $booked, string $date, int $slot_id): array
    {
        $taken = $booked[$date][$slot_id] ?? [];
        $daily_counts = $booked['_daily_counts'][$date] ?? [];
        $unavailable = $booked['_unavailable'][$date] ?? [];
        return array_values(array_filter($rooms, function ($room) use ($taken, $daily_counts, $unavailable, $slot_id) {
            $room_id = (int)$room['room_id'];
            return !in_array($room_id, $taken, true)
                && ($daily_counts[$room_id] ?? 0) < 4
                && !in_array(0, $unavailable[$room_id] ?? [], true)
                && !in_array($slot_id, $unavailable[$room_id] ?? [], true);
        }));
    }
}

if (!function_exists('getAvailableRoomForSlot')) {
    function getAvailableRoomForSlot(
        mysqli $conn,
        string $date,
        int $slot_id,
        bool $lock_for_update = false
    ): ?array {
        $sql =
            "SELECT r.room_id, r.room_name, r.location
             FROM rooms r
             WHERE r.status = 'Active'
               AND (
                   SELECT COUNT(*)
                   FROM defense_schedules daily
                   WHERE daily.room_id = r.room_id
                     AND daily.scheduled_date = ?
                     AND daily.status <> 'Cancelled'
               ) < 4
               AND NOT EXISTS (
                   SELECT 1
                   FROM defense_schedules booked
                   WHERE booked.room_id = r.room_id
                     AND booked.scheduled_date = ?
                     AND booked.slot_id = ?
                     AND booked.status <> 'Cancelled'
               )
               AND NOT EXISTS (
                   SELECT 1
                   FROM room_unavailability unavailable
                   WHERE unavailable.room_id = r.room_id
                     AND unavailable.unavailable_date = ?
                     AND (unavailable.slot_id IS NULL OR unavailable.slot_id = ?)
               )
             ORDER BY r.room_id
             LIMIT 1";
        if ($lock_for_update) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ssisi', $date, $date, $slot_id, $date, $slot_id);
        mysqli_stmt_execute($stmt);
        $room = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);
        return $room;
    }
}

if (!function_exists('openSlots')) {
    // Slots (from $slots) that still have at least one free room on $date
    function openSlots(array $slots, array $rooms, array $booked, string $date): array
    {
        return array_values(array_filter($slots, function ($slot) use ($rooms, $booked, $date) {
            return count(freeRooms($rooms, $booked, $date, (int)$slot['slot_id'])) > 0;
        }));
    }

    if (!function_exists('getValidDefenseDates')) {
        function getValidDefenseDates(mysqli $conn, string $from, int $days, ?int $exclude_schedule_id = null): array
        {
            $to_date = (new DateTimeImmutable($from))->modify('+' . max(1, $days) . ' days')->format('Y-m-d');
            $rooms = getActiveRooms($conn);
            $slots = mysqli_fetch_all(
                mysqli_query($conn, 'SELECT slot_id FROM time_slots ORDER BY start_time'),
                MYSQLI_ASSOC
            );
            $booked = getBookedRooms($conn, $from, $to_date);

            if ($exclude_schedule_id !== null) {
                $stmt = mysqli_prepare(
                    $conn,
                    'SELECT scheduled_date, slot_id, room_id, status FROM defense_schedules WHERE schedule_id = ?'
                );
                mysqli_stmt_bind_param($stmt, 'i', $exclude_schedule_id);
                mysqli_stmt_execute($stmt);
                $current = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
                mysqli_stmt_close($stmt);
                if ($current && isset($booked[$current['scheduled_date']][(int)$current['slot_id']])) {
                    $room_ids = &$booked[$current['scheduled_date']][(int)$current['slot_id']];
                    $room_ids = array_values(array_filter(
                        $room_ids,
                        function ($room_id) use ($current) {
                            return (int)$room_id !== (int)$current['room_id'];
                        }
                    ));
                }
                if (
                    $current && $current['status'] !== 'Cancelled' &&
                    isset($booked['_daily_counts'][$current['scheduled_date']][(int)$current['room_id']])
                ) {
                    $booked['_daily_counts'][$current['scheduled_date']][(int)$current['room_id']]--;
                }
            }

            $dates = [];
            $date = new DateTimeImmutable($from);
            $end = new DateTimeImmutable($to_date);
            for (; $date <= $end; $date = $date->modify('+1 day')) {
                $date_string = $date->format('Y-m-d');
                if (count(openSlots($slots, $rooms, $booked, $date_string)) > 0) {
                    $dates[] = $date_string;
                }
            }
            return $dates;
        }
    }
}
