/* Jarvis modulu: Claude Code icazə relesi + canlı sessiyalar. Server: approval.php. Alət deklarasiyaları: jarvis_modules/approvals.php.
   Yeni sorğu (RT approval.new, RT yoxdursa/qopubsa 3 san-lik list sorğusu) → HUD kartı (İcazə ver / Rədd et) + qısa səs siqnalı;
   Jarvis sessiyası açıqdırsa ona sistem qeydi gedir, o da istifadəçidən soruşur. approval.decided → kart yenilənir və bağlanır. */
(() => {
  if (!window.Jarvis) return;
  const { line, norm, userName, need } = Jarvis.util;

  async function call(action, extra) {
    const r = await fetch('approval.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    const d = await r.json().catch(() => ({}));
    if (r.status !== 200) throw new Error(d.error || 'xəta ' + r.status);
    return d;
  }
  const HUD = () => window.JarvisHUD && typeof window.JarvisHUD.show === 'function' ? window.JarvisHUD : null;
  const live = () => typeof Jarvis.ready === 'function' && Jarvis.ready();
  const RTok = () => window.RT && typeof window.RT.on === 'function';
  const ST = { allow: '✓ icazə verildi', deny: '✗ rədd edildi', timeout: '⌛ vaxt keçdi (terminalda soruşulur)' };

  /* ---------- Səs siqnalı (iki qısa ton) ---------- */
  let ac = null;
  function chime() {
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      if (ac.state === 'suspended') ac.resume().catch(() => {});
      const t = ac.currentTime;
      [[880, 0], [1320, .12]].forEach(([f, d]) => {
        const o = ac.createOscillator(), g = ac.createGain();
        o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(ac.destination);
        g.gain.setValueAtTime(0, t + d); g.gain.linearRampToValueAtTime(.12, t + d + .02); g.gain.exponentialRampToValueAtTime(.0001, t + d + .22);
        o.start(t + d); o.stop(t + d + .25);
      });
    } catch (e) {}
  }

  /* ---------- Vəziyyət və kartlar ---------- */
  const known = new Map();       // id → {id, user, project, tool, summary, status, card}
  const sess = new Map();        // RT session.update: user|session_id → son vəziyyət (data.sessions-dan təzə ola bilər)
  const cardSpec = a => {
    const done = a.status && a.status !== 'pending';
    const blocks = [{ type: 'text', text: a.summary || a.tool }, { type: 'kv', items: [{ key: 'Kim', value: a.user || '—' }, { key: 'Proyekt', value: a.project || '—' }, { key: 'Status', value: done ? ST[a.status] || a.status : 'gözləyir (~50 san)' }] }];
    if (!done) blocks.push({ type: 'buttons', items: [{ label: '✓ İcazə ver', say: 'allow', onClick: () => decide(a.id, 'allow').catch(e => line('e', e.message)) },
      { label: '✗ Rədd et', say: 'deny', onClick: () => decide(a.id, 'deny').catch(e => line('e', e.message)) }] });
    return { title: `🔐 İcazə #${a.id}: ${a.tool}`, icon: '🔐', accent: done ? (a.status === 'allow' ? 'green' : a.status === 'deny' ? 'red' : 'cyan') : 'amber', layout: 'compact', blocks };
  };
  function paint(a) {
    const h = HUD(); if (!h) return;
    if (a.card) { try { h.update(a.card, cardSpec(a)); return; } catch (e) { a.card = null; } }
    if (a.status && a.status !== 'pending') return;                 // bağlanmış sorğu üçün yeni kart açma
    try { a.card = h.show(cardSpec(a), { onClose: () => { a.card = null; } }); } catch (e) { console.error('JarvisHUD', e); }
  }
  function arrive(x) {
    const id = +x.id; if (!id || known.has(id)) return;
    const a = { id, user: x.user || '', project: x.project || '', tool: x.tool || '', summary: x.summary || x.tool || '', status: 'pending', card: null };
    known.set(id, a);
    chime(); paint(a);
    line('a', `🔐 İcazə #${id}: ${a.user ? a.user + ' · ' : ''}${a.project} — ${a.summary}`);
    if (live()) Jarvis.say(`[System note] Approval request #${id}: ${a.user ? a.user + "'s " : ''}Claude Code in project ${a.project} wants ${a.summary}. Ask the user in one short sentence whether to allow or deny (decide_approval).`);
  }
  function settle(id, status) {
    const a = known.get(+id); if (!a || a.status === status) return;
    a.status = status; paint(a);
    if (a.card) { const c = a.card; setTimeout(() => { try { const h = HUD(); if (h) h.close(c); } catch (e) {} }, 2500); }
    setTimeout(() => known.delete(a.id), 10 * 60e3);
  }
  async function decide(id, decision, reason) {
    const d = await call('decide', { id, decision, reason: reason || '' });
    settle(id, d.status);
    return d;
  }

  /* ---------- Real-time (RT) və ya sorğu ilə ehtiyat ---------- */
  let hooked = false;
  function hook() {
    if (hooked || !RTok()) return;
    hooked = true;
    RT.on('approval.new', e => arrive({ ...(e.data || {}), id: (e.data && e.data.id) || e.ref, user: (e.data && e.data.user) || e.user, project: (e.data && e.data.project) || e.project }));
    RT.on('approval.decided', e => settle((e.data && e.data.id) || e.ref, (e.data && e.data.status) || 'timeout'));
    RT.on('session.update', e => { const d = e.data || {}; if (d.session_id) sess.set((d.user || e.user) + '|' + d.session_id, { ...d, t: Date.now() / 1000 }); });
  }
  for (let i = 1; i <= 10; i++) setTimeout(hook, i * 1000);          // realtime.js sonra yüklənə bilər
  hook();
  async function poll() {
    try {
      const d = await call('list', { recent: 120 });
      const seen = new Set();
      for (const x of d.items || []) { seen.add(x.id); if (x.status === 'pending') arrive(x); else settle(x.id, x.status); }
      for (const a of known.values()) if (a.status === 'pending' && !seen.has(a.id)) settle(a.id, 'timeout');
    } catch (e) {}
  }
  let first = true;
  setInterval(() => {
    const rtUp = RTok() && (typeof RT.connected !== 'function' || RT.connected());
    const open = live() || (Jarvis.panel && !Jarvis.panel.hidden);
    if (document.hidden || (!first && rtUp)) return;
    if (open || first) { first = false; poll(); }
  }, 3000);
  Jarvis.on('ready', () => { if (RTok()) poll(); });                 // sessiya başlayanda gözləyənləri götür

  /* ---------- Alətlər ---------- */
  Jarvis.registerTool('list_approvals', async () => {
    const d = await call('list', {});
    const items = (d.items || []).filter(x => x.status === 'pending');
    items.forEach(arrive);
    return { count: items.length, approvals: items.map(x => ({ id: x.id, user: x.user, project: x.project, tool: x.tool, summary: x.summary, seconds_left: x.expires_in })) };
  });
  Jarvis.registerTool('decide_approval', async a => {
    need(a, 'decision');
    const dec = /^(allow|yes|ok|hə|he|bəli|beli|icazə)/i.test(String(a.decision)) ? 'allow' : /^(deny|no|yox|rədd|redd)/i.test(String(a.decision)) ? 'deny' : '';
    if (!dec) throw new Error('decision: allow və ya deny');
    let id = parseInt(a.id, 10) || 0;
    if (!id) {
      const p = ((await call('list', {})).items || []).filter(x => x.status === 'pending');
      if (!p.length) throw new Error('Gözləyən icazə sorğusu yoxdur');
      if (p.length > 1) return { ok: false, error: 'Bir neçə sorğu gözləyir, hansı?', pending: p.map(x => ({ id: x.id, user: x.user, project: x.project, summary: x.summary })) };
      id = p[0].id;
    }
    const d = await decide(id, dec, a.reason);
    line('a', `✓ İcazə #${id}: ${dec === 'allow' ? 'verildi' : 'rədd edildi'}`);
    return { ok: true, id, status: d.status };
  });

  Jarvis.registerTool('claude_sessions', async a => {
    a = a || {};
    const D = typeof data !== 'undefined' && data ? data : {};
    const T = typeof now === 'function' ? now() : Date.now() / 1000;
    const st = s => typeof eff === 'function' ? eff(s) : s.status;
    let pend = [];
    try { pend = ((await call('list', {})).items || []).filter(x => x.status === 'pending'); } catch (e) {}
    const lim = a.all ? 86400 : 900;
    let rows = (D.sessions || []).map(s => {
      const u = sess.get(s.user + '|' + s.session_id), m = u && u.t > s.updated_at ? { ...s, status: u.status || s.status, last_prompt: u.prompt || s.last_prompt, project: u.project || s.project, updated_at: u.t } : s;
      const p = pend.find(x => x.user === m.user && (x.session_id ? x.session_id === m.session_id : x.project === m.project));
      return { ...m, st: p ? 'permission' : st(m), perm: p ? p.summary : undefined };
    }).filter(s => s.st !== 'ended' && s.st !== 'offline' && T - s.updated_at < lim);
    if (a.user) { const u = userName(a.user) || a.user; rows = rows.filter(s => s.user === u); }
    if (a.project) { const q = norm(a.project); rows = rows.filter(s => norm(s.project).includes(q)); }
    const R = { permission: 4, waiting: 3, working: 2, idle: 1 };
    rows.sort((x, y) => (R[y.st] || 0) - (R[x.st] || 0) || y.updated_at - x.updated_at);
    const dur = s => { const m = Math.max(0, Math.round((T - (+s.started_at || T)) / 60)); return m < 60 ? m + ' dəq' : Math.floor(m / 60) + ' saat ' + (m % 60) + ' dəq'; };
    return { count: rows.length, sessions: rows.slice(0, 30).map(s => ({ user: s.user, project: s.project, status: s.st, permission_request: s.perm, prompt: (s.last_prompt || '').slice(0, 200), last: (s.last_summary || '').slice(0, 160), duration: dur(s), quiet_seconds: Math.round(T - s.updated_at) })) };
  });
})();
