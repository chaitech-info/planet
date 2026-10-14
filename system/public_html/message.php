<?php
// Komanda mesajları (giriş etmiş istifadəçi): bir nəfərə və ya hamıya. Saytda raketlə çatdırılır.
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
$in = json_decode(file_get_contents('php://input', false, null, 0, 4000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
if ((string)($in['action'] ?? '') !== 'send') {
    json_out(['error' => 'naməlum əməliyyat'], 400);
}
session_write_close();

$pdo = db();
$now = time();
$to = (string)($in['to'] ?? '');
$body = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($in['body'] ?? '')));
if ($body === '') {
    json_out(['error' => 'Mesaj boşdur'], 400);
}
if (mb_strlen($body) > 500) {
    json_out(['error' => 'Mesaj ən çox 500 simvol ola bilər'], 400);
}
if ($to !== '*') {
    if ($to === $me['name']) {
        json_out(['error' => 'Özünə mesaj göndərə bilməzsən'], 400);
    }
    $q = $pdo->prepare('SELECT name FROM users WHERE name = ?');
    $q->execute([$to]);
    if ($q->fetchColumn() === false) {
        json_out(['error' => 'Belə istifadəçi yoxdur'], 404);
    }
}
$rl = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE from_user = ? AND created_at > ?');
$rl->execute([$me['name'], $now - 60]);
if ((int)$rl->fetchColumn() >= 6) {
    json_out(['error' => 'Çox tez göndərirsən, bir az gözlə'], 429);
}
$pdo->prepare('INSERT INTO messages (from_user, to_user, body, created_at) VALUES (?,?,?,?)')->execute([$me['name'], $to, $body, $now]);
$id = (int)$pdo->lastInsertId();
if (random_int(1, 40) === 1) {
    $pdo->prepare('DELETE FROM messages WHERE created_at < ?')->execute([$now - 7 * 86400]);
}
json_out(['ok' => true, 'message' => ['id' => $id, 'from_user' => $me['name'], 'to_user' => $to, 'body' => $body, 'created_at' => $now]]);
