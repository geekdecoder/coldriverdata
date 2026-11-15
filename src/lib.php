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
