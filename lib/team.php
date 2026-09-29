<?php
// Viewers: people who log in only to look at the numbers, of every website or of some of them. An app that
// runs LibreStats (a hosting panel, say) manages groups of viewers, called teams, through the data API with a
// key that has team access: public/api.php calls ls_team_api(). The person who leads a team chooses each
// viewer's username and password, and can set a new password or remove them later.
// Each function returns null on success or a plain-English problem, unless it says otherwise.

declare(strict_types=1);

const LS_TEAM_ID = '~^[A-Za-z0-9._-]{1,64}$~D';   // the app's own id for a team, such as an account number
const LS_SCOPE_MAX = 2000;                        // the longest list of websites a viewer can hold

// ---------- who may see which websites ----------

function ls_is_admin(array $user): bool
{
    return ($user['role'] ?? 'admin') === 'admin';
}

// The domains in a scope ('*' for every website, otherwise comma-separated domains). Null means every website.
function ls_scope_list(string $scope): ?array
{
    if (trim($scope) === '*') {
        return null;
    }
    return array_values(array_filter(array_map('trim', explode(',', strtolower($scope))), fn ($domain) => $domain !== ''));
}

function ls_scope_allows(string $scope, string $domain): bool
{
    $list = ls_scope_list($scope);
    return $list === null || in_array($domain, $list, true);
}

// The websites a person may look at: all of them for an admin.
function ls_user_sites(PDO $pdo, array $user): array
{
    $scope = ls_is_admin($user) ? '*' : (string) $user['sites'];
    return array_values(array_filter(
        $pdo->query("SELECT id, domain, name, timezone FROM sites ORDER BY domain")->fetchAll(),
        fn ($site) => ls_scope_allows($scope, $site['domain'])
    ));
}

// What an app may share with a team: the websites it asks for ("*" for all of them, or a list of domains), cut
// down to what its own key can read, as a scope. Null when the answer doesn't fit in a scope.
function ls_scope_narrow(string $keyScope, string|array $wanted): ?string
{
    $mine = ls_scope_list($keyScope);
    if ($wanted === '*') {
        $scope = $mine === null ? '*' : implode(',', $mine);
    } elseif (is_array($wanted)) {
        $domains = [];
        foreach ($wanted as $domain) {
            $domain = is_string($domain) ? ls_clean_domain($domain) : '';
            if (ls_valid_domain($domain) && ($mine === null || in_array($domain, $mine, true))) {
                $domains[$domain] = $domain;
            }
        }
        $scope = implode(',', $domains);
    } else {
        return null;
    }
    return strlen($scope) <= LS_SCOPE_MAX ? $scope : null;
}

// ---------- a team, for the app that manages it ----------

// A time as the database keeps it (UTC) to one an app can read.
function ls_iso(?string $utc): ?string
{
    return $utc === null ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($utc . ' UTC'));
}

// A team's viewers, oldest first, and the places used.
function ls_team_list(PDO $pdo, string $team): array
{
    $stmt = $pdo->prepare("SELECT id, email, sites, created_at FROM users WHERE team = :t AND role = 'viewer' ORDER BY id");
    $stmt->execute(['t' => $team]);
    $members = $stmt->fetchAll();
    return ['members' => $members, 'used' => count($members)];
}

// The team's leader adds a viewer and chooses their username and password. $scope is the websites they will
// see and $limit the places a team may use (null: no limit).
function ls_team_add(PDO $pdo, string $team, string $scope, string $login, string $password, ?int $limit = null): ?string
{
    if ($limit !== null && ls_team_list($pdo, $team)['used'] >= $limit) {
        return 'This team has no free places left.';
    }
    return ls_user_add($pdo, $login, $password, 'viewer', $team, $scope);
}

// A new password for somebody on the team (their leader sets it, nobody else can reset it).
function ls_team_password(PDO $pdo, string $team, int $userId, string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Please use a password of 10 characters or more.';
    }
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :i AND team = :t AND role = 'viewer'");
    $stmt->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'i' => $userId, 't' => $team]);
    return $stmt->rowCount() === 1 ? null : 'That person isn’t on your team.';
}

// Takes a viewer off a team: their login is deleted, and any session they have ends with their next click.
function ls_team_remove(PDO $pdo, string $team, int $userId): ?string
{
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :i AND team = :t AND role = 'viewer'");
    $stmt->execute(['i' => $userId, 't' => $team]);
    return $stmt->rowCount() === 1 ? null : 'That person isn’t on your team.';
}

// Changes which websites everyone on a team can see.
function ls_team_set_sites(PDO $pdo, string $team, string $scope): void
{
    $pdo->prepare("UPDATE users SET sites = :s WHERE team = :t AND role = 'viewer'")->execute(['s' => $scope, 't' => $team]);
}

// One request from an app whose key has team access. $key is the key's row, $input the decoded JSON
// body, $limit the places a team may use. Returns [HTTP status, answer]. Every operation names the team
// it is about ("acct") and touches only that one.
function ls_team_api(PDO $pdo, array $key, array $input, ?int $limit): array
{
    $acct = $input['acct'] ?? null;
    if (!is_string($acct) || !preg_match(LS_TEAM_ID, $acct)) {
        return [400, ['ok' => false, 'error' => 'A valid "acct" is needed.']];
    }
    $op = (string) ($input['op'] ?? '');
    $done = fn (?string $problem) => $problem !== null ? [409, ['ok' => false, 'error' => $problem]] : [200, ['ok' => true]];
    switch ($op) {
        case 'team.list':
            $team = ls_team_list($pdo, $acct);
            return [200, ['ok' => true,
                'members' => array_map(fn ($m) => ['id' => (int) $m['id'], 'username' => $m['email'], 'sites' => ls_scope_list($m['sites']) ?? '*', 'joined' => ls_iso($m['created_at'])], $team['members']),
                'seats' => ['used' => $team['used'], 'limit' => $limit]]];
        case 'team.add':
        case 'team.sites':
            // The websites must be named on purpose ("*" for all of them), so leaving them out can never share everything.
            $scope = isset($input['sites']) && ($input['sites'] === '*' || is_array($input['sites'])) ? ls_scope_narrow((string) $key['sites'], $input['sites']) : null;
            if ($scope === null) {
                return [400, ['ok' => false, 'error' => 'A "sites" value is needed: "*" or a list of domains.']];
            }
            if ($op === 'team.sites') {
                ls_team_set_sites($pdo, $acct, $scope);
                return [200, ['ok' => true]];
            }
            return $done(ls_team_add($pdo, $acct, $scope, (string) ($input['username'] ?? ''), (string) ($input['password'] ?? ''), $limit));
        case 'team.password':
            return $done(ls_team_password($pdo, $acct, (int) ($input['member'] ?? 0), (string) ($input['password'] ?? '')));
        case 'team.remove':
            return $done(ls_team_remove($pdo, $acct, (int) ($input['member'] ?? 0)));
    }
    return [400, ['ok' => false, 'error' => 'Unknown operation.']];
}
