<?php defined('JARVIS_MODULE') || exit;
// Missiyalar, protokollar, paralel göndəriş: alət deklarasiyaları. İcra: jarvis-missions.js → mission.php.
require_once dirname(__DIR__) . '/mission_lib.php';

$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$confirm = ['type' => 'boolean', 'description' => 'true only after the user has explicitly confirmed this exact action in this conversation'];
$mid = ['type' => 'integer', 'description' => 'mission id; omit for the latest relevant mission'];
$step = ['type' => 'object', 'properties' => [
    'title' => $S('short step title'),
    'project' => $S('project name (must be a code folder of the user\'s agent)'),
    'prompt' => $S('complete, self-contained instruction for Claude Code for this step (max 4000 chars)'),
    'after' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '0-based indexes of earlier steps that must finish successfully first; omit to start immediately'],
], 'required' => ['project', 'prompt']];

try {
    $protoNames = array_column(protocol_list($pdo, $me['name']), 'name');
} catch (Throwable $e) {
    $protoNames = [];
}
$protoLine = $protoNames ? 'Known protocols: ' . implode(', ', array_slice($protoNames, 0, 15)) . '.' : '';

return [
    'tools' => [
        ['name' => 'dispatch_agents', 'tier' => 'y', 'group' => 'claude', 'description' => 'Send several independent Claude Code tasks at once (e.g. the same check in several projects, or different jobs). Each item is validated separately; the result reports success per item and how concurrency works on the user\'s computer.',
            'parameters' => ['type' => 'object', 'properties' => [
                'tasks' => ['type' => 'array', 'description' => '1-12 tasks', 'items' => ['type' => 'object', 'properties' => ['project' => $S('project name'), 'prompt' => $S('complete instruction for Claude Code'), 'title' => $S('optional short title')], 'required' => ['project', 'prompt']]],
                'confirmed' => $confirm], 'required' => ['tasks', 'confirmed']]],
        ['name' => 'plan_mission', 'tier' => 'g', 'group' => 'missions', 'description' => 'Plan a multi-step mission for Claude Code (a bigger goal split into steps, possibly across projects, with dependencies). Creates only a DRAFT and shows the mission card; nothing runs, so no confirmation is needed. Then summarise the plan and ask the user to start it.',
            'parameters' => ['type' => 'object', 'properties' => ['title' => $S('short mission title'), 'goal' => $S('the overall goal in one or two sentences'),
                'steps' => ['type' => 'array', 'description' => '1-12 steps; steps without "after" run in parallel', 'items' => $step]], 'required' => ['title', 'steps']]],
        ['name' => 'start_mission', 'tier' => 'y', 'group' => 'missions', 'description' => 'Start a planned (draft) mission: queues every step whose dependencies are satisfied; later steps start automatically as earlier ones finish.',
            'parameters' => ['type' => 'object', 'properties' => ['mission_id' => $mid, 'confirmed' => $confirm], 'required' => ['confirmed']]],
        ['name' => 'mission_status', 'tier' => 'g', 'group' => 'missions', 'description' => 'Status of a mission: each step with its state, progress and results.',
            'parameters' => ['type' => 'object', 'properties' => ['mission_id' => $mid]]],
        ['name' => 'cancel_mission', 'tier' => 'y', 'group' => 'missions', 'description' => 'Cancel a draft or running mission: steps not yet finished are canceled and their queued/running Claude Code tasks are stopped.',
            'parameters' => ['type' => 'object', 'properties' => ['mission_id' => $mid, 'confirmed' => $confirm], 'required' => ['confirmed']]],
        ['name' => 'list_missions', 'tier' => 'g', 'group' => 'missions', 'description' => 'Recent missions of the current user with their status.',
            'parameters' => ['type' => 'object', 'properties' => (object)[]]],
        ['name' => 'list_protocols', 'tier' => 'g', 'group' => 'missions', 'description' => 'Named protocols (saved mission templates / macros, e.g. "House Party Protocol") the user can run.',
            'parameters' => ['type' => 'object', 'properties' => (object)[]]],
        ['name' => 'run_protocol', 'tier' => 'r', 'group' => 'missions', 'description' => 'Run a named protocol: expands it into a mission and starts it immediately. Protocols for "all projects" fan out in parallel over every project of the user\'s agent; one-project protocols need "project".',
            'parameters' => ['type' => 'object', 'properties' => ['name' => $S('protocol name as the user said it'), 'project' => $S('project name (required for one-project protocols; for all-project protocols limits it to this project)'), 'confirmed' => $confirm], 'required' => ['name', 'confirmed']]],
        ['name' => 'save_protocol', 'tier' => 'y', 'group' => 'missions', 'description' => 'Create or update the user\'s own named protocol (a reusable mission template). In step "project" use a project name, "{project}" (filled in when run) or "*" (every project of the agent, in parallel); "{project}" may also appear in prompts.',
            'parameters' => ['type' => 'object', 'properties' => ['name' => $S('protocol name, e.g. "Deploy Check Protocol"'), 'descr' => $S('one-line description'),
                'steps' => ['type' => 'array', 'description' => '1-12 template steps', 'items' => $step], 'confirmed' => $confirm], 'required' => ['name', 'steps', 'confirmed']]],
    ],
    'prompt' => <<<TXT
Missions and protocols (Claude Code fleet):
- One job in one project → run_claude_code. Several independent jobs at once → dispatch_agents (announce in one sentence and dispatch; the user has a short cancel window). A bigger goal with ordered steps or several projects → plan_mission (draft), tell the plan in one or two sentences, then start_mission (announce and start; no "Başlayım?" needed unless the user asked to review first).
- When the user names a protocol ("House Party Protocol", "gecə növbəsi" …) → run_protocol after a one-sentence confirmation. {$protoLine}
- Mission progress is shown on the HUD card; you will get a system note when a mission finishes: summarise it in 2-3 sentences. Use mission_status for questions about a running mission.
- Steps on one computer run in queue order unless the agent reports parallel workers; say so honestly if asked.
TXT,
];
