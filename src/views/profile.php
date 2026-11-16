<?php $title = 'Profile'; $cur = user_theme($user); ?>
<h1>Profile</h1>

<section class="block">
<h2>Photo and name</h2>
<div class="profile-top">
<?= avatar_html($user, 'lg') ?>
<div class="stack">
<form method="post" action="/app/profile/avatar" enctype="multipart/form-data" class="inline-form">
<?= csrf_field() ?>
<div><label for="photo">Photo <span class="muted small">(JPEG, PNG or WebP; it is cropped to a square)</span></label><input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></div>
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
<p class="muted">Two-step sign-in is <?= two_step_on($user) ? 'on' : 'off' ?>. <a href="/app/security">Manage it on the Security page</a>.</p>
</section>
