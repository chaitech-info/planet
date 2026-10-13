<?php
// Gündəlik hesabat (giriş etmiş istifadəçi). "send" yalnız admin: Telegram-a göndərir.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 5000) ?: '', true);
$day = (is_array($in) && ($in['day'] ?? '') === 'yesterday') ? 'yesterday' : 'today';
$act = is_array($in) ? (string)($in['action'] ?? 'get') : 'get';
[$s, $e] = report_window($day);
$rows = report_data(db(), $s, $e);
$title = ($day === 'yesterday' ? 'Dünənki hesabat' : 'Bugünkü hesabat') . ' · ' . date('d.m.Y', $s);

if ($act === 'send') {
    if ($me['role'] !== 'admin') {
        json_out(['error' => 'yalnız admin'], 403);
    }
    if (setting_get('tg_token') === '' || setting_get('tg_chat') === '') {
        json_out(['error' => 'Əvvəlcə "Bildirişlər" bölməsində Telegram ayarlarını daxil et'], 400);
    }
    json_out(['ok' => notify(report_text($rows, $title))]);
}

json_out(['title' => $title, 'day' => $day, 'rows' => $rows, 'from' => $s, 'to' => $e]);
