<?php

function getDefenseEvaluationWindowStatus(
    string $scheduled_date,
    string $start_time,
    string $end_time,
    ?DateTimeImmutable $now = null
): string {
    $timezone = new DateTimeZone('Asia/Manila');
    $start = new DateTimeImmutable($scheduled_date . ' ' . $start_time, $timezone);
    $end = new DateTimeImmutable($scheduled_date . ' ' . $end_time, $timezone);
    $current = $now === null
        ? new DateTimeImmutable('now', $timezone)
        : $now->setTimezone($timezone);

    if ($current < $start) {
        return 'not_open';
    }
    if ($current >= $end) {
        return 'closed';
    }
    return 'open';
}
