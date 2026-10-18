<?php
// Kompüterdəki agent: növbədən tapşırıq götürür və nəticəni qaytarır. Yalnız öz tokeninə aid tapşırıqlar.
require __DIR__ . '/db.php';

$user = token_user();
if ($user === null) {
    json_out(['error' => 'token yanlışdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST lazımdır'], 405);
}
$act = (string)($_GET['a'] ?? '');
$pdo = db();
$now = time();
$in = json_decode(file_get_contents('php://input', false, null, 0, 65536) ?: '', true);
if (!is_array($in)) {
    $in = [];
}

// ürək döyüntüsü
$has = $pdo->prepare('SELECT 1 FROM agents WHERE user = ?');
$has->execute([$user]);
$ok = array_key_exists('claude_ok', $in) ? ($in['claude_ok'] ? 1 : 0) : null;
$known = (bool)$has->fetchColumn();
$has->closeCursor();                        // açıq kursor SQLite SHARED kilidini saxlayır: claim wait zamanı bütün yazanları bloklayırdı
if ($known) {
    if ($ok === null) {
        $pdo->prepare('UPDATE agents SET last_seen = ? WHERE user = ?')->execute([$now, $user]);
    } else {
        $pdo->prepare('UPDATE agents SET last_seen = ?, claude_ok = ? WHERE user = ?')->execute([$now, $ok, $user]);
    }
} else {
    $pdo->prepare('INSERT INTO agents (user, last_seen, claude_ok) VALUES (?, ?, ?)')->execute([$user, $now, $ok ?? 1]);
}

// Bir növbəli tapşırığı "running" edir. $skip: agentin hazırda götürə bilmədiyi proyektlər (paralel işçilər üçün).
function claim_one(PDO $pdo, string $user, int $now, array $skip): ?array
{
    $pdo->beginTransaction();
    $not = $skip ? ' AND project NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')' : '';
    $q = $pdo->prepare("SELECT * FROM commands WHERE user = ? AND status = 'queued'$not ORDER BY id ASC LIMIT 1");
    $q->execute(array_merge([$user], $skip));
    $row = $q->fetch();
    if (!$row) {
        $pdo->commit();
        return null;
    }
    $u = $pdo->prepare("UPDATE commands SET status = 'running', started_at = ?, progress = '' WHERE id = ? AND status = 'queued'");
    $u->execute([$now, $row['id']]);
    $pdo->commit();
    if ($u->rowCount() !== 1) {
        return null;
    }
    $names = project_names($pdo, $row['project']);   // ilkin qovluq adları (ad dəyişibsə)
    $images = array_values(array_filter((array)json_decode((string)$row['images'], true), fn($f) => is_string($f) && cmdimg_name_ok($f)));
    $id = (int)$row['id'];
    emit_event($pdo, 'command.started', $user, $row['project'], $id, ['id' => $id]);
    if ((int)($row['said_at'] ?? 0) > 0) {          // gecikmə: istifadəçinin dediyi an → agentin götürdüyü an (ms)
        try {
            $pdo->prepare('INSERT INTO jarvis_latency (user, kind, ref, said_at, start_at, first_at, done_at, meta, created_at) VALUES (?,?,?,?,?,0,0,?,?)')
                ->execute([$user, 'command', $id, (int)$row['said_at'], (int)floor(microtime(true) * 1000), json_encode(['kind' => $row['kind'] ?? 'prompt', 'model' => $row['model'] ?? '']), $now]);
        } catch (Throwable $e) {
            error_log('latency start: ' . $e->getMessage());
        }
    }
    // kind/model/mode: yeni agent üçün (think, ops, --model, --permission-mode plan); köhnə agent bu açarları sadəcə nəzərə almır
    return ['id' => $id, 'project' => $row['project'], 'names' => $names, 'prompt' => $row['prompt'], 'creator' => $row['creator'], 'fresh' => (int)$row['fresh'], 'images' => $images, 'chat' => (int)$row['chat'],
        'kind' => (string)($row['kind'] ?? 'prompt'), 'model' => (string)($row['model'] ?? ''), 'mode' => (string)($row['mode'] ?? ''), 'perm' => (string)($row['perm'] ?? '') ?: 'acceptEdits'];   // şəkillər: cmdimg.php?f=<ad>
}

/** Növbədə götürülə bilən iş varmı? (long-poll üçün ucuz yoxlama: commands (user, status) indeksi) */
function claimable(PDO $pdo, string $user, array $skip): bool
{
    $not = $skip ? ' AND project NOT IN (' . implode(',', array_fill(0, count($skip), '?')) . ')' : '';
    $q = $pdo->prepare("SELECT 1 FROM commands WHERE user = ? AND status = 'queued'$not LIMIT 1");
    $q->execute(array_merge([$user], $skip));
    $r = (bool)$q->fetchColumn();
    $q->closeCursor();                      // SQLite kilidi dərhal burax (gözləmə dövrəsində yazanları ləngitməsin)
    return $r;
}

/** Cədvəllər (workstream E): claim zamanı ən çox 30 s-dən bir, bütün agentlər arasında bir dəfə (settings.sched_at ilə atomik). */
function maybe_schedules(PDO $pdo, int $now): void
{
    try {
        if (!is_file(__DIR__ . '/mission_lib.php')) {
            return;
        }
        require_once __DIR__ . '/mission_lib.php';
        if (!function_exists('schedules_due')) {
            return;
        }
        $u = $pdo->prepare("UPDATE settings SET v = ? WHERE k = 'sched_at' AND v < ?");
        $u->execute([(string)$now, (string)($now - 30)]);
        if ($u->rowCount() !== 1) {
            $e = $pdo->prepare("SELECT 1 FROM settings WHERE k = 'sched_at'");
            $e->execute();
            if ($e->fetchColumn()) {
                return;                             // başqa sorğu son 30 s-də işlədib
            }
            $pdo->prepare("INSERT INTO settings (k, v) VALUES ('sched_at', ?)")->execute([(string)$now]);
        }
        schedules_due($pdo);
    } catch (Throwable $e) {
        error_log('schedules_due: ' . $e->getMessage());
    }
}

if ($act === 'claim') {
    $pdo->prepare("UPDATE commands SET status = 'failed', result = ?, finished_at = ? WHERE user = ? AND status = 'running' AND started_at < ?")
        ->execute(['Vaxt bitdi: agent cavab vermədi', $now, $user, $now - 2400]);
    // slots: paralel işçili agent bir dəfəyə neçə tapşırıq istəyir (1..4). Verilməyibsə köhnə cavab forması (tək obyekt).
    $slots = max(1, min(4, (int)($in['slots'] ?? 1)));
    $skip = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => is_scalar($x) ? mb_substr(trim((string)$x), 0, 128) : '', (array)($in['skip'] ?? [])), 'strlen'))), 0, 100);
    maybe_schedules($pdo, $now);
    // ops komandası Claude promptu deyil (JSON): köhnə agent (wait/kinds göndərmir) onu prompt kimi işlətməsin, dərhal "failed"
    if (!isset($in['wait']) && !isset($in['kinds'])) {
        $old = $pdo->prepare("SELECT id, project FROM commands WHERE user = ? AND status = 'queued' AND kind = 'ops'");
        $old->execute([$user]);
        foreach ($old->fetchAll() as $o) {
            $f = $pdo->prepare("UPDATE commands SET status = 'failed', result = ?, finished_at = ? WHERE id = ? AND status = 'queued'");
            $f->execute(['Agent köhnədir: ops əmrlərini dəstəkləmir (agenti yenilə)', $now, $o['id']]);
            if ($f->rowCount() === 1) {
                emit_event($pdo, 'command.done', $user, $o['project'], (int)$o['id'], ['id' => (int)$o['id'], 'status' => 'failed', 'result_tail' => 'Agent köhnədir: ops dəstəkləmir']);
                emit_event($pdo, 'ops.result', $user, $o['project'], (int)$o['id'], ['id' => (int)$o['id'], 'op' => '', 'ok' => false, 'data' => ['error' => 'agent köhnədir']]);
            }
        }
    }
    // wait (≤ 25 s): long-poll; iş gələn kimi cavab verir. Verilməyibsə köhnə davranış (dərhal cavab).
    $wait = max(0, min(25, (int)($in['wait'] ?? 0)));
    if ($wait > 0 && !claimable($pdo, $user, $skip)) {
        @set_time_limit($wait + 20);
        ignore_user_abort(false);
        $end = microtime(true) + $wait;
        $beat = time();
        do {
            usleep(250000);
            if (time() - $beat >= 10) {          // uzun gözləmədə də "onlayn" görünsün
                $beat = time();
                $pdo->prepare('UPDATE agents SET last_seen = ? WHERE user = ?')->execute([$beat, $user]);
            }
        } while (microtime(true) < $end && !claimable($pdo, $user, $skip));
        $now = time();                              // started_at gözləmədən sonrakı an olsun
    }
    if (!isset($in['slots'])) {
        $row = claim_one($pdo, $user, $now, $skip);
        json_out($row ?? ['none' => true]);
    }
    $items = [];
    while (count($items) < $slots && ($row = claim_one($pdo, $user, $now, $skip))) {
        $items[] = $row;
    }
    json_out($items ? ['items' => $items] : ['none' => true]);
}

if ($act === 'progress') {                  // canlı gedişat: {step, files[], pct, at}; yalnız öz tapşırığı, yalnız işləyərkən
    $id = (int)($in['id'] ?? 0);
    $p = $in['progress'] ?? null;
    if ($id <= 0 || !is_array($p)) {
        json_out(['error' => 'id və progress lazımdır'], 400);
    }
    $files = array_slice(array_values(array_filter(array_map(fn($f) => is_scalar($f) ? mb_substr(trim((string)$f), 0, 200) : '', (array)($p['files'] ?? [])), 'strlen')), 0, 6);
    $pct = isset($p['pct']) && is_numeric($p['pct']) ? max(0, min(99, (int)$p['pct'])) : null;   // 100 yalnız finish ilə
    $prog = json_encode(['step' => mb_substr(trim((string)(is_scalar($p['step'] ?? null) ? $p['step'] : '')), 0, 160), 'files' => $files, 'pct' => $pct, 'at' => $now], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare("UPDATE commands SET progress = ? WHERE id = ? AND user = ? AND status = 'running'");
    $st->execute([$prog, $id, $user]);
    if ($st->rowCount() === 1) {
        emit_event($pdo, 'command.progress', $user, '', $id, ['id' => $id, 'step' => json_decode($prog, true)['step'], 'pct' => $pct, 'files' => $files]);
    }
    json_out(['ok' => $st->rowCount() === 1]);
}

if ($act === 'finish') {
    $status = ($in['status'] ?? '') === 'done' ? 'done' : 'failed';
    $id = (int)($in['id'] ?? 0);
    $cq = $pdo->prepare('SELECT * FROM commands WHERE id = ? AND user = ?');
    $cq->execute([$id, $user]);
    $cmd = $cq->fetch() ?: [];
    $isOps = ($cmd['kind'] ?? '') === 'ops';
    $result = mb_substr((string)($in['result'] ?? ''), 0, $isOps ? 16000 : 8000);   // ops: JSON nəticə kəsilməsin
    $st = $pdo->prepare("UPDATE commands SET status = ?, result = ?, finished_at = ? WHERE id = ? AND user = ? AND status = 'running'");
    $st->execute([$status, $result, $now, $id, $user]);
    if ($st->rowCount() === 1) {
        $tail = mb_strlen($result) > 600 ? '…' . mb_substr($result, -599) : $result;
        emit_event($pdo, 'command.done', $user, (string)($cmd['project'] ?? ''), $id, ['id' => $id, 'status' => $status, 'result_tail' => $tail]);
        if ($isOps) {                            // ops nəticəsi: brauzer RT ops.result (ref = id) gözləyir; böyükdürsə ops.php get ilə alsın
            $spec = json_decode((string)($cmd['prompt'] ?? ''), true);
            $data = json_decode($result, true);
            $data = is_array($data) ? $data : ['text' => mb_substr($result, 0, 4000)];
            if (strlen((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) > 7600) {
                $data = ['truncated' => true];
            }
            $ok = $status === 'done' && (!array_key_exists('ok', $data) || (bool)$data['ok']);
            emit_event($pdo, 'ops.result', $user, (string)($cmd['project'] ?? ''), $id, ['id' => $id, 'op' => is_array($spec) ? (string)($spec['op'] ?? '') : '', 'ok' => $ok, 'data' => $data]);
        }
        if ((int)($cmd['said_at'] ?? 0) > 0) {
            try {
                $pdo->prepare("UPDATE jarvis_latency SET done_at = ? WHERE kind = 'command' AND ref = ? AND user = ?")->execute([(int)floor(microtime(true) * 1000), $id, $user]);
            } catch (Throwable $e) {
                error_log('latency done: ' . $e->getMessage());
            }
        }
        advance_task($pdo, $id, $user, $status, $result, $now);   // lövhə: iş → yoxlama → bitdi / düzəliş (db.php)
        $missionId = (int)($cmd['mission_id'] ?? 0);   // missiyanın addımıdırsa növbəti addımlara keç
        if ($missionId > 0 && is_file(__DIR__ . '/mission_lib.php')) {
            require_once __DIR__ . '/mission_lib.php';
            if (function_exists('mission_advance')) {
                try {
                    mission_advance($pdo, $missionId);
                } catch (Throwable $e) {
                    error_log('mission_advance: ' . $e->getMessage());   // komanda artıq bağlanıb, agentə xəta qaytarma
                }
            }
        }
    }
    json_out(['ok' => $st->rowCount() === 1]);
}

if ($act === 'poll') {                      // agent işləyərkən: ləğv olunubmu?
    $q = $pdo->prepare('SELECT status FROM commands WHERE id = ? AND user = ?');
    $q->execute([(int)($in['id'] ?? 0), $user]);
    json_out(['cancel' => $q->fetchColumn() === 'canceled']);
}

if ($act === 'ping') {
    if (isset($in['projects']) && is_array($in['projects'])) {
        $names = array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 128), $in['projects']), 'strlen')), 0, 100);
        $pdo->prepare('UPDATE agents SET projects = ? WHERE user = ?')->execute([implode("\n", $names), $user]);
    }
    if (isset($in['workers'])) {             // paralel işçi sayı (yeni agent); sütun yoxdursa bir dəfə əlavə et
        $w = max(1, min(4, (int)$in['workers']));
        try {
            $pdo->prepare('UPDATE agents SET workers = ? WHERE user = ?')->execute([$w, $user]);
        } catch (Throwable $e) {
            try {
                $pdo->exec('ALTER TABLE agents ADD COLUMN workers INT NOT NULL DEFAULT 1');
                $pdo->prepare('UPDATE agents SET workers = ? WHERE user = ?')->execute([$w, $user]);
            } catch (Throwable $e) {
                // sütun əlavə olunmadı: köhnə davranış
            }
        }
    }
    if (isset($in['profile'])) {             // icazə profili (basic|dev|ops|full) və imkanlar (claude versiyası, MCP, warm sessiyalar): ops.php yoxlayır
        $prof = in_array($in['profile'], ['basic', 'dev', 'ops', 'full'], true) ? $in['profile'] : 'basic';
        $caps = is_array($in['caps'] ?? null) ? json_encode($in['caps'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
        $pdo->prepare('UPDATE agents SET profile = ?, caps = ? WHERE user = ?')->execute([$prof, mb_substr((string)$caps, 0, 4000), $user]);
    }
    json_out(['ok' => true, 'user' => $user]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
