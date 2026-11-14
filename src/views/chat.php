<?php $title = $conv['title']; $names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude']; $who = $names[$conv['source']] ?? 'Assistant'; ?>
<p><a href="/app/chats">← Chat archive</a></p>
<h1 class="chat-title"><?= h($conv['title']) ?></h1>
<p class="muted"><span class="tag tag-<?= h($conv['source']) ?>"><?= h($who) ?></span> <?= h(fmt_date($conv['started_at'])) ?><?= $conv['updated_at'] && fmt_date($conv['updated_at']) !== fmt_date($conv['started_at']) ? ' to ' . h(fmt_date($conv['updated_at'])) : '' ?> · <?= (int)$conv['message_count'] ?> messages</p>
<div class="thread">
<?php foreach ($messages as $m): ?>
<article class="msg msg-<?= h($m['role']) ?>">
<header><strong><?= $m['role'] === 'user' ? 'You' : h($who) ?></strong><?php if ($m['sent_at']): ?> <time datetime="<?= h(str_replace(' ', 'T', $m['sent_at'])) ?>Z"><?= h(fmt_date($m['sent_at'], 'M j, Y g:i a')) ?> UTC</time><?php endif; ?></header>
<div class="msg-body"><?= h($m['body']) ?></div>
</article>
<?php endforeach; ?>
</div>
