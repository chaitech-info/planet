/* Jarvis Live: canlı sessiya təcrübəsi — aktivləşmə (orb + arc-reactor + uplink), ekran kənarında vəziyyət parıltısı,
   “düşünür…” göstəricisi, canlı altyazılar, alət fəaliyyəti çipləri, panelə mətn girişi + kontekstli sürətli əmrlər + ayarlar,
   sessiyanın sonu (orb düyməyə qayıdır + xülasə kartı), Alt+M. Yalnız window.Jarvis hadisələrini dinləyir; HUD/UI/Kainat olmasa da işləyir.
   DOM yalnız textContent ilə doldurulur (modeldən gələn mətn innerHTML-ə düşmür). Bir rAF dövrü: yalnız sessiya aktivkən/parıltı sönənədək. */
(() => {
  const JV = window.Jarvis; if (!JV || window.JarvisLive) return;
  const U = JV.util || {}, RM = matchMedia('(prefers-reduced-motion: reduce)');
  const E = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
  const $ = s => document.querySelector(s);

  /* ---------- Ayarlar (localStorage, try/catch) ---------- */
  const KEY = 'jv-live';
  const S = { snd: true, cap: true, glow: true };
  try { Object.assign(S, JSON.parse(localStorage.getItem(KEY) || '{}')); } catch (e) {}
  const save = () => { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} };

  /* ---------- Stil ---------- */
  const css = `
.jvl-edge { position: fixed; inset: 0; z-index: 59; pointer-events: none; opacity: 0; --c: 56,189,248; will-change: opacity; }
.jvl-edge i { position: absolute; inset: 0; overflow: hidden; box-shadow: inset 0 0 0 1.5px rgba(var(--c),.8), inset 0 0 22px 3px rgba(var(--c),.55), inset 0 0 120px 6px rgba(var(--c),.22); transition: box-shadow .5s ease; }
.jvl-edge i::after { content: ""; position: absolute; left: 0; right: 0; top: 0; height: 22vh; opacity: 0; background: linear-gradient(180deg, transparent, rgba(var(--c),.10) 70%, rgba(var(--c),.45) 98%, transparent); transform: translateY(-25vh); }
.jvl-edge[data-s="thinking"] { --c: 167,139,250; } .jvl-edge[data-s="thinking"] i { animation: jvl-shim 1.6s ease-in-out infinite; }
.jvl-edge[data-s="speaking"] { --c: 251,191,36; } .jvl-edge[data-s="tool"] { --c: 217,70,239; }
.jvl-edge[data-s="tool"] i::after { opacity: 1; animation: jvl-scan 1.8s cubic-bezier(.4,0,.6,1) infinite; }
.jvl-edge[data-s="error"] { --c: 248,113,113; } .jvl-edge[data-s="muted"] { --c: 148,163,184; }
@keyframes jvl-shim { 0%, 100% { opacity: .55; } 50% { opacity: 1; } }
@keyframes jvl-scan { to { transform: translateY(105vh); } }
.jvl-dim { position: fixed; inset: 0; z-index: 57; pointer-events: none; background: radial-gradient(circle at 50% 45%, rgba(8,30,48,.15), rgba(2,6,14,.62) 75%); opacity: 0; transition: opacity .5s ease; }
.jvl-dim.on { opacity: 1; }
.jvl-orb { position: fixed; left: 0; top: 0; width: 200px; height: 200px; z-index: 80; pointer-events: none; will-change: transform, opacity; opacity: 0; }
.jvl-orb svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; filter: drop-shadow(0 0 10px rgba(56,189,248,.75)); animation: jvl-up .9s cubic-bezier(.2,.8,.2,1) both; }
.jvl-orb circle { fill: none; stroke: #38bdf8; stroke-linecap: round; transform-origin: 100px 100px; }
.jvl-orb circle.am { stroke: #fbbf24; } .jvl-orb .r1 { animation: jvl-rot 2.4s linear infinite; } .jvl-orb .r2 { animation: jvl-rot 1.5s linear infinite reverse; } .jvl-orb .r3 { animation: jvl-rot 3.6s linear infinite; }
.jvl-orb .core { position: absolute; left: 50%; top: 50%; width: 64px; height: 64px; margin: -32px; border-radius: 50%; background: radial-gradient(circle, #fff 0 14%, rgba(125,211,252,.95) 26%, rgba(56,189,248,.35) 55%, transparent 72%); animation: jvl-core 1.1s ease-in-out infinite; }
.jvl-orb b { position: absolute; left: 50%; top: 50%; width: 4px; height: 4px; margin: -2px; border-radius: 50%; background: #7dd3fc; box-shadow: 0 0 6px #38bdf8; opacity: 0; animation: jvl-pt 1.3s ease-out infinite; animation-delay: var(--d); }
.jvl-orb.err svg { filter: drop-shadow(0 0 10px rgba(248,113,113,.9)); } .jvl-orb.err circle { stroke: #f87171; } .jvl-orb.err .core { background: radial-gradient(circle, #fff 0 12%, rgba(248,113,113,.9) 30%, transparent 70%); }
.jvl-orb.mini b, .jvl-orb.mini .jvl-up { display: none; }
@keyframes jvl-up { from { transform: scale(.55) rotate(-90deg); opacity: .3; } }
@keyframes jvl-rot { to { transform: rotate(360deg); } }
@keyframes jvl-core { 50% { transform: scale(1.12); opacity: .85; } }
@keyframes jvl-pt { 0% { transform: rotate(var(--a)) translateX(14px); opacity: 0; } 15% { opacity: 1; } 100% { transform: rotate(var(--a)) translateX(118px) scale(.4); opacity: 0; } }
.jvl-up { position: absolute; left: 50%; top: calc(100% + 18px); width: 300px; margin-left: -150px; display: grid; justify-items: center; gap: 6px; color: #7dd3fc; font: 11px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .28em; text-shadow: 0 0 10px rgba(56,189,248,.8); transition: opacity .3s ease; }
.jvl-up small { letter-spacing: .12em; color: rgba(186,230,253,.7); font-size: 10.5px; }
.jvl-up u { display: block; width: 180px; height: 2px; border-radius: 2px; background: linear-gradient(90deg, transparent, #38bdf8, #fbbf24, transparent) 0 0 / 50% 100% no-repeat, rgba(56,189,248,.15); animation: jvl-bar 1.1s linear infinite; }
@keyframes jvl-bar { from { background-position: -100% 0, 0 0; } to { background-position: 200% 0, 0 0; } }
#jv-btn.jvl-launch { animation: jvl-btn .7s ease; } #jv-btn.jvl-land { animation: jvl-btn .6s ease; }
@keyframes jvl-btn { 30% { transform: scale(1.12); box-shadow: 0 0 0 2px rgba(56,189,248,.8), 0 0 26px rgba(56,189,248,.7); } }
.jv .jv-top { position: relative; }
.jvl-rib { position: absolute; left: 0; right: 0; top: 0; height: 2px; overflow: hidden; background: rgba(var(--c),.35); --c: 100,116,139; transition: background-color .4s ease; z-index: 1; }
.jvl-rib::after { content: ""; position: absolute; inset: 0; width: 35%; background: linear-gradient(90deg, transparent, rgba(var(--c),1), transparent); transform: translateX(-100%); }
.jv[data-jvl] .jvl-rib::after { animation: jvl-rib 2.2s linear infinite; }
.jv[data-jvl="connecting"] .jvl-rib, .jv[data-jvl="listening"] .jvl-rib { --c: 56,189,248; } .jv[data-jvl="thinking"] .jvl-rib { --c: 167,139,250; }
.jv[data-jvl="speaking"] .jvl-rib { --c: 251,191,36; } .jv[data-jvl="tool"] .jvl-rib { --c: 217,70,239; } .jv[data-jvl="tool"] .jvl-rib::after { animation-duration: .9s; }
.jv[data-jvl="error"] .jvl-rib { --c: 248,113,113; } .jv[data-jvl="muted"] .jvl-rib { --c: 148,163,184; }
@keyframes jvl-rib { to { transform: translateX(290%); } }
.jvl-dots { position: absolute; left: 14px; top: 14px; width: 74px; height: 74px; display: flex; align-items: flex-end; justify-content: center; gap: 4px; padding-bottom: 6px; pointer-events: none; opacity: 0; transition: opacity .25s ease; }
.jvl-dots b { width: 5px; height: 5px; border-radius: 50%; background: #c4b5fd; box-shadow: 0 0 6px #a78bfa; animation: jvl-dot 1s ease-in-out infinite; } .jvl-dots b:nth-child(2) { animation-delay: .15s; } .jvl-dots b:nth-child(3) { animation-delay: .3s; }
.jv[data-jvl="thinking"] .jvl-dots { opacity: 1; }
@keyframes jvl-dot { 0%, 100% { transform: translateY(0); opacity: .45; } 40% { transform: translateY(-5px); opacity: 1; } }
.jvl-tm { white-space: nowrap; margin-left: 8px; font-size: 11px; font-weight: 400; letter-spacing: .04em; color: rgba(125,211,252,.85); font-variant-numeric: tabular-nums; }
.jvl-snd { border: 0; background: rgba(255,255,255,.08); color: #e9eef8; width: 30px; height: 30px; border-radius: 9px; cursor: pointer; font-size: 14px; flex: none; }
.jvl-qc { display: flex; gap: 6px; padding: 2px 14px 8px; overflow-x: auto; scrollbar-width: none; -webkit-mask-image: linear-gradient(90deg, #000 88%, transparent); mask-image: linear-gradient(90deg, #000 88%, transparent); }
.jvl-qc::-webkit-scrollbar { display: none; }
.jvl-qc button { flex: none; border: 1px solid rgba(125,211,252,.3); background: rgba(56,189,248,.08); color: #e0f2fe; border-radius: 999px; padding: 4px 10px; font: inherit; font-size: 12px; cursor: pointer; white-space: nowrap; transition: background-color .15s ease, transform .15s ease; }
.jvl-qc button:hover { background: rgba(56,189,248,.2); } .jvl-qc button:active { transform: scale(.96); }
.jvl-qc button.in { animation: jvl-cin .3s ease both; } @keyframes jvl-cin { from { opacity: 0; transform: translateY(4px); } }
.jvl-in { display: flex; gap: 6px; padding: 0 14px 4px; margin: 0; }
.jvl-in input { flex: 1; min-width: 0; border: 1px solid rgba(125,211,252,.25); background: rgba(255,255,255,.06); color: #e9eef8; border-radius: 999px; padding: 7px 12px; font: inherit; font-size: 13px; outline: none; transition: border-color .15s ease, box-shadow .15s ease; }
.jvl-in input:focus { border-color: rgba(125,211,252,.6); box-shadow: 0 0 0 3px rgba(56,189,248,.15); }
.jvl-in input::placeholder { color: rgba(148,163,184,.8); }
.jvl-in button { flex: none; width: 34px; height: 34px; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.07); color: #e9eef8; border-radius: 50%; cursor: pointer; font-size: 14px; padding: 0; }
.jvl-in button[type=submit] { background: linear-gradient(135deg,#0284c7,#d97706); border-color: transparent; }
.jvl-set { display: flex; flex-wrap: wrap; gap: 6px 14px; margin: 4px 14px 2px; padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(125,211,252,.2); background: rgba(56,189,248,.06); font-size: 12.5px; }
.jvl-set[hidden] { display: none; } .jvl-set label { display: flex; align-items: center; gap: 6px; cursor: pointer; } .jvl-set input { accent-color: #38bdf8; }
.jvl-low { position: fixed; left: var(--jvl-x, 50%); bottom: var(--jvl-b, 22px); z-index: 60; width: var(--jvl-w, min(680px, calc(100vw - 32px))); transform: translateX(-50%); display: flex; flex-direction: column; align-items: center; gap: 8px; pointer-events: none; }
.jvl-tick { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
.jvl-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px 4px 9px; border-radius: 999px; font-size: 12px; color: #f5e8ff; background: rgba(24,8,36,.78); border: 1px solid rgba(217,70,239,.45); box-shadow: 0 0 14px rgba(217,70,239,.3); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); transition: opacity .5s ease, transform .5s ease; animation: jvl-chin .35s cubic-bezier(.2,.9,.25,1) both; white-space: nowrap; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.jvl-chip.wait::after { content: ""; width: 10px; height: 10px; border-radius: 50%; border: 2px solid rgba(240,171,252,.35); border-top-color: #f0abfc; animation: jvl-rot .8s linear infinite; }
.jvl-chip.ok { border-color: rgba(74,222,128,.45); box-shadow: 0 0 14px rgba(74,222,128,.25); }
.jvl-chip.out { opacity: 0; transform: translateY(-8px) scale(.96); }
@keyframes jvl-chin { from { opacity: 0; transform: translateY(8px) scale(.9); } }
.jvl-cap { pointer-events: auto; max-width: 100%; padding: 9px 18px 10px; border-radius: 14px; text-align: center; line-height: 1.4; background: linear-gradient(180deg, rgba(56,189,248,.10), rgba(6,10,20,.80) 60%), rgba(6,10,20,.6); -webkit-backdrop-filter: blur(14px) saturate(1.3); backdrop-filter: blur(14px) saturate(1.3);
  border: 1px solid rgba(125,211,252,.28); box-shadow: 0 10px 30px rgba(0,0,0,.45), inset 0 0 18px rgba(56,189,248,.08); transition: opacity .4s ease, transform .4s ease; }
.jvl-cap.hide { opacity: 0; transform: translateY(10px); pointer-events: none; }
.jvl-cap .u { color: rgba(203,213,225,.72); font-size: 13.5px; font-style: italic; } .jvl-cap .u:empty, .jvl-cap .j:empty { display: none; }
.jvl-cap .j { color: #f8fbff; font-size: 16.5px; font-weight: 500; text-shadow: 0 0 12px rgba(56,189,248,.45); } .jvl-cap .u + .j { margin-top: 3px; }
.jvl-sum { position: fixed; left: calc(50% - min(160px, 50vw - 16px)); bottom: 18px; z-index: 62; width: min(320px, calc(100vw - 32px)); padding: 12px 14px 13px; border-radius: 6px 14px 6px 14px; color: #e6f6ff; font-size: 12.5px;
  background: linear-gradient(180deg, rgba(56,189,248,.13), rgba(6,12,24,.88) 40%); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border: 1px solid rgba(56,189,248,.38); box-shadow: 0 12px 34px rgba(0,0,0,.5), 0 0 22px rgba(56,189,248,.18);
  transition: opacity .45s ease, transform .45s ease; animation: jvl-sin .5s cubic-bezier(.2,.9,.25,1) both; }
.jvl-sum.out { opacity: 0; transform: translateY(12px); }
@keyframes jvl-sin { from { opacity: 0; transform: translateY(14px) scale(.97); } }
.jvl-sum header { display: flex; align-items: center; gap: 8px; margin: 0 0 9px; font-size: 11.5px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #7dd3fc; text-shadow: 0 0 8px rgba(56,189,248,.5); }
.jvl-sum header span { flex: 1; } .jvl-sum header button { border: 0; background: rgba(255,255,255,.07); color: #cfe9f7; width: 24px; height: 24px; border-radius: 7px; cursor: pointer; }
.jvl-sum .g { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; }
.jvl-sum .g div { border: 1px solid rgba(56,189,248,.2); background: rgba(56,189,248,.06); border-radius: 8px; padding: 5px 8px; }
.jvl-sum .g small { display: block; font-size: 10px; letter-spacing: .06em; text-transform: uppercase; color: rgba(203,225,240,.65); } .jvl-sum .g b { font-size: 16px; font-weight: 600; font-variant-numeric: tabular-nums; }
.jvl-sum .tl { margin-top: 8px; color: rgba(203,225,240,.8); line-height: 1.45; }
.jvl-sum .bar { margin-top: 10px; height: 2px; border-radius: 2px; background: rgba(56,189,248,.6); transform-origin: left; animation: jvl-life 8s linear forwards; } .jvl-sum:hover .bar { animation-play-state: paused; }
@keyframes jvl-life { to { transform: scaleX(0); } }
@media (max-width: 640px) {
  .jvl-sum { left: 16px; right: 16px; width: auto; bottom: auto; top: 70px; }
  .jvl-cap .j { font-size: 15px; } .jvl-cap { padding: 8px 12px; }
}
@media (prefers-reduced-motion: reduce) {
  .jvl-edge i, .jvl-edge i::after, .jvl-orb *, .jvl-rib::after, .jvl-dots b, .jvl-chip, .jvl-chip.wait::after, .jvl-qc button.in, .jvl-sum, #jv-btn.jvl-launch, #jv-btn.jvl-land { animation: none !important; }
  .jvl-edge[data-s="tool"] i::after { opacity: 0; } .jvl-cap, .jvl-chip, .jvl-sum { transition: opacity .2s linear; transform: none !important; }
}`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));

  /* ---------- Səslər (WebAudio, sintez; sakit) ---------- */
  let AC = null, MG = null;
  function ac() {
    if (!S.snd) return null;
    try {
      if (!AC) { AC = new (window.AudioContext || window.webkitAudioContext)(); MG = AC.createGain(); MG.gain.value = .06; const lp = AC.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 3200; MG.connect(lp); lp.connect(AC.destination); }
      if (AC.state === 'suspended') AC.resume().catch(() => {});
      return AC;
    } catch (e) { return null; }
  }
  function tone(c, f0, f1, at, dur, type, vol) {
    const o = c.createOscillator(), g = c.createGain(), t = c.currentTime + at;
    o.type = type || 'sine'; o.frequency.setValueAtTime(f0, t); if (f1 !== f0) o.frequency.exponentialRampToValueAtTime(f1, t + dur * .85);
    g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(vol, t + Math.min(.08, dur * .3)); g.gain.exponentialRampToValueAtTime(.0001, t + dur);
    o.connect(g); g.connect(MG); o.start(t); o.stop(t + dur + .05);
  }
  const SFX = {
    up() { const c = ac(); if (!c) return; tone(c, 110, 440, 0, .9, 'sine', .9); tone(c, 165, 660, .05, .85, 'triangle', .35); tone(c, 880, 880, .62, .5, 'sine', .45); tone(c, 1320, 1320, .7, .55, 'sine', .25); },
    ready() { const c = ac(); if (!c) return; tone(c, 660, 660, 0, .18, 'sine', .55); tone(c, 990, 990, .12, .32, 'sine', .45); },
    down() { const c = ac(); if (!c) return; tone(c, 660, 110, 0, .75, 'sine', .8); tone(c, 440, 82, .04, .7, 'triangle', .3); },
    tick() { const c = ac(); if (!c) return; tone(c, 1500, 1500, 0, .05, 'sine', .18); },
    err() { const c = ac(); if (!c) return; tone(c, 220, 180, 0, .25, 'triangle', .5); tone(c, 175, 140, .2, .3, 'triangle', .45); },
  };

  /* ---------- Vəziyyət ---------- */
  let fake = null;                                                    // _test üçün süni vəziyyət
  const st = () => fake || (typeof JV.state === 'function' ? JV.state() : 'off');
  const isOn = () => { const s = st(); return s !== 'off' && s !== 'error'; };
  let thinking = false, thinkT = 0, thinkSafe = 0, lastAct = 0, errAt = 0;
  const T = { t0: 0, ex: 0, tools: new Map(), cc: 0, bot: false };      // sessiya statistikası
  const panel = JV.panel, btn = JV.button;
  const eff = () => thinking && isOn() ? 'thinking' : st();

  /* ---------- Kənar parıltısı ---------- */
  const edge = E('div', 'jvl-edge'); edge.append(E('i')); edge.setAttribute('aria-hidden', 'true');
  const dim = E('div', 'jvl-dim'); document.body.append(edge, dim);

  /* ---------- Panel əlavələri ---------- */
  const rib = E('div', 'jvl-rib'), dots = E('i', 'jvl-dots'); dots.append(E('b'), E('b'), E('b'));
  const tm = E('span', 'jvl-tm'); tm.hidden = true;
  const snd = E('button', 'jvl-snd'); snd.type = 'button';
  const qc = E('div', 'jvl-qc'); qc.setAttribute('role', 'toolbar'); qc.setAttribute('aria-label', 'Sürətli əmrlər');
  const form = E('form', 'jvl-in'), inp = E('input'), go = E('button', null, '➤'), gear = E('button', null, '⚙');
  inp.type = 'text'; inp.placeholder = 'Yaz və ya danış…'; inp.maxLength = 500; inp.setAttribute('enterkeyhint', 'send'); inp.setAttribute('aria-label', 'Jarvis-ə yaz');
  go.type = 'submit'; go.title = 'Göndər'; go.setAttribute('aria-label', 'Göndər'); gear.type = 'button'; gear.title = 'Ayarlar'; gear.setAttribute('aria-label', 'Ayarlar'); gear.setAttribute('aria-expanded', 'false');
  form.append(inp, go, gear);
  const setBox = E('div', 'jvl-set'); setBox.hidden = true;
  const OPTS = [['snd', '🔈 Səslər'], ['cap', '💬 Altyazılar'], ['glow', '✨ Kənar parıltısı']], chk = {};
  for (const [k, l] of OPTS) { const lb = E('label'), c = E('input'); c.type = 'checkbox'; c.checked = !!S[k]; c.onchange = () => setOpt(k, c.checked); chk[k] = c; lb.append(c, l); setBox.append(lb); }
  function setOpt(k, v) {
    S[k] = !!v; save(); if (chk[k]) chk[k].checked = S[k]; paintSnd();
    if (k === 'cap' && !v) capHide(true);
    kick();
  }
  const paintSnd = () => { snd.textContent = S.snd ? '🔈' : '🔇'; snd.title = S.snd ? 'Səslər açıq' : 'Səslər bağlı'; snd.setAttribute('aria-label', snd.title); };
  snd.onclick = () => { setOpt('snd', !S.snd); if (S.snd) SFX.tick(); };
  gear.onclick = () => { setBox.hidden = !setBox.hidden; gear.setAttribute('aria-expanded', String(!setBox.hidden)); };
  paintSnd();
  if (panel) {
    panel.prepend(rib);
    const top = panel.querySelector('.jv-top'); if (top) top.append(dots);
    const x = panel.querySelector('#jv-close'); if (x) x.before(snd); else if (top) top.append(snd);
    const tb = panel.querySelector('.jv-ttl b'); if (tb) tb.append(tm);
    const bar = panel.querySelector('.jv-bar'); if (bar) bar.before(qc, form, setBox); else panel.append(qc, form, setBox);
  }

  /* ---------- Mətnlə göndərmə (bağlı deyilsə: başlat, hazır olanda göndər) ---------- */
  let pending = null, pendT = 0;
  function send(text) {
    text = String(text || '').replace(/\s+/g, ' ').trim().slice(0, 500); if (!text) return;
    if (U.line) U.line('u', text);
    capUser(text, true);
    if (JV.ready()) { JV.say('[User typed] ' + text); return; }
    pending = text; pendT = Date.now();
    if (!isOn()) { if (panel) panel.hidden = false; JV.start(); }
    if (U.line) U.line('i', 'Qoşulan kimi göndərəcəyəm…');
  }
  // Açılış salamı (HUD) bitənə qədər gözlə: boot örtüyü yoxdur, Jarvis danışmır/düşünmür, son fəaliyyətdən 0.9 san keçib (maks 12 san)
  function flush() {
    if (!pending || !JV.ready()) return;
    const busy = $('.jvh-boot') || thinking || st() === 'speaking' || st() === 'tool' || Date.now() - lastAct < 900;
    if (busy && Date.now() - pendT < 12000) return void setTimeout(flush, 250);
    const t = pending; pending = null; JV.say('[User typed] ' + t);
  }
  form.onsubmit = e => { e.preventDefault(); const v = inp.value; inp.value = ''; send(v); };

  /* Kontekstli sürətli əmrlər: planet / xəritə / lövhə / panel + həmişə missiyalar, protokollar */
  let qcKey = '';
  function quick() {
    let c = null; try { c = window.JarvisUI && typeof JarvisUI.context === 'function' ? JarvisUI.context() : null; } catch (e) {}
    const h = location.hash, view = c ? c.view : /^#lovhe/.test(h) ? 'board' : /^#kainat/.test(h) ? 'space' : 'panel';
    let late = 0; try { late = (data.tasks || []).filter(x => { const d = dueInfo(x); return d && d.left < 0 && x.status !== 'done'; }).length; } catch (e) {}
    const L = [], lateL = ['⏰', 'Gecikənlər' + (late ? ' (' + late + ')' : ''), 'Gecikən tapşırıqlar hansılardır?'];
    if (view === 'space' && c && c.planet && c.planet.name) {
      const n = c.planet.label || c.planet.name;
      L.push(['🪐', 'Bu planetin vəziyyəti', `“${n}” planetinin vəziyyəti necədir?`], ['📋', 'Tapşırıqları göstər', `“${n}” planetinin açıq tapşırıqlarını göstər`],
        ['🤖', 'Claude Code-a tapşırıq ver', `“${n}” proyektində Claude Code-a tapşırıq vermək istəyirəm — nə etməli olduğunu məndən soruş.`]);
    } else if (view === 'space') L.push(['☀️', 'Bugünkü xülasə', 'Bugünkü qısa xülasəni ver'], lateL, ['🛰', 'Bizə tur et', 'Bizə planetlər üzrə qısa tur et']);
    else if (view === 'board') L.push(['📌', 'Mənim tapşırıqlarım', 'Mənim açıq tapşırıqlarım hansılardır?'], lateL, ['☀️', 'Bugünkü xülasə', 'Bugünkü qısa xülasəni ver']);
    else L.push(['☀️', 'Bugünkü xülasə', 'Bugünkü qısa xülasəni ver'], lateL, ['🛰', 'Bizə tur et', 'Bizə planetlər üzrə qısa tur et']);
    L.push(['🚀', 'Missiyalar', 'Missiyaların vəziyyətini göstər'], ['🛡', 'Protokollar', 'Hansı protokollar var?']);
    const k = L.map(x => x[1] + x[2]).join('|'); if (k === qcKey) return; qcKey = k;
    qc.textContent = '';
    L.forEach(([ic, l, say], i) => {
      const b = E('button', RM.matches ? null : 'in', ic + ' ' + l); b.type = 'button'; b.title = say; b.style.animationDelay = i * 40 + 'ms';
      b.onclick = () => send(say); qc.append(b);
    });
  }
  setInterval(() => { if (panel && !panel.hidden && !document.hidden) quick(); }, 1500);
  if (btn) btn.addEventListener('click', () => setTimeout(quick, 0));
  addEventListener('hashchange', () => setTimeout(quick, 50));
  quick();

  /* ---------- Altyazılar + alət çipləri ---------- */
  const low = E('div', 'jvl-low'), tick = E('div', 'jvl-tick'), cap = E('div', 'jvl-cap hide'), cu = E('div', 'u'), cj = E('div', 'j');
  tick.setAttribute('data-jv-obstacle', ''); cap.setAttribute('data-jv-obstacle', ''); cap.setAttribute('aria-live', 'off'); cap.setAttribute('role', 'status');
  cap.append(cu, cj); low.append(tick, cap); document.body.append(low);
  let uTxt = '', jTxt = '', jShown = 0, capHideT = 0, newTurn = true;
  const tail = (s, n) => { if (s.length <= n) return s; const t = s.slice(-n), i = t.indexOf(' '); return '…' + (i > 0 && i < 20 ? t.slice(i + 1) : t); };
  const fromLog = (k, chunk) => {   // jarvis.js-in jurnal sətri əsas mənbədir (Grok tam transkripti “replace” ilə göndərir)
    const ns = panel ? panel.querySelectorAll('#jv-log .jv-l.' + k) : []; const n = ns[ns.length - 1], t = n && n.textContent, c = String(chunk || '').trim();
    return t && c && t.includes(c.slice(-20)) ? t : null;
  };
  function place() {   // Jarvis panelinin üstünə düşməsin: onun solundakı boşluqda mərkəzlə, yer yoxdursa üstünə qaldır
    let b = 22, w = Math.min(680, innerWidth - 32), x = innerWidth / 2;
    const r = panel && !panel.hidden ? panel.getBoundingClientRect() : null;
    if (r && r.width && x + w / 2 > r.left - 12) {
      const room = r.left - 28;
      if (room >= 340) { w = Math.min(w, room); x = Math.max(16 + w / 2, Math.min(innerWidth / 2, r.left - 12 - w / 2)); } else b = innerHeight - r.top + 12;
    }
    low.style.setProperty('--jvl-w', w + 'px'); low.style.setProperty('--jvl-b', b + 'px'); low.style.setProperty('--jvl-x', x + 'px');
  }
  const replace = () => { if (!cap.classList.contains('hide') || tick.childElementCount) place(); };
  addEventListener('resize', replace);
  if (window.ResizeObserver && panel) new ResizeObserver(replace).observe(panel);   // ayarlar açılanda / panel gizlənəndə
  function capShow() { if (!S.cap) return; clearTimeout(capHideT); if (cap.classList.contains('hide')) { place(); cap.classList.remove('hide'); } }
  function capHide(now) { clearTimeout(capHideT); if (now) { cap.classList.add('hide'); return; } capHideT = setTimeout(() => cap.classList.add('hide'), 4000); }
  function capUser(text, replace) {
    if (newTurn) { uTxt = ''; jTxt = ''; jShown = 0; cj.textContent = ''; newTurn = false; }
    uTxt = replace ? text : (fromLog('u', text) || uTxt + text);
    cu.textContent = tail(uTxt.replace(/\s+/g, ' ').trim(), 140); capShow();
  }
  function capBot(text) {
    if (newTurn) { uTxt = ''; cu.textContent = ''; jTxt = ''; jShown = 0; newTurn = false; }
    jTxt = (fromLog('j', text) || jTxt + text).replace(/\s+/g, ' ');
    if (RM.matches) { jShown = jTxt.length; cj.textContent = tail(jTxt.trim(), 170); }
    capShow(); kick();
  }
  function typeStep() {   // Jarvis-in sözləri bot parçaları ilə sinxron “yazılır”
    if (jShown >= jTxt.length) return false;
    jShown = Math.min(jTxt.length, jShown + Math.max(1, Math.ceil((jTxt.length - jShown) / 8)));
    cj.textContent = tail(jTxt.slice(0, jShown).trim(), 170); return true;
  }

  const TL = {
    navigate: a => ['🛰', 'Uçur: ' + (a.target || '')], point_at: a => ['🎯', 'Göstərir: ' + (a.target || '')], tour: () => ['🌌', 'Kainat turu'], stop_tour: () => ['⏹', 'Tur dayandı'],
    ui: a => ['🖥', 'Ekran: ' + [a.action, a.target].filter(Boolean).join(' · ')], highlight: a => ['✨', 'İşarələyir: ' + (a.target || '')], get_context: () => ['👁', 'Ekrana baxır'], list_ui_actions: () => ['📖', 'UI kataloqu'],
    get_overview: () => ['🌐', 'Ümumi vəziyyət'], get_project: a => ['🪐', 'Planet: ' + (a.name || '')], list_tasks: () => ['📋', 'Tapşırıqlar'], get_report: () => ['📊', 'Hesabat'],
    create_task: () => ['➕', 'Tapşırıq yaradıldı'], set_task_status: () => ['🔁', 'Status dəyişdi'], set_task_due: () => ['📅', 'Son tarix təyin olundu'], set_ship_deadline: () => ['🚀', 'Gəmi deadline-ı'],
    schedule_meeting: () => ['📆', 'Görüş planlandı'], record_test_result: () => ['🧪', 'Test nəticəsi yazıldı'], send_message: a => ['✉️', 'Mesaj → ' + (a.to || '')],
    run_claude_code: () => ['🤖', 'Claude Code-a göndərildi'], get_claude_tasks: () => ['🤖', 'Claude Code tapşırıqları'],
    dispatch_agents: (a, r) => ['🛸', ((r && r.queued) || '') + ' agent göndərildi'], plan_mission: () => ['🗺', 'Missiya planı'], start_mission: () => ['🚀', 'Missiya başladı'],
    mission_status: () => ['📡', 'Missiya vəziyyəti'], cancel_mission: () => ['⛔', 'Missiya ləğv olundu'], list_missions: () => ['🗂', 'Missiyalar'],
    list_protocols: () => ['🛡', 'Protokollar'], run_protocol: a => ['⚡', 'Protokol: ' + (a.name || '')], save_protocol: () => ['💾', 'Protokol saxlandı'],
    show_panel: a => ['🪟', 'Panel: ' + (a.title || '')], update_panel: () => ['🔄', 'Panel yeniləndi'], close_panels: () => ['🧹', 'Panellər bağlandı'], end_conversation: () => ['👋', 'Sağollaşır'],
  };
  const label = (n, a, r) => { let x; try { x = TL[n] ? TL[n](a || {}, r) : null; } catch (e) {} x = x || ['⚙', String(n || 'alət').replace(/_/g, ' ')]; return x[0] + ' ' + String(x[1]).trim().slice(0, 42); };
  let waitChip = null;
  function chip(text, cls, life) {
    if (tick.childElementCount >= 5) tick.firstElementChild.remove();
    const c = E('span', 'jvl-chip' + (cls ? ' ' + cls : ''), text); tick.append(c);
    if (!tick._p) { tick._p = 1; place(); }
    if (life) fade(c, life);
    return c;
  }
  function fade(c, ms) { setTimeout(() => { c.classList.add('out'); setTimeout(() => { c.remove(); if (!tick.childElementCount) tick._p = 0; }, 520); }, ms); }

  /* ---------- “Düşünür…” ---------- */
  const stEl = panel && panel.querySelector('#jv-st'); let stPrev = '';
  function think(on) {
    clearTimeout(thinkT); clearTimeout(thinkSafe);
    if (on === thinking) return; thinking = on;
    if (stEl) { if (on) { stPrev = stEl.textContent; stEl.textContent = 'düşünür…'; } else if (stEl.textContent === 'düşünür…') stEl.textContent = stPrev; }
    if (on) thinkSafe = setTimeout(() => think(false), 15000);
    paint();
  }
  const _say = JV.say;   // say() → model cavab hazırlayır: dərhal “düşünür”
  JV.say = t => { const r = _say(t); if (JV.ready()) { think(true); lastAct = Date.now(); } return r; };

  /* ---------- Vəziyyətin rəngi (panel + kənar) ---------- */
  function paint() {
    const s = eff();
    if (s !== 'off') edge.dataset.s = s;                      // sönəndə son rəngi saxla (yumşaq sönmə)
    if (panel) { if (s === 'off') delete panel.dataset.jvl; else panel.dataset.jvl = s; }
    kick();
  }

  /* ---------- Aktivləşmə orb-u ---------- */
  let orb = null, orbReady = 0, orbPhase = '', subT = 0;
  const ctr = el => { const r = el && el.getBoundingClientRect(); return r && r.width ? { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width } : null; };
  const pos = (p, s) => `translate(${(p.x - 100).toFixed(1)}px, ${(p.y - 100).toFixed(1)}px) scale(${s})`;
  function mkOrb(mini) {
    const o = E('div', 'jvl-orb' + (mini ? ' mini' : '')), NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 200 200'); svg.setAttribute('aria-hidden', 'true');
    [[92, 1.2, '', ''], [80, 3, 'am r2', '3 7'], [68, 1.6, 'r1', '70 22'], [54, 5, 'r3', '2 6'], [40, 1.4, 'am r1', '40 14']].forEach(([r, w, c, da]) => {
      const ci = document.createElementNS(NS, 'circle'); ci.setAttribute('cx', 100); ci.setAttribute('cy', 100); ci.setAttribute('r', r); ci.setAttribute('stroke-width', w);
      if (c) ci.setAttribute('class', c); if (da) ci.setAttribute('stroke-dasharray', da); svg.append(ci);
    });
    o.append(svg, E('i', 'core'));
    if (!mini && !RM.matches) for (let i = 0; i < 16; i++) { const b = E('b'); b.style.setProperty('--a', (i * 22.5 + (i % 3) * 7) + 'deg'); b.style.setProperty('--d', (i * 81 % 1300) + 'ms'); o.append(b); }
    if (!mini) { const up = E('div', 'jvl-up'), h = E('span', null, 'UPLİNK QURULUR'), sm = E('small', null, 'Təhlükəsiz kanal açılır…'); up.append(h, sm, E('u')); o.append(up); o._sm = sm; o._h = h; }
    document.body.append(o); return o;
  }
  function fly(el, kf, ms, ease) {
    if (!el.animate) { Object.assign(el.style, kf[kf.length - 1]); return Promise.resolve(); }
    const a = el.animate(kf, { duration: RM.matches ? Math.min(ms, 200) : ms, easing: ease || 'cubic-bezier(.2,.8,.2,1)', fill: 'forwards' });
    return a.finished.then(() => { Object.assign(el.style, kf[kf.length - 1]); a.cancel(); }).catch(() => {});
  }
  const centre = () => ({ x: innerWidth / 2, y: innerHeight * .44 });
  const kill = o => { if (o) { clearInterval(o._iv); o.remove(); } };
  function activate() {
    kill(orb); clearInterval(subT);
    const o = orb = mkOrb(false), c = centre(), from = ctr(btn) || { x: innerWidth - 60, y: 30 };
    orbPhase = 'up'; orbReady = Date.now() + (RM.matches ? 150 : 900);
    if (btn && !RM.matches) { btn.classList.remove('jvl-land', 'jvl-launch'); void btn.offsetWidth; btn.classList.add('jvl-launch'); setTimeout(() => btn.classList.remove('jvl-launch'), 800); }
    dim.classList.add('on'); SFX.up();
    if (RM.matches) { o.style.transform = pos(c, 1); fly(o, [{ opacity: 0 }, { opacity: 1 }], 200); }
    else fly(o, [{ transform: pos(from, .1), opacity: 0 }, { transform: pos(from, .2), opacity: 1, offset: .15 }, { transform: pos(c, 1), opacity: 1 }], 900);
    const SUB = ['Təhlükəsiz kanal açılır…', 'Token alınır…', 'Mikrofon kalibrlənir…', 'Neyron şəbəkə qoşulur…', 'Səs modeli oyanır…'];
    let i = 0; o._iv = setInterval(() => { if (o._sm) o._sm.textContent = SUB[++i % SUB.length]; }, 750);
  }
  function handover() {   // hazırdır: orb Jarvis panelinə uçur, sonra HUD boot (mövcud) işləyir
    const o = orb; if (!o || orbPhase !== 'up') return;
    orbPhase = 'fly';
    const wait = Math.max(0, orbReady - Date.now());
    if (o._h) o._h.textContent = 'UPLİNK HAZIRDIR';
    setTimeout(() => {
      clearInterval(o._iv); const up = o.querySelector('.jvl-up'); if (up) up.style.opacity = 0;
      dim.classList.remove('on');
      try { if (window.Kainat && Kainat.shock) Kainat.shock(0x7fe8ff); } catch (e) {}
      const to = ctr(panel && !panel.hidden && panel.querySelector('.jv-orb')) || ctr(btn);
      const c = centre(), s = to ? Math.max(.15, (to.w || 74) / 200) : .3;
      fly(o, [{ transform: pos(c, 1), opacity: 1 }, { transform: pos(c, 1.08), opacity: 1, offset: .18 }, { transform: pos(to || c, s), opacity: .9, offset: .85 }, { transform: pos(to || c, s * .8), opacity: 0 }], 750, 'cubic-bezier(.5,0,.2,1)')
        .then(() => { if (orb === o) { orb = null; orbPhase = ''; } kill(o); });
    }, wait);
  }
  function abort() {   // qoşulma alınmadı: qırmızı, sönür
    const o = orb; if (!o) return; orb = null; orbPhase = '';
    clearInterval(o._iv); o.classList.add('err'); if (o._h) o._h.textContent = 'UPLİNK ALINMADI'; dim.classList.remove('on'); SFX.err();
    setTimeout(() => fly(o, [{ opacity: 1, transform: o.style.transform || pos(centre(), 1) }, { opacity: 0, transform: pos(centre(), .6) }], 500).then(() => kill(o)), 700);
  }
  function collapse() {   // sessiya bitdi: orb düyməyə qayıdır
    const to = ctr(btn); let o = orb, from;
    if (o) { clearInterval(o._iv); o.classList.add('mini'); from = centre(); }
    else { from = ctr(panel && !panel.hidden && panel.querySelector('.jv-orb')); if (!from || RM.matches) return SFX.down(); o = mkOrb(true); }
    orb = null; orbPhase = ''; dim.classList.remove('on'); SFX.down();
    const s0 = from.w ? from.w / 200 : 1;
    fly(o, [{ transform: pos(from, s0), opacity: 1 }, { transform: pos(to || from, .1), opacity: .2 }], RM.matches ? 150 : 650, 'cubic-bezier(.6,0,.8,.4)').then(() => {
      kill(o);
      if (btn && !RM.matches) { btn.classList.remove('jvl-land', 'jvl-launch'); void btn.offsetWidth; btn.classList.add('jvl-land'); setTimeout(() => btn.classList.remove('jvl-land'), 700); }
    });
  }

  /* ---------- Sessiya xülasəsi ---------- */
  let sumEl = null;
  const dur = ms => { const s = Math.max(0, Math.round(ms / 1000)), m = Math.floor(s / 60); return m >= 60 ? Math.floor(m / 60) + ' st ' + (m % 60) + ' dəq' : m + ':' + String(s % 60).padStart(2, '0'); };
  function summary() {
    const t0 = T.t0; if (!t0) return;
    if (sumEl) sumEl.remove();
    const n = sumEl = E('section', 'jvl-sum'), hd = E('header'), x = E('button', null, '✕'), g = E('div', 'g');
    n.setAttribute('role', 'status'); n.setAttribute('data-jv-obstacle', '');
    x.type = 'button'; x.setAttribute('aria-label', 'Bağla'); hd.append(E('span', null, '◉ Sessiya bitdi'), x);
    const cell = (l, v) => { const d = E('div'), b = E('b', null, v); d.append(E('small', null, l), b); g.append(d); return b; };
    cell('Müddət', dur(Date.now() - t0)); cell('Mübadilə', String(T.ex)); cell('Claude Code', String(T.cc)); const today = cell('Bu gün', '…');
    n.append(hd, g);
    const top = [...T.tools.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3);
    if (top.length) n.append(E('div', 'tl', 'Alətlər: ' + top.map(([k, v]) => label(k, {}).replace(/:\s*$/, '') + (v > 1 ? ' ×' + v : '')).join(' · ')));
    n.append(E('div', 'bar')); document.body.append(n);
    let left = 8000, at = Date.now(), tmr = 0;
    const close = () => { clearTimeout(tmr); if (sumEl === n) sumEl = null; n.classList.add('out'); setTimeout(() => n.remove(), 460); };
    const arm = () => { at = Date.now(); tmr = setTimeout(close, left); };
    n.onmouseenter = () => { clearTimeout(tmr); left -= Date.now() - at; }; n.onmouseleave = arm;
    x.onclick = close; arm();
    if (U.api) setTimeout(async () => {   // son istifadə yazılandan sonra
      try { const { status, d } = await U.api('status'); if (status === 200 && d.used != null) today.textContent = Math.round(+d.used / 60) + (+d.limit ? '/' + Math.round(+d.limit / 60) : '') + ' dəq'; else today.textContent = '—'; }
      catch (e) { today.textContent = '—'; }
    }, 1500); else today.textContent = '—';
  }

  /* ---------- Bir rAF dövrü ---------- */
  let raf = 0, lv = 0, eo = 0, eoW = -1, sec = -1;
  function loop() {
    raf = 0;
    const s = st(), on = isOn() || (s === 'error' && Date.now() - errAt < 2500), e = eff();
    const x = on && typeof JV.level === 'function' && !RM.matches ? +JV.level() || 0 : 0;
    lv += (x - lv) * (x > lv ? .35 : .1);
    const tgt = !S.glow || !on ? 0 : e === 'speaking' ? .4 + lv * .6 : e === 'listening' ? .32 + lv * .35 : e === 'muted' ? .22 : e === 'connecting' ? .5 : e === 'error' ? .8 : .7;
    eo += (tgt - eo) * (RM.matches ? 1 : tgt > eo ? .2 : .07);
    if (Math.abs(eo - eoW) > .004) { eoW = eo; edge.style.opacity = eo < .005 ? '0' : eo.toFixed(3); }
    const typing = typeStep();
    const t0 = T.t0;
    if (isOn() && t0) { const k = Math.floor((Date.now() - t0) / 1000); if (k !== sec) { sec = k; tm.textContent = '⏱ ' + dur(k * 1000); tm.hidden = false; } }
    else if (!tm.hidden) { tm.hidden = true; sec = -1; }
    if (on || eo > .005 || typing) raf = requestAnimationFrame(loop);
  }
  function kick() { if (!raf) raf = requestAnimationFrame(loop); }
  document.addEventListener('visibilitychange', () => { if (!document.hidden) kick(); });

  /* ---------- Hadisələr ---------- */
  let live = false;
  function end() {   // stop hadisəsi, ya da bağlantı özü bağlandı (state off/error, stop hadisəsi olmadan)
    if (!live) return; live = false;
    think(false); clearTimeout(thinkT); pending = null;
    if (waitChip) { fade(waitChip, 0); waitChip = null; }
    if (T.t0 || orb) collapse();
    clearTimeout(capHideT); cap.classList.add('hide');   // sessiya bitdi: altyazı xülasəni örtməsin
    summary(); T.t0 = 0;
  }
  const H = {
    start() {
      live = true; T.t0 = 0; T.ex = 0; T.tools.clear(); T.cc = 0; T.bot = false; newTurn = true;
      if (sumEl) { sumEl.remove(); sumEl = null; }
      activate(); paint();
    },
    ready() { T.t0 = Date.now(); lastAct = Date.now(); handover(); SFX.ready(); paint(); if (pending) setTimeout(flush, 600); },
    state(s) {
      if (s === 'speaking' || s === 'tool') think(false);
      if (s === 'tool' && !waitChip) waitChip = chip('⚙ icra edir…', 'wait');
      if (s !== 'tool' && waitChip) { const w = waitChip; waitChip = null; if (w.classList.contains('wait')) fade(w, 0); }
      if (s === 'error') { errAt = Date.now(); if (orbPhase === 'up') abort(); }
      if (s === 'off' || s === 'error') end();
      paint();
    },
    user(text) {
      lastAct = Date.now(); T.bot = false; capUser(String(text || ''));
      think(false); clearTimeout(thinkT);
      thinkT = setTimeout(() => { const s = st(); if (!T.bot && isOn() && s !== 'speaking' && s !== 'tool') think(true); }, 700);
    },
    bot(text) { lastAct = Date.now(); clearTimeout(thinkT); think(false); T.bot = true; capBot(String(text || '')); },
    turn() {
      lastAct = Date.now(); clearTimeout(thinkT); think(false);
      if (T.bot) T.ex++; T.bot = false; newTurn = true;
      if (!cap.classList.contains('hide')) capHide();
    },
    interrupted() { jTxt = ''; jShown = 0; cj.textContent = ''; newTurn = true; think(false); },
    tool(name, args, res) {
      lastAct = Date.now(); think(false);
      T.tools.set(name, (T.tools.get(name) || 0) + 1);
      const r = res || {};
      T.cc += name === 'run_claude_code' ? 1 : name === 'dispatch_agents' ? (+r.queued || 0) : name === 'start_mission' || name === 'run_protocol' ? (+r.launched || 0) : 0;
      const t = label(name, args, r);
      if (waitChip && waitChip.classList.contains('wait')) { waitChip.textContent = t; waitChip.className = 'jvl-chip ok'; const w = waitChip; waitChip = null; fade(w, 2500); }
      else chip(t, 'ok', 2500);
      SFX.tick();
    },
    error() { /* bağlantı xətası state('error') ilə gəlir; burada yalnız məlumat */ },
    stop() { end(); paint(); },
  };
  for (const k of Object.keys(H)) JV.on(k, (...a) => H[k](...a));

  /* ---------- Klaviatura: Alt+M mikrofon, Esc altyazını bağlayır ---------- */
  addEventListener('keydown', e => {
    if (e.altKey && e.code === 'KeyM' && isOn()) { e.preventDefault(); const m = document.getElementById('jv-mic'); if (m) m.click(); return; }
    if (e.key === 'Escape') { if (!cap.classList.contains('hide')) capHide(true); if (!setBox.hidden) gear.click(); }
  });

  /* Test qarmağı (real səs sessiyası açmadan): _test('state','connecting'), _test('start'), _test('ready'), _test('user','salam'),
     _test('bot','...'), _test('tool','navigate',{target:'neo'},{ok:true}), _test('turn'), _test('stop'), _test('reset') */
  window.JarvisLive = {
    settings: () => ({ ...S }), set: setOpt, send, quick,
    _test(ev, ...a) {
      if (ev === 'reset') { fake = null; paint(); return; }
      if (ev === 'state') { fake = a[0]; return H.state(a[0]); }
      if (ev === 'start') { fake = 'connecting'; H.state('connecting'); return H.start(); }
      if (ev === 'ready') { fake = 'listening'; H.state('listening'); return H.ready({}); }
      if (ev === 'stop') { H.stop(); fake = 'off'; return H.state('off'); }
      if (H[ev]) return H[ev](...a);
    },
    _dump: () => ({ state: st(), eff: eff(), thinking, edge: edge.dataset.s, edgeOpacity: edge.style.opacity, panel: panel && panel.dataset.jvl, orb: !!orb, orbPhase,
      dim: dim.classList.contains('on'), cap: { visible: !cap.classList.contains('hide'), u: cu.textContent, j: cj.textContent }, chips: [...tick.children].map(c => c.textContent),
      summary: sumEl ? sumEl.textContent : null, stats: { ex: T.ex, cc: T.cc, tools: Object.fromEntries(T.tools) }, raf: !!raf, quick: [...qc.children].map(b => b.textContent) }),
  };
})();
