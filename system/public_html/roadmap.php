<?php
// Qalan işlər: bütün səhifələrdə görünən yapışqan siyahı. Hər üzv əlavə edə, dəyişə və bitmiş kimi işarələyə bilər; silmək — admin və ya yaradan.
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
$in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$isAdmin = $me['role'] === 'admin';

function rm_item(PDO $pdo, $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM roadmap WHERE id = ?');
    $st->execute([(int)$id]);
    return $st->fetch() ?: null;
}
/** "Askar, Emil" → "Askar,Emil" (yalnız mövcud istifadəçilər, ən çox 6) */
function rm_owners(PDO $pdo, $v): string
{
    $users = array_column($pdo->query('SELECT name FROM users')->fetchAll(), 'name');
    $out = [];
    foreach (is_array($v) ? $v : explode(',', (string)$v) as $o) {
        $o = trim((string)$o);
        foreach ($users as $u) {
            if (strcasecmp($u, $o) === 0 && !in_array($u, $out, true)) {
                $out[] = $u;
            }
        }
    }
    return implode(',', array_slice($out, 0, 6));
}
$list = fn() => $pdo->query('SELECT id, parent_id, title, owners, done, sort, done_by, done_at FROM roadmap ORDER BY parent_id ASC, sort ASC, id ASC LIMIT 300')->fetchAll();

if ($act === 'list') {
    json_out(['items' => $list()]);
}

if ($act === 'save') {                   // yeni və ya dəyişiklik: title, owners, parent_id
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 300) {
        json_out(['error' => 'ad 1-300 simvol olmalıdır'], 400);
    }
    $owners = rm_owners($pdo, $in['owners'] ?? '');
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        if (!rm_item($pdo, $id)) {
            json_out(['error' => 'tapılmadı'], 404);
        }
        $pdo->prepare('UPDATE roadmap SET title = ?, owners = ?, updated_at = ? WHERE id = ?')->execute([$title, $owners, $now, $id]);
    } else {
        $parent = (int)($in['parent_id'] ?? 0);
        if ($parent) {
            $p = rm_item($pdo, $parent);
            if (!$p || (int)$p['parent_id'] !== 0) {
                json_out(['error' => 'alt bənd yalnız əsas işə əlavə olunur'], 400);
            }
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM roadmap')->fetchColumn() >= 300) {
            json_out(['error' => 'siyahı doludur (300)'], 429);
        }
        $st = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM roadmap WHERE parent_id = ?');
        $st->execute([$parent]);
        $pdo->prepare('INSERT INTO roadmap (parent_id, title, owners, sort, creator, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$parent, $title, $owners, (int)$st->fetchColumn(), $me['name'], $now, $now]);
        $id = (int)$pdo->lastInsertId();
    }
    json_out(['ok' => true, 'id' => $id, 'items' => $list()]);
}

if ($act === 'toggle') {                 // bitdi / geri qaytar
    $it = rm_item($pdo, $in['id'] ?? 0);
    if (!$it) {
        json_out(['error' => 'tapılmadı'], 404);
    }
    $done = !empty($in['done']) ? 1 : 0;
    $pdo->prepare('UPDATE roadmap SET done = ?, done_by = ?, done_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$done, $done ? $me['name'] : '', $done ? $now : 0, $now, (int)$it['id']]);
    json_out(['ok' => true, 'items' => $list()]);
}

if ($act === 'move') {                   // sıranı dəyiş: dir = -1 (yuxarı) / 1 (aşağı), eyni səviyyədə
    $it = rm_item($pdo, $in['id'] ?? 0);
    if (!$it) {
        json_out(['error' => 'tapılmadı'], 404);
    }
    $st = $pdo->prepare('SELECT id FROM roadmap WHERE parent_id = ? ORDER BY sort ASC, id ASC');
    $st->execute([(int)$it['parent_id']]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    $i = array_search((int)$it['id'], $ids, true);
    $j = $i + ((int)($in['dir'] ?? 0) < 0 ? -1 : 1);
    if ($i !== false && $j >= 0 && $j < count($ids)) {
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $up = $pdo->prepare('UPDATE roadmap SET sort = ? WHERE id = ?');
        foreach ($ids as $k => $x) {
            $up->execute([$k + 1, $x]);
        }
    }
    json_out(['ok' => true, 'items' => $list()]);
}

if ($act === 'delete') {                 // admin və ya yaradan; alt bəndlər də silinir
    $it = rm_item($pdo, $in['id'] ?? 0);
    if (!$it) {
        json_out(['error' => 'tapılmadı'], 404);
    }
    if (!$isAdmin && $it['creator'] !== $me['name']) {
        json_out(['error' => 'yalnız admin və ya əlavə edən silə bilər'], 403);
    }
    $pdo->prepare('DELETE FROM roadmap WHERE id = ? OR parent_id = ?')->execute([(int)$it['id'], (int)$it['id']]);
    json_out(['ok' => true, 'items' => $list()]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
