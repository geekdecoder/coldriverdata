<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/stripe.php';
require dirname(__DIR__) . '/src/importer.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
require dirname(__DIR__) . '/src/twostep.php';
require dirname(__DIR__) . '/src/webauthn.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

// ---------- Stripe webhook (no session, no CSRF; authenticated by signature) ----------
if ($path === '/webhooks/stripe' && $method === 'POST') {
    $payload = (string)file_get_contents('php://input');
    $event = stripe_verify($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', (string)cfg('stripe.webhook_secret'));
    if (!$event || empty($event['id'])) { http_response_code(400); exit('bad signature'); }
    try {
        q('INSERT INTO stripe_events (id, received_at) VALUES (?, ?)', [$event['id'], now()]);
    } catch (PDOException $e) {
        exit('duplicate');   // already processed
    }
    try {
        stripe_handle_event($event);
    } catch (Throwable $e) {
        q('DELETE FROM stripe_events WHERE id = ?', [$event['id']]);   // let Stripe retry
        error_log('stripe webhook: ' . $e->getMessage());
        http_response_code(500);
        exit('error');
    }
    exit('ok');
}

start_session();
if ($method === 'POST') csrf_check();
$user = current_user();

// ---------- public pages ----------
if ($path === '/' && $method === 'GET') render('home');

if ($path === '/security' && $method === 'GET') {
    $st = protection_status();
    render('data_security', ['objectStorage' => $st['object_storage'], 'st' => $st]);
}
if ($path === '/terms' && $method === 'GET') render('terms');
if ($path === '/privacy' && $method === 'GET') render('privacy');

if ($path === '/signup') {
    if ($user) redirect('/app');
    $error = null; $email = '';
    if ($method === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        if (!captcha_ok()) $error = 'Please complete the check that shows you are not a robot.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $error = 'Enter a valid email address.';
        elseif (strlen($pass) < 10) $error = 'Use a password of at least 10 characters.';
        elseif (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetch()) $error = 'An account with that email already exists. Sign in instead.';
        else {
            q('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)', [$email, password_hash($pass, PASSWORD_DEFAULT), now()]);
            $newId = (int)db()->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['uid'] = $newId;
            $want = (string)($_POST['plan'] ?? '');
            if (isset(plans()[$want])) $_SESSION['want_plan'] = $want;
            send_verification(['id' => $newId, 'email' => $email]);
            redirect('/verify');
        }
    }
    render('signup', ['error' => $error, 'email' => $email, 'plan' => (string)($_GET['plan'] ?? ($_POST['plan'] ?? ''))]);
}

if ($path === '/login') {
    if ($user) redirect('/app');
    $error = null; $email = '';
    if ($method === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $recent = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?', [client_ip(), gmdate('Y-m-d H:i:s', time() - 900)])->fetchColumn();
        $row = q('SELECT * FROM users WHERE email = ?', [$email])->fetch();
        if ($recent >= 10) $error = 'Too many attempts. Wait 15 minutes and try again.';
        elseif ($row && !empty($row['suspended_at']) && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) $error = 'This account is suspended. Contact support.';
        elseif ($row && password_verify((string)($_POST['password'] ?? ''), $row['password_hash'])) {
            session_regenerate_id(true);
            if (second_factor_on($row)) {
                $_SESSION['pending_2fa'] = ['uid' => (int)$row['id'], 'at' => time(), 'tries' => 0];
                redirect('/login/code');
            }
            $_SESSION['uid'] = (int)$row['id'];
            redirect('/app');
        } else {
            q('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [client_ip(), now()]);
            $error = 'That email and password do not match.';
        }
    }
    render('login', ['error' => $error, 'email' => $email]);
}

if ($path === '/login/code') {
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || $p['at'] < time() - 600) { unset($_SESSION['pending_2fa']); redirect('/login'); }
    $error = null;
    if ($method === 'POST') {
        $row = q('SELECT * FROM users WHERE id = ?', [$p['uid']])->fetch();
        $code = (string)($_POST['code'] ?? '');
        $recent = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?', [client_ip(), gmdate('Y-m-d H:i:s', time() - 900)])->fetchColumn();
        if (!$row || !empty($row['suspended_at'])) { unset($_SESSION['pending_2fa']); redirect('/login'); }
        if ($recent >= 10 || $p['tries'] >= 5) {
            unset($_SESSION['pending_2fa']);
            flash('Too many wrong codes. Wait 15 minutes, then sign in again.', 'err');
            redirect('/login');
        }
        if (two_step_check_code($row, $code) || two_step_use_recovery($row, $code)) {
            unset($_SESSION['pending_2fa']);
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            $left = (int)q('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$row['id']])->fetchColumn();
            if ($left <= 2) flash('You have ' . $left . ' recovery code' . ($left === 1 ? '' : 's') . ' left. Make new ones on the Security page.', 'warn');
            redirect('/app');
        }
        $_SESSION['pending_2fa']['tries']++;
        q('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [client_ip(), now()]);
        $error = 'That code is not right. Codes change every 30 seconds, so use the newest one.';
    }
    $acct = q('SELECT * FROM users WHERE id = ?', [$p['uid']])->fetch();
    render('login_code', ['error' => $error, 'hasTotp' => $acct && two_step_on($acct), 'hasKeys' => $acct && count(user_keys((int)$acct['id'])) > 0]);
}

if (preg_match('#^/login/key/(options|verify)$#', $path, $m) && $method === 'POST') {
    $p = $_SESSION['pending_2fa'] ?? null;
    if (!$p || $p['at'] < time() - 600) json_out(['error' => 'Your sign-in expired. Start again.', 'redirect' => '/login'], 401);
    $row = q('SELECT * FROM users WHERE id = ?', [$p['uid']])->fetch();
    if (!$row || !empty($row['suspended_at'])) json_out(['error' => 'Start again.', 'redirect' => '/login'], 401);
    if ($m[1] === 'options') {
        $challenge = random_bytes(32);
        $_SESSION['webauthn_login'] = ['c' => base64_encode($challenge), 'at' => time()];
        json_out(['challenge' => b64u_enc($challenge), 'rpId' => webauthn_rp()['id'], 'timeout' => 60000, 'userVerification' => 'discouraged',
                  'allowCredentials' => array_map(fn($k) => ['type' => 'public-key', 'id' => $k['credential_id']], user_keys((int)$row['id']))]);
    }
    $w = $_SESSION['webauthn_login'] ?? null;
    unset($_SESSION['webauthn_login']);
    $recent = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?', [client_ip(), gmdate('Y-m-d H:i:s', time() - 900)])->fetchColumn();
    if ($recent >= 10 || $p['tries'] >= 5) { unset($_SESSION['pending_2fa']); json_out(['error' => 'Too many failed attempts. Wait 15 minutes, then sign in again.', 'redirect' => '/login'], 429); }
    try {
        if (!$w || $w['at'] < time() - 300) throw new WebAuthnError('That attempt expired. Try again.');
        $in = json_body();
        $key = q('SELECT * FROM security_keys WHERE user_id = ? AND credential_id = ?', [$row['id'], (string)($in['id'] ?? '')])->fetch();
        if (!$key) throw new WebAuthnError('That security key is not registered to this account.');
        $count = webauthn_verify_assertion($key, b64u_dec((string)($in['clientDataJSON'] ?? '')), b64u_dec((string)($in['authenticatorData'] ?? '')), b64u_dec((string)($in['signature'] ?? '')), base64_decode($w['c']));
        q('UPDATE security_keys SET sign_count = ?, last_used_at = ? WHERE id = ?', [$count, now(), $key['id']]);
        unset($_SESSION['pending_2fa']);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$row['id'];
        json_out(['ok' => true, 'redirect' => '/app']);
    } catch (WebAuthnError $e) {
        $_SESSION['pending_2fa']['tries']++;
        q('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [client_ip(), now()]);
        json_out(['error' => $e->getMessage()], 400);
    }
}

if ($path === '/logout' && $method === 'POST') {
    $_SESSION = [];
    session_destroy();
    redirect('/');
}

if ($path === '/forgot') {
    $sent = false;
    if ($method === 'POST') {
        if (!captcha_ok()) render('forgot', ['sent' => false, 'error' => 'Please complete the check that shows you are not a robot.']);
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $row = q('SELECT id, email FROM users WHERE email = ?', [$email])->fetch();
        if ($row) send_password_reset($row);
        $sent = true;   // same answer whether or not the address exists
    }
    render('forgot', ['sent' => $sent, 'error' => null]);
}

if ($path === '/reset') {
    $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
    $row = q('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), now()])->fetch();
    if (!$row) fail(400, 'That reset link has expired. Ask for a new one.');
    $error = null;
    if ($method === 'POST') {
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 10) $error = 'Use a password of at least 10 characters.';
        else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $row['user_id']]);
            q('DELETE FROM password_resets WHERE user_id = ?', [$row['user_id']]);
            flash('Password changed. Sign in with the new one.');
            redirect('/login');
        }
    }
    render('reset', ['token' => $token, 'error' => $error]);
}

// ---------- email verification ----------
if ($path === '/verify' && $method === 'GET') {
    $token = (string)($_GET['token'] ?? '');
    if ($token !== '') {
        $row = q('SELECT * FROM email_verifications WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), now()])->fetch();
        if (!$row) {
            if ($user && $user['email_verified_at']) redirect('/app');
            flash('That confirmation link has expired or was already used. Send a new one below.', 'err');
            redirect($user ? '/verify' : '/login');
        }
        q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $row['user_id']]);
        q('DELETE FROM email_verifications WHERE user_id = ?', [$row['user_id']]);
        flash('Email confirmed.' . ($user ? '' : ' Sign in to continue.'));
        if (!$user) redirect('/login');
        $want = (string)($_SESSION['want_plan'] ?? '');
        unset($_SESSION['want_plan']);
        redirect('/app/billing' . ($want !== '' ? '?plan=' . $want : ''));
    }
    $user = require_user();
    if ($user['email_verified_at']) redirect('/app');
    render('verify', ['user' => $user]);
}

if ($path === '/verify/resend' && $method === 'POST') {
    $user = require_user();
    if ($user['email_verified_at']) redirect('/app');
    flash(send_verification($user) ? 'A new confirmation link is on its way to ' . $user['email'] . '.' : 'A link was sent less than a minute ago. Check your inbox and spam folder, then try again shortly.', 'ok');
    redirect('/verify');
}

// ---------- admin ----------
if (str_starts_with($path, '/admin')) require dirname(__DIR__) . '/src/admin.php';

// ---------- signed-in app ----------
if (!str_starts_with($path, '/app')) fail(404, 'There is no page at this address.');
$user = require_user();
if (!$user['email_verified_at']) redirect('/verify');
$plan = active_plan($user);
$used = used_bytes((int)$user['id']);

if ($path === '/app' && $method === 'GET') {
    $stats = q('SELECT COUNT(*) AS convs, COALESCE(SUM(message_count), 0) AS msgs FROM conversations WHERE user_id = ?', [$user['id']])->fetch();
    $bySource = q('SELECT source, COUNT(*) AS n FROM conversations WHERE user_id = ? GROUP BY source', [$user['id']])->fetchAll();
    $recent = q('SELECT id, title, source, updated_at, message_count FROM conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT 8', [$user['id']])->fetchAll();
    $pending = (int)q("SELECT COUNT(*) FROM imports WHERE user_id = ? AND status IN ('queued','running')", [$user['id']])->fetchColumn();
    render('dashboard', compact('user', 'plan', 'used', 'stats', 'bySource', 'recent', 'pending'));
}

if ($path === '/app/files' && $method === 'GET') {
    $files = q('SELECT f.*, i.status AS import_status, i.conversations AS import_convs, i.error AS import_error
                FROM files f LEFT JOIN imports i ON i.file_id = f.id
                WHERE f.user_id = ? ORDER BY f.created_at DESC, f.id DESC', [$user['id']])->fetchAll();
    render('files', compact('user', 'plan', 'used', 'files'));
}

if ($path === '/app/upload/chunk' && $method === 'POST') {
    if (!$plan) json_out(['error' => 'Choose a plan before uploading.'], 402);
    $uploadId = (string)($_POST['upload_id'] ?? '');
    $index = (int)($_POST['index'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    $size = (int)($_POST['size'] ?? 0);
    $name = trim(str_replace(["\0", '/', '\\'], '', (string)($_POST['name'] ?? '')));
    $name = mb_substr($name, 0, 255);
    if (!preg_match('/^[a-f0-9]{32}$/', $uploadId) || $index < 0 || $total < 1 || $index >= $total || $size < 1 || $name === '') {
        json_out(['error' => 'Bad upload request.'], 400);
    }
    if ($used + $size > $plan['bytes']) {
        json_out(['error' => 'Not enough space left on your plan for this file (' . fmt_bytes($plan['bytes'] - $used) . ' free).'], 413);
    }
    $chunk = $_FILES['chunk'] ?? null;
    if (!$chunk || $chunk['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($chunk['tmp_name'])) {
        json_out(['error' => 'The upload was interrupted. Try again.'], 400);
    }
    $part = storage_tmp((int)$user['id'], $uploadId);
    $meta = $part . '.n';
    $expected = is_file($meta) ? (int)file_get_contents($meta) : 0;
    if ($index !== $expected) json_out(['error' => 'Upload out of order. Start the file again.'], 409);
    if ($index === 0 && is_file($part)) unlink($part);
    $in = fopen($chunk['tmp_name'], 'rb');
    $out = fopen($part, 'ab');
    stream_copy_to_stream($in, $out);
    fclose($in); fclose($out);
    clearstatcache(true, $part);
    if (filesize($part) > $size) { @unlink($part); @unlink($meta); json_out(['error' => 'Upload is larger than declared.'], 400); }
    if ($index + 1 < $total) {
        file_put_contents($meta, (string)($index + 1));
        json_out(['ok' => true]);
    }
    @unlink($meta);
    $real = filesize($part);
    if ($real !== $size || $used + $real > $plan['bytes']) { @unlink($part); json_out(['error' => 'Upload did not complete. Try again.'], 400); }
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json' && is_claude_manifest($part, $real)) {
        @unlink($part);   // not stored: it holds download links, not conversations
        json_out(['error' => 'This is the manifest from Claude, not your chats. Open it, find conversations-000.zip, open its export_url in the browser where you are signed in to Claude, then upload the .zip that downloads.'], 422);
    }
    $key = bin2hex(random_bytes(20));
    $sha = hash_file('sha256', $part);
    try {
        [$backendId, $shipTo] = store_upload($part, (int)$user['id'], $key);
    } catch (Throwable $e) {
        @unlink($part);
        error_log('upload store: ' . $e->getMessage());
        json_out(['error' => 'The file could not be stored. Try again shortly.'], 500);
    }
    q('INSERT INTO files (user_id, name, size, sha256, storage_key, created_at, backend_id, ship_to) VALUES (?,?,?,?,?,?,?,?)',
        [$user['id'], $name, $real, $sha, $key, now(), $backendId, $shipTo]);
    $fileId = (int)db()->lastInsertId();
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'zip' || $ext === 'json') {
        q("INSERT INTO imports (user_id, file_id, created_at) VALUES (?, ?, ?)", [$user['id'], $fileId, now()]);
        if ($real <= 25 * 1024 * 1024) {   // small exports are filed right away; larger ones wait for the worker
            run_import(['id' => (int)db()->lastInsertId(), 'file_id' => $fileId]);
        }
    }
    json_out(['ok' => true, 'done' => true]);
}

if (preg_match('#^/app/files/(\d+)/(download|delete)$#', $path, $m)) {
    $file = q('SELECT * FROM files WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$file) fail(404, 'That file is not in your account.');
    if ($m[2] === 'delete' && $method === 'POST') {
        try { delete_file_row($file); flash('Deleted ' . $file['name'] . '.'); }
        catch (Throwable $e) { error_log('delete: ' . $e->getMessage()); flash('That file could not be deleted right now. Try again shortly.', 'err'); }
        redirect('/app/files');
    }
    if ($m[2] === 'download' && $method === 'GET') {
        send_file($file);
    }
}

/** A safe place to send someone back to inside the chat archive. */
function chats_return(): string
{
    $r = (string)($_POST['return'] ?? '');
    return (str_starts_with($r, '/app/chats') && !str_contains($r, '//') && !str_contains($r, "\n")) ? $r : '/app/chats';
}

if ($path === '/app/chats' && $method === 'GET') {
    $term = trim((string)($_GET['q'] ?? ''));
    $source = in_array($_GET['source'] ?? '', ['chatgpt', 'claude'], true) ? $_GET['source'] : '';
    $folder = (string)($_GET['folder'] ?? '');
    if ($folder !== 'none' && !ctype_digit($folder)) $folder = '';
    $starred = !empty($_GET['starred']);
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
    $sorts = ['new' => 'c.updated_at DESC, c.id DESC', 'old' => 'c.updated_at ASC, c.id ASC', 'long' => 'c.message_count DESC, c.id DESC', 'title' => 'c.title ASC, c.id ASC'];
    $sort = isset($sorts[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'new';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 40;

    $conds = ['c.user_id = ?']; $args = [$user['id']];
    if ($source) { $conds[] = 'c.source = ?'; $args[] = $source; }
    if ($folder === 'none') $conds[] = 'c.folder_id IS NULL';
    elseif ($folder !== '') { $conds[] = 'c.folder_id = ?'; $args[] = (int)$folder; }
    if ($starred) $conds[] = 'c.starred = 1';
    if ($month) { $conds[] = 'c.updated_at >= ? AND c.updated_at < ?'; $args[] = $month . '-01 00:00:00'; $args[] = gmdate('Y-m-d H:i:s', strtotime($month . '-01 +1 month UTC')); }
    $where = implode(' AND ', $conds);
    $cols = 'c.id, c.title, c.source, c.updated_at, c.message_count, c.starred, c.folder_id';

    $hits = null; $convs = []; $more = false;
    if ($term !== '') {
        $sel = "SELECT $cols, m.body FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.user_id = ? AND $where AND ";
        $base = array_merge([$user['id']], $args);
        $rows = q($sel . 'MATCH(m.body) AGAINST (? IN NATURAL LANGUAGE MODE) LIMIT 200', array_merge($base, [$term]))->fetchAll();
        $like = '%' . addcslashes($term, '%_\\') . '%';
        if (!$rows) {   // short words and stop-words are not in the full-text index
            $rows = q($sel . 'm.body LIKE ? ORDER BY c.updated_at DESC LIMIT 200', array_merge($base, [$like]))->fetchAll();
        }
        $hits = [];
        foreach ($rows as $r) {
            if (isset($hits[$r['id']])) { $hits[$r['id']]['count']++; continue; }
            $pos = mb_stripos($r['body'], $term);
            if ($pos === false) { $first = preg_split('/\s+/', $term)[0]; $pos = mb_stripos($r['body'], $first) ?: 0; }
            $from = max(0, $pos - 90);
            $r['snippet'] = ($from > 0 ? '…' : '') . trim(mb_substr($r['body'], $from, 240)) . '…';
            $r['count'] = 1;
            unset($r['body']);
            $hits[$r['id']] = $r;
        }
        foreach (q("SELECT $cols FROM conversations c WHERE $where AND c.title LIKE ? LIMIT 50", array_merge($args, [$like])) as $r) {
            if (!isset($hits[$r['id']])) $hits[$r['id']] = $r + ['snippet' => '', 'count' => 0];
        }
    } else {
        $convs = q("SELECT $cols FROM conversations c WHERE $where ORDER BY {$sorts[$sort]} LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $args)->fetchAll();
        $more = count($convs) > $per;
        $convs = array_slice($convs, 0, $per);
    }
    $folders = q('SELECT f.id, f.name, COUNT(c.id) AS n FROM folders f LEFT JOIN conversations c ON c.folder_id = f.id WHERE f.user_id = ? GROUP BY f.id, f.name ORDER BY f.name', [$user['id']])->fetchAll();
    $totals = q('SELECT COUNT(*) AS total, COALESCE(SUM(starred), 0) AS starred, COALESCE(SUM(folder_id IS NULL), 0) AS unfiled FROM conversations WHERE user_id = ?', [$user['id']])->fetch();
    $months = q("SELECT DATE_FORMAT(updated_at, '%Y-%m') AS ym, COUNT(*) AS n FROM conversations WHERE user_id = ? AND updated_at IS NOT NULL GROUP BY ym ORDER BY ym DESC", [$user['id']])->fetchAll();
    render('chats', compact('user', 'term', 'source', 'folder', 'starred', 'month', 'sort', 'page', 'hits', 'convs', 'more', 'folders', 'totals', 'months'));
}

if ($path === '/app/chats/bulk' && $method === 'POST') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $action = (string)($_POST['action'] ?? '');
    if (!$ids) { flash('Tick at least one conversation first.', 'err'); redirect(chats_return()); }
    $ids = array_slice($ids, 0, 500);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $n = count($ids);
    if ($action === 'star' || $action === 'unstar') {
        q("UPDATE conversations SET starred = ? WHERE user_id = ? AND id IN ($in)", array_merge([$action === 'star' ? 1 : 0, $user['id']], $ids));
        flash(($action === 'star' ? 'Starred ' : 'Removed the star from ') . $n . ' conversation' . ($n === 1 ? '' : 's') . '.');
    } elseif ($action === 'unfile') {
        q("UPDATE conversations SET folder_id = NULL WHERE user_id = ? AND id IN ($in)", array_merge([$user['id']], $ids));
        flash("Took $n conversation" . ($n === 1 ? '' : 's') . ' out of their folder.');
    } elseif (preg_match('/^move:(\d+)$/', $action, $mm) && ($f = q('SELECT id, name FROM folders WHERE id = ? AND user_id = ?', [$mm[1], $user['id']])->fetch())) {
        q("UPDATE conversations SET folder_id = ? WHERE user_id = ? AND id IN ($in)", array_merge([$f['id'], $user['id']], $ids));
        flash("Moved $n conversation" . ($n === 1 ? '' : 's') . ' to ' . $f['name'] . '.');
    } else {
        flash('Choose what to do with the ticked conversations.', 'err');
    }
    redirect(chats_return());
}

if (preg_match('#^/app/chats/(\d+)/(star|move)$#', $path, $m) && $method === 'POST') {
    $conv = q('SELECT id, starred FROM conversations WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$conv) fail(404, 'That conversation is not in your archive.');
    if ($m[2] === 'star') {
        q('UPDATE conversations SET starred = ? WHERE id = ?', [$conv['starred'] ? 0 : 1, $conv['id']]);
    } else {
        $fid = (string)($_POST['folder'] ?? '');
        $f = ctype_digit($fid) ? q('SELECT id FROM folders WHERE id = ? AND user_id = ?', [$fid, $user['id']])->fetch() : null;
        q('UPDATE conversations SET folder_id = ? WHERE id = ?', [$f['id'] ?? null, $conv['id']]);
    }
    redirect(chats_return());
}

if ($path === '/app/folders' && $method === 'POST') {
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? ''))), 0, 80);
    if ($name === '') flash('Give the folder a name.', 'err');
    elseif ((int)q('SELECT COUNT(*) FROM folders WHERE user_id = ?', [$user['id']])->fetchColumn() >= 200) flash('You have reached the limit of 200 folders.', 'err');
    elseif (q('SELECT 1 FROM folders WHERE user_id = ? AND name = ?', [$user['id'], $name])->fetch()) flash('You already have a folder with that name.', 'err');
    else {
        q('INSERT INTO folders (user_id, name, created_at) VALUES (?, ?, ?)', [$user['id'], $name, now()]);
        redirect('/app/chats?folder=' . (int)db()->lastInsertId());
    }
    redirect(chats_return());
}

if (preg_match('#^/app/folders/(\d+)/(rename|delete)$#', $path, $m) && $method === 'POST') {
    $f = q('SELECT * FROM folders WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$f) fail(404, 'That folder does not exist.');
    if ($m[2] === 'delete') {
        q('DELETE FROM folders WHERE id = ?', [$f['id']]);     // its conversations become unfiled, not deleted
        flash('Deleted the folder ' . $f['name'] . '. Its conversations are still in your archive.');
        redirect('/app/chats');
    }
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? ''))), 0, 80);
    if ($name === '') flash('Give the folder a name.', 'err');
    elseif (q('SELECT 1 FROM folders WHERE user_id = ? AND name = ? AND id <> ?', [$user['id'], $name, $f['id']])->fetch()) flash('You already have a folder with that name.', 'err');
    else q('UPDATE folders SET name = ? WHERE id = ?', [$name, $f['id']]);
    redirect('/app/chats?folder=' . $f['id']);
}

if (preg_match('#^/app/chats/(\d+)/export/(md|txt|json)$#', $path, $m) && $method === 'GET') {
    $conv = q('SELECT * FROM conversations WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$conv) fail(404, 'That conversation is not in your archive.');
    $messages = q('SELECT role, body, sent_at FROM messages WHERE conversation_id = ? ORDER BY position', [$conv['id']])->fetchAll();
    $who = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude'][$conv['source']] ?? 'Assistant';
    $when = fn(?string $t) => $t ? fmt_date($t, 'M j, Y g:i a') . ' UTC' : '';
    $slug = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $conv['title']), '-');
    $slug = $slug !== '' ? substr($slug, 0, 60) : 'conversation';
    if ($m[2] === 'json') {
        $type = 'application/json';
        $out = json_encode([
            'title' => $conv['title'], 'source' => $conv['source'], 'started_at' => $conv['started_at'], 'updated_at' => $conv['updated_at'],
            'exported_from' => cfg('app_name'), 'exported_at' => gmdate('c'),
            'messages' => array_map(fn($x) => ['role' => $x['role'], 'sent_at' => $x['sent_at'], 'text' => $x['body']], $messages),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($m[2] === 'md') {
        $type = 'text/markdown';
        $out = '# ' . $conv['title'] . "\n\n- Assistant: $who\n- Dates: " . fmt_date($conv['started_at']) . ' to ' . fmt_date($conv['updated_at'])
             . "\n- Messages: " . count($messages) . "\n- Exported from " . cfg('app_name') . ' on ' . gmdate('M j, Y') . "\n";
        foreach ($messages as $x) {
            $out .= "\n---\n\n### " . ($x['role'] === 'user' ? 'You' : $who) . ($x['sent_at'] ? ' · ' . $when($x['sent_at']) : '') . "\n\n" . trim($x['body']) . "\n";
        }
    } else {
        $type = 'text/plain';
        $out = $conv['title'] . "\n" . str_repeat('=', min(70, max(3, mb_strlen($conv['title'])))) . "\n$who · " . fmt_date($conv['started_at']) . ' to ' . fmt_date($conv['updated_at']) . ' · ' . count($messages) . " messages\n";
        foreach ($messages as $x) {
            $out .= "\n[" . ($x['role'] === 'user' ? 'You' : $who) . ']' . ($x['sent_at'] ? ' ' . $when($x['sent_at']) : '') . "\n" . trim($x['body']) . "\n";
        }
    }
    header("Content-Type: $type; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $slug . '.' . $m[2] . '"');
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

if (preg_match('#^/app/chats/(\d+)$#', $path, $m) && $method === 'GET') {
    $conv = q('SELECT * FROM conversations WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$conv) fail(404, 'That conversation is not in your archive.');
    $messages = q('SELECT role, body, sent_at FROM messages WHERE conversation_id = ? ORDER BY position', [$conv['id']])->fetchAll();
    $folders = q('SELECT id, name FROM folders WHERE user_id = ? ORDER BY name', [$user['id']])->fetchAll();
    render('chat', compact('user', 'conv', 'messages', 'folders'));
}

// ---------- profile ----------
if ($path === '/app/avatar' && $method === 'GET') {
    $p = avatar_path((int)$user['id']);
    if (empty($user['avatar_at']) || !is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($p));
    header('Cache-Control: private, max-age=31536000, immutable');
    readfile($p);
    exit;
}

if ($path === '/app/profile' && $method === 'GET') render('profile', ['user' => $user]);

if (preg_match('#^/app/profile/(name|avatar|avatar-remove|theme|password)$#', $path, $m) && $method === 'POST') {
    if ($m[1] === 'name') {
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['display_name'] ?? ''))), 0, 80);
        q('UPDATE users SET display_name = ? WHERE id = ?', [$name !== '' ? $name : null, $user['id']]);
        flash('Name saved.');
    } elseif ($m[1] === 'avatar') {
        $up = $_FILES['photo'] ?? null;
        if (!$up || $up['error'] === UPLOAD_ERR_NO_FILE) flash('Choose a photo first.', 'err');
        elseif (in_array($up['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) flash('That photo is too large. Use one under ' . fmt_bytes(min(ini_bytes('upload_max_filesize'), ini_bytes('post_max_size'))) . '.', 'err');
        elseif ($up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) flash('The upload was interrupted. Try again.', 'err');
        elseif ($err = save_avatar($up['tmp_name'], avatar_path((int)$user['id']))) flash($err, 'err');
        else { q('UPDATE users SET avatar_at = ? WHERE id = ?', [now(), $user['id']]); flash('Photo updated.'); }
    } elseif ($m[1] === 'avatar-remove') {
        @unlink(avatar_path((int)$user['id']));
        q('UPDATE users SET avatar_at = NULL WHERE id = ?', [$user['id']]);
        flash('Photo removed.');
    } elseif ($m[1] === 'theme') {
        $t = (string)($_POST['theme'] ?? '');
        if (isset(themes()[$t])) { q('UPDATE users SET theme = ? WHERE id = ?', [$t, $user['id']]); flash('Theme changed to ' . themes()[$t]['name'] . '.'); }
        redirect('/app/profile#appearance');
    } else {
        $new = (string)($_POST['new_password'] ?? '');
        if (!password_verify((string)($_POST['current_password'] ?? ''), $user['password_hash'])) flash('Your current password is not right. Nothing was changed.', 'err');
        elseif (strlen($new) < 10) flash('Use a new password of at least 10 characters.', 'err');
        else { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]); flash('Password changed.'); }
    }
    redirect('/app/profile');
}

if ($path === '/app/billing' && $method === 'GET') {
    render('billing', ['user' => $user, 'plan' => $plan, 'used' => $used, 'want' => (string)($_GET['plan'] ?? ''), 'paid' => isset($_GET['paid'])]);
}

if ($path === '/app/billing/checkout' && $method === 'POST') {
    try {
        $hasCard = $user['pay_method'] === 'card' && $user['stripe_subscription_id'] && in_array($user['plan_status'], ['active', 'past_due'], true);
        if ($hasCard) redirect(stripe_portal_url($user));   // change an existing subscription instead of buying a second one
        $pay = (string)($_POST['pay'] ?? '');
        if (!in_array($pay, ['card_monthly', 'card_yearly', 'crypto_yearly'], true)) fail(400, 'Choose how you want to pay.');
        redirect(stripe_checkout_url($user, (string)($_POST['plan'] ?? ''), $pay));
    } catch (StripeError $e) {
        flash($e->getMessage(), 'err');
        redirect('/app/billing');
    }
}

if ($path === '/app/billing/portal' && $method === 'POST') {
    try {
        if (!$user['stripe_customer_id']) throw new StripeError('There is no billing record for this account yet.');
        redirect(stripe_portal_url($user));
    } catch (StripeError $e) {
        flash($e->getMessage(), 'err');
        redirect('/app/billing');
    }
}

// ---------- security keys ----------
if ($path === '/app/security/keys/options' && $method === 'POST') {
    $existing = user_keys((int)$user['id']);
    if (count($existing) >= 10) json_out(['error' => 'You have reached the limit of 10 security keys.'], 400);
    $challenge = random_bytes(32);
    $_SESSION['webauthn_reg'] = ['c' => base64_encode($challenge), 'at' => time()];
    json_out([
        'challenge' => b64u_enc($challenge),
        'rp' => ['name' => cfg('app_name'), 'id' => webauthn_rp()['id']],
        'user' => ['id' => b64u_enc(hash('sha256', 'crd-user-' . $user['id'], true)), 'name' => $user['email'], 'displayName' => user_label($user)],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['userVerification' => 'discouraged', 'residentKey' => 'discouraged'],
        'attestation' => 'none', 'timeout' => 60000,
        'excludeCredentials' => array_map(fn($k) => ['type' => 'public-key', 'id' => $k['credential_id']], $existing),
    ]);
}

if ($path === '/app/security/keys/register' && $method === 'POST') {
    $w = $_SESSION['webauthn_reg'] ?? null;
    unset($_SESSION['webauthn_reg']);
    try {
        if (!$w || $w['at'] < time() - 300) throw new WebAuthnError('That attempt expired. Try again.');
        $in = json_body();
        $cred = webauthn_verify_registration(b64u_dec((string)($in['clientDataJSON'] ?? '')), b64u_dec((string)($in['attestationObject'] ?? '')), base64_decode($w['c']));
        if (q('SELECT 1 FROM security_keys WHERE credential_id = ?', [$cred['credential_id']])->fetch()) throw new WebAuthnError('That security key is already registered.');
        $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? ''))), 0, 80);
        if ($name === '') $name = 'Security key ' . (count(user_keys((int)$user['id'])) + 1);
        $first = !second_factor_on($user);
        q('INSERT INTO security_keys (user_id, credential_id, public_key, sign_count, name, created_at) VALUES (?,?,?,?,?,?)',
            [$user['id'], $cred['credential_id'], $cred['public_key'], $cred['sign_count'], $name, now()]);
        $unused = (int)q('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user['id']])->fetchColumn();
        if ($first || !$unused) $_SESSION['new_recovery_codes'] = two_step_new_recovery_codes((int)$user['id']);
        flash('Added "' . $name . '". Signing in now asks for a security key' . (two_step_on($user) ? ' or a code from your app' : '') . '.');
        json_out(['ok' => true, 'redirect' => '/app/security']);
    } catch (WebAuthnError $e) {
        json_out(['error' => $e->getMessage()], 400);
    }
}

if (preg_match('#^/app/security/keys/(\d+)/remove$#', $path, $m) && $method === 'POST') {
    $key = q('SELECT * FROM security_keys WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$key) redirect('/app/security');
    if (!password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
        flash('Your password is not right. The key was not removed.', 'err');
        redirect('/app/security');
    }
    q('DELETE FROM security_keys WHERE id = ?', [$key['id']]);
    if (!second_factor_on($user)) q('DELETE FROM recovery_codes WHERE user_id = ?', [$user['id']]);
    flash('Removed "' . $key['name'] . '".' . (second_factor_on($user) ? '' : ' Two-step sign-in is now off.'));
    redirect('/app/security');
}

// ---------- security (two-step sign-in) ----------
if ($path === '/app/security' && $method === 'GET') {
    $codes = $_SESSION['new_recovery_codes'] ?? null;
    unset($_SESSION['new_recovery_codes']);
    $setup = $_SESSION['totp_setup'] ?? null;
    render('security', [
        'user' => $user, 'available' => app_key() !== null, 'codes' => $codes, 'setup' => $setup,
        'left' => (int)q('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user['id']])->fetchColumn(),
        'keys' => user_keys((int)$user['id']),
    ]);
}

if (preg_match('#^/app/security/(start|cancel|confirm|disable|codes)$#', $path, $m) && $method === 'POST') {
    $back = '/app/security';
    if ($m[1] === 'start') {
        if (app_key() === null) { flash('Two-step sign-in is not available yet.', 'err'); redirect($back); }
        if (!two_step_on($user)) $_SESSION['totp_setup'] = totp_new_secret();
        redirect($back);
    }
    if ($m[1] === 'cancel') { unset($_SESSION['totp_setup']); redirect($back); }
    if ($m[1] === 'confirm') {
        $secret = $_SESSION['totp_setup'] ?? null;
        if (!$secret || two_step_on($user)) redirect($back);
        $step = totp_match($secret, (string)($_POST['code'] ?? ''));
        if ($step === null) { flash('That code is not right. Check the time on your phone is set automatically, then enter the newest code.', 'err'); redirect($back); }
        q('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?', [secret_encrypt($secret), now(), $step, $user['id']]);
        unset($_SESSION['totp_setup']);
        $_SESSION['new_recovery_codes'] = two_step_new_recovery_codes((int)$user['id']);
        flash('Two-step sign-in is on.');
        redirect($back);
    }
    // disable and new codes both need the password and a current code
    $okPass = password_verify((string)($_POST['password'] ?? ''), $user['password_hash']);
    $code = (string)($_POST['code'] ?? '');
    if ($m[1] === 'disable' && !two_step_on($user)) redirect($back);
    if (!second_factor_on($user)) redirect($back);
    // With an authenticator app, a current code is needed too. With only security keys, the password is enough here.
    $okCode = two_step_on($user) ? (two_step_check_code($user, $code) || two_step_use_recovery($user, $code)) : true;
    if (!$okPass || !$okCode) {
        flash('Password or code not right. Nothing was changed.', 'err');
        redirect($back);
    }
    if ($m[1] === 'disable') {
        two_step_disable((int)$user['id']);
        flash('Two-step sign-in is off.');
    } else {
        $_SESSION['new_recovery_codes'] = two_step_new_recovery_codes((int)$user['id']);
        flash('New recovery codes made. The old ones no longer work.');
    }
    redirect($back);
}

fail(404, 'There is no page at this address.');
