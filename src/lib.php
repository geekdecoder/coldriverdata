<?php
declare(strict_types=1);

// ---------- config, database ----------

function cfg(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $path = getenv('CRD_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            exit('Missing config.php. Copy config.sample.php to config.php and fill it in.');
        }
        $c = require $path;
    }
    if ($key === null) return $c;
    $v = $c;
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return null;
        $v = $v[$k];
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $pdo = new PDO($d['dsn'], $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }

// ---------- output helpers ----------

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function fmt_bytes(int|float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    $n = ($i === 0 || $b >= 10) ? number_format($b, 0) : rtrim(rtrim(number_format($b, 1), '0'), '.');
    return $n . ' ' . $u[$i];
}

function fmt_money(int $cents): string
{
    return '$' . number_format($cents / 100, 2);
}

function fmt_date(?string $utc, string $format = 'M j, Y'): string
{
    return $utc ? gmdate($format, strtotime($utc . ' UTC')) : '';
}

/** Address of a file in public/, stamped with its modified time so browsers and Cloudflare fetch updates. */
function asset(string $path): string
{
    $t = @filemtime(dirname(__DIR__) . '/public' . $path);
    return $path . ($t ? '?v=' . $t : '');
}

function url(string $path = '/'): string { return rtrim((string)cfg('app_url'), '/') . $path; }

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function render(string $view, array $vars = []): never
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/views/layout.php';
    exit;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    render('message', ['title' => $status === 404 ? 'Not found' : 'Something went wrong', 'message' => $message]);
}

// ---------- session, CSRF, flash ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('crd');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => str_starts_with((string)cfg('app_url'), 'https://'),
    ]);
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    // PHP discards the whole form when it is larger than post_max_size, so say that plainly.
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes('post_max_size')) {
        fail(413, 'That upload is larger than this server accepts in one go (' . fmt_bytes(ini_bytes('post_max_size')) . '). Go back and try a smaller file.');
    }
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        fail(400, 'Your session expired. Go back, reload the page and try again.');
    }
}

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------- users, plans ----------

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $user = q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
            if ($user && !empty($user['suspended_at'])) { $user = null; unset($_SESSION['uid']); }
        }
    }
    return $user;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) redirect('/login');
    return $u;
}

function require_admin(): array
{
    $u = require_user();
    if (empty($u['is_admin'])) fail(404, 'There is no page at this address.');
    if (cfg('require_admin_2fa') !== false && !second_factor_on($u)) {
        flash('Admin pages need two-step sign-in. Add an authenticator app or a security key here first.', 'warn');
        redirect('/app/security');
    }
    return $u;
}

function admin_log(array $admin, string $action, ?string $target = null, ?string $detail = null): void
{
    q('INSERT INTO admin_log (admin_id, admin_email, action, target, detail, at) VALUES (?,?,?,?,?,?)',
        [$admin['id'], $admin['email'], $action, $target, $detail !== null ? mb_substr($detail, 0, 500) : null, now()]);
}

function plans(): array { return cfg('plans'); }

/** The user's plan config if their access is current, else null. */
function active_plan(array $user): ?array
{
    $p = $user['plan'] ? (plans()[$user['plan']] ?? null) : null;
    if (!$p || !$user['paid_through']) return null;
    return strtotime($user['paid_through'] . ' UTC') > time() ? $p + ['key' => $user['plan']] : null;
}

function used_bytes(int $userId): int
{
    return (int)q('SELECT COALESCE(SUM(size), 0) FROM files WHERE user_id = ?', [$userId])->fetchColumn();
}

function client_ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

function ini_bytes(string $key): int
{
    $v = trim((string)ini_get($key));
    if ($v === '' || $v === '-1' || $v === '0') return PHP_INT_MAX;
    $n = (int)$v;
    return match (strtolower($v[strlen($v) - 1])) {
        'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
    };
}

/** Largest chunk the browser may send per request, kept under PHP's upload limits. */
function chunk_bytes(): int
{
    $limit = min(ini_bytes('upload_max_filesize'), ini_bytes('post_max_size') - 65536);
    return (int)max(262144, min(8 * 1024 * 1024, $limit - 4096));
}

// ---------- storage ----------

function storage_path(int $userId, string $key): string
{
    return rtrim((string)cfg('storage_dir'), '/') . '/u' . $userId . '/' . $key;
}

function storage_tmp(int $userId, string $uploadId): string
{
    $dir = rtrim((string)cfg('storage_dir'), '/') . '/tmp';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    return $dir . '/' . $userId . '_' . $uploadId;
}


// ---------- legal pages ----------

/** A value from the 'legal' block of config.php, or a visible placeholder until it is filled in. */
function legal(string $key): string
{
    $defaults = [
        'entity' => '[LEGAL BUSINESS NAME]',
        'address' => '[BUSINESS MAILING ADDRESS]',
        'contact_email' => '[CONTACT EMAIL]',
        'governing_state' => '[STATE]',
        'venue' => '[COUNTY AND STATE]',
        'effective_date' => '[EFFECTIVE DATE]',
        'hosting_provider' => '[HOSTING PROVIDER]',
        'lapse_days' => '90',
    ];
    $v = cfg('legal.' . $key);
    return ($v === null || $v === '') ? $defaults[$key] : (string)$v;
}

// ---------- themes, profile ----------

function themes(): array
{
    return [
        'river'   => ['name' => 'Cold River',  'note' => 'The standard look',          'swatch' => ['#08202B', '#F1F5F6', '#FFFFFF', '#0E6E8C']],
        'night'   => ['name' => 'Night River', 'note' => 'Dark, easier at night',      'swatch' => ['#050D11', '#0B161C', '#13232C', '#5CC8E0']],
        'glacier' => ['name' => 'Glacier',     'note' => 'Bright with a blue accent',  'swatch' => ['#0F2A4A', '#F7FAFC', '#FFFFFF', '#1F5FA8']],
        'sandbar' => ['name' => 'Sandbar',     'note' => 'Warm paper tones',           'swatch' => ['#2B2118', '#F6F1E7', '#FFFDF8', '#9A4A1C']],
        'auto'    => ['name' => 'Match my device', 'note' => 'Cold River by day, Night River when your device is in dark mode', 'swatch' => ['#08202B', '#F1F5F6', '#0B161C', '#5CC8E0']],
    ];
}

function user_theme(?array $user): string
{
    $t = $user['theme'] ?? null;
    return ($t && isset(themes()[$t])) ? $t : 'river';
}

function user_label(array $user): string
{
    $n = trim((string)($user['display_name'] ?? ''));
    return $n !== '' ? $n : explode('@', $user['email'])[0];
}

function avatar_path(int $userId): string
{
    return rtrim((string)cfg('storage_dir'), '/') . '/avatars/u' . $userId . '.jpg';
}

/** The signed-in user's photo, or their initial on a coloured disc. */
function avatar_html(array $user, string $size = 'sm'): string
{
    if (!empty($user['avatar_at'])) {
        $inner = '<img src="/app/avatar?v=' . strtotime($user['avatar_at'] . ' UTC') . '" alt="">';
    } else {
        $inner = h(mb_strtoupper(mb_substr(user_label($user), 0, 1)));
    }
    return '<span class="avatar avatar-' . $size . '" aria-hidden="true">' . $inner . '</span>';
}

/**
 * Turn an uploaded image into a 256px square JPEG at $dest. Re-encoding strips location data
 * and anything else hidden in the file. Returns an error message, or null on success.
 */
function save_avatar(string $tmp, string $dest): ?string
{
    if (!function_exists('imagecreatefromstring')) return 'Photo uploads are not available on this server yet.';
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) return 'Choose a JPEG, PNG or WebP image.';
    if ($info[0] > 8000 || $info[1] > 8000 || $info[0] < 32 || $info[1] < 32) return 'That image is too large or too small. Use one between 32 and 8000 pixels on each side.';
    // Decoding needs about 5 bytes per pixel, and a rotated phone photo needs that twice.
    @ini_set('memory_limit', '512M');
    $limit = ini_bytes('memory_limit');
    if ($info[0] * $info[1] * 10 > $limit - memory_get_usage()) return 'That photo has too many pixels for this server to process. Use a smaller version, for example a screenshot of it.';
    $src = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$src) return 'That image could not be read. Try saving it as a JPEG or PNG first.';
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {     // phones store rotation separately
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($src, $deg, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $w = imagesx($src); $h2 = imagesy($src); $side = min($w, $h2);
    $out = imagecreatetruecolor(256, 256);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h2 - $side) / 2), 256, 256, $side, $side);
    if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) return 'The photo folder could not be created on the server.';
    $ok = @imagejpeg($out, $dest, 86);
    imagedestroy($src); imagedestroy($out);
    return $ok ? null : 'The photo could not be saved.';
}
