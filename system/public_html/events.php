<?php
// Real-time hadisə şini (Jarvis Ultra §1): brauzerə SSE axını, alınmasa ?poll=1 long-poll JSON.
// Cədvəl: rt_events (emit_event() yazır, db.php). Hook hadisələrinin köhnə `events` cədvəli ilə qarışmır.
// Görünmə: student yalnız özününkü; üzv: user IN (me,'') + session.update (sessiyalar onsuz da paneldə hamıya görünür); admin: hamısı.
// config: 'realtime' => 'sse' (default) | 'poll' (yalnız long-poll) | 'off' (şin bağlı, panel köhnə 3 s ritmində).
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    json_out(['error' => 'giriş lazımdır'], 401);
}
session_write_close();                      // sessiya kilidi açıq qalsa paralel sorğular (feed, jarvis) gözləyər

$mode = (string)($config['realtime'] ?? 'sse');
if ($mode === 'off') {
    json_out(['off' => true]);
}
$pdo = db();
$poll = isset($_GET['poll']);
$since = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0) ?: (int)($_GET['since'] ?? 0);

/** Görünmə şərti + parametrlər */
$admin = $me['role'] === 'admin';
if ($admin) {
    $scope = '';
    $sp = [];
} elseif (is_student($me)) {
    $scope = ' AND user = ?';
    $sp = [$me['name']];
} else {
    $scope = " AND (user IN (?, '') OR type = 'session.update')";
    $sp = [$me['name']];
}
$maxQ = $pdo->prepare('SELECT MAX(id) FROM rt_events');       // PK üzrə: O(1), hər 300 ms ucuzdur
$rowsQ = $pdo->prepare("SELECT id, type, user, project, ref, data, created_at FROM rt_events WHERE id > ?$scope ORDER BY id ASC LIMIT 100");
$maxId = function () use ($maxQ): int {
    try {
        $maxQ->execute();
    } catch (Throwable $e) {                // "database is locked" (SQLite, ağır yazı anı): bu dövrəni ötür
        return -1;
    }
    $m = (int)$maxQ->fetchColumn();
    $maxQ->closeCursor();                   // SQLite: açıq kursor SHARED kilidi saxlayır → yazanlar (emit_event) ~250 ms gözləyir
    return $m;
};
$fetch = function (int $after) use ($rowsQ, $sp): ?array {   // null = oxuna bilmədi (kilid): mövqe dəyişmir, sonra yenidən
    try {
        $rowsQ->execute(array_merge([$after], $sp));
        $rows = $rowsQ->fetchAll();
        $rowsQ->closeCursor();
    } catch (Throwable $e) {
        return null;
    }
    $out = [];
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true);
        $out[] = ['id' => (int)$r['id'], 'type' => $r['type'], 'user' => $r['user'], 'project' => $r['project'], 'ref' => (int)$r['ref'], 'data' => is_array($d) ? $d : new stdClass(), 'created_at' => (int)$r['created_at']];
    }
    return $out;
};

for ($i = 0; ($top = $maxId()) < 0 && $i < 10; $i++) {
    usleep(200000);
}
if ($top < 0) {
    json_out(['error' => 'baza məşğuldur'], 503);   // klient (EventSource retry / long-poll backoff) yenidən cəhd edir
}
// since yoxdursa və ya cədvəldən irəlidirsə (təmizlənib): indidən başla; çox geridirsə son ~200-ü ver (köhnə tarix lazım deyil)
if ($since <= 0 || $since > $top) {
    $since = $top;
} elseif ($top - $since > 200) {
    $since = $top - 200;
}

/* ---------- Long-poll: {events:[...], last} ; hadisə gələn kimi qayıdır, yoxsa ≤ 25 s ---------- */
if ($poll || $mode === 'poll') {
    $wait = max(0, min(25, (int)($_GET['wait'] ?? 25)));
    if (!isset($_GET['since'])) {
        $wait = 0;                          // ilk sorğu: yalnız cari mövqeyi öyrən
    }
    @set_time_limit($wait + 15);
    $end = microtime(true) + $wait;
    $ev = [];
    $last = $since;
    do {
        if (($m = $maxId()) > $last) {
            $ev = $fetch($last);
            if ($ev) {
                $last = end($ev)['id'];
                break;
            }
            $last = $ev === null ? $last : $m;                    // görünməyən hadisələri keç: növbəti dəfə yenidən oxunmasın
        }
        if (microtime(true) >= $end) {
            break;
        }
        usleep(300000);
    } while (!connection_aborted());
    json_out(['events' => $ev ?: [], 'last' => $last, 'mode' => $mode]);
}

/* ---------- SSE ---------- */
@set_time_limit(75);
ignore_user_abort(false);
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level() > 0) {
    @ob_end_flush();
}
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, no-transform');
header('X-Accel-Buffering: no');           // nginx/LiteSpeed: buferləmə
header('Content-Encoding: none');          // bəzi paylaşılan hostinqlər gzip ilə axını yığır
$send = function (string $s): bool {
    echo $s;
    @flush();
    return !connection_aborted();
};
// 2 KB padding: proxy/hostinq ilk baytları buferləməsin; retry: qopanda 1.5 s sonra yenidən qoşul
$send(':' . str_repeat(' ', 2048) . "\nretry: 1500\n\n");
// hello: klient bununla axının buferlənmədiyini yoxlayır (5 s ərzində gəlməsə long-poll-a keçir)
$send('event: hello' . "\ndata: " . json_encode(['last' => $since, 'mode' => 'sse']) . "\n\n");

$start = microtime(true);
$hb = $start;
while (true) {
    if (($m = $maxId()) > $since) {
        $ev = $fetch($since);
        foreach ($ev ?? [] as $e) {
            $since = $e['id'];
            if (!$send('id: ' . $e['id'] . "\ndata: " . json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n")) {
                exit;
            }
        }
        if ($ev === []) {
            $since = $m;                    // yeni sətirlər var, amma bu istifadəçiyə aid deyil
            if (!$send('id: ' . $since . "\n\n")) {   // Last-Event-ID irəli getsin (yenidən qoşulanda boşuna oxumasın)
                exit;
            }
        }
    }
    $t = microtime(true);
    if ($t - $hb >= 15) {
        $hb = $t;
        if (!$send(": hb\n\n")) {
            exit;
        }
    }
    if ($t - $start >= 55) {
        exit;                               // PHP vaxt limitləri: klient retry ilə yenidən qoşulur
    }
    usleep(300000);
}
