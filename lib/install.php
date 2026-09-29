<?php
// Creates and upgrades LibreStats' tables. Safe to run again. Used by bin/install.php and the tests.

declare(strict_types=1);

// Runs sql/schema.sql (which only makes tables that are missing), then adds the columns an older
// database doesn't have yet. Returns a line for each change made to a table that already existed.
function ls_install_schema(PDO $pdo): array
{
    $sql = preg_replace('~^\s*--.*$~m', '', (string) file_get_contents(LS_ROOT . '/sql/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) {
        $pdo->exec($statement);
    }
    return ls_migrate($pdo);
}

// Columns added after the first release, as table => [column => definition].
function ls_migrate(PDO $pdo): array
{
    $added = [
        'users' => [
            'role' => "VARCHAR(10) NOT NULL DEFAULT 'admin' AFTER password_hash",
            'team' => 'VARCHAR(64) NULL AFTER role',
            'sites' => "VARCHAR(2000) NOT NULL DEFAULT '*' AFTER team",
        ],
        'api_keys' => ['team' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER sites'],
    ];
    $changes = [];
    foreach ($added as $table => $columns) {
        $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($columns as $column => $definition) {
            if (!in_array($column, $have, true)) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $changes[] = "$table.$column added";
            }
        }
    }
    return $changes;
}
