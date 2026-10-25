<?php
// MEDIA universi: fal.ai bağlantısı (Fal Connection planeti) və media redaktəsi (Edit Media planeti).
//  key_status / key_set / key_delete : istifadəçinin öz fal açarı. Açar serverdə qalır, brauzerə yalnız maskası gedir.
//  models   : fal-ın canlı model kataloqu (api.fal.ai/v1/models, kateqoriya + axtarış), 6 saat keşlənir
//  schema   : modelin giriş sahələri (OpenAPI-dən), forma qurmaq üçün, 24 saat keşlənir
//  submit   : fal növbəsinə (queue.fal.run) sorğu; list : generasiyalar (gözləyənləri fal-dan yeniləyir); delete
//  upload   : redaktə üçün şəkil/video yükləmə. fal faylı götürə bilsin deyə təxmin edilməz adla açıq URL-də verilir (mediafile.php)
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
    $in = json_decode(file_get_contents('php://input', false, null, 0, 40000) ?: '', true);
}
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$name = $me['name'];

const FAL_CATS = ['text-to-image', 'image-to-image', 'text-to-video', 'image-to-video', 'video-to-video'];
const MEDIA_FILE_BYTES = 50 * 1024 * 1024;      // yükləmə limiti (video); şəkil üçün aşağıda 15 MB
const MEDIA_FILE_TTL = 14 * 86400;              // yüklənmiş fayllar 14 gün saxlanır

function fal_key_of(PDO $pdo, string $user): string
{
    $st = $pdo->prepare('SELECT fal_key FROM media_keys WHERE user = ?');
    $st->execute([$user]);
    return (string)($st->fetchColumn() ?: '');
}

function key_hint(string $k): string
{
    return mb_strlen($k) <= 10 ? '••••' : mb_substr($k, 0, 4) . '…' . mb_substr($k, -4);
}

/** fal API-yə sorğu. [http kodu, JSON (və ya null), xam cavab, curl xətası] */
function fal_http(string $method, string $url, string $key, ?array $body = null, int $timeout = 20): array
{
    $c = curl_init($url);
    $h = ['Accept: application/json'];
    if ($key !== '') {
        $h[] = 'Authorization: Key ' . $key;
    }
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6];
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        $opt[CURLOPT_POSTFIELDS] = json_encode((object)$body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($c, $opt);
    $raw = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = $raw === false ? curl_error($c) : '';
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, is_array($j) ? $j : null, is_string($raw) ? $raw : '', $err];
}

/** fal xətasını oxunaqlı mətnə çevirir */
function fal_err(int $code, ?array $j, string $raw, string $err): string
{
    if ($err !== '') {
        return 'fal.ai-ya qoşulmaq olmadı: ' . $err;
    }
    $d = $j['detail'] ?? $j['error'] ?? $j['message'] ?? null;
    if (is_array($d)) {
        $d = isset($d[0]['msg']) ? implode('; ', array_map(fn($x) => (is_array($x['loc'] ?? null) ? implode('.', $x['loc']) . ': ' : '') . ($x['msg'] ?? ''), array_slice($d, 0, 4))) : json_encode($d, JSON_UNESCAPED_UNICODE);
    }
    $msg = is_string($d) && $d !== '' ? $d : mb_substr(trim(strip_tags($raw)), 0, 200);
    $pre = $code === 401 || $code === 403 ? 'fal açarı qəbul olunmadı' : ($code === 429 ? 'fal limiti: bir az gözlə' : 'fal xətası');
    return $pre . ' (' . $code . ')' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 300) : '');
}

/** settings cədvəlində keş (k ≤ 64 simvol) */
function cache_get(string $k, int $ttl): ?array
{
    $raw = setting_get($k);
    $c = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($c) && (int)($c['t'] ?? 0) > time() - $ttl ? ($c['d'] ?? null) : null;
}
function cache_set(string $k, $d): void
{
    setting_set($k, json_encode(['t' => time(), 'd' => $d], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** fal endpoint id: "fal-ai/flux/schnell", "kling/v4/text-to-video" (ən az bir "/", yol qaçışı yox) */
function endpoint_ok(string $id): bool
{
    return (bool)preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*){1,6}$~', $id) && !str_contains($id, '..');
}

/** Yalnız fal növbə URL-lərinə sorğu göndərilir (SSRF qoruması) */
function queue_url_ok(string $u): bool
{
    return str_starts_with($u, 'https://queue.fal.run/') && !preg_match('~[\s\\\\]~', $u) && strlen($u) <= 400;
}

/** Nəticədəki bütün media URL-ləri (images[].url, image.url, video.url, audio.url …), ən çox 12 */
function media_urls($v, array &$out, string $hint = ''): void
{
    if (count($out) >= 12 || !is_array($v)) {
        return;
    }
    if (is_string($v['url'] ?? null) && str_starts_with($v['url'], 'https://')) {
        $t = (string)($v['content_type'] ?? '');
        $u = $v['url'];
        $type = str_starts_with($t, 'video/') || preg_match('~\.(mp4|webm|mov)(\?|$)~i', $u) || str_contains($hint, 'video') ? 'video'
            : (str_starts_with($t, 'audio/') || preg_match('~\.(mp3|wav|m4a|ogg)(\?|$)~i', $u) || str_contains($hint, 'audio') ? 'audio' : 'image');
        $out[] = ['url' => $u, 'type' => $type, 'w' => (int)($v['width'] ?? 0), 'h' => (int)($v['height'] ?? 0)];
        return;
    }
    foreach ($v as $k => $x) {
        media_urls($x, $out, is_string($k) ? strtolower($k) : $hint);
    }
}

function gen_out(array $r): array
{
    return [
        'id' => (int)$r['id'], 'model' => $r['model'], 'kind' => $r['kind'], 'prompt' => $r['prompt'],
        'params' => json_decode((string)$r['params'], true) ?: (object)[], 'status' => $r['status'],
        'media' => json_decode((string)$r['result'], true) ?: [], 'error' => $r['error'],
        'created_at' => (int)$r['created_at'], 'updated_at' => (int)$r['updated_at'],
    ];
}

/** Gözləyən generasiyanı fal-dan yeniləyir (status → nəticə) */
function gen_refresh(PDO $pdo, array $r, string $key, int $now): array
{
    if (!in_array($r['status'], ['queued', 'running'], true)) {
        return $r;
    }
    $set = function (array $f) use ($pdo, &$r, $now) {
        $f['updated_at'] = $now;
        $pdo->prepare('UPDATE media_gens SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($f))) . ' WHERE id = ?')
            ->execute([...array_values($f), $r['id']]);
        $r = array_merge($r, $f);
    };
    if ((int)$r['created_at'] < $now - 3 * 3600) {
        $set(['status' => 'failed', 'error' => 'Vaxt bitdi: fal 3 saat ərzində nəticə vermədi']);
        return $r;
    }
    if ($key === '' || !queue_url_ok($r['status_url']) || !queue_url_ok($r['response_url'])) {
        return $r;
    }
    [$code, $j, $raw, $err] = fal_http('GET', $r['status_url'], $key, null, 8);
    if ($code < 200 || $code >= 300 || !$j) {
        if ($code >= 400 && $code < 500 && $code !== 429) {
            $set(['status' => 'failed', 'error' => fal_err($code, $j, $raw, $err)]);
        }
        return $r;
    }
    $st = (string)($j['status'] ?? '');
    if ($st === 'IN_QUEUE' || $st === 'IN_PROGRESS') {
        $new = $st === 'IN_QUEUE' ? 'queued' : 'running';
        if ($new !== $r['status']) {
            $set(['status' => $new]);
        }
        return $r;
    }
    if ($st !== 'COMPLETED') {
        return $r;
    }
    [$code, $j, $raw, $err] = fal_http('GET', $r['response_url'], $key, null, 15);
    if ($code < 200 || $code >= 300 || !$j) {
        $set(['status' => 'failed', 'error' => fal_err($code, $j, $raw, $err)]);
        return $r;
    }
    $media = [];
    media_urls($j, $media);
    $set($media ? ['status' => 'done', 'result' => json_encode($media, JSON_UNESCAPED_SLASHES), 'error' => '']
        : ['status' => 'failed', 'error' => 'fal nəticəsində media tapılmadı: ' . mb_substr($raw, 0, 300)]);
    return $r;
}

/** Yüklənən fayllar: data/media (birbaşa girişə bağlı, mediafile.php verir) */
function media_dir(): ?string
{
    $d = __DIR__ . '/data/media';
    if (!is_dir($d) && !@mkdir($d, 0775, true)) {
        return null;
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Bu saytın kök URL-i (fal faylı oradan götürür) */
function site_base(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/* ---------- fal açarı ---------- */
if ($act === 'key_status') {
    $st = $pdo->prepare('SELECT fal_key, updated_at FROM media_keys WHERE user = ?');
    $st->execute([$name]);
    $r = $st->fetch();
    json_out($r ? ['has' => true, 'hint' => key_hint($r['fal_key']), 'updated_at' => (int)$r['updated_at']] : ['has' => false]);
}
if ($act === 'key_set') {
    $key = trim((string)($in['key'] ?? ''));
    if ($key === '' || mb_strlen($key) > 300 || preg_match('~\s~', $key)) {
        json_out(['error' => 'Açar boşdur və ya yanlış formatdadır'], 400);
    }
    // Pulsuz yoxlama: qiymət sorğusu açar tələb edir, yanlış açara 401 qaytarır
    [$code, $j, $raw, $err] = fal_http('GET', 'https://api.fal.ai/v1/models/pricing?endpoint_id=fal-ai/flux/schnell', $key, null, 12);
    if ($code === 401 || $code === 403) {
        json_out(['error' => 'fal açarı qəbul olunmadı (' . $code . '). Açarı fal.ai/dashboard/keys-dən yenidən kopyala.'], 400);
    }
    if ($err !== '' || $code >= 500) {
        json_out(['error' => fal_err($code, $j, $raw, $err) . ' — açar yoxlanmadı, bir az sonra yenə cəhd et'], 502);
    }
    $pdo->prepare('DELETE FROM media_keys WHERE user = ?')->execute([$name]);
    $pdo->prepare('INSERT INTO media_keys (user, fal_key, updated_at) VALUES (?,?,?)')->execute([$name, $key, $now]);
    json_out(['ok' => true, 'has' => true, 'hint' => key_hint($key), 'updated_at' => $now]);
}
if ($act === 'key_delete') {
    $pdo->prepare('DELETE FROM media_keys WHERE user = ?')->execute([$name]);
    json_out(['ok' => true, 'has' => false]);
}

/* ---------- Model kataloqu ---------- */
if ($act === 'models') {
    $cat = (string)($in['category'] ?? '');
    if (!in_array($cat, FAL_CATS, true)) {
        json_out(['error' => 'yanlış kateqoriya'], 400);
    }
    $q = mb_substr(trim((string)($in['q'] ?? '')), 0, 60);
    $ck = 'falm:' . md5($cat . '|' . mb_strtolower($q));
    $list = cache_get($ck, 6 * 3600);
    if ($list === null) {
        $url = 'https://api.fal.ai/v1/models?' . http_build_query(array_filter(['category' => $cat, 'status' => 'active', 'limit' => 60, 'q' => $q, 'sort' => $q !== '' ? 'relevant' : null]));
        [$code, $j, $raw, $err] = fal_http('GET', $url, fal_key_of($pdo, $name), null, 15);
        if ($code < 200 || $code >= 300 || !is_array($j['models'] ?? null)) {
            json_out(['error' => fal_err($code, $j, $raw, $err)], 502);
        }
        $list = [];
        foreach ($j['models'] as $m) {
            $md = is_array($m['metadata'] ?? null) ? $m['metadata'] : [];
            $id = (string)($m['endpoint_id'] ?? '');
            if (!endpoint_ok($id)) {
                continue;
            }
            $list[] = ['id' => $id, 'name' => (string)($md['display_name'] ?? $id), 'description' => mb_substr((string)($md['description'] ?? ''), 0, 240),
                'thumbnail' => (string)($md['thumbnail_url'] ?? $md['thumbnail'] ?? ''), 'url' => (string)($md['model_url'] ?? ('https://fal.ai/models/' . $id)),
                'date' => (string)($md['date'] ?? '')];
        }
        cache_set($ck, $list);
    }
    json_out(['models' => $list]);
}

/** OpenAPI sxemindən giriş sahələri: [{name, type, enum, default, min, max, required, description}] */
function schema_fields(array $oa): array
{
    $schemas = $oa['components']['schemas'] ?? [];
    $resolve = function ($s) use ($schemas) {
        for ($i = 0; $i < 4 && is_array($s) && isset($s['$ref']); $i++) {
            $s = $schemas[substr((string)$s['$ref'], strrpos((string)$s['$ref'], '/') + 1)] ?? null;
        }
        return is_array($s) ? $s : [];
    };
    $input = null;
    foreach ($oa['paths'] ?? [] as $ops) {
        $s = $ops['post']['requestBody']['content']['application/json']['schema'] ?? null;
        if ($s) {
            $input = $resolve($s);
            break;
        }
    }
    if (!$input) {
        return [];
    }
    $req = array_flip($input['required'] ?? []);
    $out = [];
    foreach ($input['properties'] ?? [] as $k => $p) {
        $p = $resolve($p);
        foreach (['anyOf', 'oneOf', 'allOf'] as $alt) {          // Optional[X] / Union[Enum, Obyekt]: əvvəl enum, sonra sadə tip, sonra istənilən
            $alts = array_values(array_filter(array_map($resolve, $p[$alt] ?? []), fn($a) => ($a['type'] ?? '') !== 'null'));
            $best = null;
            foreach ($alts as $a) {
                if (isset($a['enum'])) {
                    $best = $a;
                    break;
                }
            }
            foreach ($best ? [] : $alts as $a) {
                if (!in_array($a['type'] ?? 'object', ['object', 'array'], true)) {
                    $best = $a;
                    break;
                }
            }
            if ($best || $alts) {
                $p = ($best ?: $alts[0]) + $p;
            }
        }
        $type = (string)($p['type'] ?? 'string');
        $f = ['name' => (string)$k, 'type' => $type, 'required' => isset($req[$k]), 'description' => mb_substr((string)($p['description'] ?? $p['title'] ?? ''), 0, 220)];
        if (isset($p['enum']) && is_array($p['enum'])) {
            $f['enum'] = array_values(array_filter($p['enum'], 'is_scalar'));
        }
        if (array_key_exists('default', $p) && (is_scalar($p['default']) || $p['default'] === null)) {
            $f['default'] = $p['default'];
        }
        if (isset($p['minimum'])) {
            $f['min'] = $p['minimum'];
        }
        if (isset($p['maximum'])) {
            $f['max'] = $p['maximum'];
        }
        if ($type === 'array') {
            $f['items'] = (string)($resolve($p['items'] ?? [])['type'] ?? 'string');
        }
        $out[] = $f;
    }
    return $out;
}

if ($act === 'schema') {
    $id = (string)($in['endpoint_id'] ?? '');
    if (!endpoint_ok($id)) {
        json_out(['error' => 'yanlış model'], 400);
    }
    $ck = 'fals:' . md5($id);
    $fields = cache_get($ck, 24 * 3600);
    if ($fields === null) {
        $url = 'https://api.fal.ai/v1/models?' . http_build_query(['endpoint_id' => $id, 'expand' => 'openapi-3.0']);
        [$code, $j, $raw, $err] = fal_http('GET', $url, fal_key_of($pdo, $name), null, 15);
        if ($code < 200 || $code >= 300 || !is_array($j['models'][0] ?? null)) {
            json_out(['error' => fal_err($code, $j, $raw, $err)], 502);
        }
        $fields = schema_fields(is_array($j['models'][0]['openapi'] ?? null) ? $j['models'][0]['openapi'] : []);
        cache_set($ck, $fields);
    }
    json_out(['fields' => $fields]);
}

/* ---------- Generasiya ---------- */
if ($act === 'submit') {
    $key = fal_key_of($pdo, $name);
    if ($key === '') {
        json_out(['error' => 'Əvvəlcə 🔌 Fal Connection planetində fal açarını əlavə et'], 400);
    }
    $id = (string)($in['endpoint_id'] ?? '');
    $kind = (string)($in['kind'] ?? '');
    $input = $in['input'] ?? null;
    if (!endpoint_ok($id)) {
        json_out(['error' => 'Model seçilməyib'], 400);
    }
    if (!in_array($kind, FAL_CATS, true)) {
        json_out(['error' => 'yanlış növ'], 400);
    }
    if (!is_array($input) || $input !== [] && array_keys($input) === range(0, count($input) - 1)) {
        json_out(['error' => 'yanlış parametrlər'], 400);
    }
    $enc = json_encode((object)$input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (strlen($enc) > 20000) {
        json_out(['error' => 'Parametrlər çox böyükdür'], 400);
    }
    $rl = $pdo->prepare('SELECT COUNT(*) FROM media_gens WHERE user = ? AND created_at > ?');
    $rl->execute([$name, $now - 60]);
    if ((int)$rl->fetchColumn() >= 8) {
        json_out(['error' => 'Çox tez göndərirsən, bir dəqiqə gözlə'], 429);
    }
    [$code, $j, $raw, $err] = fal_http('POST', 'https://queue.fal.run/' . $id, $key, (array)$input, 25);
    if ($code < 200 || $code >= 300 || !is_string($j['request_id'] ?? null)) {
        json_out(['error' => fal_err($code, $j, $raw, $err)], $code === 401 || $code === 403 ? 400 : 502);
    }
    $su = (string)($j['status_url'] ?? '');
    $ru = (string)($j['response_url'] ?? '');
    if (!queue_url_ok($su) || !queue_url_ok($ru)) {
        json_out(['error' => 'fal gözlənilməyən cavab verdi'], 502);
    }
    $prompt = mb_substr(is_string($input['prompt'] ?? null) ? $input['prompt'] : '', 0, 4000);
    $pdo->prepare('INSERT INTO media_gens (user, model, kind, prompt, params, request_id, status_url, response_url, status, result, error, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $id, $kind, $prompt, $enc, mb_substr($j['request_id'], 0, 80), $su, $ru, 'queued', '[]', '', $now, $now]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'list') {
    $key = fal_key_of($pdo, $name);
    $st = $pdo->prepare('SELECT * FROM media_gens WHERE user = ? ORDER BY id DESC LIMIT 60');
    $st->execute([$name]);
    $rows = $st->fetchAll();
    $polled = 0;
    foreach ($rows as &$r) {                                   // gözləyənlər fal-dan yenilənir (sorğu başına ən çox 5)
        if (in_array($r['status'], ['queued', 'running'], true) && $polled < 5) {
            $r = gen_refresh($pdo, $r, $key, $now);
            $polled++;
        }
    }
    unset($r);
    json_out(['gens' => array_map('gen_out', $rows)]);
}
if ($act === 'delete') {
    $pdo->prepare('DELETE FROM media_gens WHERE id = ? AND user = ?')->execute([(int)($in['id'] ?? 0), $name]);
    json_out(['ok' => true]);
}

/* ---------- Redaktə üçün fayl yükləmə ---------- */
if ($act === 'upload') {
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
        $big = is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        json_out(['error' => $big ? 'Fayl serverin yükləmə limitindən böyükdür' : 'Fayl yüklənmədi'], 400);
    }
    $tmp = (string)$f['tmp_name'];
    $size = (int)filesize($tmp);
    $head = (string)file_get_contents($tmp, false, null, 0, 16);
    $img = @getimagesize($tmp);
    $ext = null;
    if ($img && isset([IMAGETYPE_PNG => 1, IMAGETYPE_JPEG => 1, IMAGETYPE_WEBP => 1, IMAGETYPE_GIF => 1][$img[2]])) {
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$img[2]];
        if ($size > 15 * 1024 * 1024) {
            json_out(['error' => 'Şəkil 15 MB-dan böyük ola bilməz'], 400);
        }
    } elseif (substr($head, 4, 4) === 'ftyp') {                // mp4 / mov
        $ext = str_contains(substr($head, 8, 4), 'qt') ? 'mov' : 'mp4';
    } elseif (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {      // webm
        $ext = 'webm';
    }
    if ($ext === null) {
        json_out(['error' => 'Yalnız şəkil (PNG, JPG, WEBP, GIF) və video (MP4, MOV, WEBM)'], 400);
    }
    if ($size > MEDIA_FILE_BYTES) {
        json_out(['error' => 'Fayl 50 MB-dan böyük ola bilməz'], 400);
    }
    $dir = media_dir();
    if ($dir === null) {
        json_out(['error' => 'Serverdə media qovluğu yaradılmadı'], 500);
    }
    foreach (glob($dir . '/*.*') ?: [] as $old) {               // köhnə yükləmələri təmizlə
        if (filemtime($old) < $now - MEDIA_FILE_TTL) {
            @unlink($old);
        }
    }
    $fn = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $fn)) {
        json_out(['error' => 'Fayl saxlanmadı'], 500);
    }
    json_out(['ok' => true, 'url' => site_base() . '/mediafile.php?f=' . $fn, 'type' => in_array($ext, ['mp4', 'mov', 'webm'], true) ? 'video' : 'image']);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
