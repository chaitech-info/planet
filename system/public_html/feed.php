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

// İstifadəçi musiqisi: öz yüklədiyi, yoxdursa köhnə standart media/<ad>.mp3
function user_sound(string $n): ?string
{
    $cand = ['media/u/' . substr(hash('sha256', $n), 0, 16) . '.mp3', 'media/' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $n)) . '.mp3'];
    foreach ($cand as $rel) {
        if ($rel !== 'media/.mp3' && is_file(__DIR__ . '/' . $rel)) {
            return $rel . '?v=' . (int)filemtime(__DIR__ . '/' . $rel);
        }
    }
    return null;
}

$students = student_names($pdo);

// Student yalnız Student universini görür: studentlərin planetləri, gəmiləri və onlayn olmaları; komandanın heç nəyi
if (is_student($me)) {
    ensure_student_planet($pdo, $me['name']);
    $in =$students ? implode(',', array_fill(0, count($students), '?')) : "''";
    $q = function (string $sql) use ($pdo, $students) {
        $st = $pdo->prepare($sql);
        $st->execute($students);
        return $st->fetchAll();
    };
    $sounds = [];
    foreach ($students as $n) {
        if ($s = user_sound($n)) {
            $sounds[$n] = $s;
        }
    }
    json_out([
        'now' => $now,
        'me' => $me,
        'users' => $students,
        'students' => $students,
        'worlds' => $q("SELECT id, name, owner, surface, hue, ring, size, descr, created_at, updated_at FROM worlds WHERE owner IN ($in) ORDER BY id ASC"),
        'ships' => $q("SELECT user, name, model, color, accent, deadline, mission, updated_at FROM ships WHERE user IN ($in)"),
        'presence' => $q("SELECT user, last_seen FROM presence WHERE user IN ($in)"),
        'sounds' => (object)$sounds,
        'mcp' => [], 'mcp_use' => [], 'activity' => [], 'prompts' => [], 'meetings' => [], 'tests' => [], 'meeting_posts' => [],
        'messages' => [], 'plans' => [], 'alerts' => [], 'tasks' => [], 'commands' => [], 'agents' => [],
        'sessions' => [], 'projects' => [], 'events' => [],
    ]);
}

$sessions = $pdo->prepare('SELECT user, session_id, project, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at
    FROM sessions WHERE updated_at > ? ORDER BY updated_at DESC LIMIT 100');
$sessions->execute([$now - 86400]);

$onlyProject = isset($_GET['project']) ? substr((string)$_GET['project'], 0, 128) : '';
$onlyMcp = isset($_GET['mcp']) ? substr((string)$_GET['mcp'], 0, 64) : '';
if ($onlyMcp !== '') {             // MCP Universe: bu serverin son çağırışları
    $ev = $pdo->prepare("SELECT id, user, session_id, project, event, tool, summary, created_at
        FROM events WHERE tool LIKE ? ESCAPE '\\' ORDER BY id DESC LIMIT 60");
    $ev->execute(['mcp\\_\\_' . addcslashes($onlyMcp, '%_\\') . '\\_\\_%']);
    $events = $ev->fetchAll();
} elseif ($onlyProject !== '') {
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

$cmds = $pdo->query('SELECT id, user, project, prompt, status, result, created_at, started_at, finished_at, images
    FROM commands ORDER BY id DESC LIMIT 40')->fetchAll();
foreach ($cmds as &$c) {
    $c['result'] = mb_substr($c['result'], 0, 3000);
    $c['images'] = array_values(array_filter((array)json_decode((string)$c['images'], true), 'is_string'));
}
unset($c);
$agents = $pdo->query('SELECT user, last_seen, claude_ok, projects FROM agents')->fetchAll();

$tk = $pdo->prepare("SELECT id, project, title, details, assignee, creator, status, command_id, created_at, updated_at, done_at, due_at
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

$sounds = [];
foreach ($pdo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN) as $n) {
    if ($s = user_sound($n)) {
        $sounds[$n] = $s;
    }
}

// 24 saat qaydası üçün: proyekt üzrə son bitmiş tapşırıq və ən köhnə açıq tapşırıq
$activity = $pdo->query("SELECT project,
        MAX(CASE WHEN status = 'done' THEN done_at ELSE 0 END) AS last_done,
        MIN(CASE WHEN status != 'done' THEN created_at ELSE NULL END) AS oldest_open,
        SUM(CASE WHEN status != 'done' THEN 1 ELSE 0 END) AS open
    FROM tasks GROUP BY project")->fetchAll();

// MCP peykləri: hook-dan gələn mcp__server__tool çağırışları (son 30 gün) + əl ilə qeyd olunanlar
$mu = $pdo->prepare("SELECT project, tool, COUNT(*) AS n, MAX(created_at) AS last_at FROM events
    WHERE tool LIKE 'mcp\\_\\_%' ESCAPE '\\' AND created_at > ? GROUP BY project, tool");
$mu->execute([$now - 30 * 86400]);
$mcpUse = [];
foreach ($mu->fetchAll() as $r) {
    $parts = explode('__', $r['tool']);
    $srv = $parts[1] ?? '';
    if ($srv === '') {
        continue;
    }
    $k = $r['project'] . "\n" . $srv;
    $prev = $mcpUse[$k] ?? ['project' => $r['project'], 'name' => $srv, 'n' => 0, 'last_at' => 0];
    $mcpUse[$k] = ['project' => $r['project'], 'name' => $srv, 'n' => $prev['n'] + (int)$r['n'], 'last_at' => max($prev['last_at'], (int)$r['last_at'])];
}

$mt = $pdo->prepare('SELECT id, title, agenda, starts_at, minutes, attendees, creator, created_at FROM meetings
    WHERE starts_at > ? ORDER BY starts_at ASC LIMIT 100');
$mt->execute([$now - 14 * 86400 - 480 * 60]);   // ifadə yox, sütunun özü müqayisə olunur (SQLite parametri mətn kimi bağlayır)
$meetings = $mt->fetchAll();
$posts = [];
if ($meetings) {
    $ids = implode(',', array_map(fn($m) => (int)$m['id'], $meetings));
    $posts = $pdo->query("SELECT id, meeting_id, user, body, created_at FROM meeting_posts WHERE meeting_id IN ($ids) ORDER BY id ASC LIMIT 1000")->fetchAll();
}

json_out([
    'now' => $now,
    'worlds' => $pdo->query('SELECT id, name, owner, surface, hue, ring, size, descr, created_at, updated_at FROM worlds ORDER BY id ASC')->fetchAll(),
    'ships' => $pdo->query('SELECT user, name, model, color, accent, deadline, mission, updated_at FROM ships')->fetchAll(),
    'mcp' => $pdo->query('SELECT id, name, project, descr, owner, created_at FROM mcp_sats ORDER BY id ASC LIMIT 200')->fetchAll(),
    'mcp_use' => array_values($mcpUse),
    'activity' => $activity,
    'prompts' => $pdo->query('SELECT id, owner, title, body, tags, created_at, updated_at FROM prompts ORDER BY updated_at DESC LIMIT 300')->fetchAll(),
    'meetings' => $meetings,
    'tests' => $pdo->query('SELECT id, project, title, steps, status, note, creator, tested_by, tested_at, created_at, updated_at FROM tests ORDER BY id ASC LIMIT 2000')->fetchAll(),
    'meeting_posts' => $posts,
    'sounds' => (object)$sounds,
    'messages' => $msgs,
    'plans' => $pl->fetchAll(),
    'presence' => $pdo->query('SELECT user, last_seen FROM presence')->fetchAll(),
    'alerts' => $al->fetchAll(),
    'tasks' => $tk->fetchAll(),
    'commands' => $cmds,
    'agents' => $agents,
    'me' => $me,
    // users = komanda (tapşırıq, mesaj, agent siyahıları üçün); studentlər ayrıca
    'users' => $pdo->query("SELECT name FROM users WHERE role != 'student' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
    'students' => $students,
    'sessions' => $sessions->fetchAll(),
    'projects' => $projects->fetchAll(),
    'events' => $events,
]);
