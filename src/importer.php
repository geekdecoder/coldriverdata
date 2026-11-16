<?php
declare(strict_types=1);

// Reads ChatGPT and Claude data exports (.zip or conversations.json) and files
// each conversation into the conversations/messages tables.

/**
 * Yield each top-level object of a JSON array from a stream without loading the
 * whole file, so a multi-gigabyte conversations.json stays within memory limits.
 */
function json_stream_items($fh): Generator
{
    $depth = 0; $inStr = false; $esc = false; $capturing = false; $buf = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 1 << 20);
        if ($chunk === false || $chunk === '') continue;
        $n = strlen($chunk); $i = 0; $start = $capturing ? 0 : -1;
        while ($i < $n) {
            if ($inStr) {
                if ($esc) { $esc = false; $i++; continue; }
                $i += strcspn($chunk, "\"\\", $i);
                if ($i >= $n) break;
                if ($chunk[$i] === '\\') { $esc = true; $i++; continue; }
                $inStr = false; $i++; continue;
            }
            $i += strcspn($chunk, '{}[]"', $i);
            if ($i >= $n) break;
            $c = $chunk[$i];
            if ($c === '"') { $inStr = true; $i++; continue; }
            if ($c === '{' || $c === '[') {
                if ($depth === 1 && $c === '{') { $capturing = true; $start = $i; }
                $depth++; $i++; continue;
            }
            $depth--; $i++;
            if ($depth === 1 && $capturing) {
                $buf .= substr($chunk, $start, $i - $start);
                $item = json_decode($buf, true, 2048);
                $buf = ''; $capturing = false; $start = -1;
                if (is_array($item)) yield $item;
            }
        }
        if ($capturing) $buf .= substr($chunk, max(0, $start));
    }
}

function ts_to_utc($v): ?string
{
    if ($v === null || $v === '') return null;
    $t = is_numeric($v) ? (int)$v : strtotime((string)$v);
    return $t ? gmdate('Y-m-d H:i:s', $t) : null;
}

/** Normalise one exported conversation, or null if the shape is not recognised. */
function parse_conversation(array $c): ?array
{
    if (isset($c['mapping']) && is_array($c['mapping'])) return parse_chatgpt($c);
    if (isset($c['chat_messages']) && is_array($c['chat_messages'])) return parse_claude($c);
    return null;
}

function parse_chatgpt(array $c): ?array
{
    $map = $c['mapping'];
    $id = $c['conversation_id'] ?? $c['id'] ?? null;
    if (!$id) return null;

    // Follow the branch the user last saw: current_node back up to the root.
    $nodes = [];
    $node = $c['current_node'] ?? null;
    if ($node && isset($map[$node])) {
        $guard = 0;
        while ($node && isset($map[$node]) && $guard++ < 200000) {
            $nodes[] = $map[$node];
            $node = $map[$node]['parent'] ?? null;
        }
        $nodes = array_reverse($nodes);
    } else {
        $nodes = array_values($map);
    }

    $msgs = [];
    foreach ($nodes as $n) {
        $m = $n['message'] ?? null;
        if (!is_array($m)) continue;
        $role = $m['author']['role'] ?? '';
        if ($role !== 'user' && $role !== 'assistant') continue;
        if (!empty($m['metadata']['is_visually_hidden_from_conversation'])) continue;
        $type = $m['content']['content_type'] ?? '';
        if ($type !== 'text' && $type !== 'multimodal_text') continue;
        $parts = [];
        foreach (($m['content']['parts'] ?? []) as $part) {
            if (is_string($part)) $parts[] = $part;
            elseif (is_array($part)) $parts[] = isset($part['text']) && is_string($part['text']) ? $part['text'] : '[attachment]';
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') continue;
        $msgs[] = ['role' => $role, 'body' => $text, 'sent_at' => ts_to_utc($m['create_time'] ?? null)];
    }
    if (!$msgs) return null;
    return [
        'source' => 'chatgpt', 'ext_id' => (string)$id,
        'title' => trim((string)($c['title'] ?? '')) ?: 'Untitled conversation',
        'started_at' => ts_to_utc($c['create_time'] ?? null),
        'updated_at' => ts_to_utc($c['update_time'] ?? ($c['create_time'] ?? null)),
        'messages' => $msgs,
    ];
}

/** Remove the "not supported on your current device" stand-ins Claude's export leaves in message text. */
function strip_claude_placeholders(string $text): string
{
    $text = preg_replace('/(^|\n)[ \t]*(`{3,}|~{3,})[^\n]*\n[ \t]*This block is not supported on your current device yet\.?[ \t]*\n[ \t]*\2[ \t]*(?=\n|$)/', '$1', $text) ?? $text;
    $text = preg_replace('/^[ \t]*This block is not supported on your current device yet\.?[ \t]*$/m', '', $text) ?? $text;
    return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
}

/** A code fence long enough to hold $content even when it contains fences itself. */
function fenced(string $content, string $lang = ''): string
{
    preg_match_all('/`{3,}/', $content, $m);
    $fence = str_repeat('`', max(3, ...array_map('strlen', $m[0] ?: ['``'])) + ($m[0] ? 1 : 0));
    return $fence . preg_replace('/[^A-Za-z0-9+#._-]/', '', $lang) . "\n" . rtrim($content, "\n") . "\n" . $fence;
}

/** Readable text for one Claude message from its content blocks: prose, plus artifacts as titled code blocks. */
function claude_blocks_text(array $blocks): string
{
    $parts = [];
    foreach ($blocks as $b) {
        if (!is_array($b)) continue;
        $type = $b['type'] ?? '';
        if ($type === 'text' && is_string($b['text'] ?? null)) {
            $parts[] = $b['text'];
        } elseif ($type === 'tool_use' && is_array($b['input'] ?? null)) {
            $in = $b['input'];
            // Artifacts and created files carry their whole content; other tool steps (searches and so on) are skipped.
            $content = null;
            foreach (['content', 'file_text', 'code', 'new_str'] as $k) {
                if (is_string($in[$k] ?? null) && trim($in[$k]) !== '') { $content = $in[$k]; break; }
            }
            if ($content === null) continue;
            $title = '';
            foreach (['title', 'path', 'file_path', 'filename', 'id'] as $k) {
                if (is_string($in[$k] ?? null) && trim($in[$k]) !== '') { $title = trim($in[$k]); break; }
            }
            $lang = is_string($in['language'] ?? null) ? $in['language'] : '';
            if ($lang === '' && is_string($in['type'] ?? null)) {
                $lang = ['text/html' => 'html', 'image/svg+xml' => 'xml', 'application/vnd.ant.react' => 'jsx', 'application/vnd.ant.mermaid' => 'mermaid', 'text/markdown' => 'markdown'][$in['type']] ?? '';
            }
            if ($lang === '' && $title !== '' && preg_match('/\.([A-Za-z0-9]{1,6})$/', $title, $e)) $lang = strtolower($e[1]);
            $parts[] = ($title !== '' ? '**' . str_replace('*', '', mb_substr($title, 0, 200)) . "**\n\n" : '') . fenced($content, $lang);
        }
    }
    return implode("\n\n", $parts);
}

function parse_claude(array $c): ?array
{
    $id = $c['uuid'] ?? null;
    if (!$id) return null;
    $msgs = [];
    foreach ($c['chat_messages'] as $m) {
        if (!is_array($m)) continue;
        $sender = $m['sender'] ?? '';
        $role = $sender === 'human' ? 'user' : ($sender === 'assistant' ? 'assistant' : null);
        if (!$role) continue;
        // Claude's flat "text" field replaces everything that is not plain prose (artifacts, tool
        // steps) with a placeholder, so build the message from its content blocks when it has them.
        $text = is_array($m['content'] ?? null) ? claude_blocks_text($m['content']) : '';
        if (trim($text) === '') $text = is_string($m['text'] ?? null) ? $m['text'] : '';
        $text = strip_claude_placeholders($text);
        $text = trim($text);
        if ($text === '') continue;
        $msgs[] = ['role' => $role, 'body' => $text, 'sent_at' => ts_to_utc($m['created_at'] ?? null)];
    }
    if (!$msgs) return null;
    return [
        'source' => 'claude', 'ext_id' => (string)$id,
        'title' => trim((string)($c['name'] ?? '')) ?: 'Untitled conversation',
        'started_at' => ts_to_utc($c['created_at'] ?? null),
        'updated_at' => ts_to_utc($c['updated_at'] ?? ($c['created_at'] ?? null)),
        'messages' => $msgs,
    ];
}

function save_conversation(int $userId, int $fileId, array $conv): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // A newer export replaces the older copy of the same conversation.
        // Keep the folder and star the person gave the older copy.
        $old = q('SELECT folder_id, starred FROM conversations WHERE user_id = ? AND source = ? AND ext_id = ?', [$userId, $conv['source'], $conv['ext_id']])->fetch();
        q('DELETE FROM conversations WHERE user_id = ? AND source = ? AND ext_id = ?', [$userId, $conv['source'], $conv['ext_id']]);
        q('INSERT INTO conversations (user_id, file_id, source, ext_id, title, started_at, updated_at, message_count, folder_id, starred) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$userId, $fileId, $conv['source'], mb_substr($conv['ext_id'], 0, 100), mb_substr($conv['title'], 0, 500),
             $conv['started_at'], $conv['updated_at'], count($conv['messages']), $old['folder_id'] ?? null, (int)($old['starred'] ?? 0)]);
        $cid = (int)$pdo->lastInsertId();
        $st = $pdo->prepare('INSERT INTO messages (conversation_id, user_id, position, role, body, sent_at) VALUES (?,?,?,?,?,?)');
        foreach ($conv['messages'] as $pos => $m) {
            $st->execute([$cid, $userId, $pos, $m['role'], $m['body'], $m['sent_at']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Open every conversations JSON inside an upload. Returns a list of read streams. */
function export_streams(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'json') {
        $fh = fopen($path, 'rb');
        return $fh ? [$fh] : [];
    }
    if ($ext !== 'zip') return [];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];
    $streams = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry !== false && preg_match('/^conversations(-\d+)?\.json$/i', basename($entry))) {
            $fh = $zip->getStream($entry);
            if ($fh) $streams[] = $fh;
        }
    }
    $GLOBALS['__crd_open_zips'][] = $zip;   // keep the archive open while its streams are read
    return $streams;
}

/** Run one import job. Sets its final status; never throws. */
function run_import(array $job): void
{
    q("UPDATE imports SET status = 'running' WHERE id = ?", [$job['id']]);
    $file = q('SELECT * FROM files WHERE id = ?', [$job['file_id']])->fetch();
    try {
        if (!$file) throw new RuntimeException('The uploaded file is gone.');
        $path = file_local_path($file);
        $fetched = null;
        if (!$path) {      // in object storage: bring a temporary copy back to read it
            $b = backend((int)$file['backend_id']);
            if (!$b || $b['type'] !== 's3') throw new RuntimeException('The file cannot be found in storage.');
            $path = $fetched = storage_tmp((int)$file['user_id'], 'imp_' . $file['id']);
            $out = fopen($path, 'wb');
            s3_get(file_conn($b, $file), object_key($file), function ($d) use ($out) { fwrite($out, $d); });
            fclose($out);
        }
        if (!is_file($path)) throw new RuntimeException('The file is not on this server, so it cannot be read.');
        $convs = 0; $msgs = 0;
        foreach (export_streams($path, $file['name']) as $fh) {
            foreach (json_stream_items($fh) as $item) {
                $conv = parse_conversation($item);
                if (!$conv) continue;
                save_conversation((int)$file['user_id'], (int)$file['id'], $conv);
                $convs++; $msgs += count($conv['messages']);
            }
            fclose($fh);
        }
        q('UPDATE imports SET status = ?, conversations = ?, messages = ?, finished_at = ? WHERE id = ?',
            [$convs ? 'done' : 'skipped', $convs, $msgs, now(), $job['id']]);
    } catch (Throwable $e) {
        q("UPDATE imports SET status = 'failed', error = ?, finished_at = ? WHERE id = ?",
            [mb_substr($e->getMessage(), 0, 500), now(), $job['id']]);
    }
    if (!empty($fetched)) { unset($GLOBALS['__crd_open_zips']); @unlink($fetched); }
}

/**
 * Claude's export arrives as a small manifest JSON of one-time download links
 * rather than the data itself. Returns true when $path is such a manifest.
 */
function is_claude_manifest(string $path, int $size): bool
{
    if ($size > 1048576) return false;
    $d = json_decode((string)file_get_contents($path), true);
    if (!is_array($d) || !isset($d['data_files']) || !is_array($d['data_files']) || !$d['data_files']) return false;
    foreach ($d['data_files'] as $f) {
        if (!is_array($f) || !isset($f['export_url'], $f['filename'])) return false;
    }
    return true;
}
