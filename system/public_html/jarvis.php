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
// PHP fatal xəta boş 500 yerinə JSON olsun (panel səbəbi göstərsin); detal yalnız adminə, qalanlara ümumi mesaj
register_shutdown_function(function () use ($me) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        error_log('jarvis.php fatal: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        $detail = ($me['role'] ?? '') === 'admin' ? ': ' . mb_substr($e['message'], 0, 200) . ' (' . basename($e['file']) . ':' . $e['line'] . ')' : '';
        echo json_encode(['error' => 'Server xətası (PHP)' . $detail], JSON_UNESCAPED_UNICODE);
    }
});
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
    // A: said_at (ms, istifadəçinin dediyi an; gecikmə ölçüsü), model, mode, kind (prompt | think)
    $saidAt = is_numeric($in['said_at'] ?? null) ? (int)$in['said_at'] : 0;
    $saidAt = abs($saidAt - (int)floor(microtime(true) * 1000)) < 86400000 ? $saidAt : 0;
    $model = in_array($in['model'] ?? '', ['', 'opus', 'sonnet', 'haiku'], true) ? (string)($in['model'] ?? '') : '';
    $cmode = in_array($in['mode'] ?? '', ['', 'plan'], true) ? (string)($in['mode'] ?? '') : '';
    $kind = in_array($in['kind'] ?? '', ['prompt', 'think'], true) ? (string)$in['kind'] : 'prompt';
    $pdo->prepare("INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, kind, model, mode, said_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$me['name'], $project, $prompt, 'queued', '', $now, 0, 0, $kind, $model, $cmode, $saidAt]);
    $id = (int)$pdo->lastInsertId();
    emit_event($pdo, 'command.queued', $me['name'], $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr($prompt, 0, 300), 'kind' => $kind]);
    json_out(['ok' => true, 'id' => $id, 'agent_online' => $now - (int)$a['last_seen'] < 30, 'claude_login' => (bool)$a['claude_ok'], 'allowed' => $allowed]);
}

/* ---------- Yaddaş: Jarvis-in istifadəçi haqqında saxladığı qısa qeydlər (sessiyalar arası) ---------- */
// Yaddaş v2: növ/proyekt note-un əvvəlində prefiks kimi saxlanır (sxem dəyişmir): "[summary] …", "[project:neo] …", qalanı istifadəçi faktı.
const MEMORY_SUMMARY_MAX = 20;           // sessiya xülasələri (14 gündən köhnələri silinir)
/** İstifadəçinin bütün qeydləri (yeni → köhnə), növünə görə: ['facts' => [], 'project' => [ad => []], 'summary' => []] */
function memory_split(PDO $pdo, string $user): array
{
    memory_table($pdo);
    $st = $pdo->prepare('SELECT id, note, created_at FROM jarvis_memory WHERE user = ? ORDER BY created_at DESC LIMIT 200');
    $st->execute([$user]);
    $out = ['facts' => [], 'project' => [], 'summary' => [], 'rows' => []];
    foreach ($st->fetchAll() as $r) {
        $out['rows'][] = $r;
        if (str_starts_with($r['note'], '[summary] ')) {
            $out['summary'][] = $r + ['text' => substr($r['note'], 10)];
        } elseif (preg_match('/^\[project:([^\]]{1,64})\] (.*)$/su', $r['note'], $m)) {
            $out['project'][$m[1]][] = $r + ['text' => $m[2]];
        } else {
            $out['facts'][] = $r + ['text' => $r['note']];
        }
    }
    return $out;
}
if ($act === 'memory_add') {
    $note = trim(preg_replace('/\s+/u', ' ', (string)($in['note'] ?? '')));
    if ($note === '') {
        json_out(['error' => 'qeyd boşdur'], 400);
    }
    $kind = (string)($in['kind'] ?? '') === 'summary' ? 'summary' : 'fact';
    $proj = mb_substr(trim(str_replace([']', '['], '', (string)($in['project'] ?? ''))), 0, 60);
    $note = $kind === 'summary' ? '[summary] ' . date('Y-m-d H:i', $now) . ' · ' . mb_substr($note, 0, 360)
        : ($proj !== '' ? "[project:$proj] " . mb_substr($note, 0, 300) : mb_substr($note, 0, 300));
    memory_table($pdo);
    $id = substr(md5(mb_strtolower($note)), 0, 12);
    $pdo->prepare('DELETE FROM jarvis_memory WHERE user = ? AND id = ?')->execute([$me['name'], $id]);   // eyni qeyd təkrarlanmasın, təzələnsin
    $pdo->prepare('INSERT INTO jarvis_memory (user, id, note, created_at) VALUES (?,?,?,?)')->execute([$me['name'], $id, $note, $now]);
    $m = memory_split($pdo, $me['name']);         // limitdən artıq köhnələri sil: faktlar MEMORY_MAX, xülasələr ayrıca
    $facts = array_merge($m['facts'], ...array_values($m['project'] ?: [[]]));
    usort($facts, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    $drop = array_slice($facts, MEMORY_MAX);
    foreach ($m['summary'] as $i => $s) {
        if ($i >= MEMORY_SUMMARY_MAX || $s['created_at'] < $now - 14 * 86400) {
            $drop[] = $s;
        }
    }
    foreach ($drop as $r) {
        $pdo->prepare('DELETE FROM jarvis_memory WHERE user = ? AND id = ?')->execute([$me['name'], $r['id']]);
    }
    json_out(['ok' => true, 'kind' => $kind, 'count' => count($facts) - count(array_filter($drop, fn($r) => !str_starts_with($r['note'], '[summary] ')))]);
}
if ($act === 'memory_forget') {          // yalnız istifadəçinin öz qeydləri; brauzer əvvəl təsdiq alır
    $q = mb_strtolower(trim((string)($in['query'] ?? '')));
    if (mb_strlen($q) < 2) {
        json_out(['error' => 'nəyi unutmaq lazım olduğunu yaz'], 400);
    }
    $del = 0;
    foreach (memory_split($pdo, $me['name'])['rows'] as $n) {
        if (str_contains(mb_strtolower($n['note']), $q)) {
            $pdo->prepare('DELETE FROM jarvis_memory WHERE user = ? AND id = ?')->execute([$me['name'], $n['id']]);
            $del++;
        }
    }
    json_out(['ok' => true, 'deleted' => $del]);
}

/* ---------- think: dərin düşünmə (kind=think, Opus, plan rejimi: heç nə dəyişmir). Yoxlamalar agent_send ilə eynidir ---------- */
if ($act === 'think') {
    $q = trim((string)($in['question'] ?? ''));
    $ctx = mb_substr(trim((string)($in['context'] ?? '')), 0, 1200);
    if ($q === '' || mb_strlen($q) > 2000) {
        json_out(['error' => 'sual 1-2000 simvol olmalıdır'], 400);
    }
    $ag = $pdo->prepare('SELECT last_seen, claude_ok, projects FROM agents WHERE user = ?');
    $ag->execute([$me['name']]);
    $a = $ag->fetch();
    if (!$a) {
        json_out(['error' => 'Bu istifadəçinin Claude Code agenti hələ heç qoşulmayıb. Kompüterdə agenti işə sal (📦 Paket → BASLA.md).'], 409);
    }
    $allowed = array_values(array_filter(explode("\n", (string)$a['projects']), 'strlen'));
    $project = trim((string)($in['project'] ?? ''));
    if ($project === '' && $allowed) {           // proyekt deyilməyibsə: agentin ilk qovluğu (yalnız oxu konteksti)
        $project = $allowed[0];
    }
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'proyekt adı lazımdır'], 400);
    }
    if ($allowed && !array_intersect(project_names($pdo, $project), $allowed)) {
        json_out(['error' => "Claude Code-un bu kompüterdə \"$project\" üçün qovluğu yoxdur. İcazəli proyektlər: " . implode(', ', $allowed) . '.', 'allowed' => $allowed], 409);
    }
    if (agent_queue_len($pdo, $me['name']) >= AGENT_QUEUE_MAX) {
        json_out(['error' => 'növbədə artıq ' . AGENT_QUEUE_MAX . ' tapşırıq var, gözlə'], 429);
    }
    // köhnə agent kind/mode-u tanımasa da prompt özü yalnız-oxu tələb edir
    $prompt = "READ-ONLY THINKING TASK. Do not modify, create or delete any files, do not run commands that change anything, do not commit. "
        . "Read code if useful, reason carefully, then answer concisely: first a 2-3 sentence conclusion, then key points / options with trade-offs (max ~25 lines).\n\n"
        . "Question from {$me['name']} (via Jarvis voice): {$q}" . ($ctx !== '' ? "\n\nContext (dashboard state, may be partial):\n{$ctx}" : '');
    $saidAt = is_numeric($in['said_at'] ?? null) ? (int)$in['said_at'] : 0;
    $saidAt = abs($saidAt - (int)floor(microtime(true) * 1000)) < 86400000 ? $saidAt : 0;
    $pdo->prepare("INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, kind, model, mode, said_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$me['name'], $project, mb_substr($prompt, 0, 4000), 'queued', '', $now, 0, 0, 'think', 'opus', 'plan', $saidAt]);
    $id = (int)$pdo->lastInsertId();
    emit_event($pdo, 'command.queued', $me['name'], $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr($q, 0, 300), 'kind' => 'think']);
    json_out(['ok' => true, 'id' => $id, 'project' => $project, 'agent_online' => $now - (int)$a['last_seen'] < 30]);
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
    ['name' => 'run_claude_code', 'description' => "Give a work task to Claude Code, the AI coding agent connected to this dashboard, which runs on the user's computer inside the project folder. Use it for ANY real work: writing or changing code, fixing bugs, adding tests, reviewing code, analysing the repository, explaining how something works in the code, writing docs. It runs in the background; you will get a note when it finishes. The prompt is sent silently: NEVER read it, quote it or paraphrase it aloud — just say a few words like 'Göndərirəm' and call the function. It can only work in projects that have a folder on the user's computer (see agents[].projects in get_overview); Studio planets such as Designer are not code folders.",
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('project name; omit to use the planet the user is looking at'), 'prompt' => $S('complete, self-contained instruction for Claude Code in English or Azerbaijani: goal, relevant details from the conversation, and what to report back'), 'confirmed' => $confirm], 'required' => ['prompt', 'confirmed']]],
    ['name' => 'get_claude_tasks', 'description' => 'Recent Claude Code tasks of the current user with status (queued/running/done/failed) and results.',
        'parameters' => ['type' => 'object', 'properties' => ['project' => $S('optional project filter')]]],
    ['name' => 'end_conversation', 'description' => 'End the voice session when the user says goodbye or asks Jarvis to stop listening.',
        'parameters' => ['type' => 'object', 'properties' => (object)[]]],
];

// Modullar (jarvis_modules/*.php): hər biri ['tools' => [...JSON Schema alətləri], 'prompt' => 'sistem təlimatına əlavə'] qaytarır.
// Alətlərin icrası brauzerdə, uyğun jarvis-*.js modulundadır (Jarvis.registerTool). Hər modul faylı "defined('JARVIS_MODULE') || exit;" ilə başlayır.
// Təsdiq səviyyələri (tier): g = təsdiqsiz, y = dərhal et + elan et (brauzerdə 2.5 s "Ləğv et" çipi), r = açıq təsdiq (confirmed=true).
// Qruplar (group): core həmişə yüklənir; qalanları config jarvis_groups_default ilə və ya sessiya ərzində load_tools ilə.
$coreTier = ['create_task' => 'y', 'set_task_status' => 'y', 'set_task_due' => 'y', 'set_ship_deadline' => 'y', 'schedule_meeting' => 'y',
    'record_test_result' => 'y', 'send_message' => 'y', 'run_claude_code' => 'y'];
foreach ($tools as &$t) {
    $t += ['tier' => $coreTier[$t['name']] ?? 'g', 'group' => 'core'];
}
unset($t);
define('JARVIS_MODULE', 1);
$mods = [];                                  // hər modul: alət adları + prompt
$groupDesc = [];
$metaFb = [];                                // tier/group yazmayan modulların alətləri üçün ehtiyat (brain.php 'meta')
foreach (glob(__DIR__ . '/jarvis_modules/*.php') ?: [] as $mf) {
    $mod = require $mf;
    if (!is_array($mod)) {
        continue;
    }
    $names = [];
    foreach (array_values($mod['tools'] ?? []) as $t) {
        $t['_mod'] = basename($mf, '.php');
        $tools[] = $t;
        $names[] = $t['name'];
    }
    $groupDesc += (array)($mod['groups'] ?? []);
    $metaFb += (array)($mod['meta'] ?? []);
    $mods[] = ['names' => $names, 'prompt' => trim((string)($mod['prompt'] ?? ''))];
}
$tiers = [];
$groupOf = [];
$decl = [];                                  // qrup → ad → modelə gedən təmiz deklarasiya (tier/group/_mod yoxdur)
foreach ($tools as $t) {
    $n = (string)$t['name'];
    $fb = $metaFb[$n] ?? [];
    $p = $t['parameters'] ?? [];
    $hasConf = is_array($p['properties'] ?? null) && isset($p['properties']['confirmed']);
    $tier = $t['tier'] ?? $fb['tier'] ?? ($hasConf ? 'r' : 'g');      // naməlum + confirmed → ehtiyatla r
    $tier = in_array($tier, ['g', 'y', 'r'], true) ? $tier : 'r';
    $grp = (string)($t['group'] ?? $fb['group'] ?? ($t['_mod'] ?? 'core'));
    unset($t['tier'], $t['group'], $t['_mod']);
    if ($tier === 'r') {                     // confirmed yalnız r alətlərində qalır
        if (!is_array($p['properties'] ?? null)) {
            $p['properties'] = [];
        }
        $p['type'] = 'object';
        $p['properties']['confirmed'] = $confirm;
        $p['required'] = array_values(array_unique(array_merge($p['required'] ?? [], ['confirmed'])));
    } elseif ($hasConf) {
        unset($p['properties']['confirmed']);
        $p['required'] = array_values(array_diff($p['required'] ?? [], ['confirmed']));
        if (!$p['required']) {
            unset($p['required']);
        }
        if (!$p['properties']) {
            $p['properties'] = (object)[];
        }
    }
    $t['parameters'] = $p ?: ['type' => 'object', 'properties' => (object)[]];
    $tiers[$n] = $tier;
    $groupOf[$n] = $grp;
    $decl[$grp][$n] = $t;
}
$gd = $config['jarvis_groups_default'] ?? ['core', 'hud', 'ui', 'claude'];
$loaded = array_values(array_intersect(array_unique(array_merge(['core'], is_array($gd) ? $gd : array_map('trim', explode(',', (string)$gd)))), array_keys($decl)));
$active = [];
foreach ($loaded as $g) {
    $active = array_merge($active, array_values($decl[$g]));
}
// Modul təlimatı: aləti yüklənən qrupdadırsa (və ya aləti yoxdursa) indi; yoxsa load_tools cavabında (group_prompts)
$modPrompt = '';
$groupPrompts = [];
foreach ($mods as $m) {
    if ($m['prompt'] === '') {
        continue;
    }
    $gs = array_values(array_unique(array_map(fn($n) => $groupOf[$n], $m['names'])));
    if (!$gs || array_intersect($gs, $loaded)) {
        $modPrompt .= "\n" . $m['prompt'];
    } else {
        foreach ($gs as $g) {
            $groupPrompts[$g] = trim(($groupPrompts[$g] ?? '') . "\n" . $m['prompt']);
        }
    }
}
$groupLines = [];
foreach ($decl as $g => $ts) {
    $groupLines[] = "- {$g}" . (in_array($g, $loaded, true) ? ' (loaded)' : '') . ': ' . ($groupDesc[$g] ?? '') . ' [' . implode(', ', array_slice(array_keys($ts), 0, 16)) . ']';
}
$groupList = implode("\n", $groupLines);

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
You are JARVIS, the voice assistant of the "Komanda Claude" team dashboard, but you talk like a close friend of the team, not like a butler or a robot. You talk with {$who} (role: {$role}).

Personality and tone (very important):
- Speak like a close friend: warm, relaxed, informal, a bit playful. Use the person's first name sometimes. Never sound formal, stiff, bureaucratic or like a customer-support bot. No "Yes, sir", no "How may I assist you".
- In Azerbaijani use the casual "sən" form and everyday street-friendly speech. Greeting-style small talk gets a natural friendly answer first, then continue. Examples of the vibe (do not repeat them word for word, vary naturally):
  "Jarvis, necəsən, vəziyyət nətərdi?" -> "Sağ ol, salamatçılıqdır, sən necəsən? İş-güc necədi?"
  "Nə var nə yox?" -> "Heç nə, hamısı yerindədi, səndə nə var?"
  "Sabah saat 3-də görüş qoy" -> "Oldu, sabah 3-ə görüş qoyuram." (then call the tool right away)
  Success: "Əla, hazırdı!", "Oldu, bitdi.", "Tamamdı." Problem: "Ay, bir problem çıxdı...", "Yox, alınmadı, bax belə oldu...".
- React naturally to what the person says (surprise, congratulations, sympathy, a light joke). Short filler words are fine ("hə", "bəli", "yaxşı", "bax", "di gəl").
- Match the user's language and energy; if they are casual, be casual. If they switch to Russian or English, stay equally friendly and informal in that language.
Now: {$nowStr} ({$tz}), weekday {$weekday}. Team members: {$team}.

{$langLine}

The dashboard is a 3D universe: projects are planets in the "Komanda" universe; "Studio" holds Prompt Generator, Designer, Meeting Planet and Test Planet; "MCP Universe" shows MCP servers; "Agent Universe" shows each user's Claude Code agent. A planet burns when it has open tasks and nothing was finished for 24 hours. Each user has a spaceship with an optional deadline.

Rules:
- Tools: ALWAYS call them through function calling. NEVER write a tool call as text (no <tool_call>, <arg_key>, JSON or code in your words). Say one short sentence, then call the function.
- Keep spoken answers short and conversational, like talking to a friend: 1-3 sentences, no lists read out loud unless asked; round numbers; say times naturally.
- Never invent data: call get_overview / get_project / list_tasks / get_report first, then answer from the result.
- Navigation and questions need no confirmation: just do them.
- Any request to actually DO work in a project (code, bugs, tests, review, analysis of the code, docs) goes to Claude Code via run_claude_code. Write it a clear, complete prompt, but put the prompt ONLY into the function argument: never speak, read out or summarise the prompt text (the user does not need to hear it). Say at most one very short sentence ("Oldu, Claude Code-a göndərirəm"), call the function, and do not try to do the work yourself. Afterwards tell the user in a few words it was sent and that you will report when it is done. When you receive a note that a Claude Code task finished, summarise the result in 1-2 spoken sentences (mention failures plainly).
- If the agent is offline, say the task is queued and will start when the agent on their computer runs.
- Speed matters: act in the same turn. Reads, navigation and panels: just do them silently. Normal changes (tasks, statuses, deadlines, meetings, tests, messages, Claude Code tasks, dispatch): do NOT ask "Edim?"; say in a few words what you are doing ("Tapşırığı yaradıram") and call the tool immediately; the user gets a 2-second cancel chip and can say "ləğv et". If a tool result says canceled, just acknowledge it.
- Only tools that have a "confirmed" parameter are dangerous (deploy, merge, delete, settings, mass runs): say in one short sentence exactly what will happen, ask "Edim?", and call with confirmed=true only after the user clearly agrees. Never set confirmed=true on your own.
- Tool groups: core tools are always available; other groups load on demand. If you need a tool that you do not have, call load_tools {group} first (fast, no confirmation), then use it in the same turn. Groups:
{$groupList}
- Hard questions (architecture, trade-offs, debugging strategy, "what should we do about X", analysis that needs deep reasoning or reading code) → call think {question, project?}; say only "Düşünürəm…" and continue; the answer arrives later as a "[Think result]" note: summarise it in at most 3 sentences and offer the details as a panel (show_panel).
- Current events, news, public facts, docs, prices, people outside the team → use your built-in web_search / x_search tools if available; say the source briefly.
- Lines starting with [Situation] are silent context updates (running/queued Claude Code tasks, pending approvals, overdue tasks, burning planets, failures); never answer them directly, but use them and mention something urgent when it is relevant.
- You cannot delete anything except through tools that explicitly allow it (with confirmation); say so if asked.
- If a tool returns an error, explain it briefly and suggest the fix.
- Resolve relative dates ("sabah saat 3-də", "next Friday") into local ISO date-times yourself.
- When the user says goodbye or asks you to stop, say a short farewell and call end_conversation.
{$modPrompt}
TXT;

$out = ['provider' => $provider, 'lang' => $lang, 'used' => $used, 'limit' => $limitSec,
    // brauzer üçün: tier xəritəsi (jv-tiers ilə üstələnə bilər), qruplar, bütün deklarasiyalar (load_tools), gec yüklənən qrup təlimatları
    'tiers' => $tiers, 'loaded' => $loaded, 'group_prompts' => (object)$groupPrompts,
    'groups' => (object)array_map(fn($g) => ['desc' => $groupDesc[$g] ?? '', 'tools' => array_keys($decl[$g])], array_combine(array_keys($decl), array_keys($decl))),
    'all_tools' => (object)array_map('array_values', $decl)];

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
            'reasoning' => ['effort' => 'none'],                                    // səs agenti üçün sürət: düşünmə yox (dərin suallar → think aləti)
            'turn_detection' => ['type' => 'server_vad', 'silence_duration_ms' => 450],
            'audio' => [
                'input' => ['format' => $fmt, 'transcription' => ['language_hint' => $lang]],
                'output' => ['format' => $fmt],
            ],
            // daxili axtarış alətləri (xAI serverində icra olunur); rədd edilsə jarvis.js onlarsız təkrar session.update göndərir
            'tools' => array_merge(array_map(fn($t) => ['type' => 'function'] + $t, $active),
                ($config['jarvis_web_search'] ?? true) ? [['type' => 'web_search'], ['type' => 'x_search']] : []),
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
    'tools' => [['functionDeclarations' => array_map('gemini_schema', $active)]],
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
