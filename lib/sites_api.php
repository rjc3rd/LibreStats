<?php
// Websites for another app. An app that runs LibreStats for other people (a hosting panel, say) adds the websites
// those people own, and takes them away again, through the data API with a key that has website access
// (php bin/apikey.php add "My panel" '*' --manage-sites). public/api.php calls ls_sites_api(), and only for
// a POST to api.php?sites. The README describes each operation.

declare(strict_types=1);

// One request from an app whose key can manage websites. $key is the key's row, $input the decoded JSON body.
// Returns [HTTP status, answer]. Both operations name the website ("domain"). A key that manages websites has to
// be able to read all of them: it adds websites it could not otherwise read, and may remove any.
function ls_sites_api(PDO $pdo, array $key, array $input): array
{
    if (ls_scope_list((string) $key['sites']) !== null) {
        return [403, ['ok' => false, 'error' => 'A key that manages websites has to be able to read all of them.']];
    }
    $domain = is_string($input['domain'] ?? null) ? ls_clean_domain($input['domain']) : '';
    if (!ls_valid_domain($domain)) {
        return [400, ['ok' => false, 'error' => 'A valid "domain" is needed, like example.com.']];
    }
    $stmt = $pdo->prepare("SELECT id FROM sites WHERE domain = :d");
    $stmt->execute(['d' => $domain]);
    $siteId = $stmt->fetchColumn();
    switch ((string) ($input['op'] ?? '')) {
        case 'site.add':
            // Adding one that is already there is fine, so an app can simply make sure every website is here.
            if ($siteId !== false) {
                return [200, ['ok' => true, 'created' => false]];
            }
            $timezone = is_string($input['timezone'] ?? null) ? $input['timezone'] : 'UTC';
            $problem = ls_site_add($pdo, $domain, $timezone);
            return $problem !== null ? [400, ['ok' => false, 'error' => $problem]] : [200, ['ok' => true, 'created' => true]];
        case 'site.remove':
            // Deletes every number the website has, for good; the same goes for one that is not here (nothing to do).
            if ($siteId === false) {
                return [200, ['ok' => true, 'removed' => false]];
            }
            ls_site_remove($pdo, (int) $siteId);
            return [200, ['ok' => true, 'removed' => true]];
    }
    return [400, ['ok' => false, 'error' => 'Unknown operation.']];
}
