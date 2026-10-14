/* Jarvis donanması: Claude Code tapşırıqları kainatda dron kimi (Kainat.setFleet), riskli planetlərdə fırtına (Kainat.storm),
   Jarvis-in səsi ilə nəfəs alan kainat (Kainat.pulse / Kainat.shock). index.php-nin `data` qlobalını oxuyur, heç nə yazmır.
   Sınaq: konsolda JarvisFleet.demo() — saxta dronlar (bazaya toxunmur), JarvisFleet.demo(false) — dayandır. */
(() => {
  const K = () => { const k = window.Kainat; return k && k.mounted && k.setFleet ? k : null; };
  const D = () => (typeof data !== 'undefined' && data) || null;                       // index.php: `let data` (qlobal leksik)
  const NOW = () => (typeof now === 'function' ? now() : Date.now() / 1000);
  const hueOf = s => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };   // index.php-dəki hue() ilə eyni
  const hex = (h, s, l) => {                                     // HSL → #rrggbb (THREE.Color "hsl(h s% l%)" yazılışını tanımır)
    const a = s * Math.min(l, 1 - l), f = n => { const k = (n + h / 30) % 12, c = l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1)); return Math.round(c * 255).toString(16).padStart(2, '0'); };
    return '#' + f(0) + f(8) + f(4);
  };
  const colOf = u => hex(hueOf(u), .58, .55);
  const LIVE = new Set(['running', 'queued']), sent = new Map();   // id → bitmə vaxtı (bitəndən sonra 60 san. göndərilir ki, dron qayıdış animasiyasını oynasın)
  let demo = null;

  /* ---- donanma ---- */
  function fleetList() {
    const d = D(), t = NOW(), out = [];
    const cs = ((d && d.commands) || []).concat(demo ? demo.items() : []);
    for (const c of cs) {
      const id = String(c.id), live = LIVE.has(c.status);
      if (live) sent.set(id, 0);
      else if (sent.has(id)) { if (!sent.get(id)) sent.set(id, t); if (t - sent.get(id) > 60) { sent.delete(id); continue; } }
      else continue;
      const pg = c.progress && typeof c.progress === 'object' ? c.progress : null;
      out.push({ id, user: c.user, project: c.project, status: c.status, pct: pg && pg.pct != null ? +pg.pct : null, step: pg && pg.step ? String(pg.step) : '',
        files: pg && Array.isArray(pg.files) ? pg.files.slice(-6) : [], color: colOf(c.user || '') });
    }
    if (sent.size > 400) for (const k of [...sent.keys()].slice(0, 200)) sent.delete(k);
    const rk = { running: 0, queued: 1 };                         // limit dolanda işləyənlər üstün
    return out.sort((a, b) => (rk[a.status] ?? 2) - (rk[b.status] ?? 2) || +a.id - +b.id);
  }

  /* ---- fırtına: gecikən tapşırıq və ya keçmiş gəmi deadline-ı ---- */
  let stormOn = new Set();
  function riskSet() {
    const d = D(), t = NOW(), r = new Set();
    if (!d) return r;
    for (const x of d.tasks || []) if (+x.due_at > 0 && +x.due_at < t && x.status !== 'done' && x.project) r.add(x.project);
    for (const s of d.ships || []) {
      if (!(+s.deadline > 0 && +s.deadline < t)) continue;
      const ss = (d.sessions || []).filter(x => x.user === s.user && x.project).sort((a, b) => b.updated_at - a.updated_at);   // gəminin sahibi hazırda hansı proyektdədir
      if (ss.length) r.add(ss[0].project);
    }
    return r;
  }
  function syncStorms(k) {
    if (!k.storm) return;
    const r = riskSet();
    for (const n of r) if (!stormOn.has(n)) k.storm(n, true);
    for (const n of stormOn) if (!r.has(n)) k.storm(n, false);
    stormOn = r;
  }

  function tick() {
    const k = K(); if (!k || document.hidden) return;
    try { k.setFleet(fleetList()); syncStorms(k); } catch (e) { console.warn('jarvis-fleet', e); }
  }
  setInterval(tick, 1500);
  addEventListener('kainat-ready', () => setTimeout(tick, 300));
  setTimeout(tick, 800);

  /* ---- səslə nəfəs alan kainat ---- */
  let jst = 'off', lv = 0, lastShock = 0, hooked = false;
  const J = () => window.Jarvis || null;
  function hook() {
    const j = J(); if (hooked || !j || typeof j.on !== 'function') return;
    hooked = true;
    j.on('state', s => {
      const prev = jst; jst = s;
      const k = K(), t = performance.now();
      if (k && k.shock && s === 'speaking' && prev !== 'speaking' && prev !== 'tool' && t - lastShock > 2500) { lastShock = t; k.shock(0xffc35a); }
    });
    j.on('stop', () => { jst = 'off'; });
  }
  function stateNow() { const j = J(); return j && typeof j.state === 'function' ? j.state() : jst; }
  function level() { const j = J(); try { return j && typeof j.level === 'function' ? +j.level() || 0 : 0; } catch (e) { return 0; } }
  function loop() {
    requestAnimationFrame(loop);
    hook();
    const k = K(); if (!k || !k.pulse || document.hidden) return;
    const st = stateNow();
    if (!st || st === 'off' || st === 'error') { lv = 0; return; }   // çağırılmayanda Kainat özü sakitləşir
    const x = Math.max(0, Math.min(1, level()));
    lv += (x - lv) * (x > lv ? .35 : .08);
    k.pulse(lv);
  }
  requestAnimationFrame(loop);

  /* ---- demo: bazaya toxunmadan vizual sınaq ---- */
  function mkDemo() {
    const d = D() || {}, users = (d.users || []).slice(0, 3), t0 = NOW();
    const projs = [...new Set((d.sessions || []).map(x => x.project).concat((d.tasks || []).map(x => x.project)).filter(Boolean))];
    if (!users.length || !projs.length) return null;
    const F = ['src/app.js', 'lib/db.php', 'README.md', 'api/routes.ts', 'ui/hud.css', 'tests/core.test.js', 'kainat.js', 'index.php'];
    const plan = [[0, 0, 2, 22, 'done'], [1, 1, 4, 30, 'failed'], [2, 0, 6, 40, 'done'], [0, 2, 9, 26, 'canceled']];   // [user, proyekt, başla, bit, nəticə]
    const cmds = plan.map(([u, p, a, b, end], i) => ({ id: 9e8 + i, user: users[u % users.length], project: projs[p % projs.length], a, b, end }));
    return { items() {
      const s = NOW() - t0;
      return cmds.filter(c => s < c.b + 70).map(c => {
        const st = s < c.a ? 'queued' : s < c.b ? 'running' : c.end, n = Math.max(0, Math.floor((s - c.a) / 3));
        return { id: c.id, user: c.user, project: c.project, status: st, progress: st === 'running' ? { step: 'Edit ' + F[n % F.length], pct: Math.min(95, Math.round((s - c.a) / (c.b - c.a) * 100)), files: F.slice(0, Math.min(6, n + 1)).map((f, j) => F[(j + n) % F.length]) } : null };
      });
    } };
  }
  window.JarvisFleet = {
    list: fleetList,
    demo(on = true) { demo = on ? mkDemo() : null; tick(); return demo ? 'demo: 4 dron (≈2 dəq)' : (on ? 'demo üçün data yoxdur' : 'demo dayandı'); },
    storm(name, on = true) { const k = K(); if (k && k.storm) k.storm(name, on); },
    pulse(x) { const k = K(); if (k && k.pulse) { k.pulse(x); if (k.shock) k.shock(); } },
  };
})();
