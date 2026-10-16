<?php
// Jarvis missiyaları və protokolları: paylaşılan funksiyalar (mission.php, jarvis_modules/missions.php; agent.php finish-dən də çağırıla bilər).
// db.php-ni özü yükləmir: çağıran tərəf artıq yükləyib. Bütün funksiyalar PDO alır, SQL SQLite/MySQL üçün ümumidir.
//
// Missiya: jarvis_missions.steps = JSON siyahı [{title, project, prompt, after:[0-based indekslər], st, cmd, error}]
//   st: wait (başlamayıb) | queued | running | done | failed | canceled | skipped
// Missiya statusu: draft → running → done | failed | canceled
//   - start: deps-i hazır olan addımlar növbəyə düşür (commands.mission_id/step dolur)
//   - mission_advance: komanda statuslarını addımlara köçürür, yeni açılan addımları növbəyə qoyur.
//     Addım alınmasa (failed/canceled) yalnız ondan ASILI addımlar (keçidli) skipped olur; müstəqil addımlar davam edir.
//     Görüləcək iş qalmayanda: hamısı done → done, əks halda → failed.
//   - Növbə limiti (istifadəçi üzrə 5 aktiv komanda) dolubsa hazır addım "wait"də qalır, sonrakı tick-də növbəyə düşür.
//   - Yarış: dəyişiklik "UPDATE … WHERE id=? AND status='running' AND steps=<köhnə>" (CAS) ilə, komanda insertləri ilə bir tranzaksiyada.
//     rowCount 0 → başqası qabaqlayıb, yenidən oxu. Ona görə tick idempotentdir və eyni addım iki dəfə növbəyə düşmür.

const MISSION_MAX_STEPS = 12;
const MISSION_MAX_PROMPT = 4000;
if (!defined('AGENT_QUEUE_MAX')) {
    define('AGENT_QUEUE_MAX', 5);   // db.php da təyin edir; ikiqat təyin xəbərdarlığı JSON cavabını pozurdu
}

/** Agentin bildirdiyi qovluqlar (agents.projects, sətir-sətir) */
function agent_row(PDO $pdo, string $user): ?array
{
    $st = $pdo->prepare('SELECT * FROM agents WHERE user = ?');
    $st->execute([$user]);
    $a = $st->fetch();
    return $a ?: null;
}

function mission_agent_projects(?array $a): array
{
    return $a ? array_values(array_filter(explode("\n", (string)$a['projects']), 'strlen')) : [];
}

/** Qovluq adı → panel adı (aliases src → dst); alias yoxdursa özü */
function panel_project_name(PDO $pdo, string $folder): string
{
    $st = $pdo->prepare('SELECT dst FROM aliases WHERE src = ?');
    $st->execute([$folder]);
    $d = $st->fetchColumn();
    return $d === false ? $folder : (string)$d;
}

/** agent_send yoxlaması (insertsiz): agent var, proyekt onun qovluqlarındadır. ['ok'=>true, 'agent'=>row] və ya ['error', 'code'] */
function agent_check(PDO $pdo, string $user, string $project): array
{
    if ($project === '' || mb_strlen($project) > 128) {
        return ['error' => 'proyekt adı yanlışdır', 'code' => 400];
    }
    $a = agent_row($pdo, $user);
    if (!$a) {
        return ['error' => 'Bu istifadəçinin Claude Code agenti hələ heç qoşulmayıb. Kompüterdə agenti işə sal (📦 Paket → BASLA.md).', 'code' => 409];
    }
    $allowed = mission_agent_projects($a);
    if ($allowed) {
        $al = $pdo->prepare('SELECT src FROM aliases WHERE dst = ?');
        $al->execute([$project]);
        $names = array_merge([$project], $al->fetchAll(PDO::FETCH_COLUMN));
        if (!array_intersect($names, $allowed)) {
            return ['error' => "Claude Code-un bu kompüterdə \"$project\" üçün qovluğu yoxdur (bu planet kod proyekti deyil və ya agentə əlavə olunmayıb). İcazəli proyektlər: " . implode(', ', $allowed) . '.', 'code' => 409, 'allowed' => $allowed];
        }
    }
    return ['ok' => true, 'agent' => $a, 'allowed' => $allowed];
}

/** İstifadəçinin aktiv (pending/queued/running) komandalarının sayı */
function agent_active_count(PDO $pdo, string $user): int
{
    $c = $pdo->prepare("SELECT COUNT(*) FROM commands WHERE user = ? AND status IN ('pending','queued','running')");
    $c->execute([$user]);
    return (int)$c->fetchColumn();
}

/** Agentin paralel işçi sayı (sütun varsa; yoxdursa 1 = kompüter üzrə növbə sırası ilə) */
function agent_parallel(?array $a): int
{
    return $a && array_key_exists('workers', $a) ? max(1, (int)$a['workers']) : 1;   // agents.workers: agent ping ilə bildirir (agent.php)
}

/**
 * agent_send-in məntiqi (jarvis.php ilə eyni yoxlamalar): istifadəçinin öz agentinə Claude Code komandası.
 * $o: mission_id, step, fresh (1 = təzə söhbət), creator, check_queue (false = limiti çağıran yoxlayıb).
 * Qaytarır: ['ok'=>true, 'id', 'agent_online', 'claude_login', 'allowed'] və ya ['error', 'code'(HTTP)].
 */
function queue_agent_command(PDO $pdo, string $user, string $project, string $prompt, array $o = []): array
{
    $project = trim($project);
    $prompt = trim($prompt);
    if ($prompt === '' || mb_strlen($prompt) > MISSION_MAX_PROMPT) {
        return ['error' => 'prompt 1-4000 simvol olmalıdır', 'code' => 400];
    }
    $chk = agent_check($pdo, $user, $project);
    if (empty($chk['ok'])) {
        return $chk;
    }
    if (($o['check_queue'] ?? true) && agent_active_count($pdo, $user) >= AGENT_QUEUE_MAX) {
        return ['error' => 'növbədə artıq 5 tapşırıq var, gözlə', 'code' => 429];
    }
    $now = time();
    // A: kind (prompt|task|review|think|ops), model (''|opus|sonnet|haiku), mode (''|plan), said_at (ms) — yanlış dəyər defolta düşür
    $kind = in_array($o['kind'] ?? '', ['prompt', 'task', 'review', 'think', 'ops'], true) ? (string)$o['kind'] : 'prompt';
    $model = in_array($o['model'] ?? '', ['', 'opus', 'sonnet', 'haiku'], true) ? (string)($o['model'] ?? '') : '';
    $cmode = in_array($o['mode'] ?? '', ['', 'plan'], true) ? (string)($o['mode'] ?? '') : '';
    $saidAt = is_numeric($o['said_at'] ?? null) && abs((int)$o['said_at'] - (int)floor(microtime(true) * 1000)) < 86400000 ? (int)$o['said_at'] : 0;
    $pdo->prepare('INSERT INTO commands (user, project, prompt, status, result, created_at, started_at, finished_at, creator, fresh, mission_id, step, kind, model, mode, said_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$user, $project, $prompt, 'queued', '', $now, 0, 0, (string)($o['creator'] ?? ''), (int)($o['fresh'] ?? 0), (int)($o['mission_id'] ?? 0), (int)($o['step'] ?? 0), $kind, $model, $cmode, $saidAt]);
    $id = (int)$pdo->lastInsertId();
    emit_event($pdo, 'command.queued', $user, $project, $id, ['id' => $id, 'project' => $project, 'prompt' => mb_substr($prompt, 0, 300), 'kind' => $kind]);
    $a = $chk['agent'];
    return ['ok' => true, 'id' => $id, 'agent_online' => $now - (int)$a['last_seen'] < 30, 'claude_login' => (bool)$a['claude_ok'], 'allowed' => $chk['allowed']];
}

/* ---------- Missiya addımları ---------- */

/** Addımları yoxla və normallaşdır. Qaytarır: [steps, null] və ya [null, xəta]. after: 0-based, dövrsüz. */
function mission_clean_steps($raw): array
{
    if (!is_array($raw) || !$raw) {
        return [null, 'addımlar lazımdır'];
    }
    $raw = array_values($raw);
    $n = count($raw);
    if ($n > MISSION_MAX_STEPS) {
        return [null, 'ən çox ' . MISSION_MAX_STEPS . " addım olar ($n verildi)"];
    }
    $out = [];
    foreach ($raw as $i => $s) {
        if (!is_array($s)) {
            return [null, 'addım ' . ($i + 1) . ': yanlış format'];
        }
        $title = trim(preg_replace('/\s+/u', ' ', (string)($s['title'] ?? '')));
        $project = trim((string)($s['project'] ?? ''));
        $prompt = trim((string)($s['prompt'] ?? ''));
        if ($project === '' || mb_strlen($project) > 128) {
            return [null, 'addım ' . ($i + 1) . ': proyekt adı yanlışdır'];
        }
        if ($prompt === '' || mb_strlen($prompt) > MISSION_MAX_PROMPT) {
            return [null, 'addım ' . ($i + 1) . ': prompt 1-4000 simvol olmalıdır'];
        }
        if ($title === '') {
            $title = mb_substr(preg_replace('/\s+/u', ' ', $prompt), 0, 60);
        }
        $after = [];
        foreach ((array)($s['after'] ?? []) as $d) {
            if (!is_numeric($d) || (int)$d < 0 || (int)$d >= $n || (int)$d === $i) {
                return [null, 'addım ' . ($i + 1) . ': "after" yanlış indeksdir (' . json_encode($d) . '); 0-dan ' . ($n - 1) . '-ə qədər, özündən başqa'];
            }
            $after[] = (int)$d;
        }
        $out[] = ['title' => mb_substr($title, 0, 120), 'project' => $project, 'prompt' => $prompt, 'after' => array_values(array_unique($after)), 'st' => 'wait', 'cmd' => 0, 'error' => ''];
    }
    // dövr yoxlaması (Kahn)
    $deg = array_map(fn($s) => count($s['after']), $out);
    $q = array_keys(array_filter($deg, fn($d) => $d === 0));
    $seen = 0;
    while ($q) {
        $k = array_shift($q);
        $seen++;
        foreach ($out as $j => $s) {
            if (in_array($k, $s['after'], true) && --$deg[$j] === 0) {
                $q[] = $j;
            }
        }
    }
    if ($seen !== $n) {
        return [null, 'addımların asılılığında dövr var'];
    }
    return [$out, null];
}

/** Addım üçün Claude Code-a gedən prompt: missiya konteksti + əvvəlki addımların nəticələri (sığarsa) */
function mission_step_prompt(array $m, array $steps, int $i, array $results): string
{
    $s = $steps[$i];
    $head = "[Mission #{$m['id']} \"{$m['title']}\" — step " . ($i + 1) . '/' . count($steps) . ": {$s['title']}]\n"
        . ($m['goal'] !== '' ? 'Mission goal: ' . mb_substr($m['goal'], 0, 400) . "\n" : '') . "\n";
    $tail = "\n\nSafety: do not push, do not force-reset or delete branches, do not run destructive git commands. Finish with a short report of what you did.";
    $deps = '';
    foreach ($s['after'] as $d) {
        $r = trim((string)($results[$d] ?? ''));
        if ($r !== '') {
            $deps .= "\n- Step " . ($d + 1) . " ({$steps[$d]['project']}): " . mb_substr($r, -600);
        }
    }
    $deps = $deps !== '' ? "\n\nResults of earlier steps (context):" . $deps : '';
    foreach ([$head . $s['prompt'] . $deps . $tail, $head . $s['prompt'] . $tail, $head . $s['prompt'], $s['prompt']] as $p) {
        if (mb_strlen($p) <= MISSION_MAX_PROMPT) {
            return $p;
        }
    }
    return mb_substr($s['prompt'], 0, MISSION_MAX_PROMPT);
}

function mission_row(PDO $pdo, int $id, ?string $user = null): ?array
{
    $st = $pdo->prepare('SELECT * FROM jarvis_missions WHERE id = ?' . ($user !== null ? ' AND user = ?' : ''));
    $st->execute($user !== null ? [$id, $user] : [$id]);
    $m = $st->fetch();
    return $m ?: null;
}

/** Addımın son komandası (mission_id/step üzrə) — steps JSON-dakı cmd ilə eyni, ehtiyat üçün */
function mission_commands(PDO $pdo, int $mid): array
{
    $st = $pdo->prepare('SELECT id, status, result, progress, step FROM commands WHERE mission_id = ? ORDER BY id ASC');
    $st->execute([$mid]);
    $by = [];
    foreach ($st->fetchAll() as $c) {
        $by[(int)$c['id']] = $c;
    }
    return $by;
}

/**
 * Missiyanı irəlilət (idempotent, yarışa davamlı). Missiya running deyilsə heç nə etmir.
 * $start = true: draft → running keçidi də burada (eyni CAS ilə).
 * Qaytarır: ['mission' => sətir|null, 'changed' => bool, 'finished' => bool (bu çağırış missiyanı bitirdi), 'launched' => [[step, cmd]], 'errors' => [step => xəta]]
 */
function mission_advance(PDO $pdo, int $missionId, bool $start = false): array
{
    $res = ['mission' => null, 'changed' => false, 'finished' => false, 'launched' => [], 'errors' => []];
    for ($try = 0; $try < 5; $try++) {
        $m = mission_row($pdo, $missionId);
        $res['mission'] = $m;
        if (!$m || !($m['status'] === 'running' || ($start && $m['status'] === 'draft'))) {
            return $res;
        }
        $oldStatus = $m['status'];
        $oldSteps = (string)$m['steps'];
        $steps = json_decode($oldSteps, true);
        if (!is_array($steps)) {
            return $res;
        }
        $user = (string)$m['user'];
        $cmds = mission_commands($pdo, $missionId);
        $results = [];
        // 1) komanda statuslarını addımlara köçür
        foreach ($steps as $i => &$s) {
            $cid = (int)($s['cmd'] ?? 0);
            if ($cid && in_array($s['st'], ['queued', 'running'], true)) {
                $c = $cmds[$cid] ?? null;
                if (!$c) {
                    $s['st'] = 'failed';
                    $s['error'] = 'komanda tapılmadı';
                } else {
                    $cs = $c['status'] === 'pending' ? 'queued' : (string)$c['status'];
                    if (in_array($cs, ['queued', 'running', 'done', 'failed', 'canceled'], true)) {
                        $s['st'] = $cs;
                    }
                }
            }
            if ($cid && isset($cmds[$cid])) {
                $results[$i] = (string)$cmds[$cid]['result'];
            }
        }
        unset($s);
        // 2) alınmayan addımlardan asılı olanlar → skipped (keçidli)
        do {
            $more = false;
            foreach ($steps as $i => &$s) {
                if ($s['st'] !== 'wait') {
                    continue;
                }
                foreach ($s['after'] as $d) {
                    if (in_array($steps[$d]['st'], ['failed', 'canceled', 'skipped'], true)) {
                        $s['st'] = 'skipped';
                        $s['error'] = 'addım ' . ($d + 1) . ' alınmadı';
                        $more = true;
                        break;
                    }
                }
            }
            unset($s);
        } while ($more);
        // 3) hazır addımlar: bütün deps done
        $ready = [];
        foreach ($steps as $i => $s) {
            if ($s['st'] === 'wait' && !array_filter($s['after'], fn($d) => $steps[$d]['st'] !== 'done')) {
                $ready[] = $i;
            }
        }
        $launch = [];
        if ($ready) {
            $free = AGENT_QUEUE_MAX - agent_active_count($pdo, $user);
            foreach ($ready as $i) {
                $chk = agent_check($pdo, $user, $steps[$i]['project']);
                if (empty($chk['ok'])) {
                    $steps[$i]['st'] = 'failed';
                    $steps[$i]['error'] = (string)$chk['error'];
                    $res['errors'][$i] = (string)$chk['error'];
                    continue;
                }
                if ($free <= 0) {
                    continue;                       // növbə dolu: wait-də qalır, sonrakı tick
                }
                $free--;
                $steps[$i]['st'] = 'queued';
                $launch[] = $i;
            }
            if ($res['errors']) {                   // yeni xəta → asılıları skip etmək üçün təkrar keçid sonrakı tick-ə qalmasın
                do {
                    $more = false;
                    foreach ($steps as $i => &$s) {
                        if ($s['st'] === 'wait') {
                            foreach ($s['after'] as $d) {
                                if (in_array($steps[$d]['st'], ['failed', 'canceled', 'skipped'], true)) {
                                    $s['st'] = 'skipped';
                                    $s['error'] = 'addım ' . ($d + 1) . ' alınmadı';
                                    $more = true;
                                    break;
                                }
                            }
                        }
                    }
                    unset($s);
                } while ($more);
            }
        }
        // 4) missiyanın statusu
        $sts = array_column($steps, 'st');
        $open = array_intersect($sts, ['wait', 'queued', 'running']);
        $status = $open ? 'running' : (count(array_filter($sts, fn($x) => $x === 'done')) === count($sts) ? 'done' : 'failed');
        $newSteps = json_encode($steps, JSON_UNESCAPED_UNICODE);
        if ($newSteps === $oldSteps && $status === $oldStatus) {
            return $res;                            // dəyişiklik yoxdur
        }
        // 5) CAS + insertlər bir tranzaksiyada
        $now = time();
        $pdo->beginTransaction();
        try {
            $up = $pdo->prepare('UPDATE jarvis_missions SET steps = ?, status = ?, updated_at = ? WHERE id = ? AND status = ? AND steps = ?');
            $up->execute([$newSteps, $status, $now, $missionId, $oldStatus, $oldSteps]);
            if ($up->rowCount() !== 1) {
                $pdo->rollBack();
                continue;                           // başqa tick qabaqladı: yenidən oxu
            }
            foreach ($launch as $i) {
                $r = queue_agent_command($pdo, $user, $steps[$i]['project'], mission_step_prompt($m, $steps, $i, $results),
                    ['mission_id' => $missionId, 'step' => $i, 'fresh' => 1, 'check_queue' => false]);
                if (!empty($r['ok'])) {
                    $steps[$i]['cmd'] = $r['id'];
                    $res['launched'][] = [$i, $r['id']];
                } else {
                    $steps[$i]['st'] = 'failed';
                    $steps[$i]['error'] = (string)$r['error'];
                    $res['errors'][$i] = (string)$r['error'];
                }
            }
            if ($launch) {
                $pdo->prepare('UPDATE jarvis_missions SET steps = ? WHERE id = ?')->execute([json_encode($steps, JSON_UNESCAPED_UNICODE), $missionId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        $res['changed'] = true;
        $res['finished'] = $status !== 'running';
        $res['mission'] = mission_row($pdo, $missionId);
        if ($launch && count($res['launched']) < count($launch)) {   // insert alınmadı (yarış): statusu yenidən hesabla
            $again = mission_advance($pdo, $missionId);
            $again['launched'] = array_merge($res['launched'], $again['launched']);
            $again['errors'] += $res['errors'];
            $again['finished'] = $again['finished'] || $res['finished'];
            return $again;
        }
        return $res;
    }
    $res['mission'] = mission_row($pdo, $missionId);
    return $res;
}

/** Missiyanı ləğv et: gözləyən addımlar canceled, növbədəki və işləyən komandaları canceled (agent poll ilə dayandırır). */
function mission_cancel(PDO $pdo, int $missionId, string $user): array
{
    $now = time();
    $n = 0;
    for ($try = 0; $try < 5; $try++) {
        $m = mission_row($pdo, $missionId, $user);
        if (!$m || !in_array($m['status'], ['draft', 'running'], true)) {
            return ['mission' => $m, 'canceled_commands' => $n, 'ok' => false];
        }
        $steps = json_decode((string)$m['steps'], true) ?: [];
        foreach ($steps as &$s) {
            if (in_array($s['st'], ['wait', 'queued', 'running'], true)) {
                $s['st'] = 'canceled';
            }
        }
        unset($s);
        $up = $pdo->prepare("UPDATE jarvis_missions SET status = 'canceled', steps = ?, updated_at = ? WHERE id = ? AND status = ? AND steps = ?");
        $up->execute([json_encode($steps, JSON_UNESCAPED_UNICODE), $now, $missionId, $m['status'], $m['steps']]);
        if ($up->rowCount() === 1) {
            $c = $pdo->prepare("UPDATE commands SET status = 'canceled', result = CASE WHEN result = '' THEN ? ELSE result END, finished_at = ? WHERE mission_id = ? AND user = ? AND status IN ('pending','queued','running')");
            $c->execute(['Missiya ləğv edildi', $now, $missionId, $user]);
            $n = $c->rowCount();
            return ['mission' => mission_row($pdo, $missionId), 'canceled_commands' => $n, 'ok' => true];
        }
    }
    return ['mission' => mission_row($pdo, $missionId), 'canceled_commands' => 0, 'ok' => false];
}

/** Brauzer/Jarvis üçün missiya görünüşü: addımlar + komanda gedişatı (progress.pct) */
function mission_view(PDO $pdo, array $m, bool $withResults = false): array
{
    $steps = json_decode((string)$m['steps'], true) ?: [];
    $cmds = mission_commands($pdo, (int)$m['id']);
    $out = [];
    $sum = 0;
    foreach ($steps as $i => $s) {
        $c = $cmds[(int)($s['cmd'] ?? 0)] ?? null;
        $pg = $c ? json_decode((string)$c['progress'], true) : null;
        $pct = match ($s['st']) {
            'done' => 100, 'running' => max(3, min(99, (int)($pg['pct'] ?? 5))), 'failed', 'canceled', 'skipped' => 100, default => 0,
        };
        $sum += $s['st'] === 'done' ? 100 : ($s['st'] === 'running' ? $pct : 0);
        $v = ['index' => $i, 'title' => $s['title'], 'project' => $s['project'], 'after' => $s['after'], 'status' => $s['st'], 'pct' => $pct, 'command_id' => (int)($s['cmd'] ?? 0)];
        if (!empty($pg['step'])) {
            $v['doing'] = mb_substr((string)$pg['step'], 0, 120);
        }
        if ($s['error'] !== '') {
            $v['error'] = $s['error'];
        }
        if ($withResults && $c && trim((string)$c['result']) !== '') {
            $v['result'] = mb_substr(trim((string)$c['result']), -700);
        }
        $out[] = $v;
    }
    $cnt = array_count_values(array_column($steps, 'st'));
    return ['id' => (int)$m['id'], 'title' => $m['title'], 'goal' => $m['goal'], 'status' => $m['status'], 'created_at' => (int)$m['created_at'], 'updated_at' => (int)$m['updated_at'],
        'pct' => $steps ? (int)round($sum / count($steps)) : 0, 'counts' => $cnt, 'steps' => $out];
}

/** Yeni missiya (draft) */
function mission_create(PDO $pdo, string $user, string $title, string $goal, array $steps): int
{
    $now = time();
    $pdo->prepare('INSERT INTO jarvis_missions (user, title, goal, steps, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$user, $title, $goal, json_encode($steps, JSON_UNESCAPED_UNICODE), 'draft', $now, $now]);
    return (int)$pdo->lastInsertId();
}

/* ---------- Protokollar ---------- */

/** Daxili protokollar (owner 'system', shared): yoxdursa yaradılır */
function protocol_defaults(): array
{
    $safe = ' Do not push, do not open pull requests, do not force-reset, rebase or delete branches, and do not run any destructive git or file commands.';
    return [
        ['name' => 'House Party Protocol', 'descr' => 'Bütün proyektlər: test suite-i işlət, sadə xətaları düzəlt, hesabat ver (paralel).',
            'steps' => [['title' => 'Tests · {project}', 'project' => '*', 'prompt' => 'Run the full test suite of this project ({project}). If some tests fail for trivial reasons (typos, outdated snapshots or assertions, missing imports, obvious small bugs), fix them and run the tests again. Do not change behaviour beyond what the fix needs and do not touch failures that need a design decision: just describe them.' . $safe . ' Report: how many tests ran, passed and failed, what you fixed (files), and what still fails and why.']]],
        ['name' => 'Night Shift Protocol', 'descr' => 'Hər proyekt üçün gecə baxışı: asılılıq auditi + TODO/FIXME skanı, yalnız hesabat.',
            'steps' => [['title' => 'Night review · {project}', 'project' => '*', 'prompt' => 'Night shift review of this project ({project}). REPORT ONLY: do not modify, create or delete any files and do not install or upgrade anything. 1) Dependency audit: list the dependencies, flag outdated or vulnerable ones (use the package manager\'s audit/outdated command in read-only mode if available). 2) Scan the code for TODO, FIXME, HACK and XXX comments and summarise the important ones with file:line. 3) Note any obvious risks you see (secrets in code, dead config).' . $safe . ' Finish with a short prioritised list of recommendations.']]],
        ['name' => 'Clean Slate Protocol', 'descr' => 'Bir proyekt: lint/format → ölü kodu sil → testlər; PR yox, hesabat.',
            'steps' => [
                ['title' => 'Lint & format · {project}', 'project' => '{project}', 'prompt' => 'Run the project\'s existing linter and formatter (only tools already configured in the repository) and apply the safe automatic fixes. Do not reformat unrelated generated or vendored files.' . $safe . ' Report the commands you ran and the files changed.'],
                ['title' => 'Dead code · {project}', 'project' => '{project}', 'prompt' => 'Find and remove clearly dead code in this project: unused imports, unused private functions and variables, unreachable branches, commented-out code blocks. Only remove what is provably unused; when in doubt, leave it and list it instead.' . $safe . ' Report what you removed (file and symbol) and what you left as doubtful.', 'after' => [0]],
                ['title' => 'Tests · {project}', 'project' => '{project}', 'prompt' => 'Run the full test suite of this project ({project}) after the cleanup. If a test fails because of the cleanup, fix it (or restore the removed code). Do not open a pull request.' . $safe . ' Report the test results and a short summary of the whole cleanup (lint/format, dead code, tests).', 'after' => [1]],
            ]],
        // Texniki xidmət protokolları (cədvəllə də işlədilir: schedule.php)
        ['name' => 'Dependency Update Protocol', 'descr' => 'Hər proyekt: asılılıqları semver daxilində yenilə, testlər, hesabat (push yox).',
            'steps' => [['title' => 'Deps update · {project}', 'project' => '*', 'prompt' => 'Dependency update for this project ({project}). 1) If the working tree already has uncommitted changes, STOP and report (do not mix with the user\'s work). 2) Using the project\'s own package manager(s), update dependencies only WITHIN the version ranges the manifest already allows (semver-compatible: npm/pnpm/yarn update, composer update, pip within pins, cargo update …); never raise major versions or edit version ranges. 3) Run the test suite (and build/lint if configured). 4) If tests fail because of an update, revert that package (or all lockfile changes) and note it. 5) If tests pass, commit locally with the message "chore(deps): update within semver".' . $safe . ' If package-manager or test commands are blocked by permissions, stop and list the exact commands to run manually. Report: packages updated (old → new), test results, anything reverted or skipped.']]],
        ['name' => 'Prod Error Sweep Protocol', 'descr' => 'Hər proyekt: prod loglarına/xəta izləyicilərinə bax, top xətalar + ehtimal olunan səbəb (yalnız oxu).',
            'steps' => [['title' => 'Error sweep · {project}', 'project' => '*', 'prompt' => 'Production error sweep for this project ({project}). READ-ONLY: do not modify files, do not deploy, do not change any remote setting, do not resolve or ignore issues in trackers. Use what is available to you: configured MCP servers (e.g. Sentry, Vercel, Neon, logging), read-only CLIs (vercel logs, gh run list …) and log files in the repository. If nothing is available, say so and stop. List the top errors of the last 24-72 h (message, frequency, first/last seen, affected route or function) and for each the suspected cause with file:line in this codebase when you can find it, plus a one-line fix suggestion. Never print secrets, tokens or personal data from logs.' . $safe . ' Finish with a short prioritised list.']]],
        ['name' => 'Release & Deploy Protocol', 'descr' => 'Bir proyekt: versiya + changelog, testlər → deploy (yalnız agent profili ops/full olsa).',
            'steps' => [
                ['title' => 'Release prep · {project}', 'project' => '{project}', 'prompt' => 'Prepare a release of this project ({project}). 1) If the working tree has uncommitted changes, STOP and report. 2) Run the full test suite (and build if configured); if anything fails, STOP and report: no release. 3) Bump the version (patch by default, minor if there are new features since the last tag) in the project\'s version files and add a CHANGELOG entry summarising the commits since the last tag/release. 4) Commit locally ("release: vX.Y.Z") and create a local tag vX.Y.Z.' . $safe . ' Report the new version, the changelog entry and the test results.'],
                ['title' => 'Deploy · {project}', 'project' => '{project}', 'prompt' => 'Deploy the release just prepared for this project ({project}). FIRST check whether deploying is allowed on this computer: run `echo "$CLAUDE_TEAM_PROFILE"`. Deploy ONLY if it prints "ops" or "full". If it prints anything else, the command is blocked, or you are unsure: DO NOT deploy; stop and report "deploy not allowed on this agent profile" with the exact commands the user should run manually. If allowed: use ONLY the repository\'s own deploy script or documented deploy command (package.json script, Makefile target, deploy.sh, README); never invent a deploy procedure. Afterwards check the deploy output or the documented health URL.' . $safe . ' Report what was deployed, where, and the result.', 'after' => [0]],
            ]],
    ];
}

/** Daxili protokolları yarat: hər ad yalnız bir dəfə (settings.protocols_seeded); silinmiş daxili protokol geri qayıtmır, yeni əlavə olunan default-lar köhnə bazaya da düşür */
function protocol_seed(PDO $pdo): void
{
    $st = $pdo->prepare("SELECT v FROM settings WHERE k = 'protocols_seeded'");
    $st->execute();
    $raw = $st->fetchColumn();
    if ($raw === false) {                       // köhnə qayda (hamısı birdən) ilk 3-ü artıq yaradıb və ya admin silib: onları "əkilmiş" say
        $had = (int)$pdo->query("SELECT COUNT(*) FROM jarvis_protocols WHERE owner = 'system'")->fetchColumn() > 0;
        $done = $had ? ['House Party Protocol', 'Night Shift Protocol', 'Clean Slate Protocol'] : [];
    } else {
        $done = array_filter(explode("\n", (string)$raw), 'strlen');
    }
    $todo = array_filter(protocol_defaults(), fn($p) => !in_array($p['name'], $done, true));
    if (!$todo) {
        return;
    }
    $now = time();
    $ex = $pdo->prepare("SELECT 1 FROM jarvis_protocols WHERE owner = 'system' AND name = ?");
    $ins = $pdo->prepare('INSERT INTO jarvis_protocols (owner, name, descr, steps, shared, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
    foreach ($todo as $p) {
        $ex->execute([$p['name']]);
        if (!$ex->fetchColumn()) {
            $ins->execute(['system', $p['name'], $p['descr'], json_encode($p['steps'], JSON_UNESCAPED_UNICODE), 1, $now, $now]);
        }
        $done[] = $p['name'];
    }
    $v = implode("\n", array_values(array_unique($done)));
    try {
        $pdo->prepare($raw === false ? "INSERT INTO settings (k, v) VALUES ('protocols_seeded', ?)" : "UPDATE settings SET v = ? WHERE k = 'protocols_seeded'")->execute([$v]);
    } catch (Throwable $e) {                    // paralel sorğu artıq yazıb
    }
}

/** İstifadəçinin görə bildiyi protokollar: paylaşılanlar + özününkülər */
function protocol_list(PDO $pdo, string $user): array
{
    protocol_seed($pdo);
    $st = $pdo->prepare('SELECT * FROM jarvis_protocols WHERE shared = 1 OR owner = ? ORDER BY CASE WHEN owner = ? THEN 0 ELSE 1 END, name');
    $st->execute([$user, $user]);
    return $st->fetchAll();
}

/** Protokol şablon addımlarının yoxlaması (project: ad | "{project}" | "*") */
function protocol_clean_steps($raw): array
{
    if (!is_array($raw) || !$raw || count($raw) > MISSION_MAX_STEPS) {
        return [null, 'protokolda 1-' . MISSION_MAX_STEPS . ' addım olmalıdır'];
    }
    [$s, $err] = mission_clean_steps($raw);       // eyni qaydalar (prompt, after, dövr)
    if ($err) {
        return [null, $err];
    }
    return [array_map(fn($x) => ['title' => $x['title'], 'project' => $x['project'], 'prompt' => $x['prompt'], 'after' => $x['after']], $s), null];
}

/** Protokol → missiya addımları. {project} → $project, "*" → agentin hər proyekti (paralel). Qaytarır [steps, null] və ya [null, xəta] */
function protocol_expand(PDO $pdo, string $user, array $tpl, string $project): array
{
    $needOne = $needAll = false;
    foreach ($tpl as $t) {
        $needAll = $needAll || $t['project'] === '*';
        $needOne = $needOne || str_contains($t['project'], '{project}');
    }
    if ($needOne && $project === '') {
        return [null, 'bu protokol bir proyekt üçündür: proyekt adını de'];
    }
    $all = [];
    if ($needAll) {
        if ($project !== '') {
            $all = [$project];                      // "*" + konkret proyekt verilib → yalnız o
        } else {
            $a = agent_row($pdo, $user);
            if (!$a) {
                return [null, 'Claude Code agenti hələ qoşulmayıb: "*" (bütün proyektlər) siyahısı yoxdur'];
            }
            $all = array_values(array_unique(array_map(fn($f) => panel_project_name($pdo, $f), mission_agent_projects($a))));
            if (!$all) {
                return [null, 'agent proyekt siyahısı bildirməyib'];
            }
        }
    }
    $out = [];
    $map = [];                                      // şablon indeksi → [proyekt => yeni indeks]
    foreach ($tpl as $ti => $t) {
        $t += ['title' => '', 'project' => '', 'prompt' => '', 'after' => []];
        $projs = $t['project'] === '*' ? $all : [str_replace('{project}', $project, $t['project'])];
        foreach ($projs as $p) {
            $after = [];
            foreach ((array)($t['after'] ?? []) as $d) {
                $dm = $map[$d] ?? [];
                $after = array_merge($after, isset($dm[$p]) && $tpl[$d]['project'] === '*' && $t['project'] === '*' ? [$dm[$p]] : array_values($dm));
            }
            $map[$ti][$p] = count($out);
            $out[] = ['title' => str_replace('{project}', $p, $t['title']), 'project' => $p, 'prompt' => str_replace('{project}', $p, $t['prompt']), 'after' => array_values(array_unique($after))];
        }
    }
    if (count($out) > MISSION_MAX_STEPS) {
        return [null, 'protokol ' . count($out) . ' addıma açılır, limit ' . MISSION_MAX_STEPS . '-dir: proyekt adı ilə işlət'];
    }
    return mission_clean_steps($out);
}

/** Ad üzrə protokol (dəqiq, sonra "protocol/protokol" sözsüz, sonra hissə). Öz protokolu paylaşılandan önəmlidir. */
function protocol_find(PDO $pdo, string $user, string $q, int $id = 0): ?array
{
    $list = protocol_list($pdo, $user);
    if ($id) {
        foreach ($list as $p) {
            if ((int)$p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }
    $n = fn($s) => trim(preg_replace('/\s+/u', ' ', preg_replace('/\b(protocol|protokolu?|protokol)\b/iu', '', mb_strtolower($s))));
    $nq = $n($q);
    if ($nq === '') {
        return null;
    }
    foreach ([fn($p) => $n($p['name']) === $nq, fn($p) => str_contains($n($p['name']), $nq) || str_contains($nq, $n($p['name']))] as $f) {
        foreach ($list as $p) {
            if ($f($p)) {
                return $p;
            }
        }
    }
    return null;
}

/* ---------- Cədvəllər (schedules, workstream E): "daily HH:MM" | "weekdays HH:MM" | "weekly mon..sun HH:MM", serverin saat qurşağında (config timezone) ----------
   schedules_due() agent.php claim və feed.php-dən (guarded) çağırılır: vaxtı çatmış cədvəl əvvəl CAS ilə işarələnir (last_run), sonra protokol missiyası
   və ya tək prompt komandası sahibin agentinə düşür, nəticə jarvis.notice hadisəsi ilə gedir. */
const SCHEDULE_DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
const SCHEDULE_GRACE = 6 * 3600;            // bundan gec aşkarlanan buraxılmış icra işə düşmür (yalnız bildiriş)

/** Qaydanı yoxla və normallaşdır: ['kind', 'dow' (1-7, weekly), 'h', 'm', 'text'] və ya null */
function schedule_parse_rule(string $rule): ?array
{
    $r = strtolower(trim((string)preg_replace('/\s+/', ' ', $rule)));
    if (!preg_match('/^(daily|weekdays|weekly)(?: ([a-z]+))? (\d{1,2})[:.](\d{2})$/', $r, $m) || (int)$m[3] > 23 || (int)$m[4] > 59) {
        return null;
    }
    $dow = 0;
    if ($m[1] === 'weekly') {
        foreach (SCHEDULE_DAYS as $i => $d) {
            if (strlen($m[2]) >= 3 && str_starts_with($d, $m[2])) {
                $dow = $i + 1;
            }
        }
        if (!$dow) {
            return null;
        }
    } elseif ($m[2] !== '') {
        return null;
    }
    $hm = sprintf('%02d:%02d', $m[3], $m[4]);
    return ['kind' => $m[1], 'dow' => $dow, 'h' => (int)$m[3], 'm' => (int)$m[4],
        'text' => $m[1] . ($dow ? ' ' . substr(SCHEDULE_DAYS[$dow - 1], 0, 3) : '') . ' ' . $hm];
}

/** $dir < 0: ən son baş vermə ≤ $now; $dir > 0: növbəti > $now (unix ts, 0 = tapılmadı) */
function schedule_occurrence(array $r, int $now, int $dir): int
{
    $base = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    for ($i = 0; $i <= 8; $i++) {
        $c = $base->modify(($dir < 0 ? '-' : '+') . $i . ' day')->setTime($r['h'], $r['m']);
        $t = $c->getTimestamp();
        $w = (int)$c->format('N');
        if (($dir < 0 ? $t > $now : $t <= $now) || ($r['kind'] === 'weekdays' && $w > 5) || ($r['kind'] === 'weekly' && $w !== $r['dow'])) {
            continue;
        }
        return $t;
    }
    return 0;
}

/** Brauzer/Jarvis üçün görünüş */
function schedule_view(array $s, int $now = 0): array
{
    $r = schedule_parse_rule((string)$s['rule']);
    $next = $r && (int)$s['enabled'] ? schedule_occurrence($r, $now ?: time(), 1) : 0;
    return ['id' => (int)$s['id'], 'owner' => $s['owner'], 'name' => $s['name'], 'rule' => $s['rule'], 'protocol' => $s['protocol'], 'project' => $s['project'],
        'prompt' => mb_substr((string)$s['prompt'], 0, 200), 'enabled' => (bool)(int)$s['enabled'],
        'last_run' => (int)$s['last_run'] ? date('Y-m-d H:i', (int)$s['last_run']) : null, 'next_run' => $next ? date('Y-m-d H:i', $next) : null, 'next_ts' => $next];
}

/** Bir cədvəli icra et (protokol → missiya, yoxsa tək prompt). ['ok', 'text'] və ya ['error'] */
function schedule_fire(PDO $pdo, array $s): array
{
    $owner = (string)$s['owner'];
    $project = trim((string)$s['project']);
    if ((string)$s['protocol'] !== '') {
        $p = protocol_find($pdo, $owner, (string)$s['protocol']);
        if (!$p) {
            return ['error' => "protokol tapılmadı: {$s['protocol']}"];
        }
        [$steps, $err] = protocol_expand($pdo, $owner, json_decode((string)$p['steps'], true) ?: [], $project);
        if ($err) {
            return ['error' => $err];
        }
        $mid = mission_create($pdo, $owner, mb_substr('⏰ ' . $p['name'] . ($project !== '' ? " · $project" : ''), 0, 200), (string)$p['descr'], $steps);
        $r = mission_advance($pdo, $mid, true);
        $m = $r['mission'] ?: [];
        if (($m['status'] ?? '') === 'failed') {
            return ['error' => 'missiya #' . $mid . ' başlamadı: ' . implode('; ', array_unique($r['errors'])), 'mission_id' => $mid];
        }
        return ['ok' => true, 'mission_id' => $mid, 'text' => "{$p['name']} başladı (missiya #$mid, " . count($r['launched']) . ' addım növbədə)' . ($r['errors'] ? ', xətalar: ' . implode('; ', array_unique($r['errors'])) : '')];
    }
    $r = queue_agent_command($pdo, $owner, $project, (string)$s['prompt'], ['fresh' => 1]);
    return !empty($r['ok']) ? ['ok' => true, 'command_id' => $r['id'], 'text' => "Claude Code tapşırığı növbədə (#{$r['id']}, $project)"] : ['error' => (string)$r['error']];
}

/** Vaxtı çatmış cədvəlləri işə sal (idempotent, yarışa davamlı: last_run CAS ilə əvvəl işarələnir). İcra olunanların siyahısını qaytarır. Heç vaxt atmır. */
function schedules_due(PDO $pdo, int $now = 0): array
{
    $now = $now ?: time();
    $out = [];
    try {
        $rows = $pdo->query('SELECT * FROM schedules WHERE enabled = 1')->fetchAll();
    } catch (Throwable $e) {
        return [];                              // cədvəl hələ yoxdur
    }
    foreach ($rows as $s) {
        $r = schedule_parse_rule((string)$s['rule']);
        $occ = $r ? schedule_occurrence($r, $now, -1) : 0;
        $old = (int)$s['last_run'];
        if (!$occ || $old >= $occ) {
            continue;
        }
        try {
            $up = $pdo->prepare('UPDATE schedules SET last_run = ? WHERE id = ? AND last_run = ?');
            $up->execute([$now, $s['id'], $old]);
            if ($up->rowCount() !== 1) {
                continue;                       // başqa sorğu qabaqladı
            }
            $res = $now - $occ > SCHEDULE_GRACE
                ? ['error' => 'buraxıldı: vaxtında yoxlanmadı (' . date('Y-m-d H:i', $occ) . '), növbəti dəfə işə düşəcək', 'missed' => true]
                : schedule_fire($pdo, $s);
        } catch (Throwable $e) {
            error_log('schedules_due #' . $s['id'] . ': ' . $e->getMessage());
            $res = ['error' => 'server xətası'];
        }
        $text = '⏰ Cədvəl «' . $s['name'] . '»: ' . (!empty($res['ok']) ? $res['text'] : 'alınmadı — ' . $res['error']);
        if (function_exists('emit_event')) {
            emit_event($pdo, 'jarvis.notice', (string)$s['owner'], (string)$s['project'], (int)$s['id'],
                ['text' => $text, 'level' => !empty($res['ok']) ? 'info' : 'error', 'schedule_id' => (int)$s['id']]
                + array_intersect_key($res, array_flip(['mission_id', 'command_id'])));
        }
        $out[] = ['id' => (int)$s['id'], 'owner' => $s['owner'], 'ok' => !empty($res['ok']), 'text' => $text] + array_intersect_key($res, array_flip(['mission_id', 'command_id']));
    }
    return $out;
}
