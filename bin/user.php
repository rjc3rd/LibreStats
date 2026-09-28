<?php
// Dashboard logins.
//   php bin/user.php add you@example.com      (asks for the password; it isn't shown)
//   php bin/user.php password you@example.com
//   php bin/user.php list
//   php bin/user.php remove you@example.com

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';

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
[$cmd, $email] = [$argv[1] ?? '', strtolower(trim($argv[2] ?? ''))];
switch ($cmd) {
    case 'add':
    case 'password':
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            exit("Give an email address.\n");
        }
        $hash = password_hash(ask_password(), PASSWORD_DEFAULT);
        if ($cmd === 'add') {
            $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (:e, :h)")->execute(['e' => $email, 'h' => $hash]);
            echo "Added $email.\n";
        } else {
            $stmt = $pdo->prepare("UPDATE users SET password_hash = :h WHERE email = :e");
            $stmt->execute(['e' => $email, 'h' => $hash]);
            echo $stmt->rowCount() ? "Password changed.\n" : "No user $email.\n";
        }
        break;
    case 'list':
        foreach ($pdo->query("SELECT email, created_at FROM users ORDER BY email") as $u) {
            echo str_pad($u['email'], 40), $u['created_at'], "\n";
        }
        break;
    case 'remove':
        $stmt = $pdo->prepare("DELETE FROM users WHERE email = :e");
        $stmt->execute(['e' => $email]);
        echo $stmt->rowCount() ? "Removed $email.\n" : "No user $email.\n";
        break;
    default:
        echo "Usage: php bin/user.php add|password|remove you@example.com | list\n";
}
