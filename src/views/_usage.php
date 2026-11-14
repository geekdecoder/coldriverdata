<?php $cap = $plan['bytes'] ?? 0; $pct = $cap ? min(100, (int)round($used / $cap * 100)) : 0; ?>
<div class="usage">
<div class="usage-line"><strong><?= fmt_bytes($used) ?></strong> used<?php if ($plan): ?> of <?= h($plan['label']) ?> · <?= h($plan['name']) ?> plan<?php endif; ?></div>
<?php if ($plan): ?><div class="meter" role="img" aria-label="<?= $pct ?> percent of storage used"><div style="width: <?= $pct ?>%"></div></div><?php endif; ?>
</div>
