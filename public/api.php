<?php
// JSON data for dashboards.
//   Logged-in dashboard users:  api.php?live&site=example.com   (the live counter; not when 'dashboard' => false)
//   Other apps, with a key made by bin/apikey.php, sent as "Authorization: Bearer <key>":
//     api.php?report=sites
//     api.php?report=overview&site=example.com&range=30d            (everything on the Overview)
//     api.php?report=top&site=example.com&dim=page&limit=100&range=… (one full list)
//     api.php?report=live&site=example.com
//   range: today, 7d, 30d, 90d, 12m, or custom with from=YYYY-MM-DD&to=YYYY-MM-DD.
// A key only ever sees the websites it was made for.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/collect.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ls_api_out(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = ls_db();

// Who's asking: a key (apps) or a logged-in dashboard session. Returns the allowed domains, or null for all.
$allowed = null;
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('~^Bearer\s+(ls_[a-f0-9]{48})$~', $auth, $m)) {
    $stmt = $pdo->prepare("SELECT id, sites FROM api_keys WHERE key_hash = :h");
    $stmt->execute(['h' => hash('sha256', $m[1], true)]);
    $key = $stmt->fetch();
    if (!$key) {
        ls_api_out(401, ['error' => 'unknown key']);
    }
    $pdo->prepare("UPDATE api_keys SET last_used = :t WHERE id = :i")->execute(['t' => ls_now(), 'i' => $key['id']]);
    $allowed = $key['sites'] === '*' ? null : explode(',', $key['sites']);
} elseif (ls_user()) {
    session_write_close();
} else {
    ls_api_out(403, ['error' => 'not logged in']);
}

$sites = array_values(array_filter(
    $pdo->query("SELECT id, domain, name, timezone FROM sites ORDER BY domain")->fetchAll(),
    fn ($s) => $allowed === null || in_array($s['domain'], $allowed, true)
));
$report = isset($_GET['live']) ? 'live' : (string) ($_GET['report'] ?? '');
if ($report === 'sites') {
    ls_api_out(200, ['sites' => array_map(fn ($s) => ['domain' => $s['domain'], 'name' => $s['name'], 'timezone' => $s['timezone']], $sites)]);
}

$site = null;
foreach ($sites as $s) {
    if ($s['domain'] === ($_GET['site'] ?? '')) {
        $site = $s;
    }
}
if (!$site) {
    ls_api_out(404, ['error' => 'no such site, or not allowed']);
}
$siteId = (int) $site['id'];
if ($report === 'live') {
    ls_api_out(200, ls_report_live($pdo, $siteId));
}

[$from, $to, $label, $prevFrom, $prevTo] = ls_range((string) ($_GET['range'] ?? '30d'), $site['timezone'], (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$period = ['from' => $from, 'to' => $to, 'label' => $label, 'previous_from' => $prevFrom, 'previous_to' => $prevTo];
$rows = fn (array $list) => array_map(fn ($r) => ['value' => $r[0], 'label' => $r[1], 'visitors' => $r[2], 'count' => $r[3]], $list);

if ($report === 'top') {
    $dim = (string) ($_GET['dim'] ?? '');
    $limit = max(1, min(1000, (int) ($_GET['limit'] ?? 10)));
    ls_api_out(200, ['site' => $site['domain'], 'period' => $period, 'dim' => $dim, 'rows' => $rows(ls_report_top($pdo, $siteId, $from, $to, $dim, $limit))]);
}
if ($report === 'overview') {
    $top = fn (string $dim, int $n) => $rows(ls_report_top($pdo, $siteId, $from, $to, $dim, $n));
    ls_api_out(200, [
        'site' => ['domain' => $site['domain'], 'name' => $site['name'], 'timezone' => $site['timezone']],
        'period' => $period,
        'summary' => ls_report_summary($pdo, $siteId, $from, $to),
        'previous' => ls_report_summary($pdo, $siteId, $prevFrom, $prevTo),
        'series' => array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'visitors' => $r[2], 'pageviews' => $r[3]], ls_report_series($pdo, $siteId, $from, $to)),
        'funnel' => array_map(fn ($f) => ['name' => $f[0], 'visits' => $f[1], 'percent_of_previous' => $f[2]], ls_report_funnel($pdo, $siteId, $from, $to)),
        'live' => ls_report_live($pdo, $siteId),
        'top' => ['page' => $top('page', 8), 'entry' => $top('entry', 5), 'source' => $top('source', 8), 'referrer' => $top('referrer', 8),
            'campaign' => $top('campaign', 5), 'country' => $top('country', 8), 'device' => $top('device', 4), 'browser' => $top('browser', 6),
            'os' => $top('os', 6), 'event' => $top('event', 8)],
    ]);
}
ls_api_out(400, ['error' => 'unknown report']);
