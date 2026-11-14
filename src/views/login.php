<?php $title = 'Sign in'; ?>
<section class="wrap narrow">
<h1>Sign in</h1>
<?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="/login" class="form">
<?= csrf_field() ?>
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required value="<?= h($email) ?>">
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button class="btn btn-accent btn-lg">Sign in</button>
</form>
<p class="muted"><a href="/forgot">Forgot your password?</a> · New here? <a href="/signup">Create an account</a></p>
</section>
