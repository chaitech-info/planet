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
    $self = $target === $name;                               // öz kompüterinə: icazə lazım deyil, dərhal işə düşür
    if (!$self && !user_exists($pdo, $target)) {
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
    $grant = $self ? ['grantee' => $name, 'mode' => 'auto'] : $g->fetch();
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
    // Söhbət: fresh = yeni söhbət (id-si özü olur); chat verilibsə o söhbət davam edir (proyekti söhbətinkidir);
    // heç biri yoxdursa bu proyektdəki ən son söhbət, o da yoxdursa yeni
    $chat = 0;
    if (!$fresh) {
        $want = (int)($in['chat'] ?? 0);
        if ($want > 0) {
            $cq = $pdo->prepare('SELECT project FROM commands WHERE id = ? AND chat = id AND user = ? AND creator = ?');
            $cq->execute([$want, $target, $name]);
            $cp = $cq->fetchColumn();
            if ($cp === false) {
                json_out(['error' => 'söhbət tapılmadı'], 404);
            }
            $chat = $want;
            $project = (string)$cp;
        } else {
            $lq = $pdo->prepare('SELECT chat FROM commands WHERE user = ? AND creator = ? AND project = ? AND chat > 0 ORDER BY id DESC LIMIT 1');
            $lq->execute([$target, $name, $project]);
            $chat = (int)$lq->fetchColumn();
        }
    }
    $images = remote_save_images($_FILES['images'] ?? null);
    $full = $self ? $prompt : '[' . $name . ' uzaqdan göndərdi (Desktop Universe)]' . "\n" . $prompt;
    $pdo->prepare('INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, creator, fresh, images, chat) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$target, $project, $full, $status, '', $now, 0, 0, $name, $fresh, $images ? json_encode($images) : '', $chat]);
    $id = (int)$pdo->lastInsertId();
    if ($status === 'queued') {
        emit_event($pdo, 'command.queued', $target, $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr($full, 0, 300), 'kind' => 'prompt']);
    }
    if ($chat === 0) {                                           // yeni söhbət: ilk komandanın id-si söhbətin nömrəsidir
        $chat = $id;
        $pdo->prepare('UPDATE commands SET chat = ? WHERE id = ?')->execute([$chat, $id]);
    }
    if (random_int(1, 20) === 1) {
        cmdimg_cleanup($pdo);
    }
    if ($status === 'pending') {
        notify_later("🖥 $name komanda göndərdi → $target (təsdiq gözləyir)\nProyekt: $project");
    }
    json_out(['ok' => true, 'id' => $id, 'status' => $status, 'images' => $images, 'chat' => $chat, 'project' => $project]);
}

/** Söhbətlərə baxış hüququ: sahib hamısını görür, qonaq yalnız özünün göndərdiklərini. */
function chat_scope(PDO $pdo, string $target, string $name): array
{
    if ($target !== $name && !user_exists($pdo, $target)) {
        json_out(['error' => 'hədəf istifadəçi yanlışdır'], 400);
    }
    return $target === $name ? ["user = ? AND creator != ''", [$target]] : ['user = ? AND creator = ?', [$target, $name]];   // sahib: gələnlər + özünün bu səhifədən yazdıqları
}

if ($act === 'chats') {                                           // söhbət siyahısı: ən son yazılan əvvəl
    [$where, $args] = chat_scope($pdo, (string)($in['target'] ?? ''), $name);
    $q = $pdo->prepare("SELECT chat, MIN(id) AS first_id, MAX(id) AS last_id, COUNT(*) AS n, MAX(created_at) AS last_at FROM commands WHERE $where AND chat > 0 GROUP BY chat ORDER BY last_at DESC LIMIT 60");
    $q->execute($args);
    $groups = $q->fetchAll();
    $ids = [];
    foreach ($groups as $g) {
        $ids[] = (int)$g['first_id'];
        $ids[] = (int)$g['last_id'];
    }
    $rows = [];
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $r = $pdo->prepare("SELECT id, project, creator, prompt, status FROM commands WHERE id IN ($ph)");
        $r->execute($ids);
        foreach ($r->fetchAll() as $row) {
            $rows[(int)$row['id']] = $row;
        }
    }
    $chats = [];
    foreach ($groups as $g) {
        $first = $rows[(int)$g['first_id']] ?? null;
        $last = $rows[(int)$g['last_id']] ?? null;
        if (!$first) {
            continue;
        }
        $title = trim((string)preg_replace('/^\[[^\]]*\]\n/', '', $first['prompt']));
        $title = preg_replace('/\s+/u', ' ', mb_substr($title, 0, 90));
        $chats[] = ['chat' => (int)$g['chat'], 'project' => $first['project'], 'creator' => $first['creator'], 'title' => $title, 'n' => (int)$g['n'],
            'last_at' => (int)$g['last_at'], 'status' => $last ? $last['status'] : ''];
    }
    json_out(['chats' => $chats]);
}

if ($act === 'chat') {                                            // bir söhbətin bütün komandaları (köhnədən yeniyə)
    [$where, $args] = chat_scope($pdo, (string)($in['target'] ?? ''), $name);
    $chat = (int)($in['chat'] ?? 0);
    $q = $pdo->prepare("SELECT id, user, project, prompt, status, result, created_at, started_at, finished_at, creator, images, kind, chat FROM commands WHERE $where AND chat = ? ORDER BY id ASC LIMIT 200");
    $q->execute([...$args, $chat]);
    $msgs = $q->fetchAll();
    foreach ($msgs as &$c) {
        $c['result'] = mb_substr($c['result'], 0, 8000);
        $c['images'] = array_values(array_filter((array)json_decode((string)$c['images'], true), 'is_string'));
    }
    unset($c);
    json_out(['chat' => $chat, 'messages' => $msgs]);
}

/* ---- code.php (tam səhifəlik Claude Code): kompüterlər, sessiya tarixçəsi, komandanın addımları ---- */

/** Kompüterin Claude Code sessiyalarına baxış: yalnız sahibi və ona icazə verilənlər */
function can_see_sessions(PDO $pdo, string $target, string $name): bool
{
    if ($target === $name) {
        return true;
    }
    $g = $pdo->prepare("SELECT 1 FROM remote_grants WHERE owner = ? AND grantee IN (?, '*') LIMIT 1");
    $g->execute([$target, $name]);
    return (bool)$g->fetchColumn();
}

if ($act === 'overview') {                                        // bütün kompüterlər: onlayn, Claude girişi, növbə, icazə, proyektlər
    $users = $pdo->query("SELECT name FROM users WHERE role != 'student' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $agents = [];
    foreach ($pdo->query('SELECT user, last_seen, claude_ok FROM agents')->fetchAll() as $r) {
        $agents[$r['user']] = $r;
    }
    $load = [];
    foreach ($pdo->query("SELECT user, status, COUNT(*) AS n FROM commands WHERE status IN ('pending','queued','running') GROUP BY user, status")->fetchAll() as $r) {
        $load[$r['user']][$r['status']] = (int)$r['n'];
    }
    $g = $pdo->prepare("SELECT owner, grantee, mode FROM remote_grants WHERE owner = ? OR grantee IN (?, '*')");
    $g->execute([$name, $name]);
    $grants = $g->fetchAll();
    $pq = $pdo->prepare('SELECT DISTINCT user, project FROM sessions WHERE updated_at > ? ORDER BY project');
    $pq->execute([$now - 30 * 86400]);
    $projects = [];
    foreach ($pq->fetchAll() as $r) {
        $projects[$r['user']][] = $r['project'];
    }
    $out = [];
    foreach ($users as $u) {
        $a = $agents[$u] ?? null;
        $mine = null;                                            // mənə verilən icazə: şəxsi icazə hamıya olandan üstündür
        foreach ($grants as $x) {
            if ($x['owner'] === $u && ($x['grantee'] === $name || ($x['grantee'] === '*' && !$mine))) {
                $mine = $x;
            }
        }
        $out[] = [
            'name' => $u, 'me' => $u === $name, 'agent' => (bool)$a, 'online' => $a && $now - (int)$a['last_seen'] < 30,
            'last_seen' => $a ? (int)$a['last_seen'] : 0, 'login' => !$a || (int)$a['claude_ok'] === 1,
            'running' => $load[$u]['running'] ?? 0, 'queued' => $load[$u]['queued'] ?? 0, 'pending' => $load[$u]['pending'] ?? 0,
            'grant' => $u === $name ? 'auto' : ($mine ? $mine['mode'] : null),   // öz kompüterinə həmişə avtomatik 'projects' => $projects[$u] ?? [],
            'granted' => $u === $name ? array_values(array_map(fn($x) => ['grantee' => $x['grantee'], 'mode' => $x['mode']], array_filter($grants, fn($x) => $x['owner'] === $name))) : [],
        ];
    }
    $pd = $pdo->prepare("SELECT id, project, prompt, creator, images, created_at, chat FROM commands WHERE user = ? AND status = 'pending' ORDER BY id ASC LIMIT 20");
    $pd->execute([$name]);
    $pend = $pd->fetchAll();
    foreach ($pend as &$c) {
        $c['images'] = array_values(array_filter((array)json_decode((string)$c['images'], true), 'is_string'));
    }
    unset($c);
    json_out(['now' => $now, 'me' => $name, 'computers' => $out, 'pending' => $pend]);
}

if ($act === 'sessions') {                                        // kompüterin Claude Code sessiyaları (hook-dan, son 30 gün)
    $target = (string)($in['target'] ?? '');
    if (!can_see_sessions($pdo, $target, $name)) {
        json_out(['error' => $target . ' sənə icazə verməyib'], 403);
    }
    $q = $pdo->prepare('SELECT session_id, project, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at FROM sessions WHERE user = ? AND updated_at > ? ORDER BY updated_at DESC LIMIT 100');
    $q->execute([$target, $now - 30 * 86400]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) {
        $r['last_prompt'] = mb_substr($r['last_prompt'], 0, 200);
        $r['last_summary'] = mb_substr($r['last_summary'], 0, 200);
    }
    unset($r);
    json_out(['sessions' => $rows]);
}

if ($act === 'session') {                                         // bir sessiyanın hadisələri (köhnədən yeniyə, son 400)
    $target = (string)($in['target'] ?? '');
    $sid = (string)($in['session_id'] ?? '');
    if (!can_see_sessions($pdo, $target, $name)) {
        json_out(['error' => $target . ' sənə icazə verməyib'], 403);
    }
    $s = $pdo->prepare('SELECT session_id, project, cwd, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at FROM sessions WHERE user = ? AND session_id = ?');
    $s->execute([$target, $sid]);
    $sess = $s->fetch();
    if (!$sess) {
        json_out(['error' => 'sessiya tapılmadı'], 404);
    }
    $sess['cwd'] = $target === $name ? $sess['cwd'] : '';                // tam yol yalnız sahibə
    $e = $pdo->prepare('SELECT id, event, tool, summary, created_at FROM events WHERE session_id = ? AND user = ? ORDER BY id DESC LIMIT 400');
    $e->execute([$sid, $target]);
    json_out(['session' => $sess, 'events' => array_reverse($e->fetchAll())]);
}

if ($act === 'steps') {                                           // komanda icra olunarkən Claude-un addımları (hook hadisələri)
    [$where, $args] = chat_scope($pdo, (string)($in['target'] ?? ''), $name);
    $q = $pdo->prepare("SELECT user, project, started_at, finished_at FROM commands WHERE $where AND id = ?");
    $q->execute([...$args, (int)($in['id'] ?? 0)]);
    $c = $q->fetch();
    if (!$c) {
        json_out(['error' => 'komanda tapılmadı'], 404);
    }
    if (!(int)$c['started_at']) {
        json_out(['steps' => []]);
    }
    $e = $pdo->prepare("SELECT id, event, tool, summary, created_at FROM events WHERE project = ? AND user = ? AND created_at >= ? AND created_at <= ? AND tool != '' ORDER BY id ASC LIMIT 150");
    $e->execute([$c['project'], $c['user'], (int)$c['started_at'], (int)$c['finished_at'] ?: $now]);
    json_out(['steps' => $e->fetchAll()]);
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
