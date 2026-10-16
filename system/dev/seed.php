<?php
// Lokal inkişaf üçün nümunə məlumat. Yalnız lokal SQLite bazasına yazır.
// İstifadə:  php system/dev/seed.php          (bazanı sıfırlayıb yenidən doldurur)
// Giriş: config.php-dəki istifadəçilər (parol = config-dəki dashboard_password) + Emil (eyni parol).
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__) . '/public_html';
$config = require $root . '/config.php';
if (!str_starts_with($config['db']['dsn'], 'sqlite:')) {
    exit("Yalnız SQLite bazası üçün\n");
}
$file = substr($config['db']['dsn'], 7);
@mkdir(dirname($file), 0775, true);
@unlink($file);
require $root . '/db.php';
$pdo = db();
$now = time();
$H = 3600;

$pdo->prepare('INSERT INTO users (name, token_hash, pass_hash, role, created_at) VALUES (?,?,?,?,?)')
    ->execute(['Emil', hash('sha256', 'local-dev-emil-token'), password_hash((string)$config['dashboard_password'], PASSWORD_DEFAULT), 'member', $now]);

function sess(PDO $pdo, string $u, string $sid, string $prj, string $st, string $prompt, string $sum, int $edits, int $tools, int $ago): void
{
    global $now;
    $pdo->prepare('INSERT INTO sessions (user, session_id, project, cwd, status, last_prompt, last_summary, edits, tool_count, started_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$u, $sid, $prj, '/Users/' . strtolower($u) . '/' . $prj, $st, $prompt, $sum, $edits, $tools, $now - $ago - 1800, $now - $ago]);
}
function ev(PDO $pdo, string $u, string $sid, string $prj, string $event, string $tool, string $sum, int $ago): void
{
    global $now;
    $pdo->prepare('INSERT INTO events (user, session_id, project, event, tool, summary, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$u, $sid, $prj, $event, $tool, $sum, $now - $ago]);
}
function task(PDO $pdo, string $prj, string $title, string $who, string $st, int $createdAgo, int $doneAgo = 0, int $dueIn = 0): void
{
    global $now;
    $pdo->prepare('INSERT INTO tasks (project, title, details, assignee, creator, status, command_id, created_at, updated_at, done_at, due_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$prj, $title, '', $who, 'Askar', $st, 0, $now - $createdAgo, $now - min($createdAgo, $doneAgo ?: $createdAgo), $st === 'done' ? $now - $doneAgo : 0, $dueIn ? $now + $dueIn : 0]);
}

// Sessiyalar və hadisələr
sess($pdo, 'Askar', 's-neo-1', 'neo', 'working', 'Lead scoring-i düzəlt', 'Edit · app/scoring.py', 14, 60, 20);
sess($pdo, 'Emil', 's-flow-1', 'flowcut', 'waiting', 'Render pipeline-ı sürətləndir', 'İcazə gözləyir', 6, 31, 40);
sess($pdo, 'Dost', 's-sushi-1', 'sushi-date', 'idle', 'Dəvət səhifəsini yenilə', 'Hazırdır', 9, 22, 200);
sess($pdo, 'Askar', 's-planet-1', 'planet', 'idle', 'Kainata yeni planetlər əlavə et', 'Tamamlandı', 3, 12, 3 * $H);
foreach ([
    ['Askar', 's-neo-1', 'neo', 'PostToolUse', 'mcp__neon__run_sql', 'mcp__neon__run_sql · SELECT * FROM leads', 60],
    ['Askar', 's-neo-1', 'neo', 'PostToolUse', 'mcp__vercel__list_deployments', 'mcp__vercel__list_deployments', 300],
    ['Askar', 's-neo-1', 'neo', 'PostToolUse', 'Edit', 'Edit · app/scoring.py', 25],
    ['Askar', 's-neo-1', 'neo', 'UserPromptSubmit', '', 'Tapşırıq: Lead scoring-i düzəlt', 900],
    ['Emil', 's-flow-1', 'flowcut', 'PostToolUse', 'mcp__fal__generate_video', 'mcp__fal__generate_video', 2 * $H],
    ['Emil', 's-flow-1', 'flowcut', 'Notification', '', 'İcazə gözləyir', 40],
    ['Dost', 's-sushi-1', 'sushi-date', 'PostToolUse', 'mcp__github__create_pull_request', 'mcp__github__create_pull_request', 5 * $H],
    ['Dost', 's-sushi-1', 'sushi-date', 'Stop', '', 'Hazırdır', 200],
] as $e) {
    ev($pdo, ...$e);
}

// Tapşırıqlar: neo — 2 saat əvvəl bitib (sakit); flowcut — 30 saatdır heç nə bitməyib (tüstü);
// sushi-date — 3 gündür heç nə bitməyib (yanğın və xaos)
task($pdo, 'neo', 'CRM-də dublikatları birləşdir', 'Askar', 'done', 20 * $H, 2 * $H);
task($pdo, 'neo', 'Scoring modelini yoxla', 'Askar', 'doing', 8 * $H, 0, 3 * $H);
task($pdo, 'flowcut', 'Lambda render-i default et', 'Emil', 'done', 80 * $H, 30 * $H);
task($pdo, 'flowcut', 'Render vaxtını ölç', 'Emil', 'todo', 40 * $H, 0, 5 * $H);
task($pdo, 'sushi-date', 'Telegram botunu bərpa et', 'Dost', 'todo', 90 * $H, 0, -2 * $H);
task($pdo, 'sushi-date', 'Dəvət animasiyası', 'Dost', 'doing', 75 * $H);
task($pdo, 'Designer', 'Yeni loqo eskizləri', 'Dost', 'doing', 4 * $H, 0, 20 * $H);
task($pdo, 'Designer', 'Landing page üçün hero illüstrasiya', 'Emil', 'todo', 2 * $H);

// Gəmilər: Askar-ın deadline-ı yaxındır (miqalka), Dost-unku keçib, Emil-in yoxdur
$sh = $pdo->prepare('INSERT INTO ships (user, name, model, color, accent, deadline, mission, updated_at) VALUES (?,?,?,?,?,?,?,?)');
$sh->execute(['Askar', 'Bakı Şimşəyi', 'fighter', '#d9dde8', '#ef4444', $now + 6 * $H, 'Neo v2 buraxılışı', $now]);
$sh->execute(['Dost', 'Xarıbülbül', 'saucer', '#a7f3d0', '#8b5cf6', $now - 1 * $H, 'MyInvites demo', $now]);
$sh->execute(['Emil', 'Flowcut One', 'shuttle', '#e0e7ff', '#f59e0b', 0, '', $now]);

// Fərdi planet, MCP peyki, prompt, görüşlər
$pdo->prepare('INSERT INTO worlds (name, owner, surface, hue, ring, size, descr, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
    ->execute(['Sevalora', 'Dost', 4, 190, 1, 2, 'Instagram Reels istehsalı', $now, $now]);
$pdo->prepare('INSERT INTO mcp_sats (name, project, descr, owner, created_at) VALUES (?,?,?,?,?)')
    ->execute(['figma', 'Designer', 'Dizayn faylları üçün Figma MCP', 'Emil', $now]);
$pdo->prepare('INSERT INTO mcp_sats (name, project, descr, owner, created_at) VALUES (?,?,?,?,?)')
    ->execute(['slack', '', 'Komanda kanalı (hamı üçün)', 'Askar', $now]);
$pdo->prepare('INSERT INTO prompts (owner, title, body, tags, created_at, updated_at) VALUES (?,?,?,?,?,?)')
    ->execute(['Askar', 'Kod review', "Sən təcrübəli senior developersən.\n\n## Tapşırıq\nAşağıdakı dəyişikliyi səhvlər üçün yoxla.", 'review, kod', $now, $now]);
$mt = $pdo->prepare('INSERT INTO meetings (title, agenda, starts_at, minutes, attendees, creator, created_at) VALUES (?,?,?,?,?,?,?)');
$mt->execute(['Gündəlik stand-up', "1. Dünən nə etdik\n2. Bu gün nə edəcəyik\n3. Maneələr", $now - 600, 30, 'Askar,Dost,Emil', 'Askar', $now]);
$mt->execute(['Sprint planlaması', 'Növbəti iki həftənin hədəfləri', $now + 26 * $H, 60, 'Askar,Emil', 'Askar', $now]);
$pdo->prepare('INSERT INTO meeting_posts (meeting_id, user, body, created_at) VALUES (?,?,?,?)')->execute([1, 'Dost', 'Bu gün dəvət animasiyasını bitirirəm.', $now - 300]);

// Test Planet: neo-da bir xəta var (Test Planet qırmızı yanır)
$ts = $pdo->prepare('INSERT INTO tests (project, title, steps, status, note, creator, tested_by, tested_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
$ts->execute(['neo', 'Lead import CSV-dən işləyir', "1. 100 sətirlik CSV yüklə\n2. Hamısı CRM-də görünməlidir", 'pass', '', 'Askar', 'Dost', $now - 2 * $H, $now - 30 * $H, $now - 2 * $H]);
$ts->execute(['neo', 'Skorinq A/B/C düzgün bölür', 'Nümunə leadlərlə yoxla', 'fail', 'B qrupundakı 3 lead A kimi göründü', 'Askar', 'Askar', $now - 3600, $now - 30 * $H, $now - 3600]);
$ts->execute(['neo', 'Dublikatlar birləşir', '', 'todo', '', 'Askar', '', 0, $now - 30 * $H, $now - 30 * $H]);
$ts->execute(['flowcut', 'Lambda render 60 saniyədən tez bitir', '', 'pass', '', 'Emil', 'Emil', $now - 5 * $H, $now - 50 * $H, $now - 5 * $H]);

// Agent Universe: agentlər və onların tapşırıqları (Askar-ın tapşırığını Emil-in agenti icra edir)
$ag = $pdo->prepare('INSERT INTO agents (user, last_seen, claude_ok) VALUES (?,?,?)');
$ag->execute(['Askar', $now + 3600, 1]);          // lokal demo: bir saat "onlayn" görünsün
$ag->execute(['Emil', $now + 3600, 1]);
$ag->execute(['Dost', $now - 5 * $H, 1]);
$cm = $pdo->prepare('INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at) VALUES (?,?,?,?,?,?,?,?)');
$cm->execute(['Askar', 'neo', 'Scoring modelini yoxla və testləri yenilə', 'done', "3 test əlavə etdim, 1 səhv düzəltdim (scoring.py:88).", $now - 2 * $H, $now - 2 * $H, $now - 2 * $H + 400]);
$cm->execute(['Emil', 'flowcut', 'Render vaxtını ölç', 'running', '', $now - 300, $now - 280, 0]);
$pdo->prepare('UPDATE tasks SET command_id = ? WHERE title = ?')->execute([(int)$pdo->lastInsertId(), 'Render vaxtını ölç']);
$cm->execute(['Dost', 'sushi-date', 'Telegram botunu bərpa et', 'queued', '', $now - 600, 0, 0]);
// Desktop Universe demo: Emil Askara icazə verib, Askar onun kompüterində iki söhbət aparıb (chat = söhbətin ilk komandasının id-si)
$pdo->prepare('INSERT INTO remote_grants (owner, grantee, mode, created_at) VALUES (?,?,?,?)')->execute(['Emil', 'Askar', 'auto', $now - 3 * $H]);
$rm = $pdo->prepare('INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, creator, fresh, chat) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
$rm->execute(['Emil', 'flowcut', "[Askar uzaqdan göndərdi (Desktop Universe)]\nRender vaxtını ölç və qısa hesabat yaz", 'done', 'Orta render 1.8 san; darboğaz encode.py:40-dadır.', $now - 2 * $H, $now - 2 * $H, $now - 2 * $H + 300, 'Askar', 1, 0]);
$c1 = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE commands SET chat = ? WHERE id = ?')->execute([$c1, $c1]);
$rm->execute(['Emil', 'flowcut', "[Askar uzaqdan göndərdi (Desktop Universe)]\nDarboğazı düzəlt", 'done', "↻ əvvəlki söhbət davam etdi\n\nencode.py-də buffer ölçüsünü artırdım, render 1.1 saniyəyə düşdü.", $now - $H, $now - $H, $now - $H + 240, 'Askar', 0, $c1]);
$rm->execute(['Emil', 'flowcut', "[Askar uzaqdan göndərdi (Desktop Universe)]\nREADME-ni yenilə", 'queued', '', $now - 120, 0, 0, 'Askar', 1, 0]);
$c2 = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE commands SET chat = ? WHERE id = ?')->execute([$c2, $c2]);

echo "Hazırdır: $file\n";
