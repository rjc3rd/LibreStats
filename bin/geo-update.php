<?php
// Downloads the free DB-IP Lite country database (CC BY 4.0, https://db-ip.com) and loads it
// into geo_country, so country lookups happen on this server with nothing sent anywhere.
// DB-IP publishes a new file each month; run monthly from cron, e.g.
//   30 4 3 * *  php /path/to/librestats/bin/geo-update.php --quiet
// Or load a file you downloaded yourself:  php bin/geo-update.php --file=dbip-country-lite.csv.gz

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/geo.php';

$quiet = in_array('--quiet', $argv, true);
$say = function (string $line) use ($quiet) {
    if (!$quiet) {
        echo $line, "\n";
    }
};

$file = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $file = substr($arg, 7);
    }
}
if ($file === null) {
    $file = tempnam(sys_get_temp_dir(), 'dbip');
    // This month's file appears a day or two into the month; fall back to last month's.
    foreach ([gmdate('Y-m'), gmdate('Y-m', strtotime('first day of last month'))] as $ym) {
        $url = "https://download.db-ip.com/free/dbip-country-lite-$ym.csv.gz";
        $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'LibreStats geo-update']]));
        if ($data !== false && strlen($data) > 100000) {
            file_put_contents($file, $data);
            $say("Downloaded $url");
            break;
        }
        $data = null;
    }
    if (empty($data)) {
        fwrite(STDERR, "Couldn't download the DB-IP Lite country file.\n");
        exit(1);
    }
}

$pdo = ls_db();
$pdo->exec("DROP TABLE IF EXISTS geo_country_new");
$pdo->exec("CREATE TABLE geo_country_new LIKE geo_country");
$gz = gzopen($file, 'r');
if (!$gz) {
    fwrite(STDERR, "Can't read $file\n");
    exit(1);
}
$rows = [];
$count = 0;
$flush = function () use (&$rows, $pdo) {
    if ($rows) {
        $sql = "INSERT IGNORE INTO geo_country_new (ip_start, ip_end, country) VALUES " . implode(',', array_fill(0, count($rows) / 3, '(?, ?, ?)'));
        $pdo->prepare($sql)->execute($rows);
        $rows = [];
    }
};
$pdo->beginTransaction();
while (($line = gzgets($gz)) !== false) {
    $f = str_getcsv(trim($line), ',', '"', '');
    if (count($f) < 3 || ($start = ls_ip_bin($f[0])) === null || ($end = ls_ip_bin($f[1])) === null) {
        continue;
    }
    array_push($rows, $start, $end, strtoupper(substr($f[2], 0, 2)));
    if (++$count % 1000 === 0) {
        $flush();
    }
}
$flush();
$pdo->commit();
gzclose($gz);
if ($count < 100000) {
    $pdo->exec("DROP TABLE geo_country_new");
    fwrite(STDERR, "Only $count ranges read; keeping the current data.\n");
    exit(1);
}
$pdo->exec("DROP TABLE IF EXISTS geo_country_old");
$pdo->exec("RENAME TABLE geo_country TO geo_country_old, geo_country_new TO geo_country");
$pdo->exec("DROP TABLE geo_country_old");
$say("Loaded $count address ranges.");
