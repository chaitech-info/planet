<?php
// MEDIA universi: yüklənmiş şəkil/video/səsi və Timeline renderlərini verir (media.php → upload / upload_render).
// Giriş tələb olunmur: fal.ai faylı bu URL-dən götürür. Ad 128 bitlik təsadüfi hex-dir (təxmin edilmir).
// Range sorğuları dəstəklənir: brauzer videoda irəli-geri keçə bilsin (Timeline önizləməsi, Safari).
$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|webp|gif|mp4|mov|webm|mp3|wav|m4a|ogg)$/', $f)) {
    http_response_code(400);
    exit('yanlış ad');
}
$path = __DIR__ . '/data/media/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit('fayl yoxdur');
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'ogg' => 'audio/ogg'];
$size = (int)filesize($path);
$from = 0;
$to = $size - 1;
header('Content-Type: ' . $types[substr($f, 33)]);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {                                   // "bytes=-500": son 500 bayt
        $from = max(0, $size - (int)$m[2]);
    } else {
        $from = (int)$m[1];
        $to = $m[2] === '' ? $size - 1 : min((int)$m[2], $size - 1);
    }
    if ($from > $to || $from >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $from . '-' . $to . '/' . $size);
}
header('Content-Length: ' . ($to - $from + 1));
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
$fp = fopen($path, 'rb');
fseek($fp, $from);
$left = $to - $from + 1;
while ($left > 0 && !feof($fp)) {
    $chunk = fread($fp, (int)min(65536, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fp);
