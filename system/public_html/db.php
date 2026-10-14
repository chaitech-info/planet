<?php
$config = require __DIR__ . '/config.php';
// Gizli lokal ayarlar (məs. Gemini API açarı): git-ə düşmür, serverdə əl ilə yaradılır
if (is_file(__DIR__ . '/config.local.php')) {
    $config = array_replace($config, (array)require __DIR__ . '/config.local.php');
}
date_default_timezone_set($config['timezone'] ?? 'Asia/Baku');

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo) {
        return $pdo;
    }
    $c = $config['db'];
    $pdo = new PDO($c['dsn'], $c['user'] ?? null, $c['pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $sqlite = str_starts_with($c['dsn'], 'sqlite');
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    $key = $sqlite ? '' : ', KEY idx_created (created_at)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id $id,
        user VARCHAR(64) NOT NULL,
        session_id VARCHAR(64) NOT NULL,
        project VARCHAR(128) NOT NULL,
        event VARCHAR(32) NOT NULL,
        tool VARCHAR(64) NOT NULL DEFAULT '',
        summary TEXT NOT NULL,
        created_at INT NOT NULL $key
    )");
    if ($sqlite) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_created ON events (created_at)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS sessions (
        user VARCHAR(64) NOT NULL,
        session_id VARCHAR(64) NOT NULL,
        project VARCHAR(128) NOT NULL,
        cwd VARCHAR(512) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL,
        last_prompt TEXT NOT NULL,
        last_summary TEXT NOT NULL,
        edits INT NOT NULL DEFAULT 0,
        tool_count INT NOT NULL DEFAULT 0,
        started_at INT NOT NULL,
        updated_at INT NOT NULL,
        PRIMARY KEY (user, session_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS commands (
        id $id,
        user VARCHAR(64) NOT NULL,
        project VARCHAR(128) NOT NULL,
        prompt TEXT NOT NULL,
        status VARCHAR(16) NOT NULL,
        result TEXT NOT NULL,
        created_at INT NOT NULL,
        started_at INT NOT NULL,
        finished_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS agents (
        user VARCHAR(64) NOT NULL PRIMARY KEY,
        last_seen INT NOT NULL
    )");
    try {
        $pdo->exec('ALTER TABLE agents ADD COLUMN claude_ok INT NOT NULL DEFAULT 1');
    } catch (Throwable $e) {
        // sütun artıq var
    }
    try {
        $pdo->exec("ALTER TABLE agents ADD COLUMN projects TEXT NOT NULL DEFAULT ''");   // agentin icazəli proyekt qovluqları (ping ilə gəlir)
    } catch (Throwable $e) {
        // sütun artıq var
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        name VARCHAR(64) NOT NULL PRIMARY KEY,
        token_hash VARCHAR(64) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        role VARCHAR(16) NOT NULL,
        created_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS aliases (
        src VARCHAR(128) NOT NULL PRIMARY KEY,
        dst VARCHAR(128) NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id $id,
        project VARCHAR(128) NOT NULL,
        title VARCHAR(200) NOT NULL,
        details TEXT NOT NULL,
        assignee VARCHAR(64) NOT NULL,
        creator VARCHAR(64) NOT NULL,
        status VARCHAR(16) NOT NULL,
        command_id INT NOT NULL,
        created_at INT NOT NULL,
        updated_at INT NOT NULL,
        done_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS file_edits (
        project VARCHAR(128) NOT NULL,
        file VARCHAR(300) NOT NULL,
        user VARCHAR(64) NOT NULL,
        ts INT NOT NULL,
        PRIMARY KEY (project, file, user)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
        id $id,
        project VARCHAR(128) NOT NULL,
        file VARCHAR(300) NOT NULL,
        user_a VARCHAR(64) NOT NULL,
        user_b VARCHAR(64) NOT NULL,
        created_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS presence (
        user VARCHAR(64) NOT NULL PRIMARY KEY,
        last_seen INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id $id,
        from_user VARCHAR(64) NOT NULL,
        to_user VARCHAR(64) NOT NULL,
        body TEXT NOT NULL,
        created_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id $id,
        project VARCHAR(128) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        title VARCHAR(200) NOT NULL,
        details TEXT NOT NULL,
        status VARCHAR(16) NOT NULL,
        creator VARCHAR(64) NOT NULL,
        created_at INT NOT NULL,
        updated_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_fail (
        ip VARCHAR(64) NOT NULL,
        ts INT NOT NULL
    )");
    try {
        $pdo->exec('ALTER TABLE tasks ADD COLUMN due_at INT NOT NULL DEFAULT 0');   // tapşırığın son tarixi (0 = yoxdur)
    } catch (Throwable $e) {
        // sütun artıq var
    }
    // İstifadəçilərin öz planetləri (proyekt adı kimi də işləyir: tapşırıq və plan bu ada yazıla bilər)
    $pdo->exec("CREATE TABLE IF NOT EXISTS worlds (
        id $id,
        name VARCHAR(128) NOT NULL,
        owner VARCHAR(64) NOT NULL,
        surface INT NOT NULL DEFAULT 0,
        hue INT NOT NULL DEFAULT 200,
        ring INT NOT NULL DEFAULT 0,
        size INT NOT NULL DEFAULT 2,
        descr TEXT NOT NULL,
        created_at INT NOT NULL,
        updated_at INT NOT NULL
    )");
    // Hər istifadəçinin öz kosmik gəmisi: görünüş + deadline
    $pdo->exec("CREATE TABLE IF NOT EXISTS ships (
        user VARCHAR(64) NOT NULL PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        model VARCHAR(16) NOT NULL,
        color VARCHAR(9) NOT NULL,
        accent VARCHAR(9) NOT NULL,
        deadline INT NOT NULL DEFAULT 0,
        mission VARCHAR(200) NOT NULL DEFAULT '',
        updated_at INT NOT NULL
    )");
    // MCP peykləri: əl ilə qeyd olunan inteqrasiyalar (proyekt boşdursa günəşin ətrafında fırlanır)
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_sats (
        id $id,
        name VARCHAR(64) NOT NULL,
        project VARCHAR(128) NOT NULL,
        descr TEXT NOT NULL,
        owner VARCHAR(64) NOT NULL,
        created_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prompts (
        id $id,
        owner VARCHAR(64) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body TEXT NOT NULL,
        tags VARCHAR(200) NOT NULL DEFAULT '',
        created_at INT NOT NULL,
        updated_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meetings (
        id $id,
        title VARCHAR(200) NOT NULL,
        agenda TEXT NOT NULL,
        starts_at INT NOT NULL,
        minutes INT NOT NULL,
        attendees TEXT NOT NULL,
        creator VARCHAR(64) NOT NULL,
        created_at INT NOT NULL
    )");
    // Jarvis (səsli agent): istifadəçi üzrə gündəlik istifadə (saniyə)
    $pdo->exec("CREATE TABLE IF NOT EXISTS jarvis_usage (
        user VARCHAR(64) NOT NULL,
        day VARCHAR(10) NOT NULL,
        seconds INT NOT NULL,
        PRIMARY KEY (user, day)
    )");
    // Test Planet: proyekt üzrə test ssenariləri (keçdi / xəta / yoxlanmayıb)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tests (
        id $id,
        project VARCHAR(128) NOT NULL,
        title VARCHAR(200) NOT NULL,
        steps TEXT NOT NULL,
        status VARCHAR(8) NOT NULL,
        note TEXT NOT NULL,
        creator VARCHAR(64) NOT NULL,
        tested_by VARCHAR(64) NOT NULL DEFAULT '',
        tested_at INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL,
        updated_at INT NOT NULL
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS meeting_posts (
        id $id,
        meeting_id INT NOT NULL,
        user VARCHAR(64) NOT NULL,
        body TEXT NOT NULL,
        created_at INT NOT NULL
    )");
    // İlk işə düşəndə config.php-dəki istifadəçiləri köçür (birinci admin olur, parolu köhnə ümumi paroldur)
    if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0 && !empty($config['users'])) {
        $first = true;
        foreach ($config['users'] as $tok => $name) {
            $pdo->prepare('INSERT INTO users (name, token_hash, pass_hash, role, created_at) VALUES (?,?,?,?,?)')
                ->execute([$name, hash('sha256', (string)$tok),
                    password_hash((string)($config['dashboard_password'] ?? bin2hex(random_bytes(6))), PASSWORD_DEFAULT),
                    $first ? 'admin' : 'member', time()]);
            $first = false;
        }
    }
    return $pdo;
}

function token_user(): ?string
{
    $t = $_SERVER['HTTP_X_TEAM_TOKEN'] ?? '';
    if ($t === '') {
        return null;
    }
    $st = db()->prepare('SELECT name FROM users WHERE token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    $n = $st->fetchColumn();
    return $n === false ? null : (string)$n;
}

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']),
        ]);
        session_start();
    }
}

function session_user(): ?array
{
    if (empty($_SESSION['ok']) || empty($_SESSION['user'])) {
        return null;
    }
    return ['name' => (string)$_SESSION['user'], 'role' => (string)($_SESSION['role'] ?? 'member')];
}

const EDIT_TOOLS = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];

function setting_get(string $k): string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? '' : (string)$v;
}

function setting_set(string $k, string $v): void
{
    $pdo = db();
    $e = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
    $e->execute([$k]);
    if ($e->fetchColumn()) {
        $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?')->execute([$v, $k]);
    } else {
        $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$k, $v]);
    }
}

/** Telegram qrupuna mesaj göndərir (token və chat id ayarlanıbsa). Uğurludursa true. */
function notify(string $text): bool
{
    $tok = setting_get('tg_token');
    $chat = setting_get('tg_chat');
    if ($tok === '' || $chat === '') {
        return false;
    }
    $url = 'https://api.telegram.org/bot' . $tok . '/sendMessage';
    $body = http_build_query(['chat_id' => $chat, 'text' => mb_substr($text, 0, 3500), 'disable_web_page_preview' => 1]);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
        $r = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        return $r !== false && $code === 200;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $body, 'timeout' => 5]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

/** Cavab istifadəçiyə qayıtdıqdan SONRA göndərir (gecikdirməsin). */
function notify_later(string $text): void
{
    register_shutdown_function(function () use ($text) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        try {
            notify($text);
        } catch (Throwable $e) {
        }
    });
}

function report_window(string $day): array
{
    if ($day === 'yesterday') {
        $s = strtotime('yesterday 00:00');
        return [$s, $s + 86400];
    }
    return [strtotime('today 00:00'), time() + 1];
}

/** İstifadəçi üzrə gündəlik hesabat məlumatı. */
function report_data(PDO $pdo, int $start, int $end): array
{
    $out = [];
    foreach ($pdo->query('SELECT name FROM users ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $out[$u] = ['user' => $u, 'events' => 0, 'prompts' => 0, 'edits' => 0, 'first' => 0, 'last' => 0, 'projects' => [],
            'done' => [], 'review' => 0, 'doing' => 0, 'todo' => 0, 'cmd_done' => 0, 'cmd_failed' => 0];
    }
    $in = "'" . implode("','", EDIT_TOOLS) . "'";
    $q = $pdo->prepare("SELECT user, COUNT(*) AS n, MIN(created_at) AS f, MAX(created_at) AS l,
            SUM(CASE WHEN tool IN ($in) THEN 1 ELSE 0 END) AS edits,
            SUM(CASE WHEN event = 'UserPromptSubmit' THEN 1 ELSE 0 END) AS prompts
        FROM events WHERE created_at >= ? AND created_at < ? GROUP BY user");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        if (isset($out[$r['user']])) {
            $out[$r['user']] = array_merge($out[$r['user']], ['events' => (int)$r['n'], 'first' => (int)$r['f'], 'last' => (int)$r['l'], 'edits' => (int)$r['edits'], 'prompts' => (int)$r['prompts']]);
        }
    }
    $q = $pdo->prepare('SELECT DISTINCT user, project FROM events WHERE created_at >= ? AND created_at < ?');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        if (isset($out[$r['user']])) {
            $out[$r['user']]['projects'][] = $r['project'];
        }
    }
    $q = $pdo->prepare("SELECT assignee, title FROM tasks WHERE status = 'done' AND done_at >= ? AND done_at < ?");
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        if (isset($out[$r['assignee']])) {
            $out[$r['assignee']]['done'][] = $r['title'];
        }
    }
    foreach ($pdo->query("SELECT assignee, status, COUNT(*) AS n FROM tasks WHERE status IN ('todo','doing','review') GROUP BY assignee, status")->fetchAll() as $r) {
        if (isset($out[$r['assignee']])) {
            $out[$r['assignee']][$r['status']] = (int)$r['n'];
        }
    }
    $q = $pdo->prepare('SELECT user, status, COUNT(*) AS n FROM commands WHERE created_at >= ? AND created_at < ? GROUP BY user, status');
    $q->execute([$start, $end]);
    foreach ($q->fetchAll() as $r) {
        if (isset($out[$r['user']])) {
            if ($r['status'] === 'done') {
                $out[$r['user']]['cmd_done'] = (int)$r['n'];
            } elseif ($r['status'] === 'failed') {
                $out[$r['user']]['cmd_failed'] = (int)$r['n'];
            }
        }
    }
    return array_values($out);
}

function report_text(array $rows, string $title): string
{
    $lines = ['📊 ' . $title, ''];
    $any = false;
    foreach ($rows as $r) {
        $active = $r['events'] > 0 || $r['done'] || $r['cmd_done'];
        $any = $any || $active;
        if (!$active && !$r['todo'] && !$r['doing'] && !$r['review']) {
            continue;
        }
        $l = '👤 ' . $r['user'];
        if ($active) {
            $l .= ': ' . count($r['projects']) . ' proyekt (' . implode(', ', array_slice($r['projects'], 0, 5)) . '), '
                . $r['edits'] . ' fayl dəyişikliyi, ' . $r['prompts'] . ' prompt'
                . ($r['first'] ? ', ' . date('H:i', $r['first']) . '–' . date('H:i', $r['last']) : '');
        } else {
            $l .= ': fəaliyyət yoxdur';
        }
        $lines[] = $l;
        foreach (array_slice($r['done'], 0, 6) as $t) {
            $lines[] = '   ✅ ' . $t;
        }
        $open = [];
        if ($r['doing']) {
            $open[] = $r['doing'] . ' işləyir';
        }
        if ($r['review']) {
            $open[] = $r['review'] . ' yoxlamada';
        }
        if ($r['todo']) {
            $open[] = $r['todo'] . ' gözləyir';
        }
        if ($open) {
            $lines[] = '   📌 ' . implode(', ', $open);
        }
    }
    if (!$any) {
        $lines[] = 'Bu dövr üçün fəaliyyət qeydə alınmayıb.';
    }
    return implode("\n", $lines);
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
