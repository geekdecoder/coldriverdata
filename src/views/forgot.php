<?php $title = 'Reset your password'; ?>
<section class="wrap narrow">
<h1>Reset your password</h1>
<?php if ($sent): ?>
<p>If that address has an account, a reset link is on its way. It works for one hour.</p>
<?php else: ?>
<form method="post" action="/forgot" class="form">
<?= csrf_field() ?>
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required>
<button class="btn btn-accent btn-lg">Send the reset link</button>
</form>
<?php endif; ?>
</section>
