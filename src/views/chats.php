<?php $title = 'Chats'; $names = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'];
$state = ['q' => $term, 'source' => $source, 'folder' => $folder, 'starred' => $starred ? 1 : '', 'month' => $month, 'sort' => $sort === 'new' ? '' : $sort];
$link = fn(array $over) => '/app/chats?' . http_build_query(array_filter($over + $state, fn($v) => $v !== '' && $v !== null));
$here = $link(['page' => $page > 1 ? $page : '']);
$view = fn(array $over) => $link($over + ['folder' => '', 'starred' => '', 'page' => '']);   // sidebar links reset the folder/star choice
$folderNames = array_column($folders, 'name', 'id');
$current = $starred ? 'starred' : ($folder !== '' ? $folder : 'all');
$star = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.3 2.8 1-6L3.300 9.900l6-.9z"/></svg>';
$list = $hits !== null ? array_values($hits) : $convs; ?>
<h1>Chat archive</h1>
<form method="get" action="/app/chats" class="search" role="search">
<label class="sr" for="q">Search your chats</label><input id="q" name="q" type="search" value="<?= h($term) ?>" placeholder="<?= $current === 'all' ? 'Search every conversation' : 'Search this view' ?>">
<?php foreach (['source', 'folder', 'starred', 'month'] as $k): if ($state[$k] !== '' && $state[$k] !== null): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$state[$k]) ?>"><?php endif; endforeach; ?>
<button class="btn btn-accent">Search</button>
</form>

<div class="archive">
<aside class="folders" aria-label="Folders">
<ul class="folder-list">
<li><a href="<?= h($view([])) ?>"<?= $current === 'all' ? ' aria-current="true"' : '' ?>><span>All conversations</span><span class="n"><?= number_format((int)$totals['total']) ?></span></a></li>
<li><a href="<?= h($view(['starred' => 1])) ?>"<?= $current === 'starred' ? ' aria-current="true"' : '' ?>><span>Starred</span><span class="n"><?= number_format((int)$totals['starred']) ?></span></a></li>
<li><a href="<?= h($view(['folder' => 'none'])) ?>"<?= $current === 'none' ? ' aria-current="true"' : '' ?>><span>Not in a folder</span><span class="n"><?= number_format((int)$totals['unfiled']) ?></span></a></li>
<?php if ($folders): ?><li class="sep" aria-hidden="true"></li><?php endif; ?>
<?php foreach ($folders as $f): ?>
<li><a href="<?= h($view(['folder' => $f['id']])) ?>"<?= $current === (string)$f['id'] ? ' aria-current="true"' : '' ?>><span><?= h($f['name']) ?></span><span class="n"><?= number_format((int)$f['n']) ?></span></a></li>
<?php endforeach; ?>
</ul>
<form method="post" action="/app/folders" class="folder-form">
<?= csrf_field() ?><input type="hidden" name="return" value="<?= h($here) ?>">
<label class="sr" for="new-folder">New folder name</label><input id="new-folder" name="name" type="text" maxlength="80" placeholder="New folder" required>
<button class="btn btn-line">Add</button>
</form>
<?php if (ctype_digit($folder) && isset($folderNames[(int)$folder])): ?>
<details>
<summary>Rename or delete “<?= h($folderNames[(int)$folder]) ?>”</summary>
<form method="post" action="/app/folders/<?= (int)$folder ?>/rename" class="folder-form"><?= csrf_field() ?><label class="sr" for="rename-folder">Folder name</label><input id="rename-folder" name="name" type="text" maxlength="80" value="<?= h($folderNames[(int)$folder]) ?>" required><button class="btn btn-line">Rename</button></form>
<form method="post" action="/app/folders/<?= (int)$folder ?>/delete"><?= csrf_field() ?><button class="linkish danger">Delete this folder</button> <span class="muted small">Its conversations stay in your archive.</span></form>
</details>
<?php endif; ?>
</aside>

<div class="archive-main">
<div class="toolbar">
<p class="filters">Show:
<a href="<?= h($link(['source' => '', 'page' => ''])) ?>"<?= $source === '' ? ' aria-current="true"' : '' ?>>All</a>
<a href="<?= h($link(['source' => 'chatgpt', 'page' => ''])) ?>"<?= $source === 'chatgpt' ? ' aria-current="true"' : '' ?>>ChatGPT</a>
<a href="<?= h($link(['source' => 'claude', 'page' => ''])) ?>"<?= $source === 'claude' ? ' aria-current="true"' : '' ?>>Claude</a>
</p>
<form method="get" action="/app/chats">
<?php foreach (['q', 'source', 'folder', 'starred'] as $k): if ($state[$k] !== '' && $state[$k] !== null): ?><input type="hidden" name="<?= $k ?>" value="<?= h((string)$state[$k]) ?>"><?php endif; endforeach; ?>
<label>Month<select name="month"><option value="">Any time</option><?php foreach ($months as $mo): ?><option value="<?= h($mo['ym']) ?>"<?= $month === $mo['ym'] ? ' selected' : '' ?>><?= h(gmdate('F Y', strtotime($mo['ym'] . '-01 UTC'))) ?> (<?= (int)$mo['n'] ?>)</option><?php endforeach; ?></select></label>
<?php if ($hits === null): ?><label>Sort<select name="sort"><?php foreach (['new' => 'Newest first', 'old' => 'Oldest first', 'long' => 'Longest first', 'title' => 'Title A to Z'] as $k => $lab): ?><option value="<?= $k ?>"<?= $sort === $k ? ' selected' : '' ?>><?= $lab ?></option><?php endforeach; ?></select></label><?php endif; ?>
<button class="btn btn-line">Apply</button>
</form>
</div>

<?php if ($hits !== null): ?><p class="muted"><?= count($list) ?> conversation<?= count($list) === 1 ? '' : 's' ?> for “<?= h($term) ?>” · <a href="<?= h($link(['q' => '', 'page' => ''])) ?>">Clear search</a></p><?php endif; ?>
<?php if (!$list): ?>
<p class="empty"><?= $hits !== null ? 'No conversation here mentions that.' : ((int)$totals['total'] ? 'Nothing in this view yet.' : 'No conversations filed yet. Upload a ChatGPT or Claude export from the Overview page.') ?></p>
<?php else: ?>
<form method="post" action="/app/chats/bulk" id="bulk">
<?= csrf_field() ?><input type="hidden" name="return" value="<?= h($here) ?>">
<div class="bulk">
<label><input type="checkbox" id="pick-all"> Select all on this page</label>
<label class="sr" for="bulk-action">Action for selected conversations</label>
<select id="bulk-action" name="action">
<option value="">With selected…</option>
<option value="star">Add a star</option>
<option value="unstar">Remove the star</option>
<?php foreach ($folders as $f): ?><option value="move:<?= (int)$f['id'] ?>">Move to <?= h($f['name']) ?></option><?php endforeach; ?>
<option value="unfile">Take out of folder</option>
</select>
<button class="btn btn-accent">Apply</button>
</div>
<ul class="conv-list">
<?php $lastMonth = null; foreach ($list as $c):
    if ($hits === null && in_array($sort, ['new', 'old'], true) && $month === '') {
        $ym = $c['updated_at'] ? gmdate('F Y', strtotime($c['updated_at'] . ' UTC')) : 'No date';
        if ($ym !== $lastMonth) { echo '<li class="month-head">' . h($ym) . '</li>'; $lastMonth = $ym; }
    } ?>
<li class="conv">
<label class="pick"><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>"><span class="sr">Select <?= h($c['title']) ?></span></label>
<a href="/app/chats/<?= (int)$c['id'] ?>">
<span class="conv-title"><?= h($c['title']) ?></span>
<?php if (!empty($c['snippet'])): ?><span class="snippet"><?= h($c['snippet']) ?></span><?php endif; ?>
<span class="conv-meta"><span class="tag tag-<?= h($c['source']) ?>"><?= h($names[$c['source']] ?? $c['source']) ?></span> <?= h(fmt_date($c['updated_at'])) ?> · <?= (int)$c['message_count'] ?> messages<?php if ($c['folder_id'] && $current !== (string)$c['folder_id'] && isset($folderNames[(int)$c['folder_id']])): ?> · in <?= h($folderNames[(int)$c['folder_id']]) ?><?php endif; ?><?php if (($c['count'] ?? 0) > 1): ?> · <?= (int)$c['count'] ?> matching messages<?php endif; ?></span>
</a>
<button class="star<?= $c['starred'] ? ' on' : '' ?>" formaction="/app/chats/<?= (int)$c['id'] ?>/star" aria-label="<?= $c['starred'] ? 'Remove star from' : 'Star' ?> <?= h($c['title']) ?>" aria-pressed="<?= $c['starred'] ? 'true' : 'false' ?>"><?= $star ?></button>
</li>
<?php endforeach; ?>
</ul>
</form>
<?php if ($hits === null && ($page > 1 || $more)): ?>
<p class="pager"><?php if ($page > 1): ?><a href="<?= h($link(['page' => $page - 1 > 1 ? $page - 1 : ''])) ?>">Previous page</a><?php endif; ?><?php if ($more): ?><a href="<?= h($link(['page' => $page + 1])) ?>">Next page</a><?php endif; ?></p>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
<script>
(function () {
  var all = document.getElementById('pick-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('#bulk input[name="ids[]"]').forEach(function (b) { b.checked = all.checked; });
  });
})();
</script>
