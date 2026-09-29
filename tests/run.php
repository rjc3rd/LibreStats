<?php
// LibreStats checks. Uses a separate database (config db name + "_test"), wiped on every run.
//   php tests/run.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
// Output is held back until the end, so login sessions and CSV headers can be tested like on a page.
ob_start();
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/collect.php';
require __DIR__ . '/../lib/maintain.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/admin.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/install.php';
require __DIR__ . '/../lib/team.php';

$config = ls_config();
$db = $config['db'];
$db['name'] .= '_test';
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4", $db['user'], $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET time_zone = '+00:00'");
foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
ls_install_schema($pdo);
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

echo "Reports\n";
$today = ls_site_day('America/Chicago');
$sum = ls_report_summary($pdo, 1, $today, $today);
check('summary counts visitors, visits and page views', $sum['visitors'] === 2 && $sum['visits'] === 2 && $sum['pageviews'] === 3);
check('bounce rate: one of two visits saw one page', $sum['bounce_rate'] == 50);
$top = ls_report_top($pdo, 1, $today, $today, 'page');
check('top pages, most visited first', $top[0][0] === '/' && $top[0][3] === 2);
check('sources get friendly names', ls_report_top($pdo, 1, $today, $today, 'source')[0][1] !== '');
$series = ls_report_series($pdo, 1, date('Y-m-d', strtotime("$today -6 days")), $today);
check('series fills in quiet days with zero', count($series) === 7 && $series[0][2] === 0 && end($series)[2] === 2);
$pdo->exec("INSERT INTO goals (site_id, name, kind, target, position) VALUES (1, 'Pricing', 'path', '/pricing', 1), (1, 'Order', 'event', 'Built an order', 2), (1, 'Blog', 'path', '/blog/*', 3)");
$funnel = ls_report_funnel($pdo, 1, $today, $today);
check('funnel: each step counts visits that reached it and the steps before', $funnel[0][1] === 1 && $funnel[1][1] === 1 && $funnel[1][2] === 100 && $funnel[2][1] === 0);
[$f, $t] = ls_range('7d', 'UTC');
check('a 7-day range is 7 days ending today', $t === ls_site_day('UTC') && (strtotime($t) - strtotime($f)) / 86400 === 6);
[$f, $t] = ls_range('custom', 'UTC', '2025-03-10', '2025-03-01');
check('custom range dates are put in order', $f === '2025-03-01' && $t === '2025-03-10');
check('live count sees people from the last five minutes', ls_report_live($pdo, 1)['visitors'] === 2);

echo "Settings\n";
check('a pasted address becomes a plain domain', ls_clean_domain(' https://WWW.Example.org/some/page ') === 'example.org');
check('adding a site checks the domain', ls_site_add($pdo, 'not a domain', 'UTC') !== null && ls_site_add($pdo, 'new.example', 'Nowhere/Zone') !== null);
check('adding a site works, once', ls_site_add($pdo, 'new.example', 'Europe/London') === null && ls_site_add($pdo, 'new.example', 'UTC') !== null);
$nid = (int) one($pdo, "SELECT id FROM sites WHERE domain = 'new.example'");
ls_goal_add($pdo, $nid, 'A', 'path', '/a'); ls_goal_add($pdo, $nid, 'B', 'event', 'B'); ls_goal_add($pdo, $nid, 'C', 'path', '/c/*');
check('page goals must start with /', ls_goal_add($pdo, $nid, 'D', 'path', 'd') !== null);
ls_goal_move($pdo, $nid, (int) one($pdo, "SELECT id FROM goals WHERE site_id = $nid AND name = 'C'"), -1);
check('goals move in the funnel', implode('', array_column(ls_goals($pdo, $nid), 'name')) === 'ACB');
ls_goal_move($pdo, $nid, (int) one($pdo, "SELECT id FROM goals WHERE site_id = $nid AND name = 'A'"), -1);
check('the first goal can\'t move earlier', implode('', array_column(ls_goals($pdo, $nid), 'name')) === 'ACB');
ls_site_remove($pdo, $nid);
check('deleting a site deletes its goals too', one($pdo, "SELECT COUNT(*) FROM goals WHERE site_id = $nid") == 0 && one($pdo, "SELECT COUNT(*) FROM sites WHERE id = $nid") == 0);
check('logins need a real email and a long password', ls_user_add($pdo, 'x', 'long-enough-1') !== null && ls_user_add($pdo, 'a@b.test', 'short') !== null);
check('adding a login works', ls_user_add($pdo, 'a@b.test', 'long-enough-1') === null);
check('a plain username works as a login too', ls_user_add($pdo, 'ranzy', 'long-enough-1') === null && ls_user_add($pdo, 'x', 'long-enough-1') !== null && ls_user_add($pdo, 'bad name!', 'long-enough-1') !== null);
$uid = (int) one($pdo, "SELECT id FROM users WHERE email = 'a@b.test'");
check('changing a password needs the current one', ls_user_password($pdo, $uid, 'wrong', 'another-long-1') !== null && ls_user_password($pdo, $uid, 'long-enough-1', 'another-long-1') === null);
check('login works with the new password', ls_login($pdo, 'a@b.test', 'another-long-1', '203.0.113.50') === null);
for ($i = 0; $i < 8; $i++) { ls_login($pdo, 'a@b.test', 'wrong', '203.0.113.51'); }
check('too many wrong passwords pause logins from that address', str_starts_with((string) ls_login($pdo, 'a@b.test', 'another-long-1', '203.0.113.51'), 'Too many'));
check('csv cells that look like formulas are made safe', (function () { ob_start(); ls_send_csv('t.csv', [['=cmd()', 'x', 1, 1]], 'n'); return str_contains(ob_get_clean(), "'=cmd()"); })());

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
$old = ls_report_summary($pdo, 1, '2025-01-01', '2025-01-31');
check('reports still show a deleted month from its kept totals', $old['visits'] === 2 && $old['pageviews'] === 3);
check('and its kept top pages', (ls_report_top($pdo, 1, '2024-12-01', '2025-02-28', 'page')[0][3] ?? 0) === 2);

echo "Helpers\n";
check('Gmail is email, not Google search', ls_known_referrer('mail.google.com')[0] === 'Gmail');
check('google.co.uk is Google search', ls_known_referrer('google.co.uk') === ['Google', 'search']);
check('moving around the site is not a source', ls_classify_source('https://example.com/a', 'https://example.com/b', 'example.com')[0] === 'direct');
check('Brave is recognised when the script says so', ls_parse_ua('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 1920, true)[0] === 'Brave');
check('Android tablet', ls_parse_ua('Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', 1280)[2] === 'tablet');
check('proxy header ignored unless the proxy is trusted', ls_client_ip(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], []) === '198.51.100.1'
    && ls_client_ip(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], ['198.51.100.1']) === '203.0.113.5');

echo "\nDashboard switch\n";
require_once __DIR__ . '/../lib/theme.php';
check('the dashboard is on unless the settings say otherwise', ls_dashboard_enabled([]) === true && ls_dashboard_enabled(['dashboard' => true]) === true);
check("'dashboard' => false turns it off", ls_dashboard_enabled(['dashboard' => false]) === false);
check("'off', 'no' and 0 turn it off too", ls_dashboard_enabled(['dashboard' => 'off']) === false && ls_dashboard_enabled(['dashboard' => 'no']) === false && ls_dashboard_enabled(['dashboard' => 0]) === false);
check('an unreadable value leaves it on', ls_dashboard_enabled(['dashboard' => 'banana']) === true);
check('whatever theme is active has a closed page (the default theme fills in)', ls_theme_file('templates', 'closed.php') !== null);
ob_start();
ls_render('closed');
$closedActive = (string) ob_get_clean();
ob_start();
include LS_ROOT . '/themes/default/templates/closed.php';
$closedDefault = (string) ob_get_clean();
check('the default closed page says the dashboard is turned off', str_contains($closedDefault, 'turned off'));
check('no closed page has a form or a password field', !str_contains($closedActive . $closedDefault, '<form') && !str_contains($closedActive . $closedDefault, 'password'));

echo "\nTeams of viewers\n";
require_once __DIR__ . '/../lib/theme.php';
$pdo->exec("INSERT INTO sites (domain, name, timezone) VALUES ('third.net', 'Third', 'UTC')");
$key = ['id' => 1, 'sites' => '*', 'team' => 1];
$api = fn (array $input, array $k = null, ?int $limit = 5) => ls_team_api($pdo, $k ?? $key, $input, $limit);
check('a viewer is made by the person who leads the team, with their own username and password',
    ls_team_add($pdo, '7', 'example.com,other.org', 'Sam', 'sams-password-1') === null
    && one($pdo, "SELECT role FROM users WHERE email = 'sam'") === 'viewer' && one($pdo, "SELECT team FROM users WHERE email = 'sam'") === '7');
check('the username is kept in lower case and can log in', ls_login($pdo, 'SAM', 'sams-password-1', '203.0.113.60') === null && ls_user($pdo)['email'] === 'sam');
check('the password is only kept as a hash', !str_contains(json_encode($pdo->query("SELECT * FROM users")->fetchAll()), 'sams-password-1'));
check('a viewer sees only the websites shared with them', implode(',', array_column(ls_user_sites($pdo, ls_user($pdo)), 'domain')) === 'example.com,other.org');
check('an admin sees every website', count(ls_user_sites($pdo, ['role' => 'admin'])) === 3);
check('a login without a role is an admin (older sessions)', ls_is_admin(['email' => 'x']) && !ls_is_admin(['role' => 'viewer']));
check('taken usernames, bad usernames and short passwords are refused',
    ls_team_add($pdo, '7', '*', 'sam', 'another-password-1') !== null && ls_team_add($pdo, '7', '*', 'bad name!', 'another-password-1') !== null
    && ls_team_add($pdo, '7', '*', 'tina', 'short') !== null);
check('usernames are unique across teams', ls_team_add($pdo, '8', '*', 'sam', 'another-password-1') !== null);
check('a team has a limit on its places', ls_team_add($pdo, '7', '*', 'tina', 'tinas-password-1', 2) === null && str_contains((string) ls_team_add($pdo, '7', '*', 'uma', 'umas-password-1', 2), 'no free places'));
check('no limit when the limit is null', ls_team_add($pdo, '7', '*', 'vic', 'vics-password-1', null) === null);
check('one team\'s places don\'t count against another', ls_team_add($pdo, '8', '*', 'wes', 'wess-password-1', 2) === null);
$sam = (int) one($pdo, "SELECT id FROM users WHERE email = 'sam'");
$wes = (int) one($pdo, "SELECT id FROM users WHERE email = 'wes'");
$list = ls_team_list($pdo, '7');
check('a team lists its own people only', array_column($list['members'], 'email') === ['sam', 'tina', 'vic'] && $list['used'] === 3);
check('one team can\'t set another team\'s password', ls_team_password($pdo, '7', $wes, 'hijacked-password-1') !== null && password_verify('wess-password-1', (string) one($pdo, "SELECT password_hash FROM users WHERE id = $wes")));
check('the leader sets a new password, which then works and the old one stops', ls_team_password($pdo, '7', $sam, 'sams-new-password-1') === null
    && ls_login($pdo, 'sam', 'sams-password-1', '203.0.113.61') !== null && ls_login($pdo, 'sam', 'sams-new-password-1', '203.0.113.62') === null);
check('a new password has to be 10 to 72 characters', ls_team_password($pdo, '7', $sam, 'short') !== null && ls_team_password($pdo, '7', $sam, str_repeat('x', 73)) !== null && ls_user_add($pdo, 'longpass', str_repeat('x', 73)) !== null);
check('an admin login can\'t be reached through a team', ls_team_password($pdo, '', (int) one($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1"), 'hijacked-password-1') !== null);
ls_team_set_sites($pdo, '7', 'third.net');
check('changing a team\'s websites changes what its people see, at once', implode(',', array_column(ls_user_sites($pdo, ls_user($pdo)), 'domain')) === 'third.net');
check('and only for that team', one($pdo, "SELECT sites FROM users WHERE email = 'wes'") === '*');
$_SESSION['ls_user'] = ['id' => $wes];
check('a session is checked against the database every time', ls_user($pdo)['email'] === 'wes' && ls_team_remove($pdo, '8', $wes) === null && ls_user($pdo) === null);
check('after removal the session is empty but still has a form token', is_string($_SESSION['ls_csrf'] ?? null) && $_SESSION['ls_csrf'] !== '');
check('a removed person\'s login is gone', one($pdo, "SELECT COUNT(*) FROM users WHERE email = 'wes'") == 0 && ls_login($pdo, 'wes', 'wess-password-1', '203.0.113.63') !== null);
check('a team can\'t remove somebody from another team or an admin', ls_team_remove($pdo, '8', $sam) !== null && ls_team_remove($pdo, '7', (int) one($pdo, "SELECT id FROM users WHERE role = 'admin' LIMIT 1")) !== null);

echo "Which websites a scope allows\n";
check('* means every website', ls_scope_list('*') === null && ls_scope_allows('*', 'anything.example'));
check('a list is comma-separated domains, in lower case', ls_scope_list('Example.com, other.org,') === ['example.com', 'other.org']);
check('an empty scope allows nothing', ls_scope_list('') === [] && !ls_scope_allows('', 'example.com'));
check('a key can only share what it can read', ls_scope_narrow('example.com,other.org', ['other.org', 'third.net', 'EXAMPLE.com']) === 'other.org,example.com');
check('a key that reads everything can share everything, or a list', ls_scope_narrow('*', '*') === '*' && ls_scope_narrow('*', ['https://www.Third.net/x']) === 'third.net');
check('"*" from a limited key is just what the key reads', ls_scope_narrow('example.com', '*') === 'example.com');
check('junk in a list is dropped, and "*" inside a list is not everything', ls_scope_narrow('*', ['not a domain', 5, '*', 'good.org']) === 'good.org');
check('a list that is too long for a scope is refused', ls_scope_narrow('*', array_map(fn ($i) => "site$i-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.example", range(1, 60))) === null);

echo "The team API\n";
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'zed', 'password' => 'zeds-password-1', 'sites' => ['example.com']]);
check('team.create makes a viewer', $st === 200 && $out['ok'] && one($pdo, "SELECT team FROM users WHERE email = 'zed'") === '9' && one($pdo, "SELECT sites FROM users WHERE email = 'zed'") === 'example.com');
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'yan', 'password' => 'yans-password-1']);
check('the websites have to be named, so leaving them out never shares everything', $st === 400 && !$out['ok'] && one($pdo, "SELECT COUNT(*) FROM users WHERE email = 'yan'") == 0);
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'yan', 'password' => 'yans-password-1', 'sites' => 'example.com']);
check('a string other than * isn\'t a list of websites', $st === 400 && one($pdo, "SELECT COUNT(*) FROM users WHERE email = 'yan'") == 0);
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'yan', 'password' => 'yans-password-1', 'sites' => ['third.net']], ['id' => 2, 'sites' => 'example.com', 'team' => 1]);
check('a key limited to some websites can\'t share others', $st === 200 && one($pdo, "SELECT sites FROM users WHERE email = 'yan'") === '');
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'zed', 'password' => 'zeds-password-1', 'sites' => '*']);
check('a taken username answers 409 with a reason', $st === 409 && !$out['ok'] && str_contains($out['error'], 'already taken'));
[$st, $out] = $api(['op' => 'team.create', 'acct' => '9', 'username' => 'xia', 'password' => 'xias-password-1', 'sites' => '*'], null, 2);
check('a full team answers 409', $st === 409 && str_contains($out['error'], 'no free places'));
[$st, $out] = $api(['op' => 'team.list', 'acct' => '9']);
check('team.list shows the team, its places and each person\'s websites and join date',
    $st === 200 && count($out['members']) === 2 && $out['members'][0]['username'] === 'zed' && $out['members'][0]['sites'] === ['example.com']
    && $out['seats'] === ['used' => 2, 'limit' => 5] && preg_match('~^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$~', $out['members'][0]['joined']) === 1);
check('team.list never shows a password or a hash', !str_contains(json_encode($out), 'password') && !str_contains(json_encode($out), '$2y$'));
$zed = (int) $out['members'][0]['id'];
[$st] = $api(['op' => 'team.password', 'acct' => '9', 'member' => $zed, 'password' => 'zeds-new-password-1']);
check('team.password sets a new password', $st === 200 && password_verify('zeds-new-password-1', (string) one($pdo, "SELECT password_hash FROM users WHERE id = $zed")));
[$st] = $api(['op' => 'team.password', 'acct' => '10', 'member' => $zed, 'password' => 'hijacked-password-1']);
check('team.password only reaches the named team', $st === 409 && password_verify('zeds-new-password-1', (string) one($pdo, "SELECT password_hash FROM users WHERE id = $zed")));
[$st] = $api(['op' => 'team.remove', 'acct' => '10', 'member' => $zed]);
check('team.remove only reaches the named team', $st === 409 && one($pdo, "SELECT COUNT(*) FROM users WHERE id = $zed") == 1);
[$st] = $api(['op' => 'team.sites', 'acct' => '9', 'sites' => ['third.net']]);
check('team.sites changes the team\'s websites', $st === 200 && one($pdo, "SELECT sites FROM users WHERE id = $zed") === 'third.net' && one($pdo, "SELECT sites FROM users WHERE email = 'sam'") === 'third.net');
[$st] = $api(['op' => 'team.remove', 'acct' => '9', 'member' => $zed]);
check('team.remove deletes the login', $st === 200 && one($pdo, "SELECT COUNT(*) FROM users WHERE id = $zed") == 0);
check('a team id has to be short and plain, and every request names one', $api(['op' => 'team.list'])[0] === 400 && $api(['op' => 'team.list', 'acct' => 'a b'])[0] === 400
    && $api(['op' => 'team.list', 'acct' => str_repeat('a', 65)])[0] === 400 && $api(['op' => 'team.list', 'acct' => ['7']])[0] === 400);
check('an unknown operation is refused', $api(['op' => 'team.destroy', 'acct' => '9'])[0] === 400);

echo "Upgrading an older database\n";
foreach (['users', 'api_keys'] as $t) {
    $pdo->exec("DROP TABLE `$t`");
}
$pdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL AUTO_INCREMENT, email VARCHAR(254) NOT NULL, password_hash VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY uq_users_email (email)) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE api_keys (id INT UNSIGNED NOT NULL AUTO_INCREMENT, key_hash BINARY(32) NOT NULL, label VARCHAR(100) NOT NULL, sites TEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_used DATETIME NULL, PRIMARY KEY (id), UNIQUE KEY uq_api_keys_hash (key_hash)) ENGINE=InnoDB");
$pdo->exec("INSERT INTO users (email, password_hash) VALUES ('old@admin.test', 'x')");
$pdo->exec("INSERT INTO api_keys (key_hash, label, sites) VALUES (UNHEX(SHA2('k', 256)), 'Old key', '*')");
$changes = ls_install_schema($pdo);
check('missing columns are added and reported', count($changes) === 4 && in_array('users.role added', $changes, true) && in_array('api_keys.team added', $changes, true));
check('an existing login stays an admin who sees every website', one($pdo, "SELECT role FROM users WHERE email = 'old@admin.test'") === 'admin' && one($pdo, "SELECT sites FROM users WHERE email = 'old@admin.test'") === '*');
check('an existing key gets no team access', one($pdo, "SELECT team FROM api_keys WHERE label = 'Old key'") == 0);
check('running it again changes nothing', ls_install_schema($pdo) === []);

echo "First run and team settings\n";
check('the first-run page is on unless the settings say otherwise', ls_first_run_enabled([]) === true && ls_first_run_enabled(['first_run' => false]) === false && ls_first_run_enabled(['first_run' => 'off']) === false);
check('a team has 5 places unless the settings say otherwise, and 0 means no limit', ls_team_limit([]) === 5 && ls_team_limit(['team_limit' => 8]) === 8 && ls_team_limit(['team_limit' => 0]) === null);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
