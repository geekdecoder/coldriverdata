<?php
// Gives an existing account access to /admin:  php bin/make-admin.php you@example.com
// Add "remove" as a second argument to take it away.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';

$email = strtolower(trim($argv[1] ?? ''));
$on = ($argv[2] ?? '') === 'remove' ? 0 : 1;
if ($email === '') exit("usage: php bin/make-admin.php email [remove]\n");
$n = q('UPDATE users SET is_admin = ? WHERE email = ?', [$on, $email])->rowCount();
if (!q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) exit("No account with that email. Sign up on the site first.\n");
echo $on ? "$email is now an admin.\n" : "$email is no longer an admin.\n";
