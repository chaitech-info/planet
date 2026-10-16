<?php defined('JARVIS_MODULE') || exit;
// İcazə relesi + canlı Claude Code sessiyaları: alət deklarasiyaları. İcra: jarvis-approvals.js → approval.php, data.sessions.
$s = fn(string $d) => ['type' => 'string', 'description' => $d];
return [
    'groups' => ['claude' => 'Claude Code on team computers: permission requests (approve/deny), live sessions'],
    'tools' => [
        ['name' => 'list_approvals', 'tier' => 'g', 'group' => 'claude',
            'description' => 'Pending Claude Code permission requests (a Claude Code session on a team computer waits for allow/deny). Mine; an admin sees everyone\'s. Each: id, user, project, tool, summary, seconds left.',
            'parameters' => ['type' => 'object', 'properties' => (object)[]]],
        ['name' => 'decide_approval', 'tier' => 'y', 'group' => 'claude',
            'description' => 'Allow or deny a pending Claude Code permission request. The waiting Claude Code session continues immediately (deny: Claude gets the reason).',
            'parameters' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'description' => 'approval id; omit when exactly one request is pending'],
                'decision' => ['type' => 'string', 'enum' => ['allow', 'deny']],
                'reason' => $s('optional short reason (sent to Claude on deny)'),
            ], 'required' => ['decision']]],
        ['name' => 'claude_sessions', 'tier' => 'g', 'group' => 'claude',
            'description' => 'Live Claude Code sessions of the team: who, project, status (working | waiting = needs the user\'s input | permission = waits for a permission decision | idle), current prompt, last action, duration.',
            'parameters' => ['type' => 'object', 'properties' => ['user' => $s('optional person filter'), 'project' => $s('optional project filter'),
                'all' => ['type' => 'boolean', 'description' => 'include sessions quiet for up to 24 h (default: only sessions active in the last 15 min)']]]],
    ],
    'prompt' => <<<TXT
Claude Code permission relay:
- A system note "Approval request #id" means a Claude Code session waits for permission (about 50 s, then the normal terminal dialog appears). Say in ONE short sentence who/which project and what ("Emil, planet-də Bash: npm test istəyir. İcazə verim?") and wait.
- "hə", "bəli", "icazə ver", "ok", "allow" → decide_approval decision=allow. "yox", "rədd et", "qadağan" → deny (pass the user's reason if given). Several pending and unclear which → ask, or use list_approvals. If decide_approval is not available, call load_tools with group "claude" first.
- Never approve on your own initiative; only on the user's answer. Destructive commands (rm -rf, force push, DROP, deploy): mention the risk in a few words.
- "Kim nə edir", "Claude sessiyaları" → claude_sessions; summarise in 1-2 sentences, details in a panel if many.
TXT,
];
