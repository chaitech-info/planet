/* Jarvis modulu: missiyalar (asılılıqlı addımlar), protokollar (adlı makrolar) və paralel Claude Code göndərişi.
   Server: mission.php (vəziyyət maşını mission_lib.php-dədir). Alət deklarasiyaları: jarvis_modules/missions.php.
   İşləyən missiya varsa ~3 saniyədən bir "tick" çağırır (addımları irəlilədir), kartları (JarvisHUD varsa) canlı yeniləyir,
   missiya bitəndə Jarvis bir dəfə səsli xülasə verir. */
(() => {
  if (!window.Jarvis) return;
  const { line, best, need, confirmed, planetNames } = Jarvis.util;

  async function call(action, extra) {
    const r = await fetch('mission.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    return { status: r.status, d: await r.json().catch(() => ({})) };
  }
  async function R(action, extra) { const { status, d } = await call(action, extra); if (status !== 200) throw new Error(d.error || 'xəta ' + status); return d; }
  /** Danışıqdakı proyekt adı → panel adı ("*" və "{project}" olduğu kimi qalır) */
  const proj = p => { p = String(p || '').trim(); if (!p || p === '*' || p.includes('{project}')) return p; const pl = best(planetNames(), p, x => [x.name, x.label]); return pl ? pl.name : p; };
  const mid = a => (a && a.mission_id != null && a.mission_id !== '') ? parseInt(a.mission_id, 10) || 0 : 0;
  const refresh = () => { try { if (typeof load === 'function') load(); } catch (e) {} };   // index.php-dəki feed yeniləməsi

  /* ---------- Sessiya vəziyyəti: Jarvis.say yalnız canlı sessiyada işləyir ---------- */
  let live = false; const later = [];
  Jarvis.on('ready', () => { live = true; while (later.length) { const x = later.shift(); if (Date.now() - x.t < 30 * 60e3) Jarvis.say(x.text); } });
  Jarvis.on('stop', () => { live = false; });
  Jarvis.on('state', s => { if (s === 'off' || s === 'error') live = false; });
  const isLive = () => typeof Jarvis.ready === 'function' ? !!Jarvis.ready() : live;
  const say = text => { if (isLive()) Jarvis.say(text); else { later.push({ text, t: Date.now() }); if (later.length > 5) later.shift(); } };

  /* ---------- Kartlar (JarvisHUD) ---------- */
  const IC = { wait: '·', queued: '⏳', running: '⚙', done: '✓', failed: '✗', canceled: '⊘', skipped: '↷' };
  const ST = { wait: 'gözləyir', queued: 'növbədə', running: 'işləyir', done: 'bitdi', failed: 'alınmadı', canceled: 'ləğv', skipped: 'keçildi' };
  const MS = { draft: 'qaralama', running: 'icrada', done: 'tamamlandı', failed: 'alınmadı', canceled: 'ləğv edildi' };
  const ACC = { draft: 'violet', running: 'cyan', done: 'green', failed: 'red', canceled: 'amber' };
  const HUD = () => window.JarvisHUD && typeof window.JarvisHUD.show === 'function' ? window.JarvisHUD : null;
  const cards = new Map();                       // missiya id → HUD kart id
  const closed = new Map();                      // istifadəçinin bağladığı kartlar: missiya id → o anki status (status dəyişənə qədər yenidən açma)
  const hudShow = (h, sp, onClose) => { const r = h.show(sp, { onClose }); return r && typeof r === 'object' ? r.id : r; };
  const seen = new Map();                        // missiya id → son görülən imza (status + addım statusları)
  const lastSt = new Map();
  // Kart yalnız missiya səviyyəsidir: addım → status/faiz; komandanın canlı detalları HUD-un agent kartlarındadır (mission/step etiketi ilə)
  function spec(m) {
    const blocks = [];
    if (m.goal) blocks.push({ type: 'text', text: m.goal });
    const lbl = s => { const dep = s.after.length ? ` ←${s.after.map(x => x + 1).join(',')}` : ''; const head = `${IC[s.status] || ''} ${s.index + 1}.`; const tail = ` · ${s.project}${dep}`;
      return head + ' ' + s.title.slice(0, Math.max(8, 58 - head.length - tail.length)) + tail; };
    const rows = m.steps.map(s => ({ label: lbl(s), pct: s.pct, status: s.status }));
    if (rows.length <= 10) blocks.push({ type: 'progress', items: rows });                       // HUD: progress ≤ 10 sətir
    else blocks.push({ type: 'list', items: rows.map((r, i) => ({ text: r.label, sub: ST[m.steps[i].status], status: r.status })) });
    const bad = m.steps.filter(s => s.error);
    if (bad.length) blocks.push({ type: 'list', items: bad.slice(0, 6).map(s => ({ text: `${s.index + 1}. ${s.title}: ${ST[s.status]}`, sub: s.error, status: s.status })) });
    const c = m.counts || {};
    blocks.push({ type: 'kv', items: [{ key: 'Status', value: MS[m.status] || m.status }, { key: 'Gedişat', value: `${c.done || 0}/${m.steps.length} · ${m.pct}%` }] });
    if (m.status === 'draft') blocks.push({ type: 'buttons', items: [{ label: '▶ Başla', say: `Missiya ${m.id}-i başlat, təsdiq edirəm.` }, { label: 'Ləğv et', say: `Missiya ${m.id}-i ləğv et, təsdiq edirəm.` }] });
    else if (m.status === 'running') blocks.push({ type: 'buttons', items: [{ label: 'Ləğv et', say: `Missiya ${m.id}-i ləğv et, təsdiq edirəm.` }] });
    return { title: `🎯 Missiya #${m.id}: ${m.title}`, icon: '🎯', accent: ACC[m.status] || 'cyan', blocks };
  }
  function show(m) {
    const sig = m.status + '|' + m.steps.map(s => s.status + ':' + s.pct).join(',');
    const prev = seen.get(m.id); lastSt.set(m.id, m.status); if (prev === sig) return; seen.set(m.id, sig);
    const h = HUD();
    if (h) {
      if (cards.has(m.id)) { try { h.update(cards.get(m.id), spec(m)); return; } catch (e) { cards.delete(m.id); } }   // panel bağlanıbsa update atır
      if (closed.has(m.id) && closed.get(m.id) === m.status) return;
      closed.delete(m.id);
      try { const id = m.id; cards.set(id, hudShow(h, spec(m), () => { cards.delete(id); closed.set(id, (lastSt.get(id) || '')); })); return; } catch (e) { console.error('JarvisHUD', e); }
    }
    // HUD yoxdursa: yalnız status dəyişikliklərini sətir kimi yaz
    const pst = prev ? prev.split('|') : null, ps = pst ? pst[1].split(',').map(x => x.split(':')[0]) : [];
    if (!pst || pst[0] !== m.status) line(m.status === 'failed' ? 'e' : 'a', `🎯 Missiya #${m.id} «${m.title}»: ${MS[m.status] || m.status} (${(m.counts || {}).done || 0}/${m.steps.length})`);
    if (pst) m.steps.forEach((s, i) => { if (ps[i] && ps[i] !== s.status && s.status !== 'queued') line(s.status === 'failed' ? 'e' : 'i', `  ${IC[s.status]} ${i + 1}. ${s.title} · ${s.project}: ${ST[s.status]}`); });
  }
  /** Model üçün yığcam görünüş */
  const brief = (m, full) => ({ mission_id: m.id, title: m.title, status: m.status, progress_pct: m.pct,
    steps: m.steps.map(s => ({ index: s.index, title: s.title, project: s.project, status: s.status, after: s.after.length ? s.after : undefined,
      error: s.error, doing: s.status === 'running' ? s.doing : undefined, result: full ? s.result : undefined })) });

  /* ---------- Tick: işləyən missiyaları irəlilət, kartları yenilə, bitəndə xülasə ---------- */
  const SAID = 'jv-missions-said';
  const said = new Set((() => { try { return JSON.parse(localStorage.getItem(SAID) || '[]'); } catch (e) { return []; } })());
  const remember = id => { said.add(id); try { localStorage.setItem(SAID, JSON.stringify([...said].slice(-50))); } catch (e) {} };
  const running = new Set(); let hot = 0, lastTick = 0, busy = false, off = false, first = true;
  function summary(m) {
    let txt = `[System note, not spoken by the user] Mission #${m.id} "${m.title}" finished with status "${m.status}" (${(m.counts || {}).done || 0}/${m.steps.length} steps done).`;
    for (const s of m.steps) {
      const add = `\n${s.index + 1}. ${s.title} (${s.project}): ${s.status}` + (s.error ? ` — ${s.error}` : '') + (s.result ? `\n   Result: ${s.result.slice(-Math.max(150, Math.floor(2400 / m.steps.length)))}` : '');
      if (txt.length + add.length > 3200) { txt += '\n…'; break; }
      txt += add;
    }
    return txt + '\nSummarise the mission outcome for the user in 2-3 short spoken sentences; mention failed or skipped steps plainly.';
  }
  async function tick() {
    if (busy || off) return; busy = true; lastTick = Date.now();
    try {
      const { status, d } = await call('tick');
      if (status === 401 || status === 403) { off = true; return; }
      if (status !== 200) return;
      let changed = false;
      for (const m of d.missions || []) {
        const was = running.has(m.id);
        if (m.status === 'running') running.add(m.id); else running.delete(m.id);
        if (first && m.status !== 'running') { if (!(d.finished_now || []).includes(m.id)) remember(m.id); continue; }   // köhnə bitmişləri danışma
        show(m);
        if (m.status !== 'running' && (was || (d.finished_now || []).includes(m.id)) && !said.has(m.id)) {
          remember(m.id); changed = true;
          if (m.status === 'canceled') line('i', `🎯 Missiya #${m.id} ləğv edildi`);
          else say(summary(m));
        }
      }
      first = false;
      if (changed || (d.finished_now || []).length) refresh();
    } catch (e) { /* şəbəkə: növbəti dəfə */ } finally { busy = false; }
  }
  // İşləyən missiya varsa və ya yenicə başladılıbsa: 3 s; əks halda 30 s-dən bir yoxla (başqa tabda başladıla bilər)
  setInterval(() => { if (document.hidden && !running.size) return; if (running.size || Date.now() < hot || Date.now() - lastTick > 30e3) tick(); }, 3000);
  setTimeout(tick, 1500);
  const heat = () => { hot = Date.now() + 60e3; };

  /* ---------- Paralel göndəriş kartı ---------- */
  const dispatchCards = new Map();               // komanda id → {card, items}
  function dispatchSpec(items) {
    return { title: '🤖 Paralel tapşırıqlar', icon: '🤖', accent: items.some(x => x.st === 'failed') ? 'amber' : 'cyan',
      blocks: [{ type: 'progress', items: items.slice(0, 10).map(x => ({ label: `${IC[x.st] || '·'} ${x.id ? '#' + x.id + ' ' : ''}${x.project}${x.title ? ' · ' + x.title : ''}`.slice(0, 60),
        pct: x.st === 'done' || x.st === 'failed' || x.st === 'canceled' ? 100 : x.st === 'running' ? (x.pct || 5) : 0, status: x.st })) }]
        .concat(items.some(x => x.error) ? [{ type: 'list', items: items.filter(x => x.error).map(x => ({ text: x.project, sub: x.error, status: 'failed' })) }] : []) };
  }
  function dispatchSync() {
    const h = HUD(); if (!h || !dispatchCards.size || typeof data === 'undefined' || !data || !data.commands) return;
    const groups = new Set(dispatchCards.values());
    for (const g of groups) {
      let ch = false;
      for (const x of g.items) {
        if (!x.id) continue;
        const c = data.commands.find(y => +y.id === x.id); if (!c) continue;
        let pct = 0; try { const pg = typeof c.progress === 'object' && c.progress ? c.progress : JSON.parse(c.progress || '{}'); pct = +(pg.pct || 0); } catch (e) {}
        const st = c.status === 'pending' ? 'queued' : c.status;
        if (st !== x.st || pct !== x.pct) { x.st = st; x.pct = pct; ch = true; }
      }
      if (ch && g.card) { try { h.update(g.card, dispatchSpec(g.items)); } catch (e) { g.card = null; } }   // bağlanıb: daha yeniləmə
      if (g.items.every(x => !x.id || ['done', 'failed', 'canceled'].includes(x.st))) for (const x of g.items) dispatchCards.delete(x.id);
    }
  }
  setInterval(dispatchSync, 2000);

  /* ---------- Alətlər ---------- */
  Jarvis.registerTool('dispatch_agents', async a => {
    need(a, 'tasks'); confirmed(a);
    const tasks = (Array.isArray(a.tasks) ? a.tasks : []).map(t => ({ project: proj(t.project), prompt: String(t.prompt || ''), title: t.title || '' }));
    if (!tasks.length) throw new Error('"tasks" boşdur');
    const { status, d } = await call('dispatch', { tasks });
    if (!d.items) throw new Error(d.error || 'xəta ' + status);
    const items = d.items.map(x => ({ ...x, st: x.ok ? 'queued' : 'failed' }));
    for (const x of items) if (x.ok) Jarvis.watch(x.id, { project: x.project, prompt: tasks[x.index].prompt });
    const h = HUD();
    if (h) { try { const g = { card: null, items }; g.card = hudShow(h, dispatchSpec(items), () => { g.card = null; }); for (const x of items) if (x.id) dispatchCards.set(x.id, g); } catch (e) { console.error('JarvisHUD', e); } }
    else for (const x of items) line(x.ok ? 'a' : 'e', x.ok ? `🤖 #${x.id} → ${x.project}` : `✗ ${x.project}: ${x.error}`);
    refresh();
    return { queued: d.queued, failed: d.failed, items: d.items, agent_online: d.agent_online, parallel_workers: d.parallel_workers,
      concurrency: d.concurrency, note: (d.agent_online ? '' : 'Agent is offline: tasks wait in the queue. ') + 'A spoken note arrives as each task finishes.' };
  });

  Jarvis.registerTool('plan_mission', async a => {
    need(a, 'title', 'steps');
    const steps = (Array.isArray(a.steps) ? a.steps : []).map(s => ({ title: s.title || '', project: proj(s.project), prompt: String(s.prompt || ''), after: Array.isArray(s.after) ? s.after : [] }));
    const d = await R('plan', { title: a.title, goal: a.goal || '', steps });
    show(d.mission);
    return { ...brief(d.mission), warnings: d.warnings.length ? d.warnings : undefined, concurrency: d.concurrency,
      note: 'Draft only, nothing runs yet. Briefly tell the user the plan (steps and order) and ask whether to start; on a clear yes call start_mission with confirmed=true.' };
  });

  Jarvis.registerTool('start_mission', async a => {
    confirmed(a);
    const d = await R('start', { id: mid(a) });
    heat(); show(d.mission); refresh(); tick();
    return { ...brief(d.mission), launched: d.launched.length, errors: d.errors.length ? d.errors : undefined, agent_online: d.agent_online, concurrency: d.concurrency,
      note: 'Steps run in dependency order; I will report when the mission finishes.' };
  });

  Jarvis.registerTool('mission_status', async a => { const d = await R('status', { id: mid(a) }); show(d.mission); return brief(d.mission, true); });

  Jarvis.registerTool('cancel_mission', async a => {
    confirmed(a);
    const d = await R('cancel', { id: mid(a) });
    show(d.mission); refresh();
    return { ok: true, mission_id: d.mission.id, status: d.mission.status, canceled_commands: d.canceled_commands };
  });

  Jarvis.registerTool('list_missions', async () => {
    const d = await R('list');
    return { missions: d.missions.map(m => ({ mission_id: m.id, title: m.title, status: m.status, progress_pct: m.pct, steps: m.steps.length, created: Jarvis.util.when(m.created_at) })) };
  });

  let protos = null;
  async function protocols() { protos = (await R('protocols')).protocols; return protos; }
  Jarvis.registerTool('list_protocols', async () => ({ protocols: (await protocols()).map(p => ({ name: p.name, description: p.descr, builtin: p.builtin, owner: p.owner, can_edit: p.can_edit,
    scope: p.steps.some(s => s.project === '*') ? 'all projects of the agent (parallel)' : p.steps.some(s => String(s.project).includes('{project}')) ? 'one project (needs project)' : 'fixed projects',
    steps: p.steps.map(s => s.title) })) }));

  Jarvis.registerTool('run_protocol', async a => {
    need(a, 'name'); confirmed(a);
    const list = await protocols();
    const p = best(list, a.name, x => [x.name, x.name.replace(/\s*(protocol|protokol[u]?)\s*$/i, '')]);
    if (!p) throw new Error('Protokol tapılmadı. Mövcudlar: ' + list.map(x => x.name).join(', '));
    const d = await R('run_protocol', { id: p.id, project: a.project ? proj(a.project) : '' });
    heat(); show(d.mission); refresh(); tick();
    return { protocol: d.protocol, ...brief(d.mission), launched: d.launched.length, errors: d.errors.length ? d.errors : undefined, agent_online: d.agent_online, concurrency: d.concurrency };
  });

  Jarvis.registerTool('save_protocol', async a => {
    need(a, 'name', 'steps'); confirmed(a);
    const steps = (Array.isArray(a.steps) ? a.steps : []).map(s => ({ title: s.title || '', project: proj(s.project), prompt: String(s.prompt || ''), after: Array.isArray(s.after) ? s.after : [] }));
    const d = await R('protocol_save', { name: a.name, descr: a.descr || '', steps });
    protos = null;
    line('a', `✓ Protokol ${d.updated ? 'yeniləndi' : 'yaradıldı'}: ${d.protocol.name}`);
    return { ok: true, updated: d.updated, protocol: d.protocol.name, steps: d.protocol.steps.length };
  });

  window.JarvisMissions = { tick, show, protocols };
})();
