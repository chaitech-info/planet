<?php
// MEDIA universi: Edit Media üçün yüklənmiş şəkil/videonu verir (media.php → upload).
// Giriş tələb olunmur: fal.ai faylı bu URL-dən götürür. Ad 128 bitlik təsadüfi hex-dir (təxmin edilmir), fayllar 14 gün saxlanır.
$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|webp|gif|mp4|mov|webm)$/', $f)) {
    http_response_code(400);
    exit('yanlış ad');
}
$path = __DIR__ . '/data/media/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit('fayl yoxdur');
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'];
header('Content-Type: ' . $types[substr($f, 33)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
readfile($path);
