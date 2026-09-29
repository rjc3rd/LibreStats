<?php
// The default dashboard: first-run setup, login, and the numbers for one site and date range.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/charts.php';
require __DIR__ . '/../lib/theme.php';
require __DIR__ . '/../lib/collect.php';
require __DIR__ . '/../lib/admin.php';

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

// Switched off with 'dashboard' => false in config.php: no login and no setup page here. Tracking
// and the data API keep working. (404: there is nothing to log in to.)
if (!ls_dashboard_enabled()) {
    http_response_code(404);
    ls_render('closed');
    exit;
}

$pdo = ls_db();
$error = null;
$action = $_POST['action'] ?? '';

// First run: no logins yet, so the first visitor creates the owner's login here.
$noUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0;
if ($noUsers) {
    if ($action === 'setup' && ls_csrf_ok()) {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!ls_valid_login($email)) {
            $error = 'Please enter an email address, or a username of 3 to 64 letters, digits, dots, dashes or underscores.';
        } elseif (strlen($password) < 10) {
            $error = 'Please use a password of 10 characters or more.';
        } elseif ($password !== (string) ($_POST['password2'] ?? '')) {
            $error = 'The two passwords don’t match.';
        } else {
            $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (:e, :h)")->execute(['e' => $email, 'h' => password_hash($password, PASSWORD_DEFAULT)]);
            ls_login($pdo, $email, $password, ls_client_ip($_SERVER, ls_config()['trusted_proxies'] ?? []));
            header('Location: ./', true, 303);
            exit;
        }
    }
    ls_render('setup', ['error' => $error, 'csrf' => ls_csrf()]);
    exit;
}

if ($action === 'login' && ls_csrf_ok()) {
    $error = ls_login($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''), ls_client_ip($_SERVER, ls_config()['trusted_proxies'] ?? []));
    if ($error === null) {
        header('Location: ./', true, 303);
        exit;
    }
}
if ($action === 'logout' && ls_csrf_ok()) {
    ls_logout();
    header('Location: ./', true, 303);
    exit;
}
$user = ls_user();
if (!$user) {
    ls_render('login', ['error' => $error, 'csrf' => ls_csrf()]);
    exit;
}

// Logged in: pick the site and range.
const LS_VIEWS = ['overview' => 'Overview', 'pages' => 'Pages', 'sources' => 'Sources', 'locations' => 'Locations',
    'devices' => 'Devices', 'events' => 'Events & goals', 'settings' => 'Settings'];

$sites = $pdo->query("SELECT id, domain, name, timezone FROM sites ORDER BY domain")->fetchAll();
if (!$sites && $action === 'site_add' && ls_csrf_ok()) {
    $error = ls_site_add($pdo, (string) ($_POST['domain'] ?? ''), (string) ($_POST['timezone'] ?? 'UTC'));
    if ($error === null) {
        header('Location: ./?site=' . rawurlencode(ls_clean_domain((string) $_POST['domain'])), true, 303);
        exit;
    }
}
if (!$sites) {
    ls_render('no-sites', ['user' => $user, 'csrf' => ls_csrf(), 'error' => $error]);
    exit;
}
$site = $sites[0];
foreach ($sites as $s) {
    if ($s['domain'] === ($_GET['site'] ?? $_POST['site'] ?? '')) {
        $site = $s;
    }
}
$siteId = (int) $site['id'];
$view = isset(LS_VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'overview';

// Settings forms. Each one redirects back with a short message (or the problem) to show once.
if ($action !== '' && $action !== 'login' && $action !== 'logout') {
    if (!ls_csrf_ok()) {
        $_SESSION['ls_flash'] = ['error', 'That form had expired. Please try again.'];
    } else {
        $back = ['site' => $site['domain'], 'view' => 'settings'];
        $problem = null;
        $done = 'Saved.';
        switch ($action) {
            case 'site_update':
                $problem = ls_site_update($pdo, $siteId, (string) ($_POST['name'] ?? ''), (string) ($_POST['timezone'] ?? ''));
                break;
            case 'site_add':
                $problem = ls_site_add($pdo, (string) ($_POST['domain'] ?? ''), (string) ($_POST['timezone'] ?? 'UTC'));
                $back['site'] = ls_clean_domain((string) ($_POST['domain'] ?? ''));
                $done = 'Website added. Put its tracking code on its pages to start counting.';
                break;
            case 'site_remove':
                if (ls_clean_domain((string) ($_POST['confirm'] ?? '')) !== $site['domain']) {
                    $problem = 'To delete ' . $site['domain'] . ', type its domain exactly.';
                } else {
                    ls_site_remove($pdo, $siteId);
                    unset($back['site']);
                    $done = $site['domain'] . ' and all its numbers were deleted.';
                }
                break;
            case 'goal_add':
                $problem = ls_goal_add($pdo, $siteId, (string) ($_POST['name'] ?? ''), (string) ($_POST['kind'] ?? ''), (string) ($_POST['target'] ?? ''));
                $done = 'Goal added.';
                break;
            case 'goal_remove':
                ls_goal_remove($pdo, $siteId, (int) ($_POST['goal'] ?? 0));
                $done = 'Goal removed.';
                break;
            case 'goal_up':
            case 'goal_down':
                ls_goal_move($pdo, $siteId, (int) ($_POST['goal'] ?? 0), $action === 'goal_up' ? -1 : 1);
                $done = 'Order changed.';
                break;
            case 'password':
                $problem = ls_user_password($pdo, $user['id'], (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''));
                $done = 'Password changed.';
                break;
            case 'user_add':
                $problem = ls_user_add($pdo, (string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
                $done = 'Login added. Give them the email and password you chose.';
                break;
            default:
                $problem = 'Unknown request.';
        }
        $_SESSION['ls_flash'] = $problem ? ['error', $problem] : ['ok', $done];
    }
    header('Location: ./?' . http_build_query($back ?? ['site' => $site['domain'], 'view' => 'settings']), true, 303);
    exit;
}
$flash = $_SESSION['ls_flash'] ?? null;
unset($_SESSION['ls_flash']);

$range = (string) ($_GET['range'] ?? '30d');
[$from, $to, $label, $prevFrom, $prevTo] = ls_range($range, $site['timezone'], (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$range = isset(LS_RANGES[$range]) || $range === 'custom' ? $range : '30d';
$top = fn (string $dim, int $limit = 8) => ls_report_top($pdo, $siteId, $from, $to, $dim, $limit);

// CSV download of one list: ?export=<list>
if (isset($_GET['export'])) {
    $dim = (string) $_GET['export'];
    $rows = $top($dim, 10000);
    ls_send_csv("{$site['domain']}-" . str_replace(':', '-', $dim) . "-$from-to-$to.csv", $rows, $dim === 'page' ? 'views' : 'count');
    exit;
}

$common = ['user' => $user, 'csrf' => ls_csrf(), 'sites' => $sites, 'site' => $site, 'range' => $range, 'label' => $label,
    'from' => $from, 'to' => $to, 'view' => $view, 'views' => LS_VIEWS, 'flash' => $flash, 'live' => ls_report_live($pdo, $siteId),
    'scriptUrl' => (isset($_SERVER['HTTP_HOST']) ? (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '') . '/s.js'];

if ($view === 'settings') {
    ls_render('settings', $common + ['goals' => ls_goals($pdo, $siteId), 'timezones' => DateTimeZone::listIdentifiers(),
        'users' => $pdo->query("SELECT email, created_at FROM users ORDER BY email")->fetchAll()]);
    exit;
}
if ($view !== 'overview') {
    // Full lists: [title, list id for CSV, rows, what the second number counts].
    $full = fn (string $dim) => $top($dim, 100);
    $sections = match ($view) {
        'pages' => [['Top pages', 'page', $full('page'), 'views'], ['Landing pages', 'entry', $full('entry'), 'visits'], ['Exit pages', 'exit', $full('exit'), 'visits']],
        'sources' => [['Where visitors came from', 'source', $full('source'), 'visits'], ['Referring sites', 'referrer', $full('referrer'), 'visits'],
            ['Campaigns', 'campaign', $full('campaign'), 'visits'], ['Campaign sources', 'utm_source', $full('utm_source'), 'visits'], ['Campaign mediums', 'utm_medium', $full('utm_medium'), 'visits']],
        'locations' => [['Countries', 'country', $full('country'), 'visits']],
        'devices' => [['Devices', 'device', $full('device'), 'visits'], ['Browsers', 'browser', $full('browser'), 'visits'], ['Operating systems', 'os', $full('os'), 'visits']],
        'events' => [['Events', 'event', $full('event'), 'times'], ['Outbound links', 'event:Outbound link', $full('event:Outbound link'), 'clicks'],
            ['File downloads', 'event:File download', $full('event:File download'), 'downloads']],
    };
    ls_render('detail', $common + ['sections' => $sections, 'funnel' => $view === 'events' ? ls_report_funnel($pdo, $siteId, $from, $to) : []]);
    exit;
}

$summary = ls_report_summary($pdo, $siteId, $from, $to);
$stmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM visits WHERE site_id = :s1) + (SELECT COUNT(*) FROM monthly_totals WHERE site_id = :s2)");
$stmt->execute(['s1' => $siteId, 's2' => $siteId]);
ls_render('dashboard', $common + [
    'summary' => $summary, 'previous' => ls_report_summary($pdo, $siteId, $prevFrom, $prevTo), 'hasData' => (int) $stmt->fetchColumn() > 0,
    'series' => ls_report_series($pdo, $siteId, $from, $to),
    'pages' => $top('page'), 'entries' => $top('entry', 5), 'sources' => $top('source'), 'referrers' => $top('referrer'),
    'campaigns' => $top('campaign', 5), 'countries' => $top('country'), 'devices' => $top('device', 4),
    'browsers' => $top('browser', 6), 'systems' => $top('os', 6), 'events' => $top('event', 8),
    'funnel' => ls_report_funnel($pdo, $siteId, $from, $to),
]);
