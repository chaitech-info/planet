<?php
// İcazə relesi: Claude Code PermissionRequest hook-u buraya sorğu yazır və qərarı gözləyir (long-poll).
// Panel/Jarvis isə qərarı verir. Hook: request, wait, cancel (X-Team-Token). Brauzer: list, decide (giriş + X-Requested-With: fetch).
// Statuslar: pending → allow | deny | timeout (60 san sonra wait/list özü timeout edir).
// approvals.input JSON: {in: tool_input, cwd, sum: bir sətirlik xülasə}.
require __DIR__ . '/db.php';

const APPROVAL_TTL = 60;          // bu qədər saniyədən sonra pending → timeout (hook onsuz da ~50 san gözləyir)
const APPROVAL_MAX_PENDING = 20;  // bir istifadəçi üçün eyni anda ən çox pending
const APPROVAL_HOLD = 25;         // wait sorğusunu server ən çox bu qədər saxlayır

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST lazımdır'], 405);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 65536) ?: '', true);
if (!is_array($in)) {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$act = (string)($in['action'] ?? '');
$pdo = db();
$now = time();

function ap_clip(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

/** İnsan oxuya bilən bir sətir: "Bash: npm test", "Edit: src/app.ts", "mcp: github/create_issue" */
function approval_summary(string $tool, array $in, string $cwd): string
{
    $path = $in['file_path'] ?? $in['notebook_path'] ?? $in['path'] ?? null;
    if (is_string($path) && $path !== '') {
        $root = rtrim($cwd, '/') . '/';
        if ($cwd !== '' && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }
        return "$tool: " . ap_clip($path, 140);
    }
    if ($tool === 'Bash' && isset($in['command'])) {
        return 'Bash: ' . ap_clip((string)$in['command'], 160);
    }
    if (preg_match('/^mcp__(.+?)__(.+)$/', $tool, $m)) {
        return "mcp: {$m[1]}/{$m[2]}";
    }
    foreach (['url', 'query', 'pattern', 'description', 'prompt', 'skill'] as $k) {
        if (isset($in[$k]) && is_string($in[$k]) && $in[$k] !== '') {
            return "$tool: " . ap_clip($in[$k], 140);
        }
    }
    return $tool;
}

/** Vaxtı keçmiş pending sorğuları timeout et və hadisəsini yay */
function approvals_expire(PDO $pdo, int $now): void
{
    $st = $pdo->prepare("SELECT id, user, project FROM approvals WHERE status = 'pending' AND created_at < ?");
    $st->execute([$now - APPROVAL_TTL]);
    foreach ($st->fetchAll() as $r) {
        $u = $pdo->prepare("UPDATE approvals SET status = 'timeout', decided_at = ? WHERE id = ? AND status = 'pending'");
        $u->execute([$now, $r['id']]);
        if ($u->rowCount()) {
            emit_event($pdo, 'approval.decided', $r['user'], $r['project'], (int)$r['id'], ['id' => (int)$r['id'], 'status' => 'timeout']);
        }
    }
}

function approval_row(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM approvals WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** Brauzer/Jarvis üçün yığcam görünüş */
function approval_view(array $r, int $now): array
{
    $j = json_decode((string)$r['input'], true) ?: [];
    $inp = json_encode($j['in'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return ['id' => (int)$r['id'], 'user' => $r['user'], 'session_id' => $r['session_id'], 'project' => $r['project'], 'tool' => $r['tool'],
        'summary' => (string)($j['sum'] ?? $r['tool']), 'input' => mb_substr((string)$inp, 0, 1500), 'status' => $r['status'],
        'age' => max(0, $now - (int)$r['created_at']), 'expires_in' => max(0, (int)$r['created_at'] + APPROVAL_TTL - $now),
        'decided_by' => $r['decided_by'], 'reason' => $r['reason']];
}

/* ---------- Hook tərəfi (token) ---------- */
if (in_array($act, ['request', 'wait', 'cancel'], true)) {
    $user = token_user();
    if ($user === null) {
        json_out(['error' => 'token yanlışdır'], 401);
    }

    if ($act === 'request') {
        // Panelə baxan yoxdursa (sahibi və ya admin son 60 san-də saytda deyil) gözlətmə: hook dərhal adi dialoqa keçsin
        $pr = $pdo->prepare("SELECT COUNT(*) FROM presence p WHERE p.last_seen > ? AND (p.user = ? OR p.user IN (SELECT name FROM users WHERE role = 'admin'))");
        $pr->execute([$now - 60, $user]);
        if (!(int)$pr->fetchColumn() && empty($in['force'])) {
            json_out(['ok' => true, 'skip' => 'panelə baxan yoxdur']);
        }
        approvals_expire($pdo, $now);
        $c = $pdo->prepare("SELECT COUNT(*) FROM approvals WHERE user = ? AND status = 'pending'");
        $c->execute([$user]);
        if ((int)$c->fetchColumn() >= APPROVAL_MAX_PENDING) {
            json_out(['error' => 'çox gözləyən sorğu var'], 429);
        }
        $tool = ap_clip((string)($in['tool'] ?? ''), 128);
        if ($tool === '') {
            json_out(['error' => 'tool lazımdır'], 400);
        }
        $cwd = mb_substr((string)($in['cwd'] ?? ''), 0, 500);
        $project = ap_clip((string)($in['project'] ?? '') ?: (basename($cwd) ?: 'naməlum'), 128);
        $al = $pdo->prepare('SELECT dst FROM aliases WHERE src = ?');   // ingest.php ilə eyni proyekt adı
        $al->execute([$project]);
        if (($alias = $al->fetchColumn()) !== false) {
            $project = (string)$alias;
        }
        $ti = is_array($in['input'] ?? null) ? $in['input'] : [];
        $sum = approval_summary($tool, $ti, $cwd);
        $blob = json_encode(['in' => $ti, 'cwd' => $cwd, 'sum' => $sum], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        if (strlen($blob) > 8192) {
            $blob = json_encode(['in' => ['_truncated' => mb_strcut(json_encode($ti, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '', 0, 6000)], 'cwd' => $cwd, 'sum' => $sum], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        }
        $sid = ap_clip((string)($in['session_id'] ?? ''), 128);
        $pdo->prepare("INSERT INTO approvals (user, session_id, project, tool, input, status, decided_by, reason, created_at, decided_at) VALUES (?,?,?,?,?,'pending','','',?,0)")
            ->execute([$user, $sid, $project, $tool, $blob, $now]);
        $id = (int)$pdo->lastInsertId();
        emit_event($pdo, 'approval.new', $user, $project, $id, ['id' => $id, 'project' => $project, 'tool' => $tool, 'summary' => $sum, 'user' => $user]);
        json_out(['ok' => true, 'id' => $id, 'ttl' => APPROVAL_TTL]);
    }

    $id = (int)($in['id'] ?? 0);
    $r = $id > 0 ? approval_row($pdo, $id) : null;
    if (!$r || $r['user'] !== $user) {
        json_out(['error' => 'tapılmadı'], 404);
    }

    if ($act === 'cancel') {          // hook gözləməkdən əl çəkdi (adi dialoq açılır)
        $u = $pdo->prepare("UPDATE approvals SET status = 'timeout', decided_at = ? WHERE id = ? AND status = 'pending'");
        $u->execute([$now, $id]);
        if ($u->rowCount()) {
            emit_event($pdo, 'approval.decided', $user, $r['project'], $id, ['id' => $id, 'status' => 'timeout']);
        }
        json_out(['ok' => true]);
    }

    // wait: qərar gələnə qədər saxla (≤ 25 san), sonra cari statusu qaytar
    $hold = max(0, min(APPROVAL_HOLD, (int)($in['wait'] ?? APPROVAL_HOLD)));
    set_time_limit($hold + 15);
    $end = microtime(true) + $hold;
    $q = $pdo->prepare('SELECT status, reason FROM approvals WHERE id = ?');
    while (true) {
        $q->execute([$id]);
        $s = $q->fetch() ?: ['status' => 'timeout', 'reason' => ''];
        $q->closeCursor();                                   // gözləyərkən SQLite oxuma kilidini saxlama (decide/emit bloklanmasın)
        if ($s['status'] === 'pending' && time() - (int)$r['created_at'] >= APPROVAL_TTL) {
            approvals_expire($pdo, time());
            continue;
        }
        if ($s['status'] !== 'pending' || microtime(true) >= $end || connection_aborted()) {
            break;
        }
        usleep(400000);
    }
    json_out(['id' => $id, 'status' => $s['status'], 'reason' => (string)$s['reason']]);
}

/* ---------- Brauzer tərəfi (giriş) ---------- */
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
deny_student($me);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
session_write_close();
$admin = $me['role'] === 'admin';
approvals_expire($pdo, $now);

if ($act === 'list') {
    // pending: mənimkilər (admin: hamısı). recent=N: son N san-də qərar verilənlər də (kartları bağlamaq üçün)
    $recent = max(0, min(600, (int)($in['recent'] ?? 0)));
    $w = $admin ? '' : ' AND user = ?';
    $st = $pdo->prepare("SELECT * FROM approvals WHERE (status = 'pending' OR decided_at > ?)$w ORDER BY id DESC LIMIT 50");
    $st->execute($admin ? [$recent ? $now - $recent : PHP_INT_MAX] : [$recent ? $now - $recent : PHP_INT_MAX, $me['name']]);
    $rows = array_map(fn($r) => approval_view($r, $now), $st->fetchAll());
    json_out(['ok' => true, 'pending' => count(array_filter($rows, fn($r) => $r['status'] === 'pending')), 'items' => $rows, 'now' => $now]);
}

if ($act === 'decide') {
    $id = (int)($in['id'] ?? 0);
    $dec = (string)($in['decision'] ?? '');
    if (!in_array($dec, ['allow', 'deny'], true)) {
        json_out(['error' => 'decision allow və ya deny olmalıdır'], 400);
    }
    $r = $id > 0 ? approval_row($pdo, $id) : null;
    if (!$r) {
        json_out(['error' => 'sorğu tapılmadı'], 404);
    }
    if ($r['user'] !== $me['name'] && !$admin) {
        json_out(['error' => 'yalnız sahibi və ya admin qərar verə bilər'], 403);
    }
    if ($r['status'] !== 'pending') {
        json_out(['error' => 'artıq bağlanıb: ' . $r['status'], 'status' => $r['status']], 409);
    }
    $reason = ap_clip((string)($in['reason'] ?? ''), 300);
    $u = $pdo->prepare("UPDATE approvals SET status = ?, decided_by = ?, reason = ?, decided_at = ? WHERE id = ? AND status = 'pending'");
    $u->execute([$dec, $me['name'], $reason, $now, $id]);
    if (!$u->rowCount()) {
        json_out(['error' => 'artıq bağlanıb'], 409);
    }
    emit_event($pdo, 'approval.decided', $r['user'], $r['project'], $id, ['id' => $id, 'status' => $dec, 'by' => $me['name']]);
    json_out(['ok' => true, 'id' => $id, 'status' => $dec]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
