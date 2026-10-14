<?php defined('JARVIS_MODULE') || exit;
// HUD modulu: ekranda holoqrafik panellər (siyahı, cədvəl, rəqəm, qrafik, kod, diff, zaman xətti, yoxlama siyahısı, göstərici, insanlar, şəkil, keçid). İcra brauzerdədir: jarvis-hud.js (JarvisHUD).
// Panellər heç nəyi dəyişmir, ona görə confirmed lazım deyil.
$s = fn(string $d) => ['type' => 'string', 'description' => $d];
$item = ['type' => 'object', 'description' => 'one item; use only the fields that the block type needs (see block "type")', 'properties' => [
    'label' => $s('stat/progress/bar_chart/buttons/link: label'), 'value' => $s('stat: shown value (e.g. "12", "3.4k"); bar_chart: number'), 'delta' => $s('stat: optional change, e.g. "+3" or "-12%"'),
    'text' => $s('list/timeline/checklist/people: item text'), 'sub' => $s('list: optional second line'), 'status' => $s('list/progress/timeline: ok|done|fail|warn|running|queued; people: online|working|waiting|offline'),
    'time' => $s('timeline: optional time/date label'), 'done' => ['type' => 'boolean', 'description' => 'checklist: ticked'], 'name' => $s('people: person name'),
    'href' => $s('link: in-app hash route only, e.g. "#kainat/neo" (no external URLs)'),
    'pct' => ['type' => 'number', 'description' => 'progress: 0-100'], 'key' => $s('kv: key'), 'say' => $s('buttons: what the click means, sent back to you as a note'),
]];
$block = ['type' => 'object', 'properties' => [
    'type' => ['type' => 'string', 'enum' => ['text', 'stat', 'list', 'table', 'progress', 'bar_chart', 'sparkline', 'code', 'diff', 'kv', 'buttons', 'timeline', 'checklist', 'gauge', 'people', 'image', 'link']],
    'title' => $s('optional small caption above the block'),
    'text' => $s('text/code/diff: content (diff: unified diff lines starting with + or -)'), 'lang' => $s('code: optional language'),
    'value' => ['type' => 'number', 'description' => 'gauge: 0-100'], 'label' => $s('gauge: caption'), 'sub' => $s('gauge: optional second line'),
    'src' => $s('image: same-origin relative URL or data:image base64 only'), 'alt' => $s('image: description'),
    'items' => ['type' => 'array', 'items' => $item, 'description' => 'stat (max 8), list/timeline/checklist (max 20), progress (max 10), bar_chart (max 16), kv (max 16), people (max 12), link (max 6), buttons (max 4)'],
    'columns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'table: column headers (max 8)'],
    'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']], 'description' => 'table: rows of cell strings (max 30)'],
    'values' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'sparkline: numbers in time order (2-120)'],
], 'required' => ['type']];
$spec = [
    'title' => $s('short panel title'), 'icon' => $s('optional single emoji'),
    'accent' => ['type' => 'string', 'enum' => ['cyan', 'amber', 'red', 'green', 'violet'], 'description' => 'colour: cyan info (default), amber attention, red problems, green success, violet AI/agents'],
    'layout' => ['type' => 'string', 'enum' => ['normal', 'wide', 'compact'], 'description' => 'width: compact for a few numbers, wide for tables/code/diffs (default normal)'],
    'blocks' => ['type' => 'array', 'items' => $block, 'description' => 'content blocks, top to bottom (max 12)'],
];
return [
    'tools' => [
        ['name' => 'show_panel', 'description' => 'Show a holographic panel on the user\'s screen. Use it for anything that is better seen than heard: lists, comparisons, numbers, tables, progress, charts, code, diffs, results. Say one short sentence and put the details in the panel instead of reading them out. Block types: text {text}; stat {items:[{label,value,delta?}]}; list {items:[{text,sub?,status?}]}; table {columns,rows}; progress {items:[{label,pct,status?}]}; bar_chart {items:[{label,value}]}; sparkline {values}; code {lang?,text}; diff {text}; kv {items:[{key,value}]}; timeline {items:[{time?,text,status?}]}; checklist {items:[{text,done}]}; gauge {value 0-100,label,sub?}; people {items:[{name,status?,text?}]}; image {src} (same-origin path or data:image only); link {items:[{label,href:"#route"}]} (in-app navigation); buttons {items:[{label,say}]} (a click comes back to you as a note "[User clicked ...]"). Panels place themselves in free screen space. Returns the panel id.',
            'parameters' => ['type' => 'object', 'properties' => $spec, 'required' => ['title', 'blocks']]],
        ['name' => 'update_panel', 'description' => 'Change a panel shown earlier (by id from show_panel): only the given fields are replaced; blocks replace all blocks.',
            'parameters' => ['type' => 'object', 'properties' => ['id' => $s('panel id, e.g. "p3"')] + $spec, 'required' => ['id']]],
        ['name' => 'close_panels', 'description' => 'Close one panel by id, or all panels.',
            'parameters' => ['type' => 'object', 'properties' => ['id' => $s('panel id, or "all" (default)')]]],
    ],
    'prompt' => '- Screen: you have a holographic HUD. When an answer contains several items, numbers, a comparison, code or a diff, call show_panel and speak only a one-sentence summary ("Siyahını ekrana çıxardım"). Keep panels compact and pick the accent by meaning. Reuse a panel with update_panel instead of opening duplicates; close panels the user no longer needs. The user\'s running Claude Code tasks already appear automatically as live cards, do not duplicate them.',
];
