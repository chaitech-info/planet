<?php
// Plan və scope-lar (proyekt üzrə). Hər giriş etmiş istifadəçi əlavə edə və bitirə bilər; mətni yalnız yaradan və admin dəyişir/silir.
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
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$isAdmin = $me['role'] === 'admin';
const PLAN_KINDS = ['plan' => 'plan', 'scope' => 'scope'];

function clean(string $s): string
{
    return trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s));
}

if ($act === 'create') {
    $kind = (string)($in['kind'] ?? '');
    $project = clean((string)($in['project'] ?? ''));
    $title = clean((string)($in['title'] ?? ''));
    $details = clean((string)($in['details'] ?? ''));
    if (!isset(PLAN_KINDS[$kind])) {
        json_out(['error' => 'Növ plan və ya scope olmalıdır'], 400);
    }
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'Proyekt seçilməlidir'], 400);
    }
    if ($title === '' || mb_strlen($title) > 200) {
        json_out(['error' => 'Başlıq 1-200 simvol olmalıdır'], 400);
    }
    if (mb_strlen($details) > 3000) {
        json_out(['error' => 'Ətraflı mətn çox uzundur (3000 simvola qədər)'], 400);
    }
    $rl = $pdo->prepare('SELECT COUNT(*) FROM plans WHERE creator = ? AND created_at > ?');
    $rl->execute([$me['name'], $now - 60]);
    if ((int)$rl->fetchColumn() >= 20) {
        json_out(['error' => 'Çox tez əlavə edirsən, bir az gözlə'], 429);
    }
    $pdo->prepare("INSERT INTO plans (project, kind, title, details, status, creator, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$project, $kind, $title, $details, 'open', $me['name'], $now, $now]);
    notify_later(($kind === 'scope' ? '🎯 Yeni scope' : '🗺 Yeni plan') . ': ' . $title . "\nProyekt: $project · " . $me['name']);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

$q = $pdo->prepare('SELECT * FROM plans WHERE id = ?');
$q->execute([(int)($in['id'] ?? 0)]);
$p = $q->fetch();
if (!$p) {
    json_out(['error' => 'tapılmadı'], 404);
}
$canEdit = $isAdmin || $p['creator'] === $me['name'];

if ($act === 'status') {
    $to = (string)($in['status'] ?? '');
    if (!in_array($to, ['open', 'done'], true)) {
        json_out(['error' => 'yanlış status'], 400);
    }
    $pdo->prepare('UPDATE plans SET status = ?, updated_at = ? WHERE id = ?')->execute([$to, $now, $p['id']]);
    if ($to === 'done' && $p['status'] !== 'done') {
        notify_later('✅ ' . ($p['kind'] === 'scope' ? 'Scope' : 'Plan') . ' bitdi: ' . $p['title'] . "\nProyekt: " . $p['project'] . ' · ' . $me['name']);
    }
    json_out(['ok' => true]);
}
if ($act === 'update') {
    if (!$canEdit) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $title = clean((string)($in['title'] ?? ''));
    $details = clean((string)($in['details'] ?? ''));
    if ($title === '' || mb_strlen($title) > 200) {
        json_out(['error' => 'Başlıq 1-200 simvol olmalıdır'], 400);
    }
    if (mb_strlen($details) > 3000) {
        json_out(['error' => 'Ətraflı mətn çox uzundur (3000 simvola qədər)'], 400);
    }
    $pdo->prepare('UPDATE plans SET title = ?, details = ?, updated_at = ? WHERE id = ?')->execute([$title, $details, $now, $p['id']]);
    json_out(['ok' => true]);
}
if ($act === 'delete') {
    if (!$canEdit) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
    $pdo->prepare('DELETE FROM plans WHERE id = ?')->execute([$p['id']]);
    json_out(['ok' => true]);
}
json_out(['error' => 'naməlum əməliyyat'], 400);
