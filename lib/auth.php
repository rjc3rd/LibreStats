<?php
// Dashboard login: one session cookie for people who log in to see the numbers (never for the
// visitors being counted), a CSRF token on every form, and a pause after repeated wrong passwords.

declare(strict_types=1);

require_once __DIR__ . '/salt.php';

const LS_LOGIN_TRIES = 8;           // wrong passwords allowed per 15 minutes, per address
const LS_SESSION_IDLE = 8 * 3600;   // logged out after this long without activity

function ls_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('librestats');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['ls_user'], $_SESSION['ls_seen']) && time() - $_SESSION['ls_seen'] > LS_SESSION_IDLE) {
        $_SESSION = [];
    }
    $_SESSION['ls_seen'] = time();
    $_SESSION['ls_csrf'] ??= bin2hex(random_bytes(16));
}

// The logged-in person, or null. They are looked up again on every request, so someone who has been
// removed is out at once and a change to what a viewer may see applies straight away. $pdo is for the tests.
function ls_user(?PDO $pdo = null): ?array
{
    if (!ls_dashboard_enabled()) {
        return null;  // switched off: no sessions and no logins, not even old cookies
    }
    ls_session_start();
    $id = (int) ($_SESSION['ls_user']['id'] ?? 0);
    if ($id === 0) {
        return null;
    }
    $stmt = ($pdo ?? ls_db())->prepare("SELECT id, email, role, team, sites FROM users WHERE id = :i");
    $stmt->execute(['i' => $id]);
    $user = $stmt->fetch();
    if (!$user) {
        $_SESSION = ['ls_seen' => time(), 'ls_csrf' => bin2hex(random_bytes(16))];
        return null;
    }
    return $_SESSION['ls_user'] = ['id' => (int) $user['id'], 'email' => $user['email'], 'role' => $user['role'], 'team' => $user['team'], 'sites' => $user['sites']];
}

function ls_csrf(): string
{
    ls_session_start();
    return $_SESSION['ls_csrf'];
}

function ls_csrf_ok(): bool
{
    return hash_equals(ls_csrf(), (string) ($_POST['csrf'] ?? ''));
}

// Returns null on success, or a message for the login form.
function ls_login(PDO $pdo, string $email, string $password, string $ip): ?string
{
    $who = ls_visitor_hash(ls_salt($pdo), 0, $ip, 'login');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_failures WHERE who = :w AND at >= :t");
    $stmt->execute(['w' => $who, 't' => gmdate('Y-m-d H:i:s', time() - 900)]);
    if ((int) $stmt->fetchColumn() >= LS_LOGIN_TRIES) {
        return 'Too many wrong passwords. Please wait 15 minutes and try again.';
    }
    $stmt = $pdo->prepare("SELECT id, email, password_hash FROM users WHERE email = :e");
    $stmt->execute(['e' => strtolower(trim($email))]);
    $user = $stmt->fetch();
    // Always run a hash check, so a wrong email takes as long as a wrong password.
    $ok = password_verify($password, $user['password_hash'] ?? '$2y$10$' . str_repeat('x', 53));
    if (!$user || !$ok) {
        $pdo->prepare("INSERT INTO login_failures (who, at) VALUES (:w, :t)")->execute(['w' => $who, 't' => ls_now()]);
        return 'That username and password don’t match.';
    }
    ls_login_as((int) $user['id']);
    return null;
}

// Starts a login session for somebody whose password (or invitation) has just been checked.
function ls_login_as(int $userId): void
{
    ls_session_start();
    session_regenerate_id(true);
    $_SESSION['ls_user'] = ['id' => $userId];
    $_SESSION['ls_csrf'] = bin2hex(random_bytes(16));
}

function ls_logout(): void
{
    ls_session_start();
    $_SESSION = [];
    session_regenerate_id(true);
}
