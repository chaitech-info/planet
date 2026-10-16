<?php
// Claude Code əməliyyatları (ops): brauzer → istifadəçinin ÖZ agenti. kind = 'ops', prompt = JSON {op, args}, nəticə JSON (agent/claude_ops.py).
// run {op, args, project?} → {id}; get {id} → status + nəticə; list {limit} → son ops-lar. Nəticə həm də RT ops.result (ref = id) ilə gəlir.
// Server op adını allowlist-ə, 🔴 op-ları rola və agentin bildirdiyi profilə görə yoxlayır; agent öz profilinə görə yenidən yoxlayır.
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
$in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();

// op → [minimum profil, proyekt lazımdır, 🔴]. claude_ops.py OPS ilə eyni saxla.
const OPS_ALLOW = [
    'claude_info' => ['basic', false, false], 'mcp_list' => ['basic', true, false], 'config' => ['basic', true, false],
    'usage' => ['basic', false, false], 'transcript' => ['basic', true, false], 'health' => ['basic', true, false],
    'pr_view' => ['basic', true, false], 'worktree_list' => ['basic', true, false],
    'pr_merge' => ['ops', true, true], 'mcp_add' => ['ops', true, true], 'allow_rule' => ['ops', true, true],
    'claude_md_append' => ['dev', true, true], 'claude_update' => ['dev', false, false], 'claude_doctor' => ['dev', false, false],
    'sessions_clean' => ['dev', false, true], 'worktree_new' => ['dev', true, false], 'undo_last' => ['dev', true, true],
];
const OPS_LEVELS = ['basic' => 0, 'dev' => 1, 'ops' => 2, 'full' => 3];
const OPS_RED_ROLES = ['admin', 'member'];          // 🔴: yalnız öz agentinə, studentlər heç vaxt

/** Göstərmək üçün arqumentlər: env/headers dəyərləri və gizli adlı açarlar maskalanır (DB-dəki prompt agent üçündür). */
function ops_args_public(array $args): array
{
    foreach ($args as $k => $v) {
        if (in_array($k, ['env', 'headers'], true) && is_array($v)) {
            $args[$k] = array_fill_keys(array_keys($v), '***');
        } elseif (is_string($v) && preg_match('/token|secret|passw|api_?key|authorization/i', (string)$k)) {
            $args[$k] = '***';
        } elseif ($k === 'url' && is_string($v)) {
            $args[$k] = preg_replace('/\?.*$/s', '?***', $v);
        }
    }
    return $args;
}

/** commands sətri → cavab (prompt-dan op/args, nəticə JSON-dursa massiv). */
function ops_row(array $r, bool $full): array
{
    $spec = json_decode((string)$r['prompt'], true);
    $spec = is_array($spec) ? $spec : [];
    $res = json_decode((string)$r['result'], true);
    if (!is_array($res)) {
        $res = (string)$r['result'] === '' ? null : ['text' => mb_substr((string)$r['result'], 0, 4000)];
    }
    $out = ['id' => (int)$r['id'], 'op' => (string)($spec['op'] ?? ''), 'project' => (string)$r['project'], 'status' => (string)$r['status'],
        'created_at' => (int)$r['created_at'], 'started_at' => (int)$r['started_at'], 'finished_at' => (int)$r['finished_at']];
    if ($res !== null) {
        $out['ok'] = $r['status'] === 'done' && (!array_key_exists('ok', $res) || (bool)$res['ok']);
        if (!$out['ok']) {
            $out['error'] = mb_substr((string)($res['error'] ?? ($r['status'] === 'canceled' ? 'ləğv edildi' : 'alınmadı')), 0, 300);
        }
    }
    if ($full) {
        $out['args'] = ops_args_public(is_array($spec['args'] ?? null) ? $spec['args'] : []);
        $out['result'] = $res;
    }
    return $out;
}

if ($act === 'run') {
    $op = (string)($in['op'] ?? '');
    if (!isset(OPS_ALLOW[$op])) {
        json_out(['error' => 'naməlum və ya icazəsiz əməliyyat', 'allowed' => array_keys(OPS_ALLOW)], 400);
    }
    [$need, $needsProj, $red] = OPS_ALLOW[$op];
    if ($red && !in_array($me['role'], OPS_RED_ROLES, true)) {
        json_out(['error' => 'bu əməliyyat sənin rolun üçün bağlıdır'], 403);
    }
    $args = $in['args'] ?? [];
    if (!is_array($args) || ($args && array_is_list($args))) {
        json_out(['error' => 'args obyekt olmalıdır'], 400);
    }
    $project = trim((string)($in['project'] ?? ($args['project'] ?? '')));
    if (mb_strlen($project) > 128) {
        json_out(['error' => 'proyekt adı yanlışdır'], 400);
    }
    if ($needsProj && $project === '') {
        json_out(['error' => 'proyekt lazımdır'], 400);
    }
    $ag = $pdo->prepare('SELECT last_seen, claude_ok, projects, profile FROM agents WHERE user = ?');
    $ag->execute([$me['name']]);
    $a = $ag->fetch();
    $ag->closeCursor();                                  // yazmadan əvvəl oxuma kursorunu bağla (SQLite kilidi)
    if (!$a) {
        json_out(['error' => 'Claude Code agentin hələ heç qoşulmayıb. Kompüterdə agenti işə sal (📦 Paket → BASLA.md).'], 409);
    }
    $allowed = array_values(array_filter(explode("\n", (string)$a['projects']), 'strlen'));
    if ($project !== '') {
        // panel adı → agentin qovluq adı (alias-lar da); agent proyekti args.project ilə tapır
        $hit = array_values(array_intersect(project_names($pdo, $project), $allowed));
        if (!$hit) {
            json_out(['error' => "Agentin bu kompüterdə \"$project\" üçün qovluğu yoxdur." . ($allowed ? ' İcazəli proyektlər: ' . implode(', ', $allowed) . '.' : ''), 'allowed' => $allowed], 409);
        }
        $args['project'] = $hit[0];
    }
    $prof = strtolower((string)($a['profile'] ?? ''));
    if ($prof === '') {
        json_out(['error' => 'Agent köhnədir: ops əmrlərini dəstəkləmir. Kompüterdə agenti yenilə (📦 Paket).'], 409);
    }
    if (isset(OPS_LEVELS[$prof]) && OPS_LEVELS[$prof] < OPS_LEVELS[$need]) {
        json_out(['error' => "Agent profili \"$prof\" bu əməliyyata icazə vermir (lazım: $need+). Kompüterin sahibi ~/.claude-team-agent.env-də CLAUDE_TEAM_PROFILE ilə dəyişə bilər.", 'profile' => $prof], 403);
    }
    $prompt = json_encode(['op' => $op, 'args' => (object)$args], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($prompt === false || strlen($prompt) > 4000) {
        json_out(['error' => 'arqumentlər çox böyükdür (≤ 4 KB)'], 400);
    }
    if (agent_queue_len($pdo, $me['name']) >= AGENT_QUEUE_MAX) {
        json_out(['error' => 'növbədə artıq ' . AGENT_QUEUE_MAX . ' tapşırıq var, gözlə'], 429);
    }
    $said = max(0, min(PHP_INT_MAX, (int)($in['said_at'] ?? 0)));
    $pdo->prepare("INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, creator, fresh, kind, said_at) VALUES (?,?,?,?,?,?,?,?,'',1,'ops',?)")
        ->execute([$me['name'], $project, $prompt, 'queued', '', $now, 0, 0, $said]);
    $id = (int)$pdo->lastInsertId();
    $pub = json_encode(['op' => $op, 'args' => (object)ops_args_public($args)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    emit_event($pdo, 'command.queued', $me['name'], $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr((string)$pub, 0, 1000), 'kind' => 'ops']);
    json_out(['ok' => true, 'id' => $id, 'op' => $op, 'red' => $red, 'agent_online' => $now - (int)$a['last_seen'] < 30, 'profile' => $prof]);
}

if ($act === 'get') {
    $q = $pdo->prepare("SELECT id, project, prompt, status, result, created_at, started_at, finished_at FROM commands WHERE id = ? AND user = ? AND kind = 'ops'");
    $q->execute([(int)($in['id'] ?? 0), $me['name']]);
    $r = $q->fetch();
    if (!$r) {
        json_out(['error' => 'tapılmadı'], 404);
    }
    json_out(ops_row($r, true));
}

if ($act === 'list') {
    $limit = max(1, min(50, (int)($in['limit'] ?? 20)));
    $q = $pdo->prepare("SELECT id, project, prompt, status, result, created_at, started_at, finished_at FROM commands WHERE user = ? AND kind = 'ops' ORDER BY id DESC LIMIT $limit");
    $q->execute([$me['name']]);
    json_out(['items' => array_map(fn($r) => ops_row($r, false), $q->fetchAll())]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
