<?php $title = 'Confirm it is you'; ?>
<section class="wrap narrow">
<h1>Confirm it is you</h1>
<?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if ($hasKeys): ?>
<div data-webauthn="login" data-csrf="<?= h(csrf_token()) ?>" class="stack">
<p>Use the security key you added to your account.</p>
<div><button type="button" class="btn btn-accent btn-lg" data-go>Use your security key</button></div>
<p data-msg role="status" aria-live="polite" hidden></p>
</div>
<script src="<?= h(asset('/assets/webauthn.js')) ?>" defer></script>
<h2 class="or"><?= $hasTotp ? 'Or enter a code' : 'Or use a recovery code' ?></h2>
<?php endif; ?>
<?php if ($hasTotp && !$hasKeys): ?><p>Open your authenticator app and enter the 6-digit code for <?= h(cfg('app_name')) ?>.</p><?php endif; ?>
<form method="post" action="/login/code" class="form">
<?= csrf_field() ?>
<label for="code"><?= $hasTotp ? 'Code from your app, or a recovery code' : 'Recovery code' ?></label><input id="code" name="code" type="text" autocomplete="one-time-code"<?= $hasKeys ? '' : ' autofocus' ?> required>
<button class="btn <?= $hasKeys ? 'btn-line' : 'btn-accent' ?> btn-lg">Sign in</button>
</form>
<p class="muted"><a href="/login">Start again</a></p>
</section>
