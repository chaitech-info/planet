<?php
// Desktop Universe: başqasının kompüterindəki Claude Code-a komanda göndərmək. YALNIZ kompüterin sahibi icazə verəndə.
//  grant/revoke  : sahib kimə icazə verdiyini idarə edir (mode: approve = hər komandanı özü təsdiqləyir, auto = avtomatik; "hamıya" yalnız approve)
//  send          : icazəli istifadəçi sahibin agent növbəsinə komanda qoyur (approve rejimində 'pending' olur)
//  approve/deny  : sahib gözləyən komandanı təsdiq edir və ya rədd edir
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
if (str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {   // şəkilli komanda: FormData
    $in = $_POST;
} else {
    $in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
}
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$name = $me['name'];

/** Əlavə şəkilləri məzmununa görə yoxlayıb data/cmdimg/ qovluğuna saxlayır (command.php ilə eyni qaydalar). Xəta olsa cavab verib çıxır. */
function remote_save_images(?array $files): array
{
    if (!$files || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    $n = count($files['name']);
    if ($n > CMD_IMG_MAX) {
        json_out(['error' => 'Ən çox ' . CMD_IMG_MAX . ' şəkil göndərmək olar'], 400);
    }
    $dir = cmdimg_dir();
    if ($dir === null) {
        json_out(['error' => 'Şəkil qovluğu yaradıla bilmədi'], 500);
    }
    $saved = [];
    $fail = function (string $msg, int $code = 400) use (&$saved, $dir) {
        foreach ($saved as $f) {
            @unlink($dir . '/' . $f);
        }
        json_out(['error' => $msg], $code);
    };
    for ($i = 0; $i < $n; $i++) {
        $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err !== UPLOAD_ERR_OK) {
            $fail(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Şəkil çox böyükdür (ən çox 5 MB)' : 'Şəkil yüklənmədi');
        }
        $tmp = (string)$files['tmp_name'][$i];
        $size = (int)$files['size'][$i];
        if ($size < 16 || $size > CMD_IMG_BYTES || !is_uploaded_file($tmp)) {
            $fail('Şəkil 5 MB-dan böyük olmamalıdır');
        }
        $info = @getimagesize($tmp);                       // həqiqətən şəkildir? (uzantıya yox, məzmuna baxırıq)
        $ext = $info ? (CMD_IMG_TYPES[$info['mime']] ?? null) : null;
        if ($ext === null) {
            $fail('Yalnız PNG, JPEG, GIF və ya WebP şəkil qəbul olunur');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            $fail('Şəkil saxlanmadı', 500);
        }
        $saved[] = $name;
    }
    return $saved;
}

function user_exists(PDO $pdo, string $n): bool
{
    $st = $pdo->prepare("SELECT 1 FROM users WHERE name = ? AND role != 'student'");   // studentlərin agenti yoxdur
    $st->execute([$n]);
    return (bool)$st->fetchColumn();
}

if ($act === 'grant') {
    $grantee = (string)($in['grantee'] ?? '');
    $mode = (string)($in['mode'] ?? 'approve') === 'auto' ? 'auto' : 'approve';
    if ($grantee === '*') {
        $mode = 'approve';                                   // hamıya yalnız hər dəfə təsdiqlə
    } elseif ($grantee === $name || !user_exists($pdo, $grantee)) {
        json_out(['error' => 'istifadəçi yanlışdır'], 400);
    }
    $pdo->prepare('DELETE FROM remote_grants WHERE owner = ? AND grantee = ?')->execute([$name, $grantee]);
    $pdo->prepare('INSERT INTO remote_grants (owner, grantee, mode, created_at) VALUES (?,?,?,?)')->execute([$name, $grantee, $mode, $now]);
    json_out(['ok' => true, 'mode' => $mode]);
}

if ($act === 'revoke') {
    $pdo->prepare('DELETE FROM remote_grants WHERE owner = ? AND grantee = ?')->execute([$name, (string)($in['grantee'] ?? '')]);
    // icazə alınanda hələ icra olunmamış uzaq komandalar da ləğv olunur
    $pdo->prepare("UPDATE commands SET status = 'canceled', result = ?, finished_at = ? WHERE user = ? AND creator = ? AND status IN ('pending','queued')")
        ->execute(['İcazə geri alındı', $now, $name, (string)($in['grantee'] ?? '')]);
    json_out(['ok' => true]);
}

if ($act === 'send') {
    $target = (string)($in['target'] ?? '');
    $project = trim((string)($in['project'] ?? ''));
    $prompt = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($in['prompt'] ?? '')));
    if ($target === $name || !user_exists($pdo, $target)) {
        json_out(['error' => 'hədəf istifadəçi yanlışdır'], 400);
    }
    if ($project === '' || mb_strlen($project) > 128) {
        json_out(['error' => 'proyekt seçilməlidir'], 400);
    }
    if ($prompt === '' || mb_strlen($prompt) > 3500) {
        json_out(['error' => 'prompt 1-3500 simvol olmalıdır'], 400);
    }
    // icazə: sahib mənə (və ya hamıya) verib? şəxsi icazə hamıya olandan üstündür
    $g = $pdo->prepare("SELECT grantee, mode FROM remote_grants WHERE owner = ? AND grantee IN (?, '*') ORDER BY (grantee = '*') ASC LIMIT 1");
    $g->execute([$target, $name]);
    $grant = $g->fetch();
    if (!$grant) {
        json_out(['error' => $target . ' sənə öz kompüterinə komanda göndərməyə icazə verməyib'], 403);
    }
    // proyekt hədəfin özünün proyektlərindən olmalıdır
    $pj = $pdo->prepare('SELECT 1 FROM sessions WHERE user = ? AND project = ? LIMIT 1');
    $pj->execute([$target, $project]);
    if (!$pj->fetchColumn()) {
        json_out(['error' => 'Bu proyekt ' . $target . ' tərəfində qurulmayıb'], 400);
    }
    $c = $pdo->prepare("SELECT COUNT(*) FROM commands WHERE user = ? AND status IN ('pending','queued','running')");
    $c->execute([$target]);
    if ((int)$c->fetchColumn() >= 5) {
        json_out(['error' => 'Hədəfin növbəsində artıq 5 tapşırıq var, gözlə'], 429);
    }
    $rl = $pdo->prepare('SELECT COUNT(*) FROM commands WHERE creator = ? AND created_at > ?');
    $rl->execute([$name, $now - 60]);
    if ((int)$rl->fetchColumn() >= 5) {
        json_out(['error' => 'Çox tez göndərirsən, bir az gözlə'], 429);
    }
    $status = $grant['mode'] === 'auto' ? 'queued' : 'pending';
    $fresh = !empty($in['fresh']) && $in['fresh'] !== 'false' ? 1 : 0;
    $images = remote_save_images($_FILES['images'] ?? null);
    $full = '[' . $name . ' uzaqdan göndərdi (Desktop Universe)]' . "\n" . $prompt;
    $pdo->prepare('INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, creator, fresh, images) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$target, $project, $full, $status, '', $now, 0, 0, $name, $fresh, $images ? json_encode($images) : '']);
    if (random_int(1, 20) === 1) {
        cmdimg_cleanup($pdo);
    }
    if ($status === 'pending') {
        notify_later("🖥 $name komanda göndərdi → $target (təsdiq gözləyir)\nProyekt: $project");
    }
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'status' => $status, 'images' => $images]);
}

if ($act === 'approve' || $act === 'deny') {
    $q = $pdo->prepare("SELECT id FROM commands WHERE id = ? AND user = ? AND status = 'pending'");
    $q->execute([(int)($in['id'] ?? 0), $name]);                // yalnız kompüterin sahibi
    $id = $q->fetchColumn();
    if ($id === false) {
        json_out(['error' => 'gözləyən komanda tapılmadı'], 404);
    }
    if ($act === 'approve') {
        $pdo->prepare("UPDATE commands SET status = 'queued' WHERE id = ? AND status = 'pending'")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE commands SET status = 'canceled', result = ?, finished_at = ? WHERE id = ? AND status = 'pending'")->execute(['Sahibi rədd etdi', $now, $id]);
    }
    json_out(['ok' => true]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
