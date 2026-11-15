<?php $title = 'Privacy Policy'; $co = h(legal('entity')); $app = h(cfg('app_name')); ?>
<section class="wrap legal">
<h1>Privacy Policy</h1>
<p class="muted">Effective <?= h(legal('effective_date')) ?></p>
<p>This policy explains what <?= $co ?> ("we", "us") collects when you use <?= $app ?> at coldriverdata.net, why, and what choices you have.</p>

<div class="summary">
<p><strong>In short:</strong> we store what you upload so you can read, search and download it. We do not sell it, show ads against it, or use it to train AI models. Your content is private to your account, but it is not end-to-end encrypted, so do not upload anything you would not trust a storage provider to hold.</p>
</div>

<h2>1. What we collect</h2>
<ul>
<li><strong>Account details:</strong> your email address and a scrambled (hashed) version of your password. We never store the password itself.</li>
<li><strong>Your content:</strong> the files you upload, and the conversations, titles, dates and messages we read from AI chat exports. Chat exports can include personal and sensitive information about you and about other people; that is stored as part of your content.</li>
<li><strong>Billing records:</strong> your plan, payment status, renewal date and the customer reference Stripe gives us. Card numbers and wallet details go directly to Stripe and never reach our servers.</li>
<li><strong>Technical records:</strong> your IP address, browser type and the pages requested, kept in server logs, and the IP address of sign-in attempts, used to block password guessing.</li>
</ul>

<h2>2. How we use it</h2>
<ul>
<li>To run the service: store your files, file your conversations, and make them searchable for you.</li>
<li>To take payment and manage your plan.</li>
<li>To send service email, such as password resets, billing problems and notices about your account or these policies.</li>
<li>To keep the service secure, prevent abuse and fix faults.</li>
<li>To meet legal obligations.</li>
</ul>
<p>We do not sell your personal information, do not share it for advertising, and do not use your content to train AI models. We do not read your content except when you ask for help that requires it, to investigate a reported breach of our terms, or when the law requires.</p>

<h2>3. Who else handles your information</h2>
<p>We use a small number of companies to run the service. They may process your information only to provide their service to us:</p>
<ul>
<li><strong>Stripe</strong> processes payments.</li>
<li><strong><?= h(legal('hosting_provider')) ?></strong> provides the servers where your account and content are stored.</li>
<li><strong>Cloudflare</strong> carries traffic between your browser and our servers, protects the site from attacks, and runs the automated check on our sign-up and password-reset forms that tells people from bots.</li>
<li><strong>Google Fonts</strong> supplies the typefaces on our pages; your browser requests them from Google, which sees your IP address.</li>
</ul>
<p>We may also disclose information if required by a valid legal demand, to protect someone's safety or our legal rights, or as part of a merger or sale of the business, in which case this policy continues to apply to your information.</p>

<h2>4. Cookies</h2>
<p>We set one cookie, which keeps you signed in. We do not use advertising or analytics cookies. Stripe sets its own cookies on its checkout and billing pages.</p>

<h2>5. Security</h2>
<p>Connections to the site are encrypted in transit (HTTPS). Uploaded files are kept outside the public web folder and are served only to the signed-in account that owns them. Passwords are stored hashed. Your content is not end-to-end encrypted, which means it could be accessed by us or by our hosting provider if required. No system is perfectly secure; if we learn of a breach affecting your information, we will notify you as the law requires.</p>

<h2>6. How long we keep it</h2>
<ul>
<li>Your content stays until you delete it or your account is deleted. Deleting a file removes it and the conversations filed from it.</li>
<li>After a plan ends, content is kept for <?= h(legal('lapse_days')) ?> days and may then be deleted, with notice by email first.</li>
<li>Backups that contain deleted content are overwritten on a rolling schedule.</li>
<li>Billing records are kept as long as tax and accounting rules require. Server logs are kept for a limited period for security and troubleshooting.</li>
</ul>

<h2>7. Your choices and rights</h2>
<ul>
<li><strong>Download:</strong> you can download any file you have uploaded at any time.</li>
<li><strong>Delete:</strong> you can delete individual files yourself, or ask us to delete your whole account and content.</li>
<li><strong>Access and correction:</strong> you can ask what personal information we hold about you and ask us to correct it.</li>
</ul>
<p>Depending on where you live, you may have further rights under local privacy law, such as objecting to certain uses or complaining to a regulator. To make any request, write to <?= h(legal('contact_email')) ?> from the email address on your account. We do not discriminate against anyone for using these rights.</p>

<h2>8. Other people's information</h2>
<p>Your uploads may mention other people. You are responsible for having the right to store that information. If you believe someone has stored your personal information on the service without a right to, contact us.</p>

<h2>9. Children</h2>
<p>The service is for adults. We do not knowingly collect information from anyone under 18. If you believe a minor has created an account, contact us and we will remove it.</p>

<h2>10. Where information is processed</h2>
<p>Your information is processed in the United States and in any other country where we or the companies listed above operate. By using the service you understand that it may be transferred to and stored in those places.</p>

<h2>11. Changes to this policy</h2>
<p>If we change this policy in a way that matters, we will email you or post a notice on the site before the change takes effect, and update the effective date above.</p>

<h2>12. Contact</h2>
<p><?= $co ?><br><?= h(legal('address')) ?><br><?= h(legal('contact_email')) ?></p>
</section>
