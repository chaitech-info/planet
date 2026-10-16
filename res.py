import re,sys
def hunks(path, picks):
    s=open(path,encoding='utf-8').read()
    pat=re.compile(r'<<<<<<< HEAD\n(.*?)=======\n(.*?)>>>>>>> origin/feature/desktop-chats\n',re.S)
    i=[0]
    def rep(m):
        f=picks[i[0]]; i[0]+=1
        return f(m.group(1),m.group(2))
    s=pat.sub(rep,s)
    assert i[0]==len(picks),(path,i[0])
    open(path,'w',encoding='utf-8').write(s)
def sub(a,b):
    def f(h,t):
        assert a in h,(a,)
        return h.replace(a,b)
    return f
P='public_html/'
hunks(P+'feed.php',[sub("mission_id, step\n","mission_id, step, chat\n")])
hunks(P+'jarvis.php',[lambda h,t: h.replace("$users = array_column($pdo->query('SELECT name FROM users ORDER BY name')->fetchAll(), 'name');\n",t)])
hunks(P+'agent.php',[
 sub("images FROM commands","images, chat FROM commands"),
 sub("'images' => $images];","'images' => $images, 'chat' => (int)$row['chat']];")])
def js(h,t):
    return """    const label = txt || ST[s] || s;
    if (J.state === s && stEl.textContent === label) return;      // hər səs parçasında (saniyədə onlarla) DOM-a toxunmayaq
    J.state = s; stEl.textContent = label;
    emit('state', s);
    const off = s === 'off' || s === 'error';
    btn.classList.toggle('on', !off);
    goEl.textContent = off ? '▶ Başla' : '■ Bitir';
    goEl.classList.toggle('go', off);
"""
hunks(P+'jarvis.js',[js])
A='agent/claude-team-agent.py'
def h1(h,t):
    return h.replace("os.path.expanduser('~/.claude-team-sessions.json'))   # proyekt+göndərən","os.path.expanduser('~/.claude-team-sessions.json'))   # söhbət ('chat:<id>') və ya proyekt+göndərən").replace("SESSION_TTL = 24 * 3600","SESSION_TTL = 30 * 24 * 3600                                     # köhnə söhbətə qayıtmaq olsun (Claude Code sessiyaları da ~30 gün saxlayır)")
def h2(h,t):
    old="    key = path + '|' + (cmd.get('creator') or '')\n    sid = None if (cmd.get('fresh') or wt) else (sess_load().get(key) or {}).get('sid')\n"
    assert old in h
    new="""    legacy = path + '|' + (cmd.get('creator') or '')
    key = f"chat:{cmd['chat']}" if cmd.get('chat') else legacy
    sid = None
    if not (cmd.get('fresh') or wt):
        store = sess_load()
        sid = (store.get(key) or store.get(legacy) or {}).get('sid')   # legacy: söhbətlərdən əvvəl başlanmış söhbət
"""
    return h.replace(old,new).replace("Eyni proyekt və göndərən üçün əvvəlki söhbəti","Söhbətin (chat) və ya proyekt+göndərənin əvvəlki sessiyasını")
hunks(A,[h1,h2,lambda h,t:h])
