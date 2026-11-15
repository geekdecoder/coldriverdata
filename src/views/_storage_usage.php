<?php // expects $s (a storage row)
$cap = $s['capacity_bytes'] ? (int)$s['capacity_bytes'] : null; $pct = $cap ? min(100, (int)round($s['bytes'] / $cap * 100)) : null; ?>
<?= fmt_bytes($s['bytes']) ?><?= $cap ? ' of ' . fmt_bytes($cap) : '' ?> · <?= number_format($s['files']) ?> files<?= isset($s['disk_free']) && $s['disk_free'] !== null ? ' · ' . fmt_bytes($s['disk_free']) . ' free on disk' : '' ?>
<?php if ($pct !== null): ?><div class="meter" role="img" aria-label="<?= $pct ?> percent used"><div style="width: <?= $pct ?>%"></div></div><?php endif; ?>
