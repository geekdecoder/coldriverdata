<?php $title = 'Confirm your email'; ?>
<section class="wrap narrow">
<h1>Confirm your email</h1>
<p>We sent a confirmation link to <strong><?= h($user['email']) ?></strong>. Open it to finish setting up your account. The link works for 48 hours.</p>
<p class="muted">Nothing after a few minutes? Check your spam folder, then send a new link.</p>
<form method="post" action="/verify/resend"><?= csrf_field() ?><button class="btn btn-accent">Send a new link</button></form>
<p class="muted small">Wrong address? Sign out and create the account again with the right one.</p>
</section>
