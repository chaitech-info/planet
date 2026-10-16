<?php defined('JARVIS_MODULE') || exit;
// "Qalan işlər" modulu: komandanın yapışqan siyahısını (roadmap cədvəli, roadmap.php) Jarvis-ə göstərir. İcra: jarvis-roadmap.js
$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$confirm = ['type' => 'boolean', 'description' => 'true only after the user has explicitly confirmed this exact action in this conversation'];
$lines = [];
try {
    $rows = $pdo->query('SELECT id, parent_id, title, owners, done FROM roadmap ORDER BY parent_id ASC, sort ASC, id ASC LIMIT 300')->fetchAll();
    $kids = [];
    foreach ($rows as $r) {
        $kids[(int)$r['parent_id']][] = $r;
    }
    $fmt = fn(array $r, string $pre) => $pre . '[' . ($r['done'] ? 'x' : ' ') . '] #' . $r['id'] . ' ' . $r['title'] . ($r['owners'] !== '' ? ' (' . $r['owners'] . ')' : '');
    foreach ($kids[0] ?? [] as $r) {
        $lines[] = $fmt($r, '- ');
        foreach ($kids[(int)$r['id']] ?? [] as $c) {
            $lines[] = $fmt($c, '    - ');
        }
    }
} catch (Throwable $e) {
    $lines = [];
}
$list = $lines ? implode("\n", $lines) : '(empty)';
return [
    'tools' => [
        ['name' => 'get_roadmap', 'description' => 'Read the team\'s "Qalan işlər" (remaining work) list again, with sub-items, owners and done state. Use it if the list may have changed during the conversation.',
            'parameters' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'add_roadmap_item', 'description' => 'Add an item to the "Qalan işlər" list (or a sub-item under a top-level item). Ask first, then call with confirmed=true.',
            'parameters' => ['type' => 'object', 'properties' => ['title' => $S('item text, max 300 chars'), 'owners' => $S('comma-separated team member names, optional'), 'parent_id' => ['type' => 'integer', 'description' => 'id of a top-level item to add under; omit for a new top-level item'], 'confirmed' => $confirm], 'required' => ['title', 'confirmed']]],
        ['name' => 'set_roadmap_done', 'description' => 'Mark a "Qalan işlər" item done or not done. Ask first, then call with confirmed=true.',
            'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'item id (the #number in the list)'], 'done' => ['type' => 'boolean', 'description' => 'true = done, false = reopen'], 'confirmed' => $confirm], 'required' => ['id', 'done', 'confirmed']]],
    ],
    'prompt' => <<<TXT
Remaining work: the team keeps a shared "Qalan işlər" list (the sticky list on every page). When asked what is left, what the team is working on, or about someone's items, answer from it (use show_panel for longer answers). Current list ([x] = done, #id, owners in brackets):
{$list}
TXT,
];
