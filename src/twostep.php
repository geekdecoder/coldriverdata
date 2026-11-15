<?php
declare(strict_types=1);

// Two-step sign-in: time-based codes from an authenticator app (RFC 6238, SHA-1, 6 digits,
// 30 seconds) plus one-time recovery codes. Secrets are encrypted with app_key.

const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function b32_decode(string $s): string
{
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) {
        $bits .= str_pad(decbin(strpos(B32, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_new_secret(): string { return b32_encode(random_bytes(20)); }

function totp_code(string $secretB32, int $step): string
{
    $hash = hash_hmac('sha1', pack('J', $step), b32_decode($secretB32), true);
    $off = ord($hash[19]) & 0x0f;
    $num = ((ord($hash[$off]) & 0x7f) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/** The time step a code is valid for (now, or one step either side), or null if it is wrong. */
function totp_match(string $secretB32, string $code, ?int $now = null): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return null;
    $step = intdiv($now ?? time(), 30);
    foreach ([0, -1, 1] as $d) {
        if (hash_equals(totp_code($secretB32, $step + $d), $code)) return $step + $d;
    }
    return null;
}

function totp_uri(string $secretB32, string $email): string
{
    $issuer = (string)cfg('app_name');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

function two_step_on(array $user): bool { return !empty($user['totp_enabled_at']) && !empty($user['totp_secret_enc']); }

/** Check an authenticator code for a user and burn its time step so it cannot be replayed. */
function two_step_check_code(array $user, string $code): bool
{
    if (!two_step_on($user)) return false;
    $step = totp_match(secret_decrypt((string)$user['totp_secret_enc']), $code);
    if ($step === null || ($user['totp_last_step'] !== null && $step <= (int)$user['totp_last_step'])) return false;
    q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
    return true;
}

function recovery_normalise(string $code): string { return strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code)); }

/** Use up a recovery code. Returns true if it was valid and unused. */
function two_step_use_recovery(array $user, string $code): bool
{
    $norm = recovery_normalise($code);
    if (strlen($norm) !== 10) return false;
    return q('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
        [now(), $user['id'], hash('sha256', $norm)])->rowCount() === 1;
}

/** Replace the user's recovery codes and return the new ones (shown once, never stored in readable form). */
function two_step_new_recovery_codes(int $userId): array
{
    q('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
    $codes = [];
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        q('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, hash('sha256', $c)]);
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return $codes;
}

function two_step_disable(int $userId): void
{
    q('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?', [$userId]);
    q('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
}
