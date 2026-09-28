<?php
// Manage the websites LibreStats counts.
//   php bin/site.php add example.com [America/Chicago]
//   php bin/site.php list
//   php bin/site.php remove example.com     (deletes the site and all its numbers)

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/admin.php';

$pdo = ls_db();
[$cmd, $domain, $tz] = [$argv[1] ?? '', strtolower($argv[2] ?? ''), $argv[3] ?? 'UTC'];
$domain = ls_clean_domain($domain);

switch ($cmd) {
    case 'add':
        if ($problem = ls_site_add($pdo, $domain, $tz)) {
            exit("$problem\n");
        }
        echo "Added $domain ($tz). Put this in its pages' <head>, with your LibreStats address:\n";
        echo "  <script src=\"https://YOUR-LIBRESTATS/s.js\" data-site=\"$domain\" defer></script>\n";
        break;
    case 'list':
        foreach ($pdo->query("SELECT domain, timezone, created_at FROM sites ORDER BY domain") as $s) {
            echo str_pad($s['domain'], 40), str_pad($s['timezone'], 24), $s['created_at'], "\n";
        }
        break;
    case 'remove':
        $stmt = $pdo->prepare("SELECT id FROM sites WHERE domain = :d");
        $stmt->execute(['d' => $domain]);
        $id = (int) $stmt->fetchColumn();
        if (!$id) {
            exit("No site $domain\n");
        }
        ls_site_remove($pdo, $id);
        echo "Removed $domain and all its numbers.\n";
        break;
    default:
        echo "Usage: php bin/site.php add example.com [time zone] | list | remove example.com\n";
}
