<?php $title = 'Storage'; $v = fn(string $k) => h($form[$k] ?? ''); $isS3 = ($form['type'] ?? 's3') === 's3'; ?>
<h1>Storage</h1>
<?php require __DIR__ . '/_admin_nav.php'; ?>
<p class="muted">New uploads go to the location marked "New uploads". Files already stored stay where they are, so you can add capacity without moving anything.</p>
<div class="store-list">
<?php foreach ($storage as $s): ?>
<div class="card store">
<div class="block-head"><h3><?= h($s['name']) ?></h3><span><?= $s['is_default'] ? '<span class="tag tag-claude">New uploads</span>' : '' ?><?= $s['status'] !== 'active' ? '<span class="tag">Read-only</span>' : '' ?></span></div>
<p class="small mono"><?= $s['type'] === 's3' ? 'Object storage · ' . h($s['bucket']) . ' at ' . h($s['endpoint']) . ' (' . h($s['region']) . ')' : 'Folder · ' . h($s['path']) ?></p>
<div><?php require __DIR__ . '/_storage_usage.php'; ?></div>
<div class="row">
<?php if (!$s['is_default'] && $s['status'] === 'active'): ?><form method="post" action="/admin/storage/<?= (int)$s['id'] ?>/default"><?= csrf_field() ?><button class="btn btn-line">Send new uploads here</button></form><?php endif; ?>
<?php if ($s['id']): ?>
<form method="post" action="/admin/storage/<?= (int)$s['id'] ?>/test"><?= csrf_field() ?><button class="btn btn-line">Test connection</button></form>
<form method="post" action="/admin/storage/<?= (int)$s['id'] ?>/status"><?= csrf_field() ?><button class="btn btn-line"><?= $s['status'] === 'active' ? 'Make read-only' : 'Make active' ?></button></form>
<?php if (!$s['files']): ?><form method="post" action="/admin/storage/<?= (int)$s['id'] ?>/remove"><?= csrf_field() ?><button class="linkish danger">Remove from list</button></form><?php endif; ?>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>

<section class="block">
<h2>Add a storage location</h2>
<?php if (!$hasKey): ?><p class="flash flash-warn">Object storage needs an <span class="mono">app_key</span> in config.php, which encrypts the secret key before it is saved. Generate one with <span class="mono">php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'</span> and add it; see config.sample.php. Folders can be added without it.</p><?php endif; ?>
<form method="post" action="/admin/storage/add" class="form storage-form">
<?= csrf_field() ?>
<fieldset><legend>Type</legend>
<label class="radio"><input type="radio" name="type" value="s3"<?= $isS3 ? ' checked' : '' ?>> Object storage (Hetzner Object Storage, Backblaze B2, any S3-compatible service)</label>
<label class="radio"><input type="radio" name="type" value="local"<?= $isS3 ? '' : ' checked' ?>> Folder on this server (an extra disk or a mounted volume)</label>
</fieldset>
<label for="s-name">Name</label><input id="s-name" name="name" type="text" value="<?= $v('name') ?>" placeholder="Hetzner Falkenstein 1" required>
<div data-for="s3">
<label for="s-endpoint">Endpoint</label><input id="s-endpoint" name="endpoint" type="text" value="<?= $v('endpoint') ?>" placeholder="fsn1.your-objectstorage.com">
<label for="s-region">Region</label><input id="s-region" name="region" type="text" value="<?= $v('region') ?>" placeholder="fsn1">
<label for="s-bucket">Bucket name</label><input id="s-bucket" name="bucket" type="text" value="<?= $v('bucket') ?>" placeholder="coldriverdata-files">
<label for="s-access">Access key</label><input id="s-access" name="access_key" type="text" value="<?= $v('access_key') ?>" autocomplete="off">
<label for="s-secret">Secret key</label><input id="s-secret" name="secret_key" type="password" autocomplete="new-password">
<label class="radio"><input type="checkbox" name="path_style" value="1"<?= !empty($form['path_style']) ? ' checked' : '' ?>> Use path-style addresses (leave off for Hetzner; some other services need it)</label>
</div>
<div data-for="local">
<label for="s-path">Folder path</label><input id="s-path" name="path" type="text" value="<?= $v('path') ?>" placeholder="/mnt/volume1/coldriver">
</div>
<label for="s-cap">Capacity in GB <span class="muted small">(optional, only for the usage bar)</span></label><input id="s-cap" name="capacity_gb" type="number" min="0" step="any" value="<?= $v('capacity_gb') ?>">
<button class="btn btn-accent btn-lg">Test and add</button>
</form>
<p class="muted small">Adding a location writes a small test object, reads it back and deletes it. The bucket must already exist and be private.</p>
</section>
<script>
(function () {
  var form = document.querySelector('.storage-form');
  function sync() {
    var type = form.querySelector('input[name=type]:checked').value;
    form.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.dataset.for !== type; });
  }
  form.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
