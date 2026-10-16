<?php
// Cədvəllər: müntəzəm texniki xidmət (protokol missiyası və ya tək Claude Code promptu) sabit vaxtda. Brauzer (jarvis-projects.js) giriş sessiyası ilə.
// Qaydalar: "daily HH:MM" | "weekdays HH:MM" | "weekly mon..sun HH:MM" (server saat qurşağı). İcra: mission_lib.php schedules_due() (agent.php claim, feed.php).
// Hər kəs öz cədvəllərini görür; silmək/söndürmək: sahibi və ya admin (admin "all": true ilə hamısını görür).
require __DIR__ . '/db.php';
require_once __DIR__ . '/mission_lib.php';
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
$user = $me['name'];
$isAdmin = $me['role'] === 'admin';
const SCHEDULE_MAX = 20;

$str = function (string $k, int $max, string $label) use ($in): string {
    $v = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)($in[$k] ?? '')));
    if (mb_strlen($v) > $max) {
        json_out(['error' => "$label çox uzundur ($max simvola qədər)"], 400);
    }
    return $v;
};
/** id (və ya ad) üzrə cədvəl; sahibi deyilsə yalnız admin */
$pick = function () use ($in, $pdo, $user, $isAdmin): array {
    $id = (int)($in['id'] ?? 0);
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM schedules WHERE id = ?');
        $st->execute([$id]);
    } else {
        $st = $pdo->prepare('SELECT * FROM schedules WHERE owner = ? AND LOWER(name) = LOWER(?) ORDER BY id DESC LIMIT 1');
        $st->execute([$user, trim((string)($in['name'] ?? ''))]);
    }
    $s = $st->fetch();
    if (!$s) {
        json_out(['error' => 'cədvəl tapılmadı'], 404);
    }
    if ($s['owner'] !== $user && !$isAdmin) {
        json_out(['error' => 'bu cədvəli yalnız sahibi və admin dəyişə bilər'], 403);
    }
    return $s;
};

if ($act === 'add') {
    $name = $str('name', 120, 'ad');
    $rule = schedule_parse_rule($str('rule', 60, 'qayda'));
    $protocol = $str('protocol', 100, 'protokol');
    $prompt = $str('prompt', MISSION_MAX_PROMPT, 'prompt');
    $project = $str('project', 128, 'proyekt');
    if ($name === '') {
        json_out(['error' => 'ad lazımdır'], 400);
    }
    if (!$rule) {
        json_out(['error' => 'qayda yanlışdır: "daily HH:MM", "weekdays HH:MM" və ya "weekly mon..sun HH:MM"'], 400);
    }
    if (($protocol === '') === ($prompt === '')) {
        json_out(['error' => 'ya protocol, ya da prompt lazımdır (ikisi birdən yox)'], 400);
    }
    if ($protocol !== '') {
        $p = protocol_find($pdo, $user, $protocol);
        if (!$p) {                                  // "release and deploy" → "Release & Deploy Protocol": yalnız hərf/rəqəm müqayisəsi
            $sq = fn($x) => preg_replace('/(protocol|protokolu?|and|və|[^\p{L}\p{N}])/u', '', mb_strtolower($x));
            foreach (protocol_list($pdo, $user) as $c) {
                if ($sq($protocol) !== '' && str_contains($sq($c['name']), $sq($protocol))) {
                    $p = $c;
                    break;
                }
            }
        }
        if (!$p) {
            json_out(['error' => 'protokol tapılmadı', 'protocols' => array_column(protocol_list($pdo, $user), 'name')], 404);
        }
        $protocol = (string)$p['name'];
        [, $err] = protocol_expand($pdo, $user, json_decode((string)$p['steps'], true) ?: [], $project);   // indidən yoxla ("{project}" lazımdırmı və s.)
        if ($err) {
            json_out(['error' => $err], 400);
        }
        $chk = $project !== '' ? agent_check($pdo, $user, $project) : ['ok' => true];
        if (empty($chk['ok'])) {
            json_out(['error' => $chk['error']], 400);
        }
    } else {
        $chk = agent_check($pdo, $user, $project);
        if (empty($chk['ok'])) {
            json_out(['error' => $chk['error']], 400);
        }
    }
    $c = $pdo->prepare('SELECT COUNT(*) FROM schedules WHERE owner = ?');
    $c->execute([$user]);
    if ((int)$c->fetchColumn() >= SCHEDULE_MAX) {
        json_out(['error' => 'ən çox ' . SCHEDULE_MAX . ' cədvəl'], 429);
    }
    // last_run = indi: bu günün artıq keçmiş vaxtı dərhal işə düşməsin, növbəti baş verməni gözləsin
    $pdo->prepare('INSERT INTO schedules (owner, name, rule, protocol, project, prompt, enabled, last_run, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$user, $name, $rule['text'], $protocol, $project, $prompt, 1, $now, $now]);
    $st = $pdo->prepare('SELECT * FROM schedules WHERE id = ?');
    $st->execute([(int)$pdo->lastInsertId()]);
    json_out(['ok' => true, 'schedule' => schedule_view($st->fetch(), $now), 'timezone' => date_default_timezone_get()]);
}

if ($act === 'list') {
    $all = $isAdmin && !empty($in['all']);
    $st = $pdo->prepare('SELECT * FROM schedules' . ($all ? '' : ' WHERE owner = ?') . ' ORDER BY id ASC LIMIT 200');
    $st->execute($all ? [] : [$user]);
    json_out(['schedules' => array_map(fn($s) => schedule_view($s, $now), $st->fetchAll()), 'timezone' => date_default_timezone_get(), 'now' => date('Y-m-d H:i', $now)]);
}

if ($act === 'remove') {
    $s = $pick();
    $pdo->prepare('DELETE FROM schedules WHERE id = ?')->execute([$s['id']]);
    json_out(['ok' => true, 'removed' => schedule_view($s, $now)]);
}

if ($act === 'toggle') {
    $s = $pick();
    $on = !empty($in['enabled']) ? 1 : 0;
    // yenidən açanda buraxılmış vaxtlar sonradan işə düşməsin
    $pdo->prepare('UPDATE schedules SET enabled = ?' . ($on ? ', last_run = ?' : '') . ' WHERE id = ?')->execute($on ? [$on, $now, $s['id']] : [$on, $s['id']]);
    $s['enabled'] = $on;
    json_out(['ok' => true, 'schedule' => schedule_view($s, $now)]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
