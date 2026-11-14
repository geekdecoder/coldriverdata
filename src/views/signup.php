<?php $title = 'Create an account'; ?>
<section class="wrap narrow">
<h1>Create an account</h1>
<?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>
<form method="post" action="/signup" class="form">
<?= csrf_field() ?><input type="hidden" name="plan" value="<?= h($plan) ?>">
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required value="<?= h($email) ?>">
<label for="password">Password <span class="muted small">(10 characters or more)</span></label><input id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
<p class="muted small">By creating an account you agree to the <a href="/terms">Terms of Service</a> and <a href="/privacy">Privacy Policy</a>.</p>
<button class="btn btn-accent btn-lg">Create an account</button>
</form>
<p class="muted">Already have an account? <a href="/login">Sign in</a></p>
</section>
