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

/** A 32-byte key for one purpose, derived from app_key so nothing but app_key needs safeguarding. */
function derived_key(string $purpose): string
{
    $k = app_key();
    if (!$k) throw new RuntimeException("Set 'app_key' in config.php first.");
    return hash_hkdf('sha256', $k, 32, 'crd:' . $purpose);
}

/** The encryption key for one stored file. */
function file_key(array $file): string { return derived_key('file:' . $file['storage_key']); }

/** Connection details for one file's object: adds its encryption key when the file is stored encrypted. */
function file_conn(array $b, array $file, ?bool $encrypted = null): array
{
    $c = backend_s3($b);
    if ($encrypted ?? !empty($file['encrypted'])) $c['sse_key'] = file_key($file);
    return $c;
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
    $enc = !empty($b['encrypt']);
    s3_put_file(file_conn($b, $file, $enc), object_key($file), $local);          // verifies the stored size
    q('UPDATE files SET backend_id = ?, ship_to = NULL, ship_error = NULL, encrypted = ? WHERE id = ?', [$b['id'], $enc ? 1 : 0, $file['id']]);
    unlink($local);
}

/** Send a file to the browser as a download. */
function send_file(array $file): never
{
    $b = backend(isset($file['backend_id']) ? (int)$file['backend_id'] : null);
    if ($b && $b['type'] === 's3') {
        if (empty($file['encrypted'])) {
            header('Location: ' . s3_presign_get(backend_s3($b), object_key($file), 300, $file['name']), true, 302);
            exit;
        }
        // Encrypted objects need their key on every request, so they are streamed through this server.
        $conn = file_conn($b, $file);
        if (s3_head($conn, object_key($file)) === null) fail(404, 'That file is missing from storage.');
        while (ob_get_level()) ob_end_clean();
        set_time_limit(0);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . (int)$file['size']);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file['name']) . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        s3_get($conn, object_key($file), function ($chunk) { echo $chunk; flush(); });
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

/** Send a plain-text email. With 'mail_log' set in config.php, messages are written to that file instead (for testing). */
function send_mail(string $to, string $subject, string $body): bool
{
    $from = (string)cfg('mail_from');
    if ($log = cfg('mail_log')) {
        return file_put_contents($log, "To: $to\nSubject: $subject\n\n$body\n---\n", FILE_APPEND) !== false;
    }
    $headers = 'From: ' . cfg('app_name') . ' <' . $from . ">\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, $subject, $body, $headers, '-f' . escapeshellarg($from));
}

function send_password_reset(array $user): void
{
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?', [$user['id'], now()]);
    q('INSERT INTO password_resets (token_hash, user_id, expires_at) VALUES (?, ?, ?)',
        [hash('sha256', $token), $user['id'], gmdate('Y-m-d H:i:s', time() + 3600)]);
    send_mail($user['email'], 'Reset your ' . cfg('app_name') . ' password',
        "Use this link within one hour to choose a new password:\n\n" . url('/reset?token=' . $token) .
        "\n\nIf you did not ask for this, ignore this email.");
}

/** Email a confirmation link. Returns false if one was sent in the last minute. */
function send_verification(array $user): bool
{
    $last = q('SELECT MAX(created_at) FROM email_verifications WHERE user_id = ?', [$user['id']])->fetchColumn();
    if ($last && strtotime($last . ' UTC') > time() - 60) return false;
    $token = bin2hex(random_bytes(32));
    q('DELETE FROM email_verifications WHERE expires_at < ?', [now()]);
    q('INSERT INTO email_verifications (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
        [hash('sha256', $token), $user['id'], now(), gmdate('Y-m-d H:i:s', time() + 2 * 86400)]);
    send_mail($user['email'], 'Confirm your email for ' . cfg('app_name'),
        "Welcome to " . cfg('app_name') . ".\n\nConfirm this email address to finish setting up your account:\n\n" . url('/verify?token=' . $token) .
        "\n\nThe link works for 48 hours. If you did not create this account, ignore this email and it will be removed.");
    return true;
}

// ---------- bot check (Cloudflare Turnstile) ----------

function captcha_on(): bool { return (string)cfg('turnstile.site_key') !== '' && (string)cfg('turnstile.secret_key') !== ''; }

/** The widget for a form, or nothing when Turnstile is not configured. */
function captcha_field(): string
{
    if (!captcha_on()) return '';
    return '<div class="cf-turnstile" data-sitekey="' . h((string)cfg('turnstile.site_key')) . '"></div>'
         . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
}

/** True when the visitor passed the check, or when no check is configured. */
function captcha_ok(): bool
{
    if (!captcha_on()) return true;
    $token = (string)($_POST['cf-turnstile-response'] ?? '');
    if ($token === '') return false;
    $ch = curl_init((string)(cfg('turnstile.verify_url') ?: 'https://challenges.cloudflare.com/turnstile/v0/siteverify'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => cfg('turnstile.secret_key'), 'response' => $token, 'remoteip' => client_ip()]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = is_string($resp) ? json_decode($resp, true) : null;
    return is_array($data) && !empty($data['success']);
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
    if (!$problems) {
        @unlink(avatar_path((int)$user['id']));
        q('DELETE FROM users WHERE id = ?', [$user['id']]);
    }
    return $problems;
}

/** Re-store one unencrypted object on an encrypting backend, encrypted. The plain copy is replaced only after the new one verifies. */
function encrypt_stored_file(array $file): void
{
    $b = backend((int)$file['backend_id']);
    if (!$b || $b['type'] !== 's3' || empty($b['encrypt'])) throw new RuntimeException('Its storage location does not have encryption turned on.');
    $tmp = storage_tmp((int)$file['user_id'], 'enc_' . $file['id']);
    $fh = fopen($tmp, 'wb');
    try {
        s3_get(backend_s3($b), object_key($file), function ($d) use ($fh) { fwrite($fh, $d); });
        fclose($fh);
        if (filesize($tmp) !== (int)$file['size'] || hash_file('sha256', $tmp) !== $file['sha256']) throw new RuntimeException('The stored copy does not match the original, so it was left untouched.');
        s3_put_file(file_conn($b, $file, true), object_key($file), $tmp);
        q('UPDATE files SET encrypted = 1 WHERE id = ?', [$file['id']]);
    } finally {
        if (is_resource($fh)) fclose($fh);
        @unlink($tmp);
    }
}

// ---------- backups ----------

function backup_backend(): ?array
{
    return q("SELECT * FROM storage_backends WHERE is_backup = 1 AND type = 's3' LIMIT 1")->fetch() ?: null;
}

/** Encrypt (or decrypt) a file in 1 MB pieces with an authenticated stream cipher. */
function crypt_file(string $in, string $out, string $key, bool $encrypt): void
{
    $i = fopen($in, 'rb'); $o = fopen($out, 'wb');
    if (!$i || !$o) throw new RuntimeException('Cannot open the backup file.');
    $chunk = 1 << 20;
    if ($encrypt) {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($o, $header);
        while (true) {
            $data = (string)fread($i, $chunk);
            $last = feof($i);
            fwrite($o, sodium_crypto_secretstream_xchacha20poly1305_push($state, $data, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            if ($last) break;
        }
    } else {
        $header = fread($i, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $done = false;
        while (!$done && !feof($i)) {
            $data = fread($i, $chunk + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($data === '' || $data === false) break;
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $data);
            if ($res === false) throw new RuntimeException('The backup could not be decrypted: wrong app_key, or the file is damaged.');
            fwrite($o, $res[0]);
            $done = $res[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (!$done) throw new RuntimeException('The backup file is incomplete.');
    }
    fclose($i); fclose($o);
}

/** Database connection settings as mysqldump needs them. */
function db_cli_settings(): array
{
    $d = cfg('db');
    $parts = [];
    foreach (explode(';', (string)preg_replace('/^mysql:/', '', $d['dsn'])) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[trim($k)] = trim($v);
    }
    return ['host' => $parts['host'] ?? 'localhost', 'port' => $parts['port'] ?? '', 'socket' => $parts['unix_socket'] ?? '',
            'dbname' => $parts['dbname'] ?? '', 'user' => $d['user'], 'pass' => $d['pass']];
}

/** What the protection status page and the public security page report. Every item is checked, not assumed. */
function protection_status(): array
{
    $s = ['https' => str_starts_with((string)cfg('app_url'), 'https://'), 'app_key' => app_key() !== null];
    $def = default_backend();
    $s['default_encrypts'] = $def && $def['type'] === 's3' && !empty($def['encrypt']);
    $s['object_storage'] = $def && $def['type'] === 's3';
    $row = q('SELECT COUNT(*) AS total, COALESCE(SUM(encrypted), 0) AS enc, COALESCE(SUM(ship_to IS NOT NULL), 0) AS moving FROM files')->fetch();
    $s['files_total'] = (int)$row['total']; $s['files_encrypted'] = (int)$row['enc']; $s['files_moving'] = (int)$row['moving'];
    $s['files_all_encrypted'] = $s['default_encrypts'] && $s['files_total'] === $s['files_encrypted'];
    // Database tables: MariaDB reports each encrypted table here.
    $s['db_tables'] = 0; $s['db_encrypted'] = 0; $s['db_known'] = false;
    try {
        $name = db_cli_settings()['dbname'];
        // MariaDB records ENCRYPTED=YES in each table's options; it refuses that option unless the key plugin is active.
        $rows = q('SELECT TABLE_NAME, CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND ENGINE = ?', [$name, 'InnoDB'])->fetchAll();
        $s['db_tables'] = count($rows);
        foreach ($rows as $r) if (preg_match('/ENCRYPTED`?\s*=\s*\'?YES/i', (string)$r['CREATE_OPTIONS'])) $s['db_encrypted']++;
        $s['db_known'] = true;
    } catch (Throwable $e) { /* not MariaDB, or no permission: reported as unknown */ }
    $s['db_all_encrypted'] = $s['db_known'] && $s['db_tables'] > 0 && $s['db_tables'] === $s['db_encrypted'];
    $bb = backup_backend();
    $s['backup_location'] = $bb['name'] ?? null;
    $last = q('SELECT created_at, size FROM backups ORDER BY id DESC LIMIT 1')->fetch();
    $s['backup_last'] = $last['created_at'] ?? null;
    $s['backup_count'] = (int)q('SELECT COUNT(*) FROM backups')->fetchColumn();
    $s['backup_fresh'] = $last && strtotime($last['created_at'] . ' UTC') > time() - 36 * 3600;
    $s['backup_days'] = (int)(cfg('backup_days') ?? 30);
    return $s;
}
