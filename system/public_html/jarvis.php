<?php
// Jarvis: real-time səsli agent. Səs təminatçısı adapterdir: Gemini Live (Google) və ya Grok Voice (xAI, fal.ai üzərindən).
// API açarları yalnız serverdə qalır (config.local.php). Brauzerə qısa ömürlü token verilir,
// səs brauzerlə təminatçı arasında birbaşa WebSocket ilə gedir. Alətləri brauzer mövcud endpointlərlə icra edir,
// yəni Jarvis-in icazələri giriş etmiş istifadəçinin icazələri ilə eynidir.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
deny_student($me);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 5000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();

$key = trim((string)($config['gemini_api_key'] ?? ''));
$model = (string)($config['gemini_model'] ?? 'gemini-3.8-live');
$voice = (string)($config['gemini_voice'] ?? 'Charon');
$falKey = trim((string)($config['fal_key'] ?? ''));
$grokVoice = (string)($config['grok_voice'] ?? 'rex');
// Aktiv səs təminatçıları. Gemini müvəqqəti söndürülüb (adapter kodu qalır); geri qaytarmaq üçün 'gemini'-ni siyahıya əlavə et.
$enabled = ['grok' /* , 'gemini' */];
$providers = array_values(array_intersect($enabled, array_keys(array_filter(['gemini' => $key !== '', 'grok' => $falKey !== '']))));
$defProvider = (string)($config['jarvis_provider'] ?? 'grok');
if (!in_array($defProvider, $providers, true)) {
    $defProvider = $providers[0] ?? 'gemini';
}
$lang = ($config['jarvis_language'] ?? 'az') === 'en' ? 'en' : 'az';
$limitSec = max(1, (int)($config['jarvis_daily_minutes'] ?? 60)) * 60;
$today = date('Y-m-d');

/** Bu günün istifadəsi (saniyə); sətir yoxdursa null. */
function used_today(PDO $pdo, string $user, string $day): ?int
{
    $st = $pdo->prepare('SELECT seconds FROM jarvis_usage WHERE user = ? AND day = ?');
    $st->execute([$user, $day]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int)$v;
}

if ($act === 'status') {
    $used = (int)used_today($pdo, $me['name'], $today);
    json_out(['configured' => (bool)$providers, 'providers' => $providers, 'provider' => $defProvider, 'model' => $defProvider === 'grok' ? 'Grok Voice · fal.ai' : $model, 'used' => $used, 'limit' => $limitSec, 'admin' => $me['role'] === 'admin']);
}

if ($act === 'usage') {                 // brauzer sessiya zamanı hər ~30 saniyədən bir bildirir
    $sec = max(0, min(120, (int)($in['seconds'] ?? 0)));
    $cur = used_today($pdo, $me['name'], $today);
    if ($sec > 0) {                     // bir SELECT + bir yazı (əvvəl UPDATE → INSERT → SELECT idi)
        if ($cur === null) {
            try {
                $pdo->prepare('INSERT INTO jarvis_usage (user, day, seconds) VALUES (?,?,?)')->execute([$me['name'], $today, $sec]);
            } catch (Throwable $e) {    // iki tab eyni anda: sətri o biri yaradıb
                $pdo->prepare('UPDATE jarvis_usage SET seconds = seconds + ? WHERE user = ? AND day = ?')->execute([$sec, $me['name'], $today]);
            }
        } else {
            $pdo->prepare('UPDATE jarvis_usage SET seconds = seconds + ? WHERE user = ? AND day = ?')->execute([$sec, $me['name'], $today]);
        }
    }
    $used = (int)$cur + $sec;
    json_out(['ok' => true, 'used' => $used, 'limit' => $limitSec, 'over' => $used >= $limitSec]);
}

if ($act === 'agent_send') {           // Jarvis → istifadəçinin öz Claude Code agenti (giriş sessiyası ilə, brauzerdə token lazım deyil)
    $project = trim((string)($in['project'] ?? ''));
    $prompt = trim((string)($in['prompt'] ?? ''));
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'proyekt adı yanlışdır'], 400);
    }
    if ($prompt === '' || mb_strlen($prompt) > 4000) {
        json_out(['error' => 'prompt 1-4000 simvol olmalıdır'], 400);
    }
    $ag = $pdo->prepare('SELECT last_seen, claude_ok, projects FROM agents WHERE user = ?');
    $ag->execute([$me['name']]);
    $a = $ag->fetch();
    if (!$a) {
        json_out(['error' => 'Bu istifadəçinin Claude Code agenti hələ heç qoşulmayıb. Kompüterdə agenti işə sal (📦 Paket → BASLA.md).'], 409);
    }
    $allowed = array_values(array_filter(explode("\n", (string)$a['projects']), 'strlen'));
    // agent qovluq siyahısını bildiribsə: yalnız onlarda işləyə bilər (qovluq adı → panel adı alias-ları da, db.php ilə eyni qayda)
    if ($allowed && !array_intersect(project_names($pdo, $project), $allowed)) {
        json_out(['error' => "Claude Code-un bu kompüterdə \"$project\" üçün qovluğu yoxdur (bu planet kod proyekti deyil və ya agentə əlavə olunmayıb). İcazəli proyektlər: " . implode(', ', $allowed) . '.', 'allowed' => $allowed], 409);
    }
    if (agent_queue_len($pdo, $me['name']) >= AGENT_QUEUE_MAX) {
        json_out(['error' => 'növbədə artıq ' . AGENT_QUEUE_MAX . ' tapşırıq var, gözlə'], 429);
    }
    $pdo->prepare("INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$me['name'], $project, $prompt, 'queued', '', $now, 0, 0]);
    $id = (int)$pdo->lastInsertId();
    json_out(['ok' => true, 'id' => $id, 'agent_online' => $now - (int)$a['last_seen'] < 30, 'claude_login' => (bool)$a['claude_ok'], 'allowed' => $allowed]);
}

if ($act !== 'session') {
    json_out(['error' => 'naməlum əməliyyat'], 400);
}

$provider = (string)($in['provider'] ?? $defProvider);
if (!in_array($provider, ['gemini', 'grok'], true)) {
    $provider = $defProvider;
}
if (!in_array($provider, $enabled, true)) {
    $provider = $enabled[0];
}
if (!in_array($provider, $providers, true)) {
    $what = $provider === 'grok' ? 'fal_key (fal.ai)' : 'gemini_api_key';
    json_out(['error' => ($provider === 'grok' ? 'fal.ai' : 'Gemini') . ' açarı qurulmayıb', 'setup' => "system/public_html/config.local.php faylına $what yaz (nümunə: config.local.example.php)."], 503);
}
$used = (int)used_today($pdo, $me['name'], $today);
if ($used >= $limitSec) {
    json_out(['error' => 'Bu günün Jarvis limiti bitib (' . intdiv($limitSec, 60) . ' dəqiqə). Sabah yenidən.'], 429);
}

/* ---------- Alətlər: təminatçıdan asılı olmayan JSON Schema (adapterlər öz formatına çevirir). İcra brauzerdədir: jarvis.js ---------- */
$S = fn(string $d) => ['type' => 'string', 'description' => $d];
$confirm = ['type' => 'boolean', 'description' => 'true only after the user has explicitly confirmed this exact action in this conversation'];
$tools = [
    ['name' => 'get_overview', 'description' => 'Current team status: who is working on what, projects and their state (incl. burning planets = no task finished for 24h), open tasks, test failures, meetings, agents. Call this before answering any status question.',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
    ['name' => 'get_project', 'description' => 'Details of one project/planet: sessions, recent events, tasks, tests, MCP servers, burn state.',
        'parameters' => ['type' => 'object', 'properties' => ['name' => $S('project or planet name, as the user said it')], 'required' => ['name']]],
    ['name' => 'list_tasks', 'description' => 'List tasks, optionally filtered.',
        'parameters' => ['type' => 'object', 'properties' => ['assignee' => $S('user name, or "me"'), 'project' => $S('project name'), 'status' => ['type' => 'string', 'enum' => ['open', 'todo', 'doing', 'review', 'done', 'any']]]]],
    ['name' => 'get_report', 'description' => 'Daily activity report per user.',
        'parameters' => ['type' => 'object', 'properties' => ['day' => ['type' => 'string', 'enum' => ['today', 'yesterday']]]]],
    ['name' => 'navigate', 'description' => 'Move the 3D space view: to a planet (project, custom planet, Prompt Generator, Designer, Meeting Planet, Test Planet, an MCP server, an agent), to a universe (Komanda, Studio, MCP, Agent), to the whole space map, or to the task board / panel.',
        'parameters' => ['type' => 'object', 'properties' => ['target' => $S('planet, universe, "map", "board" or "panel"')], 'required' => ['target']]],
    ['name' => 'create_task', 'description' => 'Create a task on the board.',
        'parameters' => ['type' => 'object', 'properties' => ['title' => $S('short task title'), 'project' => $S('project or planet name'), 'assignee' => $S('user name; omit for the current user'),
            'details' => $S('optional details'), 'due' => $S('optional deadline as local ISO date-time, e.g. 2026-10-15T18:00'), 'confirmed' => $confirm], 'required' => ['title', 'project', 'confirmed']]],
    ['name' => 'set_task_status', 'description' => 'Move a task to another status.',
        'parameters' => ['type' => 'object', 'properties' => ['task' => $S('task title (or part of it), "#id", or "this" for the last one discussed'), 'status' => ['type' => 'string', 'enum' => ['todo', 'doing', 'review', 'done']], 'confirmed' => $confirm], 'required' => ['task', 'status', 'confirmed']]],
    ['name' => 'set_task_due', 'description' => 'Set or clear a task deadline.',
        'parameters' => ['type' => 'object', 'properties' => ['task' => $S('task title (or part of it), "#id", or "this" for the last one discussed'), 'due' => $S('local ISO date-time, or "none" to clear'), 'confirmed' => $confirm], 'required' => ['task', 'due', 'confirmed']]],
    ['name' => 'set_ship_deadline', 'description' => "Set or clear the current user's spaceship deadline (mission).",
        'parameters' => ['type' => 'object', 'properties' => ['due' => $S('local ISO date-time, or "none" to clear'), 'mission' => $S('what the deadline is for'), 'confirmed' => $confirm], 'required' => ['due', 'confirmed']]],
    ['name' => 'schedule_meeting', 'description' => 'Schedule a meeting on Meeting Planet.',
        'parameters' => ['type' => 'object', 'properties' => ['title' => $S('topic'), 'start' => $S('local ISO date-time'), 'minutes' => ['type' => 'integer', 'description' => 'duration in minutes'],
            'attendees' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'user names; omit for everyone'], 'agenda' => $S('optional agenda'), 'confirmed' => $confirm], 'required' => ['title', 'start', 'confirmed']]],
    ['name' => 'record_test_result', 'description' => 'Record a test result on Test Planet (creates the test case if it does not exist).',
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('project name'), 'test' => $S('test case title'), 'result' => ['type' => 'string', 'enum' => ['pass', 'fail', 'todo']], 'note' => $S('what went wrong (for fail)'), 'confirmed' => $confirm], 'required' => ['project', 'test', 'result', 'confirmed']]],
    ['name' => 'send_message', 'description' => 'Send a short message to a teammate (or "all"); it flies as a rocket in the space view.',
        'parameters' => ['type' => 'object', 'properties' => ['to' => $S('user name or "all"'), 'text' => $S('message text, max 500 chars'), 'confirmed' => $confirm], 'required' => ['to', 'text', 'confirmed']]],
    ['name' => 'run_claude_code', 'description' => "Give a work task to Claude Code, the AI coding agent connected to this dashboard, which runs on the user's computer inside the project folder. Use it for ANY real work: writing or changing code, fixing bugs, adding tests, reviewing code, analysing the repository, explaining how something works in the code, writing docs. It runs in the background; you will get a note when it finishes. It can only work in projects that have a folder on the user's computer (see agents[].projects in get_overview); Studio planets such as Designer are not code folders.",
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('project name; omit to use the planet the user is looking at'), 'prompt' => $S('complete, self-contained instruction for Claude Code in English or Azerbaijani: goal, relevant details from the conversation, and what to report back'), 'confirmed' => $confirm], 'required' => ['prompt', 'confirmed']]],
    ['name' => 'get_claude_tasks', 'description' => 'Recent Claude Code tasks of the current user with status (queued/running/done/failed) and results.',
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('optional project filter')]]],
    ['name' => 'get_suggestions', 'description' => 'Prioritised things the current user should pay attention to now (priority 1 = urgent): unread messages, live/upcoming meetings, file collision risks, overdue or due-soon tasks, reviews waiting, failed Claude Code tasks, offline agent with queue, failing tests, burning planets. Call it for "what should I do / anything new / what did I miss".',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
    ['name' => 'get_screen', 'description' => 'What the user is looking at right now (view, planet, open dialogs, feed filter) and the task/project/user last mentioned in this conversation. Use it to resolve "here", "this planet", "this one".',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
    ['name' => 'get_user', 'description' => 'What one teammate is doing: live sessions and prompts, open tasks, recent activity, Claude Code agent, ship deadline.',
        'parameters' => ['type' => 'object', 'properties' => ['name' => $S('user name')], 'required' => ['name']]],
    ['name' => 'get_activity', 'description' => 'Recent activity feed (what people changed), optionally filtered by user and/or project.',
        'parameters' => ['type' => 'object', 'properties' => ['user' => $S('optional user name'), 'project' => $S('optional project name'), 'limit' => ['type' => 'integer', 'description' => '1-30, default 12']]]],
    ['name' => 'get_messages', 'description' => "The current user's team messages from the last 24 hours (sent and received). Marks them as read.",
        'parameters' => ['type' => 'object', 'properties' => ['unread_only' => ['type' => 'boolean', 'description' => 'only unread incoming messages']]]],
    ['name' => 'get_plans', 'description' => 'Plans and scopes of projects (Plan Station).',
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('optional project name'), 'status' => ['type' => 'string', 'enum' => ['open', 'any']]]]],
    ['name' => 'create_plan', 'description' => 'Add a plan item or a scope item to a project on Plan Station.',
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('project name'), 'kind' => ['type' => 'string', 'enum' => ['plan', 'scope']], 'title' => $S('short title'), 'details' => $S('optional details'), 'confirmed' => $confirm], 'required' => ['project', 'title', 'confirmed']]],
    ['name' => 'set_plan_status', 'description' => 'Mark a plan/scope item done or reopen it.',
        'parameters' => ['type' => 'object', 'properties' => ['plan' => $S('plan title (or part of it)'), 'status' => ['type' => 'string', 'enum' => ['open', 'done']], 'confirmed' => $confirm], 'required' => ['plan', 'status', 'confirmed']]],
    ['name' => 'update_task', 'description' => 'Rename a task, change its details, or (admins only) reassign it.',
        'parameters' => ['type' => 'object', 'properties' => ['task' => $S('task title, part of it, "#id", or "this" for the last one discussed'), 'title' => $S('new title'), 'details' => $S('new details'), 'assignee' => $S('new assignee (admin only)'), 'confirmed' => $confirm], 'required' => ['task', 'confirmed']]],
    ['name' => 'stop_claude_task', 'description' => 'Cancel a queued or running Claude Code task (default: the one just sent, else the running one).',
        'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'task id; omit for the latest'], 'confirmed' => $confirm], 'required' => ['confirmed']]],
    ['name' => 'set_agent_auto', 'description' => "Turn on/off whether the current user's Claude Code agent automatically picks up board tasks assigned to them.",
        'parameters' => ['type' => 'object', 'properties' => ['on' => ['type' => 'boolean'], 'confirmed' => $confirm], 'required' => ['on', 'confirmed']]],
    ['name' => 'post_meeting_note', 'description' => 'Write a note into a meeting chat on Meeting Planet (default: the live or next meeting).',
        'parameters' => ['type' => 'object', 'properties' => ['meeting' => $S('optional meeting title'), 'text' => $S('note text'), 'confirmed' => $confirm], 'required' => ['text', 'confirmed']]],
    ['name' => 'ui', 'description' => 'Control the dashboard UI. Actions: open_messages (target = recipient, optional), open_inbox, open_report, open_new_task (target = project, optional), open_ship (target = user, optional), open_new_planet, open_mcp, open_plan, close_dialogs, zoom_in, zoom_out, next_planet, prev_planet, sound_on, sound_off (universe sounds), filter_feed (target = user or "all"), mute_mic (Jarvis microphone).',
        'parameters' => ['type' => 'object', 'properties' => ['action' => ['type' => 'string', 'enum' => ['open_messages', 'open_inbox', 'open_report', 'open_new_task', 'open_ship', 'open_new_planet', 'open_mcp', 'open_plan', 'close_dialogs', 'zoom_in', 'zoom_out', 'next_planet', 'prev_planet', 'sound_on', 'sound_off', 'filter_feed', 'mute_mic']], 'target' => $S('optional: user, project, or recipient depending on action')], 'required' => ['action']]],
    ['name' => 'set_proactive', 'description' => 'Turn Jarvis live notifications (new messages, meetings, collisions, new tasks) on or off.',
        'parameters' => ['type' => 'object', 'properties' => ['on' => ['type' => 'boolean']], 'required' => ['on']]],
    ['name' => 'end_conversation', 'description' => 'End the voice session when the user says goodbye or asks Jarvis to stop listening.',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
];

$users = $pdo->query("SELECT name FROM users WHERE role != 'student' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);   // komanda (feed.php ilə eyni)
$langLine = $lang === 'az'
    ? 'Speak Azerbaijani (Azərbaycan dili) by default, naturally and correctly; if the user switches to another language, follow them.'
    : 'Speak English by default; if the user switches to another language, follow them.';
$nowStr = date('Y-m-d H:i');
$weekday = date('l');
$tz = (string)($config['timezone'] ?? 'Asia/Baku');
$team = implode(', ', $users);
$who = $me['name'];
$role = $me['role'];
$sys = <<<TXT
You are JARVIS, the voice assistant of the "Komanda Claude" team dashboard, in the spirit of Iron Man's J.A.R.V.I.S.: calm, precise, quietly witty, loyal. You talk with {$who} (role: {$role}).
Now: {$nowStr} ({$tz}), weekday {$weekday}. Team members: {$team}.

{$langLine}

The dashboard is a 3D universe: projects are planets in the "Komanda" universe; "Studio" holds Prompt Generator, Designer, Meeting Planet and Test Planet; "MCP Universe" shows MCP servers; "Agent Universe" shows each user's Claude Code agent. A planet burns when it has open tasks and nothing was finished for 24 hours. Each user has a spaceship with an optional deadline.

Rules:
- Keep spoken answers short: 1-3 sentences, no lists read out loud unless asked; round numbers; say times naturally.
- Never invent data: call get_overview / get_project / get_user / list_tasks / get_activity / get_report first, then answer from the result. For "what should I do", "anything new", "what did I miss" call get_suggestions and lead with the most urgent item.
- Navigation, questions and UI control (ui tool: open dialogs, zoom, sound, feed filter) need no confirmation: just do them.
- Context: "here", "this planet", "bu planet" mean the planet on screen (call get_screen if unsure). "This task", "that one", "it", "ona" refer to what was last discussed: tools accept "this" for task/project/user and "#id" for tasks. Track the conversation; do not ask again for something the user already said.
- If a tool says something was not found and lists similar items, ask one short question naming the top one or two candidates.
- Conversation: be natural and brief, no filler like "of course" before every answer. If a request is ambiguous, ask one short clarifying question instead of guessing. After finishing an action, offer at most one useful next step, and only when it is obvious (e.g. after creating a task: "Claude Code-a da göndərim?").
- Messages marked "[System note ... live dashboard update]" are automatic events, not user speech. Mention them in one short sentence without greeting; never repeat an update you already mentioned. If the user says the updates are annoying, call set_proactive with on=false.
- Typed input from the panel arrives as normal user text; answer it the same way.
- Any request to actually DO work in a project (code, bugs, tests, review, analysis of the code, docs) goes to Claude Code via run_claude_code. Write it a clear, complete prompt; do not try to do the work yourself. Afterwards tell the user it was sent and that you will report when it is done. When you receive a note that a Claude Code task finished, summarise the result in 1-2 spoken sentences (mention failures plainly).
- If the agent is offline, say the task is queued and will start when the agent on their computer runs.
- Every action that creates or changes something (tasks, statuses, deadlines, meetings, meeting notes, plans, tests, messages, Claude Code tasks, stopping Claude Code, agent auto mode): first say in one short sentence exactly what you will do and ask "Edim?"/"Shall I?". Only after the user clearly agrees call the tool with confirmed=true. Never set confirmed=true on your own.
- You cannot delete anything; say so if asked.
- If a tool returns an error, explain it briefly and suggest the fix.
- Resolve relative dates ("sabah saat 3-də", "next Friday") into local ISO date-times yourself.
- When the user says goodbye or asks you to stop, say a short farewell and call end_conversation.
TXT;

$out = ['provider' => $provider, 'lang' => $lang, 'used' => $used, 'limit' => $limitSec];

/* ---------- Adapter: Grok Voice (xAI) fal.ai üzərindən ---------- */
if ($provider === 'grok') {
    // fal-ın qısa ömürlü JWT-si yalnız bu tətbiqə (grok-voice) qoşulmağa icazə verir; fal açarı brauzerə getmir
    $c = curl_init('https://rest.fal.ai/tokens/');
    curl_setopt_array($c, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Key ' . $falKey],
        CURLOPT_POSTFIELDS => json_encode(['allowed_apps' => ['grok-voice'], 'token_expiration' => 120]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $tok = $raw === false ? null : json_decode($raw, true);
    $okCode = $code >= 200 && $code < 300;           // fal 201 Created qaytarır
    if (is_array($tok) && is_string($tok['detail'] ?? null) && $okCode) {
        $tok = $tok['detail'];
    }
    if (!$okCode || !is_string($tok) || $tok === '') {
        $msg = $raw === false ? curl_error($c) : ($okCode ? 'gözlənilməyən cavab formatı' : (is_array($tok) ? json_encode($tok['detail'] ?? $tok, JSON_UNESCAPED_UNICODE) : substr((string)$raw, 0, 300)));
        json_out(['error' => 'fal.ai token alınmadı (' . $code . '): ' . mb_substr((string)$msg, 0, 300)], 502);
    }
    $fmt = ['type' => 'audio/pcm', 'rate' => 24000];
    json_out($out + [
        'token' => $tok,
        'ws' => 'wss://fal.run/xai/grok-voice/realtime',
        'model' => 'Grok Voice · fal.ai',
        'session' => [
            'instructions' => $sys,
            'voice' => $grokVoice,
            'turn_detection' => ['type' => 'server_vad'],
            'audio' => [
                'input' => ['format' => $fmt, 'transcription' => ['language_hint' => $lang]],
                'output' => ['format' => $fmt],
            ],
            'tools' => array_map(fn($t) => ['type' => 'function'] + $t, $tools),
        ],
    ]);
}

/* ---------- Adapter: Gemini Live (Google) ---------- */
/** JSON Schema tiplərini Gemini-nin formatına çevirir (string → STRING) */
function gemini_schema($v)
{
    if (!is_array($v)) {
        return $v;
    }
    foreach ($v as $k => $x) {
        $v[$k] = $k === 'type' && is_string($x) ? strtoupper($x) : gemini_schema($x);
    }
    return $v;
}
$setup = [
    'model' => 'models/' . $model,
    'generationConfig' => [
        'responseModalities' => ['AUDIO'],
        'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]],
    ],
    'systemInstruction' => ['parts' => [['text' => $sys]]],
    'tools' => [['functionDeclarations' => array_map('gemini_schema', $tools)]],
    'inputAudioTranscription' => (object)[],
    'outputAudioTranscription' => (object)[],
    'contextWindowCompression' => ['slidingWindow' => (object)[]],
];
/** Gemini auth_tokens çağırışı. Qaytarır: [http kodu, cavab massivi] */
function gemini_token(string $key, array $body): array
{
    $c = curl_init('https://generativelanguage.googleapis.com/v1beta/auth_tokens');
    curl_setopt_array($c, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    if ($raw === false) {
        return [0, ['error' => ['message' => $err ?: 'şəbəkə xətası']]];
    }
    return [$code, json_decode($raw, true) ?: ['error' => ['message' => substr($raw, 0, 300)]]];
}

$iso = fn(int $t) => gmdate('Y-m-d\TH:i:s\Z', $t);
$base = ['uses' => 1, 'expireTime' => $iso($now + 30 * 60), 'newSessionExpireTime' => $iso($now + 2 * 60)];
// Əvvəlcə bütün ayarları tokenə bağlayırıq (sistem təlimatı serverdən gəlsin); alınmasa, ayarsız token alıb setup-ı brauzer göndərir
[$code, $res] = gemini_token($key, $base + ['bidiGenerateContentSetup' => $setup]);
$locked = true;
if ($code !== 200) {
    [$code2, $res2] = gemini_token($key, $base);
    if ($code2 === 200) {
        [$code, $res, $locked] = [$code2, $res2, false];
    }
}
if ($code !== 200 || empty($res['name'])) {
    $msg = (string)($res['error']['message'] ?? 'naməlum xəta');
    json_out(['error' => 'Gemini token alınmadı (' . $code . '): ' . mb_substr($msg, 0, 300)], 502);
}

json_out($out + [
    'token' => $res['name'],
    'ws' => 'wss://generativelanguage.googleapis.com/ws/google.ai.generativelanguage.v1beta.GenerativeService.BidiGenerateContentConstrained',
    'setup' => $setup,
    'locked' => $locked,
    'model' => $model,
]);
