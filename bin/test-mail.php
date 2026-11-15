<?php
// Sends a test message so you can confirm the server can deliver email:
//   php bin/test-mail.php you@example.com
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';

$to = trim($argv[1] ?? '');
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) exit("usage: php bin/test-mail.php you@example.com\n");
$ok = send_mail($to, cfg('app_name') . ' mail test', "If you can read this, " . cfg('app_name') . " can send email.\n\nSent " . gmdate('c') . " from " . cfg('mail_from') . ".");
echo $ok ? "Handed to the mail server. Check the inbox and the spam folder of $to.\n"
         : "PHP could not hand the message to a mail server. Check that sendmail/postfix is installed and running.\n";
