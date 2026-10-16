# Layihə qeydləri

## Git
- Repo: https://github.com/chaitech-info/planet (default branch: `main`)
- `/Users/user/system` lokal qovluğu git repo DEYİL (`.git` yoxdur). Dəyişiklik/pull lazım olanda repo ayrıca klonlanır (məs. scratchpad-ə) və fayllar `public_html/` ilə müqayisə/birləşdirilir.
- "main-i pull et" deyiləndə: repo `main`-i çək, lokal `system/` ilə fərqləri birləşdir, konflikt olsa həll et, sonra deploy.

## Deploy
- `bash deploy-prompt.sh` (qovluq: `/Users/user/system`) saytı `development.chaitech.info` serverinə SSH ilə yükləyir. Sonda `DEPLOYED` çıxmalıdır.
- Skript serverdəki faylları əvəz edir, ona görə yalnız istifadəçi deploy deyəndə işlət. Deploydan əvvəl git `main` ilə sinxron olduğuna əmin ol.
- Yeni `.php` faylı əlavə olunsa, skriptdəki `PHPFILES` siyahısına da yazılmalıdır.

## Səs
- Saytda kimsə onlayn olanda hamıya səs çalır: `media/online.mp3`, məntiq `index.php`-də `fireJoinSound()`, student üçün `feed.php`-dən adsız `online_n` sayı gəlir.
