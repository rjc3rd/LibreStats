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
