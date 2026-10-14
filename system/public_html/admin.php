<?php
// İstifadəçi idarəsi (yalnız daxil olmuş istifadəçi). passwd hamıya açıqdır, qalanı yalnız adminə.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
// CSRF: kənar sayt bu başlığı göndərə bilməz
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 20000) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();

function gen_token(): string
{
    return bin2hex(random_bytes(16));
}
function gen_pass(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 12; $i++) {
        $s .= $a[random_int(0, strlen($a) - 1)];
    }
    return $s;
}

if ($act === 'passwd') {
    $cur = (string)($in['current'] ?? '');
    $new = (string)($in['new'] ?? '');
    if (mb_strlen($new) < 8 || mb_strlen($new) > 100) {
        json_out(['error' => 'Yeni parol ən azı 8 simvol olmalıdır'], 400);
    }
    $st = $pdo->prepare('SELECT pass_hash FROM users WHERE name = ?');
    $st->execute([$me['name']]);
    $h = $st->fetchColumn();
    if (!$h || !password_verify($cur, $h)) {
        usleep(500000);
        json_out(['error' => 'Cari parol yanlışdır'], 403);
    }
    $pdo->prepare('UPDATE users SET pass_hash = ? WHERE name = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['name']]);
    json_out(['ok' => true]);
}

if ($me['role'] !== 'admin') {
    json_out(['error' => 'yalnız admin'], 403);
}

if ($act === 'settings_get') {
    $tok = setting_get('tg_token');
    json_out(['configured' => $tok !== '' && setting_get('tg_chat') !== '', 'token_hint' => $tok !== '' ? substr($tok, 0, 6) . '…' : '', 'chat' => setting_get('tg_chat')]);
}

if ($act === 'settings_set') {
    $tok = trim((string)($in['tg_token'] ?? ''));
    $chat = trim((string)($in['tg_chat'] ?? ''));
    if ($tok !== '' && !preg_match('/^\d{5,}:[A-Za-z0-9_-]{20,}$/', $tok)) {
        json_out(['error' => 'Bot tokeni formatı yanlışdır (məsələn 123456789:AAH...)'], 400);
    }
    if ($chat !== '' && !preg_match('/^-?\d{4,20}$/', $chat)) {
        json_out(['error' => 'Chat ID rəqəm olmalıdır (qrup üçün - ilə başlayır)'], 400);
    }
    if ($tok !== '') {
        setting_set('tg_token', $tok);       // boş buraxsan köhnəsi qalır
    }
    setting_set('tg_chat', $chat);
    json_out(['ok' => true]);
}

if ($act === 'settings_clear') {
    setting_set('tg_token', '');
    setting_set('tg_chat', '');
    json_out(['ok' => true]);
}

if ($act === 'settings_test') {
    if (setting_get('tg_token') === '' || setting_get('tg_chat') === '') {
        json_out(['error' => 'Əvvəlcə token və chat ID saxla'], 400);
    }
    $ok = notify("✅ Komanda Claude: Telegram bildirişləri işləyir. (" . $me['name'] . ")");
    json_out($ok ? ['ok' => true] : ['error' => 'Göndərilmədi. Token və chat ID düzgündürmü? Botu qrupa əlavə etmisən?'], $ok ? 200 : 502);
}

if ($act === 'list') {
    $users = $pdo->query('SELECT name, role, created_at FROM users ORDER BY created_at, name')->fetchAll();
    $ag = [];
    foreach ($pdo->query('SELECT user, last_seen, claude_ok FROM agents')->fetchAll() as $r) {
        $ag[$r['user']] = $r;
    }
    foreach ($users as &$u) {
        $u['agent_seen'] = isset($ag[$u['name']]) ? (int)$ag[$u['name']]['last_seen'] : 0;
    }
    unset($u);
    json_out(['users' => $users, 'now' => $now]);
}

if ($act === 'create') {
    $name = trim((string)($in['name'] ?? ''));
    if (!preg_match('/^[\p{L}\p{N}_-]{2,24}$/u', $name)) {
        json_out(['error' => 'Ad 2-24 simvol olmalıdır (hərf, rəqəm, _ və -)'], 400);
    }
    $ex = $pdo->prepare('SELECT 1 FROM users WHERE LOWER(name) = LOWER(?)');
    $ex->execute([$name]);
    if ($ex->fetchColumn()) {
        json_out(['error' => 'Bu ad artıq var'], 409);
    }
    $role = (string)($in['role'] ?? 'member');
    if (!in_array($role, ['member', 'student'], true)) {
        json_out(['error' => 'Yanlış istifadəçi tipi'], 400);
    }
    $tok = gen_token();
    $pw = gen_pass();
    $pdo->prepare('INSERT INTO users (name, token_hash, pass_hash, role, created_at) VALUES (?,?,?,?,?)')
        ->execute([$name, hash('sha256', $tok), password_hash($pw, PASSWORD_DEFAULT), $role, $now]);
    if ($role === 'student') {
        ensure_student_planet($pdo, $name);     // hər studentin öz planeti Student universində
    }
    json_out(['ok' => true, 'name' => $name, 'role' => $role, 'token' => $tok, 'password' => $pw]);
}

if ($act === 'reset') {
    $name = (string)($in['name'] ?? '');
    $ex = $pdo->prepare('SELECT role FROM users WHERE name = ?');
    $ex->execute([$name]);
    $role = $ex->fetchColumn();
    if ($role === false) {
        json_out(['error' => 'istifadəçi tapılmadı'], 404);
    }
    $tok = gen_token();
    $pw = gen_pass();
    $pdo->prepare('UPDATE users SET token_hash = ?, pass_hash = ? WHERE name = ?')
        ->execute([hash('sha256', $tok), password_hash($pw, PASSWORD_DEFAULT), $name]);
    json_out(['ok' => true, 'name' => $name, 'role' => $role, 'token' => $tok, 'password' => $pw]);
}

if ($act === 'delete') {
    $name = (string)($in['name'] ?? '');
    $st = $pdo->prepare('SELECT role FROM users WHERE name = ?');
    $st->execute([$name]);
    $role = $st->fetchColumn();
    if ($role === false) {
        json_out(['error' => 'istifadəçi tapılmadı'], 404);
    }
    if ($name === $me['name'] || $role === 'admin') {
        json_out(['error' => 'Admini silmək olmaz'], 400);
    }
    foreach (['events', 'sessions', 'commands', 'agents', 'ships'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE user = ?")->execute([$name]);
    }
    $pdo->prepare('DELETE FROM messages WHERE from_user = ? OR to_user = ?')->execute([$name, $name]);
    $pdo->prepare('DELETE FROM remote_grants WHERE owner = ? OR grantee = ?')->execute([$name, $name]);
    if ($role === 'student') {
        $pdo->prepare('DELETE FROM worlds WHERE owner = ?')->execute([$name]);
    }
    $pdo->prepare('DELETE FROM users WHERE name = ?')->execute([$name]);
    json_out(['ok' => true]);
}

if ($act === 'projects') {
    $rows = $pdo->query('SELECT project, COUNT(*) AS sessions, MAX(updated_at) AS last_at FROM sessions GROUP BY project ORDER BY MAX(updated_at) DESC')->fetchAll();
    $us = [];
    foreach ($pdo->query('SELECT DISTINCT project, user FROM sessions')->fetchAll() as $r) {
        $us[$r['project']][] = $r['user'];
    }
    $al = [];
    foreach ($pdo->query('SELECT src, dst FROM aliases')->fetchAll() as $r) {
        $al[$r['dst']][] = $r['src'];
    }
    foreach ($rows as &$r) {
        $r['users'] = $us[$r['project']] ?? [];
        $r['folders'] = $al[$r['project']] ?? [];
    }
    unset($r);
    json_out(['projects' => $rows, 'now' => $now]);
}

if ($act === 'rename_project') {
    $from = trim((string)($in['from'] ?? ''));
    $to = trim((string)($in['to'] ?? ''));
    if (!preg_match('/^[^\x00-\x1f\x7f]{1,60}$/u', $to)) {
        json_out(['error' => 'Ad 1-60 simvol olmalıdır'], 400);
    }
    $ex = $pdo->prepare('SELECT 1 FROM sessions WHERE project = ?');
    $ex->execute([$from]);
    if (!$ex->fetchColumn()) {
        json_out(['error' => 'proyekt tapılmadı'], 404);
    }
    if ($from === $to) {
        json_out(['ok' => true]);
    }
    $ex->execute([$to]);
    if ($ex->fetchColumn()) {
        json_out(['error' => 'Bu adda proyekt artıq var'], 409);
    }
    $pdo->beginTransaction();
    foreach (['events', 'sessions', 'commands', 'tasks', 'plans', 'mcp_sats', 'tests'] as $t) {
        $pdo->prepare("UPDATE $t SET project = ? WHERE project = ?")->execute([$to, $from]);
    }
    $up = $pdo->prepare('UPDATE aliases SET dst = ? WHERE dst = ?');
    $up->execute([$to, $from]);
    if ($up->rowCount() === 0) {      // ilk dəyişmə: qovluq adı → yeni ad
        $pdo->prepare('INSERT INTO aliases (src, dst) VALUES (?, ?)')->execute([$from, $to]);
    }
    $pdo->exec('DELETE FROM aliases WHERE src = dst');
    $pdo->commit();
    json_out(['ok' => true]);
}

if ($act === 'delete_project') {
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        json_out(['error' => 'ad boşdur'], 400);
    }
    foreach (['events', 'sessions', 'commands'] as $t) {
        $pdo->prepare("DELETE FROM $t WHERE project = ?")->execute([$name]);
    }
    $pdo->prepare('DELETE FROM aliases WHERE src = ? OR dst = ?')->execute([$name, $name]);
    json_out(['ok' => true]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
