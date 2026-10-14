#!/usr/bin/env python3
"""Komanda dashboard agenti.

Saytdakı növbədən YALNIZ SƏNİN tokenin üçün göndərilən promptları götürür və
həmin proyektin qovluğunda `claude -p` ilə işlədir. Yalnız ~/.claude-team-projects
siyahısındakı proyektlərə icazə verilir. Fayl redaktəsi və git əmrləri icazəlidir (səviyyəyə görə, aşağıya bax), qalan terminal əmrləri yox.
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

İstifadə:  python3 claude-team-agent.py          (davamlı işləyir, Ctrl+C ilə dayandır)
           python3 claude-team-agent.py --once   (bir tapşırıq götürüb çıxır, həmişə 1 işçi)
"""
import atexit, json, os, shlex, shutil, signal, subprocess, sys, tempfile, threading, time, urllib.error, urllib.parse, urllib.request

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


def git_level():
    v = (os.environ.get('CLAUDE_TEAM_GIT') or load_env().get('CLAUDE_TEAM_GIT') or 'safe').strip().lower()
    return v if v in ('read', 'safe', 'full') else 'safe'


def workers():
    """Eyni anda neçə tapşırıq (1..4). Yalnız kompüterin sahibi seçir."""
    try:
        n = int((os.environ.get('CLAUDE_TEAM_WORKERS') or load_env().get('CLAUDE_TEAM_WORKERS') or '1').strip())
    except ValueError:
        n = 1
    return max(1, min(WORKERS_MAX, n))


def allowed_tools():
    lv = git_level()
    return ','.join([READONLY_GIT] + (GIT_SAFE if lv in ('safe', 'full') else []) + (GIT_FULL if lv == 'full' else []))


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
                    if k.strip() in ('CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY', 'CLAUDE_TEAM_GIT', 'CLAUDE_TEAM_WORKERS'):
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
            p = os.path.join(d, f'sekil-{i}{os.path.splitext(str(name))[1] or ".png"}')
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
        except ProcessLookupError:
            return
        for _ in range(30):
            if proc.poll() is not None:
                return
            time.sleep(.1)


SESSIONS = os.environ.get('CLAUDE_TEAM_SESSIONS', os.path.expanduser('~/.claude-team-sessions.json'))   # proyekt+göndərən → claude sessiya nömrəsi (söhbət davam etsin)
SESSION_TTL = 24 * 3600
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

    def rel(self, p):
        p = str(p)
        return p[len(self.cwd):] if p.startswith(self.cwd) else p

    def set(self, step):
        self.step, self.dirty = ' '.join(str(step).split())[:160], True

    def tool(self, name, inp):
        inp = inp if isinstance(inp, dict) else {}
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


def exec_claude(base, token, args, cmd, path, prog=None):
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
    t0, last = time.time(), 0.0
    while th.is_alive():
        th.join(1)
        now = time.time()
        if now - t0 > TIMEOUT:
            kill_group(proc); th.join(5)
            return None, f'Vaxt bitdi ({TIMEOUT} san).', 'end'
        if stopping_hard():
            kill_group(proc); th.join(5)
            return None, STOP_MSG, 'end'
        if prog and prog.dirty and now - prog.last >= 2 and th.is_alive():
            post_progress(base, token, cmd, prog)
        if now - last >= 3:
            last = now
            try:
                if call(base, token, 'poll', {'id': cmd['id']}).get('cancel'):
                    kill_group(proc); th.join(5)
                    return None, 'Dayandırıldı.', 'end'
            except Exception:  # noqa
                pass
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


def run_claude(base, token, claude, cmd, path, extra_args=(), cwd=None):
    """claude -p işlədir. Eyni proyekt və göndərən üçün əvvəlki söhbəti davam etdirir (--resume); "Yeni söhbət" seçilibsə sıfırdan.
    cwd: paralel iş üçün git worktree (onda söhbət davam etdirilmir və saxlanmır, çünki sessiya qovluğa bağlıdır)."""
    wt = bool(cwd and cwd != path)
    run_dir = cwd or path
    key = path + '|' + (cmd.get('creator') or '')
    sid = None if (cmd.get('fresh') or wt) else (sess_load().get(key) or {}).get('sid')
    mode = ['stream' if STREAM else 'json']
    tail = ['--permission-mode', 'acceptEdits', '--allowedTools', allowed_tools(), '--disallowedTools', GIT_DENY] + list(extra_args)

    def go(with_sid):
        """Qaytarır: (out, err, rc, mətn, sessiya, xəta_bayrağı)."""
        res = ['--resume', with_sid] if with_sid else []
        if mode[0] == 'json':
            out, err, rc = exec_claude(base, token, [claude, '-p', '--output-format', 'json'] + tail + res, cmd, run_dir)
            return (out, err, rc) + ((None, None, False) if rc == 'end' else parse_result(out))
        prog = Progress(run_dir, '⎇ worktree · Claude başlayır' if wt else 'Claude başlayır')
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
    out, err, rc, text, new_sid, is_err = go(sid)
    if rc == 'end':
        return err, 'failed'
    if sid and (rc != 0 or is_err or text is None):            # köhnə söhbət tapılmadı/xəta: sıfırdan bir də cəhd et
        out, err, rc, text, new_sid, is_err = go(None)
        if rc == 'end':
            return err, 'failed'
        sid = None
    if new_sid and not wt:
        with SESS_LOCK:
            store = sess_load()
            store[key] = {'sid': new_sid, 'ts': time.time()}
            sess_save(store)
    if text is None:
        text = out.strip() or err.strip() or '(boş cavab)'
    elif not text.strip():
        text = err.strip() or '(boş cavab)'
    head = '' if (not sid or cmd.get('fresh')) else '↻ əvvəlki söhbət davam etdi\n\n'
    return head + text, ('done' if rc == 0 and not is_err else 'failed')


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


def run_one(base, token, claude):
    cmd = call(base, token, 'claim')
    if cmd.get('none'):
        return False
    handle(base, token, claude, cmd)
    return True


def handle(base, token, claude, cmd):
    projects = read_projects()
    path = next((projects[n] for n in (cmd.get('names') or [cmd['project']]) if n in projects), None)
    print(f"[{time.strftime('%H:%M:%S')}] #{cmd['id']} {cmd['project']}: {cmd['prompt'][:80]!r}", flush=True)
    if not path:
        call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed',
             'result': f"'{cmd['project']}' bu kompüterdə icazəli deyil. ~/.claude-team-projects siyahısına əlavə et."})
        return
    imgdir, extra = None, ()
    if cmd.get('images'):                                   # şəkilli prompt: faylları endir, Claude-a yolunu ver (--add-dir ilə oxuya bilsin)
        try:
            imgdir, files = fetch_images(base, token, cmd['images'])
        except Exception as e:  # noqa
            call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed', 'result': f'Şəkillər saytdan alınmadı: {e}'})
            return
        extra = ('--add-dir', imgdir)
        cmd['prompt'] += '\n\n[Göndərənin prompta əlavə etdiyi şəkillər (Read aləti ilə bax): ' + ', '.join(files) + ']'
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
    r = call(base, token, 'claim', {'slots': free, 'skip': skip})
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
    signal.signal(signal.SIGTERM, on_signal)
    signal.signal(signal.SIGINT, on_signal)
    last_auth, logged_in, warned, threads = 0, True, False, []
    print(f'Agent işləyir. Proyektlər: {", ".join(read_projects()) or "(yoxdur)"}  |  {base}' + (f'  |  {n} paralel işçi' if n > 1 else ''), flush=True)
    while not STOP.is_set():
        try:
            if time.time() - last_auth > 10:                   # ürək döyüntüsü: panel 30 san səssizlikdən sonra oflayn sayır
                last_auth = time.time()
                logged_in = auth_ok(claude)
                call(base, token, 'ping', {'claude_ok': logged_in, 'projects': sorted(read_projects()), 'workers': n})
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
            if got:
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
        return
    print(f'{len(alive)} tapşırıq bitməsi gözlənilir…', flush=True)
    for t in alive:
        t.join(max(0.0, STOP_AT[0] + GRACE - time.time()))
    HARD.set()
    for t in alive:
        t.join(30)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        pass
