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

## Lokal işə salmaq (inkişaf üçün)
```bash
brew install php                      # bir dəfə
php system/dev/seed.php               # lokal SQLite bazasını nümunə məlumatla doldurur (bazanı sıfırlayır!)
php -S 127.0.0.1:8090 -t system/public_html
```
Sonra http://127.0.0.1:8090 aç. Giriş: `config.php`-dəki istifadəçilər (Askar admin, Dost) və Emil; parol `config.php`-dəki `dashboard_password`-dur.
Lokal Emil tokeni ilə hook hadisəsini əl ilə yoxlamaq: `curl -X POST http://127.0.0.1:8090/ingest.php -H 'X-Team-Token: local-dev-emil-token' -d '{"hook_event_name":"PostToolUse","session_id":"s1","cwd":"/x/flowcut","tool_name":"mcp__linear__create_issue"}'`

## Kainatın yeni imkanları
- **24 saat qaydası**: açıq tapşırığı olan planetdə 24 saat heç bir tapşırıq bitməsə planet tüstülənir (24s), yanır (~30s) və xaosa düşür (~51s+: titrəyir, partlayır). Bir tapşırığı bitirmək kifayətdir.
- **Fərdi planetlər** (🪐 Yeni planet): ad, səth, rəng, ölçü, halqa. Planetin adı proyekt kimi işləyir, tapşırıq və plan ona yazıla bilər.
- **MCP peykləri** (🛰): Claude Code-da `mcp__server__tool` çağırışları peyk kimi avtomatik görünür, son 10 dəqiqədə işlədilibsə yaşıl yanır; əl ilə də əlavə olunur (proyekt boş = günəşin ətrafında).
- **Fərdi gəmi və deadline** (🚀 Gəmim): model, rənglər, deadline + missiya. 24 saatdan az qalanda miqalka yanıb-sönür, keçəndə qırmızı çaxır. Gəmi sahibinin işlədiyi planetin ətrafında uçur.
- **Xatırlatma balonları**: tapşırığa son tarix verilə bilər; bir gündən az qalanda planetin (planet yoxdursa icraçının gəmisinin) üstündə balon çıxır.
- **Prompt Generator, Designer, Meeting Planet**: xüsusi planetlər. Prompt yaratma və kitabxana; dizayn layihələri və tapşırıqları; görüşlər, gündəm və müzakirə (görüş vaxtı iştirakçıların gəmiləri ora uçur).
- **Test Planet** (🧪): planetə gir, test ediləcək proyekti seç. Proyekt üzrə test ssenariləri (keçdi / xəta + qeyd), "Yeni raund" ilə sıfırlama və Claude agentinə test promptu göndərmək. Xəta varsa Test Planet qırmızı yanır, proyektin planetində "🧪 N xəta" görünür.

## Kosmik xəritə və universlər
Kainat açılanda bütün universlər bir kosmik xəritədə qalaktika kimi görünür. Keçid üçün düymə yoxdur: sol düymə ilə sürükləyəndə məkan bütün istiqamətlərdə sərbəst 360° fırlanır, sağ düymə ilə sürüşdürürsən, siçan təkəri ilə kursorun altındakı yerə yaxınlaşıb-uzaqlaşırsan (toxunma ekranında: bir barmaq fırladır, iki barmaq zoom və sürüşdürmə). Qalaktikaya klik etmək onun içinə axıcı uçuşdur; 🗺 düyməsi bütün xəritəyə qaytarır.
Yaxınlaşdıqca görünüş pillə-pillə dəyişir: uzaqdan qalaktika və onun adı → planetlər, orbitlər və günəş → aylar, MCP peykləri, gəmilər və tapşırıq balonları. Səma yaxınlaşılan universin rənginə keçir; uzaq səyahətdə ulduz xətləri və geniş baxış bucağı sürət hissi verir.
- **🌌 Komanda**: proyektlər və istifadəçi planetləri.
- **🎨 Studio**: Prompt Generator, Designer, Meeting Planet, Test Planet.
- **🛰 MCP Universe**: hər MCP server bir planetdir (ayları onu işlədən proyektlərdir); paneldə proyektlər və son çağırışlar.
- **🤖 Agent Universe**: hər istifadəçinin AI agenti bir planetdir (onlayn/işləyir/növbə); yeni tapşırıq agentə raketlə çatır (başqası təyin edibsə, onun agentindən), paneldə avtomatlaşdırma axını.
Gəmilər hər biri öz trayektoriyası ilə uçur; sahibi işləyəndə öz proyektinin, boş vaxtda (agent onlayndırsa) öz agentinin ətrafında olur və arabir başqa universlərdəki planetlərə uçur.
URL-lər: `#kainat` (xəritə), `#kainat/@mcp` (universin ümumi görünüşü), `#kainat/<planet>` (planet).

## 🎙 Jarvis (səsli agent, Gemini Live API)
Real-time danışan köməkçi: sual verirsən ("kim nə edir?", "hansı planet yanır?"), kainatda gəzdirir ("MCP universinə uç"), əməllər edir (tapşırıq, status, deadline, görüş, test nəticəsi, mesaj raketi, agentə prompt). Dəyişiklik edən hər əmrdən əvvəl səslə təsdiq soruşur; silə bilmir.
1. `system/public_html/config.local.example.php` faylını `config.local.php` adı ilə kopyala, `gemini_api_key` yaz (Google AI Studio). Bu fayl git-ə düşmür; serverdə də əl ilə yaradılmalıdır.
2. Səhifədə `🎙 Jarvis` düyməsi və ya `Alt+J`. Mikrofon yalnız HTTPS və ya localhost-da işləyir; qulaqcıqla daha yaxşıdır.
- Açar brauzerə getmir: `jarvis.php` 30 dəqiqəlik bir dəfəlik token yaradır, brauzer Gemini-yə birbaşa WebSocket ilə qoşulur.
- Gündəlik limit: `jarvis_daily_minutes` (standart 60 dəq/istifadəçi). Model, səs və dil də `config.local.php`-dən dəyişir.

