/* Jarvis: Gemini Live API ilə real-time səsli agent.
   Brauzer jarvis.php-dən qısa ömürlü token alır və Gemini-yə birbaşa WebSocket ilə qoşulur:
   mikrofon → 16 kHz PCM → Gemini → 24 kHz PCM → dinamik. Alətləri (tool) burada, panelin mövcud funksiyaları ilə icra edirik.
   index.php-dəki qlobal funksiyalardan istifadə edir: data, TSK, WLD, cmdApi, go, load, ME və s. */
(() => {
  const J = { watch: new Map(), ws: null, state: 'off', micCtx: null, micNode: null, micStream: null, outCtx: null, outGain: null, outAn: null, micLv: 0,
    nextT: 0, sources: new Set(), muted: false, t0: 0, lastUsage: 0, usageTimer: null, ending: false, lines: [], cur: { user: null, jarvis: null }, session: null };

  /* ---------- UI ---------- */
  const css = `
#jv-btn.on { background: linear-gradient(135deg,#0ea5e9,#f59e0b); border-color: transparent; color: #fff; }
.jv { position: fixed; right: 18px; bottom: 18px; z-index: 70; width: min(370px, calc(100vw - 32px)); max-height: min(560px, calc(100vh - 36px)); display: flex; flex-direction: column;
  background: rgba(8,10,20,.86); -webkit-backdrop-filter: blur(18px) saturate(1.3); backdrop-filter: blur(18px) saturate(1.3); color: #e9eef8; border: 1px solid rgba(125,211,252,.28);
  border-radius: 20px; box-shadow: 0 18px 50px rgba(0,0,0,.5), 0 0 0 1px rgba(0,0,0,.2); font-size: 13.5px; overflow: hidden; }
.jv[hidden] { display: none; }
.jv-top { display: flex; align-items: center; gap: 12px; padding: 14px 14px 10px; }
.jv-orb { width: 74px; height: 74px; flex: none; }
.jv-ttl { flex: 1; min-width: 0; } .jv-ttl b { display: block; font-size: 16px; letter-spacing: .14em; } .jv-ttl span { color: rgba(203,213,225,.75); font-size: 12.5px; }
.jv-x { border: 0; background: rgba(255,255,255,.08); color: #e9eef8; width: 30px; height: 30px; border-radius: 9px; cursor: pointer; font-size: 15px; }
.jv-log { flex: 1; overflow: auto; padding: 4px 14px 8px; display: grid; gap: 6px; align-content: start; min-height: 80px; scrollbar-width: thin; }
.jv-l { line-height: 1.4; } .jv-l.u { color: #cbd5e1; } .jv-l.u::before { content: "Sən: "; color: #94a3b8; } .jv-l.j { color: #e0f2fe; } .jv-l.j::before { content: "Jarvis: "; color: #38bdf8; font-weight: 600; }
.jv-l.a { font-size: 12.5px; color: #fcd34d; } .jv-l.e { font-size: 12.5px; color: #fca5a5; } .jv-l.i { font-size: 12.5px; color: #94a3b8; }
.jv-bar { display: flex; gap: 8px; padding: 10px 14px 14px; border-top: 1px solid rgba(255,255,255,.08); }
.jv-bar button { flex: 1; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9eef8; border-radius: 999px; padding: 8px 10px; font: inherit; font-size: 13px; cursor: pointer; transition: transform .15s ease; }
.jv-bar button:active { transform: scale(.97); } .jv-bar .go { background: linear-gradient(135deg,#0284c7,#d97706); border-color: transparent; font-weight: 600; }
.jv-bar .on { background: rgba(248,113,113,.18); border-color: rgba(248,113,113,.5); }
.jv-hint { padding: 0 14px 10px; font-size: 12px; color: rgba(148,163,184,.9); }
@media (prefers-reduced-motion: reduce) { .jv-orb { animation: none; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const box = document.createElement('div'); box.className = 'jv'; box.hidden = true;
  box.innerHTML = `<div class="jv-top"><canvas class="jv-orb" width="148" height="148"></canvas><div class="jv-ttl"><b>J.A.R.V.I.S.</b><span id="jv-st">hazırdır</span></div><button class="jv-x" id="jv-close" aria-label="Bağla">✕</button></div>
<div class="jv-log" id="jv-log" aria-live="polite"></div>
<div class="jv-hint" id="jv-hint">Danışmağa başla: sual ver və ya “neo planetinə uç” de. Qulaqcıqla daha yaxşı işləyir. Qısayol: Alt+J</div>
<div class="jv-bar"><button id="jv-mic">🎙 Mikrofon açıq</button><button class="go" id="jv-go">▶ Başla</button></div>`;
  document.body.append(box);
  const $j = id => document.getElementById(id);
  const orb = box.querySelector('.jv-orb'), og = orb.getContext('2d');
  const btn = document.createElement('button'); btn.className = 'gbtn'; btn.id = 'jv-btn'; btn.title = 'Jarvis: səsli köməkçi (Alt+J)'; btn.textContent = '🎙 Jarvis';
  const meta = document.querySelector('header .meta'); if (meta) meta.insertBefore(btn, meta.firstChild.nextSibling);

  const ST = { off: 'hazırdır', connecting: 'qoşulur…', listening: 'dinləyirəm', speaking: 'danışır', tool: 'icra edir…', muted: 'mikrofon bağlıdır', error: 'xəta' };
  function setState(s, txt) {
    J.state = s; $j('jv-st').textContent = txt || ST[s] || s;
    btn.classList.toggle('on', s !== 'off' && s !== 'error');
    $j('jv-go').textContent = s === 'off' || s === 'error' ? '▶ Başla' : '■ Bitir';
    $j('jv-go').classList.toggle('go', s === 'off' || s === 'error');
  }
  function line(kind, text) {
    const n = document.createElement('div'); n.className = 'jv-l ' + kind; n.textContent = text;
    const log = $j('jv-log'); log.append(n); while (log.children.length > 80) log.firstChild.remove();
    log.scrollTop = log.scrollHeight; return n;
  }
  /** Canlı transkript: eyni növbədəki parçalar bir sətrə yığılır */
  function stream(who, text) {
    if (!text) return;
    let n = J.cur[who];
    if (!n) { n = J.cur[who] = line(who === 'user' ? 'u' : 'j', ''); n._t = ''; }
    n._t += text; n.textContent = n._t.replace(/\s+/g, ' ').trim();
    $j('jv-log').scrollTop = $j('jv-log').scrollHeight;
  }
  const endTurn = () => { J.cur.user = null; J.cur.jarvis = null; };

  /* Orb: səsə görə döyünən halqalar (dinləyəndə mavi, danışanda qızılı) */
  let orbLv = 0, orbT = 0;
  function drawOrb(now) {
    requestAnimationFrame(drawOrb);
    if (box.hidden) return;
    orbT = now / 1000;
    let lv = 0;
    if (J.outAn && J.state === 'speaking') { const a = new Uint8Array(J.outAn.fftSize); J.outAn.getByteTimeDomainData(a); let s = 0; for (const v of a) s += ((v - 128) / 128) ** 2; lv = Math.sqrt(s / a.length) * 4; }
    else if (J.state === 'listening') lv = J.micLv * 5;
    orbLv += (Math.min(1, lv) - orbLv) * .25;
    const W = orb.width, c = W / 2;
    og.clearRect(0, 0, W, W);
    const hue = J.state === 'speaking' ? [251, 191, 36] : J.state === 'tool' ? [192, 132, 252] : J.state === 'error' ? [248, 113, 113] : J.state === 'off' ? [100, 116, 139] : [56, 189, 248];
    const col = a => `rgba(${hue[0]},${hue[1]},${hue[2]},${a})`;
    const g = og.createRadialGradient(c, c, 4, c, c, c);
    g.addColorStop(0, col(.95)); g.addColorStop(.25 + orbLv * .2, col(.35)); g.addColorStop(1, col(0));
    og.fillStyle = g; og.beginPath(); og.arc(c, c, c * (.55 + orbLv * .4), 0, 6.2832); og.fill();
    og.lineWidth = 2.2;
    for (let i = 0; i < 3; i++) {
      const r = c * (.42 + i * .17) * (1 + orbLv * .18 * (3 - i)), sp = (i % 2 ? -1 : 1) * (.6 + i * .35);
      og.strokeStyle = col(.75 - i * .2); og.beginPath();
      og.arc(c, c, r, orbT * sp, orbT * sp + 4.4 - i * .7); og.stroke();
    }
    og.fillStyle = 'rgba(255,255,255,.92)'; og.beginPath(); og.arc(c, c, 6 + orbLv * 8, 0, 6.2832); og.fill();
  }
  requestAnimationFrame(drawOrb);

  /* ---------- Köməkçilər ---------- */
  const norm = s => String(s || '').toLocaleLowerCase('az').normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/ə/g, 'e').replace(/ı/g, 'i').replace(/[^a-z0-9: ]+/g, ' ').replace(/\s+/g, ' ').trim();
  function lev(a, b) {
    if (Math.abs(a.length - b.length) > 3) return 9;
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 1; j <= b.length; j++) d[0][j] = j;
    for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[a.length][b.length];
  }
  /** Ən uyğun element (səslə deyilən ad dəqiq olmaya bilər): tam, başlanğıc, daxilində, söz və yazılış yaxınlığı */
  function best(items, q, key) {
    const nq = norm(q); if (!nq) return null;
    let top = null, ts = 0;
    for (const it of items) {
      for (const k of [].concat(key(it))) {
        const nk = norm(k); if (!nk) continue;
        let s = nk === nq ? 100 : nk.startsWith(nq) || nq.startsWith(nk) ? 80 : nk.includes(nq) || nq.includes(nk) ? 65 : 0;
        if (!s) { const w = nq.split(' ').filter(x => x.length > 2 && nk.includes(x)).length; if (w) s = 40 + w * 8; }
        if (!s && lev(nk, nq) <= Math.max(1, Math.floor(nq.length / 3))) s = 55;      // səs tanımada tez-tez olan 1-2 hərf səhvi
        if (s > ts) { ts = s; top = it; }
      }
    }
    return ts >= 40 ? top : null;
  }
  const when = ts => new Date(ts * 1000).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });
  const parseWhen = v => { if (!v || /^(none|yox|sil)$/i.test(String(v).trim())) return 0; const t = new Date(String(v).trim()); return isNaN(t) ? NaN : Math.floor(t / 1000); };
  const userName = u => { if (!u || /^(me|men|mən|özüm|ozum)$/i.test(u)) return ME.name; const m = best(data.users || [], u, x => x); return m; };
  const planetNames = () => {
    const out = new Map();
    for (const u of UNIVERSES) for (const p of uniList(spaceList(buildProjects()), u.id)) out.set(p.name, { name: p.name, label: (p.label || p.name).replace(/^\S+\s/, m => /\p{Extended_Pictographic}/u.test(m) ? '' : m), uni: u.id });
    return [...out.values()];
  };
  const pickTask = q => best((data.tasks || []).slice().sort((a, b) => (a.status === 'done') - (b.status === 'done')), q, t => t.title);
  async function findTask(q) { let t = pickTask(q); if (!t) { await load(); t = pickTask(q); } return t; }   // yeni yaradılıbsa, məlumatı təzələ
  const need = (a, ...ks) => { for (const k of ks) if (a[k] === undefined || a[k] === null || a[k] === '') throw new Error('"' + k + '" lazımdır'); };
  const ok = (msg, extra) => { line('a', '✓ ' + msg); return { ok: true, result: msg, ...extra }; };
  function confirmed(a) { if (a.confirmed !== true) throw new Error('Not confirmed: describe the action to the user, ask for confirmation, then call again with confirmed=true.'); }
  const R = async res => { const { status, d } = await res; if (status !== 200) throw new Error(d.error || 'xəta ' + status); return d; };

  /* ---------- Alətlər ---------- */
  const TOOLS = {
    get_overview() {
      const all = buildProjects(), t = now();
      return {
        now: when(t), me: ME.name,
        users: (data.users || []).map(u => {
          const ss = (data.sessions || []).filter(s => s.user === u).map(s => ({ ...s, st: eff(s) })).filter(s => RANK[s.st] > 0);
          return { name: u, status: ss.length ? ss.sort((a, b) => RANK[b.st] - RANK[a.st])[0].st : isOnline(u) ? 'on dashboard' : 'offline', projects: ss.map(s => s.project), doing: ss.map(s => s.last_prompt).filter(Boolean).slice(0, 2) };
        }),
        projects: all.map(p => ({ name: p.name, status: p.st, last_activity: ago(p.last), burning: burnOf(p.name) > 0, hours_without_finished_task: Math.floor(idleOf(p.name) / 3600) || 0,
          open_tasks: (data.tasks || []).filter(x => x.project === p.name && x.status !== 'done').length, failing_tests: (data.tests || []).filter(x => x.project === p.name && x.status === 'fail').length })),
        my_open_tasks: (data.tasks || []).filter(x => x.assignee === ME.name && x.status !== 'done').map(x => ({ title: x.title, project: x.project, status: x.status, due: dueInfo(x) ? dueInfo(x).text : null })),
        overdue_tasks: (data.tasks || []).filter(x => dueInfo(x) && dueInfo(x).left < 0).map(x => ({ title: x.title, assignee: x.assignee, project: x.project })),
        meetings: (data.meetings || []).filter(m => +m.starts_at + m.minutes * 60 > t).slice(0, 5).map(m => ({ title: m.title, start: when(m.starts_at), minutes: +m.minutes, live: +m.starts_at <= t, attendees: m.attendees })),
        agents: (data.users || []).map(u => { const a = agentInfo(u); return { user: u, online: a.online, running: a.run.length, queued: a.queued.length, projects: a.ag && a.ag.projects ? a.ag.projects.split('\n').filter(Boolean) : [] }; }),
        ship_deadlines: (data.ships || []).filter(s => +s.deadline).map(s => ({ user: s.user, mission: s.mission, due: when(s.deadline), overdue: s.deadline < t })),
      };
    },
    get_project(a) {
      need(a, 'name');
      const pl = best(planetNames(), a.name, p => [p.name, p.label]);
      if (!pl) throw new Error('Belə planet tapılmadı: ' + a.name);
      const n = pl.name, p = buildProjects().find(x => x.name === n);
      return {
        name: n, universe: pl.uni, status: p ? p.st : null, last_activity: p ? ago(p.last) : null, sessions: p ? p.sessions : 0, edits: p ? p.edits : 0,
        burning: burnOf(n) > 0, hours_without_finished_task: Math.floor(idleOf(n) / 3600) || 0,
        active_sessions: (data.sessions || []).filter(s => s.project === n).map(s => ({ user: s.user, status: eff(s), prompt: s.last_prompt, last: s.last_summary })).filter(s => RANK[s.status] > 0),
        recent_events: (data.events || []).filter(e => e.project === n).slice(0, 8).map(e => e.user + ': ' + e.summary + ' (' + ago(e.created_at) + ')'),
        tasks: (data.tasks || []).filter(x => x.project === n).map(x => ({ title: x.title, status: x.status, assignee: x.assignee, due: dueInfo(x) ? dueInfo(x).text : null })),
        tests: (data.tests || []).filter(x => x.project === n).map(x => ({ title: x.title, result: x.status, note: x.note || undefined })),
        mcp_servers: satsOf(n).map(s => s.name),
      };
    },
    list_tasks(a) {
      let ts = data.tasks || [];
      if (a.assignee) { const u = userName(a.assignee); ts = ts.filter(t => t.assignee === u); }
      if (a.project) { const pl = best(planetNames(), a.project, p => [p.name, p.label]); ts = ts.filter(t => t.project === (pl ? pl.name : a.project)); }
      const st = a.status || 'open';
      if (st === 'open') ts = ts.filter(t => t.status !== 'done'); else if (st !== 'any') ts = ts.filter(t => t.status === st);
      return { count: ts.length, tasks: ts.slice(0, 25).map(t => ({ title: t.title, project: t.project, assignee: t.assignee, status: t.status, due: dueInfo(t) ? dueInfo(t).text : null })) };
    },
    async get_report(a) {
      const d = await R(REP('get', a.day === 'yesterday' ? 'yesterday' : 'today'));
      return { title: d.title, rows: d.rows.map(r => ({ user: r.user, prompts: r.prompts, edits: r.edits, projects: r.projects, finished: r.done, doing: r.doing, review: r.review, todo: r.todo })) };
    },
    navigate(a) {
      need(a, 'target');
      const q = norm(a.target);
      if (/^(map|xerite|kosmik xerite|butun|hamisi|kainat)$/.test(q)) { location.hash = '#kainat'; return ok('Kosmik xəritə'); }
      if (/(board|lovhe|tapsiriq lovhesi)/.test(q)) { location.hash = '#lovhe'; return ok('Lövhə'); }
      if (/^(panel|esas|ana sehife)$/.test(q)) { location.hash = '#'; return ok('Panel'); }
      if (/^(plan|plan gemisi|plan stansiyasi)$/.test(q)) { go(SHIPKEY); return ok('Plan stansiyası'); }
      const u = best(UNIVERSES, a.target, x => [x.name, x.id, x.name.replace(' Universe', '')]);
      const pl = best(planetNames(), a.target, p => [p.name, p.label]);
      if (u && (!pl || /univers|qalaktika|galaxy/.test(q) || norm(u.name) === q || u.id === q)) { location.hash = '#kainat/@' + u.id; return ok(u.name); }
      if (!pl) throw new Error('Belə yer tapılmadı: ' + a.target);
      go(pl.name); return ok(pl.label || pl.name);
    },
    async create_task(a) {
      need(a, 'title', 'project'); confirmed(a);
      const pl = best(planetNames(), a.project, p => [p.name, p.label]);
      const assignee = a.assignee ? userName(a.assignee) : ME.name;
      if (!assignee) throw new Error('Belə istifadəçi yoxdur: ' + a.assignee);
      const due = parseWhen(a.due); if (Number.isNaN(due)) throw new Error('Tarix başa düşülmədi: ' + a.due);
      const project = pl ? pl.name : a.project;
      await R(TSK('create', { title: a.title, details: a.details || '', project, assignee, due_at: due || 0 }));
      await load(); return ok(`Tapşırıq yaradıldı: “${a.title}” → ${assignee}, ${project}` + (due ? ', son tarix ' + when(due) : ''));
    },
    async set_task_status(a) {
      need(a, 'task', 'status'); confirmed(a);
      const t = await findTask(a.task); if (!t) throw new Error('Tapşırıq tapılmadı: ' + a.task);
      await R(TSK('move', { id: t.id, status: a.status })); await load();
      return ok(`“${t.title}” → ${a.status}`);
    },
    async set_task_due(a) {
      need(a, 'task', 'due'); confirmed(a);
      const t = await findTask(a.task); if (!t) throw new Error('Tapşırıq tapılmadı: ' + a.task);
      const due = parseWhen(a.due); if (Number.isNaN(due)) throw new Error('Tarix başa düşülmədi: ' + a.due);
      await R(TSK('due', { id: t.id, due_at: due })); await load();
      return ok(`“${t.title}” son tarix: ${due ? when(due) : 'silindi'}`);
    },
    async set_ship_deadline(a) {
      need(a, 'due'); confirmed(a);
      const due = parseWhen(a.due); if (Number.isNaN(due)) throw new Error('Tarix başa düşülmədi: ' + a.due);
      const s = shipOf(ME.name);
      await R(WLD('ship_save', { name: s.name, model: s.model, color: s.color, accent: s.accent, deadline: due, mission: a.mission || s.mission || '' })); await load();
      return ok(due ? `Gəmi deadline-ı: ${when(due)}` + (a.mission ? ' (' + a.mission + ')' : '') : 'Gəmi deadline-ı silindi');
    },
    async schedule_meeting(a) {
      need(a, 'title', 'start'); confirmed(a);
      const st = parseWhen(a.start); if (!st) throw new Error('Başlama vaxtı başa düşülmədi: ' + a.start);
      const att = (a.attendees && a.attendees.length ? a.attendees.map(userName).filter(Boolean) : (data.users || []).slice());
      await R(WLD('meeting_save', { title: a.title, agenda: a.agenda || '', starts_at: st, minutes: +a.minutes || 30, attendees: att })); await load();
      return ok(`Görüş: “${a.title}”, ${when(st)}, ${+a.minutes || 30} dəq, ${att.join(', ')}`);
    },
    async record_test_result(a) {
      need(a, 'project', 'test', 'result'); confirmed(a);
      const pl = best(planetNames(), a.project, p => [p.name, p.label]); const project = pl ? pl.name : a.project;
      let t = best((data.tests || []).filter(x => x.project === project), a.test, x => x.title);
      if (!t) { const d = await R(WLD('test_save', { project, title: a.test, steps: '' })); t = { id: d.id, title: a.test }; }
      await R(WLD('test_status', { id: t.id, status: a.result, note: a.note || '' })); await load();
      return ok(`Test “${t.title}” (${project}): ${a.result === 'pass' ? 'keçdi' : a.result === 'fail' ? 'xəta' : 'yoxlanmayıb'}`);
    },
    async send_message(a) {
      need(a, 'to', 'text'); confirmed(a);
      const to = /^(all|hami|hamiya|hamısı|hamıya|\*)$/i.test(norm(a.to)) ? '*' : userName(a.to);
      if (!to) throw new Error('Belə istifadəçi yoxdur: ' + a.to);
      const r = await fetch('message.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'send', to, body: String(a.text).slice(0, 500) }) });
      const d = await r.json().catch(() => ({})); if (r.status !== 200 || !d.message) throw new Error(d.error || 'Göndərilmədi');
      msgSeen.add(+d.message.id); data.messages = [d.message, ...(data.messages || [])]; playMessage(d.message); load();
      return ok(`Mesaj raketlə göndərildi → ${to === '*' ? 'hamı' : to}`);
    },
    async run_claude_code(a) {
      need(a, 'prompt'); confirmed(a);
      let project = null;
      if (a.project) { const pl = best(planetNames(), a.project, p => [p.name, p.label]); project = pl ? pl.name : a.project; }
      else if (curPlanet && !xKind(curPlanet)) project = curPlanet;                       // baxdığı planet
      else { const ps = buildProjects(); if (ps.length === 1) project = ps[0].name; }
      if (!project) throw new Error('Hansı proyektdə? Proyekt adını soruş.');
      const r = await fetch('jarvis.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'agent_send', project, prompt: a.prompt }) });
      const d = await r.json().catch(() => ({})); if (r.status !== 200) throw new Error(d.error || 'Göndərilmədi');
      J.watch.set(+d.id, { project, prompt: a.prompt, st: 'queued' });
      await load();
      line('a', `🤖 Claude Code-a göndərildi (#${d.id}, ${project})` + (d.agent_online ? '' : ' · agent oflayndır, növbədə gözləyir'));
      return { ok: true, task_id: d.id, project, agent_online: !!d.agent_online, claude_login: d.claude_login,
        note: d.agent_online ? 'Running on the user\'s computer; a note will arrive when it finishes.' : 'Agent is offline: queued, it starts when the agent on the user\'s computer runs.' };
    },
    get_claude_tasks(a) {
      let cs = (data.commands || []).filter(c => c.user === ME.name);
      if (a && a.project) { const pl = best(planetNames(), a.project, p => [p.name, p.label]); cs = cs.filter(c => c.project === (pl ? pl.name : a.project)); }
      return { tasks: cs.slice(0, 8).map(c => ({ id: +c.id, project: c.project, status: c.status, prompt: c.prompt.slice(0, 200), result: (c.result || '').slice(-600), created: ago(c.created_at) })) };
    },
    end_conversation() { J.ending = true; return { ok: true }; },
  };

  async function runTools(calls) {
    setState('tool');
    const out = [];
    if (!data) { try { await load(); } catch (e) {} }           // məlumat hələ yüklənməyibsə
    for (const fc of calls) {
      let response;
      try {
        const fn = TOOLS[fc.name]; if (!fn) throw new Error('naməlum alət: ' + fc.name);
        response = { result: await fn(fc.args || {}) };
      } catch (e) {
        const msg = String(e && e.message || e);
        if (!/^Not confirmed/.test(msg)) line('e', '✗ ' + msg);
        response = { error: msg };
      }
      out.push({ id: fc.id, name: fc.name, response });
    }
    send({ toolResponse: { functionResponses: out } });
    if (J.state === 'tool') setState('listening');
    if (J.ending) setTimeout(waitAndStop, 300);
  }
  function waitAndStop() { if (J.outCtx && J.nextT > J.outCtx.currentTime + .05) return setTimeout(waitAndStop, 300); stop('Görüşənədək'); }

  /* Claude Code tapşırıqlarını izlə: bitəndə Jarvis nəticəni səsli danışır */
  setInterval(() => {
    if (!J.watch.size || !data || !data.commands) return;   // data: index.php-dəki qlobal (let), window-da deyil
    for (const [id, w] of J.watch) {
      const c = data.commands.find(x => +x.id === id); if (!c || c.status === w.st) continue;
      w.st = c.status;
      if (c.status === 'running') { line('i', `🤖 Claude Code işləyir (#${id}, ${w.project})…`); continue; }
      if (c.status === 'queued') continue;
      J.watch.delete(id);
      const res = (c.result || '').trim();
      line(c.status === 'done' ? 'a' : 'e', `🤖 Claude Code ${c.status === 'done' ? 'bitirdi' : c.status === 'canceled' ? 'dayandırıldı' : 'xəta verdi'} (#${id}, ${w.project})`);
      if (J.ready) send({ clientContent: { turns: [{ role: 'user', parts: [{ text:
        `[System note, not spoken by the user] Claude Code task #${id} in project "${w.project}" finished with status "${c.status}". Task: ${w.prompt.slice(0, 300)}\nResult (may be truncated):\n${res.slice(-2500) || '(empty)'}\nTell the user the outcome in 1-2 short sentences.` }] }], turnComplete: true } });
    }
  }, 1500);

  /* ---------- Səs ---------- */
  const WORKLET = `class JvMic extends AudioWorkletProcessor {
    constructor() { super(); this.ratio = sampleRate / 16000; this.t = 0; this.last = 0; this.out = new Int16Array(640); this.n = 0; this.lv = 0; }
    process(inputs) {
      const x = inputs[0] && inputs[0][0]; if (!x) return true;
      let s = 0; for (let i = 0; i < x.length; i++) s += x[i] * x[i]; this.lv = Math.sqrt(s / x.length);
      for (;;) {
        const i = Math.floor(this.t); if (i + 1 >= x.length) break;
        const v = i < 0 ? this.last + (x[0] - this.last) * (this.t + 1) : x[i] + (x[i + 1] - x[i]) * (this.t - i);
        this.out[this.n++] = Math.max(-32768, Math.min(32767, v * 32767));
        if (this.n === this.out.length) { this.port.postMessage({ pcm: this.out.buffer.slice(0), lv: this.lv }); this.n = 0; }
        this.t += this.ratio;
      }
      this.t -= x.length; this.last = x[x.length - 1];
      return true;
    }
  } registerProcessor('jv-mic', JvMic);`;
  function b64(buf) { const u = new Uint8Array(buf); let s = ''; for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode.apply(null, u.subarray(i, i + 0x8000)); return btoa(s); }
  async function startMic() {
    J.micStream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
    J.micCtx = new (window.AudioContext || window.webkitAudioContext)();
    await J.micCtx.audioWorklet.addModule(URL.createObjectURL(new Blob([WORKLET], { type: 'application/javascript' })));
    const src = J.micCtx.createMediaStreamSource(J.micStream);
    J.micNode = new AudioWorkletNode(J.micCtx, 'jv-mic');
    J.micNode.port.onmessage = e => {
      J.micLv = e.data.lv;
      if (!J.muted && J.ws && J.ws.readyState === 1 && J.ready) send({ realtimeInput: { audio: { data: b64(e.data.pcm), mimeType: 'audio/pcm;rate=16000' } } });
    };
    src.connect(J.micNode);
  }
  function startOut() {
    J.outCtx = new (window.AudioContext || window.webkitAudioContext)();
    J.outGain = J.outCtx.createGain(); J.outAn = J.outCtx.createAnalyser(); J.outAn.fftSize = 512;
    J.outGain.connect(J.outAn); J.outAn.connect(J.outCtx.destination);
    J.nextT = 0;
  }
  function play(b64data) {
    const bin = atob(b64data), n = bin.length >> 1, buf = J.outCtx.createBuffer(1, n, 24000), ch = buf.getChannelData(0);
    for (let i = 0; i < n; i++) { let v = bin.charCodeAt(i * 2) | (bin.charCodeAt(i * 2 + 1) << 8); if (v >= 32768) v -= 65536; ch[i] = v / 32768; }
    const src = J.outCtx.createBufferSource(); src.buffer = buf; src.connect(J.outGain);
    const t = Math.max(J.nextT, J.outCtx.currentTime + .03); src.start(t); J.nextT = t + buf.duration;
    J.sources.add(src); src.onended = () => { J.sources.delete(src); if (!J.sources.size && J.state === 'speaking') setState(J.muted ? 'muted' : 'listening'); };
    if (J.state !== 'tool') setState('speaking');
  }
  function stopPlayback() { for (const s of J.sources) { try { s.stop(); } catch (e) {} } J.sources.clear(); J.nextT = 0; }

  /* ---------- Sessiya ---------- */
  const send = o => { if (J.ws && J.ws.readyState === 1) J.ws.send(JSON.stringify(o)); };
  async function api(action, extra) {
    const r = await fetch('jarvis.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    return { status: r.status, d: await r.json().catch(() => ({})) };
  }
  async function start() {
    if (J.state !== 'off' && J.state !== 'error') return;
    if (!window.isSecureContext || !navigator.mediaDevices) { setState('error'); line('e', 'Mikrofon yalnız HTTPS və ya localhost-da işləyir.'); return; }
    setState('connecting'); J.ending = false; J.ready = false; endTurn();
    const { status, d } = await api('session');
    if (status !== 200) {
      setState('error', status === 503 ? 'açar qurulmayıb' : 'xəta');
      line('e', d.error || 'Sessiya alınmadı');
      if (d.setup) line('i', d.setup);
      return;
    }
    J.session = d;
    try { startOut(); await startMic(); }
    catch (e) { cleanup(); setState('error'); line('e', 'Mikrofona icazə verilmədi: ' + (e.message || e)); return; }
    const ws = J.ws = new WebSocket(d.ws + '?access_token=' + encodeURIComponent(d.token));
    ws.onopen = () => send({ setup: d.setup });
    ws.onmessage = async ev => {
      let m; try { m = JSON.parse(typeof ev.data === 'string' ? ev.data : await ev.data.text()); } catch (e) { return; }
      if (m.setupComplete) {
        J.ready = true; J.t0 = J.lastUsage = Date.now(); setState(J.muted ? 'muted' : 'listening');
        line('i', 'Qoşuldu · ' + d.model);
        J.usageTimer = setInterval(reportUsage, 30000);
        return;
      }
      const sc = m.serverContent;
      if (sc) {
        if (sc.interrupted) { stopPlayback(); endTurn(); setState(J.muted ? 'muted' : 'listening'); }
        if (sc.inputTranscription && sc.inputTranscription.text) { J.cur.jarvis = null; stream('user', sc.inputTranscription.text); }
        if (sc.outputTranscription && sc.outputTranscription.text) stream('jarvis', sc.outputTranscription.text);
        for (const p of (sc.modelTurn && sc.modelTurn.parts) || []) if (p.inlineData && p.inlineData.data) play(p.inlineData.data);
        if (sc.turnComplete) endTurn();
      }
      if (m.toolCall && m.toolCall.functionCalls) runTools(m.toolCall.functionCalls);
      if (m.goAway) line('i', 'Sessiya tezliklə bitəcək (Gemini limiti). Yenidən başlatmaq lazım ola bilər.');
    };
    ws.onerror = () => { };
    ws.onclose = ev => {
      if (J.state === 'off') return;
      const why = ev.reason ? ev.reason : ev.code === 1006 ? 'bağlantı qırıldı' : 'kod ' + ev.code;
      if (!J.ending && ev.code !== 1000) { line('e', 'Gemini bağlantısı bağlandı: ' + why); }
      cleanup(); setState(J.ending || ev.code === 1000 ? 'off' : 'error');
    };
  }
  async function reportUsage() {
    const s = Math.round((Date.now() - J.lastUsage) / 1000); J.lastUsage = Date.now();
    if (s <= 0) return;
    const { d } = await api('usage', { seconds: s });
    if (d.over) { line('i', 'Bu günün Jarvis limiti bitdi.'); stop(); }
  }
  function cleanup() {
    clearInterval(J.usageTimer); J.usageTimer = null;
    if (J.ready && J.lastUsage) { const s = Math.round((Date.now() - J.lastUsage) / 1000); if (s > 0) api('usage', { seconds: s }); }
    J.ready = false; J.lastUsage = 0;
    stopPlayback();
    if (J.micStream) J.micStream.getTracks().forEach(t => t.stop());
    if (J.micCtx) J.micCtx.close().catch(() => {});
    if (J.outCtx) J.outCtx.close().catch(() => {});
    J.micStream = J.micCtx = J.micNode = J.outCtx = J.outAn = J.outGain = null;
    const ws = J.ws; J.ws = null; if (ws && ws.readyState <= 1) { try { ws.close(1000); } catch (e) {} }
  }
  function stop(msg) { if (J.state === 'off') return; J.state = 'off'; cleanup(); setState('off'); if (msg) line('i', msg); }
  function toggleMute() {
    J.muted = !J.muted;
    $j('jv-mic').textContent = J.muted ? '🔇 Mikrofon bağlı' : '🎙 Mikrofon açıq'; $j('jv-mic').classList.toggle('on', J.muted);
    if (J.muted && J.ready) send({ realtimeInput: { audioStreamEnd: true } });
    if (J.ready && J.state !== 'speaking' && J.state !== 'tool') setState(J.muted ? 'muted' : 'listening');
  }

  btn.onclick = () => { box.hidden = !box.hidden; if (!box.hidden && J.state === 'off') start(); };
  $j('jv-close').onclick = () => { stop(); box.hidden = true; };
  $j('jv-go').onclick = () => (J.state === 'off' || J.state === 'error') ? start() : stop('Sessiya bitdi');
  $j('jv-mic').onclick = toggleMute;
  addEventListener('keydown', e => {
    if (e.altKey && (e.code === 'KeyJ')) { e.preventDefault(); if (box.hidden) { box.hidden = false; start(); } else if (J.state === 'off' || J.state === 'error') start(); else stop('Sessiya bitdi'); }
  });
  addEventListener('beforeunload', () => cleanup());
  window.Jarvis = { start, stop, tools: TOOLS, state: () => J.state };
})();
