<?php $title = 'Chats'; $names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
$link = fn(array $over) => '/app/chats?' . http_build_query(array_filter($over + ['q' => $term, 'source' => $source])); ?>
<h1>Chat archive</h1>
<form method="get" action="/app/chats" class="search" role="search">
<label class="sr" for="q">Search your chats</label><input id="q" name="q" type="search" value="<?= h($term) ?>" placeholder="Search every conversation">
<?php if ($source): ?><input type="hidden" name="source" value="<?= h($source) ?>"><?php endif; ?>
<button class="btn btn-accent">Search</button>
</form>
<p class="filters">Show:
<a href="<?= h($link(['source' => ''])) ?>"<?= $source === '' ? ' aria-current="true"' : '' ?>>All</a>
<a href="<?= h($link(['source' => 'chatgpt'])) ?>"<?= $source === 'chatgpt' ? ' aria-current="true"' : '' ?>>ChatGPT</a>
<a href="<?= h($link(['source' => 'claude'])) ?>"<?= $source === 'claude' ? ' aria-current="true"' : '' ?>>Claude</a>
</p>
<?php $list = $hits !== null ? array_values($hits) : $convs; ?>
<?php if ($hits !== null): ?><p class="muted"><?= count($list) ?> conversation<?= count($list) === 1 ? '' : 's' ?> for “<?= h($term) ?>” · <a href="/app/chats">Clear search</a></p><?php endif; ?>
<?php if (!$list): ?>
<p class="empty"><?= $hits !== null ? 'No conversation mentions that.' : 'No conversations filed yet. Upload a ChatGPT or Claude export from the Overview page.' ?></p>
<?php else: ?>
<ul class="conv-list">
<?php foreach ($list as $c): ?>
<li><a href="/app/chats/<?= (int)$c['id'] ?>">
<span class="conv-title"><?= h($c['title']) ?></span>
<?php if (!empty($c['snippet'])): ?><span class="snippet"><?= h($c['snippet']) ?></span><?php endif; ?>
<span class="conv-meta"><span class="tag tag-<?= h($c['source']) ?>"><?= h($names[$c['source']] ?? $c['source']) ?></span> <?= h(fmt_date($c['updated_at'])) ?> · <?= (int)$c['message_count'] ?> messages<?php if (($c['count'] ?? 0) > 1): ?> · <?= (int)$c['count'] ?> matching messages<?php endif; ?></span>
</a></li>
<?php endforeach; ?>
</ul>
<?php if ($hits === null && ($page > 1 || $more)): ?>
<p class="pager"><?php if ($page > 1): ?><a href="<?= h($link(['page' => $page - 1])) ?>">Newer</a><?php endif; ?><?php if ($more): ?><a href="<?= h($link(['page' => $page + 1])) ?>">Older</a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
