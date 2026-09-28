<?php
// The default dashboard: first-run setup, login, and the numbers for one site and date range.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/report.php';
require __DIR__ . '/../lib/charts.php';
require __DIR__ . '/../lib/theme.php';
require __DIR__ . '/../lib/collect.php';

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex');

$pdo = ls_db();
$error = null;
$action = $_POST['action'] ?? '';

// First run: no logins yet, so the first visitor creates the owner's login here.
$noUsers = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0;
if ($noUsers) {
    if ($action === 'setup' && ls_csrf_ok()) {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
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
$sites = $pdo->query("SELECT id, domain, name, timezone FROM sites ORDER BY domain")->fetchAll();
if (!$sites) {
    ls_render('no-sites', ['user' => $user, 'csrf' => ls_csrf()]);
    exit;
}
$site = $sites[0];
foreach ($sites as $s) {
    if ($s['domain'] === ($_GET['site'] ?? '')) {
        $site = $s;
    }
}
$siteId = (int) $site['id'];
$range = (string) ($_GET['range'] ?? '30d');
[$from, $to, $label, $prevFrom, $prevTo] = ls_range($range, $site['timezone'], (string) ($_GET['from'] ?? ''), (string) ($_GET['to'] ?? ''));
$range = isset(LS_RANGES[$range]) || $range === 'custom' ? $range : '30d';

$summary = ls_report_summary($pdo, $siteId, $from, $to);
$previous = ls_report_summary($pdo, $siteId, $prevFrom, $prevTo);
$top = fn (string $dim, int $limit = 8) => ls_report_top($pdo, $siteId, $from, $to, $dim, $limit);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE site_id = :s");
$stmt->execute(['s' => $siteId]);
$hasData = (int) $stmt->fetchColumn() > 0 || (int) $pdo->query("SELECT COUNT(*) FROM monthly_totals WHERE site_id = $siteId")->fetchColumn() > 0;

ls_render('dashboard', [
    'user' => $user, 'csrf' => ls_csrf(), 'sites' => $sites, 'site' => $site, 'range' => $range, 'label' => $label,
    'from' => $from, 'to' => $to, 'summary' => $summary, 'previous' => $previous, 'hasData' => $hasData,
    'series' => ls_report_series($pdo, $siteId, $from, $to),
    'pages' => $top('page'), 'entries' => $top('entry', 5), 'sources' => $top('source'), 'referrers' => $top('referrer'),
    'campaigns' => $top('campaign', 5), 'countries' => $top('country'), 'devices' => $top('device', 4),
    'browsers' => $top('browser', 6), 'systems' => $top('os', 6), 'events' => $top('event', 8),
    'funnel' => ls_report_funnel($pdo, $siteId, $from, $to),
    'live' => ls_report_live($pdo, $siteId),
    'scriptUrl' => (isset($_SERVER['HTTP_HOST']) ? (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') : '') . '/s.js',
]);
