<?php
// Fetches and decrypts a database backup into a .sql file. It does NOT touch the live database.
//   php bin/restore-backup.php              list available backups
//   php bin/restore-backup.php latest       fetch the newest one
//   php bin/restore-backup.php backups/db-2026-11-16-081500.sql.gz.enc
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
set_time_limit(0);

set_exception_handler(function (Throwable $e) { fwrite(STDERR, 'Could not restore: ' . $e->getMessage() . "\n"); exit(1); });
$b = backup_backend();
if (!$b) exit("No storage location is marked for backups.\n");
$conn = backend_s3($b);
$list = s3_list($conn, 'backups/db-');
usort($list, fn($x, $y) => strcmp($x['key'], $y['key']));
$want = $argv[1] ?? '';
if ($want === '') {
    if (!$list) exit("No backups found in {$b['name']}.\n");
    foreach ($list as $o) echo $o['key'] . '  ' . fmt_bytes($o['size']) . '  ' . gmdate('Y-m-d H:i', $o['modified']) . " UTC\n";
    exit("\nFetch one with: php bin/restore-backup.php latest\n");
}
if ($want === 'latest') { if (!$list) exit("No backups found.\n"); $want = end($list)['key']; }

$base = getcwd() . '/' . basename($want, '.gz.enc');     // ends in .sql
$enc = $base . '.gz.enc'; $gz = $base . '.gz';
$fh = fopen($enc, 'wb');
s3_get($conn, $want, function ($d) use ($fh) { fwrite($fh, $d); });
fclose($fh);
crypt_file($enc, $gz, derived_key('backup'), false);
unlink($enc);
exec('gunzip -f ' . escapeshellarg($gz), $o, $code);
if ($code !== 0 || !is_file($base)) exit("Decrypted, but could not unpack $gz\n");
$tables = (int)shell_exec('grep -c "^CREATE TABLE" ' . escapeshellarg($base));
echo "Restored to $base (" . fmt_bytes(filesize($base)) . ", $tables tables).\n";
echo "It has NOT been loaded into the live database. To load it into a database, run:\n  mysql -u USER -p DATABASE < " . basename($base) . "\n";
echo "Delete the file when you are finished: it holds customer data in readable form.\n";
