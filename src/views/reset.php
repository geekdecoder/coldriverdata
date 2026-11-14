<?php $title = 'Choose a new password'; ?>
<section class="wrap narrow">
<h1>Choose a new password</h1>
<?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="/reset" class="form">
<?= csrf_field() ?><input type="hidden" name="token" value="<?= h($token) ?>">
<label for="password">New password <span class="muted small">(10 characters or more)</span></label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
<button class="btn btn-accent btn-lg">Save the new password</button>
</form>
</section>
