<?php defined('JARVIS_MODULE') || exit;
// Proyektlərin sağlamlığı, PR-lar, Claude Code idarəsi (konfiq, istifadə, MCP, model/rejim), cədvəllər və gecikmə paneli. İcra: jarvis-projects.js
// ops.php → istifadəçinin agenti (claude_ops.py) → ops.result; cədvəllər: schedule.php. tier: g (təsdiqsiz) | y (et və elan et) | r (açıq təsdiq).
$s = fn(string $d) => ['type' => 'string', 'description' => $d];
$confirm = ['type' => 'boolean', 'description' => 'true only after the user has explicitly confirmed this exact action in this conversation'];
$proj = $s('project name as the user said it; omit for the planet the user is looking at');
$P = fn(array $props, array $req = []) => ['type' => 'object', 'properties' => $props ?: (object)[]] + ($req ? ['required' => $req] : []);
$t = fn(string $name, string $tier, string $group, string $d, array $params) => ['name' => $name, 'description' => $d, 'parameters' => $params, 'tier' => $tier, 'group' => $group];

return [
    'tools' => [
        // --- proyektlər ---
        $t('project_health', 'g', 'projects', 'Health of a code project from the user\'s computer: git branch/ahead/behind/dirty, last commits, open PRs, latest CI run, dependency audit, last deploy. Shows a HUD panel.',
            $P(['project' => $proj])),
        $t('pr_summary', 'g', 'projects', 'Summarise a pull request (title, files, +/-, checks, body). Without number: the latest open PR. Shows a HUD panel with a Merge button.',
            $P(['project' => $proj, 'number' => ['type' => 'integer', 'description' => 'PR number; omit for the latest open PR']])),
        $t('pr_merge', 'r', 'projects', 'Merge a pull request via gh on the user\'s computer (agent profile ops/full only).',
            $P(['project' => $proj, 'number' => ['type' => 'integer', 'description' => 'PR number'], 'method' => ['type' => 'string', 'enum' => ['squash', 'merge', 'rebase']], 'confirmed' => $confirm], ['number', 'confirmed'])),
        $t('undo_last', 'r', 'projects', 'Undo the last Claude Code change in a project: restores the snapshot taken before the last agent task.',
            $P(['project' => $proj, 'confirmed' => $confirm], ['confirmed'])),
        $t('worktree_new', 'y', 'projects', 'Create a new git worktree (parallel working copy) for a project.',
            $P(['project' => $proj, 'name' => $s('worktree/branch name, short slug')], ['name'])),
        $t('run_skill', 'y', 'projects', 'Run a Claude Code skill (slash command, e.g. "review", "security-review") in a project as a normal Claude Code task; you get a note when it finishes.',
            $P(['project' => $proj, 'skill' => $s('skill name without the slash'), 'args' => $s('optional arguments for the skill')], ['skill'])),
        $t('schedule_add', 'y', 'projects', 'Schedule recurring maintenance: runs a protocol (mission) or one Claude Code prompt at a fixed time. Rules: "daily HH:MM", "weekdays HH:MM", "weekly mon..sun HH:MM" (server time).',
            $P(['name' => $s('short schedule name'), 'rule' => $s('e.g. "daily 03:00", "weekdays 09:30", "weekly fri 18:00"'),
                'protocol' => $s('protocol name to run (e.g. "Dependency Update Protocol")'), 'prompt' => $s('or: one Claude Code instruction (needs project)'),
                'project' => $s('project (required for prompt and one-project protocols; limits all-project protocols)')], ['name', 'rule'])),
        $t('schedule_list', 'g', 'projects', 'List the user\'s schedules with next run time.', $P([])),
        $t('schedule_remove', 'y', 'projects', 'Delete a schedule by id or name.', $P(['id' => ['type' => 'integer'], 'name' => $s('schedule name if id unknown')])),
        $t('latency_stats', 'g', 'projects', 'Jarvis latency dashboard: p50/p90 per kind for the last 24 h (HUD chart).', $P([])),
        // --- Claude Code ---
        $t('claude_status', 'g', 'claude', 'Claude Code on the user\'s computer: version, update available, logged in, default model, usage today and this week.', $P([])),
        $t('claude_config', 'g', 'claude', 'Claude Code configuration of a project: permissions allow/deny, hooks, model, CLAUDE.md head, skills/agents/commands, MCP servers.',
            $P(['project' => $proj])),
        $t('claude_usage', 'g', 'claude', 'Claude Code token/cost usage per day and project.', $P(['days' => ['type' => 'integer', 'description' => '1-90, default 7']])),
        $t('claude_transcript', 'g', 'claude', 'What Claude Code did recently in a project: prompts, tools, files, errors.',
            $P(['project' => $proj, 'minutes' => ['type' => 'integer', 'description' => 'look back this many minutes (default 60)']])),
        $t('mcp_add', 'r', 'claude', 'Add an MCP server to Claude Code (project or user scope). Agent profile ops/full only.',
            $P(['project' => $proj, 'name' => $s('server name'), 'command' => $s('stdio command line'), 'url' => $s('or: http/sse URL'),
                'scope' => ['type' => 'string', 'enum' => ['local', 'project', 'user'], 'description' => 'local (default, only this user in this project), project (.mcp.json, shared via git), user (all projects)'], 'confirmed' => $confirm], ['name', 'confirmed'])),
        $t('claude_md_add', 'r', 'claude', 'Append a rule/note to the project\'s CLAUDE.md.',
            $P(['project' => $proj, 'text' => $s('text to append (markdown, short)'), 'confirmed' => $confirm], ['text', 'confirmed'])),
        $t('allow_rule', 'r', 'claude', 'Add a permission allow rule (e.g. "Bash(npm test:*)") to the project\'s .claude/settings.local.json.',
            $P(['project' => $proj, 'rule' => $s('permission rule'), 'confirmed' => $confirm], ['rule', 'confirmed'])),
        $t('claude_update', 'r', 'claude', 'Update Claude Code on the user\'s computer (or run doctor).',
            $P(['doctor' => ['type' => 'boolean', 'description' => 'true = run "claude doctor" (diagnostics) instead of updating'], 'confirmed' => $confirm], ['confirmed'])),
        $t('set_model_mode', 'y', 'claude', 'Defaults for the next Claude Code tasks from Jarvis: model (opus/sonnet/haiku/default) and mode (plan = read-only planning, normal).',
            $P(['model' => ['type' => 'string', 'enum' => ['opus', 'sonnet', 'haiku', 'default']], 'mode' => ['type' => 'string', 'enum' => ['plan', 'normal']]])),
    ],
    'prompt' => <<<'TXT'
Projects and Claude Code control: health, PRs, config, usage, transcripts and status are fetched from the user's computer (takes a few seconds): say "yoxlayıram" once, the details appear in a HUD panel, then speak only a 1-2 sentence summary (CI red, open PRs, dirty git). If a result says pending, the panel and a note follow later. 🔴 actions (pr_merge, undo_last, mcp_add, claude_md_add, allow_rule, claude_update) need an explicit yes before confirmed=true; a "[User clicked Merge]" note still needs one short confirmation. Model/mode set with set_model_mode apply to later run_claude_code tasks. Schedules: schedule_add with a protocol (e.g. Dependency Update, Prod Error Sweep, Release & Deploy) or a prompt.
TXT,
];
