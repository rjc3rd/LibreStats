<?php
// Numbers for dashboards: summary, visitors over time, top lists, live visitors, goal funnel.
// Every function takes a site id and a date range (inclusive, in the site's time zone). Recent
// data comes from the raw tables; whole months older than the raw data come from the monthly
// totals kept for good, so long ranges still work after the raw data is deleted.
// Apps that embed LibreStats (like a hosting panel) can call these directly.

declare(strict_types=1);

require_once __DIR__ . '/maintain.php';
require_once __DIR__ . '/referrer.php';

const LS_RANGES = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days', '90d' => '90 days', '12m' => '12 months'];

// [from, to, label, previous_from, previous_to] for a named range (or custom dates) in a time zone.
function ls_range(string $range, string $timezone, string $from = '', string $to = ''): array
{
    $today = ls_site_day($timezone);
    $valid = fn (string $d) => (bool) preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) && strtotime($d) !== false;
    if ($range === 'custom' && $valid($from) && $valid($to)) {
        [$from, $to] = $from <= $to ? [$from, $to] : [$to, $from];
        $to = min($to, $today);
        $label = date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    } else {
        $range = isset(LS_RANGES[$range]) ? $range : '30d';
        $days = ['today' => 0, '7d' => 6, '30d' => 29, '90d' => 89][$range] ?? null;
        $from = $days !== null ? date('Y-m-d', strtotime("$today -$days days")) : date('Y-m-01', strtotime("$today -11 months"));
        $to = $today;
        $label = LS_RANGES[$range];
    }
    $length = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime("$from -1 day"));
    $prevFrom = date('Y-m-d', strtotime("$prevTo -" . ($length - 1) . ' days'));
    return [$from, $to, $label, $prevFrom, $prevTo];
}

// First day that still has raw data for a site ('' if none), and the months before it that fall
// completely inside [from, to] (those come from the monthly totals).
function ls_split_range(PDO $pdo, int $siteId, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT MIN(day) FROM visits WHERE site_id = :s");
    $stmt->execute(['s' => $siteId]);
    $rawStart = (string) $stmt->fetchColumn();
    $months = [];
    $m = date('Y-m-01', strtotime($from));
    if ($m < $from) {
        $m = date('Y-m-01', strtotime("$m +1 month"));
    }
    for (; $m <= $to && date('Y-m-t', strtotime($m)) <= $to; $m = date('Y-m-01', strtotime("$m +1 month"))) {
        if ($rawStart === '' || date('Y-m-t', strtotime($m)) < $rawStart) {
            $months[] = $m;
        }
    }
    return [$rawStart, $months];
}

function ls_report_summary(PDO $pdo, int $siteId, string $from, string $to): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS visits, COALESCE(SUM(pageviews), 0) AS pageviews, COALESCE(SUM(pageviews = 1), 0) AS bounces,
                COALESCE(SUM(duration), 0) AS duration
         FROM visits WHERE site_id = :s AND day BETWEEN :f AND :t"
    );
    $stmt->execute(['s' => $siteId, 'f' => $from, 't' => $to]);
    $t = array_map('intval', $stmt->fetch());
    $t['visitors'] = ls_visitors($pdo, $siteId, $from, $to);
    [, $months] = ls_split_range($pdo, $siteId, $from, $to);
    if ($months) {
        $in = implode(',', array_fill(0, count($months), '?'));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(visitors),0) visitors, COALESCE(SUM(visits),0) visits, COALESCE(SUM(pageviews),0) pageviews,
            COALESCE(SUM(bounces),0) bounces, COALESCE(SUM(duration),0) duration FROM monthly_totals WHERE site_id = ? AND month IN ($in)");
        $stmt->execute([$siteId, ...$months]);
        foreach ($stmt->fetch() as $k => $v) {
            $t[$k] += (int) $v;
        }
    }
    $t['bounce_rate'] = $t['visits'] ? round($t['bounces'] / $t['visits'] * 100) : 0;
    $t['avg_duration'] = $t['visits'] ? (int) round($t['duration'] / $t['visits']) : 0;
    $t['views_per_visit'] = $t['visits'] ? round($t['pageviews'] / $t['visits'], 1) : 0;
    return $t;
}

// Visitors and page views per day (ranges up to 92 days) or per month (longer), zeros filled in.
// Rows: [key, label, visitors, pageviews].
function ls_report_series(PDO $pdo, int $siteId, string $from, string $to): array
{
    $byMonth = (strtotime($to) - strtotime($from)) / 86400 > 92;
    $rows = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $k = $byMonth ? substr($d, 0, 7) : $d;
        $rows[$k] ??= [$k, $byMonth ? date('M Y', strtotime($d)) : date('M j', strtotime($d)), 0, 0];
    }
    $stmt = $pdo->prepare(
        "SELECT day, COUNT(DISTINCT visitor) AS visitors, SUM(pageviews) AS pageviews FROM visits
         WHERE site_id = :s AND day BETWEEN :f AND :t GROUP BY day"
    );
    $stmt->execute(['s' => $siteId, 'f' => $from, 't' => $to]);
    foreach ($stmt->fetchAll() as $r) {
        $k = $byMonth ? substr($r['day'], 0, 7) : $r['day'];
        $rows[$k][2] += (int) $r['visitors'];
        $rows[$k][3] += (int) $r['pageviews'];
    }
    if ($byMonth) {
        [, $months] = ls_split_range($pdo, $siteId, $from, $to);
        if ($months) {
            $in = implode(',', array_fill(0, count($months), '?'));
            $stmt = $pdo->prepare("SELECT month, visitors, pageviews FROM monthly_totals WHERE site_id = ? AND month IN ($in)");
            $stmt->execute([$siteId, ...$months]);
            foreach ($stmt->fetchAll() as $r) {
                $k = substr($r['month'], 0, 7);
                $rows[$k][2] += (int) $r['visitors'];
                $rows[$k][3] += (int) $r['pageviews'];
            }
        }
    }
    return array_values($rows);
}

// Top items for one dimension. Rows: [value, label, visitors, hits] (visitors = daily uniques).
// Dimensions: page, entry, exit, source, referrer, campaign, utm_source, utm_medium, country, browser, os,
// device, event, and event:<name> (that event's details).
function ls_report_top(PDO $pdo, int $siteId, string $from, string $to, string $dim, int $limit = 10): array
{
    $visitCols = ['entry' => 'entry_path', 'exit' => 'exit_path', 'source' => 'source', 'referrer' => 'referrer_host',
        'campaign' => 'utm_campaign', 'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'country' => 'country', 'browser' => 'browser', 'os' => 'os', 'device' => 'device'];
    $args = ['s' => $siteId, 'f' => $from, 't' => $to];
    if ($dim === 'page') {
        $sql = "SELECT p.path AS value, COUNT(DISTINCT v.visitor, v.day) AS visitors, COUNT(*) AS hits FROM pageviews p
                JOIN visits v ON v.id = p.visit_id WHERE p.site_id = :s AND p.day BETWEEN :f AND :t GROUP BY p.path";
    } elseif ($dim === 'event') {
        $sql = "SELECT e.name AS value, COUNT(DISTINCT v.visitor, v.day) AS visitors, COUNT(*) AS hits FROM events e
                JOIN visits v ON v.id = e.visit_id WHERE e.site_id = :s AND e.day BETWEEN :f AND :t GROUP BY e.name";
    } elseif (str_starts_with($dim, 'event:')) {
        // One event's details, e.g. event:Outbound link lists the links that were clicked.
        $sql = "SELECT e.detail AS value, COUNT(DISTINCT v.visitor, v.day) AS visitors, COUNT(*) AS hits FROM events e
                JOIN visits v ON v.id = e.visit_id WHERE e.site_id = :s AND e.day BETWEEN :f AND :t AND e.name = :n AND e.detail <> '' GROUP BY e.detail";
        $args['n'] = substr($dim, 6);
    } elseif (isset($visitCols[$dim])) {
        $col = $visitCols[$dim];
        $sql = "SELECT $col AS value, COUNT(DISTINCT visitor, day) AS visitors, COUNT(*) AS hits FROM visits
                WHERE site_id = :s AND day BETWEEN :f AND :t AND $col <> '' GROUP BY $col";
    } else {
        return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $rows[$r['value']] = [(string) $r['value'], (int) $r['visitors'], (int) $r['hits']];
    }
    // Older whole months, from the lists kept for good (entry/exit pages aren't kept).
    [, $months] = ls_split_range($pdo, $siteId, $from, $to);
    if ($months && !in_array($dim, ['entry', 'exit', 'utm_source', 'utm_medium'], true) && !str_starts_with($dim, 'event:')) {
        $in = implode(',', array_fill(0, count($months), '?'));
        $stmt = $pdo->prepare("SELECT value, SUM(visitors) visitors, SUM(hits) hits FROM monthly_top WHERE site_id = ? AND dimension = ? AND month IN ($in) GROUP BY value");
        $stmt->execute([$siteId, $dim, ...$months]);
        foreach ($stmt->fetchAll() as $r) {
            $rows[$r['value']] ??= [(string) $r['value'], 0, 0];
            $rows[$r['value']][1] += (int) $r['visitors'];
            $rows[$r['value']][2] += (int) $r['hits'];
        }
    }
    usort($rows, fn ($a, $b) => [$b[1], $b[2]] <=> [$a[1], $a[2]]);
    return array_map(fn ($r) => [$r[0], ls_label($dim, $r[0]), $r[1], $r[2]], array_slice($rows, 0, $limit));
}

// Sends rows [value, label, visitors, hits] as a CSV download.
function ls_send_csv(string $filename, array $rows, string $hitsName): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('~[^a-z0-9._-]~i', '-', $filename) . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['item', 'name', 'visitors', $hitsName], ',', '"', '');
    foreach ($rows as $r) {
        // Cells starting with = + - @ would run as formulas in spreadsheets: prefix them.
        $safe = array_map(fn ($c) => is_string($c) && preg_match('~^[=+\-@\t\r]~', $c) ? "'" . $c : $c, $r);
        fputcsv($out, $safe, ',', '"', '');
    }
    fclose($out);
}

// Friendly name for a stored value.
function ls_label(string $dim, string $value): string
{
    return match ($dim) {
        'source' => ['direct' => 'Direct', 'search' => 'Search engines', 'social' => 'Social media', 'link' => 'Links from other sites',
            'campaign' => 'Campaigns', 'email' => 'Email'][$value] ?? ucfirst($value),
        'referrer' => ls_known_referrer($value)[0] ?? $value,
        'country' => class_exists('Locale') ? (Locale::getDisplayRegion('-' . $value, 'en') ?: $value) : $value,
        'device' => ucfirst($value),
        default => $value,
    };
}

// The last time each of these websites had activity (UTC, as the database keeps it), by site id. A website with
// no raw data left, or none yet, is left out. Apps use it to tell "counting" from "the script isn't on the page yet".
function ls_sites_last_hit(PDO $pdo, array $siteIds): array
{
    $ids = array_values(array_filter(array_map('intval', $siteIds), fn ($id) => $id > 0));
    if (!$ids) {
        return [];
    }
    $last = [];
    foreach ($pdo->query('SELECT site_id, MAX(last_at) AS t FROM visits WHERE site_id IN (' . implode(',', $ids) . ') GROUP BY site_id')->fetchAll() as $row) {
        $last[(int) $row['site_id']] = (string) $row['t'];
    }
    return $last;
}

// People on the site in the last five minutes, and the pages they're on.
function ls_report_live(PDO $pdo, int $siteId): array
{
    $since = gmdate('Y-m-d H:i:s', time() - 300);
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT visitor) FROM visits WHERE site_id = :s AND last_at >= :t");
    $stmt->execute(['s' => $siteId, 't' => $since]);
    $count = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT exit_path, COUNT(*) n FROM visits WHERE site_id = :s AND last_at >= :t GROUP BY exit_path ORDER BY n DESC LIMIT 5");
    $stmt->execute(['s' => $siteId, 't' => $since]);
    return ['visitors' => $count, 'pages' => array_map(fn ($r) => [$r['exit_path'], (int) $r['n']], $stmt->fetchAll())];
}

// Goal funnel: for each goal in order, how many visits reached it and every goal before it.
// Rows: [name, visits, percent of the step before].
function ls_report_funnel(PDO $pdo, int $siteId, string $from, string $to): array
{
    $stmt = $pdo->prepare("SELECT name, kind, target FROM goals WHERE site_id = :s ORDER BY position, id");
    $stmt->execute(['s' => $siteId]);
    $rows = [];
    $reached = null;
    foreach ($stmt->fetchAll() as $goal) {
        if ($goal['kind'] === 'event') {
            $q = $pdo->prepare("SELECT DISTINCT visit_id FROM events WHERE site_id = :s AND day BETWEEN :f AND :t AND name = :x");
            $x = $goal['target'];
        } elseif (str_ends_with($goal['target'], '*')) {
            $q = $pdo->prepare("SELECT DISTINCT visit_id FROM pageviews WHERE site_id = :s AND day BETWEEN :f AND :t AND path LIKE :x");
            $x = addcslashes(rtrim($goal['target'], '*'), '%_\\') . '%';
        } else {
            $q = $pdo->prepare("SELECT DISTINCT visit_id FROM pageviews WHERE site_id = :s AND day BETWEEN :f AND :t AND path = :x");
            $x = $goal['target'];
        }
        $q->execute(['s' => $siteId, 'f' => $from, 't' => $to, 'x' => $x]);
        $ids = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
        $before = $reached === null ? null : count($reached);
        $reached = $reached === null ? $ids : array_intersect_key($reached, $ids);
        $rows[] = [$goal['name'], count($reached), $before ? (int) round(count($reached) / $before * 100) : null];
    }
    return $rows;
}

// Percent change from a previous value, or null when there's nothing to compare with.
function ls_change(int|float $now, int|float $before): ?int
{
    return $before > 0 ? (int) round(($now - $before) / $before * 100) : null;
}
