<?php $title = 'Profile'; $cur = user_theme($user); ?>
<h1>Profile</h1>

<section class="block">
<h2>Photo and name</h2>
<div class="profile-top">
<?= avatar_html($user, 'lg') ?>
<div class="stack">
<form method="post" action="/app/profile/avatar" enctype="multipart/form-data" class="inline-form" id="avatar-form">
<?= csrf_field() ?>
<div><label for="photo">Photo <span class="muted small">(it is cropped to a square)</span></label><input id="photo" name="photo" type="file" accept="image/*" required></div>
<button class="btn btn-accent">Upload photo</button>
</form>
<?php if (!empty($user['avatar_at'])): ?><form method="post" action="/app/profile/avatar-remove"><?= csrf_field() ?><button class="linkish">Remove photo</button></form><?php endif; ?>
</div>
</div>
<form method="post" action="/app/profile/name" class="inline-form">
<?= csrf_field() ?>
<div><label for="display_name">Name <span class="muted small">(shown in the menu; leave empty to use your email)</span></label><input id="display_name" name="display_name" type="text" maxlength="80" autocomplete="name" value="<?= h((string)($user['display_name'] ?? '')) ?>"></div>
<button class="btn btn-accent">Save name</button>
</form>
<p class="muted">Email: <strong><?= h($user['email']) ?></strong> · Member since <?= h(fmt_date($user['created_at'])) ?></p>
</section>

<section class="block" id="appearance">
<h2>Appearance</h2>
<form method="post" action="/app/profile/theme" class="stack">
<?= csrf_field() ?>
<fieldset class="themes"><legend class="sr">Theme</legend>
<?php foreach (themes() as $key => $t): ?>
<label class="theme-opt">
<span class="swatch" aria-hidden="true"><?php foreach ($t['swatch'] as $c): ?><i style="background: <?= h($c) ?>"></i><?php endforeach; ?></span>
<span class="radio-line"><input type="radio" name="theme" value="<?= h($key) ?>"<?= $cur === $key ? ' checked' : '' ?>> <?= h($t['name']) ?></span>
<span class="muted small"><?= h($t['note']) ?></span>
</label>
<?php endforeach; ?>
</fieldset>
<div><button class="btn btn-accent">Use this theme</button></div>
</form>
</section>

<section class="block">
<h2>Password</h2>
<form method="post" action="/app/profile/password" class="inline-form">
<?= csrf_field() ?>
<div><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
<div><label for="new_password">New password <span class="muted small">(10 characters or more)</span></label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="10" required></div>
<button class="btn btn-accent">Change password</button>
</form>
<p class="muted">Two-step sign-in is <?= second_factor_on($user) ? 'on' : 'off' ?>. <a href="/app/security">Manage it on the Security page</a>.</p>
</section>
<script>
// Shrink the chosen photo to 512px in the browser before it is sent, so large phone photos
// upload quickly and stay under the server's size limits. Falls back to the original file.
(function () {
  var form = document.getElementById('avatar-form'), input = document.getElementById('photo');
  if (!form || !window.createImageBitmap || !window.DataTransfer) return;
  var ready = false;
  form.addEventListener('submit', function (e) {
    if (ready || !input.files || !input.files[0]) return;
    e.preventDefault();
    var done = function () { ready = true; form.requestSubmit ? form.requestSubmit() : form.submit(); };
    createImageBitmap(input.files[0], { imageOrientation: 'from-image' }).then(function (bmp) {
      var side = Math.min(bmp.width, bmp.height), c = document.createElement('canvas');
      c.width = c.height = 512;
      c.getContext('2d').drawImage(bmp, (bmp.width - side) / 2, (bmp.height - side) / 2, side, side, 0, 0, 512, 512);
      c.toBlob(function (blob) {
        if (blob) {
          var dt = new DataTransfer();
          dt.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
          input.files = dt.files;
        }
        done();
      }, 'image/jpeg', 0.9);
    }).catch(done);
  });
})();
</script>
