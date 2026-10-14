<?php
// Kainatın fərdi hissələri (giriş etmiş istifadəçi): öz planetləri, öz gəmisi və deadline-ı, MCP peykləri,
// prompt kitabxanası, görüşlər. Hər kəs əlavə edə bilər; dəyişmək/silmək yalnız sahibinə və adminə.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 40000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
session_write_close();
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();
$isAdmin = $me['role'] === 'admin';
// Student yeni planet yaratmır: yalnız öz planetini fərdiləşdirir və öz gəmisini dəyişir
if (is_student($me)) {
    if ($act === 'planet_save') {
        $in['id'] = ensure_student_planet($pdo, $me['name']);
    } elseif ($act !== 'ship_save') {
        json_out(['error' => 'Bu bölmə studentlər üçün bağlıdır'], 403);
    }
}

// Xüsusi planetlərin adları: istifadəçi planeti bu adlarla yarana bilməz
const SPECIAL_PLANETS = ['Prompt Generator', 'Designer', 'Meeting Planet', 'Test Planet'];
const SHIP_MODELS = ['shuttle', 'saucer', 'fighter'];

function clean(string $s): string
{
    return trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s));
}
function str_in(array $in, string $k, int $max, bool $required, string $label): string
{
    $v = clean((string)($in[$k] ?? ''));
    if ($required && $v === '') {
        json_out(['error' => "$label boş ola bilməz"], 400);
    }
    if (mb_strlen($v) > $max) {
        json_out(['error' => "$label çox uzundur ($max simvola qədər)"], 400);
    }
    return $v;
}
function color_in(array $in, string $k, string $def): string
{
    $v = (string)($in[$k] ?? $def);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $def;
}
function row(PDO $pdo, string $table, $id): array
{
    $st = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $st->execute([(int)$id]);
    $r = $st->fetch();
    if (!$r) {
        json_out(['error' => 'tapılmadı'], 404);
    }
    return $r;
}
function must_own(array $r, string $col): void
{
    global $me, $isAdmin;
    if (!$isAdmin && $r[$col] !== $me['name']) {
        json_out(['error' => 'icazə yoxdur'], 403);
    }
}
function rate_limit(PDO $pdo, string $table, string $col, int $max): void
{
    global $me, $now;
    $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ? AND created_at > ?");
    $st->execute([$me['name'], $now - 60]);
    if ((int)$st->fetchColumn() >= $max) {
        json_out(['error' => 'Çox tez əlavə edirsən, bir az gözlə'], 429);
    }
}

/* ---------- Planetlər ---------- */
if ($act === 'planet_save') {
    $id = (int)($in['id'] ?? 0);
    $name = str_in($in, 'name', 60, true, 'Ad');
    $descr = str_in($in, 'descr', 1000, false, 'Təsvir');
    $surface = max(0, min(5, (int)($in['surface'] ?? 0)));
    $hue = max(0, min(359, (int)($in['hue'] ?? 200)));
    $ring = !empty($in['ring']) ? 1 : 0;
    $size = max(1, min(3, (int)($in['size'] ?? 2)));
    foreach (SPECIAL_PLANETS as $sp) {
        if (mb_strtolower($sp) === mb_strtolower($name)) {
            json_out(['error' => 'Bu ad xüsusi planetə aiddir'], 400);
        }
    }
    $dup = $pdo->prepare('SELECT id FROM worlds WHERE LOWER(name) = LOWER(?) AND id != ?');
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) {
        json_out(['error' => 'Bu adda planet artıq var'], 409);
    }
    if ($id) {
        $w = row($pdo, 'worlds', $id);
        must_own($w, 'owner');
        $pdo->prepare('UPDATE worlds SET name = ?, surface = ?, hue = ?, ring = ?, size = ?, descr = ?, updated_at = ? WHERE id = ?')
            ->execute([$name, $surface, $hue, $ring, $size, $descr, $now, $id]);
        if ($w['name'] !== $name) {             // planetin tapşırıq və planları yeni ada keçir
            $pdo->prepare('UPDATE tasks SET project = ? WHERE project = ?')->execute([$name, $w['name']]);
            $pdo->prepare('UPDATE plans SET project = ? WHERE project = ?')->execute([$name, $w['name']]);
            $pdo->prepare('UPDATE mcp_sats SET project = ? WHERE project = ?')->execute([$name, $w['name']]);
        }
        json_out(['ok' => true, 'id' => $id]);
    }
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM worlds WHERE owner = ?');
    $cnt->execute([$me['name']]);
    if (!$isAdmin && (int)$cnt->fetchColumn() >= 6) {
        json_out(['error' => 'Ən çox 6 öz planetin ola bilər'], 400);
    }
    rate_limit($pdo, 'worlds', 'owner', 10);
    $pdo->prepare('INSERT INTO worlds (name, owner, surface, hue, ring, size, descr, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $me['name'], $surface, $hue, $ring, $size, $descr, $now, $now]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'planet_delete') {
    $w = row($pdo, 'worlds', $in['id'] ?? 0);
    must_own($w, 'owner');
    $pdo->prepare('DELETE FROM worlds WHERE id = ?')->execute([$w['id']]);
    json_out(['ok' => true]);
}

/* ---------- Gəmi ---------- */
function my_ship(PDO $pdo, string $user): array
{
    $st = $pdo->prepare('SELECT * FROM ships WHERE user = ?');
    $st->execute([$user]);
    return $st->fetch() ?: ['user' => $user, 'name' => $user . ' gəmisi', 'model' => 'shuttle', 'color' => '#cfd6e6', 'accent' => '#f59e0b', 'deadline' => 0, 'mission' => '', 'new' => true];
}
function save_ship(PDO $pdo, array $s): void
{
    global $now;
    if (!empty($s['new'])) {
        $pdo->prepare('INSERT INTO ships (user, name, model, color, accent, deadline, mission, updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$s['user'], $s['name'], $s['model'], $s['color'], $s['accent'], (int)$s['deadline'], $s['mission'], $now]);
    } else {
        $pdo->prepare('UPDATE ships SET name = ?, model = ?, color = ?, accent = ?, deadline = ?, mission = ?, updated_at = ? WHERE user = ?')
            ->execute([$s['name'], $s['model'], $s['color'], $s['accent'], (int)$s['deadline'], $s['mission'], $now, $s['user']]);
    }
}
if ($act === 'ship_save') {
    $s = my_ship($pdo, $me['name']);
    $s['name'] = str_in($in, 'name', 60, true, 'Gəminin adı');
    $model = (string)($in['model'] ?? 'shuttle');
    $s['model'] = in_array($model, SHIP_MODELS, true) ? $model : 'shuttle';
    $s['color'] = color_in($in, 'color', $s['color']);
    $s['accent'] = color_in($in, 'accent', $s['accent']);
    if (array_key_exists('deadline', $in)) {
        $dl = (int)$in['deadline'];
        if ($dl !== 0 && ($dl < $now - 86400 * 365 || $dl > $now + 86400 * 365 * 3)) {
            json_out(['error' => 'Deadline tarixi düzgün deyil'], 400);
        }
        $s['deadline'] = $dl;
        $s['mission'] = $dl ? str_in($in, 'mission', 200, false, 'Missiya') : '';
    }
    save_ship($pdo, $s);
    json_out(['ok' => true]);
}

/* ---------- MCP peykləri ---------- */
if ($act === 'mcp_save') {
    $id = (int)($in['id'] ?? 0);
    $name = str_in($in, 'name', 64, true, 'Ad');
    if (!preg_match('/^[\p{L}\p{N} ._-]+$/u', $name)) {
        json_out(['error' => 'Adda yalnız hərf, rəqəm, boşluq, nöqtə, - və _ ola bilər'], 400);
    }
    $project = str_in($in, 'project', 128, false, 'Proyekt');
    $descr = str_in($in, 'descr', 500, false, 'Təsvir');
    if ($id) {
        $m = row($pdo, 'mcp_sats', $id);
        must_own($m, 'owner');
        $pdo->prepare('UPDATE mcp_sats SET name = ?, project = ?, descr = ? WHERE id = ?')->execute([$name, $project, $descr, $id]);
        json_out(['ok' => true, 'id' => $id]);
    }
    rate_limit($pdo, 'mcp_sats', 'owner', 15);
    $pdo->prepare('INSERT INTO mcp_sats (name, project, descr, owner, created_at) VALUES (?,?,?,?,?)')
        ->execute([$name, $project, $descr, $me['name'], $now]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'mcp_delete') {
    $m = row($pdo, 'mcp_sats', $in['id'] ?? 0);
    must_own($m, 'owner');
    $pdo->prepare('DELETE FROM mcp_sats WHERE id = ?')->execute([$m['id']]);
    json_out(['ok' => true]);
}

/* ---------- Prompt kitabxanası ---------- */
if ($act === 'prompt_save') {
    $id = (int)($in['id'] ?? 0);
    $title = str_in($in, 'title', 200, true, 'Başlıq');
    $body = str_in($in, 'body', 8000, true, 'Prompt');
    $tags = str_in($in, 'tags', 200, false, 'Teqlər');
    if ($id) {
        $p = row($pdo, 'prompts', $id);
        must_own($p, 'owner');
        $pdo->prepare('UPDATE prompts SET title = ?, body = ?, tags = ?, updated_at = ? WHERE id = ?')->execute([$title, $body, $tags, $now, $id]);
        json_out(['ok' => true, 'id' => $id]);
    }
    rate_limit($pdo, 'prompts', 'owner', 20);
    $pdo->prepare('INSERT INTO prompts (owner, title, body, tags, created_at, updated_at) VALUES (?,?,?,?,?,?)')
        ->execute([$me['name'], $title, $body, $tags, $now, $now]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'prompt_delete') {
    $p = row($pdo, 'prompts', $in['id'] ?? 0);
    must_own($p, 'owner');
    $pdo->prepare('DELETE FROM prompts WHERE id = ?')->execute([$p['id']]);
    json_out(['ok' => true]);
}

/* ---------- Görüşlər (Meeting Planet) ---------- */
if ($act === 'meeting_save') {
    $id = (int)($in['id'] ?? 0);
    $title = str_in($in, 'title', 200, true, 'Başlıq');
    $agenda = str_in($in, 'agenda', 3000, false, 'Gündəm');
    $starts = (int)($in['starts_at'] ?? 0);
    if ($starts < $now - 86400 * 30 || $starts > $now + 86400 * 365) {
        json_out(['error' => 'Başlama vaxtı düzgün deyil'], 400);
    }
    $minutes = max(5, min(480, (int)($in['minutes'] ?? 30)));
    $users = array_column($pdo->query('SELECT name FROM users')->fetchAll(), 'name');
    $att = array_values(array_intersect($users, array_map('strval', (array)($in['attendees'] ?? []))));
    if (!in_array($me['name'], $att, true) && !$id) {
        $att[] = $me['name'];
    }
    if ($id) {
        $m = row($pdo, 'meetings', $id);
        must_own($m, 'creator');
        $pdo->prepare('UPDATE meetings SET title = ?, agenda = ?, starts_at = ?, minutes = ?, attendees = ? WHERE id = ?')
            ->execute([$title, $agenda, $starts, $minutes, implode(',', $att), $id]);
        json_out(['ok' => true, 'id' => $id]);
    }
    rate_limit($pdo, 'meetings', 'creator', 10);
    $pdo->prepare('INSERT INTO meetings (title, agenda, starts_at, minutes, attendees, creator, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$title, $agenda, $starts, $minutes, implode(',', $att), $me['name'], $now]);
    notify_later('🪐 Yeni görüş: ' . $title . "\n" . date('d.m H:i', $starts) . " · $minutes dəq · " . implode(', ', $att) . ' · təşkil edən ' . $me['name']);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'meeting_delete') {
    $m = row($pdo, 'meetings', $in['id'] ?? 0);
    must_own($m, 'creator');
    $pdo->prepare('DELETE FROM meeting_posts WHERE meeting_id = ?')->execute([$m['id']]);
    $pdo->prepare('DELETE FROM meetings WHERE id = ?')->execute([$m['id']]);
    json_out(['ok' => true]);
}
if ($act === 'meeting_post') {
    $m = row($pdo, 'meetings', $in['meeting_id'] ?? 0);
    $body = str_in($in, 'body', 2000, true, 'Mesaj');
    $rl = $pdo->prepare('SELECT COUNT(*) FROM meeting_posts WHERE user = ? AND created_at > ?');
    $rl->execute([$me['name'], $now - 60]);
    if ((int)$rl->fetchColumn() >= 30) {
        json_out(['error' => 'Çox tez yazırsan, bir az gözlə'], 429);
    }
    $pdo->prepare('INSERT INTO meeting_posts (meeting_id, user, body, created_at) VALUES (?,?,?,?)')->execute([$m['id'], $me['name'], $body, $now]);
    json_out(['ok' => true]);
}

/* ---------- Test Planet ---------- */
if ($act === 'test_save') {
    $id = (int)($in['id'] ?? 0);
    $title = str_in($in, 'title', 200, true, 'Başlıq');
    $steps = str_in($in, 'steps', 3000, false, 'Addımlar');
    if ($id) {
        $t = row($pdo, 'tests', $id);
        must_own($t, 'creator');
        $pdo->prepare('UPDATE tests SET title = ?, steps = ?, updated_at = ? WHERE id = ?')->execute([$title, $steps, $now, $id]);
        json_out(['ok' => true, 'id' => $id]);
    }
    $project = str_in($in, 'project', 128, true, 'Proyekt');
    rate_limit($pdo, 'tests', 'creator', 30);
    $pdo->prepare('INSERT INTO tests (project, title, steps, status, note, creator, tested_by, tested_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$project, $title, $steps, 'todo', '', $me['name'], '', 0, $now, $now]);
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}
if ($act === 'test_status') {                       // hər kəs test edə bilər
    $t = row($pdo, 'tests', $in['id'] ?? 0);
    $st = (string)($in['status'] ?? '');
    if (!in_array($st, ['todo', 'pass', 'fail'], true)) {
        json_out(['error' => 'yanlış status'], 400);
    }
    $note = $st === 'fail' ? str_in($in, 'note', 2000, false, 'Qeyd') : '';
    $pdo->prepare('UPDATE tests SET status = ?, note = ?, tested_by = ?, tested_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$st, $note, $st === 'todo' ? '' : $me['name'], $st === 'todo' ? 0 : $now, $now, $t['id']]);
    if ($st === 'fail' && $t['status'] !== 'fail') {
        notify_later("🧪❌ Test xətası: {$t['title']}\nProyekt: {$t['project']} · " . $me['name'] . ($note !== '' ? "\n$note" : ''));
    }
    json_out(['ok' => true]);
}
if ($act === 'test_reset') {                        // yeni test raundu: proyektin bütün testləri "yoxlanmayıb"
    $project = str_in($in, 'project', 128, true, 'Proyekt');
    $pdo->prepare("UPDATE tests SET status = 'todo', note = '', tested_by = '', tested_at = 0, updated_at = ? WHERE project = ?")->execute([$now, $project]);
    json_out(['ok' => true]);
}
if ($act === 'test_delete') {
    $t = row($pdo, 'tests', $in['id'] ?? 0);
    must_own($t, 'creator');
    $pdo->prepare('DELETE FROM tests WHERE id = ?')->execute([$t['id']]);
    json_out(['ok' => true]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
