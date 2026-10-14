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
- **Şəkilli prompt** (planet → Göndər tabı): "🖼 Şəkil" düyməsi, yapışdırma (Cmd/Ctrl+V) və ya sürüşdürmə ilə prompta 4-ə qədər şəkil (PNG/JPEG/GIF/WebP, 5 MB) əlavə olunur; böyük şəkillər brauzerdə kiçildilir. Şəkillər `data/cmdimg/` içində saxlanır, yalnız `cmdimg.php` verir (giriş və ya token), 7 gündən sonra silinir. Agent onları müvəqqəti qovluğa endirib Claude-a `--add-dir` ilə göstərir. Prompt **Enter** ilə gedir, Shift+Enter yeni sətirdir.
- **Test Planet** (🧪): planetə gir, test ediləcək proyekti seç. Proyekt üzrə test ssenariləri (keçdi / xəta + qeyd), "Yeni raund" ilə sıfırlama və Claude agentinə test promptu göndərmək. Xəta varsa Test Planet qırmızı yanır, proyektin planetində "🧪 N xəta" görünür.

## Kosmik xəritə və universlər
Kainat açılanda bütün universlər bir kosmik xəritədə qalaktika kimi görünür. Keçid üçün düymə yoxdur: sol düymə ilə sürükləyəndə məkan bütün istiqamətlərdə sərbəst 360° fırlanır, sağ düymə ilə sürüşdürürsən, siçan təkəri ilə kursorun altındakı yerə yaxınlaşıb-uzaqlaşırsan (toxunma ekranında: bir barmaq fırladır, iki barmaq zoom və sürüşdürmə). Qalaktikaya klik etmək onun içinə axıcı uçuşdur; 🗺 düyməsi bütün xəritəyə qaytarır.
Yaxınlaşdıqca görünüş pillə-pillə dəyişir: uzaqdan qalaktika və onun adı → planetlər, orbitlər və günəş → aylar, MCP peykləri, gəmilər və tapşırıq balonları. Səma yaxınlaşılan universin rənginə keçir; uzaq səyahətdə ulduz xətləri və geniş baxış bucağı sürət hissi verir.
- **🌌 Komanda**: proyektlər və istifadəçi planetləri.
- **🎨 Studio**: Prompt Generator, Designer, Meeting Planet, Test Planet.
- **🛰 MCP Universe**: hər MCP server bir planetdir (ayları onu işlədən proyektlərdir); paneldə proyektlər və son çağırışlar.
- **🤖 Agent Universe**: hər istifadəçinin AI agenti bir planetdir (onlayn/işləyir/növbə); yeni tapşırıq agentə raketlə çatır (başqası təyin edibsə, onun agentindən), paneldə avtomatlaşdırma axını.
- **Avtomatik lövhə** (agentlər lövhəni özləri işləyir): tapşırıq yaradılanda və ya başqasına verilən kimi icraçının agenti onu götürür, şərtlər: agent heç olmasa bir dəfə qoşulub, `auto_tasks` açıqdır (agent planetində "Lövhə avtomatikdir" → Aç/Söndür), proyekt agentin bildirdiyi qovluqlardandır, növbədə 5-dən az iş var. Tapşırıq "İşləyir"ə keçir, agent bitirəndə "Yoxlamada"ya, alınmasa "Gözləyir"ə qayıdır (kartda xəta görünür, "▶ Claude ilə" ilə yenidən göndərilir). Yeni tapşırıq pəncərəsi avtomatik gedib-getməyəcəyini əvvəlcədən yazır. Jarvis ilə yaradılan tapşırıqlar da eyni yolla gedir.
- **Avtomatik yoxlama** (`commands.kind = review`): icraçının agenti işi bitirəndə server yoxlayıcı seçir: proyekti olan, avtomatik rejimi açıq, onlayn və icraçıdan fərqli agent (növbəsi ən boş olan); yoxdursa icraçının öz agenti təzə söhbətdə. Yoxlayıcı hesabata inanmır, `git diff`/fayllarla özü yoxlayır, fayl dəyişmir, cavabın ilk sətri `VERDICT: PASS` / `FAIL` olur (başqa kompüterdədirsə əvvəl `git pull` edir, dəyişiklik push olunmayıbsa `UNSYNCED` → icraçının öz agenti yoxlayır). PASS → "Bitdi"; FAIL → iradlar icraçıya düzəliş promptu kimi gedir, tapşırıq "İşləyir"ə qayıdır (`tasks.rounds`, ən çox 2 dövrə); limit dolsa, nəticə tapılmasa və ya yoxlayıcı xəta versə tapşırıq "Yoxlamada" qalır və insan baxır (`tasks.verdict`). İnsan "Yoxlamaya ver" edəndə də agent yoxlayır. Lövhənin üstündəki axın zolağı hansı keçidin indi getdiyini göstərir; kartlar sütunlar arasında uçur, "Bitdi"yə çatanda konfeti.
- **🎓 Student Universe**: admin "İstifadəçilər" pəncərəsində yeni istifadəçi yaradanda tipi seçir (Üzv / Student). Hər studentə avtomatik öz planeti yaranır; "🪐 Planetim" ilə onu fərdiləşdirir (ad, səth, rəng, ölçü, halqa), silə və yeni planet yarada bilməz. Student yalnız Student universini görür: komanda universləri, panel, lövhə, tapşırıq, plan, mesaj, hesabat, agent və Jarvis ona həm interfeysdə, həm serverdə bağlıdır; studentin tokeni hook/agent üçün işləmir.
- **🖥 Desktop Universe**: hər istifadəçinin kompüteri bir planetdir. Sahib "+ İcazə ver" ilə kimə uzaqdan komanda göndərmək icazəsi verdiyini seçir (✋ təsdiqlə və ya ⚡ avtomatik; istənilən vaxt geri alır). İcazəsi olan adam planetə girib söhbət kimi yazır: vəziyyət zolağı (kompüter onlayn/oflayn, Claude girişi, icazə), köhnədən yeniyə komanda axını (göndərilən mətn sağda, Claude Code-un vəziyyəti və nəticəsi solda), aşağıda yapışqan yazı qutusu: proyekt çipləri, böyüyən sahə, 🖼 şəkil, 🆕 yeni söhbət, Enter göndərir. Komanda sahibin öz Claude hesabı ilə, onun kompüterində, agentin məhdud icazələri ilə işləyir (`remote.php`).
Gəmilər hər biri öz trayektoriyası ilə uçur; sahibi işləyəndə öz proyektinin, boş vaxtda (agent onlayndırsa) öz agentinin ətrafında olur və arabir başqa universlərdəki planetlərə uçur.
URL-lər: `#kainat` (xəritə), `#kainat/@mcp` (universin ümumi görünüşü), `#kainat/<planet>` (planet).

## 📱 Mobil
Sayt telefon və planşetdə də işləyir (CSS-in sonundakı "Mobil" bloku): başlıqda alət zolağı tək sətirdə sürüşdürülür, tablar tam enə yayılır; statistika 2 sütun, kartlar 1 sütun; Kainat ekran hündürlüyünə görə (`100dvh`), planet paneli (dock) aşağıdan açılan vərəqdir, səyahət düymələri sürüşdürülür; lövhə sütunları alt-alta; modallar, Jarvis və Ratsiya aşağıdan açılan tam en vərəqlərdir; iOS-da sahələr 16px (fokusda yaxınlaşdırma olmasın), düymələr barmaq ölçüsündə, `safe-area` boşluqları nəzərə alınır.

## 📻 Ratsiya (bas-danış səs)
Başlıqdakı `📻 Ratsiya` düyməsi (və ya `Alt+R`) paneli açır və ratsiyanı yandırır (mikrofon icazəsi bir dəfə). Böyük düyməni (və ya Space-i) basılı saxlayıb danışırsan, buraxanda klip serverə gedir (`radio.php`, fayl `data/radio/`, 15 dəqiqə saxlanır). Kanal: hamıya və ya bir nəfərə. Ratsiyası açıq olan hər kəs klipi avtomatik eşidir: sorğu 2 saniyədən bir Web Worker-dən gedir, ona görə brauzer tabı arxada olanda da səs gəlir (icazə verilibsə bildiriş də çıxır). Səs radio tembrindədir (300–3400 Hz + yüngül təhrif), hər klipin əvvəlində rele kliki və xışıltı, sonunda xışıltı və iki tonlu "roger" siqnalı sintez olunur (polis ratsiyası kimi). Paneldə kimin eşitdiyi, son kliplər (▶ ilə yenidən çalmaq) və səs səviyyəsi görünür; panelin ✕-i ratsiyanı bağlamır, "⏻ Bağla" bağlayır. Yarım-dupleksdir: danışarkən gələn kliplər növbədə gözləyir. Ən çox 60 saniyə bir klip; HTTPS lazımdır.

## 🎙 Jarvis (səsli agent: Grok Voice / Gemini Live)
Real-time danışan köməkçi: sual verirsən ("kim nə edir?", "hansı planet yanır?"), kainatda gəzdirir ("MCP universinə uç"), əməllər edir (tapşırıq, status, deadline, görüş, test nəticəsi, mesaj raketi, agentə prompt). Dəyişiklik edən hər əmrdən əvvəl səslə təsdiq soruşur; silə bilmir.
1. `system/public_html/config.local.example.php` faylını `config.local.php` adı ilə kopyala, `fal_key` yaz (fal.ai → Keys). Bu fayl git-ə düşmür; serverdə də əl ilə yaradılmalıdır.
2. Səhifədə `🎙 Jarvis` düyməsi və ya `Alt+J`. Mikrofon yalnız HTTPS və ya localhost-da işləyir; qulaqcıqla daha yaxşıdır.
- Səs təminatçısı adapterdir (`jarvis.js` → `ADAPTERS`): **Grok Voice** (xAI, fal.ai üzərindən, ~$0.05/dəq) və **Gemini Live** (Google). Alətlər, panel və Claude Code inteqrasiyası hər ikisi üçün eynidir; adapter yalnız protokolu çevirir. Hazırda yalnız Grok aktivdir: Gemini-ni qaytarmaq üçün `jarvis.php`-də `$enabled` siyahısına `'gemini'` əlavə et (və `gemini_api_key` yaz). Hər ikisi aktiv olanda paneldə seçim çıxır, default `jarvis_provider`.
- Açar brauzerə getmir: `jarvis.php` qısa ömürlü token yaradır (fal: yalnız `grok-voice` üçün, 2 dəqiqə ərzində qoşulmaq; Gemini: bir dəfəlik), brauzer təminatçıya birbaşa WebSocket ilə qoşulur. fal çərçivələri msgpack-dir (kiçik kodlayıcı `jarvis.js`-in içindədir).
- Gündəlik limit: `jarvis_daily_minutes` (standart 60 dəq/istifadəçi). Səs (`grok_voice`: rex, leo, eve, ara, orion, …), model və dil də `config.local.php`-dən dəyişir.

