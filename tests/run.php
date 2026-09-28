<?php
// LibreStats checks. Uses a separate database (config db name + "_test"), wiped on every run.
//   php tests/run.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/collect.php';
require __DIR__ . '/../lib/maintain.php';

$config = ls_config();
$db = $config['db'];
$db['name'] .= '_test';
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET time_zone = '+00:00'");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
$schema = preg_replace('~^\s*--.*$~m', '', (string) file_get_contents(LS_ROOT . '/sql/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $s) {
    $pdo->exec($s);
}
$pdo->exec("INSERT INTO sites (domain, name, timezone) VALUES ('example.com', 'Example', 'America/Chicago'), ('other.org', 'Other', 'UTC')");
// 203.0.113.0/24 is a documentation range; pretend it's in the US.
$pdo->prepare("INSERT INTO geo_country VALUES (:a, :b, 'US')")->execute(['a' => ls_ip_bin('203.0.113.0'), 'b' => ls_ip_bin('203.0.113.255')]);

$failed = 0;
$passed = 0;
function check(string $what, bool $ok): void
{
    global $failed, $passed;
    $ok ? $passed++ : $failed++;
    echo ($ok ? "  ok    " : "  FAIL  "), $what, "\n";
}
function one(PDO $pdo, string $sql): mixed
{
    return $pdo->query($sql)->fetchColumn();
}

$firefox = 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
$server = fn (string $ua, string $ip = '203.0.113.7', array $extra = []) => $extra + ['HTTP_USER_AGENT' => $ua, 'REMOTE_ADDR' => $ip, 'HTTP_ORIGIN' => 'https://example.com'];
$pv = fn (string $url, string $key, string $ref = '', int $w = 1920) => ['n' => 'pageview', 'd' => 'example.com', 'u' => $url, 'r' => $ref, 't' => 'Title', 'w' => $w, 'k' => $key];
$key = fn (string $c) => str_repeat($c, 16);

echo "Collecting\n";
check('first page view is stored', ls_collect($pdo, $pv('https://example.com/?email=a@b.c&utm_source=x&utm_campaign=launch', $key('a'), 'https://t.co/abc'), $server($firefox), $config) === 'ok');
$v = $pdo->query("SELECT * FROM visits")->fetch();
check('one visit, one page view', one($pdo, "SELECT COUNT(*) FROM visits") == 1 && one($pdo, "SELECT COUNT(*) FROM pageviews") == 1);
check('campaign tags win over the referrer', $v['source'] === 'campaign' && $v['utm_source'] === 'x' && $v['utm_campaign'] === 'launch');
check('referrer host kept, tidy', $v['referrer_host'] === 't.co');
check('query string never stored', one($pdo, "SELECT path FROM pageviews") === '/' && !str_contains(json_encode($pdo->query("SELECT * FROM visits")->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE), 'a@b.c'));
check('country from the local database', $v['country'] === 'US');
check('browser, system and device', $v['browser'] === 'Firefox' && $v['os'] === 'Linux' && $v['device'] === 'desktop');
check('no IP address stored anywhere', !str_contains(json_encode(array_merge($pdo->query("SELECT * FROM visits")->fetchAll(), $pdo->query("SELECT * FROM pageviews")->fetchAll()), JSON_INVALID_UTF8_SUBSTITUTE), '203.0.113'));
check('visitor is an 8-byte hash', strlen($v['visitor']) === 8);

ls_collect($pdo, $pv('https://www.example.com/pricing', $key('b'), 'https://example.com/'), $server($firefox), $config);
check('second page joins the same visit', one($pdo, "SELECT COUNT(*) FROM visits") == 1 && one($pdo, "SELECT pageviews FROM visits") == 2);
check('exit page follows along', one($pdo, "SELECT exit_path FROM visits") === '/pricing');

check('leaving adds time on the page', ls_collect($pdo, ['n' => 'leave', 'd' => 'example.com', 'u' => 'https://example.com/pricing', 'k' => $key('b'), 's' => 42], $server($firefox), $config) === 'ok'
    && one($pdo, "SELECT seconds FROM pageviews WHERE page_key = '" . $key('b') . "'") == 42 && one($pdo, "SELECT duration FROM visits") == 42);
ls_collect($pdo, ['n' => 'leave', 'd' => 'example.com', 'u' => 'https://example.com/pricing', 'k' => $key('b'), 's' => 30], $server($firefox), $config);
check('a repeated, smaller leave never lowers the time', one($pdo, "SELECT duration FROM visits") == 42);
ls_collect($pdo, ['n' => 'leave', 'd' => 'example.com', 'u' => 'https://example.com/pricing', 'k' => $key('b'), 's' => 99999], $server($firefox), $config);
check('time on one page is capped at 30 minutes', one($pdo, "SELECT duration FROM visits") == 1800);

check('events are stored', ls_collect($pdo, ['n' => 'event', 'd' => 'example.com', 'u' => 'https://example.com/cart', 'e' => 'Built an order'], $server($firefox), $config) === 'ok'
    && one($pdo, "SELECT name FROM events") === 'Built an order');

ls_collect($pdo, $pv('https://example.com/', $key('c'), '', 390), $server($iphone, '203.0.113.8'), $config);
check('another person makes another visit', one($pdo, "SELECT COUNT(*) FROM visits") == 2);
check('phone detected', one($pdo, "SELECT device FROM visits ORDER BY id DESC LIMIT 1") === 'phone');

echo "Refusing\n";
$before = one($pdo, "SELECT COUNT(*) FROM pageviews");
check('Do Not Track is honoured', ls_collect($pdo, $pv('https://example.com/', $key('d')), $server($firefox, '203.0.113.9', ['HTTP_DNT' => '1']), $config) === 'ignored: do not track');
check('Global Privacy Control is honoured', ls_collect($pdo, $pv('https://example.com/', $key('d')), $server($firefox, '203.0.113.9', ['HTTP_SEC_GPC' => '1']), $config) === 'ignored: do not track');
check('bots are dropped', ls_collect($pdo, $pv('https://example.com/', $key('d')), $server('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'), $config) === 'ignored: bot');
check('headless browsers are dropped', ls_collect($pdo, $pv('https://example.com/', $key('d')), $server('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'), $config) === 'ignored: bot');
check('unknown sites are refused', ls_collect($pdo, ['d' => 'nope.net'] + $pv('https://nope.net/', $key('d')), $server($firefox), $config) === 'ignored: unknown site');
check('pages from another domain are refused', ls_collect($pdo, $pv('https://evil.test/', $key('d')), $server($firefox), $config) === 'ignored: page not on this site');
check('hits sent from another site are refused', ls_collect($pdo, $pv('https://example.com/', $key('d')), $server($firefox, '203.0.113.9', ['HTTP_ORIGIN' => 'https://evil.test']), $config) === 'ignored: sent from another site');
check('page views need their random id', ls_collect($pdo, $pv('https://example.com/', 'bad'), $server($firefox), $config) === 'ignored: page view without an id');
check('nothing refused was stored', one($pdo, "SELECT COUNT(*) FROM pageviews") == $before);

echo "Privacy over time\n";
$hashToday = ls_visitor_hash(ls_salt($pdo), 1, '203.0.113.7', $firefox);
$pdo->exec("UPDATE salts SET day = DATE_SUB(day, INTERVAL 1 DAY)");  // pretend today's secret is yesterday's
$tomorrowSalt = ls_salt($pdo, gmdate('Y-m-d', time() + 86400));
check('a new day gives the same person a different code', ls_visitor_hash($tomorrowSalt, 1, '203.0.113.7', $firefox) !== $hashToday);
check('old secrets are deleted', ls_salt_prune($pdo) >= 1 && one($pdo, "SELECT COUNT(*) FROM salts WHERE day < UTC_DATE()") == 0);

echo "Monthly totals and retention\n";
$pdo->exec("UPDATE visits SET day = '2025-01-15'");
$pdo->exec("UPDATE pageviews SET day = '2025-01-15'");
$pdo->exec("UPDATE events SET day = '2025-01-15'");
$log = ls_maintain($pdo, $config);
$t = $pdo->query("SELECT * FROM monthly_totals WHERE site_id = 1 AND month = '2025-01-01'")->fetch();
check('finished month is totalled', $t && $t['visits'] == 2 && $t['pageviews'] == 3 && $t['visitors'] == 2 && $t['bounces'] == 1);
check('top pages kept for the month', one($pdo, "SELECT hits FROM monthly_top WHERE month = '2025-01-01' AND dimension = 'page' AND value = '/'") == 2);
check('top events kept for the month', one($pdo, "SELECT hits FROM monthly_top WHERE month = '2025-01-01' AND dimension = 'event' AND value = 'Built an order'") == 1);
check('raw visits past 13 months deleted', one($pdo, "SELECT COUNT(*) FROM visits") == 0 && one($pdo, "SELECT COUNT(*) FROM pageviews") == 0 && one($pdo, "SELECT COUNT(*) FROM events") == 0);
check('monthly totals survive the deletion', one($pdo, "SELECT COUNT(*) FROM monthly_totals") == 1);

echo "Helpers\n";
check('Gmail is email, not Google search', ls_known_referrer('mail.google.com')[0] === 'Gmail');
check('google.co.uk is Google search', ls_known_referrer('google.co.uk') === ['Google', 'search']);
check('moving around the site is not a source', ls_classify_source('https://example.com/a', 'https://example.com/b', 'example.com')[0] === 'direct');
check('Brave is recognised when the script says so', ls_parse_ua('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 1920, true)[0] === 'Brave');
check('Android tablet', ls_parse_ua('Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 1280)[2] === 'tablet');
check('proxy header ignored unless the proxy is trusted', ls_client_ip(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], []) === '198.51.100.1'
    && ls_client_ip(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], ['198.51.100.1']) === '203.0.113.5');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
