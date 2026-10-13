<?php
// Hər istifadəçi öz musiqisini (MP3) yükləyir: işləyəndə planetdə çalır. Yalnız öz faylını dəyişə/silə bilər.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
session_write_close();

const SOUND_MAX = 8 * 1024 * 1024;
$dir = __DIR__ . '/media/u';
$file = $dir . '/' . substr(hash('sha256', $me['name']), 0, 16) . '.mp3';
$act = (string)($_POST['action'] ?? '');

if ($act === 'delete') {
    if (is_file($file)) {
        @unlink($file);
    }
    json_out(['ok' => true]);
}
if ($act !== 'upload') {
    json_out(['error' => 'naməlum əməliyyat'], 400);
}

$f = $_FILES['file'] ?? null;
if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $big = in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
    json_out(['error' => $big ? 'Fayl çox böyükdür (ən çox 8 MB)' : 'Fayl yüklənmədi'], 400);
}
if ($f['size'] < 2048 || $f['size'] > SOUND_MAX) {
    json_out(['error' => 'Fayl 2 KB ilə 8 MB arasında olmalıdır'], 400);
}
if (!is_uploaded_file($f['tmp_name'])) {
    json_out(['error' => 'Fayl yüklənmədi'], 400);
}
// Həqiqətən MP3-dür? ID3 başlığı və ya MPEG audio kadr başlığı
$h = (string)file_get_contents($f['tmp_name'], false, null, 0, 4);
$isMp3 = str_starts_with($h, 'ID3') || (strlen($h) >= 2 && ord($h[0]) === 0xFF && (ord($h[1]) & 0xE0) === 0xE0);
if (!$isMp3) {
    json_out(['error' => 'Yalnız MP3 faylı qəbul olunur'], 400);
}
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    json_out(['error' => 'Qovluq yaradıla bilmədi'], 500);
}
$tmp = $file . '.tmp';
if (!@move_uploaded_file($f['tmp_name'], $tmp) || !@rename($tmp, $file)) {
    @unlink($tmp);
    json_out(['error' => 'Fayl saxlanmadı'], 500);
}
json_out(['ok' => true]);
