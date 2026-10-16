# Komanda Claude: başlanğıc təlimatı

Salam, **{{USER}}**! Bu paket səni komandanın canlı panelinə qoşur. Təxminən 10 dəqiqə çəkir.

Panel: **{{URL}}**

## Bu nədir?

- Hər kəs öz kompüterində, **öz Claude hesabı ilə** işləyir. Heç kimin Claude girişi saytda saxlanmır.
- Kompüterindəki kiçik "hook" skripti Claude Code-da nə etdiyini (prompt, fayl, bitmə) panelə göndərir. Panelin "Kainat" bölməsində hər proyekt bir planetdir, hər istifadəçi isə onun ayıdır.
- Sağ yuxarıdakı **✉ Mesaj** düyməsi ilə komanda yoldaşına və ya hamıya mesaj yaza bilərsən. Kainatda sənin planetindən alanın planetinə **raket** uçur, partlayır və planet bir müddət zədəli qalır.
- **Hesab** düyməsində öz **MP3 musiqini** yükləyə bilərsən: sən işləyəndə planetdə çalır.
- Kainatda daima uçan **🛸 Plan gəmisinə** (və ya başlıqdakı **Plan** düyməsinə) bas: hər proyekt üçün planlar, scope-lar və tapşırıqlar görünür, hər kəs yenisini əlavə edə bilər.
- İstəsən saytdan öz kompüterinə **prompt göndərə** bilərsən. Prompt yalnız **sənin** kompüterində, sənin Claude hesabınla işləyir (aşağıda "Agent").

## Hazırlıq (bir dəfə)

1. **Mac (macOS)** və **Python 3** (`python3 --version`). Launcher düyməsi yalnız Mac-də işləyir. Windows/Linux-da agentı terminaldan əl ilə başlatmaq olar.
2. **Claude Code CLI** quraşdırılıb olmalıdır və hesabla giriş edilməlidir:
   ```bash
   claude auth status        # "loggedIn": true görünməlidir
   ```
   Giriş yoxdursa: `claude` yaz, `/login` et. Əgər `claude` əmri tapılmırsa, tam yolla yoxla: `~/.local/bin/claude`.
3. Bu paketi arxivdən çıxar və terminalda onun içinə keç:
   ```bash
   cd claude-team-kit
   ```

## Addım 1. Sayta daxil ol

{{URL}} ünvanında sənə verilən istifadəçi adı (`{{USER}}`) və parol ilə daxil ol. Parolu sağ yuxarıdakı **Hesab** düyməsindən dəyişə bilərsən. **Tokenin** isə admin sənə ayrıca verib. O, saytda deyil, kompüterində lazımdır.

## Addım 2. Proyektləri panelə qoş

Panelə görünməsini istədiyin **hər proyekt qovluğu** üçün bir dəfə:

**Bu əmri Terminalda işlət, brauzerə yapışdırma.** (`ingest.php` sayt səhifəsi deyil, hook-ların məlumat göndərdiyi texniki ünvandır. Brauzerdə açsan "POST lazımdır" yazar, bu normaldır.)

```bash
URL='{{URL}}ingest.php'
TOKEN='SƏNƏ_VERİLƏN_TOKEN'
bash hook/install.sh "$URL" "$TOKEN" /yol/proyektin/qovlugu
```

- Hook yalnız həmin proyektdə işləyir (qovluqdakı `.claude/settings.local.json`, git-ə düşmür).
- Bundan sonra həmin qovluqda **Claude Code-u yenidən aç** və bir şey yaz. Bir neçə saniyə sonra planetin panelde görünür.
- Əmri hər proyekt üçün təkrarla. Proyekt adı qovluğun adıdır.

## Addım 3. Saytdan prompt göndərmək istəyirsənsə (agent)

Bir dəfə launcher quraşdır (Mac):

```bash
bash agent/install-launcher.sh
```

Sonra paneldə planeti aç → **Göndər** tabı → agent oflayndırsa **"▶ Agenti işə sal"** düyməsinə bas. İlk dəfə brauzer "ClaudeTeamAgent açılsın?" soruşacaq, icazə ver. Başlıqda **"agent onlayn"** görünəndə prompt yaza bilərsən.

- İlk dəfə **tokenini** prompt qutusundakı sahəyə daxil et. O, yalnız bu brauzerdə qalır.
- Prompt **Enter** ilə gedir (Shift+Enter yeni sətir). Prompta şəkil də əlavə edə bilərsən: "🖼 Şəkil" düyməsi, yapışdırma və ya sürüşdürmə (ən çox 4, hər biri 5 MB-a qədər); agent onları Claude-a göstərir.
- Düymə işləmirsə agentı əl ilə başlat: `python3 agent/claude-team-agent.py` (pəncərə açıq qalsın).
- Agent yalnız hook quraşdırdığın proyektlərə prompt qəbul edir.
- Claude girişi yoxdursa, panel "Claude girişi yoxdur" xəbərdarlığı göstərir və promptlar növbədə gözləyir.

**Agentın səlahiyyəti qəsdən məhduddur:** fayl oxuya və redaktə edə bilər. `git` üçün standart səviyyə **safe**-dir: oxuma əmrləri (`status`, `log`, `diff`, `show`, `branch`) + `fetch`, `pull` (həmişə yalnız sürətli irəli, birləşmə commit-i yaranmır), `add`, `commit`, `switch`, `stash`. `push`, `merge`, `checkout` üçün səviyyəni **full** et: `~/.claude-team-agent.env` faylına `CLAUDE_TEAM_GIT=full` yaz (yalnız oxuma üçün `read`). Zorla push (`--force`), `reset --hard`, `clean` hər halda **bloklanıb**; fayl silmə və digər terminal əmrləri standart (`basic`) profildə bloklanıb (aşağıda "Profil"). Bu seçimi yalnız kompüterin sahibi edir.

**Canlı gedişat:** agent işləyərkən saytda Claude-un hazırkı addımı (məs. `Edit · app/scoring.py`), son toxunduğu fayllar və təxmini faiz görünür. Köhnə Claude CLI bunu bacarmırsa agent özü köhnə üsula keçir; söndürmək: `CLAUDE_TEAM_STREAM=0`.

**Paralel işlər (istəyə görə):** standartda agent eyni anda **bir** tapşırıq işlədir. `~/.claude-team-agent.env` faylına `CLAUDE_TEAM_WORKERS=2` (ən çox `4`) yazsan, o qədər tapşırığı paralel götürür. Eyni proyektə eyni anda ikinci tapşırıq gələrsə, o, proyektin ayrıca **git worktree** nüsxəsində (`~/.claude-team-worktrees/`) işləyir ki, iki Claude eyni faylları qarışdırmasın:
- Claude commit edibsə, commit-lər `jarvis/<nömrə>` budağında qalır (sən baxıb birləşdirirsən).
- Commit olunmamış dəyişiklik qalıbsa, worktree silinmir, yolu nəticədə yazılır. Baxandan sonra: `git worktree remove <yol>`.
- Dəyişiklik yoxdursa worktree avtomatik silinir.
- Proyekt git deyilsə və ya git səviyyəsi `read`-dirsə, ikinci tapşırıq birincinin bitməsini gözləyir.
- Worktree-də git-ə düşməyən fayllar (`.env`, `node_modules`) olmur və hook işləmir; belə işlər əvvəlki söhbəti davam etdirmir.

**Profil (istəyə görə, yalnız kompüterin sahibi seçir):** standartda agent yuxarıdakı kimi məhduddur (`basic`). Claude-un test işlətməsini, build/deploy etməsini istəsən `~/.claude-team-agent.env` faylına bir sətir yaz və agenti yenidən başlat:

| `CLAUDE_TEAM_PROFILE=` | Nə əlavə olunur | Git səviyyəsi |
|---|---|---|
| `basic` (standart) | Heç nə: fayl redaktəsi + git | `safe` |
| `dev` | Terminal: `npm`/`pnpm`/`yarn`/`bun`, `node`, `npx`, `python`/`pytest`/`pip`, `cargo`, `go`, `make`, `php`, `composer`, `ls`/`cat`/`grep`/`find`/`head`/`tail`/`wc`/`jq`, `curl` (yalnız GET) + veb axtarış. İşdən sonra testləri/lint-i özü yoxlayır | ən azı `safe` |
| `ops` | `dev` + `gh`, `vercel`, repodakı deploy skriptləri, bütün MCP serverləri | `full` |
| `full` | Bütün terminal əmrləri (aşağıdakı qadağalardan başqa) | `full` |

- `CLAUDE_TEAM_GIT` ayrıca yazılıbsa, profil onu dəyişmir (məs. `ops` + `CLAUDE_TEAM_GIT=safe`).
- **Həmişə qadağandır** (`dev`/`ops`/`full`): zorla push, `reset --hard`, `clean`, `branch -D`, proyektdən kənar `rm -rf`, `sudo`, `.env*`, `*.pem`, SSH açarları və Claude giriş fayllarını oxumaq, `curl … | sh`.
- **Yoxlama** (`dev`+): Claude faylları dəyişibsə və proyektdə test/lint varsa (`package.json`, `pyproject.toml`, `Makefile`…), eyni söhbətdə onları işlədir və nəticənin sonuna `— Yoxlama: ✅ …` / `❌ …` yazır. Söndürmək: `CLAUDE_TEAM_SELFCHECK=0`.
- **Geri qaytarma:** hər redaktə edən tapşırıqdan əvvəl agent proyektin vəziyyətini (git HEAD + commit olunmamış dəyişikliklər) `~/.claude-team-snapshots.json`-a qeyd edir; Jarvis-in "son işi geri al" əmri onu bərpa edir. Proyekt git deyilsə snapşot olmur.

**Sürət:**
- Agent saytdan "gözləmə" rejimində soruşur: prompt göndərən kimi (yarım saniyədən tez) işə düşür.
- **İsti sessiyalar:** agent son istifadə olunan proyektlər üçün Claude-u açıq saxlayır (ən çox 3, 10 dəqiqə boş qalanda bağlanır), növbəti prompt Claude-un yenidən açılmasını gözləmir. `dev`/`ops`/`full`-da standart açıqdır. `basic`-də açmaq üçün `CLAUDE_TEAM_WARM=1`, söndürmək üçün `CLAUDE_TEAM_WARM=0` yaz. Hər hansı xətada agent özü köhnə üsula keçir.

**Başqa iş növləri** (Jarvis göndərir):
- **Düşüncə (`think`):** Claude yalnız oxuyur və düşünür (opus, plan rejimi). Heç nəyi dəyişmir, söhbət saxlanmır, cavab nəticədə görünür.
- **Əməliyyat (`ops`):** Claude promptu deyil. Agentin yanındakı `agent/claude_ops.py` kompüterdə yoxlama aparır (Claude versiyası, MCP, istifadə, proyektin git/CI vəziyyəti, PR-lar…). Riskli əməliyyatlar (PR birləşdirmə, MCP əlavə etmə və s.) yalnız profil icazə verəndə işləyir. `claude_ops.py` yoxdursa paketi yenidən endir.
- Tapşırıqda model (`opus`/`sonnet`/`haiku`) və ya **plan rejimi** seçilə bilər; agent onu yalnız həmin tapşırıq üçün tətbiq edir.

## Tapşırıq lövhəsi

Panelin **Lövhə** tabında komandanın tapşırıqları var (Gözləyir → İşləyir → Yoxlamada → Bitdi). Admin sənə tapşırıq təyin edəndə **Lövhə** tabının yanında rəqəm görünür. Sən özün də özünə tapşırıq yarada bilərsən.

- **Avtomatik lövhə:** sənə təyin olunan tapşırıq (proyekt kompüterində qurulubsa) dərhal **öz agentinə** gedir, agent bitirəndə tapşırıq "Yoxlamada"ya düşür, alınmasa "Gözləyir"ə qayıdır. Agent oflayndırsa tapşırıq növbədə gözləyir. Söndürmək: Agent Universe-də öz agent planetin → "Lövhə avtomatikdir" → Söndür.
- Tapşırıq kartındakı **"▶ Claude ilə"** düyməsi tapşırığı əl ilə **öz agentinə** göndərir (avtomatik getməyibsə və ya yenidən göndərmək üçün; token bir dəfə daxil edilir).
- Claude işi bitirəndə tapşırıq özü **"Yoxlamada"** sütununa keçir və nəticə kartda görünür. Nəticəyə baxıb **"Bitdi"** düyməsini sən basırsan.
- Başqasının tapşırığını görə bilərsən, amma onu yalnız tapşırığın sahibi, təyin edən və admin hərəkət etdirə bilər.

## Təhlükəsizlik qaydaları

- **Tokenini və parolunu heç kimlə paylaşma** və söhbətə/skrinşota yazma. Token saytdan sənin kompüterində kod işlətməyə imkan verir.
- Sızdığından şübhələnirsənsə, adminə de, tokenini yeniləyəcək.
- Panel prompt mətnlərini, fayl adlarını və əmrləri komandaya göstərir. Parol və açar yazma.
- Agentı yalnız işləyəndə aç. Dayandırmaq üçün: `pkill -f claude-team-agent.py` (və ya Ctrl+C). Agent yeni tapşırıq götürmür, işləyənləri 30 saniyə gözləyir, bitməyənləri dayandırıb saytda "alınmadı" kimi qeyd edir (yenidən göndərmək olar). İkinci Ctrl+C dərhal dayandırır.

## Problem olarsa

| Əlamət | Səbəb / həll |
|---|---|
| Planet görünmür | Proyektdə Claude Code-u **yenidən aç** və bir şey yaz. `~/.claude-team-dash.conf` faylının olduğunu yoxla. |
| "agent oflayn" | "▶ Agenti işə sal" düyməsinə bas, ya da `python3 agent/claude-team-agent.py`. Jurnal: `~/.claude-team-agent.log` |
| "Claude girişi yoxdur" | Terminalda `claude` → `/login` |
| Düymə heç nə etmir | `bash agent/install-launcher.sh` işlət (bir dəfə) |
| "token yanlışdır" | Tokeni yenidən daxil et. Köhnəlibsə adminə de. |

## Çıxarmaq istəsən

- Hook-u proyektdən: həmin qovluqdakı `.claude/settings.local.json` faylından `claude-team-hook.sh` olan hissələri sil.
- Launcher: `rm -rf ~/Applications/ClaudeTeamAgent.app`.
- Konfiqurasiya: `rm ~/.claude-team-dash.conf ~/.claude-team-projects`.
