<?php
// Receives what the tracking script (public/s.js) sends and stores it. Three kinds:
//   pageview  a page was shown           {n:"pageview", d, u, r, t, w, k, b}
//   leave     the page was left/hidden  {n:"leave", d, u, k, s}
//   event     something you count       {n:"event", d, u, e, x}
// d = site domain, u = page address, r = referrer, t = title, w = screen width,
// k = random id of this page view, s = active seconds on it, e/x = event name/detail, b = Brave.
//
// Privacy rules enforced here, for every site: Do Not Track and Global Privacy Control are
// honoured, bots are dropped, only pages on the site's own domain are accepted, the address is
// used for the daily hash and the country and then forgotten, and query strings are never kept
// (they can hold emails, tokens and other personal details).

declare(strict_types=1);

require_once __DIR__ . '/salt.php';
require_once __DIR__ . '/useragent.php';
require_once __DIR__ . '/referrer.php';
require_once __DIR__ . '/geo.php';

const LS_MAX_PAGE_SECONDS = 1800;

// The visitor's address: REMOTE_ADDR, or X-Forwarded-For's closest untrusted hop when the
// request came through a listed proxy.
function ls_client_ip(array $server, array $trustedProxies): string
{
    $ip = (string) ($server['REMOTE_ADDR'] ?? '');
    if ($trustedProxies && in_array($ip, $trustedProxies, true) && !empty($server['HTTP_X_FORWARDED_FOR'])) {
        $hops = array_reverse(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])));
        foreach ($hops as $hop) {
            if (!in_array($hop, $trustedProxies, true) && filter_var($hop, FILTER_VALIDATE_IP)) {
                return $hop;
            }
        }
    }
    return $ip;
}

// Path only: no scheme, host, query string or fragment.
function ls_clean_path(string $url): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $path = $path === '' ? '/' : $path;
    return mb_substr(mb_scrub(rawurldecode($path), 'UTF-8'), 0, 512);
}

function ls_find_site(PDO $pdo, string $domain): ?array
{
    $domain = strtolower(trim($domain));
    $domain = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    $stmt = $pdo->prepare("SELECT id, domain, timezone FROM sites WHERE domain = :d");
    $stmt->execute(['d' => $domain]);
    return $stmt->fetch() ?: null;
}

// Stores one hit. Returns 'ok' or 'ignored: <reason>' (the endpoint answers 204 either way, so
// nothing about the outcome leaks to the page).
function ls_collect(PDO $pdo, array $hit, array $server, array $config): string
{
    if (($server['HTTP_DNT'] ?? '') === '1' || ($server['HTTP_SEC_GPC'] ?? '') === '1') {
        return 'ignored: do not track';
    }
    $ua = mb_substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    if (ls_is_bot($ua)) {
        return 'ignored: bot';
    }
    $type = (string) ($hit['n'] ?? 'pageview');
    if (!in_array($type, ['pageview', 'leave', 'event'], true)) {
        return 'ignored: unknown type';
    }
    $site = ls_find_site($pdo, (string) ($hit['d'] ?? ''));
    if (!$site) {
        return 'ignored: unknown site';
    }
    $url = (string) ($hit['u'] ?? '');
    if (!ls_same_site(ls_host($url), $site['domain'])) {
        return 'ignored: page not on this site';
    }
    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && $origin !== 'null' && !ls_same_site(ls_host($origin), $site['domain'])) {
        return 'ignored: sent from another site';
    }

    $siteId = (int) $site['id'];
    $now = ls_now();
    $day = ls_site_day($site['timezone']);
    $ip = ls_client_ip($server, $config['trusted_proxies'] ?? []);
    $visitor = ls_visitor_hash(ls_salt($pdo), $siteId, $ip, $ua);
    $path = ls_clean_path($url);
    $key = preg_match('~^[a-z0-9]{16}$~', (string) ($hit['k'] ?? '')) ? (string) $hit['k'] : '';
    $timeout = max(1, (int) ($config['visit_timeout_minutes'] ?? 30));

    $stmt = $pdo->prepare(
        "SELECT id FROM visits WHERE site_id = :s AND visitor = :v AND last_at >= :since ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['s' => $siteId, 'v' => $visitor, 'since' => gmdate('Y-m-d H:i:s', time() - $timeout * 60)]);
    $visitId = (int) $stmt->fetchColumn();

    if ($type === 'leave') {
        if (!$visitId || $key === '') {
            return 'ignored: no open visit';
        }
        // The script sends the running total, so repeats (hide, show, hide) never double-count.
        $seconds = min(LS_MAX_PAGE_SECONDS, max(0, (int) ($hit['s'] ?? 0)));
        $pdo->prepare("UPDATE pageviews SET seconds = GREATEST(seconds, :sec) WHERE visit_id = :v AND page_key = :k")
            ->execute(['sec' => $seconds, 'v' => $visitId, 'k' => $key]);
        $pdo->prepare("UPDATE visits SET duration = (SELECT COALESCE(SUM(seconds), 0) FROM pageviews WHERE visit_id = :v1), last_at = :now WHERE id = :v2")
            ->execute(['v1' => $visitId, 'v2' => $visitId, 'now' => $now]);
        return 'ok';
    }

    if ($type === 'event') {
        $name = mb_substr(trim((string) ($hit['e'] ?? '')), 0, 100);
        if ($name === '' || !$visitId) {
            return $name === '' ? 'ignored: event without a name' : 'ignored: no open visit';
        }
        $pdo->prepare("INSERT INTO events (visit_id, site_id, day, at, name, detail, path) VALUES (:v, :s, :d, :at, :n, :x, :p)")
            ->execute(['v' => $visitId, 's' => $siteId, 'd' => $day, 'at' => $now, 'n' => $name,
                'x' => mb_substr(trim((string) ($hit['x'] ?? '')), 0, 512), 'p' => $path]);
        $pdo->prepare("UPDATE visits SET last_at = :now WHERE id = :v")->execute(['now' => $now, 'v' => $visitId]);
        return 'ok';
    }

    // Page view.
    if ($key === '') {
        return 'ignored: page view without an id';
    }
    $width = min(20000, max(0, (int) ($hit['w'] ?? 0)));
    if (!$visitId) {
        [$browser, $os, $device] = ls_parse_ua($ua, $width, !empty($hit['b']));
        [$source, $refHost, $utmSource, $utmMedium, $utmCampaign] = ls_classify_source((string) ($hit['r'] ?? ''), $url, $site['domain']);
        $pdo->prepare(
            "INSERT INTO visits (site_id, visitor, day, started_at, last_at, entry_path, exit_path, source, referrer_host,
                utm_source, utm_medium, utm_campaign, country, browser, os, device, screen_width)
             VALUES (:s, :v, :d, :now1, :now2, :p1, :p2, :src, :ref, :us, :um, :uc, :c, :b, :o, :dev, :w)"
        )->execute([
            's' => $siteId, 'v' => $visitor, 'd' => $day, 'now1' => $now, 'now2' => $now, 'p1' => $path, 'p2' => $path,
            'src' => $source, 'ref' => $refHost, 'us' => $utmSource, 'um' => $utmMedium, 'uc' => $utmCampaign,
            'c' => ls_country($pdo, $ip), 'b' => $browser, 'o' => $os, 'dev' => $device, 'w' => $width,
        ]);
        $visitId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO pageviews (visit_id, site_id, day, at, path, title, page_key) VALUES (:v, :s, :d, :at, :p, :t, :k)")
        ->execute(['v' => $visitId, 's' => $siteId, 'd' => $day, 'at' => $now, 'p' => $path,
            't' => mb_substr(trim((string) ($hit['t'] ?? '')), 0, 200), 'k' => $key]);
    $pdo->prepare("UPDATE visits SET pageviews = pageviews + 1, last_at = :now, exit_path = :p WHERE id = :v")
        ->execute(['now' => $now, 'p' => $path, 'v' => $visitId]);
    return 'ok';
}
