<?php $title = 'Overview'; $names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude']; ?>
<h1>Your warehouse</h1>
<?php require __DIR__ . '/_usage.php'; ?>
<?php require __DIR__ . '/_uploader.php'; ?>
<?php if ($pending): ?><p class="flash flash-ok">Filing <?= $pending ?> export<?= $pending > 1 ? 's' : '' ?> into your archive. Large exports take a few minutes; reload to see progress.</p><?php endif; ?>

<section class="block">
<div class="block-head"><h2>Chat archive</h2><a href="/app/chats">Open the archive</a></div>
<?php if (!$stats['convs']): ?>
<div class="empty">
<p><strong>No conversations filed yet.</strong> To get your export:</p>
<ul>
<li><strong>ChatGPT:</strong> Settings, then Data controls, then Export data. The download link arrives by email.</li>
<li><strong>Claude:</strong> Settings, then Privacy, then Export data. The emailed link downloads a small manifest file, not your chats. Open the manifest, find <span class="mono">conversations-000.zip</span>, and open its <span class="mono">export_url</span> in the browser where you are signed in to Claude. Upload the .zip that downloads.</li>
</ul>
<p>Upload the .zip above exactly as it downloads; there is no need to unpack it.</p>
</div>
<?php else: ?>
<p class="muted"><?= number_format((int)$stats['convs']) ?> conversations, <?= number_format((int)$stats['msgs']) ?> messages<?php foreach ($bySource as $s): ?> · <?= h($names[$s['source']] ?? $s['source']) ?> <?= number_format((int)$s['n']) ?><?php endforeach; ?></p>
<form method="get" action="/app/chats" class="search" role="search"><label class="sr" for="q">Search your chats</label><input id="q" name="q" type="search" placeholder="Search every conversation"><button class="btn btn-accent">Search</button></form>
<ul class="conv-list">
<?php foreach ($recent as $c): ?>
<li><a href="/app/chats/<?= (int)$c['id'] ?>"><span class="conv-title"><?= h($c['title']) ?></span><span class="conv-meta"><span class="tag tag-<?= h($c['source']) ?>"><?= h($names[$c['source']] ?? $c['source']) ?></span> <?= h(fmt_date($c['updated_at'])) ?> · <?= (int)$c['message_count'] ?> messages</span></a></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
