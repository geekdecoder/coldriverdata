<?php
// Reports whether this PHP can process profile photos:  php bin/check-photo.php
// Run it with the same PHP the website uses (for example /usr/local/lsws/lsphp83/bin/php).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
echo 'PHP ' . PHP_VERSION . "\n";
echo 'GD extension:        ' . (function_exists('imagecreatefromstring') ? 'yes' : 'MISSING') . "\n";
if (function_exists('gd_info')) { $g = gd_info(); foreach (['JPEG Support', 'PNG Support', 'WebP Support'] as $k) echo str_pad($k . ':', 21) . (!empty($g[$k]) ? 'yes' : 'no') . "\n"; }
echo 'EXIF (phone rotation): ' . (function_exists('exif_read_data') ? 'yes' : 'no') . "\n";
echo 'upload_max_filesize: ' . ini_get('upload_max_filesize') . "\n";
echo 'post_max_size:       ' . ini_get('post_max_size') . "\n";
echo 'memory_limit:        ' . ini_get('memory_limit') . "\n";
$dir = rtrim((string)cfg('storage_dir'), '/') . '/avatars';
echo "photo folder:        $dir (" . (is_dir($dir) ? (is_writable($dir) ? 'writable by this user' : 'NOT writable by this user') : (is_writable(dirname($dir)) ? 'will be created' : 'CANNOT be created by this user')) . ")\n";
