<?php
declare(strict_types=1);

// Where files physically live. A file's backend_id points at a row in storage_backends;
// NULL means the built-in storage_dir from config.php.

function app_key(): ?string
{
    $k = base64_decode((string)cfg('app_key'), true);
    return ($k !== false && strlen($k) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $k : null;
}

function secret_encrypt(string $plain): string
{
    $key = app_key();
    if (!$key) throw new RuntimeException("Set 'app_key' in config.php before adding object storage (see config.sample.php).");
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
}

function secret_decrypt(string $enc): string
{
    $key = app_key();
    $raw = base64_decode($enc, true);
    if (!$key || $raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new RuntimeException('Cannot read the storage secret: app_key is missing or has changed.');
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    if ($plain === false) throw new RuntimeException('Cannot read the storage secret: app_key has changed.');
    return $plain;
}

function backend(?int $id): ?array
{
    static $cache = [];
    if ($id === null) return null;
    if (!array_key_exists($id, $cache)) $cache[$id] = q('SELECT * FROM storage_backends WHERE id = ?', [$id])->fetch() ?: null;
    return $cache[$id];
}

/** Connection details for the S3 client. */
function backend_s3(array $b): array
{
    return [
        'endpoint' => $b['endpoint'], 'region' => $b['region'], 'bucket' => $b['bucket'],
        'access_key' => $b['access_key'], 'secret_key' => secret_decrypt((string)$b['secret_enc']),
        'path_style' => (bool)$b['path_style'],
    ];
}

/** The location new uploads should go to, or null for the built-in folder. */
function default_backend(): ?array
{
    return q("SELECT * FROM storage_backends WHERE is_default = 1 AND status = 'active' LIMIT 1")->fetch() ?: null;
}

function object_key(array $file): string { return 'u' . $file['user_id'] . '/' . $file['storage_key']; }

/** Path of the file on this server's disks, or null when it lives in object storage. */
function file_local_path(array $file): ?string
{
    $b = backend(isset($file['backend_id']) ? (int)$file['backend_id'] : null);
    if (!$b) return storage_path((int)$file['user_id'], $file['storage_key']);
    if ($b['type'] === 'local') return rtrim((string)$b['path'], '/') . '/' . object_key($file);
    return null;
}

/**
 * Place a finished upload. Returns [backend_id, ship_to] for the files row.
 * Local locations get the file directly; object storage is filled by the worker,
 * so the file waits in the built-in folder with ship_to set.
 */
function store_upload(string $part, int $userId, string $key): array
{
    $b = default_backend();
    $dest = storage_path($userId, $key);
    $backendId = null; $shipTo = null;
    if ($b && $b['type'] === 'local') {
        $dest = rtrim((string)$b['path'], '/') . '/u' . $userId . '/' . $key;
        $backendId = (int)$b['id'];
    } elseif ($b) {
        $shipTo = (int)$b['id'];
    }
    if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0750, true) && !is_dir(dirname($dest))) {
        throw new RuntimeException('Storage location is not writable.');
    }
    if (!@rename($part, $dest)) {                 // different disk: copy then remove
        if (!copy($part, $dest)) throw new RuntimeException('Could not store the file.');
        unlink($part);
    }
    return [$backendId, $shipTo];
}

/** Move one waiting file into its object storage. Throws on failure; the local copy stays until it is verified. */
function ship_file(array $file): void
{
    $b = backend((int)$file['ship_to']);
    if (!$b || $b['type'] !== 's3') throw new RuntimeException('Target storage no longer exists.');
    $local = storage_path((int)$file['user_id'], $file['storage_key']);
    if (!is_file($local)) throw new RuntimeException('Local copy is missing.');
    s3_put_file(backend_s3($b), object_key($file), $local);          // verifies the stored size
    q('UPDATE files SET backend_id = ?, ship_to = NULL, ship_error = NULL WHERE id = ?', [$b['id'], $file['id']]);
    unlink($local);
}

/** Send a file to the browser as a download. */
function send_file(array $file): never
{
    $b = backend(isset($file['backend_id']) ? (int)$file['backend_id'] : null);
    if ($b && $b['type'] === 's3') {
        header('Location: ' . s3_presign_get(backend_s3($b), object_key($file), 300, $file['name']), true, 302);
        exit;
    }
    $p = file_local_path($file);
    if (!$p || !is_file($p)) fail(404, 'That file is missing from storage.');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($p));
    header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
    readfile($p);
    exit;
}

/** Remove a file's bytes and its database row (which cascades to imports and conversations). */
function delete_file_row(array $file): void
{
    $b = backend(isset($file['backend_id']) ? (int)$file['backend_id'] : null);
    if ($b && $b['type'] === 's3') {
        s3_delete(backend_s3($b), object_key($file));      // throws if storage is unreachable, so the row is kept
    } else {
        $p = file_local_path($file);
        if ($p && is_file($p)) @unlink($p);
    }
    q('DELETE FROM files WHERE id = ?', [$file['id']]);
}

function send_password_reset(array $user): void
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?', [$user['id'], now()]);
    q('INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
        [hash('sha256', $token), $user['id'], gmdate('Y-m-d H:i:s', time() + 3600)]);
    @mail($user['email'], 'Reset your ' . cfg('app_name') . ' password',
        "Use this link within one hour to choose a new password:\n\n" . url('/reset?token=' . $token) .
        "\n\nIf you did not ask for this, ignore this email.",
        'From: ' . cfg('mail_from'));
}

/** Delete an account and everything it stored. Returns a list of problems (empty when clean). */
function delete_account(array $user): array
{
    $problems = [];
    if ($user['stripe_subscription_id'] && in_array($user['plan_status'], ['active', 'past_due'], true) && $user['pay_method'] === 'card') {
        try { stripe_request('DELETE', 'subscriptions/' . $user['stripe_subscription_id']); }
        catch (Throwable $e) { $problems[] = 'Stripe subscription was not cancelled: ' . $e->getMessage(); }
    }
    foreach (q('SELECT * FROM files WHERE user_id = ?', [$user['id']])->fetchAll() as $f) {
        try { delete_file_row($f); }
        catch (Throwable $e) { $problems[] = 'Could not delete ' . $f['name'] . ': ' . $e->getMessage(); }
    }
    if (!$problems) q('DELETE FROM users WHERE id = ?', [$user['id']]);
    return $problems;
}
