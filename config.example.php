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

    // The built-in dashboard and its logins. false turns them off: visitors see a short notice, and
    // there is no login or setup page. Tracking and the data API keep working.
    'dashboard' => true,

    // The page where the first visitor creates the first login. false turns it off, for a server whose
    // logins only come from bin/user.php or from an app that runs LibreStats.
    'first_run' => true,

    // How many viewers (people who can only look) one team may have. Teams are managed by an app through
    // the data API with a key that has team access (bin/apikey.php). 0: no limit.
    'team_limit' => 5,

    // Dashboard look: a folder name under themes/ (or under one of theme_paths).
    'theme' => 'default',
    'theme_paths' => [],
];
