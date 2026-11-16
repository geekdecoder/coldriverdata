<?php
// Re-reads every stored chat export so conversations pick up importer improvements.
// Folders and stars are kept. The cron worker does the work within a few minutes.
//   php bin/reimport.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
$n = q("UPDATE imports SET status = 'queued', error = NULL, finished_at = NULL WHERE status IN ('done', 'failed')")->rowCount();
echo "$n export(s) queued to be read again. The cron worker will pick them up; check Admin > Overview.\n";
