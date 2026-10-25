/* Jarvis: real-time səsli agent. Səs təminatçısı adapterdir (ADAPTERS): Gemini Live və ya Grok Voice (fal.ai).
   Brauzer jarvis.php-dən qısa ömürlü token alır və təminatçıya birbaşa WebSocket ilə qoşulur:
   mikrofon → PCM (adapterin tezliyi) → model → 24 kHz PCM → dinamik. Alətləri (tool) burada, panelin mövcud funksiyaları ilə icra edirik;
   adapter yalnız protokolu çevirir, ona görə alətlər, UI və səs hər iki təminatçı üçün eynidir.
   index.php-dəki qlobal funksiyalardan istifadə edir: data, TSK, WLD, cmdApi, go, load, ME və s. */
(() => {
  const J = { watch: new Map(), conn: null, ready: false, state: 'off', micCtx: null, micNode: null, micStream: null, outCtx: null, outGain: null, outAn: null, micLv: 0,
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
.jv-prov { display: block; margin-top: 5px; background: rgba(255,255,255,.08); color: #e9eef8; border: 1px solid rgba(255,255,255,.16); border-radius: 8px; font: inherit; font-size: 12px; padding: 3px 6px; }
.jv-prov option { color: #0f172a; }
.jv-x { border: 0; background: rgba(255,255,255,.08); color: #e9eef8; width: 30px; height: 30px; border-radius: 9px; cursor: pointer; font-size: 15px; }
.jv-log { flex: 1; overflow: auto; padding: 4px 14px 8px; display: grid; gap: 6px; align-content: start; min-height: 80px; scrollbar-width: thin; }
.jv-l { line-height: 1.4; } .jv-l.u { color: #cbd5e1; } .jv-l.u::before { content: "Sən: "; color: #94a3b8; } .jv-l.j { color: #e0f2fe; } .jv-l.j::before { content: "Jarvis: "; color: #38bdf8; font-weight: 600; }
.jv-l.a { font-size: 12.5px; color: #fcd34d; } .jv-l.e { font-size: 12.5px; color: #fca5a5; } .jv-l.i { font-size: 12.5px; color: #94a3b8; }
.jv-bar { display: flex; gap: 8px; padding: 10px 14px 14px; border-top: 1px solid rgba(255,255,255,.08); }
.jv-bar button { flex: 1; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9eef8; border-radius: 999px; padding: 8px 10px; font: inherit; font-size: 13px; cursor: pointer; transition: transform .15s ease; }
.jv-bar button:active { transform: scale(.97); } .jv-bar .go { background: linear-gradient(135deg,#0284c7,#d97706); border-color: transparent; font-weight: 600; }
.jv-bar .on { background: rgba(248,113,113,.18); border-color: rgba(248,113,113,.5); }
.jv-hint { padding: 0 14px 10px; font-size: 12px; color: rgba(148,163,184,.9); }
.jv-row { display: flex; gap: 8px; align-items: center; padding: 0 14px 10px; }
.jv-row input[type=text] { flex: 1; min-width: 0; background: rgba(255,255,255,.07); color: #e9eef8; border: 1px solid rgba(255,255,255,.16); border-radius: 10px; padding: 7px 10px; font: inherit; font-size: 13px; }
.jv-row input[type=text]:disabled { opacity: .5; }
.jv-pro { display: flex; align-items: center; gap: 5px; font-size: 12px; color: rgba(203,213,225,.85); white-space: nowrap; cursor: pointer; }
@media (prefers-reduced-motion: reduce) { .jv-orb { animation: none; } }
@media (max-width: 700px) { .jv { left: 10px; right: 10px; bottom: calc(10px + env(safe-area-inset-bottom)); width: auto; max-height: min(70dvh, calc(100dvh - 24px)); border-radius: 18px; } .jv-orb { width: 58px; height: 58px; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const box = document.createElement('div'); box.className = 'jv'; box.hidden = true;
  box.innerHTML = `<div class="jv-top"><canvas class="jv-orb" width="148" height="148"></canvas><div class="jv-ttl"><b>J.A.R.V.I.S.</b><span id="jv-st">hazırdır</span><select id="jv-prov" class="jv-prov" title="Səs modeli" hidden></select></div><button class="jv-x" id="jv-close" aria-label="Bağla">✕</button></div>
<div class="jv-log" id="jv-log" aria-live="polite"></div>
<div class="jv-hint" id="jv-hint">Danışmağa başla: sual ver və ya “neo planetinə uç”, “nə etməliyəm?” de. Qulaqcıqla daha yaxşı işləyir. Qısayol: Alt+J</div>
<form class="jv-row" id="jv-in"><input type="text" id="jv-txt" maxlength="1000" autocomplete="off" placeholder="və ya yaz, Enter…" disabled><label class="jv-pro" title="Jarvis yeni mesaj, görüş, toqquşma və sənə verilən tapşırıqlar barədə özü xəbər versin"><input type="checkbox" id="jv-pro" checked> Proaktiv</label></form>
<div class="jv-bar"><button id="jv-mic">🎙 Mikrofon açıq</button><button class="go" id="jv-go">▶ Başla</button></div>`;
  document.body.append(box);
  const $j = id => document.getElementById(id);
  const orb = box.querySelector('.jv-orb'), og = orb.getContext('2d'), proBox = $j('jv-pro'), txtEl = $j('jv-txt');
  const btn = document.createElement('button'); btn.className = 'gbtn'; btn.id = 'jv-btn'; btn.title = 'Jarvis: səsli köməkçi (Alt+J)'; btn.textContent = '🎙 Jarvis';
  const meta = document.querySelector('header .meta'); if (meta) meta.insertBefore(btn, meta.firstChild.nextSibling);

  const ST = { off: 'hazırdır', connecting: 'qoşulur…', listening: 'dinləyirəm', speaking: 'danışır', tool: 'icra edir…', muted: 'mikrofon bağlıdır', error: 'xəta' };
  const stEl = $j('jv-st'), goEl = $j('jv-go'), logEl = $j('jv-log');
  function setState(s, txt) {
    const label = txt || ST[s] || s;
    if (J.state === s && stEl.textContent === label) return;      // hər səs parçasında (saniyədə onlarla) DOM-a toxunmayaq
    J.state = s; stEl.textContent = label;
    const off = s === 'off' || s === 'error';
    btn.classList.toggle('on', !off);
    goEl.textContent = off ? '▶ Başla' : '■ Bitir';
    goEl.classList.toggle('go', off);
  }
  let scrollQ = false;
  const scrollLog = () => { if (scrollQ) return; scrollQ = true; requestAnimationFrame(() => { scrollQ = false; logEl.scrollTop = logEl.scrollHeight; }); };   // kadrda bir dəfə (layout)
  function line(kind, text) {
    const n = document.createElement('div'); n.className = 'jv-l ' + kind; n.textContent = text;
    logEl.append(n); while (logEl.children.length > 80) logEl.firstChild.remove();
    scrollLog(); return n;
  }
  /** Canlı transkript: eyni növbədəki parçalar bir sətrə yığılır */
  function stream(who, text) {
    if (!text) return;
    let n = J.cur[who];
    if (!n) { n = J.cur[who] = line(who === 'user' ? 'u' : 'j', ''); n._t = ''; }
    n._t += text; n.textContent = n._t.replace(/\s+/g, ' ').trim();
    scrollLog();
  }
  const endTurn = () => { J.cur.user = null; J.cur.jarvis = null; };

  /* Orb: səsə görə döyünən halqalar (dinləyəndə mavi, danışanda qızılı). Yalnız panel görünəndə çəkilir. */
  let orbLv = 0, orbT = 0, orbRaf = 0, orbBuf = null;
  function drawOrb(now) {
    if (box.hidden) { orbRaf = 0; return; }
    orbRaf = requestAnimationFrame(drawOrb);
    orbT = now / 1000;
    let lv = 0;
    if (J.outAn && J.state === 'speaking') {
      if (!orbBuf || orbBuf.length !== J.outAn.fftSize) orbBuf = new Uint8Array(J.outAn.fftSize);
      J.outAn.getByteTimeDomainData(orbBuf); let s = 0; for (let i = 0; i < orbBuf.length; i++) { const v = (orbBuf[i] - 128) / 128; s += v * v; } lv = Math.sqrt(s / orbBuf.length) * 4;
    } else if (J.state === 'listening') lv = J.micLv * 5;
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
  /** Paneli göstər/gizlə; görünəndə orb dövrəsi başlayır, gizlənəndə özü dayanır (rAF boş yerə işləmir) */
  function showBox(on) { box.hidden = !on; if (on && !orbRaf) orbRaf = requestAnimationFrame(drawOrb); }

  /* ---------- Köməkçilər ---------- */
  const norm = s => String(s || '').toLocaleLowerCase('az').normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/ə/g, 'e').replace(/ı/g, 'i').replace(/[^a-z0-9: ]+/g, ' ').replace(/\s+/g, ' ').trim();
  /** Levenshtein məsafəsi (iki sətirli, matris ayrılmır) */
  function lev(a, b) {
    if (Math.abs(a.length - b.length) > 3) return 9;
    let prev = new Array(b.length + 1), cur = new Array(b.length + 1);
    for (let j = 0; j <= b.length; j++) prev[j] = j;
    for (let i = 1; i <= a.length; i++) {
      cur[0] = i;
      for (let j = 1; j <= b.length; j++) cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      [prev, cur] = [cur, prev];
    }
    return prev[b.length];
  }
  /** Ən uyğun element (səslə deyilən ad dəqiq olmaya bilər): tam, başlanğıc, daxilində, söz və yazılış yaxınlığı */
  function score(nk, nq) {
    let s = nk === nq ? 100 : nk.startsWith(nq) || nq.startsWith(nk) ? 80 : nk.includes(nq) || nq.includes(nk) ? 65 : 0;
    if (!s) { const w = nq.split(' ').filter(x => x.length > 2 && nk.includes(x)).length; if (w) s = 40 + w * 8; }
    if (!s && lev(nk, nq) <= Math.max(1, Math.floor(nq.length / 3))) s = 55;      // səs tanımada tez-tez olan 1-2 hərf səhvi
    return s;
  }
  /** Elementlər bal sırası ilə: [{ it, s }] (yalnız min baldan yuxarı) */
  function rank(items, q, key, min) {
    const nq = norm(q); if (!nq) return [];
    const out = [];
    for (const it of items) {
      let ts = 0;
      for (const k of [].concat(key(it))) { const nk = norm(k); if (nk) ts = Math.max(ts, score(nk, nq)); }
      if (ts >= min) out.push({ it, s: ts });
    }
    return out.sort((a, b) => b.s - a.s);
  }
  function best(items, q, key) { const r = rank(items, q, key, 40); return r.length ? r[0].it : null; }
  /** Tapılmayanda modelə oxşar variantlar: istifadəçidən dəqiqləşdirmə soruşa bilsin */
  const similar = (items, q, key, label) => rank(items, q, key, 1).slice(0, 4).map(x => label(x.it));
  /* Söhbət yaddaşı: “bu tapşırıq”, “o planet”, “ona yaz” son danışılan obyektə gedir */
  const REF = /^(bu|o|hemin|elə|ele|it|this|that|same|the same|son|last)( (tapsiriq|task|planet|proyekt|project|adam|user|onu|ona))?$/;
  const isRef = q => !q || REF.test(norm(q));
  const ctx = { task: null, project: null, user: null, cmd: null };
  const remember = (k, v) => { if (v) ctx[k] = v; return v; };
  const when = ts => new Date(ts * 1000).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });
  const parseWhen = v => { if (!v || /^(none|yox|sil)$/i.test(String(v).trim())) return 0; const t = new Date(String(v).trim()); return isNaN(t) ? NaN : Math.floor(t / 1000); };
  const userName = u => {
    if (!u || /^(me|men|mən|özüm|ozum|myself)$/i.test(u)) return ME.name;
    if (isRef(u)) return ctx.user;                                     // “ona” amma hələ kimsə danışılmayıb: null (təsadüfi ad seçməyək)
    return remember('user', best(data.users || [], u, x => x));
  };
  /** Bütün planetlər (ad, etiket, univers). Eyni feed məlumatı üçün bir dəfə hesablanır: spaceList/buildProjects ağırdır, əvvəl hər univers üçün təkrar çağırılırdı. */
  let pnData = null, pnList = [];
  const planetNames = () => {
    if (pnData === data && pnList.length) return pnList;
    const out = new Map(), full = spaceList(buildProjects());
    for (const u of UNIVERSES) for (const p of uniList(full, u.id)) out.set(p.name, { name: p.name, label: (p.label || p.name).replace(/^\S+\s/, m => /\p{Extended_Pictographic}/u.test(m) ? '' : m), uni: u.id });
    pnData = data; pnList = [...out.values()];
    return pnList;
  };
  /** Baxılan planet (xüsusi görünüşlər deyil) */
  const viewedPlanet = () => (typeof curPlanet === 'string' && curPlanet && !xKind(curPlanet) ? curPlanet : null);
  const findPlanet = q => {
    if (isRef(q) || /^(burda|bura|burasi|here|current)$/.test(norm(q))) { const n = viewedPlanet() || ctx.project; return n ? planetNames().find(p => p.name === n) || { name: n, label: n, uni: 'main' } : null; }
    return best(planetNames(), q, p => [p.name, p.label]);
  };
  const projectOf = q => {
    const pl = findPlanet(q);
    if (!pl && isRef(q)) throw new Error('Which project? No planet is open and none was mentioned yet.');
    return remember('project', pl ? pl.name : q);
  };     // səslə deyilən ad → planetin dəqiq adı (tapılmasa olduğu kimi)
  const taskPool = () => (data.tasks || []).slice().sort((a, b) => (a.status === 'done') - (b.status === 'done'));
  function pickTask(q) {
    const id = String(q || '').match(/^#?\s*(\d+)$/);                       // “#12” və ya “12”
    if (id) return (data.tasks || []).find(t => +t.id === +id[1]) || null;
    if (isRef(q)) return ctx.task ? (data.tasks || []).find(t => +t.id === +ctx.task) || null : null;
    return best(taskPool(), q, t => t.title);
  }
  /** Tapşırığı tap (yeni yaradılıbsa məlumatı təzələ); tapılmasa oxşarlarla xəta */
  async function findTask(q) {
    let t = pickTask(q); if (!t) { await load(); t = pickTask(q); }
    if (!t) { const s = similar(taskPool(), q, x => x.title, x => '“' + x.title + '” (' + x.project + ')'); throw new Error('Tapşırıq tapılmadı: ' + q + (s.length ? '. Oxşarlar: ' + s.join(', ') + ' — ask the user which one.' : '')); }
    remember('task', +t.id); remember('project', t.project);
    return t;
  }
  const need = (a, ...ks) => { for (const k of ks) if (a[k] === undefined || a[k] === null || a[k] === '') throw new Error('"' + k + '" lazımdır'); };
  const ok = (msg, extra) => { line('a', '✓ ' + msg); return { ok: true, result: msg, ...extra }; };
  function confirmed(a) { if (a.confirmed !== true) throw new Error('Not confirmed: describe the action to the user, ask for confirmation, then call again with confirmed=true.'); }
  const R = async res => { const { status, d } = await res; if (status !== 200) throw new Error(d.error || 'xəta ' + status); return d; };
  /** JSON POST (bütün endpointlər eyni başlıqları istəyir) */
  async function post(url, body, opts) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(body), ...opts });
    return { status: r.status, d: await r.json().catch(() => ({})) };
  }
  const api = (action, extra, opts) => post('jarvis.php', { action, ...extra }, opts);
  const dueText = t => { const d = dueInfo(t); return d ? d.text : null; };

  /* ---------- Alətlər ---------- */
  const TOOLS = {
    get_overview() {
      const t = now(), tasks = data.tasks || [], sessions = data.sessions || [];
      // Bir keçiddə proyekt üzrə sayğaclar (əvvəl hər proyekt üçün bütün tapşırıq/test siyahısı süzülürdü)
      const openBy = new Map(), failBy = new Map(), myOpen = [], overdue = [];
      for (const x of tasks) {
        if (x.status === 'done') continue;
        openBy.set(x.project, (openBy.get(x.project) || 0) + 1);
        const d = dueInfo(x);
        if (x.assignee === ME.name) myOpen.push({ title: x.title, project: x.project, status: x.status, due: d ? d.text : null });
        if (d && d.left < 0) overdue.push({ title: x.title, assignee: x.assignee, project: x.project });
      }
      for (const x of data.tests || []) if (x.status === 'fail') failBy.set(x.project, (failBy.get(x.project) || 0) + 1);
      const sessBy = new Map();
      for (const s of sessions) { const st = eff(s); if (RANK[st] > 0) { if (!sessBy.has(s.user)) sessBy.set(s.user, []); sessBy.get(s.user).push({ ...s, st }); } }
      return {
        now: when(t), me: ME.name,
        users: (data.users || []).map(u => {
          const ss = (sessBy.get(u) || []).sort((a, b) => RANK[b.st] - RANK[a.st]);
          return { name: u, status: ss.length ? ss[0].st : isOnline(u) ? 'on dashboard' : 'offline', projects: ss.map(s => s.project), doing: ss.map(s => s.last_prompt).filter(Boolean).slice(0, 2) };
        }),
        projects: buildProjects().map(p => { const idle = idleOf(p.name); return { name: p.name, status: p.st, last_activity: ago(p.last), burning: idle >= DAY, hours_without_finished_task: Math.floor(idle / 3600) || 0,
          open_tasks: openBy.get(p.name) || 0, failing_tests: failBy.get(p.name) || 0 }; }),
        my_open_tasks: myOpen,
        overdue_tasks: overdue,
        meetings: (data.meetings || []).filter(m => +m.starts_at + m.minutes * 60 > t).slice(0, 5).map(m => ({ title: m.title, start: when(m.starts_at), minutes: +m.minutes, live: +m.starts_at <= t, attendees: m.attendees })),
        agents: (data.users || []).map(u => { const a = agentInfo(u); return { user: u, online: a.online, running: a.run.length, queued: a.queued.length, projects: a.projects }; }),
        ship_deadlines: (data.ships || []).filter(s => +s.deadline).map(s => ({ user: s.user, mission: s.mission, due: when(s.deadline), overdue: s.deadline < t })),
      };
    },
    get_project(a) {
      need(a, 'name');
      const pl = findPlanet(a.name);
      if (!pl) { const s = similar(planetNames(), a.name, p => [p.name, p.label], p => p.name); throw new Error('Belə planet tapılmadı: ' + a.name + (s.length ? '. Oxşarlar: ' + s.join(', ') : '')); }
      const n = remember('project', pl.name), p = buildProjects().find(x => x.name === n), idle = idleOf(n);
      return {
        name: n, universe: pl.uni, status: p ? p.st : null, last_activity: p ? ago(p.last) : null, sessions: p ? p.sessions : 0, edits: p ? p.edits : 0,
        burning: idle >= DAY, hours_without_finished_task: Math.floor(idle / 3600) || 0,
        active_sessions: (data.sessions || []).filter(s => s.project === n).map(s => ({ user: s.user, status: eff(s), prompt: s.last_prompt, last: s.last_summary })).filter(s => RANK[s.status] > 0),
        recent_events: (data.events || []).filter(e => e.project === n).slice(0, 8).map(e => e.user + ': ' + e.summary + ' (' + ago(e.created_at) + ')'),
        tasks: (data.tasks || []).filter(x => x.project === n).map(x => ({ id: +x.id, title: x.title, status: x.status, assignee: x.assignee, due: dueText(x) })),
        tests: (data.tests || []).filter(x => x.project === n).map(x => ({ title: x.title, result: x.status, note: x.note || undefined })),
        open_plans: (data.plans || []).filter(x => x.project === n && x.status !== 'done').map(x => ({ kind: x.kind, title: x.title })),
        mcp_servers: satsOf(n).map(s => s.name),
      };
    },
    list_tasks(a) {
      let ts = data.tasks || [];
      if (a.assignee) { const u = userName(a.assignee); ts = ts.filter(t => t.assignee === u); }
      if (a.project) { const n = projectOf(a.project); ts = ts.filter(t => t.project === n); }
      const st = a.status || 'open';
      if (st === 'open') ts = ts.filter(t => t.status !== 'done'); else if (st !== 'any') ts = ts.filter(t => t.status === st);
      if (ts.length === 1) remember('task', +ts[0].id);
      return { count: ts.length, tasks: ts.slice(0, 25).map(t => ({ id: +t.id, title: t.title, project: t.project, assignee: t.assignee, status: t.status, due: dueText(t), verdict: t.verdict || undefined })) };
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
      const pl = findPlanet(a.target);
      if (u && (!pl || /univers|qalaktika|galaxy/.test(q) || norm(u.name) === q || u.id === q)) { location.hash = '#kainat/@' + u.id; return ok(u.name); }
      if (!pl) { const s = similar(planetNames(), a.target, p => [p.name, p.label], p => p.name); throw new Error('Belə yer tapılmadı: ' + a.target + (s.length ? '. Oxşarlar: ' + s.join(', ') : '')); }
      remember('project', pl.name); go(pl.name); return ok(pl.label || pl.name);
    },
    async create_task(a) {
      need(a, 'title', 'project'); confirmed(a);
      const assignee = a.assignee ? userName(a.assignee) : ME.name;
      if (!assignee) throw new Error('Belə istifadəçi yoxdur: ' + a.assignee);
      const due = parseWhen(a.due); if (Number.isNaN(due)) throw new Error('Tarix başa düşülmədi: ' + a.due);
      const project = projectOf(a.project);
      const d = await R(TSK('create', { title: a.title, details: a.details || '', project, assignee, due_at: due || 0 }));
      remember('task', +d.id);
      await load(); return ok(`Tapşırıq yaradıldı: “${a.title}” → ${assignee}, ${project}` + (due ? ', son tarix ' + when(due) : '') + (d.auto ? ' · 🤖 agentinə avtomatik göndərildi' : ''),
        { auto_sent_to_agent: !!d.auto });
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
      const project = projectOf(a.project);
      let t = best((data.tests || []).filter(x => x.project === project), a.test, x => x.title);
      if (!t) { const d = await R(WLD('test_save', { project, title: a.test, steps: '' })); t = { id: d.id, title: a.test }; }
      await R(WLD('test_status', { id: t.id, status: a.result, note: a.note || '' })); await load();
      return ok(`Test “${t.title}” (${project}): ${a.result === 'pass' ? 'keçdi' : a.result === 'fail' ? 'xəta' : 'yoxlanmayıb'}`);
    },
    async send_message(a) {
      need(a, 'to', 'text'); confirmed(a);
      const to = /^(all|hami|hamiya|hamısı|hamıya|\*)$/i.test(norm(a.to)) ? '*' : userName(a.to);
      if (!to) throw new Error('Belə istifadəçi yoxdur: ' + a.to);
      const { status, d } = await post('message.php', { action: 'send', to, body: String(a.text).slice(0, 500) });
      if (status !== 200 || !d.message) throw new Error(d.error || 'Göndərilmədi');
      msgSeen.add(+d.message.id); data.messages = [d.message, ...(data.messages || [])]; playMessage(d.message); load();
      return ok(`Mesaj raketlə göndərildi → ${to === '*' ? 'hamı' : to}`);
    },
    async run_claude_code(a) {
      need(a, 'prompt'); confirmed(a);
      let project = null;
      if (a.project) project = projectOf(a.project);
      else if (viewedPlanet()) project = viewedPlanet();                                 // baxdığı planet
      else if (ctx.project) project = ctx.project;                                       // söhbətdə son danışılan proyekt
      else { const ps = buildProjects(); if (ps.length === 1) project = ps[0].name; }
      if (!project) throw new Error('Hansı proyektdə? Proyekt adını soruş.');
      const { status, d } = await api('agent_send', { project, prompt: a.prompt });
      if (status !== 200) throw new Error(d.error || 'Göndərilmədi');
      watchCmd(+d.id, { project, prompt: a.prompt, st: 'queued' }); remember('cmd', +d.id); remember('project', project);
      await load();
      line('a', `🤖 Claude Code-a göndərildi (#${d.id}, ${project})` + (d.agent_online ? '' : ' · agent oflayndır, növbədə gözləyir'));
      return { ok: true, task_id: d.id, project, agent_online: !!d.agent_online, claude_login: d.claude_login,
        note: d.agent_online ? 'Running on the user\'s computer; a note will arrive when it finishes.' : 'Agent is offline: queued, it starts when the agent on the user\'s computer runs.' };
    },
    get_claude_tasks(a) {
      let cs = (data.commands || []).filter(c => c.user === ME.name);
      if (a && a.project) { const n = projectOf(a.project); cs = cs.filter(c => c.project === n); }
      return { tasks: cs.slice(0, 8).map(c => ({ id: +c.id, project: c.project, status: c.status, prompt: c.prompt.slice(0, 200), result: (c.result || '').slice(-600), created: ago(c.created_at) })) };
    },
    get_suggestions() { return { now: when(now()), suggestions: suggestions().slice(0, 8).map(s => ({ priority: s.p, about: s.kind, text: s.text })) }; },
    get_screen() { return screen(); },
    get_user(a) {
      need(a, 'name');
      const u = userName(a.name); if (!u) throw new Error('Belə istifadəçi yoxdur: ' + a.name + '. Komanda: ' + (data.users || []).join(', '));
      const ss = (data.sessions || []).map(s => ({ ...s, st: eff(s) })).filter(s => s.user === u && RANK[s.st] > 0).sort((x, y) => RANK[y.st] - RANK[x.st]);
      const ag = agentInfo(u), sh = shipOf(u);
      return {
        name: u, status: ss.length ? ss[0].st : isOnline(u) ? 'on dashboard' : 'offline', current_project: userPlanet(u),
        sessions: ss.map(s => ({ project: s.project, status: s.st, prompt: s.last_prompt, last: s.last_summary, updated: ago(s.updated_at) })),
        open_tasks: (data.tasks || []).filter(t => t.assignee === u && t.status !== 'done').map(t => ({ id: +t.id, title: t.title, project: t.project, status: t.status, due: dueText(t) })),
        recent_events: (data.events || []).filter(e => e.user === u).slice(0, 6).map(e => e.project + ': ' + e.summary + ' (' + ago(e.created_at) + ')'),
        agent: { online: ag.online, running: ag.run.length, queued: ag.queued.length, projects: ag.projects },
        ship_deadline: +sh.deadline ? { due: when(sh.deadline), mission: sh.mission, overdue: sh.deadline < now() } : null,
      };
    },
    get_activity(a) {
      let ev = data.events || [];
      if (a.user) { const u = userName(a.user); ev = ev.filter(e => e.user === u); }
      if (a.project) { const n = projectOf(a.project); ev = ev.filter(e => e.project === n); }
      const n = Math.max(1, Math.min(30, +a.limit || 12));
      return { count: ev.length, events: ev.slice(0, n).map(e => ({ user: e.user, project: e.project, what: e.summary, when: ago(e.created_at) })),
        note: (typeof curPlanet === 'string' && curPlanet) ? 'Feed may be narrowed to the planet being viewed.' : undefined };
    },
    get_messages(a) {
      const rd = getRead(), list = (data.messages || []).filter(inMine);
      const out = list.filter(m => !a.unread_only || (toMe(m) && +m.id > rd)).slice(0, 12)
        .map(m => ({ from: m.from_user, to: m.to_user === '*' ? 'all' : m.to_user, text: m.body, when: ago(m.created_at), unread: toMe(m) && +m.id > rd }));
      if (list.length) { setRead(Math.max(rd, ...list.map(m => +m.id))); updateMsgBadge(); }   // istifadəçi səsli eşitdi: oxunmuş say
      const last = out.find(m => m.from !== ME.name); if (last) remember('user', last.from);
      return { count: out.length, messages: out, note: 'Last 24 hours only.' };
    },
    get_plans(a) {
      let ps = data.plans || [];
      if (a.project) { const n = projectOf(a.project); ps = ps.filter(p => p.project === n); }
      if (a.status !== 'any') ps = ps.filter(p => p.status !== 'done');
      return { count: ps.length, plans: ps.slice(0, 25).map(p => ({ id: +p.id, project: p.project, kind: p.kind, title: p.title, status: p.status, by: p.creator })) };
    },
    async create_plan(a) {
      need(a, 'project', 'title'); confirmed(a);
      const kind = a.kind === 'scope' ? 'scope' : 'plan', project = projectOf(a.project);
      await R(PLN('create', { kind, project, title: String(a.title).slice(0, 200), details: String(a.details || '').slice(0, 3000) })); await load();
      return ok(`${kind === 'scope' ? 'Scope' : 'Plan'} əlavə olundu: “${a.title}” (${project})`);
    },
    async set_plan_status(a) {
      need(a, 'plan', 'status'); confirmed(a);
      const p = best(data.plans || [], a.plan, x => x.title);
      if (!p) { const s = similar(data.plans || [], a.plan, x => x.title, x => '“' + x.title + '”'); throw new Error('Plan tapılmadı: ' + a.plan + (s.length ? '. Oxşarlar: ' + s.join(', ') : '')); }
      await R(PLN('status', { id: p.id, status: a.status === 'done' ? 'done' : 'open' })); await load();
      return ok(`“${p.title}” → ${a.status === 'done' ? 'bitdi' : 'açıq'}`);
    },
    async update_task(a) {
      need(a, 'task'); confirmed(a);
      const t = await findTask(a.task), body = { id: t.id };
      if (a.title) body.title = String(a.title).slice(0, 200);
      if (a.details !== undefined && a.details !== null) body.details = String(a.details).slice(0, 3000);
      if (a.assignee) { const u = userName(a.assignee); if (!u) throw new Error('Belə istifadəçi yoxdur: ' + a.assignee); if (ME.role !== 'admin' && u !== t.assignee) throw new Error('Only an admin can reassign tasks.'); body.assignee = u; }
      if (Object.keys(body).length === 1) throw new Error('Nothing to change: give title, details or assignee.');
      const d = await R(TSK('update', body)); await load();
      return ok(`“${body.title || t.title}” yeniləndi` + (body.assignee && body.assignee !== t.assignee ? ' → ' + body.assignee : '') + (d.auto ? ' · 🤖 agentinə göndərildi' : ''));
    },
    async stop_claude_task(a) {
      confirmed(a);
      const mine = (data.commands || []).filter(c => (c.user === ME.name || c.creator === ME.name) && ['pending', 'queued', 'running'].includes(c.status));
      const c = a.id ? (data.commands || []).find(x => +x.id === +String(a.id).replace('#', '')) : mine.find(x => +x.id === +ctx.cmd) || mine.find(x => x.status === 'running') || mine[0];
      if (!c) throw new Error('No running or queued Claude Code task to stop.');
      await R(CTL('stop_command', { id: +c.id })); J.watch.delete(+c.id); await load();
      return ok(`Claude Code #${c.id} (${c.project}) dayandırıldı`);
    },
    async set_agent_auto(a) {
      confirmed(a);
      const d = await R(CTL('agent_auto', { on: !!a.on })); await load();
      return ok(d.on ? 'Agent lövhə tapşırıqlarını avtomatik götürəcək' : 'Agentin avtomatik tapşırıq götürməsi söndürüldü');
    },
    async post_meeting_note(a) {
      need(a, 'text'); confirmed(a);
      const t = now(), ms = (data.meetings || []).filter(m => +m.starts_at + m.minutes * 60 > t - 3600);
      const m = a.meeting ? best(ms, a.meeting, x => x.title) : ms.find(x => +x.starts_at <= t) || ms[0];
      if (!m) throw new Error('Görüş tapılmadı' + (a.meeting ? ': ' + a.meeting : ''));
      await R(WLD('meeting_post', { meeting_id: m.id, body: String(a.text).slice(0, 2000) })); await load();
      return ok(`Görüşə yazıldı: “${m.title}”`);
    },
    ui(a) {
      need(a, 'action');
      if (!UI[a.action]) throw new Error('naməlum UI əməliyyatı: ' + a.action + '. Mümkün: ' + Object.keys(UI).join(', '));
      return UI[a.action](a.target);
    },
    set_proactive(a) { PRO.on = !!a.on; store.set('jv-pro', PRO.on ? '1' : '0'); proBox.checked = PRO.on; return ok(PRO.on ? 'Proaktiv bildirişlər açıqdır' : 'Proaktiv bildirişlər söndürüldü'); },
    end_conversation() { J.ending = true; return { ok: true }; },
  };

  /* ---------- UI idarəsi: mövcud düymələri basır, ona görə panelin öz məntiqi (icazələr, modallar) eynidir ---------- */
  const click = id => { const b = $(id); if (!b || b.hidden || b.disabled) return false; b.click(); return true; };
  const toSpace = () => { if (view !== 'space') location.hash = '#kainat'; };
  const UI = {
    open_messages(to) { const u = to ? (/^(all|hami|hamiya)$/.test(norm(to)) ? '*' : userName(to)) : undefined; openMsg(u); return ok('Mesaj pəncərəsi'); },
    open_inbox() { openMsg(); showMsgTab('inbox'); return ok('Gələn mesajlar'); },
    open_report() { click('b-rep'); return ok('Hesabat'); },
    open_new_task(prj) { if (view !== 'board') location.hash = '#lovhe'; openTaskModal(prj ? projectOf(prj) : undefined); return ok('Yeni tapşırıq pəncərəsi'); },
    open_ship(u) { openShip(u ? userName(u) : undefined); return ok('Gəmi'); },
    open_new_planet() { toSpace(); click('t-world'); return ok('Yeni planet pəncərəsi'); },
    open_mcp() { toSpace(); click('t-mcp'); return ok('MCP peykləri'); },
    open_plan() { go(SHIPKEY); return ok('Plan stansiyası'); },
    close_dialogs() { document.querySelectorAll('.modal:not([hidden])').forEach(m => closeM(m.id)); return ok('Pəncərələr bağlandı'); },
    zoom_in() { toSpace(); click('zin'); return ok('Yaxınlaşdırıldı'); },
    zoom_out() { toSpace(); click('zout'); return ok('Uzaqlaşdırıldı'); },
    next_planet() { if (!click('pnext')) throw new Error('Open a project planet first.'); return ok('Növbəti planet'); },
    prev_planet() { if (!click('pprev')) throw new Error('Open a project planet first.'); return ok('Əvvəlki planet'); },
    sound_on() { const b = $('sbtn'); if (b && b.textContent.includes('🔇')) b.click(); return ok('Kainat səsi açıq'); },
    sound_off() { const b = $('sbtn'); if (b && b.textContent.includes('🔊')) b.click(); return ok('Kainat səsi söndürüldü'); },
    filter_feed(u) {
      const f = !u || /^(all|hami|hamisi|butun)$/.test(norm(u)) ? 'all' : userName(u);
      if (!f) throw new Error('Belə istifadəçi yoxdur: ' + u);
      filter = f; if (view !== 'panel') location.hash = '#'; render(); return ok(f === 'all' ? 'Axın: hamı' : 'Axın: ' + f);
    },
    mute_mic() { if (!J.muted) toggleMute(); return ok('Mikrofon bağlandı (açmaq üçün düyməni bas)'); },
  };
  function screen() {
    const open = [...document.querySelectorAll('.modal:not([hidden])')].map(m => m.id);
    return { view, planet: viewedPlanet() || (typeof curPlanet === 'string' ? curPlanet : null), plan_station: !!curShip, universe: navUni || null,
      feed_filter: filter, open_dialogs: open, last_mentioned: { task: ctx.task ? ((data.tasks || []).find(t => +t.id === +ctx.task) || {}).title : null, project: ctx.project, user: ctx.user } };
  }

  /* ---------- Proaktivlik: komanda vəziyyətindən təkliflər (alət, brifinq və canlı bildirişlər üçün eyni mənbə) ---------- */
  const attOf = m => Array.isArray(m.attendees) ? m.attendees : String(m.attendees || '').split(',').map(s => s.trim()).filter(Boolean);
  function suggestions() {
    if (!data || !ME) return [];
    const t = now(), me = ME.name, out = [], add = (p, kind, text, key) => out.push({ p, kind, text, key: key || kind + ':' + text });
    const unread = (data.messages || []).filter(m => toMe(m) && +m.id > getRead());
    if (unread.length) add(1, 'messages', `${unread.length} unread message(s) from ${[...new Set(unread.map(m => m.from_user))].join(', ')}`, 'msg:' + Math.max(...unread.map(m => +m.id)));
    for (const m of data.meetings || []) {
      const st = +m.starts_at, end = st + m.minutes * 60, att = attOf(m);
      if (att.length && !att.includes(me)) continue;
      if (st <= t && t < end) add(1, 'meeting', `Meeting "${m.title}" is live now (until ${when(end).slice(11)})`, 'mt:' + m.id + ':live');
      else if (st > t && st - t <= 3600) add(2, 'meeting', `Meeting "${m.title}" starts in ${Math.round((st - t) / 60)} min`, 'mt:' + m.id + ':soon');
    }
    for (const a of data.alerts || []) if ((a.user_a === me || a.user_b === me) && t - a.created_at < 7200) add(1, 'conflict', `Collision risk: ${a.user_a} and ${a.user_b} are editing the same file ${a.file} in ${a.project}`, 'al:' + a.id);
    for (const x of data.tasks || []) {
      if (x.status === 'done') continue;
      const d = dueInfo(x);
      if (x.assignee === me && d && d.left < 0) add(1, 'overdue', `Your task "${x.title}" (${x.project}) is overdue`, 'od:' + x.id);
      else if (x.assignee === me && d && d.left < DAY) add(2, 'due_soon', `Your task "${x.title}" is due ${d.text}`, 'ds:' + x.id);
      if (x.status === 'review' && x.creator === me && x.assignee !== me) add(2, 'review', `"${x.title}" by ${x.assignee} is waiting for review` + (x.verdict ? ` (agent verdict: ${x.verdict})` : ''), 'rv:' + x.id + ':' + x.verdict);
      if (x.assignee === me && x.creator !== me && t - x.created_at < 3 * 3600 && x.status === 'todo') add(2, 'new_task', `${x.creator} assigned you "${x.title}" (${x.project})`, 'nt:' + x.id);
    }
    for (const c of data.commands || []) if (c.user === me && (c.status === 'failed' || c.status === 'error') && t - (+c.finished_at || 0) < 7200) add(2, 'claude_failed', `Claude Code task #${c.id} in ${c.project} failed`, 'cf:' + c.id);
    const ag = agentInfo(me);
    if (!ag.online && ag.queued.length) add(2, 'agent', `Your Claude Code agent is offline with ${ag.queued.length} task(s) queued`, 'ag:off');
    const fails = new Map(); for (const x of data.tests || []) if (x.status === 'fail') fails.set(x.project, (fails.get(x.project) || 0) + 1);
    for (const [p, n] of fails) add(3, 'tests', `${n} failing test(s) in ${p}`, 'tf:' + p + ':' + n);
    for (const p of buildProjects()) if (idleOf(p.name) >= DAY && (data.tasks || []).some(x => x.project === p.name && x.status !== 'done')) add(3, 'burning', `Planet ${p.name} is burning: open tasks, nothing finished for ${Math.floor(idleOf(p.name) / 3600)}h`, 'bn:' + p.name);
    const sh = shipOf(me); if (+sh.deadline && sh.deadline > t && sh.deadline - t < DAY) add(2, 'ship', `Your ship deadline "${sh.mission || ''}" is ${when(sh.deadline)}`, 'sh:' + sh.deadline);
    return out.sort((a, b) => a.p - b.p);
  }
  // Canlı izləmə: yalnız sessiya zamanı; yeni vacib hadisə olanda (mesaj, görüş, toqquşma, yeni tapşırıq) Jarvis qısaca xəbər verir
  const PRO = { on: true, seen: new Set(), timer: null, lastNote: 0 };      // on: localStorage-dan aşağıda (store) oxunur
  const LIVE = new Set(['messages', 'meeting', 'conflict', 'new_task', 'claude_failed', 'review']);
  function proStart() {
    PRO.seen = new Set(suggestions().map(s => s.key));                      // sessiya başlayanda olanlar brifinqə düşür, təkrar bildirilmir
    clearInterval(PRO.timer); PRO.timer = setInterval(proTick, 5000);
  }
  function proTick() {
    if (!J.ready || !PRO.on) return;
    if (J.state !== 'listening' && J.state !== 'muted') return;               // danışanda / alət icra edəndə gözlə
    if (J.cur.user || playing() || Date.now() - PRO.lastNote < 45000) return; // istifadəçi danışırsa və ya çox tez-tezdirsə
    const fresh = suggestions().filter(s => LIVE.has(s.kind) && !PRO.seen.has(s.key));
    if (!fresh.length) return;
    fresh.forEach(s => PRO.seen.add(s.key)); PRO.lastNote = Date.now();
    J.conn.note('[System note, not spoken by the user: live dashboard update] ' + fresh.slice(0, 3).map(s => '- ' + s.text).join('\n') +
      '\nMention this to the user in one short natural sentence (no greeting), and offer the obvious next step if there is one. If it is a message, offer to read it.');
  }
  function briefing() {
    if (!PRO.on) return;
    PRO.lastNote = Date.now();
    const top = suggestions().filter(s => s.p <= 2).slice(0, 4);
    const sc = screen();
    J.conn.note('[System note, not spoken by the user: session started] The user is on the ' + sc.view + ' view' + (sc.planet ? ', looking at planet "' + sc.planet + '"' : '') + '.\n' +
      (top.length ? 'Things worth knowing:\n' + top.map(s => '- ' + s.text).join('\n') + '\nGreet the user in one short sentence and mention only the one or two most important items, then ask how you can help.'
        : 'Nothing urgent. Greet the user with one very short sentence and wait.'));
  }

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
    if (J.conn) J.conn.toolResults(out);
    if (J.state === 'tool') setState('listening');
    if (J.ending) setTimeout(waitAndStop, 300);
  }
  function waitAndStop() { if (J.outCtx && J.nextT > J.outCtx.currentTime + .05) return setTimeout(waitAndStop, 300); stop('Görüşənədək'); }

  /* Claude Code tapşırıqlarını izlə: bitəndə Jarvis nəticəni səsli danışır. Taymer yalnız izlənən iş olanda işləyir. */
  let watchTimer = null;
  function watchCmd(id, w) { J.watch.set(id, w); if (!watchTimer) watchTimer = setInterval(checkWatch, 1500); }
  function checkWatch() {
    if (!J.watch.size) { clearInterval(watchTimer); watchTimer = null; return; }
    if (!data || !data.commands) return;                     // data: index.php-dəki qlobal (let), window-da deyil
    for (const [id, w] of J.watch) {
      const c = data.commands.find(x => +x.id === id); if (!c || c.status === w.st) continue;
      w.st = c.status;
      if (c.status === 'running') { line('i', `🤖 Claude Code işləyir (#${id}, ${w.project})…`); continue; }
      if (c.status === 'queued') continue;
      J.watch.delete(id);
      const res = (c.result || '').trim();
      line(c.status === 'done' ? 'a' : 'e', `🤖 Claude Code ${c.status === 'done' ? 'bitirdi' : c.status === 'canceled' ? 'dayandırıldı' : 'xəta verdi'} (#${id}, ${w.project})`);
      if (J.ready) J.conn.note(
        `[System note, not spoken by the user] Claude Code task #${id} in project "${w.project}" finished with status "${c.status}". Task: ${w.prompt.slice(0, 300)}\nResult (may be truncated):\n${res.slice(-2500) || '(empty)'}\nTell the user the outcome in 1-2 short sentences.`);
    }
  }


  /* ---------- Səs ---------- */
  // Mikrofon: brauzerin tezliyindən adapterin istədiyi tezliyə (16/24 kHz) çevrilmiş 16-bit PCM parçaları.
  // Hazır parça kopyalanmır, sahibliyi (transfer) əsas axına keçir; səs səviyyəsi parça ilə bir göndərilir.
  const WORKLET = `class JvMic extends AudioWorkletProcessor {
    constructor(o) { super(); const p = o.processorOptions; this.ratio = sampleRate / p.rate; this.t = 0; this.last = 0; this.chunk = p.chunk; this.out = new Int16Array(p.chunk); this.n = 0; }
    process(inputs) {
      const x = inputs[0] && inputs[0][0]; if (!x) return true;
      let s = 0; for (let i = 0; i < x.length; i++) s += x[i] * x[i]; const lv = Math.sqrt(s / x.length);
      for (;;) {
        const i = Math.floor(this.t); if (i + 1 >= x.length) break;
        const v = i < 0 ? this.last + (x[0] - this.last) * (this.t + 1) : x[i] + (x[i + 1] - x[i]) * (this.t - i);
        this.out[this.n++] = v <= -1 ? -32768 : v >= 1 ? 32767 : (v * 32767) | 0;
        if (this.n === this.out.length) { const b = this.out.buffer; this.port.postMessage({ pcm: b, lv }, [b]); this.out = new Int16Array(this.chunk); this.n = 0; }
        this.t += this.ratio;
      }
      this.t -= x.length; this.last = x[x.length - 1];
      return true;
    }
  } registerProcessor('jv-mic', JvMic);`;
  function b64(buf) { const u = new Uint8Array(buf); let s = ''; for (let i = 0; i < u.length; i += 0x8000) s += String.fromCharCode.apply(null, u.subarray(i, i + 0x8000)); return btoa(s); }
  /** base64 → 16-bit PCM → float nümunələr (bir keçiddə; sətir üzərində charCodeAt ilə iki dəfə keçmirik) */
  function pcmFloat(b64data) {
    const bin = atob(b64data), n = bin.length >> 1; if (!n) return null;
    const f = new Float32Array(n);
    for (let i = 0, j = 0; i < n; i++, j += 2) { const v = bin.charCodeAt(j) | (bin.charCodeAt(j + 1) << 8); f[i] = (v >= 32768 ? v - 65536 : v) / 32768; }
    return f;
  }
  async function startMic(ad) {
    J.micStream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } });
    J.micCtx = new (window.AudioContext || window.webkitAudioContext)();
    await J.micCtx.audioWorklet.addModule(URL.createObjectURL(new Blob([WORKLET], { type: 'application/javascript' })));
    const src = J.micCtx.createMediaStreamSource(J.micStream);
    J.micNode = new AudioWorkletNode(J.micCtx, 'jv-mic', { processorOptions: { rate: ad.inRate, chunk: Math.round(ad.inRate * ad.chunkMs / 1000) } });
    J.micNode.port.onmessage = e => {
      J.micLv = e.data.lv;
      if (!J.muted && J.ready && J.conn) J.conn.audio(b64(e.data.pcm));
    };
    src.connect(J.micNode);
  }
  function startOut() {
    J.outCtx = new (window.AudioContext || window.webkitAudioContext)();
    J.outGain = J.outCtx.createGain(); J.outAn = J.outCtx.createAnalyser(); J.outAn.fftSize = 512;
    J.outGain.connect(J.outAn); J.outAn.connect(J.outCtx.destination);
    J.nextT = 0;
  }
  function play(b64data, rate) {
    if (!J.outCtx || !b64data) return;
    const f = pcmFloat(b64data); if (!f) return;
    const buf = J.outCtx.createBuffer(1, f.length, rate || 24000); buf.copyToChannel(f, 0);
    const src = J.outCtx.createBufferSource(); src.buffer = buf; src.connect(J.outGain);
    const t = Math.max(J.nextT, J.outCtx.currentTime + .03); src.start(t); J.nextT = t + buf.duration;
    J.sources.add(src); src.onended = () => { J.sources.delete(src); if (!J.sources.size && J.state === 'speaking') setState(J.muted ? 'muted' : 'listening'); };
    if (J.state !== 'tool') setState('speaking');
  }
  function stopPlayback() { for (const s of J.sources) { try { s.stop(); } catch (e) {} } J.sources.clear(); J.nextT = 0; }
  const playing = () => J.outCtx && J.nextT > J.outCtx.currentTime + .05;

  /* ---------- msgpack (fal realtime çərçivələri üçün kiçik kodlayıcı) ---------- */
  const MP = (() => {
    const te = new TextEncoder(), td = new TextDecoder();
    function encode(v) {
      const o = []; const u8 = (...b) => { for (const x of b) o.push(x & 255); };
      const be = (n, bytes) => { for (let i = bytes - 1; i >= 0; i--) o.push(Math.floor(n / 2 ** (8 * i)) & 255); };
      const raw = b => { for (let i = 0; i < b.length; i++) o.push(b[i]); };
      (function w(v) {
        if (v === null || v === undefined) return u8(0xc0);
        if (v === true || v === false) return u8(v ? 0xc3 : 0xc2);
        if (typeof v === 'number') {
          if (Number.isInteger(v) && v >= 0 && v < 2 ** 32) { if (v < 128) return u8(v); if (v < 256) return u8(0xcc, v); if (v < 65536) { u8(0xcd); return be(v, 2); } u8(0xce); return be(v, 4); }
          if (Number.isInteger(v) && v < 0 && v >= -(2 ** 31)) { if (v >= -32) return u8(0xe0 | (v + 32)); u8(0xd2); return be(v >>> 0, 4); }
          const f = new DataView(new ArrayBuffer(8)); f.setFloat64(0, v); u8(0xcb); return raw(new Uint8Array(f.buffer));
        }
        if (typeof v === 'string') {
          const b = te.encode(v), n = b.length;
          if (n < 32) u8(0xa0 | n); else if (n < 256) u8(0xd9, n); else if (n < 65536) { u8(0xda); be(n, 2); } else { u8(0xdb); be(n, 4); }
          return raw(b);
        }
        if (v instanceof Uint8Array) { const n = v.length; if (n < 256) u8(0xc4, n); else if (n < 65536) { u8(0xc5); be(n, 2); } else { u8(0xc6); be(n, 4); } return raw(v); }
        if (Array.isArray(v)) { const n = v.length; if (n < 16) u8(0x90 | n); else if (n < 65536) { u8(0xdc); be(n, 2); } else { u8(0xdd); be(n, 4); } return v.forEach(w); }
        const ks = Object.keys(v).filter(k => v[k] !== undefined), n = ks.length;
        if (n < 16) u8(0x80 | n); else if (n < 65536) { u8(0xde); be(n, 2); } else { u8(0xdf); be(n, 4); }
        for (const k of ks) { w(k); w(v[k]); }
      })(v);
      return new Uint8Array(o);
    }
    function decode(buf) {
      const b = new Uint8Array(buf), dv = new DataView(b.buffer, b.byteOffset, b.byteLength); let p = 0;
      const str = n => { const s = td.decode(b.subarray(p, p + n)); p += n; return s; };
      const bin = n => { const s = b.slice(p, p + n); p += n; return s; };
      const arr = n => { const a = []; for (let i = 0; i < n; i++) a.push(r()); return a; };
      const map = n => { const m = {}; for (let i = 0; i < n; i++) { const k = r(); m[k] = r(); } return m; };
      const u = n => { const v = n === 1 ? dv.getUint8(p) : n === 2 ? dv.getUint16(p) : n === 4 ? dv.getUint32(p) : Number(dv.getBigUint64(p)); p += n; return v; };
      const s = n => { const v = n === 1 ? dv.getInt8(p) : n === 2 ? dv.getInt16(p) : n === 4 ? dv.getInt32(p) : Number(dv.getBigInt64(p)); p += n; return v; };
      function r() {
        const t = b[p++];
        if (t < 0x80) return t; if (t >= 0xe0) return t - 256;
        if (t >= 0xa0 && t < 0xc0) return str(t & 31); if (t >= 0x90 && t < 0xa0) return arr(t & 15); if (t >= 0x80 && t < 0x90) return map(t & 15);
        switch (t) {
          case 0xc0: return null; case 0xc2: return false; case 0xc3: return true;
          case 0xc4: return bin(u(1)); case 0xc5: return bin(u(2)); case 0xc6: return bin(u(4));
          case 0xc7: { const n = u(1); p += 1 + n; return null; } case 0xc8: { const n = u(2); p += 1 + n; return null; } case 0xc9: { const n = u(4); p += 1 + n; return null; }
          case 0xca: { const v = dv.getFloat32(p); p += 4; return v; } case 0xcb: { const v = dv.getFloat64(p); p += 8; return v; }
          case 0xcc: return u(1); case 0xcd: return u(2); case 0xce: return u(4); case 0xcf: return u(8);
          case 0xd0: return s(1); case 0xd1: return s(2); case 0xd2: return s(4); case 0xd3: return s(8);
          case 0xd4: p += 2; return null; case 0xd5: p += 3; return null; case 0xd6: p += 5; return null; case 0xd7: p += 9; return null; case 0xd8: p += 17; return null;
          case 0xd9: return str(u(1)); case 0xda: return str(u(2)); case 0xdb: return str(u(4));
          case 0xdc: return arr(u(2)); case 0xdd: return arr(u(4)); case 0xde: return map(u(2)); case 0xdf: return map(u(4));
        }
        throw new Error('msgpack: naməlum tip 0x' + t.toString(16));
      }
      return r();
    }
    return { encode, decode };
  })();

  /* ---------- Adapterlər ----------
     Hər adapter: inRate/chunkMs (mikrofon), connect(sess, h) → { audio(b64), toolResults(out), note(text), text(typed), mute(on), close() }.
     h (nüvədən): ready(), audio(b64, rate), user(text, replace), bot(text), interrupted(), turnDone(), tools([{id,name,args}]), info(msg), closed(code, reason) */
  const ADAPTERS = {
    gemini: {
      label: 'Gemini Live', inRate: 16000, chunkMs: 40,
      connect(d, h) {
        const ws = new WebSocket(d.ws + '?access_token=' + encodeURIComponent(d.token));
        const send = o => { if (ws.readyState === 1) ws.send(JSON.stringify(o)); };
        ws.onopen = () => send({ setup: d.setup });
        ws.onmessage = async ev => {
          let m; try { m = JSON.parse(typeof ev.data === 'string' ? ev.data : await ev.data.text()); } catch (e) { return; }
          if (m.setupComplete) return h.ready();
          const sc = m.serverContent;
          if (sc) {
            if (sc.interrupted) h.interrupted();
            if (sc.inputTranscription && sc.inputTranscription.text) h.user(sc.inputTranscription.text);
            if (sc.outputTranscription && sc.outputTranscription.text) h.bot(sc.outputTranscription.text);
            for (const p of (sc.modelTurn && sc.modelTurn.parts) || []) if (p.inlineData && p.inlineData.data) h.audio(p.inlineData.data, 24000);
            if (sc.turnComplete) h.turnDone();
          }
          if (m.toolCall && m.toolCall.functionCalls) h.tools(m.toolCall.functionCalls);
          if (m.goAway) h.info('Sessiya tezliklə bitəcək (Gemini limiti). Yenidən başlatmaq lazım ola bilər.');
        };
        ws.onclose = ev => h.closed(ev.code, ev.reason);
        return {
          audio: data => send({ realtimeInput: { audio: { data, mimeType: 'audio/pcm;rate=16000' } } }),
          toolResults: out => send({ toolResponse: { functionResponses: out } }),
          note: text => send({ clientContent: { turns: [{ role: 'user', parts: [{ text }] }], turnComplete: true } }),
          text: text => { stopPlayback(); send({ clientContent: { turns: [{ role: 'user', parts: [{ text }] }], turnComplete: true } }); },
          mute: on => { if (on) send({ realtimeInput: { audioStreamEnd: true } }); },
          close: () => { if (ws.readyState <= 1) try { ws.close(1000); } catch (e) {} },
        };
      },
    },
    grok: {
      // xAI realtime hadisələri fal.ai relay-i ilə olduğu kimi ötürülür; çərçivələr msgpack-dir
      label: 'Grok Voice (fal.ai)', inRate: 24000, chunkMs: 100,
      connect(d, h) {
        const ws = new WebSocket(d.ws + '?fal_jwt_token=' + encodeURIComponent(d.token)); ws.binaryType = 'arraybuffer';
        const send = o => { if (ws.readyState === 1) ws.send(MP.encode(o)); };
        let pending = [], inResp = false, waitCreate = false, createAfter = false, userTxt = '', isReady = false, createT = null;
        // Cavab yaratmağı yalnız səs bitəndə istə (üst-üstə danışmasın). Bir gözləyən taymer olur: alət nəticəsi + qeyd eyni anda gəlsə iki response.create getməsin.
        const create = () => {
          createAfter = false;
          if (playing()) { waitCreate = true; if (!createT) createT = setTimeout(() => { createT = null; create(); }, 250); return; }
          waitCreate = false; send({ type: 'response.create' });
        };
        // Cavab gedərkən gələn qeyd/yazı: əvvəl itirdi (response.create göndərilmirdi); indi cavab bitəndə cavablanır
        const userItem = text => {
          send({ type: 'conversation.item.create', item: { type: 'message', role: 'user', content: [{ type: 'input_text', text }] } });
          if (inResp) createAfter = true; else if (!waitCreate) create();
        };
        ws.onopen = () => send({ type: 'session.update', session: d.session });
        ws.onmessage = ev => {
          let m; try { m = typeof ev.data === 'string' ? JSON.parse(ev.data) : MP.decode(ev.data); } catch (e) { return; }
          if (!m || typeof m !== 'object') return;
          if (m.type === 'x-fal-error' || (m.status === 'error' && m.error)) { h.info('fal.ai: ' + (m.error || '') + (m.reason ? ' · ' + m.reason : '')); return; }
          if (m.type === 'x-fal-message') return;
          switch (m.type) {
            case 'session.updated': if (!isReady) { isReady = true; h.ready(); } break;
            case 'error': h.info('Grok: ' + ((m.error && (m.error.message || m.error.code)) || JSON.stringify(m).slice(0, 200))); break;
            case 'input_audio_buffer.speech_started': h.interrupted(); userTxt = ''; break;
            case 'conversation.item.input_audio_transcription.delta': if (m.delta) h.user(m.delta); break;
            case 'conversation.item.input_audio_transcription.updated':
            case 'conversation.item.input_audio_transcription.completed': {
              const t = m.transcript || ''; if (t && t !== userTxt) { h.user(t, true); userTxt = t; } break;
            }
            case 'response.created': inResp = true; break;
            case 'response.output_audio.delta': case 'response.audio.delta': h.audio(m.delta, 24000); break;
            case 'response.output_audio_transcript.delta': case 'response.audio_transcript.delta': case 'response.text.delta': case 'response.output_text.delta':
              if (m.delta) h.bot(m.delta); break;
            case 'response.function_call_arguments.done': {
              let args = {}; try { args = m.arguments ? JSON.parse(m.arguments) : {}; } catch (e) {}
              pending.push({ id: m.call_id, name: m.name, args }); break;
            }
            case 'response.done': {
              inResp = false; userTxt = '';
              const st = m.response && m.response.status;
              if (st === 'failed') h.info('Grok cavabı alınmadı: ' + JSON.stringify((m.response.status_details || {}).error || m.response.status_details || '').slice(0, 200));
              if (pending.length) { const calls = pending; pending = []; h.tools(calls); }   // alətləri cavab bitəndə icra et (hamısını bir dəfəyə; nəticələr özü create edir)
              else { h.turnDone(); if (createAfter) create(); }
              break;
            }
          }
        };
        ws.onclose = ev => h.closed(ev.code, ev.reason);
        return {
          audio: data => send({ type: 'input_audio_buffer.append', audio: data }),
          toolResults: out => {
            for (const o of out) send({ type: 'conversation.item.create', item: { type: 'function_call_output', call_id: o.id, output: JSON.stringify(o.response) } });
            create();
          },
          note: userItem,
          text: userItem,                     // cavab gedirsə, o bitəndən sonra cavablanır (response.cancel alət çağırışlarını itirə bilər)
          mute: () => {},
          close: () => { clearTimeout(createT); createT = null; if (ws.readyState <= 1) try { ws.close(1000); } catch (e) {} },
        };
      },
    },
  };

  /* ---------- Sessiya (təminatçıdan asılı deyil) ---------- */
  const store = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
  const prov = $j('jv-prov');
  async function loadProviders() {
    const { status, d } = await api('status'); if (status !== 200) return;
    const list = (d.providers || []).filter(p => ADAPTERS[p]);
    prov.innerHTML = list.map(p => `<option value="${p}">${ADAPTERS[p].label}</option>`).join('');
    const saved = store.get('jv-provider');
    prov.value = list.includes(saved) ? saved : (d.provider || list[0] || '');
    prov.hidden = list.length < 2;
  }
  prov.onchange = () => store.set('jv-provider', prov.value);
  PRO.on = proBox.checked = store.get('jv-pro') !== '0';
  proBox.onchange = () => { PRO.on = proBox.checked; store.set('jv-pro', PRO.on ? '1' : '0'); };
  $j('jv-in').onsubmit = e => {
    e.preventDefault();
    const t = txtEl.value.trim(); if (!t || !J.ready || !J.conn) return;
    txtEl.value = ''; endTurn(); line('u', t); endTurn();
    J.conn.text(t);
  };
  async function start() {
    if (J.state !== 'off' && J.state !== 'error') return;
    if (!window.isSecureContext || !navigator.mediaDevices) { setState('error'); line('e', 'Mikrofon yalnız HTTPS və ya localhost-da işləyir.'); return; }
    setState('connecting'); J.ending = false; J.ready = false; endTurn();
    if (!prov.options.length) await loadProviders().catch(() => {});
    const { status, d } = await api('session', prov.value ? { provider: prov.value } : {});
    if (status !== 200) {
      setState('error', status === 503 ? 'açar qurulmayıb' : 'xəta');
      line('e', d.error || 'Sessiya alınmadı');
      if (d.setup) line('i', d.setup);
      return;
    }
    const ad = ADAPTERS[d.provider];
    if (!ad) { setState('error'); line('e', 'Naməlum səs təminatçısı: ' + d.provider); return; }
    J.session = d; prov.disabled = true;
    try { startOut(); await startMic(ad); }
    catch (e) { cleanup(); setState('error'); line('e', 'Mikrofona icazə verilmədi: ' + (e.message || e)); return; }
    J.conn = ad.connect(d, {
      ready() {
        J.ready = true; J.t0 = J.lastUsage = Date.now(); setState(J.muted ? 'muted' : 'listening');
        line('i', 'Qoşuldu · ' + d.model);
        J.usageTimer = setInterval(reportUsage, 30000);
        txtEl.disabled = false;
        try { proStart(); briefing(); } catch (e) { /* məlumat hələ yoxdursa brifinqsiz davam */ }
      },
      audio: (data, rate) => play(data, rate),
      user(text, replace) {
        J.cur.jarvis = null;
        if (replace && J.cur.user) { J.cur.user._t = ''; }
        stream('user', text);
      },
      bot: text => stream('jarvis', text),
      interrupted() { stopPlayback(); endTurn(); if (J.state === 'speaking') setState(J.muted ? 'muted' : 'listening'); },
      turnDone: endTurn,
      tools: calls => { endTurn(); runTools(calls); },
      info: msg => line('e', msg),
      closed(code, reason) {
        if (J.state === 'off') return;
        const why = reason ? reason : code === 1006 ? 'bağlantı qırıldı' : 'kod ' + code;
        if (!J.ending && code !== 1000) line('e', (ad.label || 'Səs') + ' bağlantısı bağlandı: ' + why);
        cleanup(); setState(J.ending || code === 1000 ? 'off' : 'error');
      },
    });
  }
  async function reportUsage() {
    const s = Math.round((Date.now() - J.lastUsage) / 1000); J.lastUsage = Date.now();
    if (s <= 0) return;
    const { d } = await api('usage', { seconds: s });
    if (d.over) { line('i', 'Bu günün Jarvis limiti bitdi.'); stop(); }
  }
  function cleanup() {
    clearInterval(J.usageTimer); J.usageTimer = null;
    clearInterval(PRO.timer); PRO.timer = null; txtEl.disabled = true;
    ctx.task = ctx.project = ctx.user = ctx.cmd = null;              // yeni söhbət: “bu/o” köhnə sessiyaya getməsin
    // son istifadə hissəsi: səhifə bağlananda da çatsın (keepalive)
    if (J.ready && J.lastUsage) { const s = Math.round((Date.now() - J.lastUsage) / 1000); if (s > 0) api('usage', { seconds: s }, { keepalive: true }).catch(() => {}); }
    J.ready = false; J.lastUsage = 0; prov.disabled = false;
    stopPlayback();
    if (J.micStream) J.micStream.getTracks().forEach(t => t.stop());
    if (J.micCtx) J.micCtx.close().catch(() => {});
    if (J.outCtx) J.outCtx.close().catch(() => {});
    J.micStream = J.micCtx = J.micNode = J.outCtx = J.outAn = J.outGain = null;
    const c = J.conn; J.conn = null; if (c) c.close();
  }
  function stop(msg) { if (J.state === 'off') return; J.state = 'off'; cleanup(); setState('off'); if (msg) line('i', msg); }
  function toggleMute() {
    J.muted = !J.muted;
    $j('jv-mic').textContent = J.muted ? '🔇 Mikrofon bağlı' : '🎙 Mikrofon açıq'; $j('jv-mic').classList.toggle('on', J.muted);
    if (J.ready && J.conn) J.conn.mute(J.muted);
    if (J.ready && J.state !== 'speaking' && J.state !== 'tool') setState(J.muted ? 'muted' : 'listening');
  }

  btn.onclick = async () => { showBox(box.hidden); if (!box.hidden && J.state === 'off') { await loadProviders().catch(() => {}); start(); } };
  $j('jv-close').onclick = () => { stop(); showBox(false); };
  goEl.onclick = () => (J.state === 'off' || J.state === 'error') ? start() : stop('Sessiya bitdi');
  $j('jv-mic').onclick = toggleMute;
  addEventListener('keydown', e => {
    if (e.altKey && (e.code === 'KeyJ')) { e.preventDefault(); if (box.hidden) { showBox(true); start(); } else if (J.state === 'off' || J.state === 'error') start(); else stop('Sessiya bitdi'); }
  });
  addEventListener('beforeunload', () => cleanup());
  window.Jarvis = { start, stop, say: t => J.ready && J.conn && J.conn.note(String(t)), tools: TOOLS, ui: UI, suggestions, state: () => J.state, adapters: ADAPTERS, msgpack: MP };
})();
