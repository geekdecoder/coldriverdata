<?php $title = $conv['title']; $names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude']; $who = $names[$conv['source']] ?? 'Assistant'; ?>
<p><a href="/app/chats">← Chat archive</a></p>
<h1 class="chat-title"><?= h($conv['title']) ?></h1>
<p class="muted"><span class="tag tag-<?= h($conv['source']) ?>"><?= h($who) ?></span> <?= h(fmt_date($conv['started_at'])) ?><?= $conv['updated_at'] && fmt_date($conv['updated_at']) !== fmt_date($conv['started_at']) ? ' to ' . h(fmt_date($conv['updated_at'])) : '' ?> · <?= (int)$conv['message_count'] ?> messages</p>
<div class="chat-tools">
<form method="post" action="/app/chats/<?= (int)$conv['id'] ?>/star"><?= csrf_field() ?><input type="hidden" name="return" value="/app/chats/<?= (int)$conv['id'] ?>">
<button class="star<?= $conv['starred'] ? ' on' : '' ?>" aria-pressed="<?= $conv['starred'] ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.3 2.8 1-6L3.300 9.900l6-.9z"/></svg><?= $conv['starred'] ? 'Starred' : 'Star' ?></button></form>
<form method="post" action="/app/chats/<?= (int)$conv['id'] ?>/move"><?= csrf_field() ?><input type="hidden" name="return" value="/app/chats/<?= (int)$conv['id'] ?>">
<label class="sr" for="move-folder">Folder</label>
<select id="move-folder" name="folder"><option value="">Not in a folder</option><?php foreach ($folders as $f): ?><option value="<?= (int)$f['id'] ?>"<?= (int)$conv['folder_id'] === (int)$f['id'] ? ' selected' : '' ?>><?= h($f['name']) ?></option><?php endforeach; ?></select>
<button class="btn btn-line">Move</button></form>
</div>
<div class="thread">
<?php foreach ($messages as $m): ?>
<article class="msg msg-<?= h($m['role']) ?>">
<header><strong><?= $m['role'] === 'user' ? 'You' : h($who) ?></strong><?php if ($m['sent_at']): ?> <time datetime="<?= h(str_replace(' ', 'T', $m['sent_at'])) ?>Z"><?= h(fmt_date($m['sent_at'], 'M j, Y g:i a')) ?> UTC</time><?php endif; ?></header>
<div class="msg-body"><?= h($m['body']) ?></div>
</article>
<?php endforeach; ?>
</div>
