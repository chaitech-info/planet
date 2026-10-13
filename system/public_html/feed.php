<?php
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
session_write_close();

$pdo = db();
$now = time();

// "Saytdadır": səhifə açıq və görünən olduqca hər sorğu ürək döyüntüsüdür
$pr = $pdo->prepare('UPDATE presence SET last_seen = ? WHERE user = ?');
$pr->execute([$now, $me['name']]);
if ($pr->rowCount() === 0) {
    $chk = $pdo->prepare('SELECT 1 FROM presence WHERE user = ?');
    $chk->execute([$me['name']]);
    if (!$chk->fetchColumn()) {
        $pdo->prepare('INSERT INTO presence (user, last_seen) VALUES (?, ?)')->execute([$me['name'], $now]);
    }
}

$sessions = $pdo->prepare('SELECT user, session_id, project, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at
    FROM sessions WHERE updated_at > ? ORDER BY updated_at DESC LIMIT 100');
$sessions->execute([$now - 86400]);

$onlyProject = isset($_GET['project']) ? substr((string)$_GET['project'], 0, 128) : '';
if ($onlyProject !== '') {
    $ev = $pdo->prepare('SELECT id, user, session_id, project, event, tool, summary, created_at
        FROM events WHERE project = ? ORDER BY id DESC LIMIT 60');
    $ev->execute([$onlyProject]);
    $events = $ev->fetchAll();
} else {
    $events = $pdo->query('SELECT id, user, session_id, project, event, tool, summary, created_at
        FROM events ORDER BY id DESC LIMIT 80')->fetchAll();
}

$projects = $pdo->prepare('SELECT project, user, COUNT(*) AS sessions, SUM(edits) AS edits, SUM(tool_count) AS tools, MAX(updated_at) AS last_at
    FROM sessions WHERE updated_at > ? GROUP BY project, user');
$projects->execute([$now - 30 * 86400]);

$cmds = $pdo->query('SELECT id, user, project, prompt, status, result, created_at, started_at, finished_at
    FROM commands ORDER BY id DESC LIMIT 40')->fetchAll();
foreach ($cmds as &$c) {
    $c['result'] = mb_substr($c['result'], 0, 3000);
}
unset($c);
$agents = $pdo->query('SELECT user, last_seen, claude_ok FROM agents')->fetchAll();

$tk = $pdo->prepare("SELECT id, project, title, details, assignee, creator, status, command_id, created_at, updated_at, done_at
    FROM tasks WHERE status != 'done' OR updated_at > ? ORDER BY updated_at DESC LIMIT 300");
$tk->execute([$now - 14 * 86400]);

$al = $pdo->prepare('SELECT id, project, file, user_a, user_b, created_at FROM alerts WHERE created_at > ? ORDER BY id DESC LIMIT 20');
$al->execute([$now - 1800]);

// Mesajlar: raket hamıya görünür, mətn yalnız göndərənə və alana (hamıya yazılanı hamı oxuyur)
$mq = $pdo->prepare('SELECT id, from_user, to_user, body, created_at FROM messages WHERE created_at > ? ORDER BY id DESC LIMIT 40');
$mq->execute([$now - 86400]);
$msgs = [];
foreach ($mq->fetchAll() as $m) {
    if ($m['to_user'] !== '*' && $m['to_user'] !== $me['name'] && $m['from_user'] !== $me['name']) {
        $m['body'] = '';
    }
    $msgs[] = $m;
}

// Planlar və scope-lar (bitmişlər 14 gün qalır)
$pl = $pdo->prepare("SELECT id, project, kind, title, details, status, creator, created_at, updated_at FROM plans
    WHERE status != 'done' OR updated_at > ? ORDER BY id ASC LIMIT 600");
$pl->execute([$now - 14 * 86400]);

// İstifadəçi musiqiləri: öz yüklədiyi, yoxdursa köhnə standart media/<ad>.mp3
$sounds = [];
foreach ($pdo->query('SELECT name FROM users')->fetchAll() as $u) {
    $n = $u['name'];
    $cand = ['media/u/' . substr(hash('sha256', $n), 0, 16) . '.mp3', 'media/' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $n)) . '.mp3'];
    foreach ($cand as $rel) {
        if ($rel !== 'media/.mp3' && is_file(__DIR__ . '/' . $rel)) {
            $sounds[$n] = $rel . '?v=' . (int)filemtime(__DIR__ . '/' . $rel);
            break;
        }
    }
}

json_out([
    'now' => $now,
    'sounds' => (object)$sounds,
    'messages' => $msgs,
    'plans' => $pl->fetchAll(),
    'presence' => $pdo->query('SELECT user, last_seen FROM presence')->fetchAll(),
    'alerts' => $al->fetchAll(),
    'tasks' => $tk->fetchAll(),
    'commands' => $cmds,
    'agents' => $agents,
    'me' => $me,
    'users' => array_column($pdo->query('SELECT name FROM users ORDER BY name')->fetchAll(), 'name'),
    'sessions' => $sessions->fetchAll(),
    'projects' => $projects->fetchAll(),
    'events' => $events,
]);
