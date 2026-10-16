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
if ($has->fetchColumn()) {
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
    $q = $pdo->prepare("SELECT id, project, prompt, creator, fresh, images, chat FROM commands WHERE user = ? AND status = 'queued'$not ORDER BY id ASC LIMIT 1");
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
    return ['id' => (int)$row['id'], 'project' => $row['project'], 'names' => $names, 'prompt' => $row['prompt'], 'creator' => $row['creator'], 'fresh' => (int)$row['fresh'], 'images' => $images, 'chat' => (int)$row['chat']];   // şəkillər: cmdimg.php?f=<ad>
}

if ($act === 'claim') {
    $pdo->prepare("UPDATE commands SET status = 'failed', result = ?, finished_at = ? WHERE user = ? AND status = 'running' AND started_at < ?")
        ->execute(['Vaxt bitdi: agent cavab vermədi', $now, $user, $now - 2400]);
    // slots: paralel işçili agent bir dəfəyə neçə tapşırıq istəyir (1..4). Verilməyibsə köhnə cavab forması (tək obyekt).
    $slots = max(1, min(4, (int)($in['slots'] ?? 1)));
    $skip = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => is_scalar($x) ? mb_substr(trim((string)$x), 0, 128) : '', (array)($in['skip'] ?? [])), 'strlen'))), 0, 100);
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
    json_out(['ok' => $st->rowCount() === 1]);
}

if ($act === 'finish') {
    $status = ($in['status'] ?? '') === 'done' ? 'done' : 'failed';
    $result = mb_substr((string)($in['result'] ?? ''), 0, 8000);
    $st = $pdo->prepare("UPDATE commands SET status = ?, result = ?, finished_at = ? WHERE id = ? AND user = ? AND status = 'running'");
    $st->execute([$status, $result, $now, (int)($in['id'] ?? 0), $user]);
    if ($st->rowCount() === 1) {
        advance_task($pdo, (int)($in['id'] ?? 0), $user, $status, $result, $now);   // lövhə: iş → yoxlama → bitdi / düzəliş (db.php)
        $mq = $pdo->prepare('SELECT mission_id FROM commands WHERE id = ?');   // missiyanın addımıdırsa növbəti addımlara keç
        $mq->execute([(int)($in['id'] ?? 0)]);
        $missionId = (int)$mq->fetchColumn();
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
    json_out(['ok' => true, 'user' => $user]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
