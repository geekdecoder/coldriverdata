<?php $title = 'Admin'; ?>
<h1>Admin</h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<div class="stats">
<div><strong><?= number_format($stats['users']) ?></strong><span>accounts</span></div>
<div><strong><?= number_format($stats['paying']) ?></strong><span>on a current plan</span></div>
<div><strong><?= number_format($stats['new7']) ?></strong><span>new in 7 days</span></div>
<div><strong><?= fmt_bytes($stats['bytes']) ?></strong><span>stored in <?= number_format($stats['files']) ?> files</span></div>
<div><strong><?= number_format($stats['convs']) ?></strong><span>conversations filed</span></div>
</div>
<?php if ($byPlan): ?><p class="muted">Current plans: <?php foreach ($byPlan as $i => $p): ?><?= $i ? ' · ' : '' ?><?= h(plans()[$p['plan']]['name'] ?? $p['plan']) ?> <?= (int)$p['n'] ?><?php endforeach; ?><?= $stats['suspended'] ? ' · ' . (int)$stats['suspended'] . ' suspended' : '' ?></p><?php endif; ?>
<?php if ($stats['unverified']): ?><p class="muted"><?= (int)$stats['unverified'] ?> account<?= $stats['unverified'] > 1 ? 's have' : ' has' ?> not confirmed an email address. Unconfirmed accounts are removed automatically after 7 days.</p><?php endif; ?>

<section class="block">
<h2>Needs attention</h2>
<?php $failed = (int)($imports['failed'] ?? 0); $queued = (int)($imports['queued'] ?? 0) + (int)($imports['running'] ?? 0); $waiting = (int)$shipping['waiting']; $failing = (int)$shipping['failing']; ?>
<?php if (!$failed && !$queued && !$waiting): ?><p class="empty">Nothing is waiting or failing.</p><?php else: ?>
<ul class="plain">
<?php if ($queued): ?><li><?= $queued ?> chat export<?= $queued > 1 ? 's' : '' ?> waiting to be filed. If this number does not fall, the cron worker is not running.</li><?php endif; ?>
<?php if ($failed): ?><li><?= $failed ?> chat export<?= $failed > 1 ? 's' : '' ?> could not be read. Open the user to see which.</li><?php endif; ?>
<?php if ($waiting): ?><li><?= $waiting ?> file<?= $waiting > 1 ? 's' : '' ?> waiting to move to object storage<?= $failing ? ', ' . $failing . ' of them failing. Test the storage location.' : '.' ?></li><?php endif; ?>
</ul>
<?php endif; ?>
</section>

<section class="block">
<div class="block-head"><h2>Storage</h2><a href="/admin/storage">Manage storage</a></div>
<ul class="plain">
<?php foreach ($storage as $s): ?>
<li><strong><?= h($s['name']) ?></strong><?= $s['is_default'] ? ' <span class="tag">New uploads</span>' : '' ?><?= $s['status'] !== 'active' ? ' <span class="tag">Read-only</span>' : '' ?><br><?php require __DIR__ . '/_storage_usage.php'; ?></li>
<?php endforeach; ?>
</ul>
</section>
