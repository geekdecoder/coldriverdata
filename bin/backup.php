<?php
// Nightly database backup: dump, compress, encrypt, upload to the backup location, prune old ones.
// Cron, once a day (as the site user):
//   15 8 * * * php /home/coldriverdata.net/app/bin/backup.php >> /home/coldriverdata.net/storage/backup.log 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
set_time_limit(0);

$say = fn(string $m) => print(gmdate('c') . " $m\n");
$b = backup_backend();
if (!$b) { $say('NO BACKUP: no storage location is marked for backups (Admin > Storage).'); exit(1); }

$tmpDir = rtrim((string)cfg('storage_dir'), '/') . '/tmp';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0750, true);
$stamp = gmdate('Y-m-d-His');
$dump = "$tmpDir/backup-$stamp.sql.gz"; $enc = "$dump.enc"; $cnf = "$tmpDir/backup-$stamp.cnf";

try {
    $db = db_cli_settings();
    $lines = "[client]\nuser=\"" . addcslashes($db['user'], "\"\\") . "\"\npassword=\"" . addcslashes($db['pass'], "\"\\") . "\"\n";
    if ($db['socket'] !== '') $lines .= "socket={$db['socket']}\n"; else { $lines .= "host={$db['host']}\n"; if ($db['port'] !== '') $lines .= "port={$db['port']}\n"; }
    file_put_contents($cnf, $lines);
    chmod($cnf, 0600);
    $bin = trim((string)shell_exec('command -v mariadb-dump || command -v mysqldump'));
    if ($bin === '') throw new RuntimeException('mysqldump is not installed.');
    $cmd = 'set -o pipefail; ' . escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf)
         . ' --single-transaction --quick --routines --no-tablespaces ' . escapeshellarg($db['dbname'])
         . ' | gzip -6 > ' . escapeshellarg($dump);
    exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $code);
    if ($code !== 0 || !is_file($dump) || filesize($dump) < 200) throw new RuntimeException('Database dump failed: ' . implode(' ', $out));

    crypt_file($dump, $enc, derived_key('backup'), true);
    $key = 'backups/db-' . $stamp . '.sql.gz.enc';
    $conn = backend_s3($b);
    s3_put_file($conn, $key, $enc);
    $size = filesize($enc);
    q('INSERT INTO backups (backend_id, object_key, size, created_at) VALUES (?,?,?,?)', [$b['id'], $key, $size, now()]);
    $say("backup stored: $key (" . fmt_bytes($size) . ") in {$b['name']}");

    // Remove backups older than the retention period.
    $days = max(1, (int)(cfg('backup_days') ?? 30));
    $cut = time() - $days * 86400;
    foreach (s3_list($conn, 'backups/db-') as $o) {
        if ($o['modified'] && $o['modified'] < $cut) {
            s3_delete($conn, $o['key']);
            q('DELETE FROM backups WHERE object_key = ? AND backend_id = ?', [$o['key'], $b['id']]);
            $say("removed old backup {$o['key']}");
        }
    }
} catch (Throwable $e) {
    $say('BACKUP FAILED: ' . $e->getMessage());
    $failed = true;
} finally {
    @unlink($dump); @unlink($enc); @unlink($cnf);
}
exit(empty($failed) ? 0 : 1);
