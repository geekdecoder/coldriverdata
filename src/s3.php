<?php
declare(strict_types=1);

// Minimal S3 client (Signature V4, no SDK) for S3-compatible object storage such as
// Hetzner Object Storage or Backblaze B2. A backend is an array with:
//   endpoint (host or URL), region, bucket, access_key, secret_key, path_style (bool)

class S3Error extends RuntimeException {}

function s3_enc(string $s): string { return rawurlencode($s); }

/** [scheme, host, base path] for a backend, honouring virtual-hosted or path-style addressing. */
function s3_target(array $b): array
{
    $ep = trim((string)$b['endpoint']);
    if (!preg_match('#^https?://#i', $ep)) $ep = 'https://' . $ep;
    $u = parse_url(rtrim($ep, '/'));
    $host = $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    if (!empty($b['path_style'])) return [$u['scheme'], $host, '/' . $b['bucket']];
    return [$u['scheme'], $b['bucket'] . '.' . $host, ''];
}

function s3_key_path(string $key): string
{
    return $key === '' ? '/' : '/' . implode('/', array_map('s3_enc', explode('/', $key)));
}

function s3_canonical_query(array $query): string
{
    ksort($query, SORT_STRING);
    $parts = [];
    foreach ($query as $k => $v) $parts[] = s3_enc((string)$k) . '=' . s3_enc((string)$v);
    return implode('&', $parts);
}

function s3_signing_key(array $b, string $day): string
{
    $k = hash_hmac('sha256', $day, 'AWS4' . $b['secret_key'], true);
    $k = hash_hmac('sha256', $b['region'], $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

/** Authorization header value for a request. $headers must be lower-case name => value and include host. */
function s3_authorization(array $b, string $method, string $path, array $query, array $headers, string $payloadHash, string $amzDate): string
{
    ksort($headers, SORT_STRING);
    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim((string)$v) . "\n";
    $signed = implode(';', array_keys($headers));
    $canonical = implode("\n", [$method, $path, s3_canonical_query($query), $canonHeaders, $signed, $payloadHash]);
    $day = substr($amzDate, 0, 8);
    $scope = $day . '/' . $b['region'] . '/s3/aws4_request';
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $sig = hash_hmac('sha256', $toSign, s3_signing_key($b, $day));
    return 'AWS4-HMAC-SHA256 Credential=' . $b['access_key'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig;
}

/**
 * Send one request. $upload = ['fh' => resource, 'length' => int] streams that many bytes
 * from the handle's current position as the body. Returns [status, headers, body].
 */
function s3_request(array $b, string $method, string $key, array $query = [], string $body = '', ?array $upload = null, array $extraHeaders = []): array
{
    [$scheme, $host, $base] = s3_target($b);
    $path = $base . s3_key_path($key);
    $amzDate = gmdate('Ymd\THis\Z');
    $payloadHash = $upload ? 'UNSIGNED-PAYLOAD' : hash('sha256', $body);
    $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
    foreach ($extraHeaders as $k => $v) $headers[strtolower($k)] = $v;
    $auth = s3_authorization($b, $method, $path, $query, $headers, $payloadHash, $amzDate);

    $send = ['Authorization: ' . $auth, 'Expect:'];
    foreach ($headers as $k => $v) if ($k !== 'host') $send[] = $k . ': ' . $v;
    $qs = s3_canonical_query($query);
    $respHeaders = [];
    $ch = curl_init($scheme . '://' . $host . $path . ($qs !== '' ? '?' . $qs : ''));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $upload ? 3600 : 120,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
    if ($upload) {
        $left = (int)$upload['length'];
        $fh = $upload['fh'];
        $send[] = 'Content-Length: ' . $left;
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILESIZE, $left);
        curl_setopt($ch, CURLOPT_READFUNCTION, function ($c, $h, $max) use ($fh, &$left) {
            if ($left <= 0) return '';
            $data = fread($fh, (int)min($max, $left));
            if ($data === false) return '';
            $left -= strlen($data);
            return $data;
        });
    } elseif ($body !== '' || in_array($method, ['POST', 'PUT'], true)) {
        if (!isset($headers['content-type'])) $send[] = 'Content-Type: application/octet-stream';   // stop curl labelling it as a form
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $send);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) throw new S3Error('Could not reach the storage server: ' . $err);
    return [$status, $respHeaders, (string)$resp];
}

function s3_fail(string $what, int $status, string $body): never
{
    $msg = preg_match('#<Message>(.*?)</Message>#s', $body, $m) ? html_entity_decode($m[1]) : '';
    $code = preg_match('#<Code>(.*?)</Code>#s', $body, $m) ? $m[1] : '';
    throw new S3Error(trim("$what failed (HTTP $status" . ($code ? ", $code" : '') . ')' . ($msg ? ': ' . $msg : '')));
}

/** Size of an object in bytes, or null if it does not exist. */
function s3_head(array $b, string $key): ?int
{
    [$status, $h, $body] = s3_request($b, 'HEAD', $key);
    if ($status === 404) return null;
    if ($status >= 300) s3_fail('Checking the object', $status, $body);
    return (int)($h['content-length'] ?? 0);
}

function s3_delete(array $b, string $key): void
{
    [$status, , $body] = s3_request($b, 'DELETE', $key);
    if ($status >= 300 && $status !== 404) s3_fail('Deleting the object', $status, $body);
}

/** Upload a local file, using multipart for anything larger than one part. Verifies the stored size. */
function s3_put_file(array $b, string $key, string $path, int $partSize = 64 * 1024 * 1024): void
{
    $size = filesize($path);
    $fh = fopen($path, 'rb');
    if ($size === false || !$fh) throw new S3Error('Cannot read the local file.');
    try {
        if ($size <= $partSize) {
            [$status, , $body] = s3_request($b, 'PUT', $key, [], '', ['fh' => $fh, 'length' => $size]);
            if ($status >= 300) s3_fail('Upload', $status, $body);
        } else {
            $partSize = (int)max($partSize, ceil($size / 9000));   // S3 allows at most 10,000 parts
            [$status, , $body] = s3_request($b, 'POST', $key, ['uploads' => '']);
            if ($status >= 300 || !preg_match('#<UploadId>(.*?)</UploadId>#s', $body, $m)) s3_fail('Starting the upload', $status, $body);
            $uploadId = html_entity_decode($m[1]);
            try {
                $xml = '<CompleteMultipartUpload>';
                for ($n = 1, $off = 0; $off < $size; $n++, $off += $partSize) {
                    $len = (int)min($partSize, $size - $off);
                    fseek($fh, $off);
                    $etag = null;
                    for ($try = 1; $try <= 3 && $etag === null; $try++) {
                        fseek($fh, $off);
                        try {
                            [$status, $h, $body] = s3_request($b, 'PUT', $key, ['partNumber' => $n, 'uploadId' => $uploadId], '', ['fh' => $fh, 'length' => $len]);
                            if ($status < 300 && isset($h['etag'])) $etag = $h['etag'];
                            elseif ($try === 3) s3_fail("Uploading part $n", $status, $body);
                        } catch (S3Error $e) {
                            if ($try === 3) throw $e;
                        }
                    }
                    $xml .= '<Part><PartNumber>' . $n . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
                }
                $xml .= '</CompleteMultipartUpload>';
                [$status, , $body] = s3_request($b, 'POST', $key, ['uploadId' => $uploadId], $xml, null, ['content-type' => 'application/xml']);
                if ($status >= 300 || str_contains($body, '<Error>')) s3_fail('Finishing the upload', $status, $body);
            } catch (Throwable $e) {
                try { s3_request($b, 'DELETE', $key, ['uploadId' => $uploadId]); } catch (Throwable $ignored) {}
                throw $e;
            }
        }
    } finally {
        fclose($fh);
    }
    $stored = s3_head($b, $key);
    if ($stored !== $size) throw new S3Error('The stored copy is ' . var_export($stored, true) . " bytes but the file is $size bytes.");
}

/** A time-limited download link for a private object. */
function s3_presign_get(array $b, string $key, int $expires = 300, ?string $downloadName = null, ?int $now = null): string
{
    [$scheme, $host, $base] = s3_target($b);
    $path = $base . s3_key_path($key);
    $amzDate = gmdate('Ymd\THis\Z', $now ?? time());
    $day = substr($amzDate, 0, 8);
    $scope = $day . '/' . $b['region'] . '/s3/aws4_request';
    $query = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => $b['access_key'] . '/' . $scope,
        'X-Amz-Date' => $amzDate,
        'X-Amz-Expires' => (string)$expires,
        'X-Amz-SignedHeaders' => 'host',
    ];
    if ($downloadName !== null) {
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $downloadName);
        $query['response-content-disposition'] = 'attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($downloadName);
    }
    $canonical = implode("\n", ['GET', $path, s3_canonical_query($query), 'host:' . $host . "\n", 'host', 'UNSIGNED-PAYLOAD']);
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $query['X-Amz-Signature'] = hash_hmac('sha256', $toSign, s3_signing_key($b, $day));
    return $scheme . '://' . $host . $path . '?' . s3_canonical_query($query);
}

/** Write, read back and delete a small object. Throws S3Error with the reason on failure. */
function s3_self_test(array $b): void
{
    $key = '_crd_test_' . bin2hex(random_bytes(6));
    [$status, , $body] = s3_request($b, 'PUT', $key, [], 'cold river storage test');
    if ($status >= 300) s3_fail('Writing a test object', $status, $body);
    $size = s3_head($b, $key);
    s3_delete($b, $key);
    if ($size !== 23) throw new S3Error('The test object did not read back correctly.');
}
