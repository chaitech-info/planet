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

İstifadə:  python3 claude-team-agent.py          (davamlı işləyir, Ctrl+C ilə dayandır)
           python3 claude-team-agent.py --once   (bir tapşırıq götürüb çıxır)
"""
import atexit, json, os, shlex, shutil, signal, subprocess, sys, tempfile, threading, time, urllib.error, urllib.parse, urllib.request

CONF = os.environ.get('CLAUDE_TEAM_CONF', os.path.expanduser('~/.claude-team-dash.conf'))
PROJECTS = os.environ.get('CLAUDE_TEAM_PROJECTS', os.path.expanduser('~/.claude-team-projects'))
TIMEOUT = int(os.environ.get('CLAUDE_TEAM_TIMEOUT', '1200'))  # bir tapşırıq üçün maksimum saniyə
POLL = 3
PIDFILE = os.environ.get('CLAUDE_TEAM_PID', os.path.expanduser('~/.claude-team-agent.pid'))   # ikinci (məs. lokal test) agent üçün ayrıca
ENVFILE = os.path.expanduser('~/.claude-team-agent.env')   # ehtiyat: CLAUDE_CODE_OAUTH_TOKEN=... (chmod 600)
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
    """CLI girişi mühitdən görünmürsə (məs. launcher-dən başlayanda), tokeni bu fayldan götür. Yalnız bu 2 açar."""
    out = {}
    try:
        with open(ENVFILE) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    if k.strip() in ('CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY', 'CLAUDE_TEAM_GIT'):
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


def call(base, token, action, payload=None):
    req = urllib.request.Request(
        f'{base}?a={action}', data=json.dumps(payload or {}).encode(), method='POST',
        headers={'X-Team-Token': token, 'Content-Type': 'application/json'})
    with urllib.request.urlopen(req, timeout=20) as r:
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


SESSIONS = os.path.expanduser('~/.claude-team-sessions.json')   # proyekt+göndərən → claude sessiya nömrəsi (söhbət davam etsin)
SESSION_TTL = 24 * 3600


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


def exec_claude(base, token, args, cmd, path):
    """Bir claude prosesini işlədir; "Dayandır" və vaxt limiti izlənir. Qaytarır: (out, err, returncode) və ya (None, mesaj, 'end')."""
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
    th = threading.Thread(target=work, daemon=True)
    th.start()
    t0, last = time.time(), 0.0
    while th.is_alive():
        th.join(1)
        now = time.time()
        if now - t0 > TIMEOUT:
            kill_group(proc); th.join(5)
            return None, f'Vaxt bitdi ({TIMEOUT} san).', 'end'
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


def run_claude(base, token, claude, cmd, path, extra_args=()):
    """claude -p işlədir. Eyni proyekt və göndərən üçün əvvəlki söhbəti davam etdirir (--resume); "Yeni söhbət" seçilibsə sıfırdan."""
    key = path + '|' + (cmd.get('creator') or '')
    store = sess_load()
    sid = None if cmd.get('fresh') else (store.get(key) or {}).get('sid')
    base_args = [claude, '-p', '--output-format', 'json', '--permission-mode', 'acceptEdits', '--allowedTools', allowed_tools(), '--disallowedTools', GIT_DENY] + list(extra_args)
    out, err, rc = exec_claude(base, token, base_args + (['--resume', sid] if sid else []), cmd, path)
    if rc == 'end':
        return err, 'failed'
    text, new_sid, is_err = parse_result(out)
    if sid and (rc != 0 or is_err or text is None):            # köhnə söhbət tapılmadı/xəta: sıfırdan bir də cəhd et
        out, err, rc = exec_claude(base, token, base_args, cmd, path)
        if rc == 'end':
            return err, 'failed'
        text, new_sid, is_err = parse_result(out)
        sid = None
    if new_sid:
        store[key] = {'sid': new_sid, 'ts': time.time()}
        sess_save(store)
    if text is None:
        text = out.strip() or err.strip() or '(boş cavab)'
    elif not text.strip():
        text = err.strip() or '(boş cavab)'
    head = '' if (not sid or cmd.get('fresh')) else '↻ əvvəlki söhbət davam etdi\n\n'
    return head + text, ('done' if rc == 0 and not is_err else 'failed')


def run_one(base, token, claude):
    cmd = call(base, token, 'claim')
    if cmd.get('none'):
        return False
    projects = read_projects()
    path = next((projects[n] for n in (cmd.get('names') or [cmd['project']]) if n in projects), None)
    print(f"[{time.strftime('%H:%M:%S')}] #{cmd['id']} {cmd['project']}: {cmd['prompt'][:80]!r}", flush=True)
    if not path:
        call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed',
             'result': f"'{cmd['project']}' bu kompüterdə icazəli deyil. ~/.claude-team-projects siyahısına əlavə et."})
        return True
    imgdir, extra = None, ()
    if cmd.get('images'):                                   # şəkilli prompt: faylları endir, Claude-a yolunu ver (--add-dir ilə oxuya bilsin)
        try:
            imgdir, files = fetch_images(base, token, cmd['images'])
        except Exception as e:  # noqa
            call(base, token, 'finish', {'id': cmd['id'], 'status': 'failed', 'result': f'Şəkillər saytdan alınmadı: {e}'})
            return True
        extra = ('--add-dir', imgdir)
        cmd['prompt'] += '\n\n[Göndərənin prompta əlavə etdiyi şəkillər (Read aləti ilə bax): ' + ', '.join(files) + ']'
    try:
        out, status = run_claude(base, token, claude, cmd, path, extra)
    finally:
        if imgdir:
            shutil.rmtree(imgdir, ignore_errors=True)
    call(base, token, 'finish', {'id': cmd['id'], 'status': status, 'result': out[-7500:]})
    print(f'   → {status}', flush=True)
    return True


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


def main():
    base, token = read_conf()
    claude = find_claude()
    once = '--once' in sys.argv
    if not once:
        single_instance()
    last_auth, logged_in, warned = 0, True, False
    print(f'Agent işləyir. Proyektlər: {", ".join(read_projects()) or "(yoxdur)"}  |  {base}', flush=True)
    while True:
        try:
            if time.time() - last_auth > 20:
                last_auth = time.time()
                logged_in = auth_ok(claude)
                call(base, token, 'ping', {'claude_ok': logged_in, 'projects': sorted(read_projects())})
                if not logged_in and not warned:
                    warned = True
                    print('Claude girişi yoxdur: tapşırıq götürülmür. Terminalda `~/.local/bin/claude` yaz və /login et.', flush=True)
                if logged_in and warned:
                    warned = False
                    print('Claude girişi var, tapşırıqlar götürülür.', flush=True)
            if not logged_in and not once:
                time.sleep(POLL)
                continue
            got = run_one(base, token, claude)
            if once:
                return
            if got:
                continue
        except urllib.error.HTTPError as e:
            print('Server xətası:', e.code, flush=True)
            if e.code == 401:
                sys.exit('Token yanlışdır.')
        except Exception as e:  # şəbəkə kəsilməsi və s.
            print('Bağlantı xətası:', e, flush=True)
        time.sleep(POLL)


if __name__ == '__main__':
    try:
        main()
    except KeyboardInterrupt:
        pass
