<?php
// Keys for the data API, used by apps that show LibreStats numbers in their own pages.
//   php bin/apikey.php add "My panel" example.com other.org     (or * for every website)
//   php bin/apikey.php add "My panel" * --team                  (the app may also add, change and remove viewers)
//   php bin/apikey.php team "My panel" on|off                   (change that for a key that already exists)
//   php bin/apikey.php list
//   php bin/apikey.php remove "My panel"
// The key is shown once when it's made, only a hash of it is kept.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';

$pdo = ls_db();
$cmd = $argv[1] ?? '';
$label = trim($argv[2] ?? '');
switch ($cmd) {
    case 'add':
        $team = in_array('--team', $argv, true);
        $sites = array_values(array_diff(array_slice($argv, 3), ['--team']));
        if ($label === '' || !$sites) {
            exit("Usage: php bin/apikey.php add \"Label\" example.com [more.com …] [--team]  (or * for all)\n");
        }
        $key = 'ls_' . bin2hex(random_bytes(24));
        $pdo->prepare("INSERT INTO api_keys (key_hash, label, sites, team) VALUES (:h, :l, :s, :t)")
            ->execute(['h' => hash('sha256', $key, true), 'l' => $label, 's' => in_array('*', $sites, true) ? '*' : implode(',', array_map('strtolower', $sites)), 't' => (int) $team]);
        echo "Key for \"$label\"" . ($team ? ' (can manage teams)' : '') . " (shown only now, keep it secret):\n$key\n";
        break;
    case 'team':
        $on = ($argv[3] ?? '') === 'on';
        if ($label === '' || !in_array($argv[3] ?? '', ['on', 'off'], true)) {
            exit("Usage: php bin/apikey.php team \"Label\" on|off\n");
        }
        $stmt = $pdo->prepare("UPDATE api_keys SET team = :t WHERE label = :l");
        $stmt->execute(['t' => (int) $on, 'l' => $label]);
        echo $stmt->rowCount() ? "Done: \"$label\" " . ($on ? 'can' : 'can no longer') . " manage teams.\n" : "No key \"$label\" (or it was already set that way).\n";
        break;
    case 'list':
        foreach ($pdo->query("SELECT label, sites, team, created_at, last_used FROM api_keys ORDER BY id") as $k) {
            echo str_pad($k['label'], 30), str_pad($k['sites'], 40), str_pad($k['team'] ? 'teams' : '', 8), 'last used ', $k['last_used'] ?? 'never', "\n";
        }
        break;
    case 'remove':
        $stmt = $pdo->prepare("DELETE FROM api_keys WHERE label = :l");
        $stmt->execute(['l' => $label]);
        echo $stmt->rowCount() ? "Removed.\n" : "No key \"$label\".\n";
        break;
    default:
        echo "Usage: php bin/apikey.php add|team|list|remove …\n";
}
