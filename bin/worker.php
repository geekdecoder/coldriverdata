<?php
// Files queued chat exports into the archive. Run from cron every minute:
//   * * * * * php /home/coldriverdata.net/app/bin/worker.php >> /home/coldriverdata.net/logs/worker.log 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/importer.php';

ini_set('memory_limit', '1024M');
set_time_limit(0);

$lock = fopen(sys_get_temp_dir() . '/crd-worker.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);   // a previous run is still working

// Jobs left "running" by a crashed run go back in the queue after an hour.
q("UPDATE imports SET status = 'queued' WHERE status = 'running' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 3600)]);

while ($job = q("SELECT * FROM imports WHERE status = 'queued' ORDER BY id LIMIT 1")->fetch()) {
    run_import($job);
    $done = q('SELECT status, conversations FROM imports WHERE id = ?', [$job['id']])->fetch();
    echo gmdate('c') . " import {$job['id']}: {$done['status']} ({$done['conversations']} conversations)\n";
}

// Clear abandoned partial uploads older than a day.
foreach (glob(rtrim((string)cfg('storage_dir'), '/') . '/tmp/*') ?: [] as $f) {
    if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
}
