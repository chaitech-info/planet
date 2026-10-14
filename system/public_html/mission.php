<?php
// Jarvis missiyaları, protokollar və paralel Claude Code göndərişi. Brauzer (jarvis-missions.js) giriş sessiyası ilə çağırır.
// Missiyaları yalnız sahibi görür/idarə edir; protokollar: paylaşılanlar hamıya görünür, dəyişmək yalnız sahibinə və adminə.
// Missiya vəziyyət maşını və yarış qaydaları: mission_lib.php.
require __DIR__ . '/db.php';
require_once __DIR__ . '/mission_lib.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
deny_student($me);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 80000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$user = $me['name'];
$isAdmin = $me['role'] === 'admin';

$str = function (string $k, int $max, string $label, bool $req = false) use ($in): string {
    $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($in[$k] ?? '')));
    if ($req && $v === '') {
        json_out(['error' => "$label lazımdır"], 400);
    }
    if (mb_strlen($v) > $max) {
        json_out(['error' => "$label çox uzundur ($max simvola qədər)"], 400);
    }
    return $v;
};
/** Missiya: id verilibsə o, yoxsa istifadəçinin uyğun statuslu sonuncusu */
$pick = function (array $statuses) use ($in, $pdo, $user): array {
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $m = mission_row($pdo, $id, $user);
    } else {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $st = $pdo->prepare("SELECT * FROM jarvis_missions WHERE user = ? AND status IN ($ph) ORDER BY id DESC LIMIT 1");
        $st->execute(array_merge([$user], $statuses));
        $m = $st->fetch() ?: null;
    }
    if (!$m) {
        json_out(['error' => $id ? "missiya #$id tapılmadı" : 'uyğun missiya yoxdur'], 404);
    }
    return $m;
};
$agentInfo = function () use ($pdo, $user): array {
    $a = agent_row($pdo, $user);
    return ['agent_online' => $a && time() - (int)$a['last_seen'] < 30, 'parallel_workers' => agent_parallel($a),
        'concurrency' => agent_parallel($a) > 1
            ? 'agent runs up to ' . agent_parallel($a) . ' tasks at once (same project → separate git worktree)'
            : 'one agent per computer: tasks run in queue order, one after another'];
};

/* ---------- Paralel göndəriş: bir neçə Claude Code tapşırığı birdən ---------- */
if ($act === 'dispatch') {
    $tasks = $in['tasks'] ?? null;
    if (!is_array($tasks) || !$tasks || count($tasks) > MISSION_MAX_STEPS) {
        json_out(['error' => 'tapşırıqlar 1-' . MISSION_MAX_STEPS . ' olmalıdır'], 400);
    }
    $out = [];
    $okN = 0;
    foreach (array_values($tasks) as $i => $t) {
        $project = trim((string)($t['project'] ?? ''));
        $title = mb_substr(trim((string)($t['title'] ?? '')), 0, 120);
        $r = is_array($t) ? queue_agent_command($pdo, $user, $project, (string)($t['prompt'] ?? ''), ['fresh' => 1]) : ['error' => 'yanlış format'];
        $okN += !empty($r['ok']);
        $out[] = ['index' => $i, 'project' => $project, 'title' => $title] + (!empty($r['ok']) ? ['ok' => true, 'id' => $r['id']] : ['ok' => false, 'error' => $r['error']]);
    }
    json_out(['ok' => $okN > 0, 'queued' => $okN, 'failed' => count($out) - $okN, 'items' => $out] + $agentInfo(), $okN > 0 ? 200 : 409);
}

/* ---------- Missiyalar ---------- */
if ($act === 'plan') {                       // qaralama: heç nə işə düşmür
    $title = $str('title', 200, 'başlıq', true);
    $goal = $str('goal', 2000, 'hədəf');
    [$steps, $err] = mission_clean_steps($in['steps'] ?? null);
    if ($err) {
        json_out(['error' => $err], 400);
    }
    $warn = [];
    foreach ($steps as $i => $s) {           // indidən xəbər ver: hansı addım agentdə işləməyəcək
        $chk = agent_check($pdo, $user, $s['project']);
        if (empty($chk['ok'])) {
            $warn[] = ['index' => $i, 'error' => $chk['error']];
        }
    }
    $id = mission_create($pdo, $user, $title, $goal, $steps);
    json_out(['ok' => true, 'mission' => mission_view($pdo, mission_row($pdo, $id)), 'warnings' => $warn] + $agentInfo());
}

if ($act === 'start') {
    $m = $pick(['draft']);
    if ($m['status'] !== 'draft') {
        json_out(['error' => "missiya #{$m['id']} artıq " . $m['status'] . ' vəziyyətindədir'], 409);
    }
    $r = mission_advance($pdo, (int)$m['id'], true);
    $mm = $r['mission'] ?: $m;
    json_out(['ok' => $mm['status'] !== 'draft', 'mission' => mission_view($pdo, $mm), 'launched' => $r['launched'],
        'errors' => array_map(fn($i, $e) => ['index' => $i, 'error' => $e], array_keys($r['errors']), $r['errors'])] + $agentInfo());
}

if ($act === 'status') {
    $m = $pick(['running', 'draft', 'done', 'failed', 'canceled']);
    if ($m['status'] === 'running') {
        $m = mission_advance($pdo, (int)$m['id'])['mission'] ?: $m;
    }
    json_out(['mission' => mission_view($pdo, $m, true)]);
}

if ($act === 'cancel') {
    $m = $pick(['running', 'draft']);
    $r = mission_cancel($pdo, (int)$m['id'], $user);
    if (!$r['ok']) {
        json_out(['error' => "missiya #{$m['id']} ləğv edilə bilmədi (status: " . ($r['mission']['status'] ?? '?') . ')', 'mission' => $r['mission'] ? mission_view($pdo, $r['mission']) : null], 409);
    }
    json_out(['ok' => true, 'canceled_commands' => $r['canceled_commands'], 'mission' => mission_view($pdo, $r['mission'])]);
}

if ($act === 'list') {
    $st = $pdo->prepare('SELECT * FROM jarvis_missions WHERE user = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$user]);
    json_out(['missions' => array_map(fn($m) => mission_view($pdo, $m), $st->fetchAll())]);
}

// Brauzer ~3 saniyədən bir: işləyən missiyaları irəlilədir; son 15 dəqiqədə bitənləri də qaytarır (yekun səsli xülasə üçün)
if ($act === 'tick') {
    $st = $pdo->prepare("SELECT id FROM jarvis_missions WHERE user = ? AND status = 'running' ORDER BY id DESC LIMIT 20");
    $st->execute([$user]);
    $finishedNow = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $mid) {
        if (mission_advance($pdo, (int)$mid)['finished']) {
            $finishedNow[] = (int)$mid;
        }
    }
    $st = $pdo->prepare("SELECT * FROM jarvis_missions WHERE user = ? AND (status = 'running' OR (status IN ('done','failed','canceled') AND updated_at > ?)) ORDER BY id DESC LIMIT 20");
    $st->execute([$user, $now - 900]);
    $ms = array_map(fn($m) => mission_view($pdo, $m, $m['status'] !== 'running'), $st->fetchAll());
    json_out(['missions' => $ms, 'finished_now' => $finishedNow, 'now' => $now]);
}

/* ---------- Protokollar ---------- */
$protoView = fn(array $p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'descr' => $p['descr'], 'owner' => $p['owner'], 'shared' => (bool)(int)$p['shared'],
    'builtin' => $p['owner'] === 'system', 'can_edit' => $p['owner'] === $user || $isAdmin, 'steps' => json_decode((string)$p['steps'], true) ?: []];

if ($act === 'protocols') {
    json_out(['protocols' => array_map($protoView, protocol_list($pdo, $user))]);
}

if ($act === 'protocol_save') {               // ad üzrə: öz (və ya admin üçün istənilən) protokolu yenilə, yoxsa yenisini yarat
    $name = $str('name', 100, 'ad', true);
    $descr = $str('descr', 1000, 'təsvir');
    [$steps, $err] = protocol_clean_steps($in['steps'] ?? null);
    if ($err) {
        json_out(['error' => $err], 400);
    }
    $shared = array_key_exists('shared', $in) ? (int)(bool)$in['shared'] : 1;
    $id = (int)($in['id'] ?? 0);
    $ex = $id ? protocol_find($pdo, $user, '', $id) : null;
    if (!$id) {
        $q = $pdo->prepare('SELECT * FROM jarvis_protocols WHERE LOWER(name) = LOWER(?) AND (owner = ? OR shared = 1) ORDER BY CASE WHEN owner = ? THEN 0 ELSE 1 END LIMIT 1');
        $q->execute([$name, $user, $user]);
        $ex = $q->fetch() ?: null;
        if ($ex && $ex['owner'] !== $user && !$isAdmin) {
            json_out(['error' => "\"{$ex['name']}\" protokolu {$ex['owner']}-a məxsusdur: başqa ad seç"], 403);
        }
    } elseif (!$ex) {
        json_out(['error' => 'protokol tapılmadı'], 404);
    } elseif ($ex['owner'] !== $user && !$isAdmin) {
        json_out(['error' => 'bu protokolu yalnız sahibi və admin dəyişə bilər'], 403);
    }
    $js = json_encode($steps, JSON_UNESCAPED_UNICODE);
    if ($ex) {
        $pdo->prepare('UPDATE jarvis_protocols SET name = ?, descr = ?, steps = ?, shared = ?, updated_at = ? WHERE id = ?')->execute([$name, $descr, $js, $shared, $now, $ex['id']]);
        $pid = (int)$ex['id'];
    } else {
        $c = $pdo->prepare('SELECT COUNT(*) FROM jarvis_protocols WHERE owner = ?');
        $c->execute([$user]);
        if ((int)$c->fetchColumn() >= 30) {
            json_out(['error' => 'ən çox 30 protokol'], 429);
        }
        $pdo->prepare('INSERT INTO jarvis_protocols (owner, name, descr, steps, shared, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')->execute([$user, $name, $descr, $js, $shared, $now, $now]);
        $pid = (int)$pdo->lastInsertId();
    }
    json_out(['ok' => true, 'protocol' => $protoView(protocol_find($pdo, $user, '', $pid)), 'updated' => (bool)$ex]);
}

if ($act === 'protocol_delete') {
    $p = protocol_find($pdo, $user, '', (int)($in['id'] ?? 0));
    if (!$p) {
        json_out(['error' => 'protokol tapılmadı'], 404);
    }
    if ($p['owner'] !== $user && !$isAdmin) {
        json_out(['error' => 'bu protokolu yalnız sahibi və admin silə bilər'], 403);
    }
    $pdo->prepare('DELETE FROM jarvis_protocols WHERE id = ?')->execute([$p['id']]);
    json_out(['ok' => true]);
}

if ($act === 'run_protocol') {                // protokol → missiya (yaradılır və dərhal başlayır)
    $p = protocol_find($pdo, $user, (string)($in['name'] ?? ''), (int)($in['id'] ?? 0));
    if (!$p) {
        json_out(['error' => 'protokol tapılmadı', 'protocols' => array_column(protocol_list($pdo, $user), 'name')], 404);
    }
    $project = $str('project', 128, 'proyekt');
    [$steps, $err] = protocol_expand($pdo, $user, json_decode((string)$p['steps'], true) ?: [], $project);
    if ($err) {
        json_out(['error' => $err], 400);
    }
    $id = mission_create($pdo, $user, mb_substr($p['name'] . ($project !== '' ? " · $project" : ''), 0, 200), (string)$p['descr'], $steps);
    $r = mission_advance($pdo, $id, true);
    json_out(['ok' => true, 'protocol' => $p['name'], 'mission' => mission_view($pdo, $r['mission']), 'launched' => $r['launched'],
        'errors' => array_map(fn($i, $e) => ['index' => $i, 'error' => $e], array_keys($r['errors']), $r['errors'])] + $agentInfo());
}

json_out(['error' => 'naməlum əməliyyat'], 400);
