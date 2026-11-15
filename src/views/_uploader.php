<?php if ($plan): ?>
<div class="uploader" id="uploader" data-chunk="<?= chunk_bytes() ?>" data-csrf="<?= h(csrf_token()) ?>">
<label class="dropzone" for="file-input">
<strong>Drop a chat export or any file here</strong>
<span>or choose files from your computer. ChatGPT and Claude export .zip files are filed into your chat archive.</span>
<input id="file-input" type="file" multiple>
</label>
<ul class="upload-list" id="upload-list" aria-live="polite"></ul>
</div>
<script src="<?= h(asset('/assets/upload.js')) ?>" defer></script>
<?php else: ?>
<p class="flash flash-warn"><?= $user['plan'] ? 'Your plan has lapsed. Your data is still here to read and download; renew to upload more.' : 'Choose a plan to start uploading.' ?> <a href="/app/billing">Go to plans</a></p>
<?php endif; ?>
