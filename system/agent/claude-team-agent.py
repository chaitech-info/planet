#!/usr/bin/env python3
"""Komanda dashboard agenti.

Saytdakı növbədən YALNIZ SƏNİN tokenin üçün göndərilən promptları götürür və
həmin proyektin qovluğunda `claude -p` ilə işlədir. Yalnız ~/.claude-team-projects
siyahısındakı proyektlərə icazə verilir. Fayl redaktəsi və oxuma xarakterli git əmrləri (status, log, diff, show, branch) icazəlidir, qalan terminal əmrləri yox.

İstifadə:  python3 claude-team-agent.py          (davamlı işləyir, Ctrl+C ilə dayandır)
           python3 claude-team-agent.py --once   (bir tapşırıq götürüb çıxır)
"""
import atexit, json, os, shlex, shutil, signal, subprocess, sys, threading, time, urllib.request, urllib.error

CONF = os.environ.get('CLAUDE_TEAM_CONF', os.path.expanduser('~/.claude-team-dash.conf'))
PROJECTS = os.environ.get('CLAUDE_TEAM_PROJECTS', os.path.expanduser('~/.claude-team-projects'))
TIMEOUT = int(os.environ.get('CLAUDE_TEAM_TIMEOUT', '1200'))  # bir tapşırıq üçün maksimum saniyə
POLL = 3
PIDFILE = os.environ.get('CLAUDE_TEAM_PID', os.path.expanduser('~/.claude-team-agent.pid'))   # ikinci (məs. lokal test) agent üçün ayrıca
ENVFILE = os.path.expanduser('~/.claude-team-agent.env')   # ehtiyat: CLAUDE_CODE_OAUTH_TOKEN=... (chmod 600)
# Fayl redaktəsinə əlavə: yalnız OXUMA xarakterli git əmrləri. commit, push, pull, reset, checkout və s. bloklu qalır.
READONLY_GIT = ','.join([
    'Bash(git status:*)', 'Bash(git log:*)', 'Bash(git diff:*)', 'Bash(git show:*)', 'Bash(git rev-list:*)',
    'Bash(git branch)', 'Bash(git branch -a)', 'Bash(git branch -vv)', 'Bash(git branch --show-current)', 'Bash(git remote -v)',
])


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
                    if k.strip() in ('CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'):
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


def run_claude(base, token, claude, cmd, path):
    """claude -p işlədir; sayt "Dayandır" göndərsə (hər 3 san yoxlanır) prosesi öldürür."""
    env = {**os.environ, **load_env(), 'CLAUDE_TEAM_RUN': str(cmd['id'])}
    try:
        proc = subprocess.Popen([claude, '-p', '--permission-mode', 'acceptEdits', '--allowedTools', READONLY_GIT],
                                stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd=path, env=env,
                                start_new_session=True)
    except Exception as e:  # noqa
        return f'Agent xətası: {e}', 'failed'
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
            return f'Vaxt bitdi ({TIMEOUT} san).', 'failed'
        if now - last >= 3:
            last = now
            try:
                if call(base, token, 'poll', {'id': cmd['id']}).get('cancel'):
                    kill_group(proc); th.join(5)
                    return 'Dayandırıldı.', 'failed'
            except Exception:  # noqa
                pass
    if 'exc' in box:
        return f"Agent xətası: {box['exc']}", 'failed'
    out = (box.get('out') or '').strip() or (box.get('err') or '').strip() or '(boş cavab)'
    return out, ('done' if proc.returncode == 0 else 'failed')


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
    out, status = run_claude(base, token, claude, cmd, path)
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
