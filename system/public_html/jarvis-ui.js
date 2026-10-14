/* Jarvis UI modulu: saytın idarəsi (adlı əməliyyatlar cədvəli), ekran konteksti, göstərmə (retikl/işıqlandırma) və kinematik tur.
   Koordinatla klik yoxdur: hər əməliyyat index.php-dəki real funksiyaya (go, openTaskModal, openMsg, closeM…) və ya real düyməyə bağlıdır.
   Alət deklarasiyaları: jarvis_modules/ui.php. Xaricə yalnız window.JarvisUI çıxır. */
(() => {
  if (!window.Jarvis) return;
  const JV = window.Jarvis, U = JV.util;
  const $u = id => document.getElementById(id);
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const frame = () => new Promise(r => requestAnimationFrame(() => r()));
  const settle = () => sleep(60);                           // hashchange → route() → render bitsin
  const no = (error, extra) => ({ ok: false, error, ...extra });
  const say = (msg, extra) => U.ok(msg, extra);
  const gl = () => typeof K !== 'undefined' && K && typeof glOn !== 'undefined' && glOn;
  const ready = () => JV.ready();

  /* ---------- Uyğunlaşdırma: əsas seçim Jarvis.util.best, təkliflər üçün sıralama ---------- */
  function lev(a, b) {
    if (Math.abs(a.length - b.length) > 3) return 9;
    const d = Array.from({ length: a.length + 1 }, (_, i) => [i]);
    for (let j = 1; j <= b.length; j++) d[0][j] = j;
    for (let i = 1; i <= a.length; i++) for (let j = 1; j <= b.length; j++) d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    return d[a.length][b.length];
  }
  const sc = (nk, nq) => {
    if (!nk || !nq) return 0;
    let s = nk === nq ? 100 : nk.startsWith(nq) || nq.startsWith(nk) ? 80 : nk.includes(nq) || nq.includes(nk) ? 65 : 0;
    if (!s) { const w = nq.split(' ').filter(x => x.length > 2 && nk.includes(x)).length; if (w) s = 40 + w * 8; }
    if (!s && lev(nk, nq) <= Math.max(1, Math.floor(nq.length / 3))) s = 55;
    return s;
  };
  /** Bütün uyğunlar xalla, azalan sıra (təkliflər və prioritet üçün) */
  function rank(items, q, key) {
    const nq = U.norm(q), out = [];
    for (const it of items) { let s = 0; for (const k of [].concat(key(it))) s = Math.max(s, sc(U.norm(k), nq)); if (s >= 40) out.push({ it, s }); }
    return out.sort((a, b) => b.s - a.s);
  }

  /* ---------- Yer adları: planet, univers, gəmi ---------- */
  const uniById = id => (UNIVERSES.find(u => u.id === id) || {});
  const uniName = id => id ? uniById(id).name || id : 'map';
  const findUni = q => U.best(typeof visUnis === 'function' ? visUnis() : UNIVERSES, q, x => [x.name, x.id, x.name.replace(' Universe', ''), x.name + ' universe', x.name + ' universi', x.name + ' qalaktikasi']);
  const findPlanet = q => data ? U.best(U.planetNames(), q, p => [p.name, p.label, p.name.replace(/^(mcp|agent|desktop):/, '')]) : null;
  /** 3D-dəki etiket mətni (renderSpace ilə eyni: label || name) */
  function labelOf(n) {
    if (!data) return n;
    const full = spaceList(buildProjects());
    for (const u of UNIVERSES) { const p = uniList(full, u.id).find(x => x.name === n); if (p) return p.label || p.name; }
    return n;
  }
  const MAPW = /^(map|xerite|kosmik xerite|butun xerite|kainat|space|space map|universe map|home|butun universler)$/;
  const PLANW = /^(plan|plan stansiyasi|plan gemisi|plan station|plans|planlar)$/;
  const SHIPW = /\b(gemi|gemisi|gemim|ship|spaceship)\b/;

  /* ---------- Ekran konteksti ---------- */
  let selTask = null;                                       // istifadəçinin baxdığı/seçdiyi tapşırıq (id)
  const openModals = () => [...document.querySelectorAll('.modal')].filter(m => !m.hidden).map(m => ({ id: m.id, title: ((m.querySelector('.mhead b') || {}).textContent || m.id).trim() }));
  function planetFacts(n) {
    const o = { name: n, label: labelOf(n).replace(/^\p{Extended_Pictographic}️?\s*/u, ''), universe: uniName(uniOf(n)) };
    const x = xKind(n);
    if (x && x.kind === 'agentu') { const a = agentInfo(x.name); return { ...o, kind: 'agent', user: x.name, online: a.online, running: a.run.length, queued: a.queued.length }; }
    if (x && x.kind === 'deskt') { const a = agentInfo(x.name); return { ...o, kind: 'desktop', user: x.name, online: a.online }; }
    if (x && x.kind === 'mcpu') { const m = mcpServers().find(s => s.name === x.name); return { ...o, kind: 'mcp', calls: m ? m.n : 0, projects: m ? [...m.projects.keys()] : [] }; }
    const p = buildProjects().find(z => z.name === n), ts = (data.tasks || []).filter(t => t.project === n && t.status !== 'done');
    o.kind = x ? x.kind : (data.worlds || []).some(w => w.name === n) ? 'custom' : 'project';
    o.status = p ? p.st : 'offline'; o.open_tasks = ts.length; o.doing = ts.filter(t => t.status === 'doing').length;
    o.burning = burnOf(n) > 0; if (o.burning) o.hours_without_finished_task = Math.floor(idleOf(n) / 3600);
    o.active_users = (data.sessions || []).filter(s => s.project === n && RANK[eff(s)] > 0).map(s => s.user + ' (' + eff(s) + ')');
    o.agents_running = [...new Set((data.commands || []).filter(c => c.project === n && c.status === 'running').map(c => c.user))];
    const f = (data.tests || []).filter(t => t.project === n && t.status === 'fail').length; if (f) o.failing_tests = f;
    if (p && p.last) o.last_activity = ago(p.last);
    if (x && x.kind === 'meeting') { const lm = liveMeetings(); o.live_meeting = lm.length ? lm[0].title : null; }
    return o;
  }
  function context() {
    const c = { route: location.hash || '#', view, viewport: { w: innerWidth, h: innerHeight, fullscreen: !!document.fullscreenElement } };
    if (!data) return { ...c, loading: true };
    if (view === 'space') {
      c.universe = curPlanet ? uniName(uniOf(curPlanet)) : curShip ? uniName('main') : navUni ? uniName(navUni) : 'map (all universes)';
      if (curPlanet) { c.planet = planetFacts(curPlanet); if (!xKind(curPlanet)) c.dock_tab = dockTab; else if (curPlanet === 'Test Planet') c.test_project = tsProject; }
      if (curShip) c.plan_station = { project: plProject };
    }
    if (view === 'board') {
      const l = boardTasks();
      c.board = { who: bfWho === 'me' ? 'mine' : 'all', project: bfPrj || 'all', columns: Object.fromEntries(TCOLS.map(([k]) => [k, l.filter(t => t.status === k).length])) };
    }
    if (view === 'panel') c.panel_filter = filter;
    const m = openModals(); if (m.length) c.windows = m;
    const t = selTask && (data.tasks || []).find(x => +x.id === +selTask);
    if (t) c.selected_task = { title: t.title, project: t.project, status: t.status, assignee: t.assignee, due: dueInfo(t) ? dueInfo(t).text : null };
    if (tour) c.tour = { stop: tour.i + 1, of: tour.stops.length };
    if (window.JarvisHUD && typeof JarvisHUD.list === 'function') { try { const h = JarvisHUD.list(); if (h && h.length) c.hud_panels = h; } catch (e) {} }
    return c;
  }
  /** Dəyişikliyin açarı: yalnız mənalı dəyişikliklər (marşrut, univers, planet, pəncərə, süzgəc, seçim) */
  const keyOf = c => [c.view, c.universe, c.planet && c.planet.name, c.plan_station && c.plan_station.project, c.dock_tab, c.test_project, (c.windows || []).map(w => w.id).join(), c.board && c.board.who + c.board.project, c.panel_filter, c.selected_task && c.selected_task.title].join('|');
  function screenLine(c) {
    const p = [];
    if (c.loading) return '[Screen] loading';
    if (c.view === 'panel') p.push('main panel' + (c.panel_filter && c.panel_filter !== 'all' ? ' (filter: ' + c.panel_filter + ')' : ''));
    if (c.view === 'board') p.push(`task board (${c.board.who}, project: ${c.board.project}; todo ${c.board.columns.todo}, doing ${c.board.columns.doing}, review ${c.board.columns.review}, done ${c.board.columns.done})`);
    if (c.view === 'space') {
      if (c.plan_station) p.push('Plan station' + (c.plan_station.project ? ', project ' + c.plan_station.project : ''));
      else if (c.planet) {
        const x = c.planet, f = [];
        if (x.kind === 'agent' || x.kind === 'desktop') f.push(x.user + "'s " + x.kind, x.online ? 'online' : 'offline', x.running ? x.running + ' running' : '');
        else if (x.kind === 'mcp') f.push('MCP server', x.calls + ' calls');
        else {
          f.push(x.open_tasks + ' open tasks');
          if (x.burning) f.push('burning ' + x.hours_without_finished_task + 'h');
          if (x.agents_running && x.agents_running.length) f.push('agent running: ' + x.agents_running.join(','));
          if (x.active_users && x.active_users.length) f.push('active: ' + x.active_users.join(','));
          if (x.failing_tests) f.push(x.failing_tests + ' failing tests');
          if (c.test_project) f.push('test project ' + c.test_project);
        }
        p.push(`${c.universe} › planet "${x.label}" (${f.filter(Boolean).join(', ')})`);
      } else p.push('space › ' + c.universe);
    }
    if (c.windows) p.push('window: ' + c.windows.map(w => w.title).join(', '));
    if (c.selected_task) p.push(`selected task "${c.selected_task.title}" (${c.selected_task.status}, ${c.selected_task.assignee})`);
    const s = '[Screen] ' + p.join(' · ');
    return s.length > 300 ? s.slice(0, 297) + '…' : s;
  }
  /* Səssiz kontekst: hər saniyə yoxla, açar dəyişib 1.5 san sabit qalanda göndər (təkrarsız) */
  let ctxSeen = '', ctxAt = 0, ctxSent = '';
  setInterval(() => {
    if (!ready() || tour || !data) return;
    let c; try { c = context(); } catch (e) { return; }
    const k = keyOf(c);
    if (k !== ctxSeen) { ctxSeen = k; ctxAt = Date.now(); return; }
    if (k === ctxSent || Date.now() - ctxAt < 1500) return;
    ctxSent = k; JV.context(screenLine(c));
  }, 1000);
  JV.on('ready', () => { ctxSent = ''; ctxSeen = ''; });
  JV.on('stop', () => stopTour('session'));
  document.addEventListener('click', e => { const t = e.target.closest && e.target.closest('#bcols .tcard[data-k]'); if (t) selTask = +t.dataset.k; }, true);

  /* ---------- Holoqrafik retikl və işıqlandırma ---------- */
  const css = `
.jui-ret { position: fixed; left: 0; top: 0; z-index: 66; pointer-events: none; width: var(--s); height: var(--s); opacity: 0; transition: opacity .35s ease; color: #7dd3fc; }
.jui-ret.on { opacity: 1; }
.jui-ret i { position: absolute; inset: 0; border-radius: 50%; border: 1.5px dashed rgba(125,211,252,.85); animation: jui-spin 6s linear infinite; }
.jui-ret i + i { inset: 14%; border: 1px solid rgba(251,191,36,.75); border-style: solid dotted; animation-duration: 3.6s; animation-direction: reverse; }
.jui-ret b { position: absolute; inset: -6%; border-radius: 50%; box-shadow: 0 0 22px rgba(56,189,248,.55), inset 0 0 18px rgba(56,189,248,.35); animation: jui-pulse 1.4s ease-in-out infinite; }
.jui-ret s { position: absolute; left: 50%; top: 50%; width: 6px; height: 6px; margin: -3px; border-radius: 50%; background: #fbbf24; box-shadow: 0 0 10px #fbbf24; }
.jui-ret u { position: absolute; width: 16%; height: 16%; border: 2px solid #38bdf8; text-decoration: none; }
.jui-ret u:nth-of-type(1) { left: -4%; top: -4%; border-right: 0; border-bottom: 0; } .jui-ret u:nth-of-type(2) { right: -4%; top: -4%; border-left: 0; border-bottom: 0; }
.jui-ret u:nth-of-type(3) { left: -4%; bottom: -4%; border-right: 0; border-top: 0; } .jui-ret u:nth-of-type(4) { right: -4%; bottom: -4%; border-left: 0; border-top: 0; }
.jui-ret.lock { animation: jui-lock .7s cubic-bezier(.2,.8,.2,1); }
.jui-ret em, .jui-hl em { position: absolute; left: 50%; top: calc(100% + 10px); transform: translateX(-50%); white-space: nowrap; font: 600 11.5px/1.3 ui-monospace, Menlo, monospace; letter-spacing: .12em; text-transform: uppercase;
  color: #e0f2fe; background: rgba(8,12,24,.78); border: 1px solid rgba(125,211,252,.45); border-radius: 6px; padding: 3px 8px; font-style: normal; text-shadow: 0 0 8px rgba(56,189,248,.8); }
.jui-hl { position: fixed; left: 0; top: 0; z-index: 66; pointer-events: none; border: 2px solid #38bdf8; border-radius: 12px; opacity: 0; transition: opacity .3s ease;
  box-shadow: 0 0 0 4px rgba(56,189,248,.18), 0 0 26px rgba(56,189,248,.75), inset 0 0 14px rgba(56,189,248,.35); animation: jui-glow 1.3s ease-in-out infinite; }
.jui-hl.on { opacity: 1; } .jui-hl em { top: auto; bottom: calc(100% + 8px); }
.jui-tour { position: fixed; left: 50%; top: 12px; transform: translateX(-50%); z-index: 66; display: flex; align-items: center; gap: 10px; padding: 6px 8px 6px 14px; border-radius: 999px;
  background: rgba(8,10,20,.82); border: 1px solid rgba(125,211,252,.4); color: #e0f2fe; font: 600 12.5px/1.2 ui-monospace, Menlo, monospace; letter-spacing: .08em; box-shadow: 0 0 24px rgba(56,189,248,.35); max-width: calc(100vw - 32px); }
.jui-tour[hidden] { display: none; } .jui-tour span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.jui-tour button { border: 0; border-radius: 999px; background: rgba(248,113,113,.2); color: #fecaca; font: inherit; padding: 4px 10px; cursor: pointer; }
@keyframes jui-spin { to { transform: rotate(360deg); } }
@keyframes jui-pulse { 50% { transform: scale(1.06); opacity: .55; } }
@keyframes jui-glow { 50% { box-shadow: 0 0 0 7px rgba(56,189,248,.08), 0 0 40px rgba(56,189,248,.95), inset 0 0 18px rgba(56,189,248,.5); } }
@keyframes jui-lock { from { transform: var(--tr) scale(1.9); opacity: 0; } to { transform: var(--tr) scale(1); opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .jui-ret i, .jui-ret b, .jui-hl, .jui-ret.lock { animation: none; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const ret = document.createElement('div'); ret.className = 'jui-ret'; ret.setAttribute('aria-hidden', 'true');
  ret.innerHTML = '<b></b><i></i><i></i><u></u><u></u><u></u><u></u><s></s><em></em>';
  const hl = document.createElement('div'); hl.className = 'jui-hl'; hl.setAttribute('aria-hidden', 'true'); hl.innerHTML = '<em></em>';
  const tbar = document.createElement('div'); tbar.className = 'jui-tour'; tbar.hidden = true; tbar.setAttribute('role', 'status');
  tbar.innerHTML = '<span id="jui-tour-t"></span><button type="button">■ Dayan</button>';
  document.body.append(ret, hl, tbar);
  tbar.querySelector('button').onclick = () => stopTour('button');

  /* Retikl: pos() hər kadrda ekran nöqtəsini verir ({x,y,s} və ya null = son yer qalır); ~6 san sonra gizlənir */
  let retJob = 0;
  const spaceCentre = () => { const r = $u('space').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, s: Math.max(90, Math.min(360, r.height * .42)) }; };
  function showRet(label, pos, ms) {
    const job = ++retJob, t0 = performance.now(); let last = null;
    ret.querySelector('em').textContent = label; ret.classList.remove('lock'); void ret.offsetWidth; ret.classList.add('on', 'lock');
    const step = () => {
      if (job !== retJob) return;
      if (performance.now() - t0 > (ms || 6000)) { ret.classList.remove('on'); return; }
      const q = pos() || last; last = q;
      if (q) { const tr = `translate(${(q.x - q.s / 2).toFixed(1)}px,${(q.y - q.s / 2).toFixed(1)}px)`; ret.style.setProperty('--s', q.s.toFixed(0) + 'px'); ret.style.setProperty('--tr', tr); ret.style.transform = tr; }
      requestAnimationFrame(step);
    };
    step();
  }
  const hideRet = () => { retJob++; ret.classList.remove('on'); };
  /** 3D etiketin ekran yeri (kainat etiketi planetin altında durur: retikl etiketin üstünə) */
  function labelPos(sel, text) {
    const box = document.querySelector('#space .glbls'); if (!box) return null;
    for (const b of box.querySelectorAll(sel)) {
      const t = b.querySelector('b'); if (!t || t.textContent !== text) continue;
      if (b.style.visibility === 'hidden' || +(b.style.opacity || 1) < .2) return null;
      const r = b.getBoundingClientRect(); if (!r.width) return null;
      const s = 70; return { x: r.left + r.width / 2, y: r.top - s / 2 - 4, s };
    }
    return null;
  }
  let hlJob = 0;
  function showHl(el, label, ms) {
    const job = ++hlJob, t0 = performance.now();
    hl.querySelector('em').textContent = label || '';
    hl.classList.add('on');
    const step = () => {
      if (job !== hlJob) return;
      const r = el.getBoundingClientRect();
      if (performance.now() - t0 > (ms || 6000) || !el.isConnected) { hl.classList.remove('on'); return; }
      hl.style.transform = `translate(${(r.left - 6).toFixed(1)}px,${(r.top - 6).toFixed(1)}px)`; hl.style.width = (r.width + 12) + 'px'; hl.style.height = (r.height + 12) + 'px';
      hl.style.visibility = r.width || r.height ? 'visible' : 'hidden';
      requestAnimationFrame(step);
    };
    step();
  }
  const visible = el => !!el && !el.hidden && el.getClientRects().length > 0;
  async function reveal(el, label) {
    if (!visible(el)) return false;
    el.scrollIntoView({ block: 'center', inline: 'nearest', behavior: REDUCE ? 'auto' : 'smooth' });
    await sleep(REDUCE ? 0 : 350); showHl(el, label); return true;
  }

  /* ---------- Naviqasiya köməkçiləri ---------- */
  const nav = h => { if (location.hash !== h && !(h === '#' && !location.hash)) location.hash = h; };
  const goP = n => { if (!(view === 'space' && curPlanet === n)) go(n); };
  const toView = async v => { const h = v === 'board' ? '#lovhe' : v === 'panel' ? '#' : '#kainat'; if (view !== v) { nav(h); await settle(); } };
  const needData = () => { if (!data || !ME) throw new Error('Məlumat hələ yüklənməyib'); };
  function place(q) {
    const nq = U.norm(q);
    if (!nq) return null;
    if (MAPW.test(nq)) return { type: 'map', label: 'Kosmik xəritə' };
    if (PLANW.test(nq)) return { type: 'station', name: SHIPKEY, label: '🛸 Plan' };
    if (SHIPW.test(nq) && data) {
      const who = nq.replace(SHIPW, ' ').replace(/\b(in|nin|nun|un|s|my|menim)\b/g, ' ').trim();
      const u = U.userName(who || 'me') || (data.ships || []).map(s => s.user).find(x => U.norm(shipOf(x).name) === nq);
      if (u) return { type: 'ship', user: u, label: shipOf(u).name };
    }
    const un = findUni(q), pl = findPlanet(q);
    if (un && (!pl || /univers|qalaktika|galaxy/.test(nq) || U.norm(un.name) === nq || un.id === nq)) return { type: 'universe', id: un.id, label: un.icon + ' ' + un.name };
    if (pl) return { type: 'planet', name: pl.name, label: pl.label || pl.name };
    const sh = data && (data.users || []).find(u => U.norm(shipOf(u).name) === nq);
    return sh ? { type: 'ship', user: sh, label: shipOf(sh).name } : null;
  }
  function flyPlace(p) {
    if (p.type === 'map') nav('#kainat');
    else if (p.type === 'universe') nav('#kainat/@' + p.id);
    else if (p.type === 'station' || p.type === 'planet') goP(p.name);
    else if (p.type === 'ship' && (view !== 'space' || curPlanet || curShip)) nav('#kainat/@main');
  }
  const userOrMe = t => { const u = U.userName(t || 'me'); if (!u) throw new Error('Belə istifadəçi yoxdur: ' + t); return u; };
  const modalOpen = id => !$u(id).hidden;
  const clickBtn = (id, adminOnly) => { const b = $u(id); if (!b || b.hidden) throw new Error(adminOnly ? 'Bu pəncərə yalnız admin üçündür' : 'Düymə indi görünmür'); b.click(); };
  const STATUS_W = [['todo', 'todo|gozleyir|gozleyen|to do|waiting'], ['doing', 'doing|isleyir|in progress|islenir'], ['review', 'review|yoxlamada|yoxlama'], ['done', 'done|bitdi|bitib|hazir|finished']];
  const findStatus = q => { const m = U.best(STATUS_W, q, x => x[1].split('|')); return m ? m[0] : null; };
  const stationGo = n => () => { goP(n); return say(labelOf(n)); };
  function stepPlanet(dir) {
    if (view !== 'space' || !curPlanet) return no('Planet açıq deyil: əvvəl bir planetə keç');
    const u = uniOf(curPlanet), names = U.planetNames().filter(p => p.uni === u).map(p => p.name).sort((a, b) => a.localeCompare(b));
    if (names.length < 2) return no('Bu universdə başqa planet yoxdur');
    const n = names[(names.indexOf(curPlanet) + dir + names.length) % names.length]; go(n); return say(labelOf(n));
  }

  /* ---------- Əməliyyatlar cədvəli: id, AZ/EN adlar, təsvir, parametr, icra ---------- */
  const A = (id, az, en, d, p, run) => ({ id, az: az.split('|'), en: en.split('|'), d, p, run });
  const ACTIONS = [
    A('panel', 'panel|esas panel|ana sehife|idare paneli', 'panel|dashboard|home page|main panel', 'Əsas panel görünüşü', null, () => { nav('#'); return say('Panel'); }),
    A('map', 'xerite|kosmik xerite|kainat|butun universler', 'map|space map|space|universe map|overview', 'Bütün universlərin kosmik xəritəsi', null, () => { nav('#kainat'); return say('Kosmik xəritə'); }),
    A('board', 'lovhe|tapsiriq lovhesi|tapsiriqlar', 'board|task board|tasks|kanban', 'Tapşırıq lövhəsi', null, () => { nav('#lovhe'); return say('Lövhə'); }),
    A('universe', 'univers|qalaktika', 'universe|galaxy', 'Universə uç', 'universe: Komanda, Studio, MCP, Agent, Desktop', (t) => {
      const u = findUni(t || ''); if (!u) return no('Belə univers yoxdur: ' + t, { options: UNIVERSES.map(x => x.name) });
      nav('#kainat/@' + u.id); return say(u.icon + ' ' + u.name);
    }),
    A('planet', 'planet|proyekt|planete uc', 'planet|project|fly to|go to', 'Planetə uç (proyekt, Studio stansiyası, MCP, agent)', 'planet name', (t) => {
      const p = findPlanet(t || ''); if (!p) return no('Belə planet tapılmadı: ' + t, { close: rank(U.planetNames(), t || '', x => [x.name, x.label]).slice(0, 3).map(x => x.it.name) });
      goP(p.name); return say(p.label || p.name);
    }),
    A('plan_station', 'plan|plan stansiyasi|plan gemisi|planlar', 'plan station|plans|plan ship', 'Plan stansiyası (planlar, scope-lar)', null, () => { goP(SHIPKEY); return say('🛸 Plan stansiyası'); }),
    A('plan_project', 'plan proyekti', 'plan project', 'Plan stansiyasında proyekt seç', 'project', (t) => {
      const pr = U.best(planProjects(), t || '', x => x); if (!pr) return no('Plan proyekti tapılmadı: ' + t, { options: planProjects() });
      goP(SHIPKEY); plProject = pr; renderPlan(); return say('Plan: ' + pr);
    }),
    A('prompt_generator', 'prompt generator|prompt yaradici|promptlar', 'prompt generator|prompts|prompt library', 'Prompt Generator stansiyası', null, stationGo('Prompt Generator')),
    A('designer', 'dizayner|dizayn', 'designer|design', 'Designer stansiyası', null, stationGo('Designer')),
    A('meeting_planet', 'gorus planeti|gorusler|iclas', 'meeting planet|meetings', 'Meeting Planet (görüşlər)', null, stationGo('Meeting Planet')),
    A('test_planet', 'test planeti|testler', 'test planet|tests|qa', 'Test Planet', null, stationGo('Test Planet')),
    A('test_project', 'test proyekti', 'test project', 'Test Planet-də proyekt seç', 'project', async (t) => {
      goP('Test Planet'); await settle(); renderTests();
      const names = [...$u('ts-prjs').children].map(b => b.dataset.k), n = U.best(names, t || '', x => x);
      if (!n) return no('Test proyekti tapılmadı: ' + t, { options: names });
      $u('ts-prjs').querySelector(`[data-k="${CSS.escape(n)}"]`).click(); return say('Test: ' + n);
    }),
    A('agent', 'agent|agent planeti|agenti', "agent|agent planet|user's agent", 'İstifadəçinin Claude Code agent planeti (Agent Universe)', 'user (default: me)', (t) => { const u = userOrMe(t); go('agent:' + u); return say('🤖 ' + u); }),
    A('desktop', 'kompyuter|desktop', 'desktop|computer|remote', 'İstifadəçinin kompüter planeti (Desktop Universe, uzaqdan komanda)', 'user (default: me)', (t) => { const u = userOrMe(t); go('desktop:' + u); return say('🖥 ' + u); }),
    A('back', 'geri|qayit|kainata qayit', 'back|go back|return', 'Geri: planetdən universə, universdən xəritəyə', null, () => {
      if (view === 'space' && (curPlanet || curShip)) { go(); return say('Geri'); }
      if (view === 'space' && navUni) { nav('#kainat'); return say('Kosmik xəritə'); }
      history.back(); return say('Geri');
    }),
    A('next_planet', 'novbeti planet|sonraki', 'next planet|next', 'Eyni universdə növbəti planet', null, () => stepPlanet(1)),
    A('prev_planet', 'evvelki planet|qabaqki', 'previous planet|prev|previous', 'Eyni universdə əvvəlki planet', null, () => stepPlanet(-1)),
    A('zoom_in', 'yaxinlas|yaxinlasdir|boyut', 'zoom in|closer|magnify', 'Kameranı yaxınlaşdır', 'steps 1-5', (t, v) => zoom(.78, v)),
    A('zoom_out', 'uzaqlas|uzaqlasdir|kicilt', 'zoom out|further|back off', 'Kameranı uzaqlaşdır', 'steps 1-5', (t, v) => zoom(1.28, v)),
    A('new_task', 'yeni tapsiriq|tapsiriq yarat pencere', 'new task|create task dialog|add task', 'Yeni tapşırıq pəncərəsini aç (yadda saxlamır)', 'project; value: title to prefill', (t, v) => {
      needData(); const p = t ? findPlanet(t) : null; openTaskModal(p ? p.name : t || undefined); if (v) $u('tk-title').value = String(v).slice(0, 200);
      return say('Yeni tapşırıq pəncərəsi' + (p ? ': ' + p.name : ''));
    }),
    A('messages', 'mesaj|mesajlar|mesaj yaz|qutu', 'messages|message|inbox|chat', 'Mesaj pəncərəsi (yaz / qutu)', 'recipient; value: write|inbox', (t, v) => {
      needData(); const to = t && !/^(all|hami|hamiya)$/.test(U.norm(t)) ? U.userName(t) : '*'; openMsg(to || '*');
      if (/inbox|qutu|gelen/.test(U.norm(v || t || ''))) showMsgTab('inbox');
      return say('Mesajlar' + (to && to !== '*' ? ' → ' + to : ''));
    }),
    A('report', 'hesabat|gunluk hesabat', 'report|daily report', 'Günlük hesabat pəncərəsi', 'value: today|yesterday', (t, v) => {
      clickBtn('b-rep'); const q = U.norm((v || '') + ' ' + (t || ''));
      if (/yesterday|dunen/.test(q) || (/today|bu gun/.test(q) && repDay !== 'today')) { repDay = /yesterday|dunen/.test(q) ? 'yesterday' : 'today'; repLoad(); }
      return say('Hesabat' + (repDay === 'yesterday' ? ' (dünən)' : ''));
    }),
    A('account', 'hesab|parol|profil|zeng sesi', 'account|password|profile|my sound', 'Hesab pəncərəsi (parol, səs)', null, () => { clickBtn('b-acc'); return say('Hesab'); }),
    A('users', 'istifadeciler|uzvler', 'users|team members|admin', 'İstifadəçilər (admin)', null, () => { clickBtn('b-adm', 1); return say('İstifadəçilər'); }),
    A('projects_admin', 'proyektler pencere|proyekt idaresi', 'projects admin|manage projects', 'Proyektlərin idarəsi (admin)', null, () => { clickBtn('b-prj', 1); return say('Proyektlər'); }),
    A('notifications', 'bildirisler|telegram', 'notifications|telegram', 'Telegram bildirişləri (admin)', null, () => { clickBtn('b-ntf', 1); return say('Bildirişlər'); }),
    A('my_ship', 'gemim|gemi|deadline', 'my ship|ship|spaceship', 'Gəmi pəncərəsi (deadline, görünüş); başqasının gəmisi yalnız baxış', 'user (default: me)', (t) => {
      needData(); const u = userOrMe(t); openShip(u); return say('🚀 ' + shipOf(u).name);
    }),
    A('new_planet', 'yeni planet|planetim', 'new planet|my planet|create planet', 'Yeni (fərdi) planet pəncərəsi', null, () => { clickBtn('t-world'); return say('🪐 Planet pəncərəsi'); }),
    A('mcp_satellites', 'mcp peykleri|peykler|mcp', 'mcp satellites|mcp servers|satellites', 'MCP peykləri pəncərəsi', null, () => { clickBtn('t-mcp'); return say('🛰 MCP peykləri'); }),
    A('new_meeting', 'gorus teyin et|yeni gorus', 'new meeting|schedule meeting dialog', 'Görüş pəncərəsi (yadda saxlamır)', null, () => { needData(); openMeeting(null); return say('🤝 Görüş pəncərəsi'); }),
    A('new_plan', 'yeni plan|yeni scope', 'new plan|new scope', 'Plan/scope pəncərəsi', 'project; value: plan|scope', (t, v) => {
      needData(); if (t) { const pr = U.best(planProjects(), t, x => x); plProject = pr || String(t).slice(0, 128); }
      openPlanModal(/scope/.test(U.norm(v || '')) ? 'scope' : 'plan'); return say('Yeni ' + plKind);
    }),
    A('close', 'bagla|qapat|gizlet', 'close|dismiss|hide', 'Açıq pəncərəni bağla (və ya planet panelini)', 'window name or "all"', (t) => {
      const ms = openModals();
      if (ms.length) {
        const pick = t && !/^(all|hamisi|hepsi)$/.test(U.norm(t)) ? U.best(ms, t, m => [m.id, m.title]) : null;
        for (const m of pick ? [pick] : ms) closeM(m.id);
        return say('Bağlandı: ' + (pick ? [pick] : ms).map(m => m.title).join(', '));
      }
      if (view === 'space' && (curPlanet || curShip)) { go(); return say('Panel bağlandı'); }
      return no('Açıq pəncərə yoxdur');
    }),
    A('board_filter', 'lovhe suzgeci|menim|hamisi', 'board filter|mine|all tasks|filter board', 'Lövhə süzgəci: mənim/hamısı və ya proyekt', 'mine | all | project name | all projects', async (t) => {
      needData(); await toView('board'); const q = U.norm(t || ''), out = [];
      if (/^(mine|me|menim|men|ozum|my)$/.test(q)) { bfWho = 'me'; out.push('Mənim'); }
      else if (/^(all|hamisi|hami|everyone|herkes)$/.test(q)) { bfWho = 'all'; out.push('Hamısı'); }
      else if (/^(all projects|butun proyektler|proyektsiz|clear|temizle|sifirla)$/.test(q)) { bfPrj = ''; out.push('Bütün proyektlər'); }
      else {
        const u = (data.users || []).includes(t) ? t : U.best(data.users || [], t || '', x => x);
        const opts = [...$u('bf-prj').options].map(o => o.value).filter(Boolean), pr = U.best(opts, t || '', x => x);
        if (pr) { bfPrj = pr; out.push('Proyekt: ' + pr); }
        else if (u) { bfWho = u === ME.name ? 'me' : 'all'; out.push(u === ME.name ? 'Mənim' : 'Hamısı (lövhə yalnız Mənim/Hamısı süzgəcini bilir)'); }
        else return no('Süzgəc başa düşülmədi: ' + t, { options: ['mine', 'all', 'all projects', ...opts] });
      }
      renderBoard(); return say('Lövhə: ' + out.join(', '));
    }),
    A('board_status', 'sutun|status sutunu', 'column|status column', 'Lövhədə status sütununu göstər', 'todo|doing|review|done', async (t) => {
      const s = findStatus(t || ''); if (!s) return no('Status: todo, doing, review və ya done');
      await toView('board'); await frame(); const c = $u('bcols').querySelector(`.bcol[data-s="${s}"]`);
      await reveal(c, Object.fromEntries(TCOLS)[s]); return say('Sütun: ' + Object.fromEntries(TCOLS)[s]);
    }),
    A('open_task', 'tapsirigi ac|tapsiriq karti', 'open task|show task|task card', 'Lövhədə tapşırıq kartını aç və işıqlandır', 'task title', (t) => openTask(t)),
    A('panel_filter', 'panel suzgeci|proyekt suzgeci', 'panel filter|filter projects by user', 'Paneldə proyektləri istifadəçiyə görə süz', 'user | all', async (t) => {
      needData(); await toView('panel'); const q = U.norm(t || '');
      filter = /^(all|hamisi|hami)$/.test(q) || !q ? 'all' : U.userName(t) || 'all'; render(); return say('Panel: ' + (filter === 'all' ? 'Hamısı' : filter));
    }),
    A('send_tab', 'gonder|agente gonder|tapsiriq gonder', 'send tab|send to agent|agent tab', 'Proyekt panelində "Göndər" (Claude Code-a tapşırıq) bölməsi', null, () => dockTo('send')),
    A('live_tab', 'canli|canli sessiyalar|kim isleyir', 'live tab|live|live sessions', 'Proyekt panelində "Canlı" sessiyalar bölməsi', null, () => dockTo('live')),
    A('sound', 'ses|sesi ac|sesi bagla|sessiz', 'sound|mute|unmute|audio', 'Kainat səsini aç/bağla', 'on|off|toggle', (t, v) => {
      if (!gl()) return no('Səs yalnız 3D kainatda var');
      const on = $u('sbtn').textContent.includes('🔊'), q = U.norm(v || t || 'toggle');
      const want = /\b(off|bagla|sondur|mute|sessiz)\b/.test(q) ? false : /\b(on|ac|yandir|unmute)\b/.test(q) ? true : !on;
      if (want !== on) $u('sbtn').click(); return say(want ? 'Səs açıq' : 'Səs bağlı');
    }),
    A('quality', 'keyfiyyet|hd', 'quality|hd|resolution', 'Təsvir keyfiyyəti: HD / Avto', 'hd|auto', (t, v) => {
      if (!gl()) return no('Keyfiyyət yalnız 3D kainatda var');
      const hd = $u('qbtn').classList.contains('on'), want = /auto|avto/.test(U.norm(v || t || '')) ? false : /hd|high|yuksek/.test(U.norm(v || t || '')) ? true : !hd;
      if (want !== hd) $u('qbtn').click(); return say(want ? 'HD' : 'Avto keyfiyyət');
    }),
    A('fullscreen', 'tam ekran', 'fullscreen|full screen', 'Kainatı tam ekran et / çıx', 'on|off', async (t, v) => {
      if (document.fullscreenElement) { await document.exitFullscreen().catch(() => {}); return say('Tam ekrandan çıxdım'); }
      if (/off|cix/.test(U.norm(v || ''))) return say('Tam ekran onsuz da bağlıdır');
      await toView('space');
      try { await $u('space').requestFullscreen(); return say('Tam ekran'); }
      catch (e) { if (visible($u('fs'))) showHl($u('fs'), '⛶'); return no('Brauzer tam ekranı yalnız klik ilə açır: işıqlanan ⛶ düyməsinə bas'); }
    }),
    A('scroll', 'surusdur|asagi|yuxari', 'scroll|scroll down|scroll up|page down', 'Açıq pəncərəni/paneli və ya səhifəni sürüşdür', 'up|down|top|bottom', (t, v) => {
      const q = U.norm(v || t || 'down'), m = openModals()[0];
      const box = m ? $u(m.id).querySelector('.mbox') : view === 'space' ? ['dock', 'xdock', 'plandock'].map($u).find(x => x && x.classList.contains('open')) : null;
      const tgt = box || document.scrollingElement, h = box ? box.clientHeight : innerHeight, beh = REDUCE ? 'auto' : 'smooth';
      if (/top|bas|evvel/.test(q)) tgt.scrollTo({ top: 0, behavior: beh });
      else if (/bottom|son|axir/.test(q)) tgt.scrollTo({ top: tgt.scrollHeight, behavior: beh });
      else tgt.scrollBy({ top: (/up|yuxari/.test(q) ? -1 : 1) * h * .8, behavior: beh });
      return say('Sürüşdürdüm');
    }),
    A('refresh', 'yenile|tezele', 'refresh|reload data|update', 'Məlumatı serverdən təzələ', null, async () => { await load(); return say('Təzələndi'); }),
  ];
  async function zoom(f, v) {
    if (!gl()) return no('Zoom yalnız 3D kainatda işləyir');
    await toView('space'); const n = Math.max(1, Math.min(5, Math.round(+v || 1)));
    K.zoom(Math.pow(f, n)); return say(f < 1 ? 'Yaxınlaşdırdım' : 'Uzaqlaşdırdım');
  }
  function dockTo(t) {
    if (!(view === 'space' && curPlanet && !xKind(curPlanet))) return no('Əvvəl bir proyekt planetinə keç (bu bölmə proyekt panelindədir)');
    setTab(t); return say(t === 'send' ? 'Göndər bölməsi' : 'Canlı sessiyalar');
  }
  async function openTask(q) {
    needData(); if (!q) return no('Hansı tapşırıq? Başlığını de');
    const t = await U.findTask(q); if (!t) return no('Tapşırıq tapılmadı: ' + q, { close: rank(data.tasks || [], q, x => x.title).slice(0, 3).map(x => x.it.title) });
    if (bfWho === 'me' && t.assignee !== ME.name) bfWho = 'all';
    if (bfPrj && bfPrj !== t.project) bfPrj = '';
    await toView('board'); renderBoard(); await frame();
    const card = $u('bcols').querySelector(`.tcard[data-k="${CSS.escape(String(t.id))}"]`);
    if (!card) return no('Kart lövhədə görünmür');
    selTask = +t.id;
    const r = card._r; if (r) { r.desc.classList.add('open'); if (!r.cmd.hidden) r.cmd.open = true; }
    await reveal(card, '◉ ' + t.title.slice(0, 40));
    return say('Tapşırıq: ' + t.title, { task: { title: t.title, project: t.project, status: t.status, assignee: t.assignee, due: dueInfo(t) ? dueInfo(t).text : null, details: (t.details || '').slice(0, 300) } });
  }
  const keysOf = a => [a.id, a.id.replace(/_/g, ' '), ...a.az, ...a.en];
  const GENERIC = /^(open|ac|show|goster|go|get|switch|kec|navigate|fly|uc|display|view|bax)$/;
  /** Əməliyyatı tap: id/ləqəb → cədvəl; "open"+hədəf → hədəfin özü; tapılmasa yer adı (planet/univers) */
  function match(action) { return U.best(ACTIONS, action || '', keysOf); }
  async function run(action, target, value) {
    let a = String(action || '').trim(), t = target == null ? '' : String(target).trim();
    if (GENERIC.test(U.norm(a)) && t) { a = t; t = ''; }
    const exact = ACTIONS.find(x => x.id === a);
    // ad həm əməliyyata, həm yerə uyğundursa (məs. "Komanda"), daha güclü uyğunluq qalib gəlir; bərabərdə əməliyyat
    const as = exact ? 100 : ((rank(ACTIONS, a, keysOf)[0] || {}).s || 0);
    const ps = exact || !data ? 0 : Math.max(0, ...[...rank(U.planetNames(), a, x => [x.name, x.label]), ...rank(UNIVERSES, a, x => [x.name, x.id])].map(x => x.s));
    const act = exact || (as >= ps ? match(a) : null);
    if (act) {
      try { return await act.run(t, value); }
      catch (e) { return no(String(e && e.message || e)); }
    }
    const p = data && place(a + (t ? ' ' + t : ''));
    if (p) { flyPlace(p); return say(p.label); }
    const close = rank(ACTIONS, a, keysOf).slice(0, 4).map(x => x.it.id);
    return no('Naməlum əməliyyat: ' + a, { close: close.length ? close : undefined, hint: 'call list_ui_actions for the catalogue' });
  }
  const catalogue = () => ACTIONS.map(a => ({ id: a.id, az: a.az.slice(0, 2).join(', '), en: a.en.slice(0, 2).join(', '), does: a.d, param: a.p || undefined }));

  /* ---------- Göstərmə: point_at və highlight ---------- */
  async function pointAt(q, fly) {
    if (!data) return no('Məlumat hələ yüklənməyib');
    const p = place(q || ''); if (!p) return no('Hədəf tapılmadı: ' + q, { close: rank(U.planetNames(), q || '', x => [x.name, x.label]).slice(0, 3).map(x => x.it.name) });
    if (p.type === 'planet' && fly === false && view === 'space' && !curPlanet && !curShip) {
      const lb = labelOf(p.name), pos = () => labelPos('.glabel:not(.shipl):not(.ushipl):not(.galbl):not(.sunl)', lb);
      if (pos()) { showRet(p.label, pos); return say('Budur: ' + p.label); }
    }
    if (p.type === 'ship') {
      flyPlace(p); const nm = shipOf(p.user).name, pos = () => labelPos('.ushipl', nm);
      for (let i = 0; i < 16 && !pos(); i++) await sleep(250);
      showRet('🚀 ' + nm, () => pos() || (gl() ? null : spaceCentre()));
      return say('🚀 ' + nm + (pos() ? '' : ' (gəmi hazırda görünmür)'));
    }
    const moved = !(view === 'space' && ((p.type === 'planet' || p.type === 'station') && (curPlanet === p.name || (p.name === SHIPKEY && curShip))));
    flyPlace(p);
    setTimeout(() => showRet(p.label, () => { const c = spaceCentre(); return p.type === 'planet' || p.type === 'station' ? c : { ...c, s: Math.min(220, c.s * .6) }; }), moved && !REDUCE ? 2200 : 0);
    return say((moved ? 'Uçuram: ' : 'Budur: ') + p.label, p.type === 'planet' ? { facts: planetFacts(p.name) } : undefined);
  }
  const HL = [
    ['jarvis', 'jarvis|jarvis duymesi', () => $u('jv-btn')], ['tab_panel', 'panel tab|panel', () => $u('tab-panel')], ['tab_space', 'kainat tab|kainat|space tab', () => $u('tab-space')],
    ['tab_board', 'lovhe tab|lovhe|board tab', () => $u('tab-board')], ['plan_button', 'plan duymesi|plan button', () => $u('b-plan')], ['messages_button', 'mesaj duymesi|mesaj|messages button', () => $u('b-msg')],
    ['report_button', 'hesabat duymesi|hesabat|report button', () => $u('b-rep')], ['account_button', 'hesab duymesi|hesab|account button', () => $u('b-acc')],
    ['users_button', 'istifadeciler duymesi|users button', () => $u('b-adm')], ['projects_button', 'proyektler duymesi|projects button', () => $u('b-prj')],
    ['notifications_button', 'bildirisler duymesi|notifications button', () => $u('b-ntf')], ['guide_link', 'telimat|guide|tutorial', () => document.querySelector('header a[href^="kit.php?doc"]')],
    ['kit_link', 'paket|kit|install kit', () => document.querySelector('header a[href="kit.php"]')], ['logout', 'cixis|logout|sign out', () => document.querySelector('header a[href="?logout=1"]')],
    ['new_task_button', 'yeni tapsiriq duymesi|new task button', () => $u('b-newtask'), 'board'], ['board_filters', 'lovhe suzgecleri|board filters', () => $u('bf-who'), 'board'],
    ['my_ship_button', 'gemim duymesi|my ship button', () => $u('t-ship'), 'space'], ['new_planet_button', 'yeni planet duymesi|new planet button', () => $u('t-world'), 'space'],
    ['mcp_button', 'mcp peykleri duymesi|mcp button', () => $u('t-mcp'), 'space'], ['zoom_buttons', 'zoom duymeleri|zoom', () => document.querySelector('#space .zbtns'), 'space'],
    ['sound_button', 'ses duymesi|sound button', () => $u('sbtn'), 'space'], ['quality_button', 'keyfiyyet duymesi|hd button', () => $u('qbtn'), 'space'],
    ['map_button', 'xerite duymesi|map button', () => $u('zmap'), 'space'], ['fullscreen_button', 'tam ekran duymesi|fullscreen button', () => $u('fs'), 'space'],
    ['stats', 'statistika|stats', () => $u('stats'), 'panel'], ['people', 'insanlar|uzvler|people|team', () => $u('people'), 'panel'], ['projects_list', 'proyekt kartlari|projects list', () => $u('projects'), 'panel'],
    ['feed', 'axin|lent|feed|activity', () => $u('feed'), 'panel'], ['project_panel', 'proyekt paneli|dock|project panel', () => ['dock', 'xdock', 'plandock'].map($u).find(x => x.classList.contains('open'))],
    ['send_box', 'gonder qutusu|send box|prompt box', () => $u('cmdmain')], ['alerts', 'xeberdarliqlar|toqqusma|alerts', () => $u('alerts')],
  ].map(([id, n, el, v]) => ({ id, n: n.split('|'), el, v }));
  async function highlight(q) {
    if (!q) return no('Nəyi göstərim?');
    const nq = U.norm(q), hit = rank(HL, q, h => [h.id.replace(/_/g, ' '), ...h.n])[0];
    const st = findStatus(q), strong = hit && hit.s >= 65;
    if (strong || (hit && !st && !findPlanet(q))) {
      const h = hit.it; if (h.v) await toView(h.v); await frame();
      const e = h.el(); if (!visible(e)) return no(h.id === 'users_button' || h.id === 'projects_button' || h.id === 'notifications_button' ? 'Bu düymə yalnız admin üçündür' : 'Bu element indi görünmür');
      await reveal(e, h.n[0]); return say('İşıqlandırdım: ' + h.n[0]);
    }
    if (st && /(sutun|column|status|todo|doing|review|done|gozleyir|isleyir|yoxlama|bitdi)/.test(nq)) return run('board_status', st);
    const pl = findPlanet(q);
    if (pl && view === 'panel') { const c = $u('projects').querySelector(`[data-k="${CSS.escape(pl.name)}"]`); if (visible(c)) { await reveal(c, pl.name); return say('İşıqlandırdım: ' + pl.name); } }
    if (pl) return pointAt(q, view !== 'space');
    const t = data && await U.findTask(q);
    if (t) return openTask(t.title);
    return no('Göstəriləcək element tapılmadı: ' + q, { close: rank(HL, q, h => [h.id, ...h.n]).slice(0, 4).map(x => x.it.id), targets: HL.map(h => h.id) });
  }

  /* ---------- Kinematik tur ---------- */
  let tour = null;
  function stopTour(why) {
    if (!tour) return false;
    const t = tour; tour = null; t.abort = why || 'stop'; tbar.hidden = true; hideRet();
    ctxSeen = ''; return true;
  }
  async function startTour(scope) {
    if (!data) return no('Məlumat hələ yüklənməyib');
    stopTour('restart');
    const all = U.planetNames(), q = U.norm(scope || 'all');
    let uni = null, list;
    if (!q || /^(all|hamisi|butun|her yer|everything|kainat)$/.test(q)) list = ['main', 'studio', 'agent'].flatMap(u => all.filter(p => p.uni === u));
    else { uni = findUni(scope); if (!uni) return no('Belə univers yoxdur: ' + scope, { options: UNIVERSES.map(x => x.name) }); list = all.filter(p => p.uni === uni.id); }
    const ps = buildProjects(), act = n => { const p = ps.find(x => x.name === n); return p ? RANK[p.st] * 1e12 + (p.last || 0) : 0; };
    const byAct = l => l.sort((a, b) => act(b.name) - act(a.name));
    list = (uni ? byAct(list) : ['main', 'studio', 'agent'].flatMap(u => byAct(list.filter(p => p.uni === u)).slice(0, u === 'main' ? 8 : 4))).slice(0, 12);
    if (!list.length) return no('Turda göstəriləcək planet yoxdur');
    const per = 3400;
    const stops = list.map((p, i) => { const f = planetFacts(p.name); delete f.name; return { n: i + 1, ...f }; });
    const T = tour = { stops, i: 0, abort: null, t0: Date.now(), hash: '' };
    (async () => {
      let prevU = null;
      for (let i = 0; i < list.length; i++) {
        if (T.abort) return;
        T.i = i; const p = list[i];
        tbar.hidden = false; $u('jui-tour-t').textContent = `◉ TUR ${i + 1}/${list.length} · ${stops[i].label}`;
        T.hash = '#kainat/' + encodeURIComponent(p.name); go(p.name);
        setTimeout(() => { if (tour === T && T.i === i) showRet(stops[i].label, spaceCentre, per - 2000); }, REDUCE ? 100 : 1800);
        await sleep(per + (prevU && prevU !== p.uni ? 900 : 0)); prevU = p.uni;
      }
      if (T.abort) return;
      tour = null; tbar.hidden = true;
      nav(uni ? '#kainat/@' + uni.id : '#kainat'); ctxSeen = '';
    })();
    return say(`Tur başladı: ${stops.length} dayanacaq`, { seconds_per_stop: per / 1000, stops,
      note: 'Narrate the stops in this order, one short sentence each, at the pace of the flight. Do not read numbers you do not need. The tour stops when the user speaks.' });
  }
  addEventListener('hashchange', () => { if (tour && location.hash !== tour.hash) stopTour('nav'); });
  addEventListener('keydown', e => { if (e.key === 'Escape' && tour) stopTour('esc'); });
  document.addEventListener('pointerdown', e => { if (tour && e.target.closest && e.target.closest('#space') && !e.target.closest('.jui-tour')) stopTour('user'); }, true);
  JV.on('user', () => { if (tour && Date.now() - tour.t0 > 2500) stopTour('speech'); });

  /* ---------- Alətlər ---------- */
  const safe = fn => async a => { try { return await fn(a || {}); } catch (e) { return no(String(e && e.message || e)); } };
  JV.registerTool('ui', safe(a => run(a.action, a.target, a.value)));
  JV.registerTool('list_ui_actions', () => ({ ok: true, actions: catalogue(), highlight_targets: HL.map(h => h.id), note: 'ui {action, target?, value?}. Places (planets/universes) also work as action.' }));
  JV.registerTool('get_context', () => { const c = context(); return { ...c, screen: screenLine(c) }; });
  JV.registerTool('point_at', safe(a => pointAt(a.target, a.fly)));
  JV.registerTool('highlight', safe(a => highlight(a.target)));
  JV.registerTool('tour', safe(a => startTour(a.scope)));
  JV.registerTool('stop_tour', () => stopTour('tool') ? say('Tur dayandı') : { ok: true, result: 'Tur getmirdi' });

  window.JarvisUI = {
    run, match: q => { const m = match(q); return m ? m.id : null; }, actions: catalogue, context, screen: () => screenLine(context()),
    pointAt, highlight, tour: startTour, stopTour, place,
  };
})();
