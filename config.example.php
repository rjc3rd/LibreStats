<?php
// LibreStats settings. Copy to config.php (kept out of git) and fill in.

return [
    // Database (MariaDB or MySQL).
    'db' => [
        'host' => 'localhost',
        'name' => 'librestats',
        'user' => 'librestats',
        'pass' => '',
    ],

    // How long raw visits are kept before only the monthly totals remain.
    'retention_months' => 13,

    // A visit ends after this many minutes without a page view or activity.
    'visit_timeout_minutes' => 30,

    // Behind a reverse proxy or CDN, list its addresses so the visitor's real address (used only
    // for the daily hash and the country lookup, never stored) is read from X-Forwarded-For.
    'trusted_proxies' => [],
];
