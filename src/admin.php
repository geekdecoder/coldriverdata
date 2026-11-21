<?php
declare(strict_types=1);

// Admin area. Included from public/index.php for any path under /admin.
// Admins manage accounts and storage; they see file names and sizes, never conversation text.

$admin = require_admin();

/** Storage locations with their usage, built-in folder first. */
function admin_storage_rows(): array
{
    $usage = [];
    foreach (q('SELECT backend_id, COUNT(*) AS n, COALESCE(SUM(size), 0) AS bytes FROM files GROUP BY backend_id') as $r) {
        $usage[$r['backend_id'] === null ? 0 : (int)$r['backend_id']] = $r;
    }
    $rows = q('SELECT * FROM storage_backends ORDER BY id')->fetchAll();
    $hasDefault = false;
    foreach ($rows as &$r) {
        $r['files'] = (int)($usage[(int)$r['id']]['n'] ?? 0);
        $r['bytes'] = (int)($usage[(int)$r['id']]['bytes'] ?? 0);
        if ($r['is_default'] && $r['status'] === 'active') $hasDefault = true;
    }
    unset($r);
    $dir = (string)cfg('storage_dir');
    $free = @disk_free_space($dir);
    array_unshift($rows, [
        'id' => 0, 'name' => 'Built-in folder', 'type' => 'local', 'path' => $dir, 'status' => 'active',
        'is_default' => $hasDefault ? 0 : 1, 'capacity_bytes' => null, 'disk_free' => $free === false ? null : (int)$free,
        'files' => (int)($usage[0]['n'] ?? 0), 'bytes' => (int)($usage[0]['bytes'] ?? 0),
    ]);
    return $rows;
}

// ---------- overview ----------
if ($path === '/admin' && $method === 'GET') {
    $nowUtc = now();
    $stats = [
        'users' => (int)q('SELECT COUNT(*) FROM users')->fetchColumn(),
        'paying' => (int)q('SELECT COUNT(*) FROM users WHERE plan IS NOT NULL AND paid_through > ?', [$nowUtc])->fetchColumn(),
        'suspended' => (int)q('SELECT COUNT(*) FROM users WHERE suspended_at IS NOT NULL')->fetchColumn(),
        'unverified' => (int)q('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL')->fetchColumn(),
        'new7' => (int)q('SELECT COUNT(*) FROM users WHERE created_at > ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)])->fetchColumn(),
        'bytes' => (int)q('SELECT COALESCE(SUM(size), 0) FROM files')->fetchColumn(),
        'files' => (int)q('SELECT COUNT(*) FROM files')->fetchColumn(),
        'convs' => (int)q('SELECT COUNT(*) FROM conversations')->fetchColumn(),
    ];
    $byPlan = q('SELECT plan, COUNT(*) AS n FROM users WHERE plan IS NOT NULL AND paid_through > ? GROUP BY plan', [$nowUtc])->fetchAll();
    $imports = q("SELECT status, COUNT(*) AS n FROM imports WHERE status IN ('queued','running','failed') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $shipping = q('SELECT COUNT(*) AS waiting, SUM(ship_error IS NOT NULL) AS failing FROM files WHERE ship_to IS NOT NULL')->fetch();
    $storage = admin_storage_rows();
    render('admin_overview', compact('stats', 'byPlan', 'imports', 'shipping', 'storage'));
}

// ---------- users ----------
if ($path === '/admin/users' && $method === 'GET') {
    $term = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $where = $term !== '' ? 'WHERE u.email LIKE ?' : '';
    $args = $term !== '' ? ['%' . addcslashes($term, '%_\\') . '%'] : [];
    $users = q("SELECT u.id, u.email, u.plan, u.pay_method, u.plan_status, u.paid_through, u.suspended_at, u.is_admin, u.created_at, u.email_verified_at,
                       (SELECT COALESCE(SUM(size), 0) FROM files f WHERE f.user_id = u.id) AS bytes
                FROM users u $where ORDER BY u.id DESC LIMIT " . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), $args)->fetchAll();
    $more = count($users) > $per;
    $users = array_slice($users, 0, $per);
    render('admin_users', compact('users', 'term', 'page', 'more'));
}

if (preg_match('#^/admin/users/(\d+)(?:/(plan|suspend|unsuspend|reset|delete|verify|twostep))?$#', $path, $m)) {
    $target = q('SELECT * FROM users WHERE id = ?', [$m[1]])->fetch();
    if (!$target) fail(404, 'No such account.');
    $action = $m[2] ?? null;
    $back = '/admin/users/' . $target['id'];

    if ($action === null && $method === 'GET') {
        $files = q('SELECT f.id, f.name, f.size, f.created_at, f.backend_id, f.ship_to, f.ship_error, i.status AS import_status, i.conversations AS import_convs, i.error AS import_error
                    FROM files f LEFT JOIN imports i ON i.file_id = f.id WHERE f.user_id = ? ORDER BY f.id DESC LIMIT 200', [$target['id']])->fetchAll();
        $bytes = used_bytes((int)$target['id']);
        $convs = (int)q('SELECT COUNT(*) FROM conversations WHERE user_id = ?', [$target['id']])->fetchColumn();
        $names = [0 => 'Built-in folder'] + q('SELECT id, name FROM storage_backends')->fetchAll(PDO::FETCH_KEY_PAIR);
        $target['key_count'] = count(user_keys((int)$target['id']));
        render('admin_user', compact('target', 'files', 'bytes', 'convs', 'names', 'admin'));
    }
    if ($method !== 'POST') fail(404, 'There is no page at this address.');
    $isSelf = (int)$target['id'] === (int)$admin['id'];

    if ($action === 'plan') {
        $plan = (string)($_POST['plan'] ?? '');
        $date = (string)($_POST['paid_through'] ?? '');
        if ($plan === '') {
            q('UPDATE users SET plan = NULL, plan_status = NULL, paid_through = NULL, pay_method = NULL WHERE id = ?', [$target['id']]);
            admin_log($admin, 'plan removed', $target['email']);
            flash('Plan removed.');
        } elseif (!isset(plans()[$plan]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            flash('Choose a plan and a valid end date.', 'err');
        } else {
            // Keep the payment method when Stripe is still billing this account, so renewals keep working.
            $billed = $target['pay_method'] === 'card' && in_array($target['plan_status'], ['active', 'past_due'], true);
            q('UPDATE users SET plan = ?, paid_through = ?, plan_status = ?, pay_method = ? WHERE id = ?',
                [$plan, $date . ' 23:59:59', 'active', $billed ? 'card' : 'manual', $target['id']]);
            admin_log($admin, 'plan set', $target['email'], "$plan through $date");
            flash('Plan set to ' . plans()[$plan]['name'] . " through $date." . ($billed ? ' This account also has a Stripe subscription, which will overwrite this at its next renewal.' : ''));
        }
        redirect($back);
    }
    if ($action === 'suspend' || $action === 'unsuspend') {
        if ($isSelf) { flash('You cannot suspend your own account.', 'err'); redirect($back); }
        q('UPDATE users SET suspended_at = ? WHERE id = ?', [$action === 'suspend' ? now() : null, $target['id']]);
        admin_log($admin, $action === 'suspend' ? 'suspended' : 'reactivated', $target['email']);
        flash($action === 'suspend' ? 'Account suspended. They are signed out and cannot sign in. Billing is not changed.' : 'Account reactivated.');
        redirect($back);
    }
    if ($action === 'verify') {
        q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [now(), $target['id']]);
        q('DELETE FROM email_verifications WHERE user_id = ?', [$target['id']]);
        admin_log($admin, 'email marked verified', $target['email']);
        flash('Email marked as confirmed.');
        redirect($back);
    }
    if ($action === 'twostep') {
        if ($isSelf) { flash('Turn off your own two-step sign-in from the Security page.', 'err'); redirect($back); }
        second_factor_reset((int)$target['id']);
        admin_log($admin, 'two-step sign-in turned off', $target['email']);
        flash('Two-step sign-in is off for this account. They can sign in with their password and set it up again.');
        redirect($back);
    }
    if ($action === 'reset') {
        send_password_reset($target);
        admin_log($admin, 'password reset sent', $target['email']);
        flash('Password reset email sent to ' . $target['email'] . '.');
        redirect($back);
    }
    if ($action === 'delete') {
        if ($isSelf) { flash('You cannot delete your own account here.', 'err'); redirect($back); }
        if (strtolower(trim((string)($_POST['confirm'] ?? ''))) !== strtolower($target['email'])) {
            flash('Type the account email exactly to confirm deletion.', 'err');
            redirect($back);
        }
        $problems = delete_account($target);
        if ($problems) {
            admin_log($admin, 'delete incomplete', $target['email'], implode(' | ', $problems));
            flash('Not fully deleted: ' . implode(' ', $problems), 'err');
            redirect($back);
        }
        admin_log($admin, 'account deleted', $target['email']);
        flash('Deleted ' . $target['email'] . ' and everything it stored.');
        redirect('/admin/users');
    }
}

// ---------- storage ----------
if ($path === '/admin/storage' && $method === 'GET') {
    render('admin_storage', ['storage' => admin_storage_rows(), 'hasKey' => app_key() !== null, 'form' => $_SESSION['storage_form'] ?? []]);
}

if ($path === '/admin/storage/add' && $method === 'POST') {
    $f = array_map(fn($v) => trim((string)$v), $_POST);
    $_SESSION['storage_form'] = array_diff_key($f, ['secret_key' => 1, 'csrf' => 1]);
    $cap = ($f['capacity_gb'] ?? '') !== '' ? (int)round((float)$f['capacity_gb'] * 1024 ** 3) : null;
    try {
        if (($f['name'] ?? '') === '') throw new RuntimeException('Give the location a name.');
        if (($f['type'] ?? '') === 'local') {
            $dir = rtrim($f['path'] ?? '', '/');
            if ($dir === '' || $dir[0] !== '/') throw new RuntimeException('Enter the full path of the folder, starting with /.');
            if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException("The site cannot write to $dir. Create it and make it owned by the site user.");
            $probe = $dir . '/.crd_probe_' . bin2hex(random_bytes(4));
            if (@file_put_contents($probe, 'x') === false) throw new RuntimeException("The site cannot write to $dir.");
            unlink($probe);
            q('INSERT INTO storage_backends (name, type, path, capacity_bytes, created_at) VALUES (?,?,?,?,?)', [$f['name'], 'local', $dir, $cap, now()]);
        } elseif (($f['type'] ?? '') === 's3') {
            foreach (['endpoint' => 'endpoint', 'region' => 'region', 'bucket' => 'bucket name', 'access_key' => 'access key', 'secret_key' => 'secret key'] as $k => $label) {
                if (($f[$k] ?? '') === '') throw new RuntimeException("Enter the $label.");
            }
            $conn = ['endpoint' => $f['endpoint'], 'region' => $f['region'], 'bucket' => $f['bucket'], 'access_key' => $f['access_key'],
                     'secret_key' => $f['secret_key'], 'path_style' => !empty($f['path_style'])];
            $enc = secret_encrypt($f['secret_key']);     // fails early if app_key is missing
            s3_self_test($conn);                         // write, read back, delete
            $encrypt = !empty($f['encrypt']);
            if ($encrypt) s3_encryption_self_test($conn);   // proves the store encrypts with our key
            q('INSERT INTO storage_backends (name, type, endpoint, region, bucket, access_key, secret_enc, path_style, capacity_bytes, encrypt, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$f['name'], 's3', $f['endpoint'], $f['region'], $f['bucket'], $f['access_key'], $enc, !empty($f['path_style']) ? 1 : 0, $cap, $encrypt ? 1 : 0, now()]);
        } else {
            throw new RuntimeException('Choose a storage type.');
        }
        unset($_SESSION['storage_form']);
        admin_log($admin, 'storage added', $f['name'], $f['type'] === 's3' ? $f['bucket'] . ' at ' . $f['endpoint'] : $f['path']);
        flash('Added "' . $f['name'] . '" and confirmed the site can write to it. It is not used for new uploads until you make it the default.');
    } catch (Throwable $e) {
        flash('Not added: ' . $e->getMessage(), 'err');
    }
    redirect('/admin/storage');
}

if (preg_match('#^/admin/storage/(\d+)/(default|status|test|remove|encrypt|backup)$#', $path, $m) && $method === 'POST') {
    $id = (int)$m[1];
    $b = $id ? backend($id) : null;
    if ($id && !$b) fail(404, 'No such storage location.');
    $label = $b['name'] ?? 'Built-in folder';
    try {
        if ($m[2] === 'default') {
            if ($b && $b['status'] !== 'active') throw new RuntimeException('Set it to active first.');
            q('UPDATE storage_backends SET is_default = 0');
            if ($id) q('UPDATE storage_backends SET is_default = 1 WHERE id = ?', [$id]);
            admin_log($admin, 'storage default', $label);
            flash("New uploads now go to \"$label\". Files already stored stay where they are.");
        } elseif ($m[2] === 'status' && $b) {
            $to = $b['status'] === 'active' ? 'readonly' : 'active';
            q('UPDATE storage_backends SET status = ?, is_default = IF(? = \'readonly\', 0, is_default) WHERE id = ?', [$to, $to, $id]);
            admin_log($admin, 'storage ' . $to, $label);
            flash($to === 'readonly' ? "\"$label\" takes no new uploads. Its existing files still download." : "\"$label\" is active again.");
        } elseif ($m[2] === 'test' && $b) {
            if ($b['type'] === 's3') s3_self_test(backend_s3($b));
            elseif (!is_dir((string)$b['path']) || !is_writable((string)$b['path'])) throw new RuntimeException('The folder is missing or not writable.');
            flash("\"$label\" is reachable and writable.");
        } elseif ($m[2] === 'encrypt' && $b && $b['type'] === 's3') {
            if (!empty($b['encrypt'])) throw new RuntimeException('Encryption is already on for this location.');
            s3_encryption_self_test(backend_s3($b));
            q('UPDATE storage_backends SET encrypt = 1 WHERE id = ?', [$id]);
            admin_log($admin, 'storage encryption on', $label);
            flash("Encryption is on for \"$label\": the test confirmed files cannot be read without our key. New files are encrypted from now on. Run bin/protect-files.php on the server to encrypt the files already stored.");
        } elseif ($m[2] === 'backup' && $b && $b['type'] === 's3') {
            $on = empty($b['is_backup']);
            q('UPDATE storage_backends SET is_backup = 0');
            if ($on) q('UPDATE storage_backends SET is_backup = 1 WHERE id = ?', [$id]);
            admin_log($admin, $on ? 'backup location set' : 'backup location cleared', $label);
            flash($on ? "Database backups will be stored in \"$label\"." : 'No location is set for backups now.');
        } elseif ($m[2] === 'remove' && $b) {
            $n = (int)q('SELECT COUNT(*) FROM files WHERE backend_id = ? OR ship_to = ?', [$id, $id])->fetchColumn();
            if ($n) throw new RuntimeException("$n files still live there. A location can only be removed when it is empty.");
            q('DELETE FROM storage_backends WHERE id = ?', [$id]);
            admin_log($admin, 'storage removed', $label);
            flash("Removed \"$label\" from the list. Nothing was deleted from the storage itself.");
        } else {
            fail(404, 'There is no page at this address.');
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('/admin/storage');
}

// ---------- protection status ----------
if ($path === '/admin/protection' && $method === 'GET') {
    render('admin_protection', ['st' => protection_status(), 'recent' => q('SELECT b.created_at, b.size, b.object_key, s.name FROM backups b LEFT JOIN storage_backends s ON s.id = b.backend_id ORDER BY b.id DESC LIMIT 10')->fetchAll()]);
}

// ---------- activity log ----------
if ($path === '/admin/log' && $method === 'GET') {
    render('admin_log', ['entries' => q('SELECT * FROM admin_log ORDER BY id DESC LIMIT 200')->fetchAll()]);
}

fail(404, 'There is no page at this address.');
