<?php $title = $target['email']; $p = active_plan($target); $isSelf = (int)$target['id'] === (int)$admin['id']; ?>
<p><a href="/admin/users">← Users</a></p>
<h1 class="chat-title"><?= h($target['email']) ?></h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<div class="current">
<p><?= $target['suspended_at'] ? '<span class="tag">Suspended</span> ' : '' ?><?= $target['is_admin'] ? '<span class="tag">Admin</span> ' : '' ?><?= $target['email_verified_at'] ? '' : '<span class="tag">Email not confirmed</span> ' ?>Joined <?= h(fmt_date($target['created_at'])) ?> · Two-step sign-in: <?= !empty($target['totp_enabled_at']) ? 'authenticator app' : '' ?><?= !empty($target['totp_enabled_at']) && $target['key_count'] ? ' and ' : '' ?><?= $target['key_count'] ? (int)$target['key_count'] . ' security key' . ($target['key_count'] > 1 ? 's' : '') : '' ?><?= empty($target['totp_enabled_at']) && !$target['key_count'] ? 'off' : '' ?></p>
<p><strong>Plan:</strong> <?php if ($target['plan']): ?><?= h(plans()[$target['plan']]['name'] ?? $target['plan']) ?> · <?= h($target['pay_method'] ?? '') ?> · <?= h($target['plan_status'] ?? '') ?> · <?= $p ? 'through' : 'ended' ?> <?= h(fmt_date($target['paid_through'])) ?><?php else: ?>none<?php endif; ?></p>
<p><strong>Storage:</strong> <?= fmt_bytes($bytes) ?><?= $p ? ' of ' . h($p['label']) : '' ?> · <?= number_format(count($files)) ?><?= count($files) === 200 ? '+' : '' ?> files · <?= number_format($convs) ?> conversations</p>
<?php if ($target['stripe_customer_id']): ?><p class="muted small">Stripe customer <span class="mono"><?= h($target['stripe_customer_id']) ?></span><?= $target['stripe_subscription_id'] ? ' · subscription <span class="mono">' . h($target['stripe_subscription_id']) . '</span>' : '' ?>. Refunds and card changes are done in the Stripe dashboard.</p><?php endif; ?>
</div>

<section class="block">
<h2>Set plan by hand</h2>
<p class="muted">For free accounts, goodwill extensions or fixing a billing mix-up. This does not charge anyone.</p>
<form method="post" action="/admin/users/<?= (int)$target['id'] ?>/plan" class="inline-form">
<?= csrf_field() ?>
<div><label for="plan">Plan</label><select id="plan" name="plan"><option value="">No plan</option><?php foreach (plans() as $k => $pl): ?><option value="<?= h($k) ?>"<?= $target['plan'] === $k ? ' selected' : '' ?>><?= h($pl['name']) ?> (<?= h($pl['label']) ?>)</option><?php endforeach; ?></select></div>
<div><label for="paid_through">Access through</label><input id="paid_through" name="paid_through" type="date" value="<?= h($target['paid_through'] ? substr($target['paid_through'], 0, 10) : gmdate('Y-m-d', strtotime('+1 year'))) ?>"></div>
<button class="btn btn-accent">Save plan</button>
</form>
</section>

<section class="block">
<h2>Account actions</h2>
<div class="row">
<form method="post" action="/admin/users/<?= (int)$target['id'] ?>/reset"><?= csrf_field() ?><button class="btn btn-line">Send password reset email</button></form>
<?php if ((!empty($target['totp_enabled_at']) || $target['key_count']) && !$isSelf): ?><form method="post" action="/admin/users/<?= (int)$target['id'] ?>/twostep"><?= csrf_field() ?><button class="btn btn-line">Turn off two-step sign-in</button></form><?php endif; ?>
<?php if (!$target['email_verified_at']): ?><form method="post" action="/admin/users/<?= (int)$target['id'] ?>/verify"><?= csrf_field() ?><button class="btn btn-line">Mark email as confirmed</button></form><?php endif; ?>
<?php if (!$isSelf): ?>
<form method="post" action="/admin/users/<?= (int)$target['id'] ?>/<?= $target['suspended_at'] ? 'unsuspend' : 'suspend' ?>"><?= csrf_field() ?><button class="btn btn-line"><?= $target['suspended_at'] ? 'Reactivate account' : 'Suspend account' ?></button></form>
<?php endif; ?>
</div>
<p class="muted small">Suspending signs the person out and blocks sign-in. It keeps their data and does not stop Stripe billing.</p>
</section>

<section class="block">
<h2>Files</h2>
<?php if (!$files): ?><p class="empty">Nothing stored.</p><?php else: ?>
<div class="table-scroll">
<table>
<thead><tr><th scope="col">Name</th><th scope="col">Size</th><th scope="col">Uploaded</th><th scope="col">Stored in</th><th scope="col">Chat archive</th></tr></thead>
<tbody>
<?php foreach ($files as $f): ?>
<tr>
<td class="name"><?= h($f['name']) ?></td>
<td><?= fmt_bytes((int)$f['size']) ?></td>
<td><?= h(fmt_date($f['created_at'])) ?></td>
<td><?= h($names[(int)$f['backend_id']] ?? 'Unknown') ?><?php if ($f['ship_to']): ?><br><span class="muted small">Moving to <?= h($names[(int)$f['ship_to']] ?? '?') ?><?= $f['ship_error'] ? ' — failing: ' . h($f['ship_error']) : '' ?></span><?php endif; ?></td>
<td><?= match ($f['import_status']) { 'done' => 'Filed ' . number_format((int)$f['import_convs']), 'queued', 'running' => 'Waiting', 'failed' => 'Failed: ' . h($f['import_error'] ?? ''), default => '<span class="muted">File only</span>' } ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</section>

<?php if (!$isSelf): ?>
<section class="block danger-zone">
<h2>Delete account</h2>
<p>Permanently deletes this account, every file it stored and its whole chat archive, and cancels its Stripe subscription. This cannot be undone.</p>
<form method="post" action="/admin/users/<?= (int)$target['id'] ?>/delete" class="inline-form">
<?= csrf_field() ?>
<div><label for="confirm">Type <strong><?= h($target['email']) ?></strong> to confirm</label><input id="confirm" name="confirm" type="text" autocomplete="off"></div>
<button class="btn btn-danger">Delete account and data</button>
</form>
</section>
<?php endif; ?>
