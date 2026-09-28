<?php
// Country from the visitor's address, looked up in a local copy of the free DB-IP Lite database
// (bin/geo-update.php). Nothing is sent anywhere, and the address itself is never stored.

declare(strict_types=1);

// 16-byte form used by geo_country: IPv6 as is, IPv4 as ::ffff:a.b.c.d. Null if not an address.
function ls_ip_bin(string $ip): ?string
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return null;
    }
    return strlen($bin) === 4 ? str_repeat("\0", 10) . "\xff\xff" . $bin : $bin;
}

function ls_country(PDO $pdo, string $ip): string
{
    $bin = ls_ip_bin($ip);
    if ($bin === null) {
        return '';
    }
    $stmt = $pdo->prepare("SELECT country, ip_end FROM geo_country WHERE ip_start <= :ip ORDER BY ip_start DESC LIMIT 1");
    $stmt->execute(['ip' => $bin]);
    $row = $stmt->fetch();
    return $row && strcmp($row['ip_end'], $bin) >= 0 && $row['country'] !== 'ZZ' ? $row['country'] : '';
}
