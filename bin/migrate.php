<?php
// Applies any database changes in migrations/ that have not been applied yet.
// Run after every update:  php bin/migrate.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';

db()->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB');
$done = q('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);
$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $done, true)) continue;
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/--[^\n]*/', '', $sql)))) as $stmt) {
        db()->exec($stmt);
    }
    q('INSERT INTO migrations (name, applied_at) VALUES (?, ?)', [$name, now()]);
    echo "applied $name\n";
    $ran++;
}
echo $ran ? "done\n" : "nothing to apply\n";
