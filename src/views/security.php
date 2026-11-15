<?php $title = 'Security'; $on = two_step_on($user); ?>
<h1>Security</h1>
<?php if ($codes): ?>
<section class="block codes-box">
<h2>Save your recovery codes</h2>
<p>Each code signs you in once if you do not have your phone. They are shown only this one time. Print them or keep them in a password manager.</p>
<ul class="codes mono"><?php foreach ($codes as $c): ?><li><?= h($c) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="block">
<h2>Two-step sign-in</h2>
<?php if (!$available): ?>
<p class="flash flash-warn">Two-step sign-in is not available yet on this site.</p>
<?php elseif ($on): ?>
<div class="current">
<p><strong>On</strong> since <?= h(fmt_date($user['totp_enabled_at'])) ?>. Signing in needs your password and a code from your authenticator app.</p>
<p class="muted"><?= (int)$left ?> of 10 recovery codes unused.</p>
</div>
<h3>Make new recovery codes</h3>
<form method="post" action="/app/security/codes" class="inline-form">
<?= csrf_field() ?>
<div><label for="p1">Password</label><input id="p1" name="password" type="password" autocomplete="current-password" required></div>
<div><label for="c1">Authenticator code</label><input id="c1" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></div>
<button class="btn btn-line">Make new codes</button>
</form>
<h3>Turn off two-step sign-in</h3>
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
<p>Add a second step to signing in: after your password, you enter a code from an app on your phone. Someone who learns your password still cannot get into your files and chats.</p>
<form method="post" action="/app/security/start"><?= csrf_field() ?><button class="btn btn-accent">Set up two-step sign-in</button></form>
<?php endif; ?>
</section>
