<?php
// Brings existing files under encryption:
//   1. files still in the built-in folder are queued to move to the default object storage
//   2. files already in object storage, stored before encryption was on, are re-stored encrypted
//   php bin/protect-files.php            do it
//   php bin/protect-files.php --dry-run  only report what would change
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
set_time_limit(0);
$dry = in_array('--dry-run', $argv, true);

$def = default_backend();
if ($def && $def['type'] === 's3') {
    $n = (int)q("SELECT COUNT(*) FROM files WHERE backend_id IS NULL AND ship_to IS NULL")->fetchColumn();
    if ($n && !$dry) q('UPDATE files SET ship_to = ? WHERE backend_id IS NULL AND ship_to IS NULL', [$def['id']]);
    echo "$n file(s) in the built-in folder " . ($dry ? 'would be' : 'are now') . " queued to move to {$def['name']}" . ($def['encrypt'] ? ' (encrypted)' : ' (NOT encrypted: turn encryption on for it first)') . ".\n";
    if ($n && !$dry) echo "The cron worker moves them within a minute or two.\n";
} else {
    echo "New uploads are not going to object storage, so built-in files were left alone.\n";
}

$rows = q("SELECT f.* FROM files f JOIN storage_backends b ON b.id = f.backend_id WHERE b.type = 's3' AND b.encrypt = 1 AND f.encrypted = 0 ORDER BY f.id")->fetchAll();
echo count($rows) . " file(s) in object storage " . ($dry ? 'would be' : 'to be') . " re-stored encrypted.\n";
$ok = 0; $bad = 0;
if (!$dry) foreach ($rows as $f) {
    try { encrypt_stored_file($f); $ok++; echo "  encrypted file {$f['id']} ({$f['name']})\n"; }
    catch (Throwable $e) { $bad++; echo "  FAILED file {$f['id']} ({$f['name']}): " . $e->getMessage() . "\n"; }
}
if (!$dry && $rows) echo "Done: $ok encrypted, $bad failed.\n";
exit($bad ? 1 : 0);
