<?php
// Dayandırma əməliyyatları (giriş etmiş istifadəçi): öz sessiyasını bağla, öz Claude tapşırığını dayandır. Admin hamısını.
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
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$isAdmin = $me['role'] === 'admin';

if ($act === 'stop_session') {
    $user = (string)($in['user'] ?? '');
    $sid = (string)($in['session_id'] ?? '');
    if ($user !== $me['name'] && !$isAdmin) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $st = $pdo->prepare("UPDATE sessions SET status = 'ended', updated_at = ? WHERE user = ? AND session_id = ?");
    $st->execute([$now, $user, $sid]);
    json_out(['ok' => $st->rowCount() === 1]);
}

if ($act === 'stop_command') {
    $q = $pdo->prepare('SELECT id, user, status, creator FROM commands WHERE id = ?');
    $q->execute([(int)($in['id'] ?? 0)]);
    $c = $q->fetch();
    if (!$c) {
        json_out(['error' => 'tapşırıq tapılmadı'], 404);
    }
    if ($c['user'] !== $me['name'] && $c['creator'] !== $me['name'] && !$isAdmin) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    if (!in_array($c['status'], ['pending', 'queued', 'running'], true)) {
        json_out(['ok' => true, 'note' => 'artıq bitib']);
    }
    $pdo->prepare("UPDATE commands SET status = 'canceled', result = ?, finished_at = ? WHERE id = ?")->execute(['Dayandırıldı', $now, $c['id']]);
    // bu icra ilə bağlı lövhə tapşırığı yenidən "Gözləyir"ə qayıdır
    $pdo->prepare("UPDATE tasks SET status = 'todo', updated_at = ? WHERE command_id = ? AND status = 'doing'")->execute([$now, $c['id']]);
    // yoxlama dayandırıldısa tapşırıq "Yoxlamada" qalır, amma artıq insan baxmalıdır
    $pdo->prepare("UPDATE tasks SET verdict = 'error', updated_at = ? WHERE command_id = ? AND status = 'review'")->execute([$now, $c['id']]);
    json_out(['ok' => true]);
}

if ($act === 'agent_auto') {               // mənim agentim lövhə tapşırıqlarını avtomatik götürsünmü?
    $on = !empty($in['on']) && $in['on'] !== 'false' ? 1 : 0;
    $st = $pdo->prepare('UPDATE agents SET auto_tasks = ? WHERE user = ?');
    $st->execute([$on, $me['name']]);
    if ($st->rowCount() === 0) {
        $has = $pdo->prepare('SELECT 1 FROM agents WHERE user = ?');
        $has->execute([$me['name']]);
        if (!$has->fetchColumn()) {
            json_out(['error' => 'Agentin hələ heç qoşulmayıb: kompüterində agenti bir dəfə işə sal, sonra bu ayarı dəyiş.'], 409);
        }
    }
    json_out(['ok' => true, 'on' => $on === 1]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
