#!/usr/bin/env python3
"""Komanda dashboard agenti.

Saytdakı növbədən YALNIZ SƏNİN tokenin üçün göndərilən promptları götürür və
həmin proyektin qovluğunda `claude -p` ilə işlədir. Yalnız ~/.claude-team-projects
siyahısındakı proyektlərə icazə verilir. Fayl redaktəsi və git əmrləri icazəlidir (səviyyəyə görə, aşağıya bax), qalan terminal əmrləri yox.
Claude Code rejimini (plan, manual, acceptEdits, auto) göndərən saytda seçir; sahib icazəli rejimləri CLAUDE_TEAM_MODES ilə məhdudlaşdıra bilər.
Prompta şəkil əlavə olunubsa, agent onları müvəqqəti qovluğa endirir və Claude-a yolunu verir (iş bitəndə silinir).

Git səviyyəsi (kompüterin sahibi seçir; CLAUDE_TEAM_GIT mühit dəyişəni və ya ~/.claude-team-agent.env faylında):
  read  : yalnız oxuma (status, log, diff, show, branch)
  safe  : (standart) + fetch, pull (yalnız sürətli irəli, birləşmə commit-i yox), add, commit, switch, stash
  full  : + push, merge, checkout. Zorla push (--force) və reset --hard, clean yenə də bloklanıb.

Paralel işçilər (istəyə görə; CLAUDE_TEAM_WORKERS mühit dəyişəni və ya ~/.claude-team-agent.env, standart 1, ən çox 4):
  N>1 olanda agent eyni anda N tapşırıq götürür. Eyni proyektin ikinci (və sonrakı) eyni vaxtlı tapşırığı ayrıca
  git worktree-də işləyir (`git worktree add --detach`, ~/.claude-team-worktrees/ içində). Proyekt git deyilsə və ya
  git səviyyəsi `read`-dirsə, birinci iş bitənə qədər gözləyir. İşdən sonra: commit varsa `jarvis/<id>` budağında
  saxlanır; commit olunmamış dəyişiklik varsa worktree yerində qalır (yolu nəticədə yazılır); təmizdirsə silinir.
Canlı gedişat: claude `--output-format stream-json` ilə işləyir, agent hazırkı addımı və faylları saytda göstərir
  (CLAUDE_TEAM_STREAM=0 ilə söndürülür, onda köhnə `--output-format json`).
Dayandırmaq: Ctrl+C / SIGTERM → yeni tapşırıq götürülmür, işləyənlər CLAUDE_TEAM_GRACE (30) san gözlənilir,
  bitməyənlər dayandırılıb "alınmadı" kimi qeyd olunur. İkinci Ctrl+C — dərhal.

Profil (kompüterin sahibi seçir; CLAUDE_TEAM_PROFILE mühitdə və ya ~/.claude-team-agent.env, standart basic):
  basic : bugünkü kimi (fayl redaktəsi + git səviyyəsi)
  dev   : + Bash: paket menecerləri, testlər, linterlər, build, lokal server (npm/pnpm/yarn/bun, node, npx, python/pytest/pip,
          cargo, go, make, php, composer, ls/cat/grep/find/head/tail/wc/jq, curl yalnız GET) + WebFetch/WebSearch; git ən azı safe
  ops   : dev + gh, vercel, repodakı deploy skriptləri, bütün MCP serverlərinin alətləri; git full
  full  : hər şey (Bash tam), qadağa siyahısından başqa
  Qadağa (dev/ops/full, həmişə): zorla push, reset --hard, clean, branch -D, layihədən kənar rm -rf, sudo, .env*/*.pem/açarlar/
  ~/.claude girişini oxumaq, curl | sh. CLAUDE_TEAM_GIT açıq yazılıbsa profil onu dəyişmir.
  dev+ : redaktə edən prompt/task-dan sonra eyni sessiyada test/lint yoxlaması (CLAUDE_TEAM_SELFCHECK=0 ilə söndürülür).
İsti sessiyalar (CLAUDE_TEAM_WARM=1; dev/ops/full-da standart açıq, basic-də bağlı): proyekt başına davamlı
  `claude -p --input-format stream-json` prosesi saxlanır, növbəti tapşırıq ona yazılır (başlanğıc gözləməsi yoxdur).
  10 dəq boş qalan bağlanır, ən çox 3. İstənilən xətada köhnə bir dəfəlik üsula keçir.
Növlər: prompt/task/review (redaktə), think (yalnız oxuyan düşüncə, opus, plan rejimi, sessiya saxlanmır),
  ops (prompt = {op, args} JSON, claude_ops.py işlədir, nəticə JSON). model / mode=plan tapşırıq başına.
Snapşot: redaktə edən hər tapşırıqdan əvvəl git HEAD + `git stash create` ~/.claude-team-snapshots.json-a yazılır (undo_last üçün).
Növbə: claim `wait` (long-poll) ilə; köhnə server dərhal cavab verirsə köhnə 3 san-lıq dövrə qalır.

İstifadə:  python3 claude-team-agent.py          (davamlı işləyir, Ctrl+C ilə dayandır)
           python3 claude-team-agent.py --once   (bir tapşırıq götürüb çıxır, həmişə 1 işçi)
"""
import atexit, collections, hashlib, json, os, queue, re, shlex, shutil, signal, subprocess, sys, tempfile, threading, time, urllib.error, urllib.parse, urllib.request

CONF = os.environ.get('CLAUDE_TEAM_CONF', os.path.expanduser('~/.claude-team-dash.conf'))
PROJECTS = os.environ.get('CLAUDE_TEAM_PROJECTS', os.path.expanduser('~/.claude-team-projects'))
TIMEOUT = int(os.environ.get('CLAUDE_TEAM_TIMEOUT', '1200'))  # bir tapşırıq üçün maksimum saniyə
POLL = 3
PIDFILE = os.environ.get('CLAUDE_TEAM_PID', os.path.expanduser('~/.claude-team-agent.pid'))   # ikinci (məs. lokal test) agent üçün ayrıca
ENVFILE = os.environ.get('CLAUDE_TEAM_ENV', os.path.expanduser('~/.claude-team-agent.env'))   # ehtiyat: CLAUDE_CODE_OAUTH_TOKEN=... (chmod 600)
WT_DIR = os.environ.get('CLAUDE_TEAM_WORKTREES', os.path.expanduser('~/.claude-team-worktrees'))   # paralel işlər üçün git worktree-lər
GRACE = int(os.environ.get('CLAUDE_TEAM_GRACE', '30'))     # dayananda işləyən tapşırıqları neçə saniyə gözləsin
STREAM = os.environ.get('CLAUDE_TEAM_STREAM', '1') != '0'  # canlı gedişat (stream-json)
WORKERS_MAX = 4
STOP, HARD, STOP_AT = threading.Event(), threading.Event(), [0.0]   # dayanma: STOP → yeni iş yox; HARD (və ya GRACE keçdi) → işləyəni dayandır
LOCK = threading.Lock()
PRIMARY = set()          # əsas iş qovluğu hazırda məşğul olan proyekt yolları
WORKERS_N = [1]          # bu agentin işçi sayı (main təyin edir)
PROGRESS_OK = [True]     # köhnə server `progress` bilmirsə bir dəfə xəta verəndən sonra göndərmirik
STOP_MSG = 'Agent dayandırıldı (kompüterdə agent söndürüldü), tapşırıq yarımçıq qaldı. Yenidən göndər.'
# Fayl redaktəsinə əlavə: git əmrləri (səviyyəyə görə). Dağıdıcı əmrlər (reset --hard, clean, zorla push) heç vaxt icazəli deyil.
READONLY_GIT = ','.join([
    'Bash(git status:*)', 'Bash(git log:*)', 'Bash(git diff:*)', 'Bash(git show:*)', 'Bash(git rev-list:*)',
    'Bash(git branch)', 'Bash(git branch -a)', 'Bash(git branch -vv)', 'Bash(git branch --show-current)', 'Bash(git remote -v)',
])
GIT_SAFE = ['Bash(git fetch:*)', 'Bash(git pull:*)', 'Bash(git add:*)', 'Bash(git commit:*)', 'Bash(git switch:*)',
            'Bash(git stash)', 'Bash(git stash push:*)', 'Bash(git stash pop)', 'Bash(git stash list)']
GIT_FULL = ['Bash(git push:*)', 'Bash(git merge:*)', 'Bash(git checkout:*)']
GIT_DENY = ','.join(['Bash(git push --force:*)', 'Bash(git push -f:*)', 'Bash(git push --force-with-lease:*)', 'Bash(git reset --hard:*)', 'Bash(git clean:*)',
                     'Bash(git push --delete:*)', 'Bash(git branch -D:*)', 'Bash(git checkout -- :*)'])
PROFILES = ('basic', 'dev', 'ops', 'full')
# dev: paket menecerləri, testlər, linterlər, build, lokal server, oxuma əmrləri (curl yalnız GET: yazma bayraqları qadağada)
DEV_BASH = ['npm', 'pnpm', 'yarn', 'bun', 'node', 'npx', 'python', 'python3', 'pytest', 'pip', 'pip3', 'uv', 'ruff', 'mypy', 'tsc', 'eslint',
            'prettier', 'vitest', 'jest', 'phpunit', 'cargo', 'go', 'make', 'php', 'composer', 'ls', 'cat', 'grep', 'rg', 'find', 'head', 'tail',
            'wc', 'jq', 'curl', 'pwd', 'which', 'sort', 'uniq', 'diff']
DEV_TOOLS = [f'Bash({c}:*)' for c in DEV_BASH] + ['WebFetch', 'WebSearch']
OPS_TOOLS = ['Bash(gh:*)', 'Bash(vercel:*)', 'Bash(./*deploy*)', 'Bash(bash *deploy*)', 'Bash(sh *deploy*)']   # + MCP serverləri (mcp_rules)
# Gizli fayllar heç vaxt oxunmasın (dev/ops/full və think)
SECRET_DENY = ['Read(**/.env*)', 'Read(**/*.pem)', 'Read(~/.ssh/**)', 'Read(**/id_rsa*)', 'Read(**/id_ed25519*)', 'Read(**/id_ecdsa*)',
               'Read(**/id_dsa*)', 'Read(~/.claude.json)', 'Read(~/.claude/.credentials.json)', 'Read(~/.claude-team-dash.conf)',
               'Read(~/.claude-team-agent.env)'] + [f'Bash({c} *{f}*)' for c in ('cat', 'head', 'tail', 'less', 'more', 'grep', 'source', '.')
                                                     for f in ('.env', '.pem', 'id_rsa', 'id_ed25519', '.claude.json', '.credentials.json', '.claude-team-')]
# Həmişə qadağan (dev/ops/full): GIT_DENY + aşağıdakılar. `:*` köhnə CLI üçün, `*` yeni sintaksis (istənilən yerdə).
DENY_ALWAYS = (GIT_DENY.split(',') + [
    'Bash(git push * --force*)', 'Bash(git push * -f*)', 'Bash(git push * +*)', 'Bash(git reset * --hard*)', 'Bash(git branch * -D*)',
    'Bash(sudo:*)', 'Bash(sudo *)', 'Bash(sh)', 'Bash(bash)', 'Bash(zsh)', 'Bash(sh -s*)', 'Bash(bash -s*)',
    'Bash(curl * | sh*)', 'Bash(curl * | bash*)', 'Bash(wget * | sh*)', 'Bash(wget * | bash*)',
] + [f'Bash(rm {fl} {t}:*)' for fl in ('-rf', '-fr', '-Rf', '-rF', '-r -f', '-f -r') for t in ('/', '~', '..', '$HOME')] + SECRET_DENY)
# dev/ops: curl yalnız GET; find ilə silmə/icra yox (full-da Bash tamdır, bunlar ona aid deyil)
DENY_DEV = ['Bash(curl -X:*)', 'Bash(curl -d:*)', 'Bash(curl --data:*)', 'Bash(curl -F:*)', 'Bash(curl -T:*)', 'Bash(curl --json:*)'] + [
    f'Bash(curl * {o}*)' for o in ('-X', '--request', '-d', '--data', '--json', '-F', '--form', '-T', '--upload-file')] + [
    'Bash(find * -delete*)', 'Bash(find * -exec*)', 'Bash(find * -ok*)']
SELF_CHECK = ("Run this project's test and lint commands if they exist (detect from package.json/pyproject/Makefile); report only a one-line summary. "
              "Start the line with ✅ if everything passed or ❌ if something failed. Do not edit any files.")
CAPS = {'claude_version': '', 'mcp': []}   # ping-də göstərilir; discover() doldurur
DISCOVERED = threading.Event()             # ilk `claude mcp list` bitdi (ops/full ilk tapşırıqda MCP-siz qalmasın)


def env_opt(k):
    """Mühit dəyişəni və ya ~/.claude-team-agent.env (mühit üstündür). Yoxdursa ''."""
    return (os.environ.get(k) or load_env().get(k) or '').strip()


def profile():
    v = env_opt('CLAUDE_TEAM_PROFILE').lower()
    return v if v in PROFILES else 'basic'


PERM_MODES = ('plan', 'manual', 'acceptEdits', 'auto')   # Claude Code rejimləri (saytdan seçilir); bypassPermissions heç vaxt


def perm_modes():
    """Kompüterin sahibi icazəli rejimləri məhdudlaşdıra bilər: CLAUDE_TEAM_MODES=plan,manual,acceptEdits (standart: hamısı)."""
    v = os.environ.get('CLAUDE_TEAM_MODES') or load_env().get('CLAUDE_TEAM_MODES') or ','.join(PERM_MODES)
    return [m for m in (x.strip() for x in v.split(',')) if m in PERM_MODES]


def git_level():
    v = env_opt('CLAUDE_TEAM_GIT').lower()
    if v in ('read', 'safe', 'full'):        # açıq seçim həmişə üstündür
        return v
    return 'full' if profile() in ('ops', 'full') else 'safe'


def workers():
    """Eyni anda neçə tapşırıq (1..4). Yalnız kompüterin sahibi seçir."""
    try:
        n = int((os.environ.get('CLAUDE_TEAM_WORKERS') or load_env().get('CLAUDE_TEAM_WORKERS') or '1').strip())
    except ValueError:
        n = 1
    return max(1, min(WORKERS_MAX, n))


def allowed_tools(prof='basic', d=None):
    """--allowedTools: basic-də bugünkü kimi (yalnız git), dev/ops/full-da genişlənir. d: proyekt qovluğu (.mcp.json üçün)."""
    lv = git_level()
    parts = [READONLY_GIT] + (GIT_SAFE if lv in ('safe', 'full') else []) + (GIT_FULL if lv == 'full' else [])
    if prof in ('dev', 'ops', 'full'):
        parts += DEV_TOOLS
    if prof in ('ops', 'full'):
        parts += OPS_TOOLS + mcp_rules(d)
    if prof == 'full':
        parts.append('Bash')
    return ','.join(parts)


def denied_tools(prof='basic'):
    if prof == 'basic':
        return GIT_DENY                              # bugünkü kimi
    return ','.join(DENY_ALWAYS + (DENY_DEV if prof in ('dev', 'ops') else []))


def cmd_kind(cmd):
    return str(cmd.get('kind') or 'prompt').strip().lower()


def model_of(cmd):
    m = str(cmd.get('model') or '').strip()
    return m if re.fullmatch(r'[A-Za-z0-9._\[\]-]{1,64}', m) else ''


def perm_args(cmd, d):
    """İcazə bayraqları + model/rejim. basic və model/rejimsiz tapşırıqda bugünkü argv-nin eynisi."""
    prof = profile()
    perm = cmd.get('perm') if cmd.get('perm') in PERM_MODES else 'acceptEdits'   # saytdan seçilən rejim (run_one icazəli olduğunu yoxlayıb)
    a = ['--permission-mode', 'plan' if cmd.get('mode') == 'plan' else perm,
         '--allowedTools', allowed_tools(prof, d), '--disallowedTools', denied_tools(prof)]
    if model_of(cmd):
        a += ['--model', model_of(cmd)]
    return a


def mcp_names(d=None):
    """MCP server adları: `claude mcp list` (discover) + proyektin .mcp.json-u."""
    DISCOVERED.wait(30)
    names = list(CAPS['mcp'])
    if d:
        try:
            with open(os.path.join(d, '.mcp.json'), encoding='utf-8') as f:
                names += [str(k) for k in (json.load(f).get('mcpServers') or {})]
        except Exception:  # noqa
            pass
    return sorted(set(names))


def mcp_rules(d=None):
    """`mcp__*` hamısı üçün işləmir: hər server üçün `mcp__<ad>` (alət adlarındakı kimi normallaşdırılmış)."""
    return ['mcp__' + re.sub(r'[^A-Za-z0-9_-]', '_', n) for n in mcp_names(d)]


def discover(claude, loop):
    """Claude versiyası və MCP serverləri (ping caps + ops profili üçün). `claude mcp list` ağırdır: ops/full-da 30 dəq-dən bir."""
    env = {**os.environ, **load_env()}
    while True:
        try:
            p = subprocess.run([claude, '--version'], capture_output=True, text=True, timeout=30, env=env)
            CAPS['claude_version'] = (p.stdout.strip().split() or [''])[0][:40]
        except Exception:  # noqa
            pass
        try:
            p = subprocess.run([claude, 'mcp', 'list'], capture_output=True, text=True, timeout=120, env=env, cwd=os.path.expanduser('~'),
                               stdin=subprocess.DEVNULL)
            if p.returncode == 0:
                CAPS['mcp'] = sorted({ln.split(': ', 1)[0].strip()[:80] for ln in p.stdout.splitlines()
                                      if ': ' in ln and not ln.lower().startswith(('checking', 'no mcp'))})[:50]
        except Exception:  # noqa
            pass
        DISCOVERED.set()
        if not loop or STOP.wait(1800):
            return


def git_env():
    """git pull həmişə yalnız sürətli irəli (fast-forward) olsun: birləşmə commit-i və ya rebase yaranmasın."""
    return {'GIT_CONFIG_COUNT': '2', 'GIT_CONFIG_KEY_0': 'pull.ff', 'GIT_CONFIG_VALUE_0': 'only', 'GIT_CONFIG_KEY_1': 'pull.rebase', 'GIT_CONFIG_VALUE_1': 'false'}


def read_conf():
    d = {}
    with open(CONF) as f:
        for line in f:
            line = line.strip()
            if '=' in line and not line.startswith('#'):
                k, v = shlex.split(line)[0].split('=', 1)
                d[k] = v
    if not d.get('URL') or not d.get('TOKEN'):
        sys.exit('Konfiqurasiya tapılmadı: ' + CONF)
    return d['URL'].replace('ingest.php', 'agent.php'), d['TOKEN']


def load_env():
    """CLI girişi mühitdən görünmürsə (məs. launcher-dən başlayanda), tokeni bu fayldan götür. Yalnız bu açarlar."""
    out = {}
    try:
        with open(ENVFILE) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    if k.strip() in ('CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY', 'CLAUDE_TEAM_GIT', 'CLAUDE_TEAM_WORKERS',
                                     'CLAUDE_TEAM_PROFILE', 'CLAUDE_TEAM_WARM', 'CLAUDE_TEAM_SELFCHECK', 'CLAUDE_TEAM_MODES'):
                        out[k.strip()] = v.strip().strip('"\'')
    except FileNotFoundError:
        pass
    return out


def read_projects():
    out = {}
    try:
        with open(PROJECTS) as f:
            for line in f:
                if '\t' in line:
                    name, path = line.rstrip('\n').split('\t', 1)
                    if os.path.isdir(path):
                        out[name] = path
    except FileNotFoundError:
        pass
    return out


def call(base, token, action, payload=None, timeout=20):
    req = urllib.request.Request(
        f'{base}?a={action}', data=json.dumps(payload or {}).encode(), method='POST',
        headers={'X-Team-Token': token, 'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode())


def fetch_images(base, token, names):
    """Prompta əlavə olunmuş şəkilləri müvəqqəti qovluğa endirir. Qaytarır: (qovluq, fayl yolları). Alınmasa xəta atır."""
    d = tempfile.mkdtemp(prefix='claude-team-img-')
    paths = []
    try:
        for i, name in enumerate(names, 1):
            req = urllib.request.Request(base.replace('agent.php', 'cmdimg.php') + '?f=' + urllib.parse.quote(str(name)),
                                         headers={'X-Team-Token': token})
            with urllib.request.urlopen(req, timeout=60) as r:
                data = r.read()
            name = str(name)
            if '-' in name:                                 # fayl: "<hex>-<orijinal ad>.<uzantı>" → orijinal ad (nömrə ilə, toqquşmasın)
                p = os.path.join(d, f'{i}-{os.path.basename(name[25:])}')
            else:
                p = os.path.join(d, f'sekil-{i}{os.path.splitext(name)[1] or ".png"}')
            with open(p, 'wb') as f:
                f.write(data)
            paths.append(p)
    except Exception:
        shutil.rmtree(d, ignore_errors=True)
        raise
    return d, paths


def find_claude():
    for c in (os.environ.get('CLAUDE_BIN'), shutil.which('claude'),
              os.path.expanduser('~/.local/bin/claude'), '/usr/local/bin/claude', '/opt/homebrew/bin/claude'):
        if c and os.path.exists(c):
            return c
    sys.exit('claude tapılmadı. CLAUDE_BIN mühit dəyişəni ilə yolu göstər.')


def auth_ok(claude):
    """claude girişlidirmi? (`claude auth status`, gizli məlumat çıxarmır)"""
    try:
        p = subprocess.run([claude, 'auth', 'status'], capture_output=True, text=True, timeout=30, env={**os.environ, **load_env()})
        return bool(json.loads(p.stdout or '{}').get('loggedIn'))
    except Exception:
        return False


def kill_group(proc):
    """claude və onun açdığı bütün alt-prosesləri dayandır."""
    for sig in (signal.SIGTERM, signal.SIGKILL):
        try:
            os.killpg(proc.pid, sig)
        except (ProcessLookupError, PermissionError):   # macOS: qrupda yalnız zombi qalıbsa EPERM
            return
        for _ in range(30):
            if proc.poll() is not None:
                return
            time.sleep(.1)


SESSIONS = os.environ.get('CLAUDE_TEAM_SESSIONS', os.path.expanduser('~/.claude-team-sessions.json'))   # söhbət ('chat:<id>') və ya proyekt+göndərən → claude sessiya nömrəsi (söhbət davam etsin)
SESSION_TTL = 30 * 24 * 3600                                     # köhnə söhbətə qayıtmaq olsun (Claude Code sessiyaları da ~30 gün saxlayır)
SESS_LOCK = threading.Lock()   # paralel işçilər faylı eyni anda yazmasın


def sess_load():
    try:
        with open(SESSIONS, encoding='utf-8') as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except Exception:  # noqa
        return {}


def sess_save(d):
    try:
        now = time.time()
        d = {k: v for k, v in d.items() if now - v.get('ts', 0) < SESSION_TTL}
        tmp = SESSIONS + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(d, f)
        os.chmod(tmp, 0o600)
        os.replace(tmp, SESSIONS)
    except Exception:  # noqa
        pass


def stopping_hard():
    """İşləyən tapşırığı indi dayandırmaq lazımdırmı? (ikinci Ctrl+C və ya gözləmə vaxtı bitdi)"""
    return HARD.is_set() or (STOP.is_set() and time.time() - STOP_AT[0] >= GRACE)


class Progress:
    """stream-json hadisələrindən canlı gedişat: hazırkı addım, son fayllar, təxmini faiz, sessiya və yekun nəticə."""

    def __init__(self, cwd, step='Claude başlayır'):
        self.cwd = cwd.rstrip('/') + '/'
        self.step, self.files, self.msgs, self.text = step, [], set(), ''
        self.sid, self.result, self.events, self.dirty, self.last = None, None, 0, True, 0.0
        self.edited = False      # Edit/Write aləti işlədibmi (yoxlama üçün)

    def rel(self, p):
        p = str(p)
        return p[len(self.cwd):] if p.startswith(self.cwd) else p

    def set(self, step):
        self.step, self.dirty = ' '.join(str(step).split())[:160], True

    def tool(self, name, inp):
        inp = inp if isinstance(inp, dict) else {}
        if name in ('Edit', 'Write', 'MultiEdit', 'NotebookEdit'):
            self.edited = True
        f = inp.get('file_path') or inp.get('notebook_path')
        if f:
            f = self.rel(f)[:200]
            self.files = [x for x in self.files if x != f][-5:] + [f]
        arg = f or inp.get('command') or inp.get('pattern') or inp.get('url') or inp.get('description') or inp.get('query') or ''
        self.set(f'{name} · {arg}' if arg else name)

    def feed(self, line):
        try:
            ev = json.loads(line)
        except Exception:  # noqa
            return
        if not isinstance(ev, dict) or not ev.get('type'):
            return
        self.events += 1
        t = ev['type']
        if isinstance(ev.get('session_id'), str):
            self.sid = ev['session_id']
        if t == 'system' and ev.get('subtype') == 'init':
            self.set('Sessiya başladı')
        elif t == 'assistant':
            m = ev.get('message') if isinstance(ev.get('message'), dict) else {}
            self.msgs.add(m.get('id') or len(self.msgs))
            for c in m.get('content') or []:
                if not isinstance(c, dict):
                    continue
                if c.get('type') == 'tool_use':
                    self.tool(str(c.get('name') or '?'), c.get('input'))
                elif c.get('type') == 'text' and str(c.get('text') or '').strip():
                    self.text = str(c['text'])
                    self.set(self.text[:120])
        elif t == 'result':
            self.result = ev

    def payload(self):
        n = len(self.msgs)   # təxmini: addım sayı adi büdcəyə nisbətən, heç vaxt 100 deyil (100 = finish)
        return {'step': self.step, 'files': self.files[-6:], 'pct': min(95, round(100 * n / (n + 12))) if n else None, 'at': int(time.time())}


def post_progress(base, token, cmd, prog):
    prog.dirty, prog.last = False, time.time()
    if not PROGRESS_OK[0]:
        return
    try:
        call(base, token, 'progress', {'id': cmd['id'], 'progress': prog.payload()}, timeout=8)
    except urllib.error.HTTPError as e:
        if e.code in (400, 404):         # köhnə server: `progress` yoxdur, bir daha cəhd etmə
            PROGRESS_OK[0] = False
    except Exception:  # noqa
        pass


class Watch:
    """İşləyən tapşırığı nə vaxt dayandırmaq lazımdır: vaxt limiti, agentin dayanması, saytda "Dayandır" (3 san-dan bir soruşulur)."""

    def __init__(self, base, token, cmd, timeout=TIMEOUT):
        self.base, self.token, self.cmd, self.timeout, self.t0, self.last = base, token, cmd, timeout, time.time(), 0.0

    def why(self):
        now = time.time()
        if now - self.t0 > self.timeout:
            return f'Vaxt bitdi ({self.timeout} san).'
        if stopping_hard():
            return STOP_MSG
        if now - self.last >= 3:
            self.last = now
            try:
                if call(self.base, self.token, 'poll', {'id': self.cmd['id']}).get('cancel'):
                    return 'Dayandırıldı.'
            except Exception:  # noqa
                pass
        return None


def exec_claude(base, token, args, cmd, path, prog=None, timeout=TIMEOUT):
    """Bir claude prosesini işlədir; "Dayandır", vaxt limiti və agentin dayanması izlənir. prog verilibsə stdout sətir-sətir
    oxunur və gedişat ən azı 2 san aralıqla serverə göndərilir. Qaytarır: (out, err, returncode) və ya (None, mesaj, 'end')."""
    env = {**os.environ, **load_env(), **git_env(), 'CLAUDE_TEAM_RUN': str(cmd['id'])}
    try:
        proc = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd=path, env=env,
                                start_new_session=True)
    except Exception as e:  # noqa
        return None, f'Agent xətası: {e}', 'end'
    box = {}

    def work():
        try:
            box['out'], box['err'] = proc.communicate(cmd['prompt'])
        except Exception as e:  # noqa
            box['exc'] = e

    def work_stream():
        def feed_in():
            try:
                proc.stdin.write(cmd['prompt'])
                proc.stdin.close()
            except Exception:  # noqa
                pass
        errs, lines = [], []
        ts = [threading.Thread(target=feed_in, daemon=True), threading.Thread(target=lambda: errs.append(proc.stderr.read()), daemon=True)]
        for t in ts:
            t.start()
        try:
            for line in proc.stdout:
                if not prog.events and len(lines) < 5000:   # hadisə gəlməyibsə xam çıxışı saxla (köhnə üsula keçid üçün)
                    lines.append(line)
                try:
                    prog.feed(line)
                except Exception:  # noqa
                    pass
            proc.wait()
            for t in ts:
                t.join(5)
            box['out'], box['err'] = ''.join(lines), ''.join(errs)
        except Exception as e:  # noqa
            box['exc'] = e
    th = threading.Thread(target=work_stream if prog else work, daemon=True)
    th.start()
    w = Watch(base, token, cmd, timeout)
    while th.is_alive():
        th.join(1)
        why = w.why() if th.is_alive() else None
        if why:
            kill_group(proc); th.join(5)
            return None, why, 'end'
        if prog and prog.dirty and time.time() - prog.last >= 2 and th.is_alive():
            post_progress(base, token, cmd, prog)
    if 'exc' in box:
        return None, f"Agent xətası: {box['exc']}", 'end'
    return (box.get('out') or ''), (box.get('err') or ''), proc.returncode


def parse_result(out):
    """--output-format json cavabından mətn, sessiya nömrəsi və xəta bayrağı."""
    try:
        j = json.loads(out)
        if isinstance(j, dict) and 'result' in j:
            return str(j.get('result') or ''), j.get('session_id'), bool(j.get('is_error'))
    except Exception:  # noqa
        pass
    return None, None, False


# ---- İsti sessiyalar: proyekt başına davamlı `claude -p --input-format stream-json` prosesi ----
# Yalnız əsas qovluqda (worktree yox), prompt/task, şəkilsiz. Proses söhbətin sessiyasını (sid) və icazə bayraqlarını
# yadda saxlayır; növbəti tapşırıq eyni sid + eyni bayraqlarla gəlirsə ona yazılır, yoxsa yenisi açılır (--resume sid).
# CLAUDE_TEAM_RUN prosesin açıldığı tapşırığın nömrəsidir (hook yalnız boş olub-olmadığına baxır).
WARM_MAX, WARM_IDLE = 3, 600
WARM, WARM_LOCK, WARM_ON = {}, threading.Lock(), [False]


def warm_enabled():
    v = env_opt('CLAUDE_TEAM_WARM')
    if v in ('0', '1'):
        return v == '1'
    return profile() != 'basic'          # basic: bugünkü kimi (bir dəfəlik), sahibi CLAUDE_TEAM_WARM=1 ilə açır


class Warm:
    def __init__(self, claude, path, tail, sid, cid):
        self.path, self.tail, self.sid, self.used, self.busy = path, list(tail), sid, time.time(), True
        self.q, self.err = queue.Queue(), collections.deque(maxlen=200)
        env = {**os.environ, **load_env(), **git_env(), 'CLAUDE_TEAM_RUN': str(cid)}
        args = [claude, '-p', '--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose'] + self.tail + (['--resume', sid] if sid else [])
        self.proc = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding='utf-8',
                                     errors='replace', bufsize=1, cwd=path, env=env, start_new_session=True)
        threading.Thread(target=self._out, daemon=True).start()
        threading.Thread(target=self._err, daemon=True).start()

    def _out(self):
        try:
            for line in self.proc.stdout:
                self.q.put(line)
        except Exception:  # noqa
            pass
        self.q.put(None)                     # EOF: proses öldü

    def _err(self):
        try:
            for line in self.proc.stderr:
                self.err.append(line)
        except Exception:  # noqa
            pass

    def alive(self):
        return self.proc.poll() is None

    def send(self, obj):
        self.proc.stdin.write(json.dumps(obj) + '\n')
        self.proc.stdin.flush()

    def kill(self):
        try:
            self.proc.stdin.close()
        except Exception:  # noqa
            pass
        kill_group(self.proc)


def warm_get(claude, path, tail, sid, cid):
    """Uyğun isti proses (eyni sid + bayraqlar) → (w, True); yoxdursa yenisi → (w, False); limit dolubsa (None, False)."""
    old = []
    try:
        with WARM_LOCK:
            w = WARM.get(path)
            if w and not w.busy and w.alive() and sid and w.sid == sid and w.tail == list(tail):
                w.busy = True
                return w, True
            if w and not w.busy:
                old.append(WARM.pop(path))
            elif w:
                return None, False
            idle = sorted((x for x in WARM.values() if not x.busy), key=lambda x: x.used)
            while len(WARM) >= WARM_MAX and idle:
                x = idle.pop(0)
                old.append(WARM.pop(x.path))
            if len(WARM) >= WARM_MAX:
                return None, False
            try:
                w = WARM[path] = Warm(claude, path, tail, sid, cid)
            except Exception:  # noqa
                return None, False
            return w, False
    finally:
        for x in old:
            x.kill()


def warm_release(w):
    with WARM_LOCK:
        w.busy, w.used = False, time.time()


def warm_drop(path, w=None):
    with WARM_LOCK:
        x = WARM.get(path)
        if x is None or (w is not None and x is not w):
            x = None
        else:
            WARM.pop(path)
    if x:
        x.kill()
    if w is not None and w is not x:
        w.kill()


def warm_reap():
    """10 dəq boş qalan və ya ölmüş prosesləri bağla."""
    now, dead = time.time(), []
    with WARM_LOCK:
        for p, w in list(WARM.items()):
            if not w.busy and (not w.alive() or now - w.used > WARM_IDLE):
                dead.append(WARM.pop(p))
    for w in dead:
        w.kill()


def warm_kill_all():
    with WARM_LOCK:
        ws = list(WARM.values())
        WARM.clear()
    for w in ws:
        w.kill()


def warm_names():
    inv = {v: k for k, v in read_projects().items()}
    with WARM_LOCK:
        return sorted(inv.get(p, os.path.basename(p)) for p in WARM)


def warm_turn(base, token, w, cmd, prompt, prog, timeout=TIMEOUT):
    """İsti prosesə bir istifadəçi mesajı yaz, `result` hadisəsinə qədər oxu.
    Qaytarır: ('ok', None) | ('dead', None) — proses öldü | ('end', səbəb) — dayandırıldı/vaxt bitdi (çağıran prosesi öldürür)."""
    while True:                                   # əvvəlki növbədən qalan hadisələr
        try:
            x = w.q.get_nowait()
        except queue.Empty:
            break
        if x is None:
            return 'dead', None
    if not w.alive():
        return 'dead', None
    try:
        w.send({'type': 'user', 'message': {'role': 'user', 'content': [{'type': 'text', 'text': prompt}]}, 'parent_tool_use_id': None})
    except Exception:  # noqa
        return 'dead', None
    wd = Watch(base, token, cmd, timeout)
    while True:
        try:
            line = w.q.get(timeout=1)
        except queue.Empty:
            line = ''
        if line is None:
            return 'dead', None
        if line.strip():
            try:
                ev = json.loads(line)
            except Exception:  # noqa
                ev = None
            if isinstance(ev, dict) and ev.get('type') == 'control_request':   # icazə sorğusu: bir dəfəlik -p kimi rədd et
                rq = ev.get('request') if isinstance(ev.get('request'), dict) else {}
                rid = ev.get('request_id')
                resp = ({'subtype': 'success', 'request_id': rid, 'response': {
                    'behavior': 'deny', 'message': 'Agent profili bu aləti icazə vermir (sahibi CLAUDE_TEAM_PROFILE ilə genişləndirə bilər).'}}
                        if rq.get('subtype') == 'can_use_tool' else {'subtype': 'error', 'request_id': rid, 'error': 'agent dəstəkləmir'})
                try:
                    w.send({'type': 'control_response', 'response': resp})
                except Exception:  # noqa
                    return 'dead', None
            else:
                try:
                    prog.feed(line)
                except Exception:  # noqa
                    pass
                if prog.result is not None:
                    return 'ok', None
        why = wd.why()
        if why:
            return 'end', why
        if prog.dirty and time.time() - prog.last >= 2:
            post_progress(base, token, cmd, prog)


def warm_run(base, token, claude, cmd, path, perm, sid, progs):
    """Tapşırığı isti prosesdə işlət. Qaytarır: (w | None, (out, err, rc, mətn, sessiya, xəta) | None). None → köhnə üsul."""
    w, reused = warm_get(claude, path, perm, sid, cmd['id'])
    if not w:
        return None, None
    prog = Progress(path, 'Claude başlayır (isti sessiya)' if reused else 'Claude başlayır')
    progs.append(prog)
    state, why = warm_turn(base, token, w, cmd, cmd['prompt'], prog)
    if state == 'end':
        warm_drop(path, w)
        return None, (None, why, 'end', None, None, False)
    r = prog.result
    if state == 'dead' or (r is not None and r.get('is_error') and not prog.msgs):
        err = ''.join(w.err).strip()[-2000:]
        warm_drop(path, w)
        if not prog.msgs:                         # heç nə etməmişdi: köhnə üsulla bir də (--resume / sıfırdan)
            print(f"   #{cmd['id']} isti sessiya alınmadı, bir dəfəlik üsula keçir", flush=True)
            return None, None
        return None, (prog.text, err, 1, None, prog.sid, False)   # yarımçıq qırıldı: təkrar etmə (dəyişikliklər ola bilər)
    w.sid = r.get('session_id') or prog.sid or w.sid
    return w, ('', '', 0, str(r.get('result') or ''), w.sid, bool(r.get('is_error')))


# ---- Snapşot (undo_last üçün) ----
# ~/.claude-team-snapshots.json (CLAUDE_TEAM_SNAPSHOTS), hər proyekt üçün yalnız sonuncu:
#   {"<proyekt adı>": {"path": "/abs/qovluq", "sha": "<HEAD>", "stash": "<`git stash create` commit-i və ya ''>",
#                      "untracked": ["<o an izlənməyən fayllar>", ...], "at": <unix san>, "cmd_id": <tapşırıq nömrəsi>}}
# stash: o anki izlənən dəyişikliklər (index + iş qovluğu), heç nə dəyişdirmədən yaradılır; '' = qovluq təmiz idi.
# Bərpa (D2 undo_last): izlənən faylları `sha`-ya qaytar, stash boş deyilsə onu tətbiq et (`git stash apply <stash>`),
# `untracked`-də olmayan yeni izlənməyən fayllar tapşırığın yaratdıqlarıdır.
SNAPS = os.environ.get('CLAUDE_TEAM_SNAPSHOTS', os.path.expanduser('~/.claude-team-snapshots.json'))
SNAP_LOCK = threading.Lock()


def snapshot(cmd, path):
    if not os.path.exists(os.path.join(path, '.git')):
        return
    try:
        sha = git(path, 'rev-parse', 'HEAD', timeout=20).stdout.strip()
        if not sha:
            return
        st = git(path, 'stash', 'create', timeout=60)
        un = git(path, 'ls-files', '--others', '--exclude-standard', timeout=30).stdout.splitlines()[:500]
        rec = {'path': path, 'sha': sha, 'stash': st.stdout.strip() if st.returncode == 0 else '', 'untracked': un,
               'at': int(time.time()), 'cmd_id': cmd['id']}
        with SNAP_LOCK:
            try:
                with open(SNAPS, encoding='utf-8') as f:
                    d = json.load(f)
                d = d if isinstance(d, dict) else {}
            except Exception:  # noqa
                d = {}
            d[cmd['project']] = rec
            tmp = SNAPS + '.tmp'
            with open(tmp, 'w', encoding='utf-8') as f:
                json.dump(d, f, ensure_ascii=False)
            os.chmod(tmp, 0o600)
            os.replace(tmp, SNAPS)
    except Exception as e:  # noqa
        print(f"   #{cmd['id']} snapşot alınmadı: {e}", flush=True)


# ---- Özünü yoxlama (profil ≥ dev): redaktədən sonra eyni sessiyada test/lint ----
def selfcheck_on(cmd):
    return (profile() != 'basic' and cmd_kind(cmd) in ('prompt', 'task') and cmd.get('mode') != 'plan'
            and env_opt('CLAUDE_TEAM_SELFCHECK') != '0')


def git_state(d):
    """HEAD + status + diff izi: tapşırıq faylları dəyişdibmi? git deyilsə None."""
    if not os.path.exists(os.path.join(d, '.git')):
        return None
    try:
        return (git(d, 'rev-parse', 'HEAD', timeout=20).stdout + git(d, 'status', '--porcelain', timeout=30).stdout
                + hashlib.sha1(git(d, 'diff', 'HEAD', timeout=30).stdout.encode('utf-8', 'replace')).hexdigest())
    except Exception:  # noqa
        return None


def has_tests(d):
    """Test/lint əmri tez tapılırmı? (package.json scripts, pyproject, Makefile, ...)"""
    try:
        pj = os.path.join(d, 'package.json')
        if os.path.isfile(pj):
            with open(pj, encoding='utf-8') as f:
                sc = json.load(f).get('scripts') or {}
            if any(k in sc for k in ('lint', 'typecheck', 'check')) or ('test' in sc and 'no test specified' not in str(sc['test'])):
                return True
        if any(os.path.isfile(os.path.join(d, f)) for f in ('pyproject.toml', 'pytest.ini', 'tox.ini', 'Cargo.toml', 'go.mod', 'phpunit.xml', 'phpunit.xml.dist')):
            return True
        if any(os.path.isdir(os.path.join(d, f)) for f in ('tests', 'test')):
            return True
        mk = os.path.join(d, 'Makefile')
        if os.path.isfile(mk):
            with open(mk, encoding='utf-8', errors='replace') as f:
                if re.search(r'^(test|lint|check)\s*:', f.read(200000), re.M):
                    return True
        cj = os.path.join(d, 'composer.json')
        if os.path.isfile(cj):
            with open(cj, encoding='utf-8') as f:
                if 'test' in (json.load(f).get('scripts') or {}):
                    return True
    except Exception:  # noqa
        pass
    return False


def self_check(base, token, claude, cmd, run_dir, tail, sid, w):
    """Eyni sessiyada (isti proses və ya --resume) test/lint. Qaytarır: (bir sətir, yeni sessiya | None)."""
    c2, limit = {**cmd, 'prompt': SELF_CHECK}, min(TIMEOUT, 600)
    prog = Progress(run_dir, 'Yoxlama: testlər və lint')
    post_progress(base, token, cmd, prog)
    r, err = None, ''
    if w is not None and w.alive():
        state, why = warm_turn(base, token, w, c2, SELF_CHECK, prog, limit)
        if state == 'end':
            warm_drop(w.path, w)
            return f'dayandırıldı ({why})', None
        if state == 'dead':
            warm_drop(w.path, w)
        r = prog.result
        if r is not None:
            w.sid = r.get('session_id') or w.sid
    elif sid:
        if STREAM:
            out, err, rc = exec_claude(base, token, [claude, '-p', '--output-format', 'stream-json', '--verbose'] + list(tail) + ['--resume', sid],
                                       c2, run_dir, prog, timeout=limit)
            r = prog.result
        else:
            out, err, rc = exec_claude(base, token, [claude, '-p', '--output-format', 'json'] + list(tail) + ['--resume', sid], c2, run_dir, timeout=limit)
            t, s2, e2 = parse_result(out or '') if rc != 'end' else (None, None, False)
            r = {'result': t, 'session_id': s2, 'is_error': e2} if t is not None else None
        if rc == 'end':
            return f'dayandırıldı ({err})', None
    else:
        return '', None
    if r is None:
        return '❌ yoxlama alınmadı' + (f": {str(err).strip().splitlines()[-1][:200]}" if str(err).strip() else ''), None
    line = next((x.strip() for x in str(r.get('result') or '').splitlines() if x.strip()), '')[:300]
    return (('❌ yoxlama alınmadı: ' if r.get('is_error') else '') + (line or '(boş cavab)')), r.get('session_id') or prog.sid


def run_claude(base, token, claude, cmd, path, extra_args=(), cwd=None):
    """claude -p işlədir. Söhbətin (chat) və ya proyekt+göndərənin əvvəlki sessiyasını davam etdirir (--resume); "Yeni söhbət" seçilibsə sıfırdan.
    cwd: paralel iş üçün git worktree (onda söhbət davam etdirilmir və saxlanmır, çünki sessiya qovluğa bağlıdır).
    İsti sessiya açıqdırsa (əsas qovluq, prompt/task, şəkilsiz) tapşırıq davamlı prosesə yazılır; alınmasa köhnə üsul."""
    wt = bool(cwd and cwd != path)
    run_dir = cwd or path
    legacy = path + '|' + (cmd.get('creator') or '')
    key = f"chat:{cmd['chat']}" if cmd.get('chat') else legacy
    sid = None
    if not (cmd.get('fresh') or wt):
        store = sess_load()
        sid = (store.get(key) or store.get(legacy) or {}).get('sid')   # legacy: söhbətlərdən əvvəl başlanmış söhbət
    mode = ['stream' if STREAM else 'json']
    perm = perm_args(cmd, run_dir)
    tail = perm + list(extra_args)
    progs = []
    check = selfcheck_on(cmd)
    pre = git_state(run_dir) if check else None

    def go(with_sid):
        """Qaytarır: (out, err, rc, mətn, sessiya, xəta_bayrağı)."""
        res = ['--resume', with_sid] if with_sid else []
        if mode[0] == 'json':
            out, err, rc = exec_claude(base, token, [claude, '-p', '--output-format', 'json'] + tail + res, cmd, run_dir)
            return (out, err, rc) + ((None, None, False) if rc == 'end' else parse_result(out))
        prog = Progress(run_dir, '⎇ worktree · Claude başlayır' if wt else 'Claude başlayır')
        progs.append(prog)
        out, err, rc = exec_claude(base, token, [claude, '-p', '--output-format', 'stream-json', '--verbose'] + tail + res, cmd, run_dir, prog)
        if rc == 'end':
            return out, err, rc, None, None, False
        r = prog.result
        if r is not None:
            return out, err, rc, str(r.get('result') or ''), r.get('session_id') or prog.sid, bool(r.get('is_error'))
        if not prog.events and rc != 0 and not with_sid:   # stream-json alınmadı (köhnə claude?): bu iş üçün köhnə üsul
            mode[0] = 'json'
            return go(None)
        if prog.events:                                    # hadisələr gəldi, amma yekun yoxdur (yarımçıq qırıldı)
            return prog.text, err, rc, None, prog.sid, False
        return (out, err, rc) + parse_result(out)
    w, res = None, None
    if WARM_ON[0] and STREAM and not wt and not extra_args and cmd_kind(cmd) in ('prompt', 'task'):
        w, res = warm_run(base, token, claude, cmd, path, perm, sid, progs)
    elif not wt:
        warm_drop(path)          # bir dəfəlik iş eyni söhbəti davam etdirə bilər: isti prosesin yaddaşı köhnələr
    try:
        if res is not None:
            out, err, rc, text, new_sid, is_err = res
            if rc == 'end':
                return err, 'failed'
        else:
            out, err, rc, text, new_sid, is_err = go(sid)
            if rc == 'end':
                return err, 'failed'
            if sid and (rc != 0 or is_err or text is None):            # köhnə söhbət tapılmadı/xəta: sıfırdan bir də cəhd et
                out, err, rc, text, new_sid, is_err = go(None)
                if rc == 'end':
                    return err, 'failed'
                sid = None
        if text is None:
            text = out.strip() or err.strip() or '(boş cavab)'
        elif not text.strip():
            text = err.strip() or '(boş cavab)'
        ok = rc == 0 and not is_err
        note = ''
        if check and ok and (any(p.edited for p in progs) or git_state(run_dir) != pre) and has_tests(run_dir):
            note, sid2 = self_check(base, token, claude, cmd, run_dir, tail, new_sid, w)
            new_sid = sid2 or new_sid
        if new_sid and not wt:
            with SESS_LOCK:
                store = sess_load()
                store[key] = {'sid': new_sid, 'ts': time.time()}
                sess_save(store)
            if w is not None:
                w.sid = new_sid
        head = '' if (not sid or cmd.get('fresh')) else '↻ əvvəlki söhbət davam etdi\n\n'
        return head + text + (f'\n\n— Yoxlama: {note}' if note else ''), ('done' if ok else 'failed')
    finally:
        if w is not None:
            warm_release(w)


def run_think(base, token, claude, cmd, d, extra=()):
    """think: yalnız oxuyan düşüncə (opus, plan rejimi, redaktə/Bash yox, sessiya saxlanmır). Nəticə = cavab mətni."""
    web = profile() != 'basic'
    deny = ','.join(SECRET_DENY)
    head = [claude, '-p', '--model', model_of(cmd) or 'opus', '--permission-mode', 'plan', '--output-format', 'stream-json', '--verbose']
    allow = ['--allowedTools', 'WebSearch,WebFetch'] if web else []
    variants = [
        head + ['--tools', 'Read,Grep,Glob' + (',WebSearch,WebFetch' if web else ''), '--no-session-persistence', '--disallowedTools', deny] + allow,
        head + ['--disallowedTools', 'Bash,Edit,Write,MultiEdit,NotebookEdit,' + deny] + allow,    # köhnə CLI: --tools/--no-session-persistence yoxdur
    ]
    err = out = ''
    for args in variants:
        prog = Progress(d, 'Düşünür')
        out, err, rc = exec_claude(base, token, args + list(extra), cmd, d, prog)
        if rc == 'end':
            return err, 'failed'
        r = prog.result
        if r is not None:
            return (str(r.get('result') or '').strip() or '(boş cavab)'), ('done' if rc == 0 and not r.get('is_error') else 'failed')
        if prog.events:
            return prog.text or (err or '').strip() or '(boş cavab)', 'failed'
    return ((err or '').strip() or (out or '').strip() or 'claude cavab vermədi')[-2000:], 'failed'


def run_ops(base, token, claude, cmd):
    """ops: prompt = {"op", "args"} JSON, claude_ops.handle(op, args, ctx) işlədir. Qaytarır: (JSON mətn, status)."""
    def fail(msg, op=''):
        return json.dumps({'ok': False, 'op': op, 'error': msg}, ensure_ascii=False), 'failed'
    try:
        req = json.loads(cmd.get('prompt') or '')
        op, args = str(req.get('op') or '').strip(), req.get('args') or {}
        if not op or not isinstance(args, dict):
            raise ValueError
    except Exception:  # noqa
        return fail('ops: prompt {"op": ..., "args": {...}} JSON olmalıdır')
    here = os.path.dirname(os.path.abspath(__file__))
    if here not in sys.path:
        sys.path.append(here)
    try:
        import claude_ops
    except Exception as e:  # noqa
        return fail(f'claude_ops.py tapılmadı və ya yüklənmədi ({e}). Agent paketini yenilə (agent/claude_ops.py agentin yanında olmalıdır).', op)
    projects = read_projects()
    names = [str(args['project'])] if args.get('project') else (cmd.get('names') or [cmd.get('project') or ''])
    ctx = {'project_path': next((projects[n] for n in names if n in projects), ''), 'projects': projects,   # qlobal op: ''
           'profile': profile(), 'git_level': git_level(), 'claude_bin': claude}
    box = {}

    def work():
        try:
            box['r'] = claude_ops.handle(op, args, ctx)
        except Exception as e:  # noqa
            box['r'] = {'ok': False, 'op': op, 'error': f'{type(e).__name__}: {e}'}
    th = threading.Thread(target=work, daemon=True)
    th.start()
    wd = Watch(base, token, cmd, min(TIMEOUT, 900))
    while th.is_alive():
        th.join(1)
        why = wd.why() if th.is_alive() else None
        if why:
            return fail(why, op)
    r = box.get('r')
    r = r if isinstance(r, dict) else {'ok': True, 'data': r}
    s = json.dumps(r, ensure_ascii=False, default=str)
    if len(s) > 7500:                          # server 8000-də kəsir: yarımçıq JSON olmasın
        s = json.dumps({'ok': r.get('ok', True), 'op': op, 'truncated': True, 'head': s[:6000]}, ensure_ascii=False)
    return s, ('failed' if r.get('ok') is False else 'done')


def git(d, *a, timeout=60):
    return subprocess.run(['git', '-C', d] + list(a), capture_output=True, text=True, timeout=timeout)


def can_worktree(path):
    return git_level() in ('safe', 'full') and os.path.exists(os.path.join(path, '.git'))


def wt_add(path, cid):
    """Proyektin HEAD-indən ayrıca worktree (detached). Qaytarır: (yol, başlanğıc commit) və ya None."""
    try:
        os.makedirs(WT_DIR, exist_ok=True)
        wt = os.path.join(WT_DIR, f'{os.path.basename(path.rstrip("/")) or "proyekt"}-{cid}')
        if os.path.exists(wt):
            wt += f'-{int(time.time())}'
        if git(path, 'worktree', 'add', '--detach', wt, 'HEAD', timeout=180).returncode != 0:
            return None
        return wt, git(wt, 'rev-parse', 'HEAD').stdout.strip()
    except Exception:  # noqa
        return None


def wt_done(path, wt, head0, cid):
    """İşdən sonra: commit-lər budaqda saxlanır, commit olunmamış dəyişiklik varsa worktree qalır, təmizdirsə silinir. Qaytarır: qeyd."""
    try:
        head = git(wt, 'rev-parse', 'HEAD').stdout.strip()
        br = git(wt, 'symbolic-ref', '-q', '--short', 'HEAD').stdout.strip()
        notes = []
        if head and head != head0 and not br:
            b = f'jarvis/{cid}'
            if git(wt, 'show-ref', '--verify', '--quiet', f'refs/heads/{b}').returncode == 0:
                b += f'-{int(time.time())}'
            if git(wt, 'switch', '-c', b).returncode == 0:
                br = b
                notes.append(f'yeni commit-lər `{b}` budağında saxlanıb')
            else:
                notes.append(f'yeni commit-lər budağa yazılmadı (HEAD {head[:10]})')
        elif br:
            notes.append(f'iş `{br}` budağındadır')
        if git(wt, 'status', '--porcelain').stdout.strip():
            notes.append(f'commit olunmamış dəyişikliklər var, worktree saxlanıldı: {wt} (bax, birləşdir, sonra `git worktree remove {wt}`)')
        elif git(path, 'worktree', 'remove', wt).returncode == 0:
            notes.append('worktree silindi' if notes else 'dəyişiklik yoxdur, worktree silindi')
        else:
            notes.append(f'worktree silinmədi: {wt}')
        return '; '.join(notes)
    except Exception as e:  # noqa
        return f'worktree yoxlanmadı ({e}): {wt}'


def acquire(base, token, cmd, path):
    """Proyekt üçün iş qovluğu: əsas qovluq boşdursa o, deyilsə git worktree; mümkün deyilsə əsas qovluq boşalana qədər gözlə.
    Qaytarır: (qovluq, worktree_məlumatı | None, None) və ya (None, None, səbəb)."""
    no_wt, waited, last = False, False, 0.0
    while True:
        with LOCK:
            if path not in PRIMARY:
                PRIMARY.add(path)
                return path, None, None
        if STOP.is_set():
            return None, None, 'Agent dayandırıldı, tapşırıq başlamadı. Yenidən göndər.'
        if not no_wt and can_worktree(path):
            w = wt_add(path, cmd['id'])
            if w:
                return w[0], w, None
            no_wt = True
        if not waited:
            waited = True
            print(f"   #{cmd['id']} gözləyir: bu proyektdə başqa iş gedir", flush=True)
            post_progress(base, token, cmd, Progress(path, 'Növbədə: bu proyektdə başqa iş gedir, bitməsi gözlənilir'))
        if time.time() - last >= 3:
            last = time.time()
            try:
                if call(base, token, 'poll', {'id': cmd['id']}).get('cancel'):
                    return None, None, 'Dayandırıldı.'
            except Exception:  # noqa
                pass
        STOP.wait(1)


WAIT = 20              # claim long-poll (saniyə); server cavabı tapşırıq gələn kimi qaytarır
KINDS = ['prompt', 'task', 'review', 'think', 'ops']   # bu agentin bildiyi növlər (server `kinds`-siz agentə ops vermir)
LONG = [False]         # son claim uzun gözlədisə (server long-poll bilir) → POLL yatmadan dərhal yenidən


def claim(base, token, payload):
    """claim + wait. Köhnə server `wait`-i bilmir və dərhal cavab verir: onda əsas dövrə köhnə 3 san-lıq yuxuya qayıdır."""
    t0 = time.time()
    try:
        return call(base, token, 'claim', {**payload, 'wait': WAIT, 'kinds': KINDS}, timeout=WAIT + 15)
    finally:
        LONG[0] = time.time() - t0 >= WAIT / 2


def run_one(base, token, claude):
    cmd = claim(base, token, {})
    if cmd.get('none'):
        return False
    handle_safe(base, token, claude, cmd)
    return True


def handle(base, token, claude, cmd):
    kind = cmd_kind(cmd)
    projects = read_projects()
    path = next((projects[n] for n in (cmd.get('names') or [cmd['project']]) if n in projects), None)
    print(f"[{time.strftime('%H:%M:%S')}] #{cmd['id']} {cmd['project']}" + ('' if kind == 'prompt' else f' [{kind}]') + f": {cmd['prompt'][:80]!r}", flush=True)
    if STOP.is_set():                                       # long-poll gözləyərkən dayandırıldı: başlama
        call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed', 'result': 'Agent dayandırıldı, tapşırıq başlamadı. Yenidən göndər.'})
        return
    if kind == 'ops':                                       # Claude promptu deyil: claude_ops.py
        out, status = run_ops(base, token, claude, cmd)
        call(base, token, 'finish', {'id': cmd['id'], 'status': status, 'result': out})
        print(f"   → #{cmd['id']} {status}" if WORKERS_N[0] > 1 else f'   → {status}', flush=True)
        return
    tmpdir = None
    if kind == 'think' and not path and not cmd.get('project'):   # proyektsiz düşüncə: boş müvəqqəti qovluqda
        path = tmpdir = tempfile.mkdtemp(prefix='claude-team-think-')
    if not path:
        call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed',
             'result': f"'{cmd['project']}' bu kompüterdə icazəli deyil. ~/.claude-team-projects siyahısına əlavə et."})
        return
    cmd['perm'] = cmd.get('perm') or 'acceptEdits'
    if cmd['perm'] not in perm_modes():
        call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed',
             'result': f"'{cmd['perm']}' rejimi bu kompüterdə icazəli deyil (CLAUDE_TEAM_MODES: {', '.join(perm_modes()) or 'heç biri'})."})
        return
    imgdir, extra = None, ()
    if cmd.get('images'):                                   # fayllı prompt: faylları endir, Claude-a yolunu ver (--add-dir ilə oxuya bilsin)
        try:
            imgdir, files = fetch_images(base, token, cmd['images'])
        except Exception as e:  # noqa
            call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed', 'result': f'Fayllar saytdan alınmadı: {e}'})
            return
        extra = ('--add-dir', imgdir)
        cmd['prompt'] += '\n\n[Göndərənin prompta əlavə etdiyi fayllar (Read aləti ilə bax): ' + ', '.join(files) + ']'
    if kind == 'think':                                     # yalnız oxuyur: növbə/worktree/snapşot lazım deyil
        try:
            out, status = run_think(base, token, claude, cmd, path, extra)
        finally:
            for d in (imgdir, tmpdir):
                if d:
                    shutil.rmtree(d, ignore_errors=True)
        call(base, token, 'finish', {'id': cmd['id'], 'status': status, 'result': out[-7500:]})
        print(f"   → #{cmd['id']} {status}" if WORKERS_N[0] > 1 else f'   → {status}', flush=True)
        return
    wt, held = None, False
    try:
        cwd, wt, why = acquire(base, token, cmd, path)
        held = cwd == path
        if why:
            out, status = why, 'failed'
        else:
            if wt:
                print(f"   #{cmd['id']} paralel: worktree {cwd}", flush=True)
                cmd['prompt'] += (f'\n\n[Qeyd: bu tapşırıq eyni proyektdəki başqa işlə paralel getdiyi üçün ayrıca git worktree-də işləyir: {cwd} '
                                  '(detached HEAD). Yalnız bu qovluqda işlə, budaq dəyişmə. Git-ə düşməyən fayllar (.env, node_modules və s.) burada yoxdur.]')
            elif cmd.get('mode') != 'plan':
                snapshot(cmd, path)                         # undo_last üçün: işdən əvvəlki vəziyyət
            out, status = run_claude(base, token, claude, cmd, path, extra, cwd)
            if wt:
                out += '\n\n⎇ Paralel iş ayrıca git worktree-də getdi: ' + wt_done(path, cwd, wt[1], cmd['id'])
    finally:
        if held:
            with LOCK:
                PRIMARY.discard(path)
        if imgdir:
            shutil.rmtree(imgdir, ignore_errors=True)
    call(base, token, 'finish', {'id': cmd['id'], 'status': status, 'result': out[-7500:]})
    print(f"   → #{cmd['id']} {status}" if WORKERS_N[0] > 1 else f'   → {status}', flush=True)


def handle_safe(base, token, claude, cmd):
    """Paralel işçi axını: xəta olsa belə tapşırıq "alınmadı" kimi bağlansın."""
    try:
        handle(base, token, claude, cmd)
    except Exception as e:  # noqa
        print(f"   #{cmd.get('id')} xəta: {e}", flush=True)
        try:
            call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed', 'result': f'Agent xətası: {e}'})
        except Exception:  # noqa
            pass


def claim_many(base, token, claude, free, threads):
    """Boş yer qədər tapşırıq götür (slots). Worktree mümkün olmayan və əsas qovluğu məşğul proyektləri ötür (skip)."""
    with LOCK:
        busy = set(PRIMARY)
    skip = sorted(n for n, p in read_projects().items() if p in busy and not can_worktree(p))
    r = claim(base, token, {'slots': free, 'skip': skip})
    items = r['items'] if isinstance(r.get('items'), list) else ([] if r.get('none') else [r])   # köhnə server: tək obyekt
    for cmd in items[:free]:
        t = threading.Thread(target=handle_safe, args=(base, token, claude, cmd), daemon=True)
        t.start()
        threads.append(t)
    return bool(items)


def single_instance():
    """Eyni anda yalnız bir agent işləsin (launcher düyməsinə təkrar basanda dublikat açılmasın)."""
    try:
        pid = int(open(PIDFILE).read().strip())
        os.kill(pid, 0)
        sys.exit(f'Agent artıq işləyir (pid {pid}).')
    except (FileNotFoundError, ValueError, ProcessLookupError):
        pass
    except PermissionError:
        sys.exit('Agent artıq işləyir.')
    with open(PIDFILE, 'w') as f:
        f.write(str(os.getpid()))
    atexit.register(lambda: os.path.exists(PIDFILE) and os.unlink(PIDFILE))


def on_signal(sig, frm):
    """Ctrl+C / SIGTERM: yeni tapşırıq götürmə, işləyənləri GRACE san gözlə. İkinci siqnal: dərhal dayandır."""
    if STOP.is_set():
        HARD.set()
        msg = 'Dərhal dayanır: işləyən tapşırıqlar dayandırılır.'
    else:
        STOP_AT[0] = time.time()
        STOP.set()
        msg = f'Dayanır: yeni tapşırıq götürülmür, işləyənlər {GRACE} san gözlənilir (yenidən Ctrl+C — dərhal).'
    try:
        print(msg, flush=True)
    except Exception:  # noqa
        pass


def main():
    base, token = read_conf()
    claude = find_claude()
    once = '--once' in sys.argv
    n = WORKERS_N[0] = 1 if once else workers()
    if not once:
        single_instance()
    WARM_ON[0] = not once and warm_enabled()
    atexit.register(warm_kill_all)
    threading.Thread(target=discover, args=(claude, not once and profile() in ('ops', 'full')), daemon=True).start()
    signal.signal(signal.SIGTERM, on_signal)
    signal.signal(signal.SIGINT, on_signal)
    last_ping, last_auth, logged_in, warned, threads = 0, 0, True, False, []
    prof = profile()
    print(f'Agent işləyir. Proyektlər: {", ".join(read_projects()) or "(yoxdur)"}  |  {base}' + (f'  |  {n} paralel işçi' if n > 1 else '')
          + ('' if prof == 'basic' else f'  |  profil {prof}, git {git_level()}') + ('  |  isti sessiyalar' if WARM_ON[0] else ''), flush=True)
    while not STOP.is_set():
        try:
            LONG[0] = False
            warm_reap()
            now = time.time()
            if now - last_ping > 10:                           # ürək döyüntüsü: panel 30 san səssizlikdən sonra oflayn sayır
                last_ping = now
                # `claude auth status` ayrıca proses açır (ağırdır): girişlidirsə 60 saniyədən bir, deyilsə 10 saniyədən bir yoxla
                if now - last_auth > (60 if logged_in else 10):
                    last_auth = now
                    logged_in = auth_ok(claude)
                call(base, token, 'ping', {'claude_ok': logged_in, 'projects': sorted(read_projects()), 'workers': n, 'profile': profile(),
                                           'caps': {'claude_version': CAPS['claude_version'], 'mcp': CAPS['mcp'], 'warm': warm_names(), 'workers': n}})
                if not logged_in and not warned:
                    warned = True
                    print('Claude girişi yoxdur: tapşırıq götürülmür. Terminalda `~/.local/bin/claude` yaz və /login et.', flush=True)
                if logged_in and warned:
                    warned = False
                    print('Claude girişi var, tapşırıqlar götürülür.', flush=True)
            if not logged_in and not once:
                STOP.wait(POLL)
                continue
            if n == 1:
                got = run_one(base, token, claude)
                if once:
                    return
            else:
                threads[:] = [t for t in threads if t.is_alive()]
                got = len(threads) < n and claim_many(base, token, claude, n - len(threads), threads)
                if len(threads) >= n:
                    STOP.wait(1)
                    continue
            if got or LONG[0]:                                 # long-poll artıq gözlədi: yatmadan yenidən soruş
                continue
        except urllib.error.HTTPError as e:
            print('Server xətası:', e.code, flush=True)
            if e.code == 401:
                STOP_AT[0] = STOP_AT[0] or time.time()
                STOP.set()
                shutdown(threads)
                sys.exit('Token yanlışdır.')
        except Exception as e:  # şəbəkə kəsilməsi və s.
            print('Bağlantı xətası:', e, flush=True)
        STOP.wait(POLL)
    shutdown(threads)


def shutdown(threads):
    """Paralel işçiləri gözlə: GRACE ərzində bitməyənləri dayandır (exec_claude özü öldürüb "alınmadı" yazır)."""
    alive = [t for t in threads if t.is_alive()]
    if not alive:
        warm_kill_all()
        return
    print(f'{len(alive)} tapşırıq bitməsi gözlənilir…', flush=True)
    for t in alive:
        t.join(max(0.0, STOP_AT[0] + GRACE - time.time()))
    HARD.set()
    for t in alive:
        t.join(30)
    warm_kill_all()


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        pass
