<?php

function manuscript_status_label(string $status): string
{
    return match ($status) {
        'In Revision' => 'Revision Required',
        'Revision Submitted' => 'Revision Submitted',
        'Approved', 'Reviewed' => 'Approved',
        default => $status,
    };
}
