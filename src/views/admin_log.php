<?php $title = 'Activity log'; ?>
<h1>Activity log</h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<?php if (!$entries): ?><p class="empty">No admin actions recorded yet.</p><?php else: ?>
<div class="table-scroll">
<table>
<thead><tr><th scope="col">When (UTC)</th><th scope="col">Admin</th><th scope="col">Action</th><th scope="col">On</th><th scope="col">Detail</th></tr></thead>
<tbody>
<?php foreach ($entries as $e): ?>
<tr><td><?= h(fmt_date($e['at'], 'M j, Y H:i')) ?></td><td><?= h($e['admin_email']) ?></td><td><?= h($e['action']) ?></td><td class="name"><?= h($e['target'] ?? '') ?></td><td><?= h($e['detail'] ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="muted small">Showing the latest 200 entries.</p>
<?php endif; ?>
