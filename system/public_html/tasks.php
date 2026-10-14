<?php
// Tapşırıq lövhəsi (giriş etmiş istifadəçi). Admin hər kəsə təyin edir, üzv yalnız özünə.
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
/** Son tarix (unix saniyə, 0 = yoxdur). Keçmişə bir ildən çox və gələcəyə üç ildən çox olmaz. */
function due_in(array $in): int
{
    $d = (int)($in['due_at'] ?? 0);
    if ($d !== 0 && ($d < time() - 86400 * 365 || $d > time() + 86400 * 365 * 3)) {
        json_out(['error' => 'Son tarix düzgün deyil'], 400);
    }
    return $d;
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
    $due = due_in($in);
    if (!$isAdmin) {
        $assignee = $me['name'];                       // üzv yalnız özünə yaza bilər
    } elseif (!user_exists($pdo, $assignee)) {
        json_out(['error' => 'istifadəçi tapılmadı'], 404);
    }
    $pdo->prepare("INSERT INTO tasks (project, title, details, assignee, creator, status, command_id, created_at, updated_at, done_at, due_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$project, $title, $details, $assignee, $me['name'], 'todo', 0, $now, $now, 0, $due]);
    $id = (int)$pdo->lastInsertId();
    // agentlər lövhəni özləri işləsin: icraçının agenti varsa tapşırıq dərhal ona gedir
    $cid = auto_run_task($pdo, ['id' => $id, 'project' => $project, 'title' => $title, 'details' => $details, 'assignee' => $assignee, 'status' => 'todo'], $now);
    if ($assignee !== $me['name']) {
        notify_later("📌 Yeni tapşırıq → $assignee\n$title\nProyekt: $project · təyin edən: " . $me['name'] . ($cid ? "\n🤖 $assignee-in agentinə avtomatik göndərildi" : ''));
    }
    json_out(['ok' => true, 'id' => $id, 'auto' => $cid !== null, 'command_id' => $cid]);
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
    $auto = null;
    if ($to === 'todo') {                                   // yenidən açıldı: yoxlama dövrələri sıfırdan
        $pdo->prepare("UPDATE tasks SET rounds = 0, verdict = '' WHERE id = ?")->execute([$t['id']]);
    }
    if ($to === 'review' && $t['status'] !== 'review') {
        // insan "Yoxlamaya ver" dedi: agent hələ işləmirsə yoxlamanı da agent etsin (mümkünsə başqa agent)
        $ac = $pdo->prepare("SELECT 1 FROM commands WHERE id = ? AND status IN ('pending','queued','running')");
        $ac->execute([(int)$t['command_id']]);
        $auto = $ac->fetchColumn() ? null : start_review($pdo, array_merge($t, ['status' => 'review']), '(icraçı işi özü bitirdi, agent hesabatı yoxdur: dəyişiklikləri git ilə tap)', $now);
        notify_later($auto ? "🔎 Yoxlamada, $auto-in agenti yoxlayır: {$t['title']}\n{$t['assignee']} · {$t['project']}" : "🔎 Yoxlamada: {$t['title']}\n{$t['assignee']} · {$t['project']}");
    } elseif ($to === 'done' && $t['status'] !== 'done') {
        notify_later("✅ Bitdi: {$t['title']}\n{$t['assignee']} · {$t['project']}");
    }
    json_out(['ok' => true]);
}

if ($act === 'due') {
    if (!$canMove) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $pdo->prepare('UPDATE tasks SET due_at = ?, updated_at = ? WHERE id = ?')->execute([due_in($in), $now, $t['id']]);
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
    // başqasına verildisə və hələ gözləyirsə: yeni icraçının agentinə avtomatik gedir
    $cid = $assignee !== $t['assignee'] ? auto_run_task($pdo, array_merge($t, ['title' => $title, 'details' => $details, 'assignee' => $assignee]), $now) : null;
    json_out(['ok' => true, 'auto' => $cid !== null]);
}

if ($act === 'delete') {
    if (!$canEdit) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$t['id']]);
    json_out(['ok' => true]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
