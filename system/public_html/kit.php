<?php
// Quraşdırma paketi: daxil olmuş istifadəçi üçün zip (hook + agent + launcher + BASLA.md)
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null) {
    http_response_code(401);
    exit('giriş lazımdır');
}
$dir = __DIR__ . '/kit';
if (!class_exists('ZipArchive') || !is_dir($dir)) {
    http_response_code(500);
    exit('Paket hazır deyil');
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$tpl = @file_get_contents($dir . '/BASLA.template.md') ?: "Təlimat tapılmadı.\n";
$guide = str_replace(['{{URL}}', '{{USER}}'], [$base . '/', $me['name']], $tpl);
if (isset($_GET['doc'])) {          // təlimatı birbaşa brauzerdə oxumaq üçün
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $guide;
    exit;
}
$tmp = tempnam(sys_get_temp_dir(), 'kit');
$z = new ZipArchive();
$z->open($tmp, ZipArchive::OVERWRITE);
$root = 'claude-team-kit/';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
    if ($rel === '.htaccess' || $rel === 'BASLA.template.md') {
        continue;
    }
    $z->addFile($f->getPathname(), $root . $rel);
    if (preg_match('/\.(sh|py)$/', $rel)) {
        $z->setExternalAttributesName($root . $rel, ZipArchive::OPSYS_UNIX, 0100755 << 16);
    }
}
$z->addFromString($root . 'BASLA.md', $guide);
$z->close();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="claude-team-kit.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
