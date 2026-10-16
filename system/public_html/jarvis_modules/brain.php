<?php defined('JARVIS_MODULE') || exit;
// Beyin modulu: think (dərin düşünmə → Claude Code kind=think), load_tools (qrupu sessiya ərzində yüklə), audit_log.
// Həmçinin qrup təsvirləri ('groups') və tier/group yazmayan modulların alətləri üçün ehtiyat xəritə ('meta'). İcra: jarvis-brain.js, jarvis.js (load_tools)
$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$g = fn(string $tier, string $group) => ['tier' => $tier, 'group' => $group];
return [
    'tools' => [
        ['name' => 'think', 'tier' => 'g', 'group' => 'core', 'description' => 'Deep reasoning for hard questions (architecture, trade-offs, debugging strategy, planning, code analysis): sends the question to Claude Code with Opus in read-only plan mode on the user\'s computer. Returns immediately; the answer arrives later as a "[Think result]" note (usually 20-120 s).',
            'parameters' => ['type' => 'object', 'properties' => ['question' => $S('the full question with all relevant details from the conversation'), 'project' => $S('optional project the question is about (its code is read for context)')], 'required' => ['question']]],
        ['name' => 'load_tools', 'tier' => 'g', 'group' => 'core', 'description' => 'Load an extra tool group into this session (see the group list in your instructions). Fast; afterwards the tools are available immediately.',
            'parameters' => ['type' => 'object', 'properties' => ['group' => $S('group name, e.g. "missions" or "projects"')], 'required' => ['group']]],
        ['name' => 'audit_log', 'tier' => 'g', 'group' => 'core', 'description' => 'Recent Jarvis tool calls (audit log): what was done, when, ok or failed. Admins can include everyone.',
            'parameters' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => '1-100, default 20'], 'all' => ['type' => 'boolean', 'description' => 'admin only: all users']]]],
    ],
    'groups' => [
        'core' => 'overview, projects, tasks, meetings, messages, run_claude_code, memory, think',
        'hud' => 'holographic panels on screen',
        'ui' => 'drive the website UI, screen context, point at things, tours',
        'claude' => 'Claude Code fleet: parallel dispatch, permission approvals, live sessions',
        'missions' => 'multi-step missions and named protocols (macros)',
        'projects' => 'project health/CI/PRs, Claude Code config, MCP, usage, transcripts, skills, schedules, undo, model/mode',
        'approvals' => 'Claude Code permission requests (allow/deny)',
    ],
    // Ehtiyat: digər axınların modulları tier/group yazmasa da düzgün səviyyə alsın
    'meta' => [
        'list_approvals' => $g('g', 'claude'), 'decide_approval' => $g('y', 'claude'), 'claude_sessions' => $g('g', 'claude'),
        'project_health' => $g('g', 'projects'), 'claude_status' => $g('g', 'projects'), 'claude_config' => $g('g', 'projects'), 'claude_usage' => $g('g', 'projects'),
        'claude_transcript' => $g('g', 'projects'), 'pr_summary' => $g('g', 'projects'), 'worktree_list' => $g('g', 'projects'), 'schedule_list' => $g('g', 'projects'),
        'latency_stats' => $g('g', 'projects'), 'set_model_mode' => $g('g', 'projects'), 'claude_doctor' => $g('g', 'projects'),
        'run_skill' => $g('y', 'projects'), 'worktree_new' => $g('y', 'projects'), 'schedule_add' => $g('y', 'projects'), 'schedule_remove' => $g('y', 'projects'),
        'mcp_add' => $g('r', 'projects'), 'claude_md_add' => $g('r', 'projects'), 'allow_rule' => $g('r', 'projects'), 'claude_update' => $g('r', 'projects'),
        'pr_merge' => $g('r', 'projects'), 'undo_last' => $g('r', 'projects'), 'sessions_clean' => $g('r', 'projects'),
    ],
];
