<?php
// Turns on encryption at rest for this site's database tables (MariaDB).
// The MariaDB encryption key plugin must be set up first: see docs/DATABASE-ENCRYPTION.md.
//   php bin/encrypt-db-tables.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
set_time_limit(0);

$name = db_cli_settings()['dbname'];
try {
    $plugin = q("SELECT PLUGIN_STATUS FROM information_schema.PLUGINS WHERE PLUGIN_NAME = 'file_key_management'")->fetchColumn();
} catch (Throwable $e) { $plugin = false; }
if ($plugin !== 'ACTIVE') exit("The MariaDB key plugin (file_key_management) is not active. Follow docs/DATABASE-ENCRYPTION.md first.\n");

$tables = q('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE = ?', [$name, 'InnoDB'])->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $t)) continue;
    try { db()->exec("ALTER TABLE `$t` ENCRYPTED=YES"); echo "encrypted $t\n"; }
    catch (Throwable $e) { echo "FAILED $t: " . $e->getMessage() . "\n"; }
}
$st = protection_status();
echo "{$st['db_encrypted']} of {$st['db_tables']} tables are encrypted.\n";
