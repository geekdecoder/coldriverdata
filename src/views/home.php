<section class="hero">
<div class="wrap hero-grid">
<div class="stack">
<div class="eyebrow ice">AI chat archive and file storage</div>
<h1>Keep every AI conversation you have ever had.</h1>
<p class="lede">Export your chats from ChatGPT and Claude, upload them here, and read and search them in one archive you control. Your other files fit on the same shelf.</p>
<div class="row"><a class="btn btn-ice btn-lg" href="/signup">Create an account</a><a class="on-dark" href="#plans">See the plans</a></div>
</div>
<div class="bay" aria-hidden="true">
<div class="bay-head"><span>Your warehouse</span><span class="ice">Bay 01</span></div>
<div class="drop"><strong>Drop your chat export here</strong><span>The .zip your AI app emails you, or any other file</span></div>
<div class="bay-row"><span class="mono">claude-export.zip</span><span class="ice">Chat archive</span></div>
<div class="bay-row"><span class="mono">chatgpt-conversations.json</span><span class="ice">Chat archive</span></div>
<div class="bay-row"><span class="mono">project-files-backup.zip</span><span>File</span></div>
</div>
</div>
</section>

<section id="get" class="wrap section">
<div class="eyebrow">What you get</div>
<h2>An export is a pile of JSON. This turns it back into conversations.</h2>
<div class="cards">
<div class="card"><h3>A readable archive</h3><p>Upload the export from ChatGPT or Claude and every conversation is filed by title and date, and opens as a chat you can read from top to bottom.</p></div>
<div class="card"><h3>Search across all of it</h3><p>One search box covers every conversation from every assistant, so the answer you got last spring is a few words away, whichever app it came from.</p></div>
<div class="card"><h3>Room for your files</h3><p>The original export stays downloadable, and documents, backups and exports from other assistants can be stored next to it as plain files.</p></div>
</div>
</section>

<section id="how" class="band">
<div class="wrap section">
<div class="eyebrow">How it works</div>
<h2>From export to archive in three steps.</h2>
<ol class="steps">
<li><span class="mono num">01</span><h3>Open an account</h3><p>Sign up with an email address and choose a plan.</p></li>
<li><span class="mono num">02</span><h3>Export and upload</h3><p>Ask ChatGPT or Claude for your data export, then upload the .zip it sends you.</p></li>
<li><span class="mono num">03</span><h3>Read and search</h3><p>Your conversations are filed and searchable. Upload a fresh export whenever you want to bring the archive up to date.</p></li>
</ol>
</div>
</section>

<section id="plans" class="wrap section">
<div class="eyebrow">Plans</div>
<h2>Rent the space you need.</h2>
<p class="muted">Every plan includes the chat archive, with reader and search, for ChatGPT and Claude exports. Plans differ only in space.</p>
<div class="cards">
<?php foreach (plans() as $key => $p): ?>
<div class="card plan<?= !empty($p['featured']) ? ' featured' : '' ?>">
<h3><?= h($p['name']) ?></h3>
<p class="muted small"><?= h($p['tagline']) ?></p>
<p class="price"><?= fmt_money($p['monthly']) ?><span> / month</span></p>
<ul><li><?= h($p['label']) ?> of storage</li><li>Chat archive with reader and search</li><li>File storage and downloads</li></ul>
<a class="btn <?= !empty($p['featured']) ? 'btn-accent' : 'btn-line' ?>" href="/signup?plan=<?= h($key) ?>">Choose <?= h($p['name']) ?></a>
</div>
<?php endforeach; ?>
</div>
<p class="muted">Pay monthly by card, or pay for a year up front by card<?= cfg('stripe.crypto_enabled') ? ' or in USDC' : '' ?> and get two months free.</p>
</section>

<section class="hero cta">
<div class="wrap hero-grid">
<div class="stack"><h2>Start your archive.</h2><p class="lede">Create an account, choose a plan, and upload your first chat export today.</p></div>
<div><a class="btn btn-ice btn-lg" href="/signup">Create an account</a></div>
</div>
</section>
