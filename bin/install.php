<?php
// Creates LibreStats' tables. Safe to run again (existing tables are left as they are).
//   php bin/install.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';

$sql = file_get_contents(LS_ROOT . '/sql/schema.sql');
$sql = preg_replace('~^\s*--.*$~m', '', (string) $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    ls_db()->exec($statement);
}
echo "Tables ready. Next: php bin/site.php add example.com [time zone]\n";
