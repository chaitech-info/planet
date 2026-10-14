/* Jarvis HUD: holoqrafik panellər (JarvisHUD API + show_panel/update_panel/close_panels alətləri), canlı Claude Code agent kartları,
   açılış (boot) animasiyası, səsə görə parlayan HUD (--jv-level) və günlük limit sayğacı. DOM yalnız textContent ilə qurulur:
   modeldən gələn mətn heç vaxt innerHTML-ə düşmür. index.php qlobalları: data, ME, now, load, CTL, buildProjects, burnOf, dueInfo, agentInfo. */
(() => {
  const JV = window.Jarvis; if (!JV || window.JarvisHUD) return;
  const U = JV.util || {};

  /*<validate> Spesifikasiya yoxlaması: hər şey kəsilir/məhdudlaşdırılır (uzunluq, say, rəqəm); naməlum blok atlanır */
  const ACC = ['cyan', 'amber', 'red', 'green', 'violet'];
  const S = (v, n) => v == null ? '' : String(typeof v === 'object' ? JSON.stringify(v) : v).slice(0, n);
  const N = v => { const x = typeof v === 'number' ? v : parseFloat(String(v == null ? '' : v).replace(',', '.')); return Number.isFinite(x) ? x : null; };
  const A = (v, n) => Array.isArray(v) ? v.slice(0, n) : [];
  const clamp = (x, a, b) => Math.min(b, Math.max(a, x));
  const ST = v => S(v, 16).toLowerCase().replace(/[^a-z_]/g, '');
  const lines = (t, n) => t.split('\n').slice(0, n).join('\n');
  const parse = v => { if (typeof v !== 'string') return v; try { return JSON.parse(v); } catch (e) { return v; } };   // bəzi modellər massivi JSON sətri kimi göndərir
  function block(b) {
    b = parse(b); if (!b || typeof b !== 'object') return null;
    const o = { type: S(b.type, 16).toLowerCase(), title: S(b.title, 60) };
    const its = n => A(parse(b.items), n);
    switch (o.type) {
      case 'text': o.text = S(b.text, 1500).trim(); return o.text ? o : null;
      case 'stat': o.items = its(8).filter(x => x && typeof x === 'object').map(x => ({ label: S(x.label, 40), value: S(x.value, 24), delta: S(x.delta, 16) })); break;
      case 'list': o.items = its(20).map(x => typeof x === 'object' && x ? { text: S(x.text || x.label, 200), sub: S(x.sub, 160), status: ST(x.status) } : { text: S(x, 200), sub: '', status: '' }).filter(x => x.text); break;
      case 'table': {
        o.columns = A(parse(b.columns), 8).map(c => S(c, 40));
        const w = o.columns.length || 8;
        o.rows = A(parse(b.rows), 30).map(r => Array.isArray(r) ? r.slice(0, w).map(c => S(c, 120)) : r && typeof r === 'object' ? o.columns.map(c => S(r[c], 120)) : [S(r, 120)]);
        return o.rows.length || o.columns.length ? o : null;
      }
      case 'progress': o.items = its(10).filter(x => x && typeof x === 'object').map(x => ({ label: S(x.label, 60), pct: clamp(N(x.pct) || 0, 0, 100), status: ST(x.status) })); break;
      case 'bar_chart': o.items = its(16).filter(x => x && typeof x === 'object').map(x => ({ label: S(x.label, 30), value: N(x.value) || 0 })); break;
      case 'sparkline': o.values = A(parse(b.values), 120).map(N).filter(x => x !== null); return o.values.length >= 2 ? o : null;
      case 'code': o.lang = S(b.lang, 20); o.text = lines(S(b.text, 6000), 200); return o.text.trim() ? o : null;
      case 'diff': o.text = lines(S(b.text, 6000), 300); return o.text.trim() ? o : null;
      case 'kv': {
        let it = parse(b.items); if (it && typeof it === 'object' && !Array.isArray(it)) it = Object.entries(it).map(([key, value]) => ({ key, value }));
        o.items = A(it, 16).filter(x => x && typeof x === 'object').map(x => ({ key: S(x.key, 40), value: S(x.value, 200) })); break;
      }
      case 'buttons': o.items = its(4).filter(x => x && typeof x === 'object' && (x.label || x.say)).map(x => ({ label: S(x.label || x.say, 30), say: S(x.say || x.label, 300), onClick: typeof x.onClick === 'function' ? x.onClick : null })); break;
      case 'timeline': o.items = its(20).map(x => x && typeof x === 'object' ? { time: S(x.time || x.date, 24), text: S(x.text || x.label, 200), status: ST(x.status) } : { time: '', text: S(x, 200), status: '' }).filter(x => x.text); break;
      case 'checklist': o.items = its(20).map(x => x && typeof x === 'object' ? { text: S(x.text || x.label, 200), done: yes(x.done) } : { text: S(x, 200), done: false }).filter(x => x.text); break;
      case 'gauge': { const v = N(b.value != null ? b.value : b.pct); if (v === null) return null; o.value = clamp(v, 0, 100); o.label = S(b.label, 40); o.sub = S(b.sub, 80); return o; }
      case 'people': o.items = its(12).map(x => x && typeof x === 'object' ? { name: S(x.name || x.label, 40).trim(), status: S(x.status, 24).trim(), text: S(x.text || x.sub, 120) } : { name: S(x, 40).trim(), status: '', text: '' }).filter(x => x.name); break;
      case 'image': o.src = src(b.src); o.alt = S(b.alt || b.title, 120); return o.src ? o : null;
      case 'link': o.items = its(6).filter(x => x && typeof x === 'object').map(x => ({ label: S(x.label || x.href, 40), href: S(x.href, 160).trim() })).filter(x => x.label && /^#[\w\-\/.~%:=&?+]{0,150}$/.test(x.href)); break;
      default: return null;
    }
    return o.items.length ? o : null;
  }
  const yes = v => v === true || v === 1 || /^(1|true|yes|y|done|ok|bəli|hə|\+|✓|x)$/i.test(String(v == null ? '' : v).trim());
  /** Şəkil: yalnız eyni mənbə (nisbi/eyni origin http(s)) və ya data:image base64; qalan hər şey (xarici, javascript:, //) rədd */
  function src(v) {
    const s = S(v, 400000).trim(); if (!s) return '';
    if (/^data:/i.test(s)) return /^data:image\/(png|jpe?g|gif|webp|avif|svg\+xml);base64,[a-z0-9+/=\s]+$/i.test(s) ? s : '';
    try { const u = new URL(s, location.href); return u.origin === location.origin && /^https?:$/.test(u.protocol) ? u.href : ''; } catch (e) { return ''; }
  }
  const LAY = ['normal', 'wide', 'compact'];
  function clean(spec) {
    spec = parse(spec); if (!spec || typeof spec !== 'object') throw new Error('panel spesifikasiyası obyekt olmalıdır');
    const out = { title: S(spec.title, 80).trim() || 'Panel', icon: Array.from(S(spec.icon, 16).trim()).slice(0, 2).join(''), accent: ACC.includes(spec.accent) ? spec.accent : 'cyan', layout: LAY.includes(spec.layout) ? spec.layout : 'normal', blocks: [] }, skipped = [];
    for (const b of A(parse(spec.blocks), 12)) { const c = block(b); if (c) out.blocks.push(c); else skipped.push(S(b && b.type, 16) || '?'); }
    return { spec: out, skipped };
  }
  /*</validate>*/

  /* ---------- Stil ---------- */
  const css = `
.jvh { position: fixed; inset: 0; z-index: 55; pointer-events: none; font-size: 13px; color: #e6f6ff; --jv-a: 56,189,248; transition: opacity .25s ease, filter .25s ease; }
.jvh.dim { opacity: .14; filter: saturate(.5); } .jvh.dim * { pointer-events: none !important; }
.jvh-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
.jvh-p.hp { position: absolute; left: 0; top: 0; width: 380px; max-height: var(--jvh-mh, 80vh); display: flex; flex-direction: column; transform-origin: 0 0; will-change: transform; box-sizing: border-box;
  transition: transform .3s cubic-bezier(.2,.85,.25,1), opacity .24s ease, translate .18s ease, border-color .18s ease; }
.jvh-p.hp[hidden] { display: none; } .jvh-p.hp.nt, .jvh-p.hp.drag { transition: none; } .jvh-p.hp.gone { pointer-events: none; }
.jvh-p.hp:hover:not(.drag):not(.gone) { translate: 0 -2px; border-color: rgba(var(--a),.62); }
.jvh-p.hp.drag { translate: 0 -3px; box-shadow: 0 22px 50px rgba(0,0,0,.55), 0 0 26px rgba(var(--a),.35); }
.jvh-p.hp .jvh-h { flex: none; } .jvh-p.hp .jvh-b { flex: 0 1 auto; min-height: 0; max-height: var(--bh, min(56vh, 480px)); }
.jvh-p.hp.in .jvh-h, .jvh-p.hp.in .jvh-b { animation: jvh-mat .26s .06s ease-out both; }
@keyframes jvh-mat { from { opacity: .2; clip-path: inset(0 0 100% 0); } to { opacity: 1; clip-path: inset(0 0 0 0); } }
.jvh-pn { opacity: .5; } .jvh-p.pin .jvh-pn { opacity: 1; background: rgba(var(--a),.32); }
.jvh-h:focus-visible { outline: 2px solid rgba(var(--a),.95); outline-offset: -2px; } .jvh button:focus-visible, .jvh a:focus-visible { outline: 2px solid #7dd3fc; outline-offset: 2px; }
.jvh-dk { position: absolute; left: 18px; bottom: 18px; z-index: 100000; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; max-width: min(620px, calc(100vw - 36px)); pointer-events: auto; animation: jvh-bin .25s ease both; }
.jvh-dk[hidden] { display: none; } .jvh-dk > div { display: contents; }
.jvh-chip, .jvh-dk .all { --a: 56,189,248; display: inline-flex; align-items: center; gap: 6px; max-width: 210px; border: 1px solid rgba(var(--a),.45); background: rgba(6,10,20,.8); color: #e6f6ff; border-radius: 999px; padding: 4px 11px; font: inherit; font-size: 11.5px; cursor: pointer;
  -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); box-shadow: 0 0 10px rgba(var(--a),.2); transition: background-color .15s ease, transform .15s ease; }
.jvh-chip span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .jvh-chip:hover, .jvh-dk .all:hover { background: rgba(var(--a),.22); transform: translateY(-1px); }
.jvh-chip.ping { animation: jvh-ping .5s ease; } .jvh-dk .all { --a: 248,113,113; color: #fecaca; } .jvh-dk .all[hidden] { display: none; }
.jvh-ag { position: absolute; right: 18px; top: var(--jvh-top, 76px); width: min(290px, calc(100vw - 36px)); display: flex; flex-direction: column; gap: 8px; overflow: auto; scrollbar-width: none; max-height: calc(100vh - var(--jvh-top, 76px) - 20px); }
.jvh-ag[hidden] { display: none; }
.jvh-p { --a: var(--jv-a); position: relative; pointer-events: auto; border-radius: 6px 14px 6px 14px; color: #e6f6ff;
  background: linear-gradient(180deg, rgba(var(--a),.13), rgba(6,12,24,.82) 38%), repeating-linear-gradient(0deg, rgba(var(--a),.045) 0 1px, transparent 1px 3px), rgba(6,10,20,.72);
  -webkit-backdrop-filter: blur(12px) saturate(1.25); backdrop-filter: blur(12px) saturate(1.25); border: 1px solid rgba(var(--a),.38);
  box-shadow: 0 10px 34px rgba(0,0,0,.45), 0 0 calc(10px + var(--jv-level, 0) * 26px) rgba(var(--a), calc(.14 + var(--jv-level, 0) * .4)), inset 0 0 22px rgba(var(--a),.07); }
.jvh-p::before, .jvh-p::after { content: ""; position: absolute; width: 14px; height: 14px; pointer-events: none; border: 2px solid rgba(var(--a),.9); filter: drop-shadow(0 0 4px rgba(var(--a),.8)); }
.jvh-p::before { left: -3px; top: -3px; border-right: 0; border-bottom: 0; } .jvh-p::after { right: -3px; bottom: -3px; border-left: 0; border-top: 0; }
.jvh-p.cyan { --a: 56,189,248; } .jvh-p.amber { --a: 251,191,36; } .jvh-p.red { --a: 248,113,113; } .jvh-p.green { --a: 74,222,128; } .jvh-p.violet { --a: 192,132,252; }
.jvh-c.in { animation: jvh-in .42s cubic-bezier(.2,.9,.25,1) both; } .jvh-p.ping { animation: jvh-ping .5s ease; }
@keyframes jvh-in { from { opacity: 0; transform: translateX(-14px) scale(.97); clip-path: inset(0 0 100% 0); } to { opacity: 1; transform: none; clip-path: inset(0 0 0 0); } }
@keyframes jvh-ping { 40% { box-shadow: 0 0 0 1px rgba(var(--a),.9), 0 0 30px rgba(var(--a),.6); } }
.jvh-scan { position: absolute; inset: 0; overflow: hidden; pointer-events: none; border-radius: inherit; }
.jvh-scan::after { content: ""; position: absolute; left: 0; right: 0; height: 40%; top: -40%; background: linear-gradient(180deg, transparent, rgba(var(--a),.09), transparent); animation: jvh-sweep 5.5s linear infinite; }
@keyframes jvh-sweep { to { top: 100%; } }
.jvh-h { display: flex; align-items: center; gap: 8px; padding: 8px 8px 7px 12px; cursor: grab; touch-action: none; user-select: none; -webkit-user-select: none; border-bottom: 1px solid rgba(var(--a),.18); }
.jvh-p.col .jvh-h { border-bottom: 0; } .jvh-p.drag .jvh-h { cursor: grabbing; }
.jvh-h b { flex: 1; min-width: 0; font-size: 11.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: rgb(var(--a)); text-shadow: 0 0 8px rgba(var(--a),.55); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.jvh-h i { font-style: normal; font-size: 14px; }
.jvh-x { border: 0; background: rgba(255,255,255,.06); color: #cfe9f7; width: 24px; height: 24px; border-radius: 7px; cursor: pointer; font-size: 12px; line-height: 1; flex: none; }
.jvh-x:hover { background: rgba(var(--a),.25); }
.jvh-b { padding: 8px 12px 12px; display: grid; gap: 10px; max-height: min(52vh, 460px); overflow: auto; scrollbar-width: thin; }
.jvh-p.col .jvh-b { display: none; }
.jvh-bt { font-size: 10.5px; letter-spacing: .16em; text-transform: uppercase; color: rgba(var(--a),.85); margin-bottom: 4px; }
.jvh-t { line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; color: #e2eef8; }
.jvh-st { display: grid; grid-template-columns: repeat(auto-fit, minmax(84px, 1fr)); gap: 6px; }
.jvh-st div { border: 1px solid rgba(var(--a),.2); background: rgba(var(--a),.06); border-radius: 8px; padding: 6px 8px; min-width: 0; }
.jvh-st small { display: block; font-size: 10.5px; color: rgba(203,225,240,.7); text-transform: uppercase; letter-spacing: .06em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.jvh-st b { font-size: 18px; font-weight: 600; color: #fff; text-shadow: 0 0 10px rgba(var(--a),.5); font-variant-numeric: tabular-nums; }
.jvh-st em { font-style: normal; font-size: 11px; margin-left: 5px; color: #94a3b8; } .jvh-st em.up { color: #4ade80; } .jvh-st em.dn { color: #f87171; }
.jvh-ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 5px; }
.jvh-ul li { display: grid; grid-template-columns: 10px 1fr; gap: 7px; align-items: baseline; line-height: 1.35; overflow-wrap: anywhere; }
.jvh-ul li::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: rgba(var(--a),.85); box-shadow: 0 0 6px rgba(var(--a),.8); transform: translateY(-1px); }
.jvh-ul small { display: block; color: rgba(203,225,240,.65); font-size: 11.5px; }
.jvh-ul li[data-s]::before, .jvh-pr[data-s] i, .jvh-c .dot { background: #94a3b8; box-shadow: none; }
[data-s="ok"]::before, [data-s="done"]::before, [data-s="pass"]::before, [data-s="success"]::before { background: #4ade80 !important; }
[data-s="fail"]::before, [data-s="failed"]::before, [data-s="error"]::before, [data-s="red"]::before { background: #f87171 !important; }
[data-s="warn"]::before, [data-s="warning"]::before, [data-s="pending"]::before, [data-s="queued"]::before { background: #fbbf24 !important; }
[data-s="running"]::before, [data-s="active"]::before, [data-s="doing"]::before { background: #38bdf8 !important; }
.jvh-tb { overflow: auto; } .jvh-tb table { border-collapse: collapse; width: 100%; font-size: 12px; font-variant-numeric: tabular-nums; }
.jvh-tb th { text-align: left; font-weight: 600; font-size: 10.5px; letter-spacing: .08em; text-transform: uppercase; color: rgb(var(--a)); padding: 4px 8px 5px 0; border-bottom: 1px solid rgba(var(--a),.3); white-space: nowrap; }
.jvh-tb td { padding: 4px 8px 4px 0; border-bottom: 1px solid rgba(255,255,255,.06); vertical-align: top; overflow-wrap: anywhere; }
.jvh-pr { display: grid; grid-template-columns: 1fr auto; gap: 3px 8px; font-size: 12px; } .jvh-pr + .jvh-pr { margin-top: 6px; }
.jvh-pr span { font-variant-numeric: tabular-nums; color: rgb(var(--a)); }
.jvh-pr i { grid-column: 1 / -1; display: block; height: 5px; border-radius: 3px; background: rgba(255,255,255,.08); overflow: hidden; }
.jvh-pr i u { display: block; height: 100%; background: linear-gradient(90deg, rgba(var(--a),.5), rgb(var(--a))); box-shadow: 0 0 8px rgba(var(--a),.8); transition: width .5s ease; }
.jvh-pr[data-s="done"] u, .jvh-pr[data-s="ok"] u { background: #4ade80; } .jvh-pr[data-s="fail"] u, .jvh-pr[data-s="failed"] u, .jvh-pr[data-s="error"] u { background: #f87171; }
.jvh-svg { width: 100%; height: auto; display: block; overflow: visible; }
.jvh-svg text { fill: #cfe3f1; font-size: 10px; font-family: inherit; } .jvh-svg .v { fill: rgb(var(--a)); font-variant-numeric: tabular-nums; }
.jvh-code { margin: 0; padding: 8px 10px; border-radius: 8px; background: rgba(0,0,0,.4); border: 1px solid rgba(var(--a),.16); font: 11.5px/1.45 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre; overflow: auto; max-height: 300px; color: #dbeafe; }
.jvh-code .add { color: #86efac; background: rgba(74,222,128,.08); display: block; } .jvh-code .del { color: #fca5a5; background: rgba(248,113,113,.08); display: block; } .jvh-code .hk { color: #c4b5fd; display: block; }
.jvh-code span { display: block; min-height: 1.45em; }
.jvh-kv { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; font-size: 12.5px; } .jvh-kv dt { color: rgba(203,225,240,.65); } .jvh-kv dd { margin: 0; overflow-wrap: anywhere; }
.jvh-bn { display: flex; flex-wrap: wrap; gap: 6px; }
.jvh-bn button, .jvh-c .act button { border: 1px solid rgba(var(--a),.45); background: rgba(var(--a),.1); color: #e6f6ff; border-radius: 999px; padding: 5px 12px; font: inherit; font-size: 12px; cursor: pointer; transition: background-color .15s ease, transform .15s ease; }
.jvh-bn a { display: inline-block; text-decoration: none; border: 1px solid rgba(var(--a),.45); background: rgba(var(--a),.1); color: #e6f6ff; border-radius: 999px; padding: 5px 12px; font-size: 12px; transition: background-color .15s ease; }
.jvh-bn a::after { content: " →"; opacity: .6; } .jvh-bn a:hover { background: rgba(var(--a),.24); }
.jvh-tl { list-style: none; margin: 0; padding: 0; }
.jvh-tl li { position: relative; display: flex; gap: 10px; padding: 0 0 9px 17px; line-height: 1.35; overflow-wrap: anywhere; }
.jvh-tl li::before { content: ""; position: absolute; left: 0; top: .32em; width: 7px; height: 7px; border-radius: 50%; background: rgb(var(--a)); box-shadow: 0 0 6px rgba(var(--a),.8); }
.jvh-tl li::after { content: ""; position: absolute; left: 3px; top: 1.1em; bottom: -.15em; width: 1px; background: rgba(var(--a),.3); } .jvh-tl li:last-child::after { display: none; }
.jvh-tl time { flex: none; font-size: 11px; color: rgba(var(--a),.95); font-variant-numeric: tabular-nums; white-space: nowrap; padding-top: 1px; } .jvh-tl time:empty { display: none; } .jvh-tl div { flex: 1; min-width: 0; }
.jvh-ck { list-style: none; margin: 0; padding: 0; display: grid; gap: 5px; }
.jvh-ck li { display: flex; gap: 8px; align-items: baseline; line-height: 1.35; overflow-wrap: anywhere; }
.jvh-ck i { flex: none; width: 14px; height: 14px; box-sizing: border-box; border-radius: 4px; border: 1px solid rgba(var(--a),.6); display: inline-grid; place-items: center; font-style: normal; font-size: 10px; transform: translateY(2px); }
.jvh-ck li.on i { background: rgba(74,222,128,.22); border-color: #4ade80; color: #4ade80; } .jvh-ck li.on span { color: rgba(203,225,240,.6); text-decoration: line-through; }
.jvh-gw { display: grid; justify-items: center; gap: 2px; } .jvh-gw .jvh-svg { width: 150px; } .jvh-gw small { color: rgba(203,225,240,.7); font-size: 11.5px; text-align: center; }
.jvh-pp { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
.jvh-pp > div { display: flex; gap: 8px; align-items: center; min-width: 0; } .jvh-pp > div > div { min-width: 0; }
.jvh-pp .av { position: relative; flex: none; width: 30px; height: 30px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; font-weight: 600; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.4); box-shadow: 0 0 0 1px rgba(var(--a),.4), 0 0 10px rgba(var(--a),.25); }
.jvh-pp .av i { position: absolute; right: -1px; bottom: -1px; width: 9px; height: 9px; border-radius: 50%; border: 2px solid #07101e; background: #64748b; }
.jvh-pp .av i[data-p="on"] { background: #4ade80; } .jvh-pp .av i[data-p="busy"] { background: #fbbf24; } .jvh-pp .av i[data-p="bad"] { background: #f87171; } .jvh-pp .av i[data-p=""] { display: none; }
.jvh-pp b { display: block; font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.jvh-pp small { display: block; font-size: 11px; color: rgba(203,225,240,.65); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.jvh-img { display: block; max-width: 100%; max-height: 280px; margin: 0 auto; border-radius: 8px; border: 1px solid rgba(var(--a),.25); object-fit: contain; background: rgba(0,0,0,.3); }
.jvh-bn button:hover, .jvh-c .act button:hover { background: rgba(var(--a),.24); } .jvh-bn button:active, .jvh-c .act button:active { transform: scale(.96); }
.jvh-agh { pointer-events: auto; display: flex; align-items: center; gap: 8px; align-self: flex-end; border: 1px solid rgba(56,189,248,.3); background: rgba(6,10,20,.7); color: #7dd3fc; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 10.5px; letter-spacing: .14em; cursor: pointer; -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.jvh-ag.min .jvh-c { display: none; }
.jvh-c { padding: 0; } .jvh-c .jvh-h { cursor: default; padding: 6px 6px 5px 10px; gap: 6px; }
.jvh-c .jvh-h b { font-size: 11px; letter-spacing: .08em; }
.jvh-c .chip { font-size: 10px; letter-spacing: .06em; text-transform: uppercase; border-radius: 999px; padding: 1px 7px; border: 1px solid rgba(var(--a),.4); color: rgb(var(--a)); white-space: nowrap; }
.jvh-c .tm { font-size: 11px; color: rgba(203,225,240,.7); font-variant-numeric: tabular-nums; }
.jvh-c .bd { padding: 6px 10px 9px; display: grid; gap: 5px; font-size: 12px; }
.jvh-c .pq { color: #dbeafe; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; }
.jvh-c .sp { color: rgb(var(--a)); font-size: 11.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.jvh-c .fl { color: rgba(203,225,240,.6); font: 10.5px/1.35 ui-monospace, Menlo, monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.jvh-c .act { display: flex; gap: 6px; } .jvh-c .act button { padding: 2px 10px; font-size: 11.5px; } .jvh-c .act .dn { border-color: rgba(248,113,113,.5); background: rgba(248,113,113,.1); }
.jvh-c .pb { height: 3px; border-radius: 2px; background: rgba(255,255,255,.08); overflow: hidden; } .jvh-c .pb u { display: block; height: 100%; background: rgb(var(--a)); box-shadow: 0 0 6px rgba(var(--a),.9); transition: width .6s ease; }
.jvh-c .pb.ind u { width: 35% !important; animation: jvh-ind 1.4s ease-in-out infinite; } @keyframes jvh-ind { from { transform: translateX(-100%); } to { transform: translateX(290%); } }
.jvh-c.fl-ok { animation: jvh-fl 1.2s ease; --fl: 74,222,128; } .jvh-c.fl-bad { animation: jvh-fl 1.2s ease; --fl: 248,113,113; }
@keyframes jvh-fl { 0%, 60% { box-shadow: 0 0 0 2px rgba(var(--fl),.95), 0 0 34px rgba(var(--fl),.75); background-color: rgba(var(--fl),.18); } }
.jvh-c.fade { transition: opacity 1.2s ease, transform 1.2s ease; opacity: 0; transform: translateX(16px); }
.jvh-cost { display: inline-block; margin-left: 8px; font-size: 11px; letter-spacing: .04em; color: rgba(125,211,252,.85); border: 1px solid rgba(125,211,252,.28); border-radius: 999px; padding: 0 7px; font-variant-numeric: tabular-nums; vertical-align: 1px; }
.jvh-cost.hi { color: #fbbf24; border-color: rgba(251,191,36,.45); } .jvh-cost.over { color: #f87171; border-color: rgba(248,113,113,.5); }
.jvh-boot { position: fixed; inset: 0; z-index: 58; display: grid; place-items: center; background: radial-gradient(circle at 50% 45%, rgba(8,30,48,.82), rgba(2,6,14,.94) 70%); color: #7dd3fc; cursor: pointer; animation: jvh-bin .35s ease both; }
.jvh-boot.out { transition: opacity .45s ease; opacity: 0; } @keyframes jvh-bin { from { opacity: 0; } }
.jvh-boot .in { display: grid; justify-items: center; gap: 14px; width: min(440px, calc(100vw - 32px)); }
.jvh-boot svg { width: min(220px, 56vw); height: auto; overflow: visible; filter: drop-shadow(0 0 10px rgba(56,189,248,.7)); }
.jvh-boot circle { fill: none; stroke: #38bdf8; stroke-linecap: round; animation: jvh-draw 1.5s cubic-bezier(.3,.7,.2,1) both; transform-origin: 100px 100px; }
.jvh-boot circle.am { stroke: #fbbf24; } .jvh-boot circle.sp { animation: jvh-draw 1.5s cubic-bezier(.3,.7,.2,1) both, jvh-rot 6s linear infinite; }
@keyframes jvh-draw { from { stroke-dashoffset: var(--len); opacity: .2; } } @keyframes jvh-rot { to { transform: rotate(360deg); } }
.jvh-boot h2 { margin: 0; font-size: 20px; font-weight: 600; letter-spacing: .4em; color: #e0f2fe; text-shadow: 0 0 14px rgba(56,189,248,.9); animation: jvh-bin .6s .7s ease both; }
.jvh-boot h2 span { color: #fbbf24; text-shadow: 0 0 14px rgba(251,191,36,.8); }
.jvh-boot pre { margin: 0; min-height: 7.5em; width: 100%; font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; color: #7dd3fc; white-space: pre-wrap; }
.jvh-boot pre .w { color: #fbbf24; } .jvh-boot pre .b { color: #f87171; } .jvh-boot small { font-size: 10.5px; letter-spacing: .2em; color: rgba(125,211,252,.5); }
@media (max-width: 600px) {
  .jvh-ag { left: 8px; right: 8px; width: auto; max-height: 24vh !important; }
  .jvh-dk { left: 8px; bottom: auto; top: var(--jvh-top, 76px); } .jvh-p.hp:hover { translate: none !important; }
}
@media (prefers-reduced-motion: reduce) {
  .jvh-c.in, .jvh-p.hp.in .jvh-h, .jvh-p.hp.in .jvh-b, .jvh-p.ping, .jvh-chip.ping, .jvh-dk, .jvh-c.fl-ok, .jvh-c.fl-bad, .jvh-scan::after, .jvh-c .pb.ind u, .jvh-boot, .jvh-boot * { animation: none !important; }
  .jvh-c.fade, .jvh-pr i u, .jvh-c .pb u { transition: none; }
  .jvh-p.hp { transition: opacity .15s ease !important; } .jvh-p.hp:hover { translate: none !important; }
}`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const RM = matchMedia('(prefers-reduced-motion: reduce)');
  const E = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const SV = (tag, at) => { const n = document.createElementNS('http://www.w3.org/2000/svg', tag); for (const k in at) n.setAttribute(k, at[k]); return n; };
  const root = E('div', 'jvh'), ag = E('div', 'jvh-ag'), dk = E('div', 'jvh-dk'), dkc = E('div'), dka = E('button', 'all', '✕ Hamısını bağla'), sr = E('div', 'jvh-sr');
  ag.hidden = true; dk.hidden = true; dka.type = 'button'; dk.setAttribute('aria-label', 'Kiçildilmiş panellər'); sr.setAttribute('aria-live', 'polite');
  dk.append(dkc, dka); root.append(ag, dk, sr); document.body.append(root);
  const st = () => typeof JV.state === 'function' ? JV.state() : curSt;          // jarvis.js-də state/level ixrac olunmaya bilər (bax: hesabat)
  let curSt = 'off'; JV.on('state', s => { curSt = s; glow(); cost(); });

  /* ---------- Blokların render-i ---------- */
  const fmtN = v => Math.abs(v) >= 1e6 ? (v / 1e6).toFixed(1) + 'M' : Math.abs(v) >= 1e4 ? (v / 1e3).toFixed(1) + 'k' : String(Math.round(v * 100) / 100);
  const R = {
    text: b => E('div', 'jvh-t', b.text),
    stat: b => { const g = E('div', 'jvh-st'); for (const x of b.items) { const d = E('div'), v = E('b', null, x.value); d.append(E('small', null, x.label), v); if (x.delta) v.append(E('em', /^\s*\+|^\s*[1-9]/.test(x.delta) ? 'up' : /^\s*[-−]/.test(x.delta) ? 'dn' : '', x.delta)); g.append(d); } return g; },
    list: b => { const u = E('ul', 'jvh-ul'); for (const x of b.items) { const li = E('li'), t = E('div', null, x.text); if (x.sub) t.append(E('small', null, x.sub)); if (x.status) li.dataset.s = x.status; li.append(t); u.append(li); } return u; },
    table: b => {
      const w = E('div', 'jvh-tb'), t = E('table');
      if (b.columns.length) { const tr = E('tr'); for (const c of b.columns) tr.append(E('th', null, c)); t.append(E('thead')); t.tHead.append(tr); }
      const tb = E('tbody'); for (const r of b.rows) { const tr = E('tr'); for (const c of r) tr.append(E('td', null, c)); tb.append(tr); } t.append(tb); w.append(t); return w;
    },
    progress: b => { const g = E('div'); for (const x of b.items) { const r = E('div', 'jvh-pr'), i = E('i'), u = E('u'); u.style.width = x.pct + '%'; i.append(u); if (x.status) r.dataset.s = x.status; r.append(E('div', null, x.label), E('span', null, Math.round(x.pct) + '%'), i); g.append(r); } return g; },
    bar_chart: b => {
      const h = 18, W = 300, lw = 92, vw = 44, max = Math.max(...b.items.map(x => Math.abs(x.value)), 1e-9);
      const s = SV('svg', { class: 'jvh-svg', viewBox: `0 0 ${W} ${b.items.length * h}`, role: 'img' });
      b.items.forEach((x, i) => {
        const y = i * h, bw = Math.max(1, (W - lw - vw) * Math.abs(x.value) / max);
        const t = SV('text', { x: lw - 6, y: y + 12, 'text-anchor': 'end' }); t.textContent = x.label.length > 16 ? x.label.slice(0, 15) + '…' : x.label;
        const v = SV('text', { x: lw + bw + 5, y: y + 12, class: 'v' }); v.textContent = fmtN(x.value);
        s.append(t, SV('rect', { x: lw, y: y + 3, width: bw.toFixed(1), height: h - 7, rx: 2, style: 'fill:' + (x.value < 0 ? 'rgba(248,113,113,.8)' : 'rgba(var(--a),.75)') }), v);
      });
      return s;
    },
    sparkline: b => {
      const v = b.values, W = 240, H = 40, lo = Math.min(...v), hi = Math.max(...v), k = hi - lo || 1;
      const pts = v.map((x, i) => [i / (v.length - 1) * W, H - 3 - (x - lo) / k * (H - 6)]);
      const s = SV('svg', { class: 'jvh-svg', viewBox: `0 0 ${W + 46} ${H}`, role: 'img' }), p = pts.map(q => q[0].toFixed(1) + ',' + q[1].toFixed(1)).join(' ');
      s.append(SV('polyline', { points: `0,${H} ${p} ${W},${H}`, style: 'fill:rgba(var(--a),.12);stroke:none' }), SV('polyline', { points: p, style: 'fill:none;stroke:rgb(var(--a));stroke-width:1.6;stroke-linejoin:round' }));
      const L = pts[pts.length - 1]; s.append(SV('circle', { cx: L[0], cy: L[1], r: 2.6, style: 'fill:#fff' }));
      const t = SV('text', { x: W + 6, y: Math.min(H - 2, Math.max(10, L[1] + 4)), class: 'v' }); t.textContent = fmtN(v[v.length - 1]); s.append(t); return s;
    },
    code: b => { const p = E('pre', 'jvh-code', b.text); if (b.lang) p.dataset.lang = b.lang; return p; },
    diff: b => { const p = E('pre', 'jvh-code'); for (const l of b.text.split('\n')) p.append(E('span', /^\+(?!\+\+)/.test(l) ? 'add' : /^-(?!--)/.test(l) ? 'del' : /^@@/.test(l) ? 'hk' : null, l)); return p; },
    kv: b => { const d = E('dl', 'jvh-kv'); for (const x of b.items) d.append(E('dt', null, x.key), E('dd', null, x.value)); return d; },
    timeline: b => { const o = E('ol', 'jvh-tl'); for (const x of b.items) { const li = E('li'); if (x.status) li.dataset.s = x.status; li.append(E('time', null, x.time), E('div', null, x.text)); o.append(li); } return o; },
    checklist: b => { const u = E('ul', 'jvh-ck'); for (const x of b.items) { const li = E('li', x.done ? 'on' : null), i = E('i', null, x.done ? '✓' : ''); i.setAttribute('aria-hidden', 'true'); li.setAttribute('aria-label', (x.done ? 'edilib: ' : 'gözləyir: ') + x.text); li.append(i, E('span', null, x.text)); u.append(li); } return u; },
    gauge: b => {
      const r = 42, C = 2 * Math.PI * r, L = C * .75, v = Math.round(b.value), s = SV('svg', { class: 'jvh-svg', viewBox: '0 0 120 ' + (b.label ? 104 : 94), role: 'img', 'aria-label': (b.label ? b.label + ': ' : '') + v + '%' });
      const arc = (len, st) => SV('circle', { cx: 60, cy: 56, r, transform: 'rotate(135 60 56)', style: `fill:none;stroke-linecap:round;stroke-width:9;stroke-dasharray:${len.toFixed(1)} ${C.toFixed(1)};${st}` });
      s.append(arc(L, 'stroke:rgba(255,255,255,.09)'), arc(Math.max(.01, L * b.value / 100), 'stroke:rgb(var(--a));filter:drop-shadow(0 0 4px rgba(var(--a),.8))'));
      const t = SV('text', { x: 60, y: 63, 'text-anchor': 'middle', class: 'v', style: 'font-size:22px;font-weight:600' }); t.textContent = v + '%'; s.append(t);
      if (b.label) { const l = SV('text', { x: 60, y: 100, 'text-anchor': 'middle', style: 'font-size:11px' }); l.textContent = b.label; s.append(l); }
      const w = E('div', 'jvh-gw'); w.append(s); if (b.sub) w.append(E('small', null, b.sub)); return w;
    },
    people: b => {
      const g = E('div', 'jvh-pp');
      for (const x of b.items) {
        const d = E('div'), av = E('span', 'av', ini(x.name)), dot = E('i'), t = E('div'); av.style.background = ucol(x.name); dot.dataset.p = pst(x.status); av.append(dot);
        t.append(E('b', null, x.name)); const sub = [x.status, x.text].filter(Boolean).join(' · '); if (sub) t.append(E('small', null, sub)); t.title = x.name + (sub ? ' — ' + sub : '');
        d.append(av, t); g.append(d);
      }
      return g;
    },
    image: b => { const i = E('img', 'jvh-img'); i.alt = b.alt; i.decoding = 'async'; i.referrerPolicy = 'same-origin'; i.src = b.src; return i; },
    link: b => { const g = E('div', 'jvh-bn'); for (const x of b.items) { const a = E('a', null, x.label); a.href = x.href; g.append(a); } return g; },
    buttons: b => {
      const g = E('div', 'jvh-bn');
      for (const x of b.items) {
        const k = E('button', null, x.label); k.type = 'button';
        k.onclick = () => { if (x.onClick) { try { x.onClick(); } catch (e) { console.error('JarvisHUD', e); } return; }
          if (JV.say && (typeof JV.ready !== 'function' || JV.ready())) JV.say('[User clicked "' + x.label + '"] ' + x.say); else if (U.line) U.line('i', 'Jarvis qoşulmayıb: “' + x.label + '”'); };
        g.append(k);
      }
      return g;
    },
  };
  const ini = n => n.split(/[\s._-]+/).filter(Boolean).slice(0, 2).map(w => Array.from(w)[0].toUpperCase()).join('') || '?';
  const ucol = n => { try { if (typeof tint === 'function') return tint(n); } catch (e) {} let h = 0; for (const c of n) h = (h * 31 + c.codePointAt(0)) % 360; return `hsl(${h} 58% 50%)`; };   // index.php-nin istifadəçi rəngi
  const pst = s => { s = s.toLowerCase(); return !s ? '' : /^(online|onlayn|active|aktiv|ok|free|available|hazır)/.test(s) ? 'on' : /^(work|busy|running|doing|işləyir|məşğul)/.test(s) ? 'busy' : /^(wait|block|error|fail|gözlə|xəta)/.test(s) ? 'bad' : 'off'; };
  const accent = (n, a) => { for (const x of ACC) n.classList.toggle(x, x === a); };
  const once = n => n.addEventListener('animationend', e => { if (e.target === n && e.animationName === 'jvh-in') n.classList.remove('in'); });
  function body(n, spec) {
    const b = n._r.b, sc = b.scrollTop; b.textContent = '';
    for (const x of spec.blocks) { const w = E('div'); if (x.title) w.append(E('div', 'jvh-bt', x.title)); try { w.append(R[x.type](x)); } catch (e) { console.error('JarvisHUD ' + x.type, e); continue; } b.append(w); }
    accent(n, spec.accent); b.scrollTop = sc;
    n._r.ic.textContent = spec.icon; n._r.ic.hidden = !spec.icon; n._r.t.textContent = spec.title; n.setAttribute('aria-label', spec.title);
    if (n._chip) chipPaint(n);
  }
  /** Uşaqları lazım olan sıraya qoy; yalnız yeri dəyişənləri köçür (köçürmə CSS animasiyasını yenidən başladır) */
  function order(par, ns, off) { ns.forEach((n, i) => { const at = par.children[i + off]; if (at !== n) par.insertBefore(n, at || null); }); }
  const say = t => { sr.textContent = ''; setTimeout(() => { sr.textContent = t; }, 40); };   // ekran oxuyucusu üçün

  /* ---------- Panellər: boş yer mühərriki ----------
     Maneələr: başlıq, Jarvis paneli, agent kartları, kiçildilmiş panellər sırası, saytın planet paneli/düymələri, [data-jv-obstacle], digər HUD panelləri.
     Yeni panel → sol sütun → aşağı → mərkəz-sol ardıcıllığı ilə ilk boş düzbucaqlı (GAP aralıqla); yoxdursa dar en, sonra qısa gövdə,
     sonra ən köhnə bərkidilməmiş panel çipə yığılır. Mövcud panellər yalnız nəyəsə dəyəndə yerini yaxınlıqda dəyişir. */
  const P = new Map(); let seq = 0, z = 1, age = 0;
  const MAXALL = 12, GAP = 12, MG = 12, WD = { compact: 300, normal: 380, wide: 560 };
  const mob = () => innerWidth <= 600;
  const hdr = document.querySelector('header');
  let topY = 76, FC = null;
  function setTop() {
    topY = Math.max(MG, Math.round((hdr ? hdr.getBoundingClientRect().bottom : 64) + 10));
    root.style.setProperty('--jvh-top', topY + 'px'); root.style.setProperty('--jvh-mh', Math.max(160, innerHeight - topY - MG) + 'px');
  }
  addEventListener('scroll', setTop, { passive: true }); setTop();
  const area = () => ({ l: MG, t: topY, r: innerWidth - MG, b: innerHeight - MG });
  const hit = (a, b, g) => a.l < b.r + g && a.r + g > b.l && a.t < b.b + g && a.b + g > b.t;
  const inA = (c, A) => c.l >= A.l - 1 && c.t >= A.t - 1 && c.r <= A.r + 1 && c.b <= A.b + 1;
  const rc = n => ({ l: n._x, t: n._y, r: n._x + n._w, b: n._y + n._h });
  /** Görünən elementin düzbucaqlısı (gizli/şəffaf/ekrandan kənar → null) */
  function visEl(el, anyOp) {
    if (!el || !el.isConnected || el.closest('[hidden]')) return null;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || (!anyOp && +cs.opacity < .05)) return null;
    const r = el.getBoundingClientRect(); if (r.width < 2 || r.height < 2 || r.bottom <= 0 || r.right <= 0 || r.top >= innerHeight || r.left >= innerWidth) return null;
    return { l: r.left, t: r.top, r: r.right, b: r.bottom };
  }
  /** Konteynerin görünən uşaqlarının birləşməsi (məs. mərkəzlənmiş düymə sırası: boş kənarlar maneə sayılmasın) */
  function uni(el) {
    if (!el || el.closest('[hidden]')) return null; let u = null;
    for (const c of el.children) { const r = visEl(c); if (r) u = u ? { l: Math.min(u.l, r.l), t: Math.min(u.t, r.t), r: Math.max(u.r, r.r), b: Math.max(u.b, r.b) } : r; }
    return u;
  }
  const OBS1 = ['#space .fsbtn', '.jui-tour', '.jvl-cap', '[data-jv-obstacle]'], OBSU = ['#space .zbtns', '#space .stools', '#space .travel', '#space .hud'];
  function obstacles(noAg) {
    const o = [], add = r => { if (r) o.push(r); };
    add(visEl(hdr)); if (JV.panel && !JV.panel.hidden) add(visEl(JV.panel));
    if (!ag.hidden && !noAg) add(uni(ag)); if (!dk.hidden && !mob()) add(visEl(dk));
    add(visEl(document.querySelector('#dock.open'), true));                     // planet paneli (açılarkən hələ şəffafdır)
    for (const s of OBS1) for (const e of document.querySelectorAll(s)) add(visEl(e));
    for (const s of OBSU) for (const e of document.querySelectorAll(s)) add(uni(e));
    return o;
  }
  /** 3D səhnənin fokus mərkəzi: mümkünsə örtmə (cəza balı) */
  function focus() {
    const sp = document.getElementById('space'), r = sp && !sp.closest('[hidden]') ? sp.getBoundingClientRect() : null;
    return r && r.width > 200 ? { x: r.left + r.width / 2, y: r.top + r.height * .45, r: Math.min(r.width, r.height) * .17 } : null;
  }
  const hitC = (c, f) => { const dx = Math.max(c.l - f.x, 0, f.x - c.r), dy = Math.max(c.t - f.y, 0, f.y - c.b); return dx * dx + dy * dy < f.r * f.r; };
  /** w×h üçün ən yaxşı boş yer: namizəd x/y = kənar + maneə kənarları + 40px tor; bal = sol sütun üstünlüyü (və ya köhnə yerə yaxınlıq) */
  function spot(w, h, obs, near) {
    const A = area(); if (w > A.r - A.l || h > A.b - A.t) return null;
    const xs = new Set([A.l, A.r - w]), ys = new Set([A.t, A.b - h]);
    for (const r of obs) { xs.add(r.r + GAP); xs.add(r.l - GAP - w); ys.add(r.b + GAP); ys.add(r.t - GAP - h); }
    for (let x = A.l; x <= A.r - w; x += 40) xs.add(x);
    for (let y = A.t; y <= A.b - h; y += 40) ys.add(y);
    let best = null, bs = Infinity;
    for (const x of xs) {
      if (x < A.l || x + w > A.r) continue;
      for (const y of ys) {
        if (y < A.t || y + h > A.b) continue;
        let s = near ? Math.abs(x - near.x) + Math.abs(y - near.y) : x * 3 + y; if (s >= bs) continue;
        const c = { l: x, t: y, r: x + w, b: y + h };
        if (FC && hitC(c, FC)) { s += 4000; if (s >= bs) continue; }
        if (obs.some(r => hit(c, r, GAP))) continue;
        bs = s; best = c;
      }
    }
    return best;
  }
  const setW = (n, w) => { w = Math.round(w); if (n._w !== w) { n._w = w; n.style.width = w + 'px'; } };
  const wants = n => { const A = area(), pw = Math.min(WD[n._spec.layout] || WD.normal, A.r - A.l); return [pw, Math.min(WD.compact, pw)]; };
  /** Paneli yerləşdirməyə çalış: əsas en → dar en → qısa gövdə (daxili scroll) */
  function fitIn(n, obs, near) {
    const [pw, cw] = wants(n); n.style.removeProperty('--bh');
    for (const w of pw > cw ? [pw, cw] : [pw]) { setW(n, w); const c = spot(w, n.offsetHeight, obs, near); if (c) return c; }
    if (!n._uc) { n.style.setProperty('--bh', '170px'); const c = spot(cw, n.offsetHeight, obs, near); if (c) return c; n.style.removeProperty('--bh'); }
    return null;
  }
  function origin() {
    const o = JV.panel && !JV.panel.hidden && JV.panel.querySelector('.jv-orb') || JV.button, r = o && o.getBoundingClientRect();
    return r && r.width ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : { x: innerWidth - 60, y: innerHeight - 60 };
  }
  const tf = (x, y, s) => `translate3d(${Math.round(x)}px,${Math.round(y)}px,0)` + (s ? ` scale(${s})` : '');
  /** Hədəfə hamar keçid; ilk dəfə isə Jarvis kürəsindən (və ya çipdən) "uçub" gəlir */
  function move(n, c) {
    const first = !n._placed; n._x = Math.round(c.l); n._y = Math.round(c.t); n._h = Math.round(c.b - c.t); n._rc = rc(n);
    clearTimeout(n._ht); n.hidden = false; n.classList.remove('gone');
    if (first && !RM.matches) {
      const o = n._from || origin();
      n.classList.add('nt'); n.style.opacity = '0'; n.style.transform = tf(o.x - n._w * .09, o.y - n._h * .09, .18);
      void n.offsetWidth; n.classList.remove('nt'); n.classList.add('in'); clearTimeout(n._it); n._it = setTimeout(() => n.classList.remove('in'), 400);
    } else n.classList.remove('nt');
    n._from = null; n._placed = true; n.style.transform = tf(n._x, n._y); n.style.opacity = '1';
  }
  /** Vizual: panel kiçildilmiş sıraya uçur və gizlənir */
  function toDock(n) {
    const r = !dk.hidden && dk.getBoundingClientRect(), x = r && r.width ? r.right : MG + 20, y = r && r.width ? r.top : innerHeight - 40;
    n._pend = false; n.classList.remove('drag', 'nt', 'in'); n.classList.add('gone');
    n.style.transform = tf(x, y, .12); n.style.opacity = '0';
    clearTimeout(n._ht); n._ht = setTimeout(() => { if (n._min) n.hidden = true; }, RM.matches ? 0 : 300);
  }
  const cc = n => { const r = n._chip && n._chip.isConnected ? n._chip.getBoundingClientRect() : null; return r && r.width ? { x: r.left + r.width / 2, y: r.top + r.height / 2 } : null; };
  const RGB = { cyan: '56,189,248', amber: '251,191,36', red: '248,113,113', green: '74,222,128', violet: '192,132,252' };
  function chipPaint(n) {
    const k = n._chip, sp = n._spec; k.style.setProperty('--a', RGB[sp.accent] || RGB.cyan);
    k.firstChild.textContent = (sp.icon || '▣') + ' '; k.lastChild.textContent = sp.title; k.title = 'Aç: ' + sp.title; k.setAttribute('aria-label', 'Paneli aç: ' + sp.title);
  }
  function dockPaint() {
    const ms = [...P.values()].filter(n => n._min && !n._pend).sort((a, b) => a._pa - b._pa), keep = new Set(ms.map(n => n._chip));
    for (const c of [...dkc.children]) if (!keep.has(c)) c.remove();
    for (const n of ms) {
      if (!n._chip) { const k = n._chip = E('button', 'jvh-chip'); k.type = 'button'; k.append(E('i'), E('span')); k.onclick = () => restore(n); }
      chipPaint(n); if (n._chip.parentNode !== dkc) dkc.append(n._chip);
    }
    dka.hidden = P.size < 2; dk.hidden = !ms.length && P.size < 2;
  }
  dka.onclick = () => close('all');

  let rlT = 0, sig = '';
  const kick = d => { clearTimeout(rlT); rlT = setTimeout(relayout, d == null ? 60 : d); };
  const sigOf = o => innerWidth + 'x' + innerHeight + '|' + o.map(r => Math.round(r.l) + ',' + Math.round(r.t) + ',' + Math.round(r.r) + ',' + Math.round(r.b)).join(';');
  function relayout() {
    clearTimeout(rlT); setTop();
    const site = obstacles(); sig = sigOf(site);
    if (mob()) { mobLayout(); fit(); return; }
    if (dk.style.top) dk.style.top = '';
    FC = focus();
    const A = area(), live = [...P.values()].filter(n => !n._min).sort((a, b) => (b._pin ? 1 : 0) - (a._pin ? 1 : 0) || a._pa - b._pa);
    const acc = [], todo = [], obs = () => site.concat(acc.map(n => n._rc));
    for (const n of live) {
      if (n._placed && n._pin) {                                                   // bərkidilmiş: yerində qalır (yalnız ekrana sığdırılır)
        n._h = n.offsetHeight; const x = clamp(n._x, 0, Math.max(0, innerWidth - n._w)), y = clamp(n._y, 0, Math.max(0, innerHeight - n._h));
        if (x !== n._x || y !== n._y) move(n, { l: x, t: y, r: x + n._w, b: y + n._h }); n._rc = rc(n); acc.push(n); continue;
      }
      if (n._placed && wants(n).includes(n._w)) {
        n._h = n.offsetHeight; const c = rc(n);
        if (inA(c, A) && !site.some(r => hit(c, r, GAP - 2)) && !acc.some(m => hit(c, m._rc, GAP - 2))) { n._rc = c; acc.push(n); continue; }
      }
      todo.push(n);
    }
    todo.sort((a, b) => (a._placed ? 0 : 1) - (b._placed ? 0 : 1) || a._pa - b._pa);   // əvvəl yerindən çıxanlar (yaxında), sonra yenilər
    const vics = [];
    for (const n of todo) {
      let c;
      for (;;) {
        c = fitIn(n, obs(), n._placed ? { x: n._x, y: n._y } : null); if (c) break;
        const v = acc.concat(n).filter(m => !m._pin).sort((a, b) => a._pa - b._pa)[0];   // ən köhnə bərkidilməmiş → çip
        if (!v || v === n) break;
        acc.splice(acc.indexOf(v), 1); v._min = 'auto'; v._pend = true; vics.push(v);
      }
      if (c) { move(n, c); acc.push(n); } else { n._min = 'auto'; n._pend = true; vics.push(n); }
    }
    // İndi çipə düşənlər başqa yerə sığırsa — çip etmə, sadəcə köçür; köhnə avtomatik çipləri də yer varsa geri qaytar
    for (const n of vics) {
      const c = fitIn(n, obs(), { x: n._x, y: n._y });
      if (c) { n._min = null; n._pend = false; move(n, c); acc.push(n); } else toDock(n);
    }
    for (const n of [...P.values()].filter(m => m._min === 'auto' && !m._pend && !vics.includes(m)).sort((a, b) => b._pa - a._pa)) {
      const wasH = n.hidden; n.hidden = false; n.classList.add('nt');
      const c = fitIn(n, obs(), null);
      if (c) { n._from = cc(n); n._min = null; n._placed = false; move(n, c); acc.push(n); } else { n.hidden = wasH; n.classList.remove('nt'); }
    }
    dockPaint(); fit();
  }
  /** Mobil (≤600px): başlığın altında tək sütun, ən çox 2 açıq panel, qalanları çip */
  function mobLayout() {
    const A = area(); let y = A.t;
    if (!ag.hidden) { const r = uni(ag); if (r) y = Math.max(y, r.b + 8); }
    const ps = [...P.values()].sort((a, b) => b._pa - a._pa), cand = ps.filter(n => n._min !== 'user');
    // Aşağıdan gələn maneələr (Jarvis paneli, planet paneli): yer azdırsa daha az panel açıq qalır
    const yd = y + (P.size < 2 && !ps.some(n => n._min) ? 0 : 44);
    let bot = A.b; for (const r of [JV.panel && !JV.panel.hidden ? visEl(JV.panel) : null, visEl(document.querySelector('#dock.open'), true)]) if (r && r.t > yd) bot = Math.min(bot, r.t - 8);
    const room = bot - yd, max = room >= 320 ? 2 : room >= 140 ? 1 : cand.some(n => n._ur) ? 1 : 0;
    if (room < 140) bot = A.b;                                                       // yer yoxdur: yalnız istifadəçinin özü açdığı panel (maneənin üstünə düşsə də)
    const exp = (room < 140 ? cand.filter(n => n._ur) : cand).slice(0, max);
    for (const n of ps) if (!exp.includes(n) && !n._min) { n._min = 'auto'; n._ur = false; toDock(n); }
    for (const n of exp) n._pend = false;
    dockPaint(); dk.style.top = y + 'px'; if (!dk.hidden) y += dk.offsetHeight + 8;
    const w = innerWidth - 16, each = Math.max(140, (bot - y - 8 * (exp.length - 1)) / Math.max(1, exp.length));
    for (const n of exp) {
      if (n._min) { n._from = cc(n); n._min = null; n._placed = false; n.hidden = false; n.classList.add('nt'); }
      setW(n, w); n.style.setProperty('--bh', Math.max(80, Math.round(each - 56)) + 'px');
      const h = Math.min(n.offsetHeight, each); move(n, { l: 8, t: y, r: 8 + w, b: y + h }); y += h + 8;
    }
    dockPaint();
  }
  function restore(n) {
    if (!P.has(n.dataset.id)) return;
    n._from = cc(n); n._min = null; n._pend = false; n._ur = true; n._pa = ++age; n._placed = false; n._uc = false; n.classList.remove('col');
    n.hidden = false; n.classList.add('nt'); n.style.opacity = '0'; n.style.zIndex = ++z;
    relayout(); say('Panel açıldı: ' + n._spec.title);
  }
  function minimise(n) { n._min = 'user'; n._ur = false; toDock(n); dockPaint(); kick(); }
  function setPin(n, v) { n._pin = !!v; n.classList.toggle('pin', n._pin); n._r.pn.setAttribute('aria-pressed', String(n._pin)); n._r.pn.title = n._pin ? 'Bərkidilib (burax)' : 'Bərkit'; if (!v) kick(); }
  function toggleCol(n) { n._uc = !n._uc; n.classList.toggle('col', n._uc); n._r.h.setAttribute('aria-expanded', String(!n._uc)); relayout(); }
  function drag(n, h) {
    let s = null;
    h.addEventListener('pointerdown', e => {
      n.style.zIndex = ++z;
      if (e.button || e.target.closest('button') || mob()) return;
      s = { x: e.clientX, y: e.clientY, id: e.pointerId, moved: false, l: n._x, t: n._y }; try { h.setPointerCapture(e.pointerId); } catch (er) {}
    });
    h.addEventListener('pointermove', e => {
      if (!s || e.pointerId !== s.id) return;
      if (!s.moved) { if (Math.hypot(e.clientX - s.x, e.clientY - s.y) < 5) return; s.moved = true; n.classList.add('drag'); }
      n._x = Math.round(clamp(s.l + e.clientX - s.x, 0, Math.max(0, innerWidth - n._w))); n._y = Math.round(clamp(s.t + e.clientY - s.y, 0, Math.max(0, innerHeight - n._h)));
      n.style.transform = tf(n._x, n._y);
    });
    const up = e => {
      if (!s || e.pointerId !== s.id) return; const m = s.moved; s = null; n.classList.remove('drag');
      if (m) { n._rc = rc(n); setPin(n, true); relayout(); }                    // atıldığı yerdə bərkidilir, digərləri ondan qaçır
    };
    h.addEventListener('pointerup', up); h.addEventListener('pointercancel', up);
    h.addEventListener('dblclick', e => { if (!e.target.closest('button')) toggleCol(n); });
    h.addEventListener('keydown', e => {
      if (e.target !== h) return;
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleCol(n); }
      else if (e.key === 'Escape') { e.preventDefault(); close(n.dataset.id); }
      else if (/^Arrow/.test(e.key) && !mob()) {
        e.preventDefault(); const d = e.shiftKey ? 60 : 20, dx = e.key === 'ArrowLeft' ? -d : e.key === 'ArrowRight' ? d : 0, dy = e.key === 'ArrowUp' ? -d : e.key === 'ArrowDown' ? d : 0;
        const x = clamp(n._x + dx, 0, Math.max(0, innerWidth - n._w)), y = clamp(n._y + dy, 0, Math.max(0, innerHeight - n._h));
        move(n, { l: x, t: y, r: x + n._w, b: y + n._h }); setPin(n, true); kick(250);
      }
    });
  }
  const ro = typeof ResizeObserver === 'function' ? new ResizeObserver(() => { if (P.size) kick(90); }) : null;
  const hbtn = (txt, cls, tl) => { const k = E('button', 'jvh-x' + (cls ? ' ' + cls : ''), txt); k.type = 'button'; k.title = tl; k.setAttribute('aria-label', tl); return k; };
  function show(spec, opt) {
    const { spec: sp, skipped } = clean(spec);
    const id = 'p' + (++seq), n = E('section', 'jvh-p hp'), h = E('div', 'jvh-h'), ic = E('i'), t = E('b'), b = E('div', 'jvh-b');
    const pn = hbtn('📌', 'jvh-pn', 'Bərkit'), mn = hbtn('–', null, 'Kiçilt'), x = hbtn('✕', null, 'Bağla');
    pn.setAttribute('aria-pressed', 'false'); pn.onclick = () => setPin(n, !n._pin); mn.onclick = () => minimise(n); x.onclick = () => close(id);
    h.tabIndex = 0; h.setAttribute('aria-expanded', 'true'); h.title = 'Sürüklə: yerini dəyiş · iki klik: yığ/aç';
    h.append(ic, t, pn, mn, x); n.append(E('div', 'jvh-scan'), h, b); n.setAttribute('role', 'region'); n.dataset.id = id;
    n._r = { ic, t, b, h, pn }; n._spec = sp; n._pa = ++age; n._on = opt && typeof opt.onClose === 'function' ? opt.onClose : null;
    n.classList.add('nt'); n.style.opacity = '0'; n.style.zIndex = ++z;
    body(n, sp); drag(n, h); P.set(id, n); root.append(n);
    if (P.size > MAXALL) { const old = [...P.values()].sort((a, b) => (b._min ? 1 : 0) - (a._min ? 1 : 0) || a._pa - b._pa)[0]; close(old.dataset.id); }
    relayout(); if (ro) ro.observe(n); say('Panel açıldı: ' + sp.title);
    return skipped.length ? { id, skipped } : { id };
  }
  function update(id, spec) {
    const n = P.get(String(id)); if (!n) throw new Error('Panel tapılmadı: ' + id);
    spec = parse(spec) || {}; const m = { ...n._spec };
    for (const k of ['title', 'icon', 'accent', 'layout', 'blocks']) if (spec[k] !== undefined && spec[k] !== null) m[k] = spec[k];
    const { spec: sp, skipped } = clean(m);
    n._spec = sp; body(n, sp);
    if (n._min) { if (n._chip && !RM.matches) { n._chip.classList.remove('ping'); void n._chip.offsetWidth; n._chip.classList.add('ping'); } }
    else { n.style.removeProperty('--bh'); relayout(); if (!RM.matches) { n.classList.remove('ping'); void n.offsetWidth; n.classList.add('ping'); } }
    say('Panel yeniləndi: ' + sp.title);
    return skipped.length ? { id: String(id), skipped } : { id: String(id) };
  }
  function close(id) {
    const ids = id == null || id === 'all' ? [...P.keys()] : [String(id)]; let k = 0;
    for (const i of ids) {
      const n = P.get(i); if (!n) continue; P.delete(i); k++; if (ro) ro.unobserve(n);
      if (n._on) try { n._on(i); } catch (e) { console.error('JarvisHUD', e); }
      if (RM.matches || n.hidden) n.remove();
      else { n.classList.add('gone'); n.style.transformOrigin = '50% 50%'; n.style.opacity = '0'; n.style.transform = tf(n._x, n._y + 6, .96); setTimeout(() => n.remove(), 300); }
    }
    dockPaint(); if (k) kick(160);                                                 // qalanlar yerində qalır; yer boşaldısa çiplər geri qayıdır
    return k;
  }
  const list = () => [...P.entries()].map(([id, n]) => ({ id, title: n._spec.title, accent: n._spec.accent, layout: n._spec.layout, collapsed: !!n._uc, pinned: !!n._pin, minimised: !!n._min, blocks: n._spec.blocks.map(b => b.type) }));

  /* Dəyişiklik izləyicisi: ölçü dəyişəndə (ResizeObserver) + ucuz 500ms yoxlama (maneələrin imzası); sayt modalı açıqdırsa HUD geri çəkilir */
  if (ro) { for (const e of [hdr, JV.panel, ag]) if (e) ro.observe(e); }
  addEventListener('resize', () => { setTop(); kick(120); });
  setInterval(() => {
    if (document.hidden) return;
    root.classList.toggle('dim', !!document.querySelector('.modal:not([hidden])'));
    if (P.size && sigOf(obstacles()) !== sig) kick(40);
  }, 500);

  /* Agent kartları sütunu Jarvis panelinin (sağ-aşağı) üstünə düşməsin */
  function fit() {
    if (mob()) { ag.style.maxHeight = ''; ag.style.left = ag.style.top = ag.style.right = ''; return; }
    placeAg();
    const jv = JV.panel && !JV.panel.hidden ? JV.panel.getBoundingClientRect() : null, r = ag.getBoundingClientRect(); let mh = innerHeight - r.top - 20;
    if (jv && jv.width && r.right > jv.left - 8 && r.left < jv.right) mh = Math.min(mh, jv.top - r.top - 12);
    ag.style.maxHeight = Math.max(120, mh) + 'px';
  }
  /* Agent kartları da boş yerə: sağ-yuxarıya ən yaxın, saytın panellərini (planet paneli, Jarvis) örtməyən yer */
  function placeAg() {
    if (ag.hidden) return;
    const w = ag.offsetWidth, h = Math.min(ag.scrollHeight, 300), A = area(), c = spot(w, h, obstacles(true), { x: A.r - w, y: A.t });
    if (!c) return;
    const r = ag.getBoundingClientRect();
    if (Math.abs(r.left - c.l) > 3 || Math.abs(r.top - c.t) > 3) Object.assign(ag.style, { left: c.l + 'px', top: c.t + 'px', right: 'auto' });
  }
  addEventListener('resize', fit);

  /* ---------- Alətlər (deklarasiya: jarvis_modules/hud.php) ---------- */
  const blocksArg = a => a.blocks !== undefined ? a.blocks : a.spec && a.spec.blocks;
  JV.registerTool('show_panel', a => { const r = show({ title: a.title, icon: a.icon, accent: a.accent, layout: a.layout, blocks: blocksArg(a) }); return { ok: true, ...r }; });
  JV.registerTool('update_panel', a => {
    if (!a.id) throw new Error('"id" lazımdır');
    const sp = a.spec && typeof a.spec === 'object' ? a.spec : a, r = update(a.id, { title: sp.title, icon: sp.icon, accent: sp.accent, layout: sp.layout, blocks: sp.blocks });
    return { ok: true, ...r };
  });
  JV.registerTool('close_panels', a => { const id = a && a.id ? String(a.id) : 'all'; const k = close(id); if (!k && id !== 'all') throw new Error('Panel tapılmadı: ' + id); return { ok: true, id, closed: k }; });

  /* ---------- Canlı agent kartları: mənim Claude Code tapşırıqlarım (növbədə/işləyir + son 60 san bitənlər) ---------- */
  const LIVE = new Set(['pending', 'queued', 'running']);
  const CST = { pending: ['təsdiq', 'amber'], queued: ['növbədə', 'amber'], running: ['işləyir', 'cyan'], done: ['bitdi', 'green'], failed: ['xəta', 'red'], canceled: ['dayandı', 'violet'] };
  const C = new Map(), gone = new Set(), resPanel = new Map();
  const agHead = E('button', 'jvh-agh'); agHead.type = 'button'; ag.append(agHead);
  let agMin = false; agHead.onclick = () => { agMin = !agMin; ag.classList.toggle('min', agMin); tick(); };
  const dur = s => { s = Math.max(0, Math.floor(s)); const h = Math.floor(s / 3600), m = Math.floor(s / 60) % 60, x = s % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
  const base = f => String(f).split(/[\\/]/).pop();
  function card(c) {
    const n = E('section', 'jvh-p jvh-c'), h = E('div', 'jvh-h'), ttl = E('b'), chip = E('span', 'chip'), tm = E('span', 'tm'), x = E('button', 'jvh-x', '✕');
    const bd = E('div', 'bd'), pq = E('div', 'pq'), sp = E('div', 'sp'), pb = E('div', 'pb'), pu = E('u'), fl = E('div', 'fl'), act = E('div', 'act');
    const stop = E('button', 'dn', 'Dayandır'), res = E('button', null, 'Nəticə');
    x.type = stop.type = res.type = 'button'; x.title = 'Gizlət'; x.setAttribute('aria-label', 'Gizlət');
    pb.append(pu); act.append(stop, res); h.append(ttl, chip, tm, x); bd.append(pq, sp, pb, fl, act); n.append(h, bd);
    const id = +c.id;
    x.onclick = () => { gone.add(id); n.remove(); C.delete(id); tick(); };
    stop.onclick = async () => {
      stop.disabled = true;
      try { const r = typeof CTL === 'function' ? await CTL('stop_command', { id }) : null; if (r && r.status !== 200 && U.line) U.line('e', '✗ ' + (r.d.error || 'Dayandırılmadı')); if (typeof load === 'function') load(); }
      catch (e) { console.error('JarvisHUD stop', e); } finally { stop.disabled = false; }
    };
    res.onclick = () => {
      const k = n._c, txt = String(k.result || '').trim(); if (!txt) return;
      const sp2 = { title: '#' + id + ' · ' + k.project, icon: '🤖', accent: k.status === 'done' ? 'green' : k.status === 'failed' ? 'red' : 'cyan',
        blocks: [{ type: 'kv', items: [{ key: 'Status', value: (CST[k.status] || [k.status])[0] }, { key: 'Tapşırıq', value: k.prompt }] }, { type: 'code', text: txt.slice(-6000) }] };
      const pid = resPanel.get(id);
      if (pid && P.has(pid)) update(pid, sp2); else resPanel.set(id, show(sp2, { onClose: () => resPanel.delete(id) }).id);
    };
    n._r = { ttl, chip, tm, pq, sp, pb, pu, fl, stop, res }; n._st = c.status;
    if (!RM.matches) { n.classList.add('in'); once(n); }
    return n;
  }
  function paint(n, c, t) {
    const q = n._r, cs = CST[c.status] || [c.status, 'cyan'], pr = c.progress && typeof c.progress === 'object' ? c.progress : null;
    n._c = c;
    accent(n, cs[1]);
    const set = (el, v) => { if (el.textContent !== v) el.textContent = v; };
    set(q.ttl, '🤖 ' + c.project + (+c.mission_id ? ' · M' + c.mission_id + (c.step ? '/' + c.step : '') : ''));
    set(q.chip, cs[0]);
    const t0 = +c.started_at || +c.created_at, t1 = LIVE.has(c.status) ? t : +c.finished_at || t;
    set(q.tm, c.status === 'queued' || c.status === 'pending' ? dur(t - +c.created_at) : dur(t1 - t0));
    set(q.pq, String(c.prompt || '').replace(/\s+/g, ' ').slice(0, 180));
    const step = pr && pr.step ? String(pr.step).slice(0, 140) : c.status === 'running' ? 'işləyir…' : '';
    set(q.sp, step); q.sp.hidden = !step;
    const pct = pr ? N(pr.pct) : null, run = c.status === 'running';
    q.pb.hidden = !(run || pct !== null); q.pb.classList.toggle('ind', run && pct === null);
    if (pct !== null) q.pu.style.width = clamp(pct, 0, 100) + '%'; else if (!run) q.pu.style.width = '100%';
    const fs = pr && Array.isArray(pr.files) ? pr.files.slice(-3).map(base).join(' · ') : '';
    set(q.fl, fs ? '✎ ' + fs : ''); q.fl.hidden = !fs;
    q.stop.hidden = !LIVE.has(c.status); q.stop.textContent = run ? 'Dayandır' : 'Ləğv et';
    q.res.hidden = !String(c.result || '').trim() || c.status === 'canceled';
    q.stop.parentNode.hidden = q.stop.hidden && q.res.hidden;
  }
  function tick() {
    if (typeof data === 'undefined' || !data || typeof ME === 'undefined' || !ME) return;
    const t = typeof now === 'function' ? now() : Date.now() / 1000;
    const mine = (data.commands || []).filter(c => c.user === ME.name && !gone.has(+c.id) && (LIVE.has(c.status) || (+c.finished_at && t - c.finished_at < 60))).slice(0, 6);
    const seen = new Set();
    for (const c of mine) {
      const id = +c.id; seen.add(id);
      let n = C.get(id); if (!n) { n = card(c); C.set(id, n); }
      if (n._st !== c.status) {
        if (LIVE.has(n._st) && !LIVE.has(c.status) && !RM.matches) { n.classList.remove('fl-ok', 'fl-bad'); void n.offsetWidth; n.classList.add(c.status === 'done' ? 'fl-ok' : 'fl-bad'); }
        n._st = c.status;
      }
      paint(n, c, t);
      if (!LIVE.has(c.status) && t - c.finished_at > 57) n.classList.add('fade');
    }
    for (const [id, n] of C) if (!seen.has(id)) { n.remove(); C.delete(id); }
    order(ag, mine.map(c => C.get(+c.id)), 1);                                              // yeni tapşırıqlar yuxarıda (feed DESC verir); 0-cı uşaq başlıqdır
    const live = mine.filter(c => LIVE.has(c.status)).length;
    agHead.textContent = (agMin ? '▸ ' : '▾ ') + 'CLAUDE CODE · ' + (live ? live + ' aktiv' : mine.length + ' bitdi');
    ag.hidden = !mine.length;
    fit();
  }
  setInterval(tick, 1000);

  /* ---------- Səsə görə parıltı: --jv-level (bir rAF dövrü, Jarvis sönülüdürsə dayanır) ---------- */
  let lv = 0, raf = 0, lastW = -1;
  const docSt = document.documentElement.style;
  function frame(ms) {
    const s = st();
    if (s === 'off' || s === 'error') { raf = 0; lv = 0; docSt.setProperty('--jv-level', '0'); lastW = 0; return; }
    let x = 0;
    if (typeof JV.level === 'function') x = +JV.level() || 0;
    else x = s === 'speaking' ? .35 + .25 * Math.sin(ms / 140) * Math.sin(ms / 53) : s === 'listening' ? .06 : s === 'tool' ? .2 + .1 * Math.sin(ms / 220) : 0;   // ehtiyat: level() yoxdursa
    lv += (clamp(x, 0, 1) - lv) * (x > lv ? .35 : .08);
    if (Math.abs(lv - lastW) > .01) { lastW = lv; docSt.setProperty('--jv-level', lv.toFixed(3)); }
    raf = requestAnimationFrame(frame);
  }
  function glow() { if (!raf && st() !== 'off' && st() !== 'error') raf = requestAnimationFrame(frame); }

  /* ---------- Günlük limit sayğacı (Jarvis panelinin başlığında) ---------- */
  const meter = E('span', 'jvh-cost'); meter.hidden = true; meter.title = 'Bu gün Jarvis-lə danışılan dəqiqələr / gündəlik limit';
  const stEl = JV.panel && JV.panel.querySelector('#jv-st'); if (stEl) stEl.after(meter);   // status yazısının yanında
  let costT = 0, costAt = 0;
  async function costNow() {
    if (!U.api) return; costAt = Date.now();
    try {
      const { status, d } = await U.api('status'); if (status !== 200 || !(+d.limit)) return;
      const u = Math.round(+d.used / 60), l = Math.round(+d.limit / 60), f = +d.used / +d.limit;
      meter.textContent = '⏱ ' + u + '/' + l + ' dəq'; meter.hidden = false;
      meter.classList.toggle('hi', f >= .8 && f < 1); meter.classList.toggle('over', f >= 1);
    } catch (e) { /* növbəti cəhd 30 san sonra */ }
  }
  function cost() {
    const on = st() !== 'off' && st() !== 'error';
    if (on && !costT) { if (Date.now() - costAt > 5000) costNow(); costT = setInterval(costNow, 30000); }
    else if (!on && costT) { clearInterval(costT); costT = 0; setTimeout(costNow, 1500); }   // son istifadə yazıldıqdan sonra bir dəfə təzələ
  }
  if (JV.button) JV.button.addEventListener('click', () => { if (!meter.textContent) costNow(); });

  /* ---------- Açılış (boot): halqalar + “J.A.R.V.I.S. ONLINE” + real məlumatdan sistem yoxlaması ---------- */
  let booted = false;
  function status() {
    const o = { agents: 0, users: 0, running: 0, queued: 0, burning: [], overdue: [], failing: [] };
    try {
      const us = data.users || []; o.users = us.length; o.agents = us.filter(u => agentInfo(u).online).length;
      o.running = (data.commands || []).filter(c => c.status === 'running').length; o.queued = (data.commands || []).filter(c => c.status === 'queued').length;
      o.burning = buildProjects().filter(p => burnOf(p.name) > 0).map(p => p.name);
      o.overdue = (data.tasks || []).filter(x => { const d = dueInfo(x); return d && d.left < 0; }).map(x => x.title + (x.assignee ? ' (' + x.assignee + ')' : ''));
      o.failing = (data.tests || []).filter(x => x.status === 'fail').map(x => x.project + ': ' + x.title);
      o.mine = (data.tasks || []).filter(x => x.assignee === ME.name && x.status !== 'done').length;
    } catch (e) { console.error('JarvisHUD status', e); }
    return o;
  }
  function bootLines(o) {
    const L = (k, v, lvl) => ({ t: '› ' + k.padEnd(24, '.') + ' ' + v, c: lvl });
    return [
      L('AGENTLƏR ONLAYN', o.agents + '/' + o.users, o.agents ? '' : 'w'),
      L('CLAUDE CODE İŞLƏYİR', o.running + (o.queued ? ' (+' + o.queued + ' növbədə)' : ''), ''),
      L('YANAN PLANETLƏR', String(o.burning.length), o.burning.length ? 'b' : ''),
      L('GECİKƏN SON TARİXLƏR', String(o.overdue.length), o.overdue.length ? 'w' : ''),
      L('UĞURSUZ TESTLƏR', String(o.failing.length), o.failing.length ? 'b' : ''),
    ];
  }
  function note(o) {
    const j = (a, n) => a.slice(0, n).join('; ') + (a.length > n ? '; …' : '');
    return '[System note] Boot complete. Status: agents online ' + o.agents + '/' + o.users + '; Claude Code tasks running ' + o.running + ', queued ' + o.queued +
      '; burning planets ' + o.burning.length + (o.burning.length ? ' (' + j(o.burning, 4) + ')' : '') +
      '; overdue tasks ' + o.overdue.length + (o.overdue.length ? ' (' + j(o.overdue, 3) + ')' : '') +
      '; failing tests ' + o.failing.length + (o.failing.length ? ' (' + j(o.failing, 3) + ')' : '') +
      '; my open tasks ' + (o.mine || 0) + '. Greet the user in one short sentence and mention only the single most important item.';
  }
  function bootFx(o, done) {
    const ov = E('div', 'jvh-boot'), inn = E('div', 'in'), svg = SV('svg', { viewBox: '0 0 200 200', 'aria-hidden': 'true' });
    [[92, 1.2, '', 0, ''], [78, 3, 'am', .15, '4 6'], [64, 1.5, 'sp', .3, '60 18'], [48, 5, '', .45, '2 5'], [32, 1.2, 'am sp', .6, '']].forEach(([r, w, c, dl, da]) => {
      const len = (2 * Math.PI * r).toFixed(0), ci = SV('circle', { cx: 100, cy: 100, r, 'stroke-width': w, class: c });
      ci.style.cssText = `--len:${len};stroke-dasharray:${da || len};stroke-dashoffset:0;animation-delay:${dl}s` + (c.includes('sp') ? `,${dl}s` : '');
      svg.append(ci);
    });
    const h2 = E('h2', null, 'J.A.R.V.I.S. '); h2.append(E('span', null, 'ONLINE'));
    const pre = E('pre'), hint = E('small', null, 'KEÇMƏK ÜÇÜN TOXUN · ESC');
    inn.append(svg, h2, pre, hint); ov.append(inn); document.body.append(ov);
    let end = false, tm = [];
    const fin = () => {
      if (end) return; end = true; tm.forEach(clearTimeout); removeEventListener('keydown', key, true);
      ov.classList.add('out'); setTimeout(() => ov.remove(), 480); done();
    };
    const key = e => { if (e.key === 'Escape') { e.stopPropagation(); fin(); } };
    ov.addEventListener('click', fin); addEventListener('keydown', key, true);
    let t = 750;
    for (const l of bootLines(o)) {
      const sp = E('span', l.c || null); tm.push(setTimeout(() => { pre.append(sp, '\n'); }, t));
      for (let i = 1; i <= l.t.length; i += 3) { const k = Math.min(i + 2, l.t.length); tm.push(setTimeout(() => { sp.textContent = l.t.slice(0, k); }, t + i * 6)); }
      t += 330;
    }
    tm.push(setTimeout(fin, Math.max(2600, t + 450)));
  }
  JV.on('ready', async () => {
    glow(); cost();
    if (booted) return; booted = true;
    const t0 = Date.now();
    if (typeof data === 'undefined' || !data) { try { await load(); } catch (e) {} }
    const o = status();
    const greet = () => { if (Date.now() - t0 < 5000 && (typeof JV.ready !== 'function' || JV.ready())) JV.say(note(o)); };
    if (RM.matches) greet(); else bootFx(o, greet);
  });
  JV.on('stop', () => { glow(); cost(); });

  /* Digər modullar üçün API: show(spec, {onClose}) → id, update(id, spec), close(id|'all'), list(), validate(spec); əlavə: relayout() — maneələr dəyişəndə yerləşdirməni dərhal yenilə */
  window.JarvisHUD = {
    show: (spec, opt) => show(spec, opt).id,
    update: (id, spec) => { update(id, spec); return String(id); },
    close: id => close(id === undefined ? 'all' : id),
    list, validate: spec => clean(spec), relayout: () => relayout(),
  };
})();
