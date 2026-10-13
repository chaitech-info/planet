# Komanda Claude dashboard

Hər kəs öz kompüterində öz hesabı ilə Claude Code işlədir. Hook-lar hadisələri `ingest.php`-yə göndərir, `index.php` isə canlı göstərir.

## 1. Hostinger-ə yüklə
`public_html/` içindəkiləri saytın qovluğuna yüklə (File Manager və ya FTP). `data/` qovluğu yazıla bilən olmalıdır (SQLite faylı orada yaranır).
`config.php`-də istifadəçi adlarını dəyiş, tokenləri və dashboard parolunu oxu. Hər kəsə yalnız öz tokenini ver.

## 2. Hər kəs öz kompüterində
```bash
./hook/install.sh https://SAYTIN.com/ingest.php SENIN_TOKENIN
```
Sonra Claude Code-u yenidən başlat.

## Qeydlər
- HTTPS istifadə et (Hostingerdə SSL-i aktiv et), yoxsa token açıq gedir.
- Dashboard prompt mətnini və əmr/fayl adlarını göstərir. Bash əmrinə parol yazma.
- MySQL istəsən `config.php`-də `db` hissəsini dəyiş.
- Ləğv etmək: `~/.claude/settings.json`-dan `claude-team-hook.sh` sətirlərini sil.
