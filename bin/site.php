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
require __DIR__ . '/../lib/referrer.php';

$pdo = ls_db();
[$cmd, $domain, $tz] = [$argv[1] ?? '', strtolower($argv[2] ?? ''), $argv[3] ?? 'UTC'];
$domain = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

switch ($cmd) {
    case 'add':
        if (!preg_match('~^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}$~', $domain)) {
            exit("Give a domain like example.com\n");
        }
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            exit("Unknown time zone: $tz (e.g. America/Chicago, Europe/London, UTC)\n");
        }
        $pdo->prepare("INSERT INTO sites (domain, name, timezone) VALUES (:d, :n, :t)")->execute(['d' => $domain, 'n' => $domain, 't' => $tz]);
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
        foreach (['events', 'pageviews', 'visits', 'goals', 'monthly_totals', 'monthly_top'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE site_id = :s")->execute(['s' => $id]);
        }
        $pdo->prepare("DELETE FROM sites WHERE id = :s")->execute(['s' => $id]);
        echo "Removed $domain and all its numbers.\n";
        break;
    default:
        echo "Usage: php bin/site.php add example.com [time zone] | list | remove example.com\n";
}
