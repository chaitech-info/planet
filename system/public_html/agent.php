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

if ($act === 'claim') {
    $pdo->prepare("UPDATE commands SET status = 'failed', result = ?, finished_at = ? WHERE user = ? AND status = 'running' AND started_at < ?")
        ->execute(['Vaxt bitdi: agent cavab vermədi', $now, $user, $now - 2400]);
    $pdo->beginTransaction();
    $q = $pdo->prepare("SELECT id, project, prompt, creator, fresh, images, chat, perm FROM commands WHERE user = ? AND status = 'queued' ORDER BY id ASC LIMIT 1");
    $q->execute([$user]);
    $row = $q->fetch();
    if (!$row) {
        $pdo->commit();
        json_out(['none' => true]);
    }
    $u = $pdo->prepare("UPDATE commands SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'");
    $u->execute([$now, $row['id']]);
    $pdo->commit();
    if ($u->rowCount() !== 1) {
        json_out(['none' => true]);
    }
    $names = project_names($pdo, $row['project']);   // ilkin qovluq adları (ad dəyişibsə)
    $images = array_values(array_filter((array)json_decode((string)$row['images'], true), fn($f) => is_string($f) && cmdimg_name_ok($f)));
    json_out(['id' => (int)$row['id'], 'project' => $row['project'], 'names' => $names, 'prompt' => $row['prompt'], 'creator' => $row['creator'], 'fresh' => (int)$row['fresh'], 'images' => $images, 'chat' => (int)$row['chat'], 'perm' => $row['perm'] ?: 'acceptEdits']);   // şəkillər: cmdimg.php?f=<ad>; chat: söhbət (agent sessiyanı ona görə davam etdirir)
}

if ($act === 'finish') {
    $status = ($in['status'] ?? '') === 'done' ? 'done' : 'failed';
    $result = mb_substr((string)($in['result'] ?? ''), 0, 8000);
    $st = $pdo->prepare("UPDATE commands SET status = ?, result = ?, finished_at = ? WHERE id = ? AND user = ? AND status = 'running'");
    $st->execute([$status, $result, $now, (int)($in['id'] ?? 0), $user]);
    if ($st->rowCount() === 1) {
        advance_task($pdo, (int)($in['id'] ?? 0), $user, $status, $result, $now);   // lövhə: iş → yoxlama → bitdi / düzəliş (db.php)
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
    json_out(['ok' => true, 'user' => $user]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
