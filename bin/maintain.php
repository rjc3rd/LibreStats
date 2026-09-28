<?php
// Nightly housekeeping: run once a day from cron, e.g.
//   15 3 * * *  php /path/to/librestats/bin/maintain.php --quiet

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/maintain.php';

$log = ls_maintain(ls_db(), ls_config());
if (!in_array('--quiet', $argv, true)) {
    echo implode("\n", $log), "\n";
}
