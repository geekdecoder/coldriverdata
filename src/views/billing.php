<?php $title = 'Plan';
$crypto = (bool)cfg('stripe.crypto_enabled');
$hasCard = $user['pay_method'] === 'card' && $user['stripe_subscription_id'] && in_array($user['plan_status'], ['active', 'past_due'], true); ?>
<h1>Plan and billing</h1>
<?php if ($paid && !$plan): ?><p class="flash flash-ok">Payment received. Your plan switches on as soon as Stripe confirms it, usually within a minute. Reload this page.</p><?php endif; ?>
<?php if ($plan): ?>
<div class="current">
<p><strong><?= h($plan['name']) ?></strong> · <?= h($plan['label']) ?> · <?= ['crypto' => 'paid by USDC', 'manual' => 'set up by support'][$user['pay_method']] ?? 'paid by card' ?></p>
<p class="muted"><?php if ($user['pay_method'] === 'manual'): ?>Runs through <?= h(fmt_date($user['paid_through'])) ?>.<?php elseif ($user['pay_method'] === 'crypto'): ?>Paid through <?= h(fmt_date($user['paid_through'])) ?>. Crypto plans do not renew by themselves; pay again before that date to extend.<?php elseif ($user['plan_status'] === 'past_due'): ?>Your last card payment failed. Update your card to keep the plan.<?php elseif ($user['plan_status'] === 'canceled'): ?>Cancelled. Access continues until <?= h(fmt_date($user['paid_through'])) ?>.<?php else: ?>Renews automatically.<?php endif; ?></p>
<?php require __DIR__ . '/_usage.php'; ?>
<?php if ($user['stripe_customer_id'] && $user['pay_method'] === 'card'): ?><form method="post" action="/app/billing/portal"><?= csrf_field() ?><button class="btn btn-line">Change plan, card or cancel</button></form><?php endif; ?>
</div>
<?php elseif ($user['plan']): ?>
<p class="flash flash-warn">Your <?= h(plans()[$user['plan']]['name'] ?? '') ?> plan ended on <?= h(fmt_date($user['paid_through'])) ?>. Your data is still here; choose a plan to upload again.</p>
<?php endif; ?>

<?php if (!$hasCard): ?>
<h2><?= $plan ? 'Renew or change plan' : 'Choose a plan' ?></h2>
<div class="cards">
<?php foreach (plans() as $key => $p): $tooSmall = $used > $p['bytes']; ?>
<div class="card plan<?= ($want === $key || (!$want && !empty($p['featured']))) ? ' featured' : '' ?>">
<h3><?= h($p['name']) ?></h3>
<p class="muted small"><?= h($p['tagline']) ?></p>
<p class="price"><?= fmt_money($p['monthly']) ?><span> / month</span></p>
<ul><li><?= h($p['label']) ?> of storage</li><li>Chat archive with reader and search</li><li>File storage and downloads</li></ul>
<?php if ($tooSmall): ?><p class="muted small">You already store more than this plan holds.</p>
<?php else: ?>
<form method="post" action="/app/billing/checkout" class="pay">
<?= csrf_field() ?><input type="hidden" name="plan" value="<?= h($key) ?>">
<button class="btn btn-accent" name="pay" value="card_monthly">Card, <?= fmt_money($p['monthly']) ?> a month</button>
<button class="btn btn-line" name="pay" value="card_yearly">Card, <?= fmt_money($p['yearly']) ?> a year</button>
<?php if ($crypto): ?><button class="btn btn-line" name="pay" value="crypto_yearly">USDC, <?= fmt_money($p['yearly']) ?> for 12 months</button><?php endif; ?>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<p class="muted">Card payments renew automatically and can be cancelled any time. Payment is handled by Stripe; this site never sees your card or wallet.</p>
<?php endif; ?>
