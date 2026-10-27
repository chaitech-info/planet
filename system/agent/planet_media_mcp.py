#!/usr/bin/env python3
"""planet-media: Planet MEDIA Timeline üçün MCP server (stdio, yalnız standart kitabxana).

Claude Code istənilən sessiyada komandanın video layihələrini oxuyur və redaktə edir; dəyişikliklər Planet-in
Edit Media → 🎞 Timeline redaktorunda canlı görünür. Server URL və token ~/.claude-team-dash.conf-dan oxunur
(install.sh yazır); token Claude-a heç vaxt göstərilmir, yalnız bu proses HTTP başlığında (X-Team-Token) göndərir.

Quraşdırma (install.sh avtomatik edir):
  claude mcp add planet-media -s user -- python3 ~/claude-team-kit/agent/planet_media_mcp.py
Yoxlama:  python3 planet_media_mcp.py --check
"""
import json, mimetypes, os, shlex, sys, urllib.error, urllib.request, uuid

CONF = os.environ.get('CLAUDE_TEAM_CONF', os.path.expanduser('~/.claude-team-dash.conf'))
PROTO = '2025-06-18'
MEDIA_EXTS = {'.mp4', '.mov', '.webm', '.png', '.jpg', '.jpeg', '.webp', '.gif', '.mp3', '.wav', '.m4a', '.ogg'}
MAX_UPLOAD = 50 * 1024 * 1024

SCHEMA_DOC = """# Planet MEDIA timeline (v1)
All times are seconds. Layers: tracks later in the list draw ON TOP. Track kinds: video (video+image clips), audio, text, caption.
Project: {width, height, fps, background, duration, tracks:[{id, kind, name, muted, hidden, clips:[...]}]}
Clip: {id, type: video|image|audio|text|caption, start, dur, src (https url, media types), in (source offset, video/audio),
  speed (0.25-4), volume (0-2), x/y (center, 0..1 of frame; caption default y=0.86), scale, rotation (deg), opacity (0-1),
  fit (cover|contain|fill, video/image), fadeIn, fadeOut,
  text + style {font, size(px), weight, color, bg (css color or 'transparent'), stroke, align, italic, anim: none|fade|pop|slide-up|typewriter}}

Edit ops for media_apply_edits (applied in order, validated, saved as ONE new version):
  {op:'add_track', kind, name?, below?}            {op:'remove_track', track}
  {op:'add_clip', src, type?, track?, start?, dur, in?, ...props}   (no start = append at track end; type guessed from url)
  {op:'add_text', text, start, dur, track?, x?, y?, style?}
  {op:'add_captions', cues:[{start, end, text}], style?, track?, replace?}
  {op:'move', clip, start?, track?}   {op:'trim', clip, start?, dur?|end?, in?}
  {op:'split', clip, at}  (at = timeline seconds inside the clip; second half gets a new id)
  {op:'delete', clip, ripple?}        {op:'set_props', clip, props:{...}}
  {op:'set_track', track, name?, muted?, hidden?}
  {op:'set_canvas', preset?: landscape|reels|square|portrait, width?, height?, fps?, background?}
Pass base_version (the version you read) to avoid overwriting someone else's newer edit (409 = re-read and retry).
Workflow: media_get_project -> plan -> media_apply_edits (batch ops) -> verify with media_get_project.
To use a local file (e.g. a HyperFrames render or downloaded asset): media_upload_file(path, project_id, as_render).
"""

TOOLS = [
    {'name': 'media_list_projects', 'description': 'List the team\'s MEDIA timeline video projects (id, title, owner, version, duration, size, clip count).',
     'inputSchema': {'type': 'object', 'properties': {}}},
    {'name': 'media_get_project', 'description': 'Get one project with its full timeline JSON, current version and rendered exports.',
     'inputSchema': {'type': 'object', 'properties': {'id': {'type': 'integer'}}, 'required': ['id']}},
    {'name': 'media_create_project', 'description': 'Create a new empty video project. preset: landscape (1920x1080), reels (1080x1920), square, portrait (1080x1350).',
     'inputSchema': {'type': 'object', 'properties': {'title': {'type': 'string'}, 'preset': {'type': 'string', 'enum': ['landscape', 'reels', 'square', 'portrait']}}, 'required': ['title']}},
    {'name': 'media_apply_edits', 'description': 'Apply a list of timeline edit ops to a project as one new version (see media_timeline_schema for ops). Returns created ids and the new timeline.',
     'inputSchema': {'type': 'object', 'properties': {'id': {'type': 'integer'}, 'ops': {'type': 'array', 'items': {'type': 'object'}},
                                                      'base_version': {'type': 'integer'}, 'note': {'type': 'string', 'description': 'Short human description shown live in the editor'}},
                     'required': ['id', 'ops']}},
    {'name': 'media_restore_version', 'description': 'Restore an earlier version of a project (saved as a new version; nothing is lost). Use media_list_versions first.',
     'inputSchema': {'type': 'object', 'properties': {'id': {'type': 'integer'}, 'version': {'type': 'integer'}}, 'required': ['id', 'version']}},
    {'name': 'media_list_versions', 'description': 'List the last 50 versions of a project (version, author, note, time).',
     'inputSchema': {'type': 'object', 'properties': {'id': {'type': 'integer'}}, 'required': ['id']}},
    {'name': 'media_list_assets', 'description': 'List media assets available to place on the timeline: your uploads, renders, finished fal.ai generations and assets attached to a project. Each has url + type.',
     'inputSchema': {'type': 'object', 'properties': {'project_id': {'type': 'integer'}}}},
    {'name': 'media_upload_file', 'description': 'Upload a local media file (image/video/audio, <= 50 MB) to Planet and get its https url for add_clip. as_render=true attaches an MP4 to the project as a finished render/export.',
     'inputSchema': {'type': 'object', 'properties': {'path': {'type': 'string'}, 'project_id': {'type': 'integer'}, 'as_render': {'type': 'boolean'}}, 'required': ['path']}},
    {'name': 'media_timeline_schema', 'description': 'Timeline JSON format and the full list of edit ops with fields. Read this before editing.',
     'inputSchema': {'type': 'object', 'properties': {}}},
]


def conf():
    out = {}
    try:
        with open(CONF) as f:
            for line in f:
                if '=' in line:
                    k, v = line.rstrip('\n').split('=', 1)
                    try:
                        v = shlex.split(v)[0] if v.strip() else ''
                    except ValueError:
                        pass
                    out[k.strip()] = v
    except OSError:
        pass
    url, tok = out.get('URL', ''), out.get('TOKEN', '')
    if not url or not tok:
        raise RuntimeError(f'Planet quraşdırılmayıb: {CONF} yoxdur (team kit-də hook/install.sh işlət)')
    base = url.rsplit('/', 1)[0] + '/media.php'
    return base, tok


def call(action, payload=None, files=None, timeout=60):
    base, tok = conf()
    body = dict(payload or {}, action=action)
    headers = {'X-Team-Token': tok, 'Accept': 'application/json'}
    if files:
        bnd = uuid.uuid4().hex
        parts = []
        for k, v in body.items():
            parts.append(f'--{bnd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
        for k, (name, data, ctype) in files.items():
            parts.append(f'--{bnd}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + data + b'\r\n')
        parts.append(f'--{bnd}--\r\n'.encode())
        data = b''.join(parts)
        headers['Content-Type'] = 'multipart/form-data; boundary=' + bnd
    else:
        data = json.dumps(body).encode()
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(base, data=data, headers=headers, method='POST')
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return json.loads(r.read().decode() or '{}')
    except urllib.error.HTTPError as e:
        try:
            d = json.loads(e.read().decode() or '{}')
        except Exception:
            d = {}
        msg = d.get('error') or f'HTTP {e.code}'
        if e.code == 409 and d.get('project'):
            msg += f" (latest version: {d['project'].get('version')})"
        raise RuntimeError(msg)


def compact_project(p):
    """Claude üçün qısa görünüş: trek/klip xülasəsi + tam timeline"""
    t = p.get('timeline') or {}
    lines = [f"#{p['id']} \"{p['title']}\" v{p['version']} · {t.get('width')}x{t.get('height')} @{t.get('fps')}fps · {t.get('duration')}s · updated by {p.get('updated_by')}"]
    for tr in reversed(t.get('tracks', [])):
        lines.append(f"  track {tr['id']} [{tr['kind']}] \"{tr['name']}\"{' (muted)' if tr.get('muted') else ''}{' (hidden)' if tr.get('hidden') else ''}: {len(tr['clips'])} clips")
        for c in tr['clips'][:60]:
            what = c.get('text') if c['type'] in ('text', 'caption') else (c.get('name') or c.get('src', '').rsplit('=', 1)[-1])
            lines.append(f"    {c['id']} {c['type']} {c['start']}→{round(c['start'] + c['dur'], 3)}s" + (f" in={c.get('in')}" if c.get('in') else '') + f" · {str(what)[:70]}")
    return '\n'.join(lines)


def run_tool(name, a):
    if name == 'media_timeline_schema':
        return SCHEMA_DOC
    if name == 'media_list_projects':
        ps = call('project_list').get('projects', [])
        if not ps:
            return 'No projects yet. Create one with media_create_project.'
        return '\n'.join(f"#{p['id']} \"{p['title']}\" v{p['version']} · {p['width']}x{p['height']} · {p['duration']}s · {p.get('clips', 0)} clips · owner {p['owner']} · updated by {p['updated_by']}" for p in ps)
    if name == 'media_get_project':
        d = call('project_get', {'id': int(a['id'])})
        p = d['project']
        ex = d.get('exports') or []
        extra = '\nExports:\n' + '\n'.join(f"  {x['url']} ({x.get('name')})" for x in ex[:5]) if ex else ''
        return compact_project(p) + extra + '\n\nTimeline JSON:\n' + json.dumps(p['timeline'], ensure_ascii=False)
    if name == 'media_create_project':
        p = call('project_create', {'title': a.get('title', 'Yeni video'), 'preset': a.get('preset', 'landscape')})['project']
        return 'Created.\n' + compact_project(p)
    if name == 'media_apply_edits':
        body = {'id': int(a['id']), 'ops': a.get('ops') or [], 'note': (a.get('note') or 'Claude')[:200]}
        if a.get('base_version') is not None:
            body['base_version'] = int(a['base_version'])
        d = call('project_apply', body)
        return f"Saved as v{d['project']['version']}. Created ids: {', '.join(d.get('created') or []) or '-'}\n" + compact_project(d['project'])
    if name == 'media_list_versions':
        vs = call('project_versions', {'id': int(a['id'])}).get('versions', [])
        return '\n'.join(f"v{v['version']} · {v['author']} · {v.get('note', '')}" for v in vs) or 'No versions.'
    if name == 'media_restore_version':
        p = call('project_restore', {'id': int(a['id']), 'version': int(a['version'])})['project']
        return f"Restored as v{p['version']}.\n" + compact_project(p)
    if name == 'media_list_assets':
        xs = call('asset_list', {'project_id': int(a.get('project_id') or 0)}).get('assets', [])
        return '\n'.join(f"{x['type']:5} {x['kind']:10} {x['url']} · {str(x.get('name', ''))[:60]}" for x in xs[:150]) or 'No assets. Upload with media_upload_file or generate in Fal Connection.'
    if name == 'media_upload_file':
        path = os.path.realpath(os.path.expanduser(str(a.get('path', ''))))
        home = os.path.realpath(os.path.expanduser('~'))
        ext = os.path.splitext(path)[1].lower()
        if not path.startswith(home + os.sep) or not os.path.isfile(path):
            raise RuntimeError('Fayl tapılmadı (yalnız ev qovluğundakı fayllar): ' + str(a.get('path')))
        if ext not in MEDIA_EXTS:
            raise RuntimeError('Yalnız media faylları: ' + ', '.join(sorted(MEDIA_EXTS)))
        size = os.path.getsize(path)
        if size > MAX_UPLOAD:
            raise RuntimeError(f'Fayl {size // 1048576} MB: limit 50 MB')
        render = bool(a.get('as_render'))
        if render and not a.get('project_id'):
            raise RuntimeError('as_render üçün project_id lazımdır')
        with open(path, 'rb') as f:
            data = f.read()
        ctype = mimetypes.guess_type(path)[0] or 'application/octet-stream'
        d = call('upload_render' if render else 'upload', {'project_id': int(a.get('project_id') or 0)},
                 files={'file': (os.path.basename(path), data, ctype)}, timeout=300)
        return f"Uploaded {d.get('type')}: {d.get('url')}" + (' (attached as project render)' if render else '')
    raise RuntimeError('Naməlum alət: ' + name)


def send(obj):
    sys.stdout.write(json.dumps(obj, ensure_ascii=False) + '\n')
    sys.stdout.flush()


def main():
    if '--check' in sys.argv:
        try:
            print('OK:', len(call('project_list').get('projects', [])), 'layihə')
        except Exception as e:
            print('XƏTA:', e)
            sys.exit(1)
        return
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            msg = json.loads(line)
        except ValueError:
            continue
        mid, method = msg.get('id'), msg.get('method')
        if mid is None:                      # bildiriş (notifications/initialized və s.)
            continue
        if method == 'initialize':
            send({'jsonrpc': '2.0', 'id': mid, 'result': {
                'protocolVersion': (msg.get('params') or {}).get('protocolVersion') or PROTO,
                'capabilities': {'tools': {}},
                'serverInfo': {'name': 'planet-media', 'version': '1.0.0'},
                'instructions': 'Planet MEDIA timeline editor. Read media_timeline_schema once, then media_get_project before editing; batch edits in media_apply_edits with base_version and a short note.'}})
        elif method == 'tools/list':
            send({'jsonrpc': '2.0', 'id': mid, 'result': {'tools': TOOLS}})
        elif method == 'tools/call':
            p = msg.get('params') or {}
            try:
                text = run_tool(p.get('name', ''), p.get('arguments') or {})
                send({'jsonrpc': '2.0', 'id': mid, 'result': {'content': [{'type': 'text', 'text': text}]}})
            except Exception as e:
                send({'jsonrpc': '2.0', 'id': mid, 'result': {'content': [{'type': 'text', 'text': 'Error: ' + str(e)}], 'isError': True}})
        elif method == 'ping':
            send({'jsonrpc': '2.0', 'id': mid, 'result': {}})
        else:
            send({'jsonrpc': '2.0', 'id': mid, 'error': {'code': -32601, 'message': 'Method not found: ' + str(method)}})


if __name__ == '__main__':
    main()
