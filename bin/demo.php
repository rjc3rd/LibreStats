<?php
// Fills a site called demo.example with 90 days of made-up visits, so you can see the dashboard
// before any real traffic arrives. Run again to start over; remove with: php bin/site.php remove demo.example
//   php bin/demo.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';

$pdo = ls_db();
$domain = 'demo.example';
$pdo->prepare("INSERT IGNORE INTO sites (domain, name, timezone) VALUES (:d, 'Demo site', 'America/Chicago')")->execute(['d' => $domain]);
$stmt = $pdo->prepare("SELECT id FROM sites WHERE domain = :d");
$stmt->execute(['d' => $domain]);
$sid = (int) $stmt->fetchColumn();
foreach (['events', 'pageviews', 'visits', 'goals', 'monthly_totals', 'monthly_top'] as $t) {
    $pdo->prepare("DELETE FROM $t WHERE site_id = :s")->execute(['s' => $sid]);
}
$goals = [['Viewed pricing', 'path', '/pricing'], ['Started an order', 'event', 'Started an order'], ['Signed up', 'event', 'Signed up'], ['Paid', 'path', '/thanks']];
foreach ($goals as $i => [$n, $k, $t]) {
    $pdo->prepare("INSERT INTO goals (site_id, name, kind, target, position) VALUES (:s, :n, :k, :t, :p)")->execute(['s' => $sid, 'n' => $n, 'k' => $k, 't' => $t, 'p' => $i + 1]);
}

$pick = function (array $weighted) {
    $r = mt_rand(1, array_sum($weighted));
    foreach ($weighted as $k => $w) {
        if (($r -= $w) <= 0) {
            return $k;
        }
    }
    return array_key_first($weighted);
};
mt_srand(42);
$sources = ['direct||' => 40, 'search|google.com|' => 22, 'search|duckduckgo.com|' => 12, 'social|x.com|' => 8, 'link|news.ycombinator.com|' => 4,
    'social|reddit.com|' => 5, 'campaign||spring-launch' => 6, 'link|github.com|' => 3];
$countries = ['US' => 45, 'GB' => 9, 'DE' => 8, 'CA' => 7, 'FR' => 5, 'NL' => 4, 'IN' => 6, 'AU' => 4, 'BR' => 3, 'PH' => 3];
$agents = ['Chrome|Windows|desktop|1920' => 24, 'Firefox|Linux|desktop|1920' => 9, 'Safari|macOS|desktop|1440' => 14, 'Safari|iOS|phone|390' => 22,
    'Chrome|Android|phone|412' => 17, 'Edge|Windows|desktop|1536' => 6, 'Safari|iOS|tablet|820' => 5, 'Brave|Linux|desktop|2560' => 3];
$pages = ['/' => 40, '/pricing' => 18, '/features' => 14, '/blog/' => 8, '/blog/privacy-first-stats' => 7, '/docs/' => 6, '/about' => 5, '/contact' => 2];

$insVisit = $pdo->prepare("INSERT INTO visits (site_id, visitor, day, started_at, last_at, pageviews, duration, entry_path, exit_path, source, referrer_host,
    utm_source, utm_medium, utm_campaign, country, browser, os, device, screen_width) VALUES (:s,:v,:d,:a,:b,:pv,:du,:en,:ex,:src,:ref,:us,'',:uc,:c,:br,:os,:dev,:w)");
$insPage = $pdo->prepare("INSERT INTO pageviews (visit_id, site_id, day, at, path, title, page_key, seconds) VALUES (:v,:s,:d,:a,:p,'',:k,:sec)");
$insEvent = $pdo->prepare("INSERT INTO events (visit_id, site_id, day, at, name, detail, path) VALUES (:v,:s,:d,:a,:n,:x,:p)");

$pdo->beginTransaction();
$total = 0;
for ($daysAgo = 89; $daysAgo >= 0; $daysAgo--) {
    $day = date('Y-m-d', strtotime("-$daysAgo days"));
    $weekday = (int) date('N', strtotime($day));
    $count = (int) round((60 + (89 - $daysAgo) * 0.9) * ($weekday >= 6 ? 0.7 : 1) * mt_rand(80, 120) / 100);
    for ($i = 0; $i < $count; $i++) {
        [$src, $ref, $camp] = explode('|', $pick($sources));
        [$br, $os, $dev, $w] = explode('|', $pick($agents));
        $start = strtotime($day) + mt_rand(6 * 3600, 23 * 3600);
        $n = $pick([1 => 46, 2 => 24, 3 => 15, 4 => 9, 6 => 6]);
        $path = $pick($pages);
        $seconds = [];
        $paths = [];
        for ($p = 0; $p < $n; $p++) {
            $paths[] = $path;
            $seconds[] = $n === 1 ? mt_rand(5, 70) : mt_rand(10, 160);
            $path = $pick($pages);
        }
        $insVisit->execute(['s' => $sid, 'v' => random_bytes(8), 'd' => $day, 'a' => gmdate('Y-m-d H:i:s', $start), 'b' => gmdate('Y-m-d H:i:s', $start + array_sum($seconds)),
            'pv' => $n, 'du' => array_sum($seconds), 'en' => $paths[0], 'ex' => end($paths), 'src' => $src, 'ref' => $ref, 'us' => $camp ? 'newsletter' : '',
            'uc' => $camp, 'c' => $pick($countries), 'br' => $br, 'os' => $os, 'dev' => $dev, 'w' => $w]);
        $vid = (int) $pdo->lastInsertId();
        $t = $start;
        foreach ($paths as $k => $p) {
            $insPage->execute(['v' => $vid, 's' => $sid, 'd' => $day, 'a' => gmdate('Y-m-d H:i:s', $t), 'p' => $p, 'k' => bin2hex(random_bytes(8)), 'sec' => $seconds[$k]]);
            $t += $seconds[$k];
        }
        $event = fn (string $name, string $x = '') => $insEvent->execute(['v' => $vid, 's' => $sid, 'd' => $day, 'a' => gmdate('Y-m-d H:i:s', $t), 'n' => $name, 'x' => $x, 'p' => end($paths)]);
        if (in_array('/pricing', $paths, true) && mt_rand(1, 100) <= 30) {
            $event('Started an order');
            if (mt_rand(1, 100) <= 40) {
                $event('Signed up');
                if (mt_rand(1, 100) <= 55) {
                    $insPage->execute(['v' => $vid, 's' => $sid, 'd' => $day, 'a' => gmdate('Y-m-d H:i:s', $t), 'p' => '/thanks', 'k' => bin2hex(random_bytes(8)), 'sec' => 20]);
                }
            }
        }
        if (mt_rand(1, 100) <= 8) {
            $event('Outbound link', 'github.com/rjc3rd/LibreStats');
        }
        if (mt_rand(1, 100) <= 4) {
            $event('File download', '/docs/guide.pdf');
        }
        $total++;
    }
}
// A few people "on the site now".
$pdo->exec("UPDATE visits SET last_at = UTC_TIMESTAMP() WHERE site_id = $sid ORDER BY id DESC LIMIT 3");
$pdo->commit();
echo "demo.example: $total made-up visits over 90 days, with 4 goals.\n";
