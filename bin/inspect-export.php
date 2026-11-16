<?php
// Describes the STRUCTURE of a chat export without printing any conversation text:
// which kinds of content blocks it has and which fields they carry. For checking the importer.
//   php bin/inspect-export.php /path/to/conversations-000.zip
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/lib.php';
require dirname(__DIR__) . '/src/importer.php';
$path = $argv[1] ?? '';
if (!is_file($path)) exit("usage: php bin/inspect-export.php /path/to/export.zip\n");
$convs = 0; $msgs = 0; $types = []; $tools = []; $place = 0; $topKeys = [];
foreach (export_streams($path, basename($path)) as $fh) {
    foreach (json_stream_items($fh) as $c) {
        $convs++;
        foreach (array_keys($c) as $k) $topKeys[$k] = true;
        foreach (($c['chat_messages'] ?? []) as $m) {
            $msgs++;
            if (is_string($m['text'] ?? null) && str_contains($m['text'], 'This block is not supported')) $place++;
            foreach (($m['content'] ?? []) as $b) {
                $t = $b['type'] ?? '?';
                $types[$t] = ($types[$t] ?? 0) + 1;
                if ($t === 'tool_use') {
                    $sig = ($b['name'] ?? '?') . ' {' . implode(', ', array_keys(is_array($b['input'] ?? null) ? $b['input'] : [])) . '}';
                    $tools[$sig] = ($tools[$sig] ?? 0) + 1;
                } elseif (!in_array($t, ['text'], true)) {
                    $sig = $t . ' {' . implode(', ', array_keys($b)) . '}';
                    $tools[$sig] = ($tools[$sig] ?? 0) + 1;
                }
            }
        }
    }
}
arsort($types); arsort($tools);
echo "conversations: $convs\nmessages: $msgs\nmessages whose text has the 'not supported' placeholder: $place\n";
echo "conversation fields: " . implode(', ', array_keys($topKeys)) . "\n\ncontent block types:\n";
foreach ($types as $t => $n) echo "  $n  $t\n";
echo "\nblock shapes (name and field names only, no content):\n";
foreach (array_slice($tools, 0, 40, true) as $s => $n) echo "  $n  $s\n";
