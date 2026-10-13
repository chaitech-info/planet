<?php
// Cron üçün: hər axşam bugünkü hesabatı Telegram-a göndərir. Yalnız komanda sətri (CLI) ilə işləyir.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/db.php';
[$s, $e] = report_window('today');
$text = report_text(report_data(db(), $s, $e), 'Gündəlik hesabat · ' . date('d.m.Y', $s));
echo $text . "\n";
echo notify($text) ? "Telegram-a göndərildi\n" : "Göndərilmədi (Telegram ayarlanmayıb və ya xəta)\n";
