<?php
// Goals, shown as a funnel in the order they're added.
//   php bin/goal.php add example.com "Viewed pricing" path /pricing
//   php bin/goal.php add example.com "Read the blog" path "/blog/*"      (* = any page starting with /blog/)
//   php bin/goal.php add example.com "Signed up" event "Signed up"       (sent by librestats("Signed up"))
//   php bin/goal.php list example.com
//   php bin/goal.php remove example.com "Viewed pricing"

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run from the command line.\n");
}
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/collect.php';
require __DIR__ . '/../lib/admin.php';

$pdo = ls_db();
[$cmd, $domain, $name, $kind, $target] = array_pad(array_slice($argv, 1), 5, '');
$site = ls_find_site($pdo, $domain);
if (!$site) {
    exit("No site $domain (add it with bin/site.php first).\n");
}
$sid = (int) $site['id'];
switch ($cmd) {
    case 'add':
        if ($problem = ls_goal_add($pdo, $sid, $name, $kind, $target)) {
            exit("$problem\nUsage: php bin/goal.php add example.com \"Name\" path|event TARGET\n");
        }
        echo "Added goal \"$name\".\n";
        break;
    case 'list':
        $stmt = $pdo->prepare("SELECT position, name, kind, target FROM goals WHERE site_id = :s ORDER BY position, id");
        $stmt->execute(['s' => $sid]);
        foreach ($stmt->fetchAll() as $g) {
            echo "{$g['position']}. {$g['name']}  ({$g['kind']}: {$g['target']})\n";
        }
        break;
    case 'remove':
        $stmt = $pdo->prepare("DELETE FROM goals WHERE site_id = :s AND name = :n");
        $stmt->execute(['s' => $sid, 'n' => $name]);
        echo $stmt->rowCount() ? "Removed.\n" : "No goal \"$name\".\n";
        break;
    default:
        echo "Usage: php bin/goal.php add|list|remove example.com ...\n";
}
