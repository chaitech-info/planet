<?php
// Brauzer → növbə: istifadəçi öz tokeni ilə prompt göndərir. Yalnız öz maşınına.
require __DIR__ . '/db.php';

$user = token_user();
if ($user === null) {
    json_out(['error' => 'token yanlışdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST lazımdır'], 405);
}
if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {   // şəkilli prompt: FormData
    $in = $_POST;
} else {
    $in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
}
if (!is_array($in)) {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();

if ($act === 'whoami') {
    json_out(['user' => $user]);
}

if ($act === 'send') {
    $project = trim((string)($in['project'] ?? ''));
    $prompt = trim((string)($in['prompt'] ?? ''));
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'proyekt adı yanlışdır'], 400);
    }
    if ($prompt === '' || mb_strlen($prompt) > 4000) {
        json_out(['error' => 'prompt 1-4000 simvol olmalıdır'], 400);
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM commands WHERE user = ? AND status IN ('queued','running')");
    $c->execute([$user]);
    if ((int)$c->fetchColumn() >= 5) {
        json_out(['error' => 'növbədə artıq 5 tapşırıq var, gözlə'], 429);
    }
    $images = cmdfile_save($_FILES['images'] ?? null);
    $pdo->prepare("INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, images) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$user, $project, $prompt, 'queued', '', $now, 0, 0, $images ? json_encode($images) : '']);
    $id = (int)$pdo->lastInsertId();
    emit_event($pdo, 'command.queued', $user, $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr($prompt, 0, 300), 'kind' => 'prompt']);
    if (random_int(1, 20) === 1) {
        cmdimg_cleanup($pdo);
    }
    json_out(['ok' => true, 'id' => $id, 'images' => $images]);
}

if ($act === 'run_task') {
    $st = $pdo->prepare('SELECT id, project, title, details, assignee, status FROM tasks WHERE id = ?');
    $st->execute([(int)($in['task_id'] ?? 0)]);
    $t = $st->fetch();
    if (!$t) {
        json_out(['error' => 'tapşırıq tapılmadı'], 404);
    }
    if ($t['assignee'] !== $user) {
        json_out(['error' => 'Bu tapşırıq sənə təyin olunmayıb'], 403);
    }
    if ($t['status'] === 'done') {
        json_out(['error' => 'Tapşırıq artıq bitib'], 400);
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM commands WHERE user = ? AND status IN ('queued','running')");
    $c->execute([$user]);
    if ((int)$c->fetchColumn() >= 5) {
        json_out(['error' => 'növbədə artıq 5 tapşırıq var, gözlə'], 429);
    }
    $cid = queue_command($pdo, $user, $t['project'], task_command_prompt($t), 'task', $now);   // tapşırıq həmişə təzə söhbətdə; bitəndə avtomatik yoxlamaya gedir
    $pdo->prepare("UPDATE tasks SET status = 'doing', command_id = ?, updated_at = ?, verdict = '' WHERE id = ?")->execute([$cid, $now, $t['id']]);
    json_out(['ok' => true, 'command_id' => $cid]);
}

if ($act === 'cancel') {
    $st = $pdo->prepare("UPDATE commands SET status = 'canceled', finished_at = ? WHERE id = ? AND user = ? AND status = 'queued'");
    $st->execute([$now, (int)($in['id'] ?? 0), $user]);
    json_out(['ok' => $st->rowCount() === 1]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
