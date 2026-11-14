<?php $title = 'Files'; ?>
<h1>Files</h1>
<?php require __DIR__ . '/_usage.php'; ?>
<?php require __DIR__ . '/_uploader.php'; ?>
<section class="block">
<?php if (!$files): ?>
<p class="empty">Nothing stored yet.</p>
<?php else: ?>
<div class="table-scroll">
<table>
<thead><tr><th scope="col">Name</th><th scope="col">Size</th><th scope="col">Uploaded</th><th scope="col">Chat archive</th><th scope="col"><span class="sr">Actions</span></th></tr></thead>
<tbody>
<?php foreach ($files as $f): ?>
<tr>
<td class="name"><?= h($f['name']) ?></td>
<td><?= fmt_bytes((int)$f['size']) ?></td>
<td><?= h(fmt_date($f['created_at'])) ?></td>
<td><?php
    echo match ($f['import_status']) {
        'done' => 'Filed ' . number_format((int)$f['import_convs']) . ' conversations',
        'queued', 'running' => 'Filing…',
        'failed' => 'Could not read: ' . h($f['import_error'] ?? ''),
        default => '<span class="muted">Stored as a file</span>',
    };
?></td>
<td class="actions">
<a href="/app/files/<?= (int)$f['id'] ?>/download">Download</a>
<form method="post" action="/app/files/<?= (int)$f['id'] ?>/delete" data-confirm="Delete <?= h($f['name']) ?>?<?= $f['import_status'] === 'done' ? ' The conversations filed from it are removed from your archive too.' : '' ?>"><?= csrf_field() ?><button class="linkish danger">Delete</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>
<script>
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
