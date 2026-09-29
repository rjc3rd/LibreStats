<?php
// Dashboard logins.
//   php bin/user.php add ranzy                (a username or an email; asks for the password, not shown)
//   php bin/user.php add sam --viewer         (someone who can only look at the numbers, not change anything)
//   php bin/user.php password you@example.com
//   php bin/user.php list
//   php bin/user.php remove you@example.com

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/admin.php';

function ask_password(): string
{
    $tty = stream_isatty(STDIN);
    echo 'Password (10 characters or more): ';
    $tty && shell_exec('stty -echo');
    $p = rtrim((string) fgets(STDIN), "\r\n");
    $tty && shell_exec('stty echo');
    echo "\n";
    if (strlen($p) < 10) {
        exit("Too short.\n");
    }
    return $p;
}

$pdo = ls_db();
$viewer = in_array('--viewer', $argv, true);
[$cmd, $email] = [$argv[1] ?? '', strtolower(trim($argv[2] ?? ''))];
switch ($cmd) {
    case 'add':
    case 'password':
        if (!ls_valid_login($email)) {
            exit("Give an email address or a username (3-64 letters, digits, . _ -).\n");
        }
        $password = ask_password();
        if ($cmd === 'add') {
            echo ($problem = ls_user_add($pdo, $email, $password, $viewer ? 'viewer' : 'admin')) ? "$problem\n" : "Added $email" . ($viewer ? ', who can only look' : '') . ".\n";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE email = :e");
            $stmt->execute(['e' => $email, 'h' => $hash]);
            echo $stmt->rowCount() ? "Password changed.\n" : "No user $email.\n";
        }
        break;
    case 'list':
        foreach ($pdo->query("SELECT email, role, team, created_at FROM users ORDER BY email") as $u) {
            echo str_pad($u['email'], 40), str_pad($u['role'] === 'viewer' ? 'viewer' . ($u['team'] !== null ? ' (team ' . $u['team'] . ')' : '') : 'admin', 22), $u['created_at'], "\n";
        }
        break;
    case 'remove':
        $stmt = $pdo->prepare("DELETE FROM users WHERE email = :e");
        $stmt->execute(['e' => $email]);
        echo $stmt->rowCount() ? "Removed $email.\n" : "No user $email.\n";
        break;
    default:
        echo "Usage: php bin/user.php add|password|remove you@example.com [--viewer] | list\n";
}
