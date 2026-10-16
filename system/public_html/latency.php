<?php
// Gecikmə ölçüləri (Jarvis Ultra §1): {action:'mark', kind, ref, said_at, start_at, first_at, done_at, meta} və {action:'stats'}.
// Vaxtlar ms (Date.now()). stats: son 24 saatda növ üzrə p50/p90 (said→start, said→first, said→done); üzv özününkünü, admin hamınınkını görür.
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
session_write_close();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') {
    json_out(['error' => 'yanlış sorğu'], 400);
}
$in = json_decode(file_get_contents('php://input', false, null, 0, 8192) ?: '', true);
if (!is_array($in)) {
    $in = [];
}
$pdo = db();
$now = time();
$act = (string)($in['action'] ?? '');

if ($act === 'mark') {
    $kind = (string)($in['kind'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9_.:-]{0,39}$/', $kind)) {
        json_out(['error' => 'kind yanlışdır'], 400);
    }
    $nowMs = (int)floor(microtime(true) * 1000);
    $ms = function (string $k) use ($in, $nowMs): int {      // ±1 gün xaricindəki dəyərlər 0 (saat fərqi / zibil)
        $v = $in[$k] ?? 0;
        $v = is_numeric($v) ? (int)$v : 0;
        return $v > 0 && abs($v - $nowMs) < 86400000 ? $v : 0;
    };
    $t = ['said_at' => $ms('said_at'), 'start_at' => $ms('start_at'), 'first_at' => $ms('first_at'), 'done_at' => $ms('done_at')];
    if (!array_filter($t)) {
        json_out(['error' => 'ən azı bir vaxt lazımdır'], 400);
    }
    $meta = is_array($in['meta'] ?? null) ? json_encode($in['meta'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
    if (strlen((string)$meta) > 2000) {
        $meta = '';
    }
    $pdo->prepare('INSERT INTO jarvis_latency (user, kind, ref, said_at, start_at, first_at, done_at, meta, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$me['name'], $kind, max(0, (int)($in['ref'] ?? 0)), $t['said_at'], $t['start_at'], $t['first_at'], $t['done_at'], (string)$meta, $now]);
    if (random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM jarvis_latency WHERE created_at < ?')->execute([$now - 14 * 86400]);
    }
    json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($act === 'stats') {
    $all = $me['role'] === 'admin' && !empty($in['all']);
    $hours = max(1, min(168, (int)($in['hours'] ?? 24)));
    $sql = 'SELECT user, kind, said_at, start_at, first_at, done_at FROM jarvis_latency WHERE created_at > ?' . ($all ? '' : ' AND user = ?') . ' ORDER BY id DESC LIMIT 5000';
    $st = $pdo->prepare($sql);
    $st->execute($all ? [$now - $hours * 3600] : [$now - $hours * 3600, $me['name']]);
    $g = [];
    foreach ($st->fetchAll() as $r) {
        $s = (int)$r['said_at'];
        $k = $r['kind'];
        $g[$k]['n'] = ($g[$k]['n'] ?? 0) + 1;
        foreach (['start' => 'start_at', 'first' => 'first_at', 'done' => 'done_at'] as $name => $col) {
            $v = (int)$r[$col];
            if ($s > 0 && $v >= $s) {
                $g[$k][$name][] = $v - $s;
            }
        }
    }
    $pct = function (array $a, float $p): int {
        sort($a);
        return (int)$a[(int)min(count($a) - 1, floor($p * (count($a) - 1) + 0.5))];
    };
    $out = [];
    foreach ($g as $k => $x) {
        $row = ['kind' => $k, 'count' => $x['n']];
        foreach (['start', 'first', 'done'] as $name) {
            if (!empty($x[$name])) {
                $row[$name] = ['p50' => $pct($x[$name], .5), 'p90' => $pct($x[$name], .9), 'n' => count($x[$name])];
            }
        }
        $out[] = $row;
    }
    usort($out, fn($a, $b) => $b['count'] <=> $a['count']);
    json_out(['ok' => true, 'hours' => $hours, 'all' => $all, 'kinds' => $out]);
}

json_out(['error' => 'naməlum əməliyyat'], 400);
