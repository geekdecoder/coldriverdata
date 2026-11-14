<?php $title = 'Users'; $nowTs = time(); ?>
<h1>Users</h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<form method="get" action="/admin/users" class="search" role="search"><label class="sr" for="q">Search by email</label><input id="q" name="q" type="search" value="<?= h($term) ?>" placeholder="Search by email"><button class="btn btn-accent">Search</button></form>
<?php if (!$users): ?><p class="empty">No accounts<?= $term !== '' ? ' match that' : ' yet' ?>.</p><?php else: ?>
<div class="table-scroll">
<table>
<thead><tr><th scope="col">Email</th><th scope="col">Plan</th><th scope="col">Paid through</th><th scope="col">Stored</th><th scope="col">Joined</th></tr></thead>
<tbody>
<?php foreach ($users as $u2): $current = $u2['plan'] && $u2['paid_through'] && strtotime($u2['paid_through'] . ' UTC') > $nowTs; ?>
<tr>
<td class="name"><a href="/admin/users/<?= (int)$u2['id'] ?>"><?= h($u2['email']) ?></a><?= $u2['is_admin'] ? ' <span class="tag">Admin</span>' : '' ?><?= $u2['suspended_at'] ? ' <span class="tag">Suspended</span>' : '' ?></td>
<td><?= $u2['plan'] ? h(plans()[$u2['plan']]['name'] ?? $u2['plan']) . ' · ' . h($u2['pay_method'] ?? '') . ($current ? ($u2['plan_status'] === 'past_due' ? ' · payment failed' : '') : ' · ended') : '<span class="muted">None</span>' ?></td>
<td><?= h(fmt_date($u2['paid_through'])) ?></td>
<td><?= fmt_bytes((int)$u2['bytes']) ?></td>
<td><?= h(fmt_date($u2['created_at'])) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($page > 1 || $more): ?><p class="pager"><?php if ($page > 1): ?><a href="/admin/users?<?= h(http_build_query(array_filter(['q' => $term, 'page' => $page - 1]))) ?>">Newer</a><?php endif; ?><?php if ($more): ?><a href="/admin/users?<?= h(http_build_query(array_filter(['q' => $term, 'page' => $page + 1]))) ?>">Older</a><?php endif; ?></p><?php endif; ?>
<?php endif; ?>
