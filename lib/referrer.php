<?php
// Where a visit came from: its source type and a tidy referrer host (www. removed). Campaign tags
// (utm_source / utm_medium / utm_campaign on the landing page's address) win over the referrer.

declare(strict_types=1);

// host suffix => [label, source]. Matched on the host or any subdomain of it.
const LS_KNOWN_REFERRERS = [
    'google.' => ['Google', 'search'], 'bing.com' => ['Bing', 'search'], 'duckduckgo.com' => ['DuckDuckGo', 'search'],
    'search.brave.com' => ['Brave Search', 'search'], 'yahoo.com' => ['Yahoo', 'search'], 'yandex.' => ['Yandex', 'search'],
    'baidu.com' => ['Baidu', 'search'], 'ecosia.org' => ['Ecosia', 'search'], 'startpage.com' => ['Startpage', 'search'],
    'qwant.com' => ['Qwant', 'search'], 'kagi.com' => ['Kagi', 'search'], 'mojeek.com' => ['Mojeek', 'search'],
    'proxysearch.org' => ['ProxySearch', 'search'], 'chatgpt.com' => ['ChatGPT', 'link'], 'perplexity.ai' => ['Perplexity', 'link'],
    'x.com' => ['X', 'social'], 'twitter.com' => ['X', 'social'], 't.co' => ['X', 'social'], 'facebook.com' => ['Facebook', 'social'],
    'fb.com' => ['Facebook', 'social'], 'instagram.com' => ['Instagram', 'social'], 'linkedin.com' => ['LinkedIn', 'social'],
    'lnkd.in' => ['LinkedIn', 'social'], 'reddit.com' => ['Reddit', 'social'], 'youtube.com' => ['YouTube', 'social'],
    'tiktok.com' => ['TikTok', 'social'], 'pinterest.' => ['Pinterest', 'social'], 'bsky.app' => ['Bluesky', 'social'],
    'threads.net' => ['Threads', 'social'], 'mastodon.' => ['Mastodon', 'social'], 'lemmy.' => ['Lemmy', 'social'],
    'news.ycombinator.com' => ['Hacker News', 'social'],
    'mail.google.com' => ['Gmail', 'email'], 'outlook.live.com' => ['Outlook', 'email'], 'mail.yahoo.com' => ['Yahoo Mail', 'email'],
    'proton.me' => ['Proton Mail', 'email'], 'mail.riseup.net' => ['Riseup Mail', 'email'],
];

function ls_host(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
}

function ls_same_site(string $host, string $siteDomain): bool
{
    return $host === $siteDomain || str_ends_with($host, '.' . $siteDomain);
}

// Known referrer label and source, or null. Exact names are checked before 'google.'-style keys,
// which match any country domain, so mail.google.com is Gmail, not Google search.
function ls_known_referrer(string $host): ?array
{
    foreach (LS_KNOWN_REFERRERS as $suffix => $info) {
        if (!str_ends_with($suffix, '.') && ($host === $suffix || str_ends_with($host, '.' . $suffix))) {
            return $info;
        }
    }
    foreach (LS_KNOWN_REFERRERS as $suffix => $info) {
        if (str_ends_with($suffix, '.') && preg_match('~(^|\.)' . preg_quote($suffix, '~') . '[a-z.]+$~', $host)) {
            return $info;
        }
    }
    return null;
}

// Returns [source, referrer_host, utm_source, utm_medium, utm_campaign].
function ls_classify_source(string $referrer, string $pageUrl, string $siteDomain): array
{
    parse_str((string) parse_url($pageUrl, PHP_URL_QUERY), $q);
    $utm = array_map(fn ($k) => mb_substr(trim((string) ($q[$k] ?? '')), 0, 100), ['utm_source', 'utm_medium', 'utm_campaign']);
    $host = $referrer !== '' ? ls_host($referrer) : '';
    if ($host !== '' && ls_same_site($host, $siteDomain)) {
        $host = '';  // moving around the site itself isn't a source
    }
    if ($utm[0] !== '') {
        $source = strtolower($utm[1]) === 'email' ? 'email' : 'campaign';
    } elseif ($host === '') {
        $source = 'direct';
    } else {
        $source = ls_known_referrer($host)[1] ?? 'link';
    }
    return [$source, mb_substr($host, 0, 253), ...$utm];
}
