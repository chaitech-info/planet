<?php defined('JARVIS_MODULE') || exit;
// Yaddaş modulu (v2): istifadəçi faktları, proyekt faktları ([project:x]) və son 7 günün sessiya xülasələri ([summary]).
// Saxlama: jarvis_memory (jarvis.php memory_add / memory_split). İcra: jarvis-memory.js
$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$m = memory_split($pdo, $me['name']);
$list = $m['facts'] ? implode("\n", array_map(fn($n) => '- ' . $n['text'], array_slice($m['facts'], 0, MEMORY_MAX))) : '(none yet)';
$pf = [];
foreach ($m['project'] as $p => $ns) {
    $pf[] = "- {$p}: " . implode(' · ', array_map(fn($n) => $n['text'], array_slice($ns, 0, 8)));
}
$sum = '';
foreach ($m['summary'] as $s) {              // yeni → köhnə, son 7 gün, cəmi ≤ 1200 simvol
    if ($s['created_at'] < time() - 7 * 86400 || mb_strlen($sum) + mb_strlen($s['text']) > 1200) {
        break;
    }
    $sum .= '- ' . $s['text'] . "\n";
}
$pfTxt = $pf ? "\nProject facts:\n" . implode("\n", array_slice($pf, 0, 12)) : '';
$sumTxt = $sum !== '' ? "\nRecent sessions (auto summaries, newest first; use for continuity, e.g. \"dünən neo-da başladığımız iş\"):\n" . rtrim($sum) : '';
return [
    'tools' => [
        ['name' => 'remember', 'tier' => 'g', 'group' => 'core', 'description' => 'Save one short durable note: about the user (preference, habit, nickname, ongoing goal, how they like you to talk) or, with "project", a fact about a project (stack, convention, deploy target, owner, decision). Saves silently, no confirmation needed. Never save passwords, keys or other secrets.',
            'parameters' => ['type' => 'object', 'properties' => ['note' => $S('one short factual sentence, third person, e.g. "Askar prefers answers in Azerbaijani and likes short jokes"'),
                'project' => $S('optional project name when the fact is about a project')], 'required' => ['note']]],
        ['name' => 'forget', 'tier' => 'r', 'group' => 'core', 'description' => 'Delete the user\'s saved memory notes that contain the given text. Ask first ("Unudum?"), then call with confirmed=true.',
            'parameters' => ['type' => 'object', 'properties' => ['query' => $S('word or phrase of the note(s) to forget'), 'confirmed' => ['type' => 'boolean', 'description' => 'true only after the user clearly agreed']], 'required' => ['query', 'confirmed']]],
    ],
    'prompt' => <<<TXT
Memory: you remember things about {$me['name']} and their projects between sessions. When they tell you something durable about themselves (preference, nickname, habit, goal, a person they work with) or about a project (stack, convention, decision), or ask you to remember it, call remember (with project for project facts), silently, and acknowledge in a few words ("Yadda saxladım"). Do not save small talk, one-off tasks or secrets. Use the notes naturally, without reciting them or saying "according to my memory". If they ask what you remember, read the notes out briefly. If they ask to forget something, confirm first, then call forget.
Saved notes about {$me['name']}:
{$list}{$pfTxt}{$sumTxt}
TXT,
];
