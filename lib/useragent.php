<?php
// Browser, operating system and device type from the browser's user-agent string, plus a check
// for bots. Only the three labels are stored; the string itself is never kept.

declare(strict_types=1);

// Crawlers, monitors, previewers, scripts and headless browsers. Most never run the tracking
// script at all; this catches the ones that do.
function ls_is_bot(string $ua): bool
{
    if ($ua === '' || strlen($ua) < 20) {
        return true;
    }
    return (bool) preg_match(
        '~bot\b|bot/|crawl|spider|slurp|scrap|preview|facebookexternalhit|embedly|headless|phantomjs|selenium|puppeteer|playwright'
        . '|lighthouse|pagespeed|pingdom|uptime|monitor|curl/|wget|python|httpclient|http-client|go-http|java/|okhttp|axios|node-fetch'
        . '|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|perplexity|applebot|bingpreview|yandex|baidu|duckduck~i',
        $ua
    );
}

// [browser, os, device]. $brave is true when the script saw navigator.brave (Brave looks like Chrome).
function ls_parse_ua(string $ua, int $screenWidth = 0, bool $brave = false): array
{
    $browser = match (true) {
        (bool) preg_match('~Edg(e|A|iOS)?/~', $ua) => 'Edge',
        (bool) preg_match('~OPR/|Opera~', $ua) => 'Opera',
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
        (bool) preg_match('~Firefox/|FxiOS~', $ua) => 'Firefox',
        (bool) preg_match('~Chrome/|CriOS/|Chromium/~', $ua) => $brave ? 'Brave' : 'Chrome',
        (bool) preg_match('~Version/[\d.]+.*Safari/~', $ua) => 'Safari',
        default => 'Other',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows',
        (bool) preg_match('~iPhone|iPad|iPod~', $ua) => 'iOS',
        str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'CrOS') => 'ChromeOS',
        str_contains($ua, 'Mac OS X') => 'macOS',
        (bool) preg_match('~Linux|X11~', $ua) => 'Linux',
        default => 'Other',
    };
    $device = match (true) {
        (bool) preg_match('~iPad|Tablet~i', $ua) || ($os === 'Android' && !str_contains($ua, 'Mobile')) => 'tablet',
        (bool) preg_match('~Mobi|iPhone|iPod~', $ua) => 'phone',
        default => 'desktop',
    };
    // A "desktop" browser on a small screen is almost certainly a phone asking for desktop pages.
    if ($device === 'desktop' && $screenWidth > 0 && $screenWidth < 600) {
        $device = 'phone';
    }
    return [$browser, $os, $device];
}
