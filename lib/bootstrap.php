<?php
// Loads the settings and opens the database. Every entry point starts here.

declare(strict_types=1);

const LS_ROOT = __DIR__ . '/..';

function ls_config(): array
{
    static $config = null;
    if ($config === null) {
        $file = LS_ROOT . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('LibreStats: config.php is missing (copy config.example.php).');
        }
        $config = require $file;
    }
    return $config;
}

// A yes/no setting that is on unless config.php says otherwise ('off', 'no', 0 and false all turn it off).
function ls_flag(string $name, ?array $config = null): bool
{
    $value = ($config ?? ls_config())[$name] ?? true;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
}

// Whether the built-in dashboard (its pages, logins and first-run setup) is on. 'dashboard' => false
// in config.php turns it off; tracking and the data API keep working. $config is for the tests.
function ls_dashboard_enabled(?array $config = null): bool
{
    return ls_flag('dashboard', $config);
}

// Whether the first visitor to a dashboard that has no logins yet may create the first one. 'first_run' =>
// false turns that page off, for a server where logins only ever come from bin/user.php or from an app
// that runs LibreStats.
function ls_first_run_enabled(?array $config = null): bool
{
    return ls_flag('first_run', $config);
}

// How many viewers one team may have, or null for no limit. 'team_limit' in config.php.
function ls_team_limit(?array $config = null): ?int
{
    $limit = (int) (($config ?? ls_config())['team_limit'] ?? 5);
    return $limit > 0 ? $limit : null;
}

function ls_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = ls_config()['db'];
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

// Current time in UTC as the database stores it.
function ls_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

// Today's date in a site's time zone (days and months are counted in that zone).
function ls_site_day(string $timezone, ?int $ts = null): string
{
    try {
        $tz = new DateTimeZone($timezone);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone($tz)->format('Y-m-d');
}
