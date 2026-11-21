<?php
declare(strict_types=1);

// Security keys (WebAuthn / FIDO2: YubiKey and similar) as a second step at sign-in.
// No library: this verifies registrations and sign-ins directly. Attestation is not
// requested ("none"), so any standards-compliant key works.

class WebAuthnError extends RuntimeException {}

function b64u_enc(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_dec(string $s): string
{
    $bin = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($bin === false) throw new WebAuthnError('Malformed data from the security key.');
    return $bin;
}

/** The site's identity for keys: the host name, and the exact origin the browser must report. */
function webauthn_rp(): array
{
    $u = parse_url((string)cfg('app_url'));
    $host = $u['host'] ?? 'localhost';
    return ['id' => $host, 'origin' => ($u['scheme'] ?? 'https') . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : '')];
}

/** Decode one CBOR item at $o (advances $o). Covers what authenticators emit. */
function cbor_decode(string $d, int &$o = 0)
{
    $n = strlen($d);
    if ($o >= $n) throw new WebAuthnError('Truncated key data.');
    $b = ord($d[$o++]); $major = $b >> 5; $info = $b & 31;
    if ($info < 24) $val = $info;
    elseif ($info >= 24 && $info <= 27) {
        $len = 1 << ($info - 24);
        if ($o + $len > $n) throw new WebAuthnError('Truncated key data.');
        $val = 0;
        for ($i = 0; $i < $len; $i++) $val = ($val << 8) | ord($d[$o + $i]);
        $o += $len;
    } else throw new WebAuthnError('Unsupported key data.');
    switch ($major) {
        case 0: return $val;
        case 1: return -1 - $val;
        case 2: case 3:
            if ($val < 0 || $o + $val > $n) throw new WebAuthnError('Truncated key data.');
            $s = substr($d, $o, $val); $o += $val; return $s;
        case 4:
            if ($val > 1000) throw new WebAuthnError('Unsupported key data.');
            $a = []; for ($i = 0; $i < $val; $i++) $a[] = cbor_decode($d, $o); return $a;
        case 5:
            if ($val > 1000) throw new WebAuthnError('Unsupported key data.');
            $m = [];
            for ($i = 0; $i < $val; $i++) { $k = cbor_decode($d, $o); if (!is_int($k) && !is_string($k)) throw new WebAuthnError('Unsupported key data.'); $m[$k] = cbor_decode($d, $o); }
            return $m;
        case 6: return cbor_decode($d, $o);
        default:
            if ($info === 20) return false; if ($info === 21) return true; if ($info === 22) return null;
            throw new WebAuthnError('Unsupported key data.');
    }
}

function der_len(int $l): string { return $l < 128 ? chr($l) : ($l < 256 ? "\x81" . chr($l) : "\x82" . pack('n', $l)); }
function der(int $tag, string $body): string { return chr($tag) . der_len(strlen($body)) . $body; }
function der_uint(string $bytes): string { $bytes = ltrim($bytes, "\x00"); if ($bytes === '' || ord($bytes[0]) & 0x80) $bytes = "\x00" . $bytes; return der(0x02, $bytes); }
function pem_public(string $spki): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"; }

/** Turn the key's COSE public key into PEM. Supports ES256 (YubiKeys and most keys) and RS256. */
function cose_to_pem(array $k): string
{
    $kty = $k[1] ?? null; $alg = $k[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($k[-1] ?? null) === 1 && strlen((string)($k[-2] ?? '')) === 32 && strlen((string)($k[-3] ?? '')) === 32) {
        return pem_public(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3]);
    }
    if ($kty === 3 && $alg === -257 && is_string($k[-1] ?? null) && is_string($k[-2] ?? null) && strlen($k[-1]) >= 256) {
        $rsa = der(0x30, der_uint($k[-1]) . der_uint($k[-2]));
        return pem_public(der(0x30, der(0x30, hex2bin('06092a864886f70d0101010500')) . der(0x03, "\x00" . $rsa)));
    }
    throw new WebAuthnError('This kind of security key is not supported.');
}

/** Checks shared by registration and sign-in: the browser signed our challenge, on our site. */
function webauthn_check_client(string $clientDataJSON, string $type, string $challenge): void
{
    $c = json_decode($clientDataJSON, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new WebAuthnError('Unexpected response from the security key.');
    if (!is_string($c['challenge'] ?? null) || !hash_equals(b64u_enc($challenge), $c['challenge'])) throw new WebAuthnError('That attempt expired. Try again.');
    if (($c['origin'] ?? '') !== webauthn_rp()['origin']) throw new WebAuthnError('The security key was used on a different website address.');
}

/** Split authenticator data into its fields. */
function webauthn_auth_data(string $ad): array
{
    if (strlen($ad) < 37) throw new WebAuthnError('Truncated key data.');
    if (!hash_equals(hash('sha256', webauthn_rp()['id'], true), substr($ad, 0, 32))) throw new WebAuthnError('The security key was used for a different website.');
    $flags = ord($ad[32]);
    if (!($flags & 0x01)) throw new WebAuthnError('Touch the security key to confirm.');
    return ['flags' => $flags, 'count' => unpack('N', substr($ad, 33, 4))[1], 'rest' => substr($ad, 37)];
}

/** Verify a new key. Returns ['credential_id' => b64url, 'public_key' => PEM, 'sign_count' => int]. */
function webauthn_verify_registration(string $clientDataJSON, string $attestationObject, string $challenge): array
{
    webauthn_check_client($clientDataJSON, 'webauthn.create', $challenge);
    $o = 0;
    $att = cbor_decode($attestationObject, $o);
    if (!is_array($att) || !is_string($att['authData'] ?? null)) throw new WebAuthnError('Malformed data from the security key.');
    $ad = webauthn_auth_data($att['authData']);
    if (!($ad['flags'] & 0x40) || strlen($ad['rest']) < 18) throw new WebAuthnError('The security key did not return a new credential.');
    $len = unpack('n', substr($ad['rest'], 16, 2))[1];
    if ($len < 1 || $len > 512 || strlen($ad['rest']) < 18 + $len) throw new WebAuthnError('Malformed data from the security key.');
    $credId = substr($ad['rest'], 18, $len);
    $p = 18 + $len;
    $cose = cbor_decode($ad['rest'], $p);
    if (!is_array($cose)) throw new WebAuthnError('Malformed data from the security key.');
    return ['credential_id' => b64u_enc($credId), 'public_key' => cose_to_pem($cose), 'sign_count' => $ad['count']];
}

/** Verify a sign-in with a stored key row. Returns the key's new use counter. */
function webauthn_verify_assertion(array $key, string $clientDataJSON, string $authData, string $signature, string $challenge): int
{
    webauthn_check_client($clientDataJSON, 'webauthn.get', $challenge);
    $ad = webauthn_auth_data($authData);
    if (openssl_verify($authData . hash('sha256', $clientDataJSON, true), $signature, $key['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        throw new WebAuthnError('The security key could not be verified.');
    }
    // A counter that goes backwards means the key may have been copied.
    $old = (int)$key['sign_count'];
    if (($old > 0 || $ad['count'] > 0) && $ad['count'] <= $old) throw new WebAuthnError('This security key reported an unexpected use count, so it was refused.');
    return $ad['count'];
}

function user_keys(int $userId): array
{
    return q('SELECT * FROM security_keys WHERE user_id = ? ORDER BY id', [$userId])->fetchAll();
}

/** True when signing in needs a second step: an authenticator app, a security key, or both. */
function second_factor_on(array $user): bool
{
    return two_step_on($user) || (int)q('SELECT COUNT(*) FROM security_keys WHERE user_id = ?', [$user['id']])->fetchColumn() > 0;
}

/** Remove every second step for an account (lost phone and keys). */
function second_factor_reset(int $userId): void
{
    q('DELETE FROM security_keys WHERE user_id = ?', [$userId]);
    two_step_disable($userId);
}

/** Read and decode a JSON request body. */
function json_body(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}
