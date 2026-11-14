<?php $title = 'Terms of Service'; $co = h(legal('entity')); $app = h(cfg('app_name')); ?>
<section class="wrap legal">
<h1>Terms of Service</h1>
<p class="muted">Effective <?= h(legal('effective_date')) ?></p>
<p>These terms are an agreement between you and <?= $co ?> ("we", "us"), which operates <?= $app ?> at coldriverdata.net (the "service"). By creating an account or using the service you agree to them. If you do not agree, do not use the service.</p>

<h2>1. The service</h2>
<p><?= $app ?> stores files you upload and files AI chat exports into an archive you can read and search. We may add, change or remove features. If a change materially reduces what a paid plan includes, we will tell you by email before it takes effect.</p>

<h2>2. Your account</h2>
<ul>
<li>You must be at least 18 years old and able to enter into a contract.</li>
<li>Give an email address you control and keep your password private. You are responsible for activity under your account.</li>
<li>Tell us promptly at <?= h(legal('contact_email')) ?> if you believe someone else has accessed your account.</li>
</ul>

<h2>3. Your content</h2>
<p>You keep all rights to the files and conversations you upload ("your content"). You give us permission to store, copy, process and display your content only as needed to run the service for you, for example to index your conversations so you can search them, and to make backups.</p>
<p>We do not sell your content, do not use it for advertising, and do not use it to train AI models.</p>
<p>You are responsible for your content, including having the right to upload it. AI chat exports often contain personal details about you and other people; decide what you are comfortable storing before you upload.</p>

<h2>4. Acceptable use</h2>
<p>You may not use the service to:</p>
<ul>
<li>store or share anything illegal, including child sexual abuse material, or content that infringes someone else's rights;</li>
<li>store or distribute malware, or attack, probe or overload the service or other people's systems;</li>
<li>access another person's account or data without permission;</li>
<li>resell the service or use it to host files for the public without our written agreement.</li>
</ul>
<p>We may remove content or suspend an account that breaks these rules, and we report illegal content where the law requires it.</p>

<h2>5. Plans, payment and renewal</h2>
<ul>
<li>Prices and storage limits are shown on the site when you buy. Payments are processed by Stripe; we never see your full card number or your wallet keys.</li>
<li><strong>Card plans</strong> renew automatically each month or year at the price then in effect until you cancel. You can cancel at any time from the Plan page; you keep access until the end of the period you have paid for.</li>
<li><strong>Crypto plans</strong> are a one-time payment for 12 months and do not renew by themselves.</li>
<li>If we change a price, we will email you at least 30 days before your next renewal at the new price.</li>
<li>Payments are not refunded for partial periods or unused storage, except where the law requires a refund.</li>
<li>If a payment fails we may limit the account to reading and downloading until it is paid.</li>
</ul>

<h2>6. Storage limits</h2>
<p>Each plan has a storage limit. When you reach it, new uploads are refused until you delete files or move to a larger plan.</p>

<h2>7. When a plan ends</h2>
<p>If your plan ends or is cancelled, uploads stop but you can still sign in, read and download your content for <?= h(legal('lapse_days')) ?> days. After that we may delete your content. We will email the address on your account before deleting anything.</p>
<p>You can ask us to delete your account and content at any time by writing to <?= h(legal('contact_email')) ?>. Deletion is permanent.</p>

<h2>8. Keep your own copies</h2>
<p>We work to keep your content safe and available, but no storage service can promise that data will never be lost or that the service will never be interrupted. Do not make <?= $app ?> the only place an important file exists.</p>

<h2>9. Copyright complaints</h2>
<p>If you believe content on the service infringes your copyright, send a notice to <?= h(legal('contact_email')) ?> with your contact details, a description of the work, where the material is on the service, a statement that you have a good-faith belief the use is not authorised, and a statement under penalty of perjury that the notice is accurate and that you are the owner or authorised to act for the owner. We remove infringing material and close the accounts of repeat infringers.</p>

<h2>10. Other companies' products</h2>
<p><?= $app ?> is independent. It is not affiliated with, endorsed by or sponsored by OpenAI, Anthropic or any other AI provider. ChatGPT and Claude are trademarks of their owners, used here only to describe which export files the service reads. Your use of those products is governed by their own terms.</p>

<h2>11. Disclaimer</h2>
<p>The service is provided "as is" and "as available". To the fullest extent the law allows, we disclaim all warranties, express or implied, including merchantability, fitness for a particular purpose and non-infringement. We do not warrant that the service will be uninterrupted, error-free or secure against every threat, or that imported conversations will be complete or exactly match the original.</p>

<h2>12. Limit of liability</h2>
<p>To the fullest extent the law allows, we are not liable for indirect, incidental, special, consequential or punitive damages, or for loss of data, profits or business. Our total liability for any claim about the service is limited to the amount you paid us in the 12 months before the event giving rise to the claim. Some places do not allow these limits, so they may not apply to you in full.</p>

<h2>13. Indemnity</h2>
<p>You agree to cover our reasonable costs, including legal fees, arising from a third-party claim caused by your content or by your breach of these terms.</p>

<h2>14. Ending the agreement</h2>
<p>You can stop using the service at any time. We may suspend or close an account that breaks these terms, or close the service with at least 30 days' notice by email so you can download your content. If we close the service, we will refund the unused part of any prepaid period.</p>

<h2>15. Changes to these terms</h2>
<p>We may update these terms. If a change is material, we will email you or post a notice on the site at least 14 days before it takes effect. Continuing to use the service after that date means you accept the updated terms.</p>

<h2>16. Governing law</h2>
<p>These terms are governed by the laws of the State of <?= h(legal('governing_state')) ?>, without regard to its conflict-of-law rules. Disputes will be brought in the state or federal courts located in <?= h(legal('venue')) ?>, unless the law where you live gives you the right to bring them elsewhere.</p>

<h2>17. Contact</h2>
<p><?= $co ?><br><?= h(legal('address')) ?><br><?= h(legal('contact_email')) ?></p>
</section>
