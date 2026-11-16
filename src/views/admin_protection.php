<?php $title = 'Protection';
$row = function (bool $ok, string $label, string $detail, ?bool $unknown = false) {
    $state = $unknown ? ['Unknown', 'tag'] : ($ok ? ['In place', 'tag tag-claude'] : ['Not yet', 'tag']);
    echo '<li><div class="block-head"><strong>' . h($label) . '</strong><span class="' . $state[1] . '">' . $state[0] . '</span></div><p class="muted">' . $detail . '</p></li>';
}; ?>
<h1>Protection</h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<p class="muted">Each item is checked live. The public <a href="/security">security page</a> only makes a claim when the matching item here is in place.</p>
<ul class="plain">
<?php
$row($st['https'], 'Encrypted in transit', 'The site address uses HTTPS, and files travel to storage over encrypted connections.');
$row($st['app_key'], 'Encryption key set', $st['app_key'] ? 'Every file key and backup key is derived from <span class="mono">app_key</span> in config.php. <strong>Keep a copy of it somewhere that is not this server.</strong> If it is lost, encrypted files and backups cannot be recovered.' : 'Add <span class="mono">app_key</span> to config.php.');
$left = $st['files_total'] - $st['files_encrypted'];
$row($st['files_all_encrypted'], 'Files encrypted at rest',
    number_format($st['files_encrypted']) . ' of ' . number_format($st['files_total']) . ' files are stored encrypted.'
    . (!$st['default_encrypts'] ? ' New uploads are not going to an encrypting location: in Storage, turn on encryption for the object storage and send new uploads there.' : '')
    . ($left > 0 && $st['default_encrypts'] ? ' To protect the remaining ' . number_format($left) . ', run <span class="mono">php bin/protect-files.php</span> on the server.' : '')
    . ($st['files_moving'] ? ' ' . number_format($st['files_moving']) . ' are waiting for the worker to move them.' : ''));
$row($st['db_all_encrypted'], 'Chat archive database encrypted at rest',
    $st['db_known'] ? $st['db_encrypted'] . ' of ' . $st['db_tables'] . ' tables are encrypted.' . ($st['db_all_encrypted'] ? '' : ' Follow <span class="mono">docs/DATABASE-ENCRYPTION.md</span>, then run <span class="mono">php bin/encrypt-db-tables.php</span>.') : 'This database server does not report its encryption status.', !$st['db_known']);
$row((bool)$st['backup_fresh'], 'Nightly encrypted backups',
    ($st['backup_location'] ? 'Backups go to ' . h($st['backup_location']) . ' and are kept ' . (int)$st['backup_days'] . ' days. ' : 'No backup location is set: in Storage, click "Use for backups" on a second bucket. ')
    . ($st['backup_last'] ? 'Last backup: ' . h(fmt_date($st['backup_last'], 'M j, Y H:i')) . ' UTC (' . (int)$st['backup_count'] . ' on record).' . ($st['backup_fresh'] ? '' : ' That is more than 36 hours ago, so check the backup cron job and <span class="mono">storage/backup.log</span>.') : 'No backup has run yet. Run <span class="mono">php bin/backup.php</span> once, then add it to cron.'));
?>
</ul>

<?php if ($recent): ?>
<section class="block">
<h2>Recent backups</h2>
<div class="table-scroll"><table>
<thead><tr><th scope="col">When (UTC)</th><th scope="col">Size</th><th scope="col">Stored in</th><th scope="col">Name</th></tr></thead>
<tbody><?php foreach ($recent as $r): ?><tr><td><?= h(fmt_date($r['created_at'], 'M j, Y H:i')) ?></td><td><?= fmt_bytes((int)$r['size']) ?></td><td><?= h($r['name'] ?? 'Removed location') ?></td><td class="name mono small"><?= h($r['object_key']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<p class="muted small">To check a backup can be restored, run <span class="mono">php bin/restore-backup.php latest</span> on the server. It writes a .sql file and does not change the live database.</p>
</section>
<?php endif; ?>
