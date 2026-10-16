<?php
// Deploy-dan sonra serverdə işləyən özünü yoxlama (yalnız CLI): ~/domains/.../public_html-də `php selftest.php`.
// Hər endpoint faylının yüklənməsində "Cannot redeclare" kimi icra xətası olub-olmadığını, Jarvis modullarının
// alət siyahısını və verilənlər bazasının açılmasını yoxlayır. Uğursuzluqda çıxış kodu 1.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__);
$fail = [];
$ok = fn(string $m) => print("   ok: $m\n");
$bad = function (string $m) use (&$fail) { $fail[] = $m; print("   XƏTA: $m\n"); };

// 1) Eyni anda yüklənən kitabxanalarda təkrar funksiya/sabit adı olmasın
$libs = ['db.php', 'mission_lib.php'];
$seen = [];
foreach ($libs as $f) {
    preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', (string)file_get_contents($f), $m);
    foreach ($m[1] as $fn) {
        if (isset($seen[strtolower($fn)])) {
            $bad("funksiya $fn() həm {$seen[strtolower($fn)]}, həm $f faylında var");
        }
        $seen[strtolower($fn)] = $f;
    }
}
$fail || $ok('funksiya adları təkrarlanmır (db.php + mission_lib.php)');

// 2) Əsas kitabxanalar yüklənir və baza açılır
try {
    require __DIR__ . '/db.php';
    require_once __DIR__ . '/mission_lib.php';
    $pdo = db();
    $ok('db.php + mission_lib.php yükləndi, baza açıldı');
} catch (Throwable $e) {
    $bad('kitabxana/baza: ' . $e->getMessage());
    exit(1);
}

// 3) Jarvis modulları: hər biri massiv qaytarır, alət adları təkrarlanmır, JSON-a çevrilir
define('JARVIS_MODULE', 1);
// memory.php jarvis.php-dəki memory_split()-i çağırır; selftest jarvis.php-ni yükləmir, ona görə yalnız test üçün boş əvəzedici
if (!function_exists('memory_split')) {
    function memory_split(PDO $pdo, string $user): array
    {
        return ['facts' => [], 'project' => [], 'summary' => []];
    }
}
$me = ['name' => '__selftest__', 'role' => 'admin'];
$names = [];
foreach (glob(__DIR__ . '/jarvis_modules/*.php') ?: [] as $mf) {
    try {
        $mod = require $mf;
    } catch (Throwable $e) {
        $bad(basename($mf) . ': ' . $e->getMessage());
        continue;
    }
    if (!is_array($mod) || !isset($mod['tools'])) {
        $bad(basename($mf) . ': modul massiv qaytarmır');
        continue;
    }
    foreach ($mod['tools'] as $t) {
        if (isset($names[$t['name']])) {
            $bad("alət {$t['name']} təkrarlanır ({$names[$t['name']]}, " . basename($mf) . ')');
        }
        $names[$t['name']] = basename($mf);
    }
    if (json_encode($mod['tools']) === false) {
        $bad(basename($mf) . ': alətlər JSON-a çevrilmir');
    }
}
$ok(count($names) . ' modul aləti yoxlandı');

// 4) Yaddaş cədvəli açılır və oxunur
try {
    memory_all($pdo, '__selftest__');
    $ok('jarvis_memory cədvəli işləyir');
} catch (Throwable $e) {
    $bad('jarvis_memory: ' . $e->getMessage());
}
echo $fail ? "\nSELFTEST FAILED (" . count($fail) . ")\n" : "\nSELFTEST OK\n";
exit($fail ? 1 : 0);
