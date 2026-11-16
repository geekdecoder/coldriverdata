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
<div class="reader-bar" id="reader-bar" hidden>
<div class="seg" role="group" aria-label="View">
<button type="button" data-mode="formatted">Formatted</button><button type="button" data-mode="plain">Plain</button><button type="button" data-mode="source">Source</button><button type="button" data-mode="code">Code only</button>
</div>
<div class="seg" role="group" aria-label="Show messages from">
<button type="button" data-who="all">Everyone</button><button type="button" data-who="user">You</button><button type="button" data-who="assistant"><?= h($who) ?></button>
</div>
<div class="seg" role="group" aria-label="Text size">
<button type="button" data-size="-1" aria-label="Smaller text">A−</button><button type="button" data-size="1" aria-label="Larger text">A+</button>
</div>
<button type="button" class="mini" id="toggle-stats" aria-expanded="false" aria-controls="stats">Stats</button>
<button type="button" class="mini" id="copy-all">Copy all</button>
<button type="button" class="mini" id="do-print">Print or save as PDF</button>
<span class="starter"><label class="sr" for="starter-n">How much to include</label><select id="starter-n"><option value="10">Last 10 messages</option><option value="30">Last 30 messages</option><option value="all">Whole conversation</option></select><button type="button" class="mini" id="copy-starter">Copy as a new-chat starter</button></span>
</div>
<p class="downloads">Download: <a href="/app/chats/<?= (int)$conv['id'] ?>/export/md">Markdown</a> <a href="/app/chats/<?= (int)$conv['id'] ?>/export/txt">Plain text</a> <a href="/app/chats/<?= (int)$conv['id'] ?>/export/json">JSON</a></p>
<section id="stats" class="statbox" hidden><h2>About this conversation</h2><div id="stats-body"></div></section>
<p id="reader-toast" class="toast" role="status" aria-live="polite" hidden></p>
<p id="reader-empty" class="empty" hidden>Nothing to show in this view. Try Formatted, or Everyone.</p>
<div class="thread" id="thread" data-title="<?= h($conv['title']) ?>" data-assistant="<?= h($who) ?>">
<?php foreach ($messages as $m): ?>
<article class="msg msg-<?= h($m['role']) ?>" data-role="<?= h($m['role']) ?>">
<header><strong><?= $m['role'] === 'user' ? 'You' : h($who) ?></strong><?php if ($m['sent_at']): ?> <time datetime="<?= h(str_replace(' ', 'T', $m['sent_at'])) ?>Z"><?= h(fmt_date($m['sent_at'], 'M j, Y g:i a')) ?> UTC</time><?php endif; ?></header>
<div class="msg-body"><?= h($m['body']) ?></div>
</article>
<?php endforeach; ?>
</div>
<script src="<?= h(asset('/assets/vendor/marked.js')) ?>" defer></script>
<script src="<?= h(asset('/assets/vendor/purify.min.js')) ?>" defer></script>
<script src="<?= h(asset('/assets/vendor/highlight.min.js')) ?>" defer></script>
<script src="<?= h(asset('/assets/chat.js')) ?>" defer></script>
