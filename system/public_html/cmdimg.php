<?php
// Prompta əlavə olunmuş şəkli verir. Giriş etmiş istifadəçi (brauzerdə kart) və ya token ilə agent (kompüterdə Claude-a göstərmək üçün).
require __DIR__ . '/db.php';

$f = (string)($_GET['f'] ?? '');
if (!cmdimg_name_ok($f)) {
    http_response_code(400);
    exit('yanlış ad');
}
$who = token_user();
if ($who === null) {
    auth_boot();
    $me = session_user();
    if ($me === null || is_student($me)) {
        http_response_code(401);
        exit('giriş lazımdır');
    }
    session_write_close();
}
$dir = cmdimg_dir();
$path = $dir === null ? '' : $dir . '/' . $f;
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('şəkil yoxdur');
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[substr($f, strrpos($f, '.') + 1)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
