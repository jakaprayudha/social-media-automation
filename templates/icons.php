<?php
declare(strict_types=1);

function icon(string $name, string $class = ''): string
{
    $paths = [
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'back' => '<path d="M19 12H5m6-6-6 6 6 6"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'shield' => '<path d="m12 3 8 3v6c0 4-4 7-8 9-4-2-8-5-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'spark' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 3v4m10-4v4M3 11h18m-14 4h2m4 0h2"/>',
        'flag' => '<path d="M5 21V3m0 1h14l-3 5 3 5H5"/>',
        'file' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6Zm0 0v6h6M8 13h8m-8 4h5"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="2"/><path d="m21 15-5-5L5 21"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'send' => '<path d="m21 3-7 18-4-7-7-4L21 3Zm0 0L10 14"/>',
        'link' => '<path d="m10 13 4-4m-6 6-2 2a4 4 0 0 1-6-6l4-4a4 4 0 0 1 6 0m2 2 2-2a4 4 0 0 1 6 6l-4 4a4 4 0 0 1-6 0" transform="translate(2 0)"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-4h6v4M8 7h1m6 0h1m-8 5h1m6 0h1"/>',
        'palette' => '<path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h3a3 3 0 0 0 3-3c0-4-4-7-9-7Z"/><path d="M7 10h.01M10 7h.01M15 7h.01"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m1-16a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 5v2"/>',
        'chart' => '<path d="M4 3v18h17M9 17v-5m5 5V7m5 10v-7"/>',
        'trend' => '<path d="m3 17 6-6 4 4 8-10m-6 0h6v6"/>',
        'list' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8m-8 4h8m-8 4h5"/>',
        'settings' => '<path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 2-1 3 2 3-2 2 1 3-2-1-3 2-2-2-3-3-1-1-3H9Z"/><circle cx="12" cy="11" r="3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'logout' => '<path d="M9 4H4v16h5m5-13 5 5-5 5m-6-5h11"/>',
    ];
    return '<svg class="icon ' . escape($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? $paths['lock']) . '</svg>';
}
