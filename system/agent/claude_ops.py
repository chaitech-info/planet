#!/usr/bin/env python3
"""Claude Code əməliyyatları (ops): agent `kind = 'ops'` komandasını bura ötürür.

handle(op, args, ctx) -> dict. Heç vaxt istisna atmır: xəta → {'ok': False, 'error': '…'}.
ctx = {'project_path', 'projects' (ad → yol), 'profile' (basic|dev|ops|full), 'git_level' (read|safe|full), 'claude_bin'}.
Yalnız allowlist-dəki op-lar; 🔴 op-lar profilə görə yenidən yoxlanır. Nəticə ≤ 8 KB JSON, gizli məlumat (token, açar,
.env, OAuth, MCP env/header dəyərləri, URL query) maskalanır. Yalnız Python stdlib.
"""
import datetime, glob, hashlib, json, os, re, shutil, signal, subprocess, tempfile, time

LIMIT = 8000                                 # nəticə JSON-un maksimum ölçüsü (bayt)
LEVELS = {'basic': 0, 'dev': 1, 'ops': 2, 'full': 3}
# op → (minimum profil, proyekt lazımdır?, 🔴?)
OPS = {
    'claude_info': ('basic', False, False),
    'mcp_list': ('basic', True, False),
    'config': ('basic', True, False),
    'usage': ('basic', False, False),
    'transcript': ('basic', True, False),
    'health': ('basic', True, False),
    'pr_view': ('basic', True, False),
    'worktree_list': ('basic', True, False),
    'pr_merge': ('ops', True, True),
    'mcp_add': ('ops', True, True),
    'allow_rule': ('ops', True, True),
    'claude_md_append': ('dev', True, True),
    'claude_update': ('dev', False, False),
    'claude_doctor': ('dev', False, False),
    'sessions_clean': ('dev', False, True),
    'worktree_new': ('dev', True, False),
    'undo_last': ('dev', True, True),
}


def home(*p):
    return os.path.join(os.path.expanduser('~'), *p)


# ---------- gizli məlumatın maskalanması ----------
_SECRET_RX = [
    (re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)', re.S), '***PRIVATE KEY***'),
    (re.compile(r'\bsk-ant-[A-Za-z0-9_\-]{8,}'), 'sk-ant-***'),
    (re.compile(r'\bsk-[A-Za-z0-9_\-]{20,}'), 'sk-***'),
    (re.compile(r'\b(gh[pousr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})'), 'gh_***'),
    (re.compile(r'\bxox[abprs]-[A-Za-z0-9\-]{10,}'), 'xox-***'),
    (re.compile(r'\bAKIA[0-9A-Z]{16}\b'), 'AKIA***'),
    (re.compile(r'\bAIza[0-9A-Za-z_\-]{30,}'), 'AIza***'),
    (re.compile(r'\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}'), 'jwt***'),
    (re.compile(r'(?i)\b(bearer|basic|token)\s+[A-Za-z0-9._~+/=\-]{12,}'), r'\1 ***'),
    (re.compile(r'(?i)(authorization|x-api-key|api-key|cookie)\s*:\s*[^\s,;"\']+'), r'\1: ***'),
    (re.compile(r'(?i)\b([A-Z0-9_\-]*(?:token|secret|passw(?:or)?d|api_?key|access_?key|private_?key|client_?secret|credential)[A-Z0-9_\-]*)(["\']?\s*[=:]\s*["\']?)[^\s"\',;&]{3,}'), r'\1\2***'),
    (re.compile(r'(?i)\b(https?|wss?)://[^/\s:@]+:[^/\s@]+@'), r'\1://***@'),
    (re.compile(r'(?i)\b((?:https?|wss?)://[^\s?#"\'<>]+)\?[^\s"\'<>]*'), r'\1?***'),
]
_SECRET_KEY = re.compile(r'(?i)(token|secret|passw|api_?key|apikey|authorization|cookie|credential|private_?key|oauth)')


def redact(s):
    if not isinstance(s, str) or not s:
        return s
    for rx, rep in _SECRET_RX:
        s = rx.sub(rep, s)
    return s


def scrub(x):
    """Bütün nəticəni gəzir: sətirlərdə gizli nümunələri, gizli adlı açarların dəyərlərini maskalayır."""
    if isinstance(x, dict):
        return {k: ('***' if isinstance(k, str) and isinstance(v, str) and v and _SECRET_KEY.search(k) else scrub(v)) for k, v in x.items()}
    if isinstance(x, list):
        return [scrub(v) for v in x]
    if isinstance(x, str):
        return redact(x)
    return x


def has_secret(s):
    return redact(s) != s


# ---------- ölçü limiti ----------
def _size(x):
    return len(json.dumps(x, ensure_ascii=False, default=str).encode())


def fit(d, limit=LIMIT):
    """JSON ≤ limit olana qədər ən böyük siyahını/sətri qısaldır."""
    if _size(d) <= limit:
        return d
    d = json.loads(json.dumps(d, ensure_ascii=False, default=str))
    for _ in range(200):
        if _size(d) <= limit:
            d['truncated'] = True
            return d
        best = [None, None, 0]               # (valideyn, açar, ölçü)

        def walk(node):
            items = node.items() if isinstance(node, dict) else enumerate(node)
            for k, v in items:
                if isinstance(v, (list, str)) or isinstance(v, dict):
                    sz = _size(v)
                    if isinstance(v, (list, str)) and sz > best[2] and (len(v) > 1):
                        best[0], best[1], best[2] = node, k, sz
                    if isinstance(v, (list, dict)):
                        walk(v)
        walk(d)
        if best[0] is None:
            break
        v = best[0][best[1]]
        best[0][best[1]] = v[:max(1, len(v) // 2)] if isinstance(v, list) else v[:max(1, len(v) // 2)] + '…'
    j = json.dumps(d, ensure_ascii=False, default=str)
    return {'ok': d.get('ok', False), 'op': d.get('op'), 'truncated': True, 'head': j[:limit - 200]}


# ---------- prosesler ----------
def run(cmd, cwd=None, timeout=60, env=None, inp=None):
    """Alt-proses: stdin bağlı, sərt vaxt limiti (proses qrupu öldürülür). Qaytarır: (kod, stdout, stderr); vaxt bitəndə kod = -9."""
    e = {**os.environ, 'GH_PROMPT_DISABLED': '1', 'GIT_TERMINAL_PROMPT': '0', 'NO_COLOR': '1', 'CI': '1', **(env or {})}
    try:
        p = subprocess.Popen(cmd, cwd=cwd, stdin=subprocess.PIPE if inp is not None else subprocess.DEVNULL,
                             stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=e, start_new_session=True)
    except (OSError, ValueError) as ex:
        return 127, '', str(ex)
    try:
        out, err = p.communicate(inp, timeout=timeout)
        return p.returncode, out or '', err or ''
    except subprocess.TimeoutExpired:
        try:
            os.killpg(p.pid, signal.SIGKILL)
        except Exception:  # noqa
            p.kill()
        try:
            out, err = p.communicate(timeout=5)
        except Exception:  # noqa
            out, err = '', ''
        return -9, out or '', (err or '') + f'\n[vaxt bitdi: {timeout} s]'


def git(path, *a, timeout=30):
    return run(['git', '-C', path] + list(a), timeout=timeout)


def tail(s, n=1500):
    s = re.sub(r'\x1b\[[0-9;?]*[A-Za-z]', '', s or '').strip().replace(os.path.expanduser('~'), '~')
    return s if len(s) <= n else '…' + s[-n:]


def claude_env():
    """Agent launcher-dən başlayanda CLI girişi ~/.claude-team-agent.env-də ola bilər. Dəyərlər heç vaxt qaytarılmır."""
    out = {}
    try:
        with open(os.environ.get('CLAUDE_TEAM_ENV', home('.claude-team-agent.env'))) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    if k.strip() in ('CLAUDE_CODE_OAUTH_TOKEN', 'ANTHROPIC_API_KEY'):
                        out[k.strip()] = v.strip().strip('"\'')
    except OSError:
        pass
    return out


def claude_bin(ctx):
    for c in (ctx.get('claude_bin'), os.environ.get('CLAUDE_BIN'), shutil.which('claude'), home('.local/bin/claude'),
              '/usr/local/bin/claude', '/opt/homebrew/bin/claude'):
        if c and os.path.exists(c):
            return c
    return None


def gh_ok(path):
    if not shutil.which('gh'):
        return False, 'gh quraşdırılmayıb'
    c, _, _ = run(['gh', 'auth', 'status'], cwd=path, timeout=10)
    return (True, '') if c == 0 else (False, 'gh girişli deyil')


def read_json(p):
    try:
        with open(p) as f:
            d = json.load(f)
        return d if isinstance(d, dict) else {}
    except (OSError, ValueError):
        return {}


def mtime(f):
    try:
        return os.path.getmtime(f)
    except OSError:
        return 0.0


def proj_dirs(path):
    base = home('.claude', 'projects')
    out = []
    for p in {path, os.path.realpath(path)}:
        d = os.path.join(base, re.sub(r'[^A-Za-z0-9]', '-', p))
        if os.path.isdir(d) and d not in out:
            out.append(d)
    return out


# ---------- oxuma op-ları ----------
def op_claude_info(a, ctx, path):
    cb = claude_bin(ctx)
    if not cb:
        return {'ok': False, 'error': 'claude tapılmadı'}
    c, out, err = run([cb, '--version'], timeout=20)
    ver = (out or err).strip().split()[0] if (out or err).strip() else ''
    r = {'ok': c == 0, 'version': ver, 'bin': cb.replace(os.path.expanduser('~'), '~')}
    c, out, _ = run([cb, 'auth', 'status', '--json'], timeout=20, env=claude_env())
    try:
        r['logged_in'] = bool(json.loads(out or '{}').get('loggedIn'))
    except ValueError:
        r['logged_in'] = None
    latest = ''
    if shutil.which('npm'):
        c, out, _ = run(['npm', 'view', '@anthropic-ai/claude-code', 'version'], timeout=10)
        latest = out.strip().splitlines()[-1].strip() if c == 0 and out.strip() else ''
    r['latest'] = latest
    try:
        r['update_available'] = bool(latest and ver and tuple(int(x) for x in re.findall(r'\d+', latest)[:3]) > tuple(int(x) for x in re.findall(r'\d+', ver)[:3]))
    except ValueError:
        r['update_available'] = None
    s = read_json(home('.claude', 'settings.json'))
    r['default_model'] = s.get('model') or os.environ.get('ANTHROPIC_MODEL') or 'default'
    return r


_MCP_LINE = re.compile(r'^(.+?): (.*?)(?: \((HTTP|SSE|stdio)\))? - (.+)$')


def mcp_target(t, transport):
    t = t.strip()
    m = re.match(r'(?i)^((?:https?|wss?)://[^/\s?#]+)', t)
    if m:
        return m.group(1)
    return os.path.basename(t.split()[0]) if t else ''   # stdio: yalnız əmrin adı, arqumentlər yox (gizli ola bilər)


def op_mcp_list(a, ctx, path):
    cb = claude_bin(ctx)
    if not cb:
        return {'ok': False, 'error': 'claude tapılmadı'}
    c, out, err = run([cb, 'mcp', 'list'], cwd=path, timeout=60, env=claude_env())
    servers = []
    for line in out.splitlines():
        m = _MCP_LINE.match(line.strip())
        if not m:
            continue
        name, target, tr, st = m.groups()
        tr = (tr or ('http' if re.match(r'(?i)^https?://', target) else 'stdio')).lower()
        stl = st.lower()
        status = 'connected' if 'connected' in stl and 'fail' not in stl else 'failed' if 'fail' in stl else \
            'needs_auth' if 'auth' in stl else 'pending' if 'pending' in stl else 'disabled' if 'disabl' in stl else re.sub(r'[^\w ]', '', st).strip()[:30]
        servers.append({'name': name.strip()[:80], 'transport': tr, 'target': mcp_target(target, tr), 'status': status})
    if c != 0 and not servers:
        return {'ok': False, 'error': 'claude mcp list alınmadı', 'tail': tail(err, 400)}
    return {'ok': True, 'count': len(servers), 'servers': servers}


def _names(d, kind):
    out = []
    try:
        for e in sorted(os.listdir(d)):
            if e.startswith('.'):
                continue
            p = os.path.join(d, e)
            if kind == 'skills' and os.path.isdir(p):
                out.append(e)
            elif kind != 'skills' and e.endswith('.md'):
                out.append(e[:-3])
            elif kind == 'commands' and os.path.isdir(p):      # alt qovluq: namespace:command
                out += [f'{e}:{x[:-3]}' for x in sorted(os.listdir(p)) if x.endswith('.md')]
    except OSError:
        pass
    return out[:60]


def _settings_view(p):
    s = read_json(p)
    if not s:
        return None
    perm = s.get('permissions') if isinstance(s.get('permissions'), dict) else {}
    hooks = s.get('hooks') if isinstance(s.get('hooks'), dict) else {}
    return {k: v for k, v in {
        'allow': [str(x)[:160] for x in (perm.get('allow') or [])][:60],
        'deny': [str(x)[:160] for x in (perm.get('deny') or [])][:60],
        'ask': [str(x)[:160] for x in (perm.get('ask') or [])][:30],
        'default_mode': perm.get('defaultMode'),
        'hooks': sorted(hooks.keys()),
        'model': s.get('model'),
        'env_keys': sorted((s.get('env') or {}).keys()) if isinstance(s.get('env'), dict) else [],   # yalnız adlar
        'plugins': sorted(k for k, v in (s.get('enabledPlugins') or {}).items() if v) if isinstance(s.get('enabledPlugins'), dict) else [],
    }.items() if v not in (None, [], '')}


def head_file(p, n):
    try:
        with open(p, 'rb') as f:
            b = f.read(n + 1)
        s = b[:n].decode('utf-8', 'ignore')
        return s + ('…' if len(b) > n else '')
    except OSError:
        return None


def op_config(a, ctx, path):
    r = {'ok': True, 'settings': {}}
    for label, p in (('user', home('.claude', 'settings.json')), ('project', os.path.join(path, '.claude', 'settings.json')),
                     ('local', os.path.join(path, '.claude', 'settings.local.json'))):
        v = _settings_view(p)
        if v is not None:
            r['settings'][label] = v
    md = head_file(os.path.join(path, 'CLAUDE.md'), 2048)
    if md is None:
        md = head_file(os.path.join(path, '.claude', 'CLAUDE.md'), 2048)
    r['claude_md'] = md
    r['user_claude_md'] = head_file(home('.claude', 'CLAUDE.md'), 600)
    for kind in ('skills', 'agents', 'commands'):
        r[kind] = {'user': _names(home('.claude', kind), kind), 'project': _names(os.path.join(path, '.claude', kind), kind)}
    r['mcp_json'] = sorted((read_json(os.path.join(path, '.mcp.json')).get('mcpServers') or {}).keys())
    return r


def _ts(s):
    try:
        return datetime.datetime.fromisoformat(str(s).replace('Z', '+00:00')).timestamp()
    except ValueError:
        return 0.0


def _proj_label(cwd, dirname, ctx):
    for n, p in (ctx.get('projects') or {}).items():
        if cwd and os.path.realpath(cwd) == os.path.realpath(p):
            return n
    return os.path.basename(cwd.rstrip('/')) if cwd else dirname


def op_usage(a, ctx, path):
    days = max(1, min(31, int(a.get('days') or 7)))
    t0 = time.time()
    since = t0 - days * 86400
    files = [(mtime(f), f) for f in glob.glob(home('.claude', 'projects', '*', '*.jsonl')) + glob.glob(home('.claude', 'projects', '*', '*', 'subagents', '*.jsonl'))]
    files = [f for m, f in sorted(files, reverse=True) if m >= since]
    by_day, by_proj, seen = {}, {}, set()
    partial, scanned = False, 0
    tot = {'input': 0, 'output': 0, 'cache_read': 0, 'cache_write': 0, 'cost_usd': 0.0, 'messages': 0}

    def add(day, proj, k, v):
        for bucket, key in ((by_day, day), (by_proj, proj)):
            b = bucket.setdefault(key, {'input': 0, 'output': 0, 'cache_read': 0, 'cache_write': 0, 'cost_usd': 0.0, 'messages': 0})
            b[k] += v
        tot[k] += v

    for f in files:
        if time.time() - t0 > 8:
            partial = True
            break
        scanned += 1
        dirname = os.path.basename(os.path.dirname(f)) if 'subagents' not in f else os.path.basename(os.path.dirname(os.path.dirname(os.path.dirname(f))))
        cwd, last_ts, cost_prev, has_cs = '', 0.0, 0.0, False
        try:
            fh = open(f, errors='ignore')
        except OSError:
            continue
        with fh:
            for line in fh:
                if '"usage"' not in line and '"cost-state"' not in line and '"cwd"' not in line:
                    continue
                try:
                    d = json.loads(line)
                except ValueError:
                    continue
                if not isinstance(d, dict):
                    continue
                cwd = d.get('cwd') or cwd
                if d.get('timestamp'):
                    last_ts = _ts(d['timestamp']) or last_ts
                proj = _proj_label(cwd, dirname, ctx)
                if d.get('type') == 'cost-state' and isinstance(d.get('totalCostUSD'), (int, float)):
                    has_cs = True
                    cur = float(d['totalCostUSD'])
                    inc = cur - cost_prev if cur >= cost_prev else cur
                    cost_prev = cur
                    ts = last_ts or mtime(f)
                    if ts >= since and inc > 0:
                        add(time.strftime('%Y-%m-%d', time.localtime(ts)), proj, 'cost_usd', inc)
                    continue
                if d.get('type') != 'assistant' or not isinstance(d.get('message'), dict):
                    continue
                m = d['message']
                u = m.get('usage')
                ts = _ts(d.get('timestamp'))
                if not isinstance(u, dict) or ts < since:
                    continue
                key = (m.get('id'), d.get('requestId'))
                if key[0] and key in seen:          # eyni cavabın təkrar sətirləri (stream)
                    continue
                seen.add(key)
                day = time.strftime('%Y-%m-%d', time.localtime(ts))
                add(day, proj, 'input', int(u.get('input_tokens') or 0))
                add(day, proj, 'output', int(u.get('output_tokens') or 0))
                add(day, proj, 'cache_read', int(u.get('cache_read_input_tokens') or 0))
                add(day, proj, 'cache_write', int(u.get('cache_creation_input_tokens') or 0))
                add(day, proj, 'messages', 1)
                if not has_cs and isinstance(d.get('costUSD'), (int, float)):
                    add(day, proj, 'cost_usd', float(d['costUSD']))
    rnd = lambda b: {**b, 'cost_usd': round(b['cost_usd'], 2)}   # noqa
    projs = sorted(by_proj.items(), key=lambda kv: -(kv[1]['cost_usd'] or kv[1]['output']))
    return {'ok': True, 'days': days, 'partial': partial, 'files_scanned': scanned, 'files_total': len(files),
            'total': rnd(tot), 'by_day': {k: rnd(v) for k, v in sorted(by_day.items())},
            'by_project': {k: rnd(v) for k, v in projs[:25]}}


def _short_input(name, inp, root=''):
    if not isinstance(inp, dict):
        return ''
    for k in ('command', 'file_path', 'notebook_path', 'path', 'pattern', 'url', 'query', 'description', 'skill', 'prompt'):
        if inp.get(k):
            v = str(inp[k]).replace('\n', ' ')
            return (v[len(root) + 1:] if root and v.startswith(root + '/') else v)[:140]
    return ','.join(sorted(inp.keys()))[:80]


def _text(content):
    if isinstance(content, str):
        return content
    if isinstance(content, list):
        return ' '.join(str(c.get('text', '')) for c in content if isinstance(c, dict) and c.get('type') == 'text')
    return ''


def op_transcript(a, ctx, path):
    mins = max(1, min(1440, int(a.get('since_minutes') or 60)))
    since = time.time() - mins * 60
    dirs = proj_dirs(path)
    if not dirs:
        return {'ok': True, 'sessions': [], 'note': 'bu proyekt üçün transkript yoxdur'}
    want = str(a.get('session') or '').strip()
    if want and not re.match(r'^[A-Za-z0-9\-]{4,64}$', want):
        return {'ok': False, 'error': 'session id yanlışdır'}
    files = [f for d in dirs for f in glob.glob(os.path.join(d, '*.jsonl'))]
    files = [f for f in files if (os.path.basename(f).startswith(want) if want else mtime(f) >= since)]
    files = sorted(files, key=mtime, reverse=True)[:3]
    sessions = []
    t0 = time.time()
    for f in files:
        prompts, tools, touched, errors, last = [], [], {}, [], 0.0
        tool_names = {}
        try:
            fh = open(f, errors='ignore')
        except OSError:
            continue
        with fh:
            for line in fh:
                if time.time() - t0 > 10:
                    break
                try:
                    d = json.loads(line)
                except ValueError:
                    continue
                if not isinstance(d, dict) or d.get('type') not in ('user', 'assistant'):
                    continue
                ts = _ts(d.get('timestamp'))
                if not want and ts < since:
                    continue
                last = max(last, ts)
                m = d.get('message') if isinstance(d.get('message'), dict) else {}
                c = m.get('content')
                hm = time.strftime('%H:%M', time.localtime(ts)) if ts else ''
                if d['type'] == 'user':
                    if isinstance(c, str) or (isinstance(c, list) and any(isinstance(x, dict) and x.get('type') == 'text' for x in c)):
                        t = _text(c).strip()
                        if t and not t.startswith('<') and not d.get('isMeta') and not d.get('isSidechain'):
                            prompts.append({'at': hm, 'text': t[:300]})
                    if isinstance(c, list):
                        for x in c:
                            if isinstance(x, dict) and x.get('type') == 'tool_result' and x.get('is_error'):
                                errors.append({'at': hm, 'tool': tool_names.get(x.get('tool_use_id'), ''), 'text': _text(x.get('content')).strip()[:200] or str(x.get('content'))[:200]})
                elif isinstance(c, list):
                    for x in c:
                        if isinstance(x, dict) and x.get('type') == 'tool_use':
                            n, inp = str(x.get('name')), x.get('input') or {}
                            tool_names[x.get('id')] = n
                            tools.append({'at': hm, 'tool': n, 'input': _short_input(n, inp, path)})
                            fp = inp.get('file_path') or inp.get('notebook_path') if isinstance(inp, dict) else None
                            if fp and n in ('Edit', 'Write', 'MultiEdit', 'NotebookEdit'):
                                rel = os.path.relpath(fp, path) if str(fp).startswith(path) else str(fp)
                                touched[rel] = touched.get(rel, 0) + 1
        counts = {}
        for t in tools:
            counts[t['tool']] = counts.get(t['tool'], 0) + 1
        sessions.append({'session': os.path.basename(f)[:-6], 'last': time.strftime('%Y-%m-%d %H:%M', time.localtime(last)) if last else '',
                         'prompts': prompts[-12:], 'tool_counts': counts, 'tools': tools[-25:],
                         'files': [{'file': k, 'edits': v} for k, v in sorted(touched.items(), key=lambda kv: -kv[1])[:20]],
                         'errors': errors[-8:]})
    return {'ok': True, 'since_minutes': mins, 'sessions': sessions}


def _audit(path):
    """npm audit: yalnız package-lock varsa, 6 saat keşlə (lockfile dəyişəndə yenilənir)."""
    lock = os.path.join(path, 'package-lock.json')
    if not os.path.isfile(lock) or not shutil.which('npm'):
        return None
    cdir = home('.claude-team-cache')
    key = hashlib.sha1(f'{os.path.realpath(path)}:{os.path.getmtime(lock)}'.encode()).hexdigest()[:16]
    cf = os.path.join(cdir, f'audit-{key}.json')
    try:
        if time.time() - os.path.getmtime(cf) < 6 * 3600:
            with open(cf) as f:
                return {**json.load(f), 'cached': True}
    except (OSError, ValueError):
        pass
    c, out, _ = run(['npm', 'audit', '--json', '--package-lock-only'], cwd=path, timeout=20)
    try:
        v = (json.loads(out).get('metadata') or {}).get('vulnerabilities') or {}
        res = {k: int(v.get(k) or 0) for k in ('critical', 'high', 'moderate', 'low')}
        res['total'] = int(v.get('total') or sum(res.values()))
    except (ValueError, AttributeError):
        return {'error': 'npm audit alınmadı' if c != -9 else 'npm audit vaxtı bitdi'}
    try:
        os.makedirs(cdir, exist_ok=True)
        with open(cf, 'w') as f:
            json.dump(res, f)
    except OSError:
        pass
    return res


def _deploy_target(path):
    found = [n for f, n in (('vercel.json', 'vercel'), ('.vercel', 'vercel'), ('netlify.toml', 'netlify'), ('fly.toml', 'fly'),
                            ('Dockerfile', 'docker'), ('docker-compose.yml', 'docker-compose'), ('render.yaml', 'render'),
                            ('app.yaml', 'gcp-appengine'), ('Procfile', 'heroku'), ('firebase.json', 'firebase'), ('wrangler.toml', 'cloudflare'))
             if os.path.exists(os.path.join(path, f))]
    found += [f for f in os.listdir(path) if re.match(r'(?i)^deploy.*\.(sh|py|js)$', f)][:3] if os.path.isdir(path) else []
    try:
        scripts = (read_json(os.path.join(path, 'package.json')).get('scripts') or {})
        found += [f'npm run {k}' for k in scripts if re.match(r'(?i)^deploy', k)][:3]
    except AttributeError:
        pass
    return sorted(set(found))


def op_health(a, ctx, path):
    """Nəticənin forması (açar yoxdursa, həmin məlumat alınmayıb):
    {ok, op: 'health', project,
     git: {branch, upstream: 'origin/main'|None, ahead?, behind?, dirty, untracked, worktrees,
           last_commits: [{sha, when, author, subject}]} | None (git repo deyil),
     ci: {status, conclusion, name, branch, url, at, event} | None (gh yoxdur/girişsiz/run yoxdur) — ən son run,
     runs: [{status, conclusion, name, branch, url, at, event}] (son 5, yalnız gh varsa),
     ci_red: bool (son 3 run-dan biri failure/timed_out/startup_failure; gh yoxdursa yoxdur),
     prs: [{number, title, branch, draft, author}], prs_error?, ci_error?,
     last_deploy?: {env, at, sha}, gh?: 'gh quraşdırılmayıb' | 'gh girişli deyil' | 'git repo deyil',
     audit?: {critical, high, moderate, low, total, cached?} | {error}, deploy: ['vercel', 'npm run deploy', …]}
    conclusion: success | failure | cancelled | skipped | timed_out | startup_failure | neutral | action_required | '' (hələ gedir)."""
    r = {'ok': True, 'ci': None}
    if os.path.isdir(os.path.join(path, '.git')) or os.path.isfile(os.path.join(path, '.git')):
        g = {}
        g['branch'] = git(path, 'branch', '--show-current')[1].strip() or '(detached)'
        c, out, _ = git(path, 'rev-list', '--left-right', '--count', '@{u}...HEAD')
        if c == 0 and out.strip():
            b, ah = out.split()[:2]
            g['behind'], g['ahead'] = int(b), int(ah)
            g['upstream'] = git(path, 'rev-parse', '--abbrev-ref', '@{u}')[1].strip()
        else:
            g['upstream'] = None
        st = git(path, 'status', '--porcelain')[1].splitlines()
        g['dirty'] = len(st)
        g['untracked'] = sum(1 for x in st if x.startswith('??'))
        g['last_commits'] = [dict(zip(('sha', 'when', 'author', 'subject'), ln.split('\x1f', 3)))
                             for ln in git(path, 'log', '-5', '--format=%h\x1f%cr\x1f%an\x1f%s')[1].splitlines() if ln]
        g['worktrees'] = sum(1 for ln in git(path, 'worktree', 'list', '--porcelain')[1].splitlines() if ln.startswith('worktree '))
        r['git'] = g
    else:
        r['git'] = None
    ok, why = gh_ok(path) if r['git'] else (False, 'git repo deyil')
    if ok:
        c, out, err = run(['gh', 'pr', 'list', '--json', 'number,title,headRefName,isDraft,author', '--limit', '10'], cwd=path, timeout=20)
        try:
            r['prs'] = [{'number': p['number'], 'title': p['title'][:120], 'branch': p['headRefName'], 'draft': p['isDraft'],
                         'author': (p.get('author') or {}).get('login', '')} for p in json.loads(out)]
        except (ValueError, TypeError, KeyError):
            r['prs_error'] = tail(err, 200) or 'gh pr list alınmadı'
        c, out, err = run(['gh', 'run', 'list', '--limit', '5', '--json', 'status,conclusion,name,headBranch,createdAt,event,url'], cwd=path, timeout=20)
        try:
            r['runs'] = [{'status': x['status'], 'conclusion': x['conclusion'] or '', 'name': x['name'][:80], 'branch': x['headBranch'],
                          'url': x.get('url', ''), 'at': x['createdAt'], 'event': x.get('event')} for x in json.loads(out)]
            r['ci'] = r['runs'][0] if r['runs'] else None
            r['ci_red'] = any(x['conclusion'] in ('failure', 'timed_out', 'startup_failure') for x in r['runs'][:3])
        except (ValueError, TypeError, KeyError):
            r['ci_error'] = tail(err, 200) or 'gh run list alınmadı'
        c, out, _ = run(['gh', 'api', 'repos/{owner}/{repo}/deployments?per_page=1'], cwd=path, timeout=10)
        try:
            dep = json.loads(out)
            if isinstance(dep, list) and dep:
                r['last_deploy'] = {'env': dep[0].get('environment'), 'at': dep[0].get('created_at'), 'sha': str(dep[0].get('sha'))[:7]}
        except ValueError:
            pass
    else:
        r['gh'] = why
    au = _audit(path)
    if au is not None:
        r['audit'] = au
    r['deploy'] = _deploy_target(path)
    return r


def _num(a):
    try:
        n = int(a.get('number'))
        return n if 0 < n < 10 ** 7 else None
    except (TypeError, ValueError):
        return None


def op_pr_view(a, ctx, path):
    ok, why = gh_ok(path)
    if not ok:
        return {'ok': False, 'error': why}
    n = _num(a)
    cmd = ['gh', 'pr', 'view'] + ([str(n)] if n else []) + ['--json', 'number,title,state,isDraft,author,headRefName,baseRefName,additions,deletions,changedFiles,files,body,statusCheckRollup,mergeable,reviewDecision,url']
    c, out, err = run(cmd, cwd=path, timeout=25)
    try:
        p = json.loads(out)
    except ValueError:
        return {'ok': False, 'error': tail(err, 300) or 'gh pr view alınmadı'}
    checks = []
    for x in p.get('statusCheckRollup') or []:
        checks.append({'name': (x.get('name') or x.get('context') or '')[:60], 'status': x.get('status') or x.get('state'), 'conclusion': x.get('conclusion')})
    return {'ok': True, 'number': p.get('number'), 'title': p.get('title'), 'state': p.get('state'), 'draft': p.get('isDraft'),
            'author': (p.get('author') or {}).get('login', ''), 'head': p.get('headRefName'), 'base': p.get('baseRefName'),
            'additions': p.get('additions'), 'deletions': p.get('deletions'), 'changed_files': p.get('changedFiles'),
            'files': [{'path': f.get('path'), '+': f.get('additions'), '-': f.get('deletions')} for f in (p.get('files') or [])[:40]],
            'checks': checks[:20], 'mergeable': p.get('mergeable'), 'review': p.get('reviewDecision'), 'url': p.get('url'),
            'body': (p.get('body') or '')[:1200]}


def op_worktree_list(a, ctx, path):
    c, out, err = git(path, 'worktree', 'list', '--porcelain')
    if c != 0:
        return {'ok': False, 'error': tail(err, 300) or 'git worktree list alınmadı'}
    wts, cur = [], {}
    for ln in out.splitlines() + ['']:
        if not ln:
            if cur:
                wts.append(cur)
            cur = {}
            continue
        k, _, v = ln.partition(' ')
        if k == 'worktree':
            cur['path'] = v.replace(os.path.expanduser('~'), '~')
        elif k == 'HEAD':
            cur['head'] = v[:9]
        elif k == 'branch':
            cur['branch'] = v.replace('refs/heads/', '')
        elif k in ('detached', 'locked', 'prunable', 'bare'):
            cur[k] = True
    return {'ok': True, 'worktrees': wts[:30]}


# ---------- dəyişən op-lar ----------
def op_pr_merge(a, ctx, path):
    n = _num(a)
    method = str(a.get('method') or 'squash')
    if not n:
        return {'ok': False, 'error': 'PR nömrəsi lazımdır'}
    if method not in ('merge', 'squash', 'rebase'):
        return {'ok': False, 'error': 'method: merge | squash | rebase'}
    ok, why = gh_ok(path)
    if not ok:
        return {'ok': False, 'error': why}
    c, out, err = run(['gh', 'pr', 'merge', str(n), '--' + method], cwd=path, timeout=60)   # --admin və --delete-branch heç vaxt
    return {'ok': c == 0, 'number': n, 'method': method, 'tail': tail(out + '\n' + err, 600),
            **({} if c == 0 else {'error': 'birləşdirmə alınmadı (yoxlamalar, konflikt və ya icazə)'})}


MCP_RUNNERS = {'npx', 'uvx', 'uv', 'node', 'python', 'python3', 'bunx', 'bun', 'deno', 'docker', 'pipx'}


def op_mcp_add(a, ctx, path):
    name = str(a.get('name') or '')
    scope = str(a.get('scope') or 'local')
    if not re.match(r'^[A-Za-z0-9_.\-]{1,64}$', name):
        return {'ok': False, 'error': 'ad yanlışdır (A-Z a-z 0-9 _ . -)'}
    if scope not in ('local', 'project', 'user'):
        return {'ok': False, 'error': 'scope: local | project | user'}
    url, command = str(a.get('url') or '').strip(), str(a.get('command') or '').strip()
    extra = a.get('args') or []
    env = a.get('env') or {}
    headers = a.get('headers') or {}
    if not isinstance(extra, list) or not isinstance(env, dict) or not isinstance(headers, dict) or len(extra) > 20 or len(env) > 20 or len(headers) > 10:
        return {'ok': False, 'error': 'args siyahı, env/headers obyekt olmalıdır'}
    if scope == 'project' and (env or headers):
        return {'ok': False, 'error': 'project scope .mcp.json-a yazır (git-ə düşür): env/headers ilə local və ya user seç'}
    opts = ['-s', scope]
    for k, v in env.items():
        if not re.match(r'^[A-Za-z_][A-Za-z0-9_]{0,63}$', str(k)) or '\n' in str(v) or len(str(v)) > 2000:
            return {'ok': False, 'error': f'env açarı yanlışdır: {str(k)[:40]}'}
        opts += ['-e', f'{k}={v}']
    if url:
        if command:
            return {'ok': False, 'error': 'ya command, ya url'}
        if not re.match(r'^https://[^\s]+$', url) and not re.match(r'^http://(localhost|127\.0\.0\.1)(:\d+)?(/[^\s]*)?$', url):
            return {'ok': False, 'error': 'url https:// olmalıdır (və ya http://localhost)'}
        tr = str(a.get('transport') or 'http')
        if tr not in ('http', 'sse'):
            return {'ok': False, 'error': 'transport: http | sse'}
        for k, v in headers.items():
            if not re.match(r'^[A-Za-z0-9\-]{1,64}$', str(k)) or '\n' in str(v):
                return {'ok': False, 'error': 'header yanlışdır'}
            opts += ['-H', f'{k}: {v}']
        target = [url]
        opts = ['-t', tr] + opts
    else:
        if headers:
            return {'ok': False, 'error': 'headers yalnız url ilə'}
        if os.path.basename(command) not in MCP_RUNNERS or ('/' in command and not os.path.isabs(command)):
            return {'ok': False, 'error': 'command icazəli deyil: ' + ', '.join(sorted(MCP_RUNNERS))}
        if any(not isinstance(x, (str, int, float)) or '\n' in str(x) or len(str(x)) > 300 for x in extra):
            return {'ok': False, 'error': 'args yanlışdır'}
        target = [command] + [str(x) for x in extra]
    cb = claude_bin(ctx)
    if not cb:
        return {'ok': False, 'error': 'claude tapılmadı'}
    c, out, err = run([cb, 'mcp', 'add', name] + opts + ['--'] + target, cwd=path, timeout=60, env=claude_env())
    secrets = [str(v) for v in list(env.values()) + list(headers.values()) if len(str(v)) >= 3]
    t = tail(out + '\n' + err, 600)
    for s in secrets:
        t = t.replace(s, '***')
    return {'ok': c == 0, 'name': name, 'scope': scope, 'transport': (a.get('transport') or 'http') if url else 'stdio',
            'target': mcp_target(url or command, ''), 'env_keys': sorted(env.keys()), 'header_keys': sorted(headers.keys()),
            'tail': t, **({} if c == 0 else {'error': 'claude mcp add alınmadı'})}


def op_claude_md_append(a, ctx, path):
    text = str(a.get('text') or '').strip()
    if not text or len(text.encode()) > 2048:
        return {'ok': False, 'error': 'mətn 1 bayt – 2 KB olmalıdır'}
    if has_secret(text):
        return {'ok': False, 'error': 'mətndə gizli məlumata oxşar hissə var (token/açar), yazılmadı'}
    p = os.path.join(path, 'CLAUDE.md')
    if os.path.islink(p):
        return {'ok': False, 'error': 'CLAUDE.md simvolik linkdir, yazılmadı'}
    pre = ''
    try:
        with open(p, 'rb') as f:
            f.seek(0, 2)
            if f.tell():
                f.seek(-1, 2)
                pre = '\n' if f.read(1) != b'\n' else ''
    except OSError:
        pass
    block = f'{pre}\n## Jarvis qeydi ({time.strftime("%Y-%m-%d %H:%M")})\n{text}\n'
    with open(p, 'a', encoding='utf-8') as f:
        f.write(block)
    return {'ok': True, 'file': 'CLAUDE.md', 'appended_bytes': len(block.encode()), 'size': os.path.getsize(p)}


_RULE_TOOLS = {'Bash', 'Read', 'Edit', 'Write', 'MultiEdit', 'NotebookEdit', 'WebFetch', 'WebSearch', 'Glob', 'Grep', 'Task', 'Agent', 'Skill', 'TodoWrite'}
_RULE_BAD = re.compile(r'(?i)(sudo|rm\s+-[a-z]*r|--force|push\s+-f|reset\s+--hard|clean\s+-|branch\s+-D|\|\s*(ba|z)?sh|curl[^)]*\|\s*|\.env|\.pem|id_rsa|id_ed25519|\.claude|\.ssh|credentials|chmod|chown|mkfs|dd\s+if=|eval\b|>\s*/)')


def rule_ok(rule):
    if not rule or len(rule) > 200 or '\n' in rule:
        return 'qayda 1–200 simvol, tək sətir olmalıdır'
    m = re.match(r'^(mcp__[A-Za-z0-9_\-]+(?:__[A-Za-z0-9_\-]+)?|[A-Za-z]+)(?:\((.+)\))?$', rule)
    if not m:
        return 'format: Tool və ya Tool(spec), məs. Bash(npm test:*)'
    tool, spec = m.group(1), m.group(2)
    if not tool.startswith('mcp__') and tool not in _RULE_TOOLS:
        return f'naməlum alət: {tool}'
    if tool == 'Bash':
        if not spec:
            return 'bütün Bash-ə icazə verilmir: Bash(əmr:*) yaz'
        s = spec.strip()
        if s.startswith('*') or s.startswith(':') or re.match(r'^[\s*:]*$', s) or len(re.sub(r'[*:\s]', '', s)) < 2:
            return 'çox geniş qayda'
    if spec is not None and spec.strip() in ('*', '**', '/**', '~/**', '//**'):
        return 'çox geniş qayda'
    if spec and _RULE_BAD.search(spec):
        return 'təhlükəli qayda (deny siyahısı ilə toqquşur)'
    return None


def op_allow_rule(a, ctx, path):
    rule = str(a.get('rule') or '').strip()
    why = rule_ok(rule)
    if why:
        return {'ok': False, 'error': why}
    d = os.path.join(path, '.claude')
    p = os.path.join(d, 'settings.local.json')
    s = {}
    if os.path.exists(p):
        if os.path.islink(p):
            return {'ok': False, 'error': 'settings.local.json simvolik linkdir'}
        try:
            with open(p) as f:
                s = json.load(f)
        except (OSError, ValueError):
            return {'ok': False, 'error': 'settings.local.json oxunmadı (JSON səhv), toxunulmadı'}
        if not isinstance(s, dict):
            return {'ok': False, 'error': 'settings.local.json obyekt deyil, toxunulmadı'}
    perm = s.setdefault('permissions', {})
    if not isinstance(perm, dict):
        return {'ok': False, 'error': 'permissions obyekt deyil, toxunulmadı'}
    allow = perm.setdefault('allow', [])
    if rule in allow:
        return {'ok': True, 'rule': rule, 'added': False, 'note': 'artıq var', 'allow_count': len(allow)}
    if rule in (perm.get('deny') or []):
        return {'ok': False, 'error': 'bu qayda deny siyahısındadır'}
    allow.append(rule)
    os.makedirs(d, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=d, prefix='.settings.', suffix='.tmp')
    with os.fdopen(fd, 'w') as f:
        json.dump(s, f, indent=2, ensure_ascii=False)
        f.write('\n')
    os.replace(tmp, p)
    return {'ok': True, 'rule': rule, 'added': True, 'file': '.claude/settings.local.json', 'allow_count': len(allow)}


def op_claude_update(a, ctx, path):
    cb = claude_bin(ctx)
    if not cb:
        return {'ok': False, 'error': 'claude tapılmadı'}
    before = run([cb, '--version'], timeout=20)[1].strip()
    c, out, err = run([cb, 'update'], timeout=180, env=claude_env())
    after = run([cb, '--version'], timeout=20)[1].strip()
    return {'ok': c == 0, 'before': before, 'after': after, 'updated': before != after, 'tail': tail(out + '\n' + err, 1500),
            **({} if c == 0 else {'error': 'vaxt bitdi' if c == -9 else 'claude update alınmadı'})}


def op_claude_doctor(a, ctx, path):
    cb = claude_bin(ctx)
    if not cb:
        return {'ok': False, 'error': 'claude tapılmadı'}
    c, out, err = run([cb, 'doctor'], cwd=path or os.path.expanduser('~'), timeout=120, env=claude_env())
    return {'ok': c in (0, -9) and bool((out + err).strip()), 'exit': c, 'tail': tail(out + '\n' + err, 3000),
            **({'note': 'vaxt bitdi (interaktiv ola bilər), çıxışın sonu göstərilir'} if c == -9 else {})}


def op_sessions_clean(a, ctx, path):
    try:
        days = int(a.get('days') or 30)
    except (TypeError, ValueError):
        days = 0
    if days < 7:
        return {'ok': False, 'error': 'days ≥ 7 olmalıdır'}
    base = home('.claude', 'projects')
    cutoff = time.time() - days * 86400
    roots = proj_dirs(path) if path else [base]
    n, size, kept = 0, 0, 0
    for r in roots:
        for f in glob.glob(os.path.join(r, '**', '*.jsonl'), recursive=True):
            try:
                if os.path.islink(f) or not os.path.realpath(f).startswith(os.path.realpath(base) + os.sep):
                    continue
                st = os.stat(f)
                if st.st_mtime < cutoff:
                    os.remove(f)
                    n += 1
                    size += st.st_size
                else:
                    kept += 1
            except OSError:
                continue
    return {'ok': True, 'days': days, 'deleted': n, 'freed_mb': round(size / 1048576, 1), 'kept': kept,
            'scope': 'proyekt' if path else 'hamısı'}


def op_worktree_new(a, ctx, path, project):
    if ctx.get('git_level', 'safe') == 'read':
        return {'ok': False, 'error': 'git səviyyəsi read: worktree yaradılmır'}
    name = str(a.get('name') or '')
    if not re.match(r'^[a-z0-9][a-z0-9._\-]{0,39}$', name) or '..' in name:
        return {'ok': False, 'error': 'ad: kiçik hərf, rəqəm, . _ - (≤ 40)'}
    if git(path, 'rev-parse', '--git-dir')[0] != 0:
        return {'ok': False, 'error': 'git repo deyil'}
    br = f'jarvis/{name}'
    if git(path, 'rev-parse', '--verify', '--quiet', f'refs/heads/{br}')[0] == 0:
        return {'ok': False, 'error': f'budaq artıq var: {br}'}
    base = os.environ.get('CLAUDE_TEAM_WORKTREES', home('.claude-team-worktrees'))
    os.makedirs(base, exist_ok=True)
    slug = re.sub(r'[^A-Za-z0-9._\-]', '-', project or os.path.basename(path.rstrip('/')))[:40]
    wt = os.path.join(base, f'{slug}-{name}')
    if os.path.exists(wt):
        return {'ok': False, 'error': 'qovluq artıq var: ' + wt.replace(os.path.expanduser('~'), '~')}
    c, out, err = git(path, 'worktree', 'add', '-b', br, wt, 'HEAD', timeout=120)
    return {'ok': c == 0, 'path': wt.replace(os.path.expanduser('~'), '~'), 'branch': br,
            **({} if c == 0 else {'error': tail(err, 300) or 'worktree yaradılmadı'})}


def op_undo_last(a, ctx, path, project):
    """Son agent tapşırığından əvvəlki vəziyyətə qaytarır. Heç nə itmir: indiki dəyişikliklər stash-ə, köhnə HEAD ehtiyat budağa."""
    if ctx.get('git_level', 'safe') == 'read':
        return {'ok': False, 'error': 'git səviyyəsi read: geri qaytarma olmaz'}
    snaps = read_json(os.environ.get('CLAUDE_TEAM_SNAPSHOTS', home('.claude-team-snapshots.json')))
    s = snaps.get(project) or snaps.get(path) or next((v for k, v in snaps.items() if isinstance(v, dict) and v.get('path') == path), None)
    if not isinstance(s, dict) or not re.match(r'^[0-9a-f]{7,40}$', str(s.get('sha') or '')):
        return {'ok': False, 'error': 'bu proyekt üçün snapshot yoxdur'}
    sha, stash = s['sha'], str(s.get('stash') or '')
    if stash and not re.match(r'^[0-9a-f]{7,40}$', stash):
        return {'ok': False, 'error': 'snapshot stash yanlışdır'}
    if git(path, 'cat-file', '-e', sha + '^{commit}')[0] != 0 or (stash and git(path, 'cat-file', '-e', stash + '^{commit}')[0] != 0):
        return {'ok': False, 'error': 'snapshot commit-i repoda tapılmadı'}
    head = git(path, 'rev-parse', 'HEAD')[1].strip()
    full_sha = git(path, 'rev-parse', sha)[1].strip()
    at = float(s.get('at') or 0)
    at = at / 1000 if at > 1e12 else at
    # indiki dəyişikliklər: izlənən fayllar + snapshot-dan sonra yaranan izlənməyən fayllar (köhnə izlənməyənlərə toxunulmur)
    raw = git(path, 'status', '--porcelain=v1', '-z', '--untracked-files=all')[1].split('\0')
    tracked, untracked, i = [], [], 0
    while i < len(raw):
        e = raw[i]
        i += 1
        if len(e) < 4:
            continue
        xy, f = e[:2], e[3:]
        if xy == '??':
            try:
                if not at or os.path.getmtime(os.path.join(path, f)) >= at - 5:
                    untracked.append(f)
            except OSError:
                pass
        else:
            tracked.append(f)
            if xy[0] in 'RC' and i < len(raw):
                tracked.append(raw[i])
                i += 1
    changes = tracked + untracked
    if head == full_sha:          # HEAD eynidir: iş ağacı snapshot-dakı ilə eynidirsə, geri qaytarılacaq bir şey yoxdur
        now_tree = git(path, 'rev-parse', (git(path, 'stash', 'create')[1].strip() or 'HEAD') + '^{tree}')[1].strip()
        snap_tree = git(path, 'rev-parse', (stash or full_sha) + '^{tree}')[1].strip()
        if now_tree == snap_tree and not untracked:
            return {'ok': True, 'changed': False, 'note': 'snapshot-dan bəri dəyişiklik yoxdur'}
    else:
        if git(path, 'merge-base', '--is-ancestor', full_sha, 'HEAD')[0] != 0:
            return {'ok': False, 'error': 'HEAD snapshot-un davamı deyil (budaq dəyişib?), toxunulmadı'}
        n_new = int(git(path, 'rev-list', '--count', f'{full_sha}..HEAD')[1].strip() or 0)
        n_local = int(git(path, 'rev-list', '--count', f'{full_sha}..HEAD', '--not', '--remotes')[1].strip() or 0)
        if n_local != n_new:
            return {'ok': False, 'error': f'{n_new - n_local} yeni commit artıq push olunub, geri qaytarılmır'}
        if at and any(int(t) < at - 120 for t in git(path, 'log', '--format=%ct', f'{full_sha}..HEAD')[1].split()):
            return {'ok': False, 'error': 'snapshot-dan əvvəlki (yad) commit-lər var, toxunulmadı'}
    ts = time.strftime('%Y%m%d-%H%M%S')
    steps = []
    if changes:                   # heç nə silinmir: indiki vəziyyət stash-də qalır
        c, _, err = git(path, 'stash', 'push', '-u', '-m', f'jarvis-undo-{ts}', '--', *changes)
        if c != 0:
            return {'ok': False, 'error': 'stash alınmadı: ' + tail(err, 200)}
        steps.append(f'indiki dəyişikliklər ({len(changes)} fayl) stash-də: jarvis-undo-{ts} (git stash list)')
    if head != full_sha:
        backup = f'jarvis/undo-backup-{ts}'
        git(path, 'branch', backup, head)
        c, _, err = git(path, 'reset', '--keep', full_sha)
        if c != 0:
            return {'ok': False, 'error': 'reset --keep alınmadı: ' + tail(err, 200), 'steps': steps}
        steps.append(f'HEAD {head[:7]} → {full_sha[:7]} (köhnə HEAD budaqda: {backup})')
    if stash:
        c, _, err = git(path, 'stash', 'apply', stash)
        if c != 0:
            return {'ok': False, 'changed': True, 'steps': steps, 'error': 'qismən: tapşırıqdan əvvəlki dəyişikliklər tətbiq olunmadı: ' + tail(err, 200), 'stash': stash[:12]}
        steps.append('tapşırıqdan əvvəlki commit olunmamış dəyişikliklər bərpa olundu')
    return {'ok': True, 'changed': True, 'snapshot': {'sha': full_sha[:9], 'at': s.get('at'), 'cmd_id': s.get('cmd_id')}, 'steps': steps}


HANDLERS = {
    'claude_info': op_claude_info, 'mcp_list': op_mcp_list, 'config': op_config, 'usage': op_usage, 'transcript': op_transcript,
    'health': op_health, 'pr_view': op_pr_view, 'worktree_list': op_worktree_list, 'pr_merge': op_pr_merge, 'mcp_add': op_mcp_add,
    'allow_rule': op_allow_rule, 'claude_md_append': op_claude_md_append, 'claude_update': op_claude_update,
    'claude_doctor': op_claude_doctor, 'sessions_clean': op_sessions_clean,
}


def resolve(args, ctx):
    """Proyekt adı → yol. Yalnız ctx['projects'] siyahısındakılar."""
    projects = ctx.get('projects') or {}
    name = str(args.get('project') or '').strip()
    if name:
        p = projects.get(name)
        if p and os.path.isdir(p):
            return name, p
        return None, None
    pp = ctx.get('project_path')
    if pp:
        for n, p in projects.items():
            if os.path.realpath(p) == os.path.realpath(pp) and os.path.isdir(p):
                return n, p
    return None, None


def handle(op, args, ctx):
    try:
        op = str(op or '')
        if op not in OPS:
            return {'ok': False, 'op': op[:40], 'error': 'naməlum əməliyyat'}
        args = args if isinstance(args, dict) else {}
        ctx = ctx if isinstance(ctx, dict) else {}
        need, needs_proj, red = OPS[op]
        prof = str(ctx.get('profile') or 'basic').lower()
        prof = prof if prof in LEVELS else 'basic'
        if LEVELS[prof] < LEVELS[need]:
            return {'ok': False, 'op': op, 'error': f'profil "{prof}" bu əməliyyata icazə vermir (lazım: {need}+)', 'denied': True}
        name, path = resolve(args, ctx)
        if needs_proj and not path:
            return {'ok': False, 'op': op, 'error': 'proyekt bu kompüterdə tapılmadı'}
        if op == 'sessions_clean' and args.get('project') and not path:
            return {'ok': False, 'op': op, 'error': 'proyekt bu kompüterdə tapılmadı'}
        if op in ('worktree_new', 'undo_last'):
            r = (op_worktree_new if op == 'worktree_new' else op_undo_last)(args, ctx, path, name)
        else:
            r = HANDLERS[op](args, ctx, path if (needs_proj or op in ('sessions_clean', 'claude_doctor')) else None)
        r = {'op': op, **({'project': name} if name else {}), **r}
        return fit(scrub(r))
    except Exception as e:  # noqa: heç vaxt istisna atma
        return {'ok': False, 'op': str(op)[:40], 'error': redact(f'{type(e).__name__}: {e}')[:300]}


if __name__ == '__main__':      # lokal yoxlama: python3 claude_ops.py <op> '<args json>' [proyekt_yolu] [profil]
    import sys
    a = json.loads(sys.argv[2]) if len(sys.argv) > 2 else {}
    pp = sys.argv[3] if len(sys.argv) > 3 else os.getcwd()
    print(json.dumps(handle(sys.argv[1] if len(sys.argv) > 1 else 'claude_info', a,
                            {'projects': {os.path.basename(pp): pp}, 'project_path': pp, 'profile': sys.argv[4] if len(sys.argv) > 4 else 'basic'}),
                     ensure_ascii=False, indent=1))
