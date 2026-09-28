<?php
// The daily secret. A visitor is identified only by
//   first 8 bytes of SHA-256(today's secret + site + address + browser string)
// The secret is random, made fresh each UTC day, and deleted afterwards (bin/maintain.php), so a
// hash can never be linked to a person or to their visits on another day, not even by us. The
// address itself is never written anywhere.

declare(strict_types=1);

function ls_salt(PDO $pdo, ?string $day = null): string
{
    $day ??= gmdate('Y-m-d');
    static $cache = [];
    if (isset($cache[$day])) {
        return $cache[$day];
    }
    // Two requests racing at midnight: INSERT IGNORE keeps the first secret, both read it back.
    $pdo->prepare("INSERT IGNORE INTO salts (day, salt) VALUES (:d, :s)")->execute(['d' => $day, 's' => random_bytes(32)]);
    $stmt = $pdo->prepare("SELECT salt FROM salts WHERE day = :d");
    $stmt->execute(['d' => $day]);
    return $cache[$day] = (string) $stmt->fetchColumn();
}

function ls_visitor_hash(string $salt, int $siteId, string $ip, string $userAgent): string
{
    return substr(hash('sha256', $salt . "\0" . $siteId . "\0" . $ip . "\0" . $userAgent, true), 0, 8);
}

// Deletes every secret older than today, which makes all earlier hashes unlinkable for good.
function ls_salt_prune(PDO $pdo): int
{
    $stmt = $pdo->prepare("DELETE FROM salts WHERE day < :d");
    $stmt->execute(['d' => gmdate('Y-m-d')]);
    return $stmt->rowCount();
}
