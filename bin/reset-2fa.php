<?php
// Turns off two-step sign-in for an account that is locked out (lost phone and recovery codes):
//   php bin/reset-2fa.php you@example.com
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
require dirname(__DIR__) . '/src/twostep.php';
require dirname(__DIR__) . '/src/webauthn.php';

$email = strtolower(trim($argv[1] ?? ''));
$u = $email !== '' ? q('SELECT id FROM users WHERE email = ?', [$email])->fetch() : null;
if (!$u) exit("usage: php bin/reset-2fa.php email   (no account with that email)\n");
second_factor_reset((int)$u['id']);
echo "Two-step sign-in is off for $email (authenticator app and security keys removed). They can sign in with their password and set it up again.\n";
