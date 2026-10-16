/* Jarvis beyni: think (dərin düşünmə → Claude Code kind=think, Opus, plan rejimi), audit_log, "[Situation]" sətri (səssiz kontekst),
   təsdiq sorğusu gələndə claude qrupunun avtomatik yüklənməsi. Alət deklarasiyaları: jarvis_modules/brain.php (load_tools icrası jarvis.js-dədir).
   RT, HUD, UI, approval.php, JarvisProactive olmasa da işləyir. index.php qlobalları: data, ME, dueInfo, idleOf, buildProjects, DAY, curPlanet, xKind, load. */
(() => {
  const JV = window.Jarvis; if (!JV || window.JarvisBrain) return;
  const U = JV.util;
  const D = () => { try { return typeof data !== 'undefined' ? data : null; } catch (e) { return null; } };
  const me = () => { try { return ME.name; } catch (e) { return ''; } };
  const g = (f, d) => { try { return f(); } catch (e) { return d; } };

  /* ---------- think ---------- */
  JV.registerTool('think', async a => {
    U.need(a, 'question');
    let project = a.project ? U.projectOf(a.project) : g(() => (curPlanet && !xKind(curPlanet) ? curPlanet : ''), '');
    const ctx = [g(() => window.JarvisUI && JarvisUI.screen && JarvisUI.screen(), ''), last].filter(Boolean).join('\n').slice(0, 1200);
    const body = { question: String(a.question).slice(0, 2000), project, context: ctx, said_at: U.saidAt() };
    let { status, d } = await U.post('jarvis.php', { action: 'think', ...body });
    if (status === 409 && d.allowed && !a.project && project) ({ status, d } = await U.post('jarvis.php', { action: 'think', ...body, project: '' }));   // baxdığı planet kod qovluğu deyil
    if (status !== 200) throw new Error(d.error || 'xəta ' + status);
    JV.watch(+d.id, { project: d.project, prompt: String(a.question), think: true });
    U.line('i', `🧠 Düşünürəm… (#${d.id}, Opus · ${d.project})` + (d.agent_online ? '' : ' · agent oflayndır, növbədə'));
    return { ok: true, id: d.id, project: d.project, agent_online: !!d.agent_online,
      note: 'Deep thinking started (usually 20-120 s). Say only a short "Düşünürəm…" now and keep helping; the answer arrives as a [Think result] note.' };
  });

  /* ---------- audit_log ---------- */
  JV.registerTool('audit_log', async a => {
    const { status, d } = await U.post('audit.php', { action: 'list', limit: Math.max(1, Math.min(100, +(a && a.limit) || 20)), all: !!(a && a.all) });
    if (status !== 200) throw new Error(d.error || 'xəta ' + status);
    return { count: (d.items || []).length, items: (d.items || []).map(r => ({ when: U.when(+r.created_at), user: r.user, tool: r.tool, tier: r.tier, ok: !!+r.ok, args: String(r.args || '').slice(0, 200), result: String(r.result || '').slice(0, 160) })) };
  });

  /* ---------- Situation: ≤ 300 simvol, yalnız dəyişəndə, 60 s-dən bir və RT hadisələrində ---------- */
  let last = '', sent = '', appr = null, apprT = 0, debT = 0;
  async function approvals(force) {
    if (!force && Date.now() - apprT < 30000) return appr;
    apprT = Date.now();
    try { const { status, d } = await U.post('approval.php', { action: 'list' }); if (status === 200) appr = Array.isArray(d.pending) ? d.pending.length : +d.pending || 0; } catch (e) {}
    return appr;
  }
  function situation() {
    const dt = D(); if (!dt) return '';
    const mine = (dt.commands || []).filter(c => c.user === me());
    const run = mine.filter(c => c.status === 'running').length, q = mine.filter(c => c.status === 'queued' || c.status === 'pending').length;
    const fail = mine.filter(c => c.status === 'failed' && Date.now() / 1000 - +(c.finished_at || c.created_at) < 6 * 3600)[0];
    let od = 0, odMe = 0;
    for (const t of dt.tasks || []) { const x = g(() => dueInfo(t), null); if (x && x.left < 0) { od++; if (t.assignee === me()) odMe++; } }
    const burn = g(() => buildProjects().filter(p => idleOf(p.name) >= DAY).map(p => p.name), []);
    const ci = g(() => window.JarvisProactive && JarvisProactive.ciRed ? JarvisProactive.ciRed() : [], []);
    const parts = [];
    if (run || q) parts.push(`Claude Code: ${run} işləyir, ${q} növbədə`);
    if (appr) parts.push(`təsdiq gözləyir: ${appr}`);
    if (od) parts.push(`gecikmiş tapşırıq: ${od}` + (odMe ? ` (səndə ${odMe})` : ''));
    if (burn.length) parts.push('yanan planet: ' + burn.slice(0, 4).join(', ') + (burn.length > 4 ? ` +${burn.length - 4}` : ''));
    if (ci.length) parts.push('CI qırmızı: ' + ci.slice(0, 4).join(', '));
    if (fail) parts.push(`son xəta: #${fail.id} ${fail.project}`);
    return ('[Situation] ' + (parts.join(' · ') || 'hər şey sakit')).slice(0, 300);
  }
  async function push(force) {
    await approvals(force);
    last = situation();
    if (last && last !== sent && JV.ready()) { sent = last; JV.context(last); }
  }
  const soon = (force) => { clearTimeout(debT); debT = setTimeout(() => push(force), 1500); };   // index.php RT ilə load() edir, sonra hesabla
  setInterval(() => { if (!document.hidden) push(); }, 60000);
  JV.on('ready', () => { sent = ''; setTimeout(() => push(true), 1200); });
  ['command.done', 'command.queued', 'command.started', 'jarvis.notice', 'ops.result'].forEach(t => JV.rt(t, () => soon(false)));
  ['approval.new', 'approval.decided'].forEach(t => JV.rt(t, () => soon(true)));

  /* Təsdiq sorğusu gəldi: model "hə" deyə bilsin deyə claude qrupu yüklü olmalıdır (default-dadır; config dəyişibsə burada yüklənir) */
  JV.rt('approval.new', () => {
    if (!JV.ready()) return;
    const L = JV.loaded(), all = (JV.session() || {}).all_tools || {};
    for (const grp of Object.keys(all)) if (!L.includes(grp) && all[grp].some(t => t.name === 'decide_approval')) JV.loadGroup(grp);
  });

  window.JarvisBrain = { situation, push, last: () => last };
})();

/* Səsli sessiya zamanı real-time axın gizli tabda da açıq qalsın (RT 5 dəq sonra bağlayır) */
(() => {
  if (!window.Jarvis) return;
  Jarvis.on('ready', () => { if (window.RT) RT.keepAlive = true; });
  Jarvis.on('stop', () => { if (window.RT) RT.keepAlive = false; });
})();
