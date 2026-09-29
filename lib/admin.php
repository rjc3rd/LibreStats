<?php
// Settings changes shared by the dashboard and the command-line tools: sites, goals, logins.
// Each returns null on success or a plain-English problem.

declare(strict_types=1);

function ls_clean_domain(string $domain): string
{
    $domain = strtolower(trim($domain));
    $domain = preg_replace('~^https?://~', '', $domain);
    $domain = explode('/', $domain)[0];
    return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
}

function ls_valid_domain(string $domain): bool
{
    return (bool) preg_match('~^(?=.{1,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$~', $domain);
}

function ls_valid_timezone(string $tz): bool
{
    return in_array($tz, DateTimeZone::listIdentifiers(), true);
}

function ls_site_add(PDO $pdo, string $domain, string $timezone): ?string
{
    $domain = ls_clean_domain($domain);
    if (!ls_valid_domain($domain)) {
        return 'Please enter a domain like example.com.';
    }
    if (!ls_valid_timezone($timezone)) {
        return 'Please choose a time zone.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sites WHERE domain = :d");
    $stmt->execute(['d' => $domain]);
    if ($stmt->fetchColumn()) {
        return "$domain is already here.";
    }
    $pdo->prepare("INSERT INTO sites (domain, name, timezone) VALUES (:d, :n, :t)")->execute(['d' => $domain, 'n' => $domain, 't' => $timezone]);
    return null;
}

function ls_site_update(PDO $pdo, int $siteId, string $name, string $timezone): ?string
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) {
        return 'Please give the site a name (up to 100 characters).';
    }
    if (!ls_valid_timezone($timezone)) {
        return 'Please choose a time zone.';
    }
    $pdo->prepare("UPDATE sites SET name = :n, timezone = :t WHERE id = :s")->execute(['n' => $name, 't' => $timezone, 's' => $siteId]);
    return null;
}

// Deletes a site and every number it has. Nothing can bring it back.
function ls_site_remove(PDO $pdo, int $siteId): void
{
    foreach (['events', 'pageviews', 'visits', 'goals', 'monthly_totals', 'monthly_top'] as $table) {
        $pdo->prepare("DELETE FROM $table WHERE site_id = :s")->execute(['s' => $siteId]);
    }
    $pdo->prepare("DELETE FROM sites WHERE id = :s")->execute(['s' => $siteId]);
}

function ls_goals(PDO $pdo, int $siteId): array
{
    $stmt = $pdo->prepare("SELECT id, name, kind, target, position FROM goals WHERE site_id = :s ORDER BY position, id");
    $stmt->execute(['s' => $siteId]);
    return $stmt->fetchAll();
}

function ls_goal_add(PDO $pdo, int $siteId, string $name, string $kind, string $target): ?string
{
    $name = trim($name);
    $target = trim($target);
    if ($name === '' || mb_strlen($name) > 100) {
        return 'Please give the goal a name (up to 100 characters).';
    }
    if (!in_array($kind, ['path', 'event'], true)) {
        return 'Choose whether the goal is a page or an event.';
    }
    if ($kind === 'path' && !str_starts_with($target, '/')) {
        return 'A page goal starts with / (for example /pricing, or /blog/* for every blog page).';
    }
    if ($target === '' || mb_strlen($target) > 512) {
        return 'Please enter the page or the event name.';
    }
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM goals WHERE site_id = :s");
    $stmt->execute(['s' => $siteId]);
    $pdo->prepare("INSERT INTO goals (site_id, name, kind, target, position) VALUES (:s, :n, :k, :t, :p)")
        ->execute(['s' => $siteId, 'n' => $name, 'k' => $kind, 't' => $target, 'p' => (int) $stmt->fetchColumn()]);
    return null;
}

function ls_goal_remove(PDO $pdo, int $siteId, int $goalId): void
{
    $pdo->prepare("DELETE FROM goals WHERE id = :g AND site_id = :s")->execute(['g' => $goalId, 's' => $siteId]);
}

// Moves a goal one step earlier (-1) or later (+1) in the funnel.
function ls_goal_move(PDO $pdo, int $siteId, int $goalId, int $direction): void
{
    $goals = ls_goals($pdo, $siteId);
    $ids = array_column($goals, 'id');
    $i = array_search($goalId, array_map('intval', $ids), true);
    $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    $stmt = $pdo->prepare("UPDATE goals SET position = :p WHERE id = :g AND site_id = :s");
    foreach ($ids as $pos => $id) {
        $stmt->execute(['p' => $pos + 1, 'g' => $id, 's' => $siteId]);
    }
}

// A login name is an email address or a username (3 to 64 letters, digits, dots, dashes, underscores).
function ls_valid_login(string $login): bool
{
    return (bool) filter_var($login, FILTER_VALIDATE_EMAIL) || (bool) preg_match('~^[a-z0-9._-]{3,64}$~', $login);
}

// Adds a login without any checks. An 'admin' can change everything, a 'viewer' can only look (at every
// website, or at the domains in $sites). Returns the new login's id.
function ls_user_create(PDO $pdo, string $login, string $password, string $role = 'admin', ?string $team = null, string $sites = '*'): int
{
    $pdo->prepare("INSERT INTO users (email, password_hash, role, team, sites) VALUES (:e, :h, :r, :t, :s)")
        ->execute(['e' => strtolower(trim($login)), 'h' => password_hash($password, PASSWORD_DEFAULT), 'r' => $role, 't' => $team, 's' => $sites]);
    return (int) $pdo->lastInsertId();
}

function ls_user_add(PDO $pdo, string $email, string $password, string $role = 'admin', ?string $team = null, string $sites = '*'): ?string
{
    $email = strtolower(trim($email));
    if (!ls_valid_login($email)) {
        return 'Please enter an email address, or a username of 3 to 64 letters, digits, dots, dashes or underscores.';
    }
    if (strlen($password) < 10) {
        return 'Please use a password of 10 characters or more.';
    }
    if (!in_array($role, ['admin', 'viewer'], true)) {
        return 'Choose whether they can change settings or only look at the numbers.';
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = :e");
    $stmt->execute(['e' => $email]);
    if ($stmt->fetchColumn()) {
        return 'That username is already taken.';
    }
    ls_user_create($pdo, $email, $password, $role, $team, $sites);
    return null;
}

function ls_user_password(PDO $pdo, int $userId, string $current, string $new): ?string
{
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = :u");
    $stmt->execute(['u' => $userId]);
    if (!password_verify($current, (string) $stmt->fetchColumn())) {
        return 'Your current password isn’t right.';
    }
    if (strlen($new) < 10) {
        return 'Please use a new password of 10 characters or more.';
    }
    $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :u")->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'u' => $userId]);
    return null;
}
