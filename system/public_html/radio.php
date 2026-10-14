<?php
// Ratsiya (bas-danış): göndərən qısa səs klipini yükləyir, ratsiyası açıq olanlar 2 saniyədən bir soruşub çalır.
//  poll : yeni klipləri və ratsiyası açıq olanları qaytarır (həm də "mən açığam" ürək döyüntüsüdür)
//  send : klip (FormData: clip, to, dur, mime)
//  clip : klipin səs faylı
//  off  : ratsiyanı bağladım
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
if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
    $in = $_POST;
} else {
    $in = json_decode(file_get_contents('php://input', false, null, 0, 5000) ?: '', true);
}
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$name = $me['name'];

const RADIO_MAX = 3 * 1024 * 1024;     // bir klip ən çox (≈ 60 san opus)
const RADIO_TTL = 900;                 // klip faylı bu qədər saniyə saxlanır
const RADIO_ONLINE = 8;                // bu qədər saniyə səs verməyən "ratsiyası bağlı" sayılır
$MIMES = ['audio/webm' => 'webm', 'audio/ogg' => 'ogg', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/mpeg' => 'mp3'];

function radio_touch(PDO $pdo, string $user, int $ts): void
{
    $up = $pdo->prepare('UPDATE radio_presence SET last_seen = ? WHERE user = ?');
    $up->execute([$ts, $user]);
    if ($up->rowCount() === 0) {
        try {
            $pdo->prepare('INSERT INTO radio_presence (user, last_seen) VALUES (?, ?)')->execute([$user, $ts]);
        } catch (Throwable $e) {
            // eyni anda iki sorğu: artıq var
        }
    }
}

function radio_cleanup(PDO $pdo, int $now): void
{
    $dir = radio_dir();
    $q = $pdo->prepare('SELECT id, file FROM radio WHERE created_at < ?');
    $q->execute([$now - RADIO_TTL]);
    foreach ($q->fetchAll() as $r) {
        if ($dir !== null && preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,4}$/', $r['file'])) {
            @unlink($dir . '/' . $r['file']);
        }
        $pdo->prepare('DELETE FROM radio WHERE id = ?')->execute([$r['id']]);
    }
}

if ($act === 'poll') {
    radio_touch($pdo, $name, $now);
    $last = (int)$pdo->query('SELECT COALESCE(MAX(id), 0) FROM radio')->fetchColumn();
    $since = (int)($in['since'] ?? 0);
    $clips = [];
    if ($since > 0) {                                   // ilk sorğuda (since = 0) köhnə kliplər çalınmır
        $q = $pdo->prepare("SELECT id, user, target, mime, dur, created_at FROM radio WHERE id > ? AND user != ? AND (target = '*' OR target = ?) AND created_at > ? ORDER BY id ASC LIMIT 10");
        $q->execute([$since, $name, $name, $now - 120]);
        $clips = $q->fetchAll();
    }
    $on = $pdo->prepare('SELECT user FROM radio_presence WHERE last_seen > ? AND user != ? ORDER BY user');
    $on->execute([$now - RADIO_ONLINE, $name]);
    json_out(['ok' => true, 'clips' => $clips, 'last' => max($since, $last), 'on' => $on->fetchAll(PDO::FETCH_COLUMN), 'now' => $now]);
}

if ($act === 'off') {
    $pdo->prepare('UPDATE radio_presence SET last_seen = 0 WHERE user = ?')->execute([$name]);
    json_out(['ok' => true]);
}

if ($act === 'send') {
    $to = (string)($in['to'] ?? '*');
    if ($to !== '*') {
        $ue = $pdo->prepare("SELECT 1 FROM users WHERE name = ? AND role != 'student'");
        $ue->execute([$to]);
        if ($to === $name || !$ue->fetchColumn()) {
            json_out(['error' => 'kanal yanlışdır'], 400);
        }
    }
    $dur = max(0, min(90, (float)($in['dur'] ?? 0)));
    $f = $_FILES['clip'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        json_out(['error' => 'Səs klipi gəlmədi'], 400);
    }
    if ($f['size'] < 200 || $f['size'] > RADIO_MAX) {
        json_out(['error' => 'Klip çox böyükdür (ən çox 60 saniyə)'], 400);
    }
    // format: fayl başlığından (EBML = webm, ftyp = mp4, OggS = ogg); tapılmasa brauzerin dediyi
    $h = (string)file_get_contents($f['tmp_name'], false, null, 0, 12);
    $mime = str_starts_with($h, "\x1A\x45\xDF\xA3") ? 'audio/webm' : (substr($h, 4, 4) === 'ftyp' ? 'audio/mp4' : (str_starts_with($h, 'OggS') ? 'audio/ogg' : ''));
    if ($mime === '') {
        $decl = strtolower(trim(explode(';', (string)($in['mime'] ?? $f['type'] ?? ''))[0]));
        $mime = isset($MIMES[$decl]) ? $decl : '';
    }
    if ($mime === '') {
        json_out(['error' => 'Səs formatı tanınmadı'], 400);
    }
    $dir = radio_dir();
    if ($dir === null) {
        json_out(['error' => 'Qovluq yaradıla bilmədi'], 500);
    }
    $file = bin2hex(random_bytes(12)) . '.' . $MIMES[$mime];
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
        json_out(['error' => 'Klip saxlanmadı'], 500);
    }
    $pdo->prepare('INSERT INTO radio (user, target, file, mime, dur, created_at) VALUES (?,?,?,?,?,?)')->execute([$name, $to, $file, $mime, $dur, $now]);
    $id = (int)$pdo->lastInsertId();
    radio_touch($pdo, $name, $now);
    if (random_int(1, 10) === 1) {
        radio_cleanup($pdo, $now);
    }
    json_out(['ok' => true, 'id' => $id]);
}

if ($act === 'clip') {
    $q = $pdo->prepare('SELECT user, target, file, mime FROM radio WHERE id = ?');
    $q->execute([(int)($in['id'] ?? 0)]);
    $r = $q->fetch();
    if (!$r || ($r['user'] !== $name && $r['target'] !== '*' && $r['target'] !== $name)) {
        http_response_code(404);
        exit('klip yoxdur');
    }
    $dir = radio_dir();
    $path = $dir === null ? '' : $dir . '/' . $r['file'];
    if ($path === '' || !preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,4}$/', $r['file']) || !is_file($path)) {
        http_response_code(404);
        exit('klip silinib');
    }
    header('Content-Type: ' . $r['mime']);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

json_out(['error' => 'naməlum əməliyyat'], 400);
