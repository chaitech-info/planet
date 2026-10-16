<?php defined('JARVIS_MODULE') || exit;
// Yaddaş modulu: Jarvis istifadəçi haqqında qısa qeydləri sessiyalar arası saxlayır (jarvis_memory cədvəli, jarvis.php). İcra: jarvis-memory.js
$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$notes = memory_all($pdo, $me['name']);
$list = $notes ? implode("\n", array_map(fn($n) => '- ' . $n['note'], $notes)) : '(none yet)';
return [
    'tools' => [
        ['name' => 'remember', 'description' => 'Save one short durable note about the user (preference, habit, nickname, ongoing goal, how they like you to talk). Saves silently, no confirmation needed. Never save passwords, keys or other secrets.',
            'parameters' => ['type' => 'object', 'properties' => ['note' => $S('one short factual sentence, third person, e.g. "Askar prefers answers in Azerbaijani and likes short jokes"')], 'required' => ['note']]],
        ['name' => 'forget', 'description' => 'Delete the user\'s saved memory notes that contain the given text. Ask first ("Unudum?"), then call with confirmed=true.',
            'parameters' => ['type' => 'object', 'properties' => ['query' => $S('word or phrase of the note(s) to forget'), 'confirmed' => ['type' => 'boolean', 'description' => 'true only after the user clearly agreed']], 'required' => ['query', 'confirmed']]],
    ],
    'prompt' => <<<TXT
Memory: you remember things about {$me['name']} between sessions. When they tell you something durable about themselves (preference, nickname, habit, goal, a person they work with) or ask you to remember it, call remember with one short note, silently, and acknowledge naturally in a few words ("Yadda saxladım"). Do not save small talk, one-off tasks or secrets. Use the notes naturally, without reciting them or saying "according to my memory". If they ask what you remember, read the notes out briefly. If they ask to forget something, confirm first, then call forget.
Saved notes about {$me['name']}:
{$list}
TXT,
];
