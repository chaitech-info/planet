<?php
// Claude Code hook-ları buraya POST edir. Başlıq: X-Team-Token
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST lazımdır'], 405);
}

$user = token_user();
if ($user === null) {
    json_out(['error' => 'token yanlışdır'], 401);
}

$raw = file_get_contents('php://input', false, null, 0, 262144);
$p = json_decode($raw ?: '', true);
if (!is_array($p) || empty($p['hook_event_name'])) {
    json_out(['error' => 'yanlış payload'], 400);
}

function clip(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s;
}

function describe_tool(string $name, array $in, string $cwd): string
{
    $path = $in['file_path'] ?? $in['notebook_path'] ?? null;
    if (is_string($path)) {
        if ($cwd !== '' && str_starts_with($path, rtrim($cwd, '/') . '/')) {
            $path = substr($path, strlen(rtrim($cwd, '/')) + 1);
        }
        return "$name · " . clip($path, 120);
    }
    if ($name === 'Bash' && isset($in['command'])) {
        return 'Bash · ' . clip((string)$in['command'], 140);
    }
    foreach (['pattern', 'query', 'url', 'description', 'prompt'] as $k) {
        if (isset($in[$k]) && is_string($in[$k])) {
            return "$name · " . clip($in[$k], 120);
        }
    }
    return $name;
}

$event = (string)$p['hook_event_name'];
$sid = clip((string)($p['session_id'] ?? 'unknown'), 64);
$cwd = (string)($p['cwd'] ?? '');
$project = clip(basename($cwd) ?: 'naməlum', 128);
$al = db()->prepare('SELECT dst FROM aliases WHERE src = ?');   // qovluq adı əvəzinə göstərilən ad
$al->execute([$project]);
if (($alias = $al->fetchColumn()) !== false) {
    $project = (string)$alias;
}
$tool = '';
$edit = false;
$status = 'working';
$setPrompt = null;

switch ($event) {
    case 'SessionStart':
        $status = 'idle';
        $summary = 'Sessiya başladı';
        break;
    case 'UserPromptSubmit':
        $setPrompt = clip((string)($p['prompt'] ?? ''), 400);
        $summary = 'Tapşırıq: ' . clip($setPrompt, 200);
        break;
    case 'PostToolUse':
        $tool = clip((string)($p['tool_name'] ?? ''), 64);
        $summary = describe_tool($tool, (array)($p['tool_input'] ?? []), $cwd);
        $edit = in_array($tool, ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'], true);
        break;
    case 'Notification':
        $status = 'waiting';
        $summary = clip((string)($p['message'] ?? 'Diqqətini gözləyir'), 200);
        break;
    case 'Stop':
        // agent tapşırığı bir cavabdan sonra bitir; adi sessiya isə növbəti promptu gözləyir
        $status = !empty($_SERVER['HTTP_X_TEAM_RUN']) ? 'ended' : 'idle';
        $last = isset($p['last_assistant_message']) ? clip((string)$p['last_assistant_message'], 300) : '';
        $summary = $last !== '' ? 'Bitdi: ' . $last : 'Cavab tamamlandı';
        break;
    case 'Interrupt':   // istifadəçi Esc ilə dayandırdı (Stop hook bu halda işləmir; yerli izləyici göndərir)
        $status = 'idle';
        $summary = 'Dayandırıldı (istifadəçi)';
        break;
    case 'SessionEnd':
        $status = 'ended';
        $summary = 'Sessiya bağlandı';
        break;
    default:
        json_out(['ok' => true, 'ignored' => $event]);
}

$pdo = db();
$now = time();

$pdo->prepare('INSERT INTO events (user, session_id, project, event, tool, summary, created_at) VALUES (?,?,?,?,?,?,?)')
    ->execute([$user, $sid, $project, $event, $tool, $summary, $now]);

$exists = $pdo->prepare('SELECT 1 FROM sessions WHERE user = ? AND session_id = ?');
$exists->execute([$user, $sid]);
if ($exists->fetchColumn()) {
    $pdo->prepare('UPDATE sessions SET project = ?, cwd = ?, status = ?, last_summary = ?,
            last_prompt = COALESCE(?, last_prompt), edits = edits + ?, tool_count = tool_count + ?, updated_at = ?
            WHERE user = ? AND session_id = ?')
        ->execute([$project, $cwd, $status, $summary, $setPrompt, $edit ? 1 : 0, $tool !== '' ? 1 : 0, $now, $user, $sid]);
} else {
    $pdo->prepare('INSERT INTO sessions (user, session_id, project, cwd, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$user, $sid, $project, $cwd, $status, $setPrompt ?? '', $summary, $edit ? 1 : 0, $tool !== '' ? 1 : 0, $now, $now]);
}

// Eyni faylı iki nəfər dəyişir? (30 dəqiqə pəncərəsi). Xəta olsa hadisənin qəbuluna mane olmasın.
try {
if ($event === 'PostToolUse' && in_array($tool, EDIT_TOOLS, true)) {
    $ti = (array)($p['tool_input'] ?? []);
    $path = (string)($ti['file_path'] ?? $ti['notebook_path'] ?? '');
    $root = rtrim($cwd, '/') . '/';
    if ($path !== '' && $cwd !== '' && str_starts_with($path, $root)) {
        $path = substr($path, strlen($root));
    }
    $path = clip($path, 300);
    if ($path !== '') {
        $fe = $pdo->prepare('SELECT 1 FROM file_edits WHERE project = ? AND file = ? AND user = ?');
        $fe->execute([$project, $path, $user]);
        if ($fe->fetchColumn()) {
            $pdo->prepare('UPDATE file_edits SET ts = ? WHERE project = ? AND file = ? AND user = ?')->execute([$now, $project, $path, $user]);
        } else {
            $pdo->prepare('INSERT INTO file_edits (project, file, user, ts) VALUES (?,?,?,?)')->execute([$project, $path, $user, $now]);
        }
        $o = $pdo->prepare('SELECT user FROM file_edits WHERE project = ? AND file = ? AND user <> ? AND ts > ? LIMIT 1');
        $o->execute([$project, $path, $user, $now - 1800]);
        $other = $o->fetchColumn();
        if ($other !== false) {
            $dup = $pdo->prepare('SELECT 1 FROM alerts WHERE project = ? AND file = ? AND created_at > ? AND ((user_a = ? AND user_b = ?) OR (user_a = ? AND user_b = ?))');
            $dup->execute([$project, $path, $now - 1800, $user, $other, $other, $user]);
            if (!$dup->fetchColumn()) {
                $pdo->prepare('INSERT INTO alerts (project, file, user_a, user_b, created_at) VALUES (?,?,?,?,?)')->execute([$project, $path, $user, $other, $now]);
                notify_later("⚠️ Toqquşma riski: $user və $other eyni faylı dəyişir\nProyekt: $project\nFayl: $path");
            }
        }
    }
}
} catch (Throwable $e) {
    // toqquşma yoxlaması uğursuz oldu; hadisə artıq qeydə alınıb
}

// Köhnə qeydləri təmizlə (30 gündən köhnə), hər ~100 sorğudan birində
if (random_int(1, 100) === 1) {
    $pdo->prepare('DELETE FROM file_edits WHERE ts < ?')->execute([$now - 2 * 86400]);
    $pdo->prepare('DELETE FROM alerts WHERE created_at < ?')->execute([$now - 2 * 86400]);
    $cut = $now - 30 * 86400;
    $pdo->prepare('DELETE FROM events WHERE created_at < ?')->execute([$cut]);
    $pdo->prepare('DELETE FROM sessions WHERE updated_at < ?')->execute([$cut]);
}

json_out(['ok' => true]);
