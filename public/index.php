<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/stripe.php';
require dirname(__DIR__) . '/src/importer.php';
require dirname(__DIR__) . '/src/s3.php';
require dirname(__DIR__) . '/src/storage.php';
require dirname(__DIR__) . '/src/twostep.php';

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
            if (two_step_on($row)) {
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
    render('login_code', ['error' => $error]);
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

if ($path === '/app/chats' && $method === 'GET') {
    $term = trim((string)($_GET['q'] ?? ''));
    $source = in_array($_GET['source'] ?? '', ['chatgpt', 'claude'], true) ? $_GET['source'] : '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 40;
    $hits = null; $convs = []; $more = false;
    if ($term !== '') {
        $srcSql = $source ? ' AND c.source = ?' : '';
        $args = fn(string $t) => array_merge([$user['id'], $t], $source ? [$source] : []);
        $sel = 'SELECT c.id, c.title, c.source, c.updated_at, c.message_count, m.body FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE m.user_id = ? AND ';
        $rows = q($sel . 'MATCH(m.body) AGAINST (? IN NATURAL LANGUAGE MODE)' . $srcSql . ' LIMIT 200', $args($term))->fetchAll();
        if (!$rows) {   // short words and stop-words are not in the full-text index
            $like = '%' . addcslashes($term, '%_\\') . '%';
            $rows = q($sel . 'm.body LIKE ?' . $srcSql . ' ORDER BY c.updated_at DESC LIMIT 200', $args($like))->fetchAll();
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
        $titleArgs = array_merge([$user['id'], '%' . addcslashes($term, '%_\\') . '%'], $source ? [$source] : []);
        foreach (q('SELECT c.id, c.title, c.source, c.updated_at, c.message_count FROM conversations c WHERE c.user_id = ? AND c.title LIKE ?' . $srcSql . ' LIMIT 50', $titleArgs) as $r) {
            if (!isset($hits[$r['id']])) $hits[$r['id']] = $r + ['snippet' => '', 'count' => 0];
        }
    } else {
        $sql = 'SELECT id, title, source, updated_at, message_count FROM conversations WHERE user_id = ?' . ($source ? ' AND source = ?' : '')
             . ' ORDER BY updated_at DESC, id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
        $convs = q($sql, array_merge([$user['id']], $source ? [$source] : []))->fetchAll();
        $more = count($convs) > $per;
        $convs = array_slice($convs, 0, $per);
    }
    render('chats', compact('user', 'term', 'source', 'page', 'hits', 'convs', 'more'));
}

if (preg_match('#^/app/chats/(\d+)$#', $path, $m) && $method === 'GET') {
    $conv = q('SELECT * FROM conversations WHERE id = ? AND user_id = ?', [$m[1], $user['id']])->fetch();
    if (!$conv) fail(404, 'That conversation is not in your archive.');
    $messages = q('SELECT role, body, sent_at FROM messages WHERE conversation_id = ? ORDER BY position', [$conv['id']])->fetchAll();
    render('chat', compact('user', 'conv', 'messages'));
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

// ---------- security (two-step sign-in) ----------
if ($path === '/app/security' && $method === 'GET') {
    $codes = $_SESSION['new_recovery_codes'] ?? null;
    unset($_SESSION['new_recovery_codes']);
    $setup = $_SESSION['totp_setup'] ?? null;
    render('security', [
        'user' => $user, 'available' => app_key() !== null, 'codes' => $codes, 'setup' => $setup,
        'left' => (int)q('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$user['id']])->fetchColumn(),
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
    if (!two_step_on($user)) redirect($back);
    if (!$okPass || !(two_step_check_code($user, $code) || two_step_use_recovery($user, $code))) {
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
