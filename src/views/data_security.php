<?php $title = 'How your data is protected'; $app = h(cfg('app_name')); ?>
<section class="wrap legal">
<div class="eyebrow">Security</div>
<h1>How your data is protected</h1>
<p class="lede-dark">Your AI conversations can hold some of the most personal things you have written. Here is exactly what <?= $app ?> does to protect them, and where the limits are.</p>

<h2>Encrypted on the way in and out</h2>
<p>Every connection to this site uses HTTPS, so your uploads, downloads and sign-in details are encrypted between your device and us. Files moving between our server and our storage provider travel over encrypted connections too.</p>

<?php if ($st['files_all_encrypted'] || $st['db_all_encrypted']): ?>
<h2>Encrypted where it is stored</h2>
<ul>
<?php if ($st['files_all_encrypted']): ?><li><strong>Your files are encrypted at rest.</strong> Each file is encrypted with its own key before it is written to storage, and the storage provider never keeps that key. Without it, the stored file is unreadable.</li><?php endif; ?>
<?php if ($st['db_all_encrypted']): ?><li><strong>Your conversations are encrypted at rest.</strong> The database that holds your chat archive is encrypted on disk.</li><?php endif; ?>
</ul>
<?php endif; ?>

<h2>Private by default</h2>
<ul>
<li>Uploaded files are kept in private storage. No file has a public address.</li>
<li>Only the signed-in account that owns a file can download it.</li>
<li>Your conversations are visible only inside your account. Our support tools show file names and sizes, never the text of your conversations.</li>
</ul>

<?php if ($objectStorage): ?>
<h2>Stored to survive hardware failure</h2>
<p>Files you upload are kept in dedicated object storage. Each file is split into pieces spread across many storage servers, with extra recovery pieces, so it stays intact even if several of those servers fail at once.</p>
<?php endif; ?>

<?php if ($st['backup_fresh']): ?>
<h2>Backed up every day</h2>
<p>Your chat archive is backed up every night. Each backup is encrypted before it leaves our server and is kept in a separate storage location for <?= (int)$st['backup_days'] ?> days.</p>
<?php endif; ?>

<h2>Your account is hard to break into</h2>
<ul>
<li><strong>Two-step sign-in:</strong> add a hardware security key such as a YubiKey, a code from an authenticator app, or both, so a stolen password alone is not enough. Staff accounts are required to use it.</li>
<li><strong>Passwords are never stored.</strong> We keep only a one-way scrambled form that cannot be turned back into your password.</li>
<li><strong>Guessing is blocked.</strong> Repeated wrong passwords or codes lock out further attempts for a while.</li>
<li><strong>Email addresses are confirmed</strong> before a new account can be used.</li>
</ul>

<h2>Your data is not our product</h2>
<p>We do not sell your data, show advertising against it, or use it to train AI models. Our income is the plan you pay for.</p>

<h2>Payments never touch our servers</h2>
<p>Card and wallet details go straight to Stripe, our payment processor. We never see or store them.</p>

<h2>You stay in control</h2>
<ul>
<li>Download any file you uploaded, at any time.</li>
<li>Delete a file and the conversations filed from it are removed with it.</li>
<li>Ask us to delete your account and everything in it.</li>
</ul>

<h2>What this does not mean</h2>
<p>We would rather be exact than reassuring. <?= $app ?> is not end-to-end encrypted: to file your conversations and make them searchable, our systems have to be able to read them, and we hold the encryption keys. That means we could technically access stored data, and we could be required to disclose it by a valid legal order. We also recommend keeping your own copy of anything important; no storage service should be the only place a file exists.</p>
<p>Details are in the <a href="/privacy">Privacy Policy</a> and <a href="/terms">Terms of Service</a>.</p>
</section>
