# Jarvis Ultra: shared contracts

Every workstream builds against these interfaces. Change a contract only by telling the lead.

## 0. Ground rules
- Branch `feature/jarvis-ultra`, worktree `~/Documents/planet-ultra`. Local DB and `config.local.php` are symlinks shared with other local instances. Additive schema changes only. Never delete other rows.
- Code style: Azerbaijani UI strings and comments, terse and dense, match the surrounding file.
- Default behaviour on every team computer must stay as it is today, unless the computer's owner opts in through `~/.claude-team-agent.env`.
- Never read, print or send secrets: tokens, keys, `.env` files, or Claude login data.

## 1. Real-time event bus (workstream A)
- **Table** `rt_events(id, type, user, project, ref, data JSON, created_at)`. (The name `events` is already taken by the hook log.), written with `emit_event($pdo, $type, $user, $project, $ref, $data)` from `db.php`.
- **Browser:** `events.php` is an SSE stream for the logged-in user. It emits rows where `user IN (me, '')`, plus team-wide types for admins.
  - Resume with `?since=<id>` or the `Last-Event-ID` header.
  - Send a heartbeat comment every 15 s and reconnect after about 55 s (PHP time limits).
  - Clients use `window.RT` from `realtime.js`:
    - `RT.on(type | '*', cb(evt))`, where `evt = {id, type, user, project, ref, data, created_at}`.
    - `RT.connected()` returns whether the stream is up.
  - Fallback: if SSE fails, `RT` long-polls `events.php?poll=1&since=`.
  - `index.php` keeps its 3 s `load()`, but `RT` triggers an immediate `load()` on `command.*`, `approval.*` and `session.*` events. With SSE healthy, `load()` slows to every 10 s.
- **Agent:** `agent.php` `claim` accepts `wait` (seconds, ≤ 25). It long-polls until a queued command exists for this agent, then answers immediately. Old agents send no `wait`, so their behaviour is unchanged.
- **Event types:**

| Type | Data / meaning |
|---|---|
| `command.queued` | `{id, project, prompt, kind}` |
| `command.started` | `{id}` |
| `command.progress` | `{id, step, pct, files}` |
| `command.done` | `{id, status, result_tail}` |
| `approval.new` | `{id, project, tool, summary}` |
| `approval.decided` | `{id, status}` |
| `ops.result` | `{id, op, ok, data}` (an ops command finished; `data` is a parsed JSON result) |
| `session.update` | `{user, session_id, project, status, prompt}` (from `ingest.php`) |
| `jarvis.notice` | `{text, level}` (proactive notices) |

  Whoever changes the matching rows emits the event. Workstream A wires `command.*` in `agent.php`, `jarvis.php` (`agent_send`), `mission_lib.php` (`queue`) and `tasks.php` (auto send), plus `session.update` in `ingest.php`. Workstream B emits `approval.*`. Workstream D2 emits `ops.result` through `agent.php` finish when `kind = 'ops'`; A adds that in `finish`.
- **Latency:** table `jarvis_latency`.
  - `commands.said_at` holds the ms timestamp when the user spoke. `jarvis.js` sends it with `agent_send`, `dispatch` and `ops`.
  - `latency.php` handles `{action: 'mark', kind, ref, said_at, start_at, first_at, done_at, meta}` and `{action: 'stats'}` (p50/p90 per kind, last 24 h).
  - The agent sets `started_at`/`finished_at` as today; A records the claim time as `start_at`.

## 2. Commands: kinds, model, mode (workstreams D1 and D2)

| `commands.kind` | Meaning |
|---|---|
| `prompt` (default), `task`, `review` | Existing kinds. Claude Code edits the project |
| `think` | Read-only reasoning: `claude -p --model opus --permission-mode plan`, with no edits and no Bash beyond read-only. `prompt` holds the question plus context; `result` is the answer. Used by Jarvis's `think` tool |
| `ops` | Not a Claude prompt. `prompt` holds JSON `{op, args}`, run by `claude_ops.py` on the computer. `result` is JSON |

- `commands.model`: `''` (agent default), `opus`, `sonnet` or `haiku`, passed as `--model`.
- `commands.mode`: `''` or `plan` (`--permission-mode plan`).
- **Ops list** (D2 implements, E calls through `ops.php`):

| Op | Returns |
|---|---|
| `claude_info` | version, update available, login status (logged in yes/no only), default model |
| `mcp_list {project}` | MCP servers for the project and user scope |
| `config {project}` | permissions allow/deny, hooks events, model, CLAUDE.md head (2 KB), skills/agents/commands names |
| `usage {days}` | tokens and cost per day/project from `~/.claude/projects/**/*.jsonl` |
| `transcript {project, since_minutes, session?}` | recent prompts, tools, files and errors, condensed to 8 KB |
| `health {project}` | git branch/ahead/behind/dirty, last commits, `gh pr list`, `gh run list` (latest CI), dependency audit summary if cheap, last deploy if detectable |
| `pr_view {project, number}` | title, files, +/-, checks, body head |
| `pr_merge {project, number, method}` | 🔴, only if the profile is `ops` or `full` |
| `mcp_add {project, name, command \| url, scope}` | 🔴, profile `ops`/`full` |
| `claude_md_append {project, text}` | 🔴, any profile ≥ `dev` |
| `allow_rule {project, rule}` | 🔴, writes `.claude/settings.local.json` `permissions.allow`; profile `ops`/`full` |
| `claude_update`, `claude_doctor` | profile ≥ `dev` |
| `sessions_clean {days}` | 🔴, deletes old transcripts, profile ≥ `dev` |
| `worktree_new {project, name}`, `worktree_list {project}` | git worktrees |
| `undo_last {project}` | restores the snapshot taken before the last agent task (see D1). 🔴 |

- **`ops.php`** (browser → server):
  - `{action: 'run', op, args, project}` creates a `kind = 'ops'` command for the current user's agent and returns `{id}`.
  - `{action: 'get', id}` returns status and the parsed result.
  - The browser waits for `RT` `ops.result` with `ref = id`; polling `get` is the fallback.
  - The server validates the op name against an allowlist and the 🔴 ops against the user's role. The agent re-checks against its own profile.

## 3. Agent permission profiles (workstream D1)
- `CLAUDE_TEAM_PROFILE=basic|dev|ops|full`, set in the environment or in `~/.claude-team-agent.env`. Default `basic` is exactly today's behaviour; the git level stays as it is.
  - `dev`: `basic` + Bash for package managers, tests, linters, build tools, local servers (`npm`/`pnpm`/`yarn`/`bun`, `node`, `npx`, `python`/`pytest`/`pip`, `cargo`, `go`, `make`, `php`, `composer`, `ls`/`cat`/`grep`/`find`/`head`/`tail`/`wc`/`jq`/`curl` GET) + `WebFetch`/`WebSearch` + git level safe.
  - `ops`: `dev` + `gh`, `vercel`, deploy scripts in the repo, all `mcp__*` tools + git level full.
  - `full`: everything except the deny list.
- **Deny list (always):** force push, `reset --hard`, `clean -f`, `branch -D`, `rm -rf` outside the project, `sudo`, reading `.env*`, `*.pem`, `id_*` and `~/.claude*` credentials, and `curl | sh`.
- The agent reports `profile` and `caps` (JSON: claude version, MCP server names, warm session projects) in `ping`; `agents.profile` and `agents.caps` hold them.
- **Self-check** (profile ≥ `dev`): after a `prompt` or `task` command, ask Claude in the same session to run the project's tests/lint if they exist, and append "✅ tests …" / "❌ …" to the result.
- **Snapshot:** before every editing command, if the project is a git repo and dirty or clean, record `git stash create` (or `HEAD`) in `~/.claude-team-snapshots.json` per project for `undo_last`.
- **Warm sessions:** keep one persistent `claude` process per recently used project (stream-json input; idle timeout 10 min; at most 3) and feed the next command into it. Fall back to today's `-p --resume` on any error.
- **Long-poll:** use `claim` with `wait = 20`. On an old server, `wait` is ignored and the agent keeps today's 3 s loop.
- `think` and `ops` kinds as in §2.

## 4. Approval relay (workstream B)
- **Hook:** `claude-team-hook.sh` registers for `PermissionRequest` (`install.sh` adds the event).
  - It POSTs to `approval.php` `{action: 'request', session_id, project, tool, input}` with the hook token and long-polls `{action: 'wait', id}` for up to 50 s.
  - On `allow`/`deny` it prints the hook decision JSON that Claude Code expects for `PermissionRequest`. Check the current docs; if unsure, use `{"hookSpecificOutput":{"hookEventName":"PermissionRequest","decision":{"behavior":"allow"|"deny","message":"…"}}}`.
  - On timeout or error it prints nothing and exits 0, so the normal interactive prompt appears.
- **Server:** `approval.php` handles `request` (token auth), `wait` (token), `decide` (logged-in session; the owner, or an admin for anyone; `allow|deny` + reason) and `list` (pending for me, or all for an admin). It emits `approval.new` and `approval.decided`.
- **Jarvis:**
  - Tools `list_approvals` and `decide_approval {id, decision, reason?}`, speaking when `approval.new` arrives.
  - A HUD card with Allow/Deny buttons.
  - Tool `claude_sessions` for a live view of all sessions (from `data.sessions` and the `session.update` event).

## 5. Jarvis brain (workstream C)
- **Session (Grok):**
  - `reasoning: {effort: 'none'}`.
  - `turn_detection: {type: 'server_vad', silence_duration_ms: 450}`.
  - Built-in tools `web_search` and `x_search` enabled (see the xAI / fal docs; the fal schema uses `tools.web_search.enabled`). Make sure it doesn't break if they're rejected: fall back to function tools only.
- **Confirmation tiers:** each tool declares `tier` in PHP: `g` (no confirmation), `y` (act immediately and announce, with a 4 s undo chip) or `r` (explicit confirmation, `confirmed = true`). `Jarvis.util.confirmed(args, tier)` enforces it. Users can override per tool in localStorage (`jv-tiers`).
- **Tool gating:**
  - Core tools are always loaded.
  - Other modules declare `group` (`missions`, `ui`, `claude`, `projects`, `hud`, …).
  - `load_tools({group})` adds a group mid-session (`session.update` with the merged tool list). Every group is listed in the prompt with a one-line description.
- **`think {question, project?}`:** queues `kind = 'think'` (model `opus`), answers "thinking…" immediately, and speaks the answer when `command.done` arrives.
- **Memory v2:** facts per user (existing), plus per-project facts (`project_facts`) and a session summary.
  - At session end, the summary is stored as a memory item (kind `summary`).
  - The last 7 days of summaries plus the facts go into the prompt.
- **Situation line:** a compact "situation" string (running commands, pending approvals, overdue tasks, CI red projects from the last health results) is sent as silent context every 60 s and on change.
- **Speculative prefetch and cache:** while the user is speaking, prefetch `get_overview`. Cache read tools for 5 s.
- **Audit:** every tool call posts to `audit.php` `{tool, tier, args, ok, result_head}`. Tool `audit_log {limit}` shows it.
- **Latency marks:** user speech end → tool start → first audio → done, posted to `latency.php`.

## 6. Projects and Claude Code control from Jarvis (workstream E)
- **Tools** (they call `ops.php`; results come from `RT` `ops.result`):
  - `project_health {project}`: shows a HUD panel (CI, PRs, git, audit).
  - `claude_status`: version, login, usage today and this week.
  - `claude_config {project}`, `claude_usage {days}`, `claude_transcript {project, minutes}`.
  - `mcp_add`, `claude_md_add`, `allow_rule`, `claude_update` (🔴).
  - `pr_summary {project, number?}`, `pr_merge` (🔴).
  - `undo_last {project}` (🔴), `worktree_new`.
  - `run_skill {project, skill, args}`: queues a `prompt` command "Run the /<skill> skill …".
  - `set_model_mode {model?, mode?}`: defaults for the next `run_claude_code`. `run_claude_code` and `dispatch_agents` accept `model` and `mode`.
- **Schedules:** `schedule.php` with the `schedules` table.
  - Rules are `daily HH:MM`, `weekdays HH:MM` or `weekly <mon..sun> HH:MM` in the server timezone.
  - `schedules_due($pdo)` is called (guarded) from `agent.php` `claim` and from `feed.php`. It starts a protocol mission (`mission_lib`) or a single prompt command.
  - Tools: `schedule_add` (🟡), `schedule_list`, `schedule_remove` (🟡).
- **Maintenance protocols** seeded in `mission_lib` defaults: "Dependency Update", "Prod Error Sweep", "Release & Deploy" (deploy steps only for profile `ops`).
- **Latency dashboard:** a HUD panel or tool `latency_stats`.

## File ownership

| Stream | Files |
|---|---|
| **A** | `events.php`, `realtime.js`, `latency.php`, `agent.php`, `ingest.php`, `jarvis.php` (only the `agent_send` emit plus `said_at`), `tasks.php` (only the emit), `mission_lib.php` (only the emit in the queue function), `index.php` (only the RT include, `load()` cadence and latency hookup) |
| **B** | `hook/claude-team-hook.sh`, `hook/install.sh`, `approval.php`, `jarvis_modules/approvals.php`, `jarvis-approvals.js` |
| **C** | `jarvis.js`, `jarvis.php` (everything except A's `agent_send` lines), `jarvis-memory.js`, `jarvis-proactive.js`, `jarvis_modules/memory.php`, `jarvis_modules/brain.php`, `jarvis-brain.js`, `audit.php`, and the `tier`/`group` keys in other modules' PHP declarations (C may edit those arrays only) |
| **D1** | `agent/claude-team-agent.py`, `public_html/kit/BASLA.template.md` (agent section) |
| **D2** | `agent/claude_ops.py`, `ops.php`. `deploy-prompt.sh` ships `claude_ops.py` to `kit/agent/` (the lead does that edit) |
| **E** | `jarvis_modules/projects.php`, `jarvis-projects.js`, `schedule.php`, `mission_lib.php` (only the protocol defaults and the `schedules_due` function) |
| **Lead** | `db.php`, `feed.php`, `deploy-prompt.sh`, README, integration |
