<?php

function dashboard_icon(string $name): string
{
    $paths = [
        'dashboard' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus' => '<path d="M15 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m-3-3h6"/>',
        'request' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-8 4h8"/>',
        'schedule' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-12 4h.01M12 15h.01M16 15h.01"/>',
        'panels' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M18 8v6m-3-3h6"/>',
        'rooms' => '<path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M2 21h20M9 21v-5h6v5"/><circle cx="16" cy="10" r=".6"/>',
        'report' => '<path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 5-6"/><path d="M16 7h3v3"/>',
        'project' => '<path d="M4 4h16v16H4z"/><path d="M8 8h8m-8 4h8m-8 4h5"/>',
        'evaluation' => '<path d="M9 3h6m-7 0H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-2"/><path d="m8 13 2.5 2.5L16 10"/>',
        'add' => '<path d="M12 5v14M5 12h14"/>',
        'switch' => '<path d="m17 1 4 4-4 4M3 5h18m-14 18-4-4 4-4m14 4H3"/>',
        'logout' => '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    ];
    if (!isset($paths[$name])) {
        throw new InvalidArgumentException('Unknown dashboard icon: ' . $name);
    }

    return '<svg class="icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}
