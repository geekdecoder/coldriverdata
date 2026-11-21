<?php $title = 'Security'; $on = two_step_on($user); ?>
<h1>Security</h1>
<?php if ($codes): ?>
<section class="block codes-box">
<h2>Save your recovery codes</h2>
<p>Each code signs you in once if you do not have your phone or security key. They are shown only this one time. Print them or keep them in a password manager.</p>
<ul class="codes mono"><?php foreach ($codes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<p class="muted">Two-step sign-in adds a second check after your password: a security key you plug in, a code from an app on your phone, or both. It is <strong><?= second_factor_on($user) ? 'on' : 'off' ?></strong> for your account.</p>

<section class="block">
<h2>Security keys</h2>
<p class="muted">A YubiKey or other FIDO2 security key. It cannot be phished: the key only answers on this site's real address.</p>
<?php if ($keys): ?>
<div class="table-scroll"><table>
<thead><tr><th scope="col">Key</th><th scope="col">Added</th><th scope="col">Last used</th><th scope="col"><span class="sr">Remove</span></th></tr></thead>
<tbody>
<?php foreach ($keys as $k): ?>
<tr><td class="name"><?= h($k['name']) ?></td><td><?= h(fmt_date($k['created_at'])) ?></td><td><?= $k['last_used_at'] ? h(fmt_date($k['last_used_at'])) : '<span class="muted">Not yet</span>' ?></td>
<td><details><summary class="linkish danger">Remove</summary><form method="post" action="/app/security/keys/<?= (int)$k['id'] ?>/remove" class="inline-form"><?= csrf_field() ?><div><label for="kp<?= (int)$k['id'] ?>">Your password</label><input id="kp<?= (int)$k['id'] ?>" name="password" type="password" autocomplete="current-password" required></div><button class="btn btn-danger">Remove this key</button></form></details></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div data-webauthn="register" data-csrf="<?= h(csrf_token()) ?>" class="inline-form">
<div><label for="key-name">Name for this key <span class="muted small">(for example "YubiKey on my keyring")</span></label><input id="key-name" data-name type="text" maxlength="80"></div>
<button type="button" class="btn btn-accent" data-go><?= $keys ? 'Add another security key' : 'Add a security key' ?></button>
<p data-msg role="status" aria-live="polite" hidden style="flex-basis:100%"></p>
</div>
<?php if (count($keys) === 1): ?><p class="muted small">Add a second key if you have one, and keep it somewhere safe. A lost key is then an inconvenience, not a lockout.</p><?php endif; ?>
</section>
<script src="<?= h(asset('/assets/webauthn.js')) ?>" defer></script>

<section class="block">
<h2>Authenticator app</h2>
<?php if (!$available): ?>
<p class="flash flash-warn">The authenticator app option is not available yet on this site.</p>
<?php elseif ($on): ?>
<div class="current">
<p><strong>On</strong> since <?= h(fmt_date($user['totp_enabled_at'])) ?>.</p>
<p class="muted"><?= (int)$left ?> of 10 recovery codes unused.</p>
</div>
<h3>Make new recovery codes</h3>
<form method="post" action="/app/security/codes" class="inline-form">
<?= csrf_field() ?>
<div><label for="p1">Password</label><input id="p1" name="password" type="password" autocomplete="current-password" required></div>
<div><label for="c1">Authenticator code</label><input id="c1" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></div>
<button class="btn btn-line">Make new codes</button>
</form>
<h3>Turn off the authenticator app</h3>
<form method="post" action="/app/security/disable" class="inline-form">
<?= csrf_field() ?>
<div><label for="p2">Password</label><input id="p2" name="password" type="password" autocomplete="current-password" required></div>
<div><label for="c2">Authenticator or recovery code</label><input id="c2" name="code" type="text" autocomplete="one-time-code" required></div>
<button class="btn btn-danger">Turn off</button>
</form>
<?php elseif ($setup): ?>
<ol class="setup">
<li><strong>Install an authenticator app</strong> if you do not have one: Google Authenticator, Microsoft Authenticator, Authy or 1Password all work.</li>
<li><strong>Scan this code</strong> with the app.
<div id="qr" class="qr" data-uri="<?= h(totp_uri($setup, $user['email'])) ?>" role="img" aria-label="QR code for your authenticator app"></div>
<p class="muted small">Cannot scan? Enter this key by hand: <span class="mono key"><?= h(trim(chunk_split($setup, 4, ' '))) ?></span></p></li>
<li><strong>Enter the 6-digit code</strong> the app shows.
<form method="post" action="/app/security/confirm" class="inline-form">
<?= csrf_field() ?>
<div><label for="code">Code</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></div>
<button class="btn btn-accent">Turn on</button>
</form></li>
</ol>
<form method="post" action="/app/security/cancel"><?= csrf_field() ?><button class="linkish">Cancel</button></form>
<script src="<?= h(asset('/assets/qrcode.js')) ?>"></script>
<script>
(function () {
  var el = document.getElementById('qr');
  var qr = qrcode(0, 'M');
  qr.addData(el.dataset.uri);
  qr.make();
  el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
})();
</script>
<?php else: ?>
<p>After your password, you enter a 6-digit code from an app on your phone. It works alongside security keys, as a second way in.</p>
<form method="post" action="/app/security/start"><?= csrf_field() ?><button class="btn btn-accent">Set up an authenticator app</button></form>
<?php endif; ?>
</section>

<?php if (!$on && $keys): ?>
<section class="block">
<h2>Recovery codes</h2>
<p class="muted"><?= (int)$left ?> of 10 recovery codes unused. Each one signs you in once if you do not have your key.</p>
<form method="post" action="/app/security/codes" class="inline-form">
<?= csrf_field() ?>
<div><label for="p3">Password</label><input id="p3" name="password" type="password" autocomplete="current-password" required></div>
<button class="btn btn-line">Make new codes</button>
</form>
</section>
<?php endif; ?>
