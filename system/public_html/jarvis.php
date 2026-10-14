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

function used_today(PDO $pdo, string $user, string $day): int
{
    $st = $pdo->prepare('SELECT seconds FROM jarvis_usage WHERE user = ? AND day = ?');
    $st->execute([$user, $day]);
    return (int)$st->fetchColumn();
}

if ($act === 'status') {
    $used = used_today($pdo, $me['name'], $today);
    json_out(['configured' => (bool)$providers, 'providers' => $providers, 'provider' => $defProvider, 'model' => $defProvider === 'grok' ? 'Grok Voice · fal.ai' : $model, 'used' => $used, 'limit' => $limitSec, 'admin' => $me['role'] === 'admin']);
}

if ($act === 'usage') {                 // brauzer sessiya zamanı hər ~30 saniyədən bir bildirir
    $sec = max(0, min(120, (int)($in['seconds'] ?? 0)));
    if ($sec > 0) {
        $up = $pdo->prepare('UPDATE jarvis_usage SET seconds = seconds + ? WHERE user = ? AND day = ?');
        $up->execute([$sec, $me['name'], $today]);
        if ($up->rowCount() === 0) {
            $pdo->prepare('INSERT INTO jarvis_usage (user, day, seconds) VALUES (?,?,?)')->execute([$me['name'], $today, $sec]);
        }
    }
    $used = used_today($pdo, $me['name'], $today);
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
    if ($allowed) {                       // agent qovluq siyahısını bildiribsə: yalnız onlarda işləyə bilər (qovluq adı → panel adı alias-ları da)
        $names = [$project];
        $al = $pdo->prepare('SELECT src FROM aliases WHERE dst = ?');
        $al->execute([$project]);
        $names = array_merge($names, $al->fetchAll(PDO::FETCH_COLUMN));
        if (!array_intersect($names, $allowed)) {
            json_out(['error' => "Claude Code-un bu kompüterdə \"$project\" üçün qovluğu yoxdur (bu planet kod proyekti deyil və ya agentə əlavə olunmayıb). İcazəli proyektlər: " . implode(', ', $allowed) . '.', 'allowed' => $allowed], 409);
        }
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM commands WHERE user = ? AND status IN ('queued','running')");
    $c->execute([$me['name']]);
    if ((int)$c->fetchColumn() >= 5) {
        json_out(['error' => 'növbədə artıq 5 tapşırıq var, gözlə'], 429);
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
if (used_today($pdo, $me['name'], $today) >= $limitSec) {
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
        'parameters' => ['type' => 'object', 'properties' => ['task' => $S('task title (or part of it)'), 'status' => ['type' => 'string', 'enum' => ['todo', 'doing', 'review', 'done']], 'confirmed' => $confirm], 'required' => ['task', 'status', 'confirmed']]],
    ['name' => 'set_task_due', 'description' => 'Set or clear a task deadline.',
        'parameters' => ['type' => 'object', 'properties' => ['task' => $S('task title (or part of it)'), 'due' => $S('local ISO date-time, or "none" to clear'), 'confirmed' => $confirm], 'required' => ['task', 'due', 'confirmed']]],
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
    ['name' => 'end_conversation', 'description' => 'End the voice session when the user says goodbye or asks Jarvis to stop listening.',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
];

// Modullar (jarvis_modules/*.php): hər biri ['tools' => [...JSON Schema alətləri], 'prompt' => 'sistem təlimatına əlavə'] qaytarır.
// Alətlərin icrası brauzerdə, uyğun jarvis-*.js modulundadır (Jarvis.registerTool). Hər modul faylı "defined('JARVIS_MODULE') || exit;" ilə başlayır.
define('JARVIS_MODULE', 1);
$modPrompt = '';
foreach (glob(__DIR__ . '/jarvis_modules/*.php') ?: [] as $mf) {
    $mod = require $mf;
    if (is_array($mod)) {
        $tools = array_merge($tools, array_values($mod['tools'] ?? []));
        if (!empty($mod['prompt'])) {
            $modPrompt .= "\n" . trim((string)$mod['prompt']);
        }
    }
}

$users = array_column($pdo->query('SELECT name FROM users ORDER BY name')->fetchAll(), 'name');
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
- Never invent data: call get_overview / get_project / list_tasks / get_report first, then answer from the result.
- Navigation and questions need no confirmation: just do them.
- Any request to actually DO work in a project (code, bugs, tests, review, analysis of the code, docs) goes to Claude Code via run_claude_code. Write it a clear, complete prompt; do not try to do the work yourself. Afterwards tell the user it was sent and that you will report when it is done. When you receive a note that a Claude Code task finished, summarise the result in 1-2 spoken sentences (mention failures plainly).
- If the agent is offline, say the task is queued and will start when the agent on their computer runs.
- Every action that creates or changes something (tasks, statuses, deadlines, meetings, tests, messages, Claude Code tasks): first say in one short sentence exactly what you will do and ask "Edim?"/"Shall I?". Only after the user clearly agrees call the tool with confirmed=true. Never set confirmed=true on your own.
- You cannot delete anything; say so if asked.
- If a tool returns an error, explain it briefly and suggest the fix.
- Resolve relative dates ("sabah saat 3-də", "next Friday") into local ISO date-times yourself.
- When the user says goodbye or asks you to stop, say a short farewell and call end_conversation.
{$modPrompt}
TXT;

$out = ['provider' => $provider, 'lang' => $lang, 'used' => used_today($pdo, $me['name'], $today), 'limit' => $limitSec];

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
