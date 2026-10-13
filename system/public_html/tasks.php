<?php
// Tapşırıq lövhəsi (giriş etmiş istifadəçi). Admin hər kəsə təyin edir, üzv yalnız özünə.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$isAdmin = $me['role'] === 'admin';
const TASK_STATUSES = ['todo', 'doing', 'review', 'done'];

function load_task(PDO $pdo, $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $st->execute([(int)$id]);
    $r = $st->fetch();
    return $r ?: null;
}
function user_exists(PDO $pdo, string $name): bool
{
    $st = $pdo->prepare('SELECT 1 FROM users WHERE name = ?');
    $st->execute([$name]);
    return (bool)$st->fetchColumn();
}

if ($act === 'create') {
    $title = trim((string)($in['title'] ?? ''));
    $details = trim((string)($in['details'] ?? ''));
    $project = trim((string)($in['project'] ?? ''));
    $assignee = trim((string)($in['assignee'] ?? $me['name']));
    if ($title === '' || mb_strlen($title) > 200) {
        json_out(['error' => 'Başlıq 1-200 simvol olmalıdır'], 400);
    }
    if (mb_strlen($details) > 3000) {
        json_out(['error' => 'Ətraflı mətn çox uzundur (3000 simvola qədər)'], 400);
    }
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'Proyekt seçilməlidir'], 400);
    }
    if (!$isAdmin) {
        $assignee = $me['name'];                       // üzv yalnız özünə yaza bilər
    } elseif (!user_exists($pdo, $assignee)) {
        json_out(['error' => 'istifadəçi tapılmadı'], 404);
    }
    $pdo->prepare("INSERT INTO tasks (project, title, details, assignee, creator, status, command_id, created_at, updated_at, done_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$project, $title, $details, $assignee, $me['name'], 'todo', 0, $now, $now, 0]);
    if ($assignee !== $me['name']) {
        notify_later("📌 Yeni tapşırıq → $assignee\n$title\nProyekt: $project · təyin edən: " . $me['name']);
    }
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

$t = load_task($pdo, $in['id'] ?? 0);
if (!$t) {
    json_out(['error' => 'tapşırıq tapılmadı'], 404);
}
$canMove = $isAdmin || $t['assignee'] === $me['name'] || $t['creator'] === $me['name'];
$canEdit = $isAdmin || $t['creator'] === $me['name'];

if ($act === 'move') {
    $to = (string)($in['status'] ?? '');
    if (!in_array($to, TASK_STATUSES, true)) {
        json_out(['error' => 'yanlış status'], 400);
    }
    if (!$canMove) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $pdo->prepare('UPDATE tasks SET status = ?, updated_at = ?, done_at = ? WHERE id = ?')
        ->execute([$to, $now, $to === 'done' ? $now : 0, $t['id']]);
    if ($to === 'review' && $t['status'] !== 'review') {
        notify_later("🔎 Yoxlamada: {$t['title']}\n{$t['assignee']} · {$t['project']}");
    } elseif ($to === 'done' && $t['status'] !== 'done') {
        notify_later("✅ Bitdi: {$t['title']}\n{$t['assignee']} · {$t['project']}");
    }
    json_out(['ok' => true]);
}

if ($act === 'update') {
    if (!$canEdit) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $title = trim((string)($in['title'] ?? $t['title']));
    $details = trim((string)($in['details'] ?? $t['details']));
    if ($title === '' || mb_strlen($title) > 200 || mb_strlen($details) > 3000) {
        json_out(['error' => 'Başlıq və ya mətn düzgün deyil'], 400);
    }
    $assignee = $t['assignee'];
    if ($isAdmin && isset($in['assignee']) && user_exists($pdo, (string)$in['assignee'])) {
        $assignee = (string)$in['assignee'];
    }
    $pdo->prepare('UPDATE tasks SET title = ?, details = ?, assignee = ?, updated_at = ? WHERE id = ?')
        ->execute([$title, $details, $assignee, $now, $t['id']]);
    json_out(['ok' => true]);
}

if ($act === 'delete') {
    if (!$canEdit) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$t['id']]);
    json_out(['ok' => true]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
