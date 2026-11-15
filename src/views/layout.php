<?php $u = current_user(); $f = flash(); $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'; $inApp = $u && (str_starts_with($reqPath, '/app') || str_starts_with($reqPath, '/admin')); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(($title ?? null) ? $title . ' · ' . cfg('app_name') : cfg('app_name') . ' — AI chat archive and file storage') ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600;700&display=swap">
<link rel="stylesheet" href="<?= h(asset('/assets/style.css')) ?>">
</head>
<body>
<header class="top">
<div class="wrap top-row">
<a class="brand" href="<?= $u ? '/app' : '/' ?>">
<svg aria-hidden="true" width="34" height="34" viewBox="0 0 34 34" fill="none" stroke="#8EDCF0" stroke-width="2" stroke-linecap="round"><rect x="1" y="1" width="32" height="32"></rect><path d="M7 12c3.5-3 6.5 3 10 0s6.5 3 10 0"></path><path d="M7 18c3.5-3 6.5 3 10 0s6.5 3 10 0"></path><path d="M7 24c3.5-3 6.5 3 10 0s6.5 3 10 0"></path></svg>
<span><?= h(cfg('app_name')) ?></span>
</a>
<nav aria-label="Main" class="nav">
<?php if ($u): ?>
<a href="/app">Overview</a>
<a href="/app/chats">Chats</a>
<a href="/app/files">Files</a>
<a href="/app/billing">Plan</a>
<?php if (!empty($u['is_admin'])): ?><a href="/admin">Admin</a><?php endif; ?>
<form method="post" action="/logout"><?= csrf_field() ?><button class="linkish">Sign out</button></form>
<?php else: ?>
<a href="/#get">What you get</a>
<a href="/#how">How it works</a>
<a href="/#plans">Plans</a>
<a href="/login">Sign in</a>
<a class="btn btn-ice" href="/signup">Create an account</a>
<?php endif; ?>
</nav>
</div>
</header>
<?php if ($f): ?><div class="wrap"><p class="flash flash-<?= h($f['kind']) ?>" role="status"><?= h($f['msg']) ?></p></div><?php endif; ?>
<main<?= $inApp ? ' class="wrap app"' : '' ?>>
<?= $content ?>
</main>
<footer class="foot"><div class="wrap foot-row"><span>Cold River Data Network</span><span class="foot-links"><a href="/terms">Terms</a><a href="/privacy">Privacy</a><span class="mono">coldriverdata.net</span></span></div></footer>
</body>
</html>
