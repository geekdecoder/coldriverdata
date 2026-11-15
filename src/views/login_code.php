<?php $title = 'Enter your code'; ?>
<section class="wrap narrow">
<h1>Enter your code</h1>
<p>Open your authenticator app and enter the 6-digit code for <?= h(cfg('app_name')) ?>.</p>
<?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="/login/code" class="form">
<?= csrf_field() ?>
<label for="code">Code</label><input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" autofocus required>
<button class="btn btn-accent btn-lg">Sign in</button>
</form>
<p class="muted">No phone? Enter one of your recovery codes in the same box. <a href="/login">Start again</a></p>
</section>
