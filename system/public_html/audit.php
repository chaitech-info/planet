<?php
// Jarvis audit: hər alət çağırışı (jarvis.js runTools) buraya yazılır: kim, hansı alət, tier, arqumentlər (≤ 2 KB), ok, nəticənin başı (≤ 500).
// log — öz adına yazır; list — öz qeydləri, admin üçün {all:true} hamınınkı. Cədvəl: jarvis_audit (db.php v5).
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
$in = json_decode(file_get_contents('php://input', false, null, 0, 16000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$cut = fn($v, int $n) => mb_substr(is_string($v) ? $v : (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, $n);

if ($act === 'log') {
    $tool = (string)($in['tool'] ?? '');
    if (!preg_match('/^[a-z0-9_]{1,64}$/', $tool)) {
        json_out(['error' => 'alət adı yanlışdır'], 400);
    }
    $tier = in_array($in['tier'] ?? '', ['g', 'y', 'r'], true) ? (string)$in['tier'] : '';
    $pdo->prepare('INSERT INTO jarvis_audit (user, tool, tier, args, result, ok, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$me['name'], $tool, $tier, $cut($in['args'] ?? '', 2000), $cut($in['result_head'] ?? '', 500), empty($in['ok']) ? 0 : 1, time()]);
    if (random_int(1, 300) === 1) {                 // arabir 30 gündən köhnələri sil
        $pdo->prepare('DELETE FROM jarvis_audit WHERE created_at < ?')->execute([time() - 30 * 86400]);
    }
    json_out(['ok' => true]);
}

if ($act === 'list') {
    $lim = max(1, min(100, (int)($in['limit'] ?? 20)));
    $all = !empty($in['all']) && ($me['role'] ?? '') === 'admin';
    $st = $pdo->prepare('SELECT id, user, tool, tier, args, result, ok, created_at FROM jarvis_audit' . ($all ? '' : ' WHERE user = ?') . ' ORDER BY id DESC LIMIT ' . $lim);
    $st->execute($all ? [] : [$me['name']]);
    json_out(['ok' => true, 'all' => $all, 'items' => $st->fetchAll()]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
