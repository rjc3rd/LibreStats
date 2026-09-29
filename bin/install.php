<?php
// Creates LibreStats' tables and brings an older database up to date. Safe to run again (existing
// tables and data are left as they are).
//   php bin/install.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/install.php';

foreach (ls_install_schema(ls_db()) as $change) {
    echo "Updated: $change\n";
}
echo "Tables ready. Next: php bin/site.php add example.com [time zone]\n";
