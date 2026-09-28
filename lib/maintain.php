<?php
// Nightly housekeeping (bin/maintain.php):
//   1. delete old daily secrets, so earlier visitor hashes can never be linked again;
//   2. fill in monthly totals and top lists for every finished month, kept for good;
//   3. delete raw visits, page views and events older than the retention period.

declare(strict_types=1);

require_once __DIR__ . '/salt.php';

const LS_TOP_ROWS = 50;

// Unique visitors in a date range = each day's unique visitors added up. Hashes change every day
// by design, so the same person on two days can't be recognised, and can't be counted once.
function ls_visitors(PDO $pdo, int $siteId, string $from, string $to): int
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(n), 0) FROM (SELECT COUNT(DISTINCT visitor) AS n FROM visits
         WHERE site_id = :s AND day BETWEEN :f AND :t GROUP BY day) per_day"
    );
    $stmt->execute(['s' => $siteId, 'f' => $from, 't' => $to]);
    return (int) $stmt->fetchColumn();
}

// Monthly totals and top lists for one site and month (first day), replacing any earlier copy.
function ls_rollup_month(PDO $pdo, int $siteId, string $month): void
{
    $from = $month;
    $to = date('Y-m-t', strtotime($month));
    $args = ['s' => $siteId, 'f' => $from, 't' => $to];

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS visits, COALESCE(SUM(pageviews), 0) AS pageviews, COALESCE(SUM(pageviews = 1), 0) AS bounces,
                COALESCE(SUM(duration), 0) AS duration
         FROM visits WHERE site_id = :s AND day BETWEEN :f AND :t"
    );
    $stmt->execute($args);
    $t = $stmt->fetch();
    $pdo->prepare(
        "REPLACE INTO monthly_totals (site_id, month, visitors, visits, pageviews, bounces, duration)
         VALUES (:s, :m, :vr, :vs, :pv, :b, :d)"
    )->execute(['s' => $siteId, 'm' => $month, 'vr' => ls_visitors($pdo, $siteId, $from, $to), 'vs' => $t['visits'],
        'pv' => $t['pageviews'], 'b' => $t['bounces'], 'd' => $t['duration']]);

    $pdo->prepare("DELETE FROM monthly_top WHERE site_id = :s AND month = :m")->execute(['s' => $siteId, 'm' => $month]);
    // Visitors per item = distinct (visitor, day) pairs, the same daily-unique rule as above.
    $queries = [
        'page' => "SELECT LEFT(p.path, 191) AS value, COUNT(DISTINCT v.visitor, v.day) AS visitors, COUNT(*) AS hits
                   FROM pageviews p JOIN visits v ON v.id = p.visit_id WHERE p.site_id = :s AND p.day BETWEEN :f AND :t GROUP BY value",
        'event' => "SELECT LEFT(e.name, 191) AS value, COUNT(DISTINCT v.visitor, v.day) AS visitors, COUNT(*) AS hits
                   FROM events e JOIN visits v ON v.id = e.visit_id WHERE e.site_id = :s AND e.day BETWEEN :f AND :t GROUP BY value",
    ];
    foreach (['source' => 'source', 'referrer' => 'referrer_host', 'country' => 'country', 'browser' => 'browser', 'os' => 'os', 'device' => 'device', 'campaign' => 'utm_campaign'] as $dim => $col) {
        $queries[$dim] = "SELECT LEFT($col, 191) AS value, COUNT(DISTINCT visitor, day) AS visitors, COUNT(*) AS hits
                          FROM visits WHERE site_id = :s AND day BETWEEN :f AND :t AND $col <> '' GROUP BY value";
    }
    $insert = $pdo->prepare("INSERT INTO monthly_top (site_id, month, dimension, value, visitors, hits) VALUES (:s, :m, :d, :v, :vr, :h)");
    foreach ($queries as $dim => $sql) {
        $stmt = $pdo->prepare($sql . ' ORDER BY visitors DESC, hits DESC LIMIT ' . LS_TOP_ROWS);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $insert->execute(['s' => $siteId, 'm' => $month, 'd' => $dim, 'v' => $row['value'], 'vr' => $row['visitors'], 'h' => $row['hits']]);
        }
    }
}

// Runs all three steps. Returns log lines.
function ls_maintain(PDO $pdo, array $config): array
{
    $log = [];
    $log[] = 'old daily secrets deleted: ' . ls_salt_prune($pdo);
    $pdo->prepare("DELETE FROM login_failures WHERE at < :t")->execute(['t' => gmdate('Y-m-d H:i:s', time() - 86400)]);
    $keep = max(1, (int) ($config['retention_months'] ?? 13));
    foreach ($pdo->query("SELECT id, domain, timezone FROM sites")->fetchAll() as $site) {
        $siteId = (int) $site['id'];
        $thisMonth = substr(ls_site_day($site['timezone']), 0, 7) . '-01';
        // Every finished month that still has raw visits gets (re)totalled before anything is deleted.
        $stmt = $pdo->prepare("SELECT DISTINCT DATE_FORMAT(day, '%Y-%m-01') FROM visits WHERE site_id = :s AND day < :m");
        $stmt->execute(['s' => $siteId, 'm' => $thisMonth]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $month) {
            ls_rollup_month($pdo, $siteId, $month);
            $log[] = "{$site['domain']}: totals for " . substr($month, 0, 7);
        }
        $cutoff = date('Y-m-01', strtotime("$thisMonth -$keep months"));
        $deleted = 0;
        foreach (['events', 'pageviews', 'visits'] as $table) {
            $stmt = $pdo->prepare("DELETE FROM $table WHERE site_id = :s AND day < :c");
            $stmt->execute(['s' => $siteId, 'c' => $cutoff]);
            $deleted += $stmt->rowCount();
        }
        if ($deleted) {
            $log[] = "{$site['domain']}: $deleted raw rows older than $cutoff deleted";
        }
    }
    return $log;
}
