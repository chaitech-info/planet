/* ====== MEDIA Timeline: OpenCut tipli redaktor (Edit Media → 🎞 Timeline) ======
   Layihə = timeline JSON (system/MEDIA_TIMELINE.md). Brauzer burada redaktə edir, Claude planet-media MCP ilə eyni layihəni
   redaktə edir (media.php → project_apply). Hər dəyişiklik versiyadır; başqası dəyişəndə (media.project hadisəsi) redaktor
   son versiyanı canlı yükləyir. Önizləmə və eksport eyni çəkmə funksiyasından keçir (tlDraw). */
(() => {
  const css = `
.mxe [hidden] { display: none !important; }
.mxe.tl-on { grid-template-rows: auto minmax(0, 1fr) clamp(210px, 34vh, 360px); }
.mxe.tl-on .mxe-hist { display: flex; flex-direction: column; padding: 0; min-height: 0; }
.tl-stage { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; gap: 8px; min-height: 0; height: 100%; }
.tl-stage[hidden], .tl-foot[hidden] { display: none !important; }
.tl-proj { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; min-width: 0; }
.tl-proj select, .tl-proj input { background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 6px 9px; font: inherit; font-size: 13px; min-width: 0; }
.tl-proj select option { color: #111; } .tl-proj select { max-width: 260px; }
.tl-proj .gbtn { padding: 5px 10px; font-size: 12.5px; }
.tl-proj .st { font-size: 12px; color: var(--muted); margin-left: auto; white-space: nowrap; }
.tl-proj .st[data-s="err"] { color: #fca5a5; } .tl-proj .st[data-s="remote"] { color: #fde68a; }
.tl-canvas-box { position: relative; display: grid; place-items: center; min-height: 0; border-radius: 16px; background: repeating-conic-gradient(rgba(255,255,255,.035) 0 25%, transparent 0 50%) 0 0 / 24px 24px, rgba(0,0,0,.35); border: 1px solid rgba(255,255,255,.08); overflow: hidden; }
.tl-canvas-box canvas { position: absolute; inset: 0; margin: auto; max-width: calc(100% - 16px); max-height: calc(100% - 16px); box-shadow: 0 10px 40px rgba(0,0,0,.55); background: #000; cursor: pointer; }
.tl-canvas-box.flash { animation: tlflash 1.2s ease; }
@keyframes tlflash { 0% { box-shadow: inset 0 0 0 3px rgba(251,191,36,.9); } 100% { box-shadow: inset 0 0 0 3px rgba(251,191,36,0); } }
.tl-empty { display: grid; place-items: center; align-content: center; gap: 10px; text-align: center; color: rgba(233,233,243,.8); padding: 24px; }
.tl-empty b { font-size: 18px; color: #fff; } .tl-empty .row { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; }
.tl-bar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; padding: 6px 12px; border-bottom: 1px solid rgba(255,255,255,.08); }
.tl-bar .gbtn { padding: 4px 10px; font-size: 12.5px; min-width: 34px; }
.tl-bar .tm { font: 600 12.5px ui-monospace, Menlo, monospace; color: #e9e9f3; min-width: 120px; text-align: center; }
.tl-bar .sp { flex: 1; } .tl-bar input[type=range] { width: 110px; accent-color: #ff7a3d; }
.tl-bar select { background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 9px; padding: 4px 6px; font: inherit; font-size: 12.5px; } .tl-bar select option { color: #111; }
.tl-scroll { position: relative; flex: 1; overflow: auto; min-height: 0; overscroll-behavior: contain; }
.tl-inner { position: relative; min-width: 100%; }
.tl-ruler { position: sticky; top: 0; z-index: 3; height: 22px; display: flex; background: #0b0c16; border-bottom: 1px solid rgba(255,255,255,.08); }
.tl-ruler .hd { position: sticky; left: 0; z-index: 2; flex: none; width: 132px; background: #0b0c16; }
.tl-ruler .rl { position: relative; flex: none; cursor: col-resize; }
.tl-ruler .tk { position: absolute; top: 0; bottom: 0; border-left: 1px solid rgba(255,255,255,.18); font: 10px ui-monospace, Menlo, monospace; color: rgba(233,233,243,.55); padding-left: 3px; line-height: 22px; white-space: nowrap; }
.tl-ruler .tk.m { top: 14px; color: transparent; }
.tl-row { display: flex; height: 46px; border-bottom: 1px solid rgba(255,255,255,.06); }
.tl-row.caption, .tl-row.text { height: 38px; } .tl-row.audio { height: 40px; }
.tl-row .hd { position: sticky; left: 0; z-index: 2; flex: none; width: 132px; display: flex; align-items: center; gap: 4px; padding: 0 6px; background: #0e0f1b; border-right: 1px solid rgba(255,255,255,.08); font-size: 12px; overflow: hidden; }
.tl-row .hd .nm { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tl-row .hd button { border: 0; background: none; color: rgba(233,233,243,.7); cursor: pointer; font-size: 13px; padding: 2px; border-radius: 6px; }
.tl-row .hd button.off { opacity: .35; }
.tl-lane { position: relative; flex: none; }
.tl-lane.drop { background: rgba(255,122,61,.08); }
.tl-clip { position: absolute; top: 4px; bottom: 4px; border-radius: 8px; overflow: hidden; cursor: grab; display: flex; align-items: center; padding: 0 9px; font-size: 11.5px; color: #fff; white-space: nowrap; text-overflow: ellipsis; border: 1px solid rgba(255,255,255,.18); user-select: none; touch-action: none; background-size: auto 100%; background-repeat: repeat-x; }
.tl-clip[data-type="video"] { background-color: #1d4ed8; } .tl-clip[data-type="image"] { background-color: #0f766e; }
.tl-clip[data-type="audio"] { background-color: #7c3aed; } .tl-clip[data-type="text"] { background-color: #c2410c; } .tl-clip[data-type="caption"] { background-color: #be185d; }
.tl-clip .lb { overflow: hidden; text-overflow: ellipsis; text-shadow: 0 1px 2px rgba(0,0,0,.7); pointer-events: none; position: relative; }
.tl-clip.sel { outline: 2px solid #fbbf24; outline-offset: 0; z-index: 1; }
.tl-clip.dragging { opacity: .85; cursor: grabbing; z-index: 2; }
.tl-clip .h { position: absolute; top: 0; bottom: 0; width: 8px; cursor: ew-resize; background: rgba(255,255,255,.0); }
.tl-clip .h:hover, .tl-clip.sel .h { background: rgba(255,255,255,.28); } .tl-clip .h.l { left: 0; } .tl-clip .h.r { right: 0; }
.tl-ph { position: absolute; top: 0; bottom: 0; width: 2px; background: #fbbf24; z-index: 4; pointer-events: none; box-shadow: 0 0 6px rgba(251,191,36,.7); }
.tl-ph::before { content: ""; position: absolute; top: 0; left: -5px; border: 6px solid transparent; border-top-color: #fbbf24; }
.tl-sec { padding: 10px 12px; border-radius: 14px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); margin-bottom: 10px; }
.tl-sec h4 { margin-top: 0 !important; }
.tl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; }
.tl-grid label.fl { margin-top: 8px !important; }
.tl-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.tl-chips button { border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.05); color: #e9e9f3; border-radius: 999px; padding: 4px 10px; font: inherit; font-size: 12.5px; cursor: pointer; }
.tl-chips button.on { background: rgba(255,122,61,.2); border-color: rgba(255,122,61,.6); }
.tl-acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; } .tl-acts .gbtn { padding: 4px 10px; font-size: 12.5px; }
.tl-ver { display: grid; gap: 4px; max-height: 220px; overflow: auto; margin-top: 6px; }
.tl-ver button { display: flex; gap: 8px; text-align: left; border: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.03); color: #ececf1; border-radius: 9px; padding: 5px 8px; font: inherit; font-size: 12px; cursor: pointer; }
.tl-ver button b { color: #ff9a6b; flex: none; } .tl-ver button span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); }
@media (max-width: 900px) {
  .mxe.tl-on { display: flex; flex-direction: column; overflow-y: auto; }
  .mxe.tl-on .mxe-body { display: contents; }
  .mxe.tl-on .mxe-top, .mxe.tl-on .mxe-stage, .mxe.tl-on .mxe-lib, .mxe.tl-on .mxe-ctl { flex: none; }
  .mxe.tl-on .mxe-stage { display: block; } .mxe.tl-on .tl-stage { display: grid; grid-template-rows: auto auto auto; }
  .mxe.tl-on .mxe-top { order: 0; position: sticky; top: 0; z-index: 5; }
  .mxe.tl-on .mxe-stage { order: 1; min-height: 0; padding: 10px 12px 0; }
  .mxe.tl-on .mxe-hist { order: 2; height: 320px; flex: none; }
  .mxe.tl-on .mxe-lib { order: 3; } .mxe.tl-on .mxe-ctl { order: 4; }
  .tl-stage { height: auto; } .tl-canvas-box { height: 46vh; }
  .tl-row .hd, .tl-ruler .hd { width: 92px; }
}`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
})();

const TL = { list: null, proj: null, t: null, ver: 0, sel: null, ph: 0, playing: false, zoom: 60, undo: [], redo: [], dirty: false, saving: false,
  saveTm: 0, media: new Map(), probe: new Map(), built: false, snap: true, note: '', exports: [], lastPoll: 0, sentSig: '' };
const TL_LS = 'mx:tlproj', TL_HD = () => (innerWidth <= 900 ? 92 : 132);
const TL_FONTS = ['Inter', 'Arial', 'Helvetica', 'Georgia', 'Impact', 'Verdana', 'Courier New', 'Times New Roman'];
const TL_ANIMS = [['none', 'yox'], ['fade', 'fade'], ['pop', 'pop'], ['slide-up', 'yuxarı sürüş'], ['typewriter', 'yazı maşını']];
const TL_PRESET = [['landscape', '16:9', 1920, 1080], ['reels', '9:16', 1080, 1920], ['square', '1:1', 1080, 1080], ['portrait', '4:5', 1080, 1350]];
const TL_KIND_OF = { video: 'video', image: 'video', audio: 'audio', text: 'text', caption: 'caption' };
const TL_ICON = { video: '🎞', audio: '🎵', text: '🔤', caption: '💬' };
const tlRid = p => p + Math.random().toString(16).slice(2, 8);
const tlFmt = s => { s = Math.max(0, s || 0); const m = Math.floor(s / 60), r = s - m * 60; return String(m).padStart(2, '0') + ':' + r.toFixed(1).padStart(4, '0'); };
const tlClone = o => JSON.parse(JSON.stringify(o));
const tlName = u => decodeURIComponent(String(u || '').split(/[\/=]/).pop() || '').slice(0, 40);

/* ---------- Timeline əməliyyatları (lokal; sonra project_save) ---------- */
function tlFix(t) {
  let end = 0;
  for (const tr of t.tracks) { tr.clips.sort((a, b) => a.start - b.start); for (const c of tr.clips) end = Math.max(end, c.start + c.dur); }
  t.duration = Math.round(end * 1000) / 1000;
}
function tlFind(id) { for (const tr of TL.t ? TL.t.tracks : []) { const c = tr.clips.find(x => x.id === id); if (c) return { tr, c }; } return null; }
/** Dəyişiklik: geri alma nöqtəsi + avtomatik saxlama. coalesce: eyni sahənin ardıcıl dəyişikliyi bir geri alma addımıdır */
function tlChange(fn, note, coalesce) {
  if (!TL.t) return;
  const now = Date.now();
  if (!(coalesce && TL._co === coalesce && now - TL._coAt < 1200)) { TL.undo.push(JSON.stringify(TL.t)); if (TL.undo.length > 80) TL.undo.shift(); TL.redo = []; }
  TL._co = coalesce || ''; TL._coAt = now;
  fn(TL.t); tlFix(TL.t);
  TL.note = note || 'redaktə';
  tlDirty();
}
function tlDirty() {
  TL.dirty = true; clearTimeout(TL.saveTm); TL.saveTm = setTimeout(tlSave, 700);
  tlStatus('Saxlanır…'); tlDrawTimeline(); tlFrame(); tlProps();
}
async function tlSave() {
  if (!TL.proj || !TL.dirty) return;
  if (TL.saving) { clearTimeout(TL.saveTm); TL.saveTm = setTimeout(tlSave, 400); return; }
  TL.saving = true; const sent = JSON.stringify(TL.t); TL.dirty = false;
  const { status, d } = await MED('project_save', { id: TL.proj.id, timeline: JSON.parse(sent), base_version: TL.ver, note: TL.note });
  TL.saving = false;
  if (status === 200) {
    TL.ver = d.project.version; TL.proj = { ...TL.proj, ...d.project };
    if (!TL.dirty && JSON.stringify(TL.t) === sent) { TL.t = d.project.timeline; }   // serverin təmizlənmiş versiyası
    tlStatus('✓ Saxlandı · v' + TL.ver);
    if (TL.dirty) tlSave();
  } else if (status === 409 && d.project) {
    tlAdopt(d.project, '⚠️ Layihə bu arada dəyişdi: son versiya yükləndi (' + (d.project.updated_by || '') + ')');
  } else {
    tlStatus('Saxlanmadı: ' + (d.error || status), 'err'); miniToast('❌ ' + (d.error || 'Saxlanmadı'));
    if (status === 400 && TL.undo.length) { TL.t = JSON.parse(TL.undo.pop()); tlDrawTimeline(); tlFrame(); tlProps(); }
  }
}
function tlStatus(t, s) { const e = TL.ui && TL.ui.st; if (e) { e.textContent = t; e.dataset.s = s || ''; } }
/** Serverdəki versiyanı götür (başqası/Claude dəyişib) */
function tlAdopt(p, msg) {
  TL.t = p.timeline; TL.ver = p.version; TL.proj = { ...TL.proj, ...p }; TL.dirty = false; clearTimeout(TL.saveTm);
  if (TL.sel && !tlFind(TL.sel)) TL.sel = null;
  if (TL.ph > TL.t.duration) TL.ph = TL.t.duration;
  tlDrawTimeline(); tlFrame(); tlProps(true);
  tlStatus(msg ? '🔄 Yeniləndi · v' + TL.ver : '✓ v' + TL.ver, msg ? 'remote' : '');
  if (msg) { miniToast(msg); const b = TL.ui && TL.ui.box; if (b) { b.classList.remove('flash'); void b.offsetWidth; b.classList.add('flash'); } }
}
function tlUndo(redo) {
  const from = redo ? TL.redo : TL.undo, to = redo ? TL.undo : TL.redo;
  if (!from.length || !TL.t) return;
  to.push(JSON.stringify(TL.t)); TL.t = JSON.parse(from.pop()); tlFix(TL.t); TL.note = redo ? 'yenidən et' : 'geri al';
  if (TL.sel && !tlFind(TL.sel)) TL.sel = null;
  tlDirty();
}
function tlTrackFor(kind, prefer) {
  const t = TL.t; let tr = prefer && t.tracks.find(x => x.id === prefer && x.kind === kind);
  if (!tr) tr = t.tracks.find(x => x.kind === kind);
  if (!tr) { tr = { id: tlRid('t_'), kind, name: { video: 'Video', audio: 'Səs', text: 'Mətn', caption: 'Altyazı' }[kind], muted: false, hidden: false, clips: [] }; kind === 'audio' ? t.tracks.unshift(tr) : t.tracks.push(tr); }
  return tr;
}
function tlProbe(src, type) {
  if (TL.probe.has(src)) return TL.probe.get(src);
  const p = new Promise(res => {
    const m = document.createElement(type === 'audio' ? 'audio' : 'video'); m.preload = 'metadata'; m.muted = true; m.crossOrigin = 'anonymous';
    const done = v => { res(isFinite(v) && v > 0 ? Math.round(v * 1000) / 1000 : 0); m.removeAttribute('src'); m.load(); };
    m.onloadedmetadata = () => done(m.duration); m.onerror = () => done(0); setTimeout(() => done(0), 8000); m.src = src;
  });
  TL.probe.set(src, p); return p;
}
/** Kitabxanadan media əlavə et (klik: oynatma xəttində, sürüşdür: atılan yerdə) */
async function tlAddMedia(m, o = {}) {
  if (!TL.t) { miniToast('Əvvəlcə layihə yarat və ya aç'); return; }
  const type = m.type === 'audio' ? 'audio' : m.type === 'video' ? 'video' : 'image';
  const srcDur = type === 'image' ? 0 : await tlProbe(m.url, type);
  const dur = type === 'image' ? 4 : Math.min(srcDur || 5, 600);
  let id = null;
  tlChange(t => {
    const tr = tlTrackFor(TL_KIND_OF[type], o.track);
    let start = o.start != null ? Math.max(0, o.start) : TL.ph;
    if (o.start == null && tr.clips.some(c => start < c.start + c.dur && start + dur > c.start)) start = tr.clips.reduce((e, c) => Math.max(e, c.start + c.dur), 0);
    const c = { id: tlRid('c_'), type, src: m.url, name: (m.label || tlName(m.url)).replace(/^[📎🎬] /u, '').slice(0, 80), start: Math.round(start * 100) / 100, dur };
    if (type !== 'image') Object.assign(c, { in: 0, speed: 1, volume: 1, srcDur });
    if (type !== 'audio') Object.assign(c, { x: 0.5, y: 0.5, scale: 1, rotation: 0, opacity: 1, fit: 'cover' });
    c.fadeIn = 0; c.fadeOut = 0; tr.clips.push(c); id = c.id;
  }, '+ ' + type);
  TL.sel = id; tlProps(true);
}
function tlAddText(kind = 'text') {
  if (!TL.t) return;
  let id;
  tlChange(t => {
    const tr = tlTrackFor(kind);
    const c = { id: tlRid('c_'), type: kind, text: kind === 'caption' ? 'Altyazı mətni' : 'Başlıq', start: Math.round(TL.ph * 100) / 100, dur: 3,
      x: 0.5, y: kind === 'caption' ? 0.86 : 0.5, scale: 1, rotation: 0, opacity: 1, fadeIn: 0, fadeOut: 0,
      style: { font: 'Inter', size: kind === 'caption' ? 54 : 96, weight: 700, color: '#ffffff', bg: kind === 'caption' ? 'rgba(0,0,0,0.55)' : 'transparent', stroke: 'transparent', align: 'center', italic: false, anim: kind === 'caption' ? 'none' : 'pop' } };
    tr.clips.push(c); id = c.id;
  }, '+ mətn');
  TL.sel = id; tlProps(true);
}
function tlSplit() {
  const f = TL.sel && tlFind(TL.sel); if (!f) return miniToast('Kəsmək üçün klip seç');
  const { c } = f, at = TL.ph;
  if (at <= c.start + 0.05 || at >= c.start + c.dur - 0.05) return miniToast('Oynatma xəttini klipin içinə qoy');
  tlChange(() => {
    const g = tlFind(c.id), a = g.c, b = tlClone(a), d1 = Math.round((at - a.start) * 1000) / 1000;
    b.id = tlRid('c_'); b.start = Math.round(at * 1000) / 1000; b.dur = Math.round((a.dur - d1) * 1000) / 1000; b.fadeIn = 0;
    if (a.in != null) b.in = Math.round((a.in + d1 * (a.speed || 1)) * 1000) / 1000;
    a.dur = d1; a.fadeOut = 0; g.tr.clips.push(b);
  }, '✂ kəs');
}
function tlDelete() { if (!TL.sel || !tlFind(TL.sel)) return; const id = TL.sel; tlChange(t => { for (const tr of t.tracks) tr.clips = tr.clips.filter(c => c.id !== id); }, '🗑 sil'); TL.sel = null; tlProps(true); }
function tlDuplicate() {
  const f = TL.sel && tlFind(TL.sel); if (!f) return;
  let id; tlChange(() => { const g = tlFind(TL.sel), b = tlClone(g.c); b.id = tlRid('c_'); b.start = Math.round((g.c.start + g.c.dur) * 1000) / 1000; g.tr.clips.push(b); id = b.id; }, '⧉ kopya');
  TL.sel = id; tlProps(true);
}

/* ---------- Önizləmə: kompozitor ---------- */
function tlMedia(c) {
  let m = TL.media.get(c.id);
  if (m && m.src === c.src) return m.el;
  if (m) { try { m.el.pause && m.el.pause(); } catch (e) {} }
  let e;
  if (c.type === 'image') { e = new Image(); e.crossOrigin = 'anonymous'; e.onload = () => tlFrame(); e.src = c.src; }
  else {
    e = document.createElement(c.type === 'audio' ? 'audio' : 'video'); e.crossOrigin = 'anonymous'; e.preload = 'auto'; e.playsInline = true;
    e.onseeked = e.onloadeddata = () => { if (!TL.playing) tlFrame(); }; e.src = c.src;
  }
  TL.media.set(c.id, { el: e, src: c.src });
  return e;
}
const tlEase = x => 1 - Math.pow(1 - Math.min(1, Math.max(0, x)), 3);
function tlAlpha(c, lt) { let a = c.opacity == null ? 1 : c.opacity; if (c.fadeIn > 0 && lt < c.fadeIn) a *= lt / c.fadeIn; if (c.fadeOut > 0 && lt > c.dur - c.fadeOut) a *= Math.max(0, (c.dur - lt) / c.fadeOut); return Math.max(0, Math.min(1, a)); }
function tlWrap(ctx, text, maxW) {
  const out = [];
  for (const para of String(text).split('\n')) {
    let line = '';
    for (const w of para.split(/\s+/)) { const tst = line ? line + ' ' + w : w; if (ctx.measureText(tst).width > maxW && line) { out.push(line); line = w; } else line = tst; }
    out.push(line);
  }
  return out;
}
function tlDrawText(ctx, c, W, H, lt) {
  const s = c.style || {}, size = s.size || 64, anim = s.anim || 'none';
  let txt = c.text || '', sc = c.scale || 1, dy = 0, a = tlAlpha(c, lt);
  if (anim === 'fade') a *= tlEase(lt / 0.4);
  if (anim === 'pop') { const k = Math.min(1, lt / 0.35); sc *= 0.6 + 0.4 * (1 + 2.70158 * Math.pow(k - 1, 3) + 1.70158 * Math.pow(k - 1, 2)); a *= Math.min(1, lt / 0.12); }
  if (anim === 'slide-up') { dy = (1 - tlEase(lt / 0.45)) * size * 0.8; a *= tlEase(lt / 0.3); }
  if (anim === 'typewriter') txt = txt.slice(0, Math.ceil(txt.length * Math.min(1, lt / Math.min(c.dur * 0.6, 0.06 * txt.length + 0.2))));
  if (a <= 0.001 || !txt) return;
  ctx.save(); ctx.globalAlpha = a;
  ctx.translate((c.x == null ? 0.5 : c.x) * W, (c.y == null ? 0.5 : c.y) * H + dy); ctx.rotate((c.rotation || 0) * Math.PI / 180); ctx.scale(sc, sc);
  ctx.font = (s.italic ? 'italic ' : '') + (s.weight || 700) + ' ' + size + 'px "' + (s.font || 'Inter') + '", system-ui, sans-serif';
  ctx.textBaseline = 'middle'; ctx.textAlign = s.align || 'center';
  const lines = tlWrap(ctx, txt, W * 0.88 / sc), lh = size * 1.18, h = lines.length * lh, w = Math.max(...lines.map(l => ctx.measureText(l).width)), pad = size * 0.32;
  const x0 = ctx.textAlign === 'left' ? 0 : ctx.textAlign === 'right' ? -w : -w / 2;
  if (s.bg && s.bg !== 'transparent') { ctx.fillStyle = s.bg; ctx.beginPath(); ctx.roundRect ? ctx.roundRect(x0 - pad, -h / 2 - pad * 0.6, w + pad * 2, h + pad * 1.2, size * 0.22) : ctx.rect(x0 - pad, -h / 2 - pad * 0.6, w + pad * 2, h + pad * 1.2); ctx.fill(); }
  lines.forEach((l, i) => {
    const y = -h / 2 + lh / 2 + i * lh;
    if (s.stroke && s.stroke !== 'transparent') { ctx.lineJoin = 'round'; ctx.lineWidth = size * 0.12; ctx.strokeStyle = s.stroke; ctx.strokeText(l, 0, y); }
    else if (!s.bg || s.bg === 'transparent') { ctx.shadowColor = 'rgba(0,0,0,.55)'; ctx.shadowBlur = size * 0.12; ctx.shadowOffsetY = size * 0.04; }
    ctx.fillStyle = s.color || '#fff'; ctx.fillText(l, 0, y); ctx.shadowColor = 'transparent';
  });
  ctx.restore();
}
/** Bir kadr: t zamanında bütün görünən klipləri çək. get(c) → media elementi (önizləmə və eksport fərqli verə bilər) */
function tlDraw(ctx, t, time, get) {
  const W = t.width, H = t.height;
  ctx.save(); ctx.fillStyle = t.background || '#000'; ctx.fillRect(0, 0, W, H);
  for (const tr of t.tracks) {
    if (tr.hidden || tr.kind === 'audio') continue;
    for (const c of tr.clips) {
      if (time < c.start || time >= c.start + c.dur) continue;
      const lt = time - c.start;
      if (c.type === 'text' || c.type === 'caption') { tlDrawText(ctx, c, W, H, lt); continue; }
      const e = get(c); if (!e) continue;
      const iw = e.videoWidth || e.naturalWidth || e.width, ih = e.videoHeight || e.naturalHeight || e.height;
      if (!iw || !ih || (e.readyState !== undefined && e.tagName === 'VIDEO' && e.readyState < 2)) continue;
      let w = W, h = H;
      if (c.fit !== 'fill') { const k = c.fit === 'contain' ? Math.min(W / iw, H / ih) : Math.max(W / iw, H / ih); w = iw * k; h = ih * k; }
      ctx.save(); ctx.globalAlpha = tlAlpha(c, lt);
      ctx.translate((c.x == null ? 0.5 : c.x) * W, (c.y == null ? 0.5 : c.y) * H); ctx.rotate((c.rotation || 0) * Math.PI / 180); ctx.scale(c.scale || 1, c.scale || 1);
      try { ctx.drawImage(e, -w / 2, -h / 2, w, h); } catch (x) {}
      ctx.restore();
    }
  }
  ctx.restore();
}
/** Önizləmə media elementlərini oynatma xətti ilə sinxronla (səs də) */
function tlSync(time) {
  const t = TL.t; if (!t) return;
  const live = new Set();
  for (const tr of t.tracks) for (const c of tr.clips) {
    if (c.type !== 'video' && c.type !== 'audio') continue;
    live.add(c.id);
    const e = tlMedia(c), on = time >= c.start && time < c.start + c.dur && !(tr.hidden && c.type === 'video');
    const target = (c.in || 0) + (time - c.start) * (c.speed || 1);
    if (!on) { if (!e.paused) e.pause(); continue; }
    e.muted = tr.muted; e.volume = Math.max(0, Math.min(1, (c.volume == null ? 1 : c.volume) * tlAlpha({ ...c, opacity: 1 }, time - c.start)));
    if (TL.playing) {
      e.playbackRate = c.speed || 1;
      if (e.paused) { try { e.currentTime = target; } catch (x) {} e.play().catch(() => {}); }
      else if (Math.abs(e.currentTime - target) > 0.3) { try { e.currentTime = target; } catch (x) {} }
    } else {
      if (!e.paused) e.pause();
      if (Math.abs(e.currentTime - target) > 0.03) { try { e.currentTime = target; } catch (x) {} }
    }
  }
  for (const [id, m] of TL.media) if (!live.has(id) && m.el.pause) { m.el.pause(); }
}
function tlFrame() {
  const ui = TL.ui; if (!ui || !TL.t) return;
  const t = TL.t, cv = ui.cv, k = Math.min(1, 960 / Math.max(t.width, t.height));
  const cw = Math.round(t.width * k), ch = Math.round(t.height * k);
  if (cv.width !== cw || cv.height !== ch) { cv.width = cw; cv.height = ch; }
  if (!TL.playing) tlSync(TL.ph);
  const ctx = cv.getContext('2d'); ctx.setTransform(k, 0, 0, k, 0, 0);
  tlDraw(ctx, t, TL.ph, c => c.type === 'image' || c.type === 'video' ? tlMedia(c) : null);
  setText(ui.tm, tlFmt(TL.ph) + ' / ' + tlFmt(t.duration));
  if (ui.ph) ui.ph.style.left = (TL_HD() + TL.ph * TL.zoom) + 'px';
}
function tlPlay(on) {
  if (!TL.t) return;
  if (on == null) on = !TL.playing;
  if (on && TL.ph >= TL.t.duration - 0.05) TL.ph = 0;
  TL.playing = on; setText(TL.ui.play, on ? '⏸' : '▶');
  if (!on) { tlSync(TL.ph); tlFrame(); return; }
  const t0 = performance.now(), p0 = TL.ph;
  const step = () => {
    if (!TL.playing) return;
    TL.ph = p0 + (performance.now() - t0) / 1000;
    if (TL.ph >= TL.t.duration) { TL.ph = TL.t.duration; tlPlay(false); return; }
    tlSync(TL.ph); tlFrame(); tlFollow();
    requestAnimationFrame(step);
  };
  tlSync(TL.ph); requestAnimationFrame(step);
}
function tlSeek(s) { TL.ph = Math.max(0, Math.min(TL.t ? TL.t.duration || 0 : 0, s)); if (TL.playing) { tlPlay(false); tlPlay(true); } else tlFrame(); }
function tlFollow() { const sc = TL.ui && TL.ui.scroll; if (!sc) return; const x = TL_HD() + TL.ph * TL.zoom; if (x > sc.scrollLeft + sc.clientWidth - 40 || x < sc.scrollLeft + TL_HD()) sc.scrollLeft = x - TL_HD() - 40; }

/* ---------- Timeline UI (treklər, kliplər, sürüşdürmə) ---------- */
function tlSnapT(s, ignore) {
  if (!TL.snap || !TL.t) return s;
  const th = 8 / TL.zoom; let best = s, bd = th;
  const pts = [0, TL.ph];
  for (const tr of TL.t.tracks) for (const c of tr.clips) if (c.id !== ignore) pts.push(c.start, c.start + c.dur);
  for (const p of pts) if (Math.abs(p - s) < bd) { bd = Math.abs(p - s); best = p; }
  return best;
}
function tlDrawTimeline() {
  const ui = TL.ui; if (!ui) return;
  const t = TL.t, inner = ui.inner;
  if (!t) { inner.replaceChildren(); return; }
  const len = Math.max(t.duration + 10, (ui.scroll.clientWidth - TL_HD()) / TL.zoom + 2), lw = Math.ceil(len * TL.zoom);
  const sig = JSON.stringify([t.tracks.map(tr => [tr.id, tr.kind, tr.name, tr.muted, tr.hidden, tr.clips.map(c => [c.id, c.start, c.dur, c.type, c.text || c.name || '', c.src || ''])]), TL.zoom, TL.sel, lw]);
  if (ui.inner._sig === sig) { tlFrame(); return; }
  ui.inner._sig = sig;
  const frag = document.createDocumentFragment();
  /* xətkeş */
  const ru = el('div', 'tl-ruler'), rl = el('div', 'rl'); rl.style.width = lw + 'px';
  const stepS = TL.zoom >= 80 ? 1 : TL.zoom >= 30 ? 2 : TL.zoom >= 12 ? 5 : 10;
  for (let s = 0; s <= len; s += stepS / 2) { const tk = el('div', 'tk' + (s % stepS ? ' m' : ''), s % stepS ? '' : tlFmt(s).replace(/\.0$/, '')); tk.style.left = (s * TL.zoom) + 'px'; rl.append(tk); }
  ru.append(el('div', 'hd'), rl);
  const scrub = e => { const r = rl.getBoundingClientRect(); tlSeek((e.clientX - r.left) / TL.zoom); };
  rl.onpointerdown = e => { rl.setPointerCapture(e.pointerId); scrub(e); rl.onpointermove = scrub; rl.onpointerup = () => { rl.onpointermove = null; }; };
  frag.append(ru);
  /* treklər: yuxarıdakı trek üst qatdır (siyahıda sonuncu) */
  for (const tr of [...t.tracks].reverse()) {
    const row = el('div', 'tl-row ' + tr.kind), hd = el('div', 'hd'), lane = el('div', 'tl-lane');
    lane.style.width = lw + 'px'; lane.dataset.track = tr.id; lane.dataset.kind = tr.kind;
    const mute = el('button', tr.muted ? 'off' : null, tr.kind === 'audio' || tr.kind === 'video' ? '🔊' : ''), hide = el('button', tr.hidden ? 'off' : null, tr.kind === 'audio' ? '' : '👁'), del = el('button', null, '✕');
    mute.title = 'Səsi bağla/aç'; hide.title = 'Gizlət/göstər'; del.title = 'Treki sil';
    mute.onclick = () => tlChange(x => { const k = x.tracks.find(y => y.id === tr.id); k.muted = !k.muted; }, 'trek səsi');
    hide.onclick = () => tlChange(x => { const k = x.tracks.find(y => y.id === tr.id); k.hidden = !k.hidden; }, 'trek görünüşü');
    del.onclick = () => { if (tr.clips.length && !confirm('"' + tr.name + '" treki və ' + tr.clips.length + ' klipi silinsin?')) return; tlChange(x => { x.tracks = x.tracks.filter(y => y.id !== tr.id); }, 'trek sil'); };
    const nm = el('span', 'nm', (TL_ICON[tr.kind] || '') + ' ' + tr.name); nm.title = tr.name + ' (' + tr.kind + ')';
    nm.ondblclick = () => { const n = prompt('Trekin adı', tr.name); if (n && n.trim()) tlChange(x => { x.tracks.find(y => y.id === tr.id).name = n.trim().slice(0, 60); }, 'trek adı'); };
    hd.append(nm); if (mute.textContent) hd.append(mute); if (hide.textContent) hd.append(hide); hd.append(del);
    for (const c of tr.clips) lane.append(tlClipEl(c, tr));
    lane.onpointerdown = e => { if (e.target === lane) { TL.sel = null; tlSeek((e.clientX - lane.getBoundingClientRect().left) / TL.zoom); tlDrawTimeline(); tlProps(true); } };
    lane.ondragover = e => { if ([...e.dataTransfer.types].includes('application/x-mx-media')) { e.preventDefault(); lane.classList.add('drop'); } };
    lane.ondragleave = () => lane.classList.remove('drop');
    lane.ondrop = e => {
      lane.classList.remove('drop'); const raw = e.dataTransfer.getData('application/x-mx-media'); if (!raw) return; e.preventDefault();
      const m = JSON.parse(raw), at = tlSnapT((e.clientX - lane.getBoundingClientRect().left) / TL.zoom);
      tlAddMedia(m, { start: at, track: TL_KIND_OF[m.type === 'audio' ? 'audio' : m.type === 'video' ? 'video' : 'image'] === tr.kind ? tr.id : null });
    };
    row.append(hd, lane); frag.append(row);
  }
  const ph = el('div', 'tl-ph'); ui.ph = ph; frag.append(ph);
  inner.style.width = (TL_HD() + lw) + 'px';
  inner.replaceChildren(frag);
  tlFrame();
}
function tlClipEl(c, tr) {
  const n = el('div', 'tl-clip' + (TL.sel === c.id ? ' sel' : '')), lb = el('span', 'lb', c.type === 'text' || c.type === 'caption' ? c.text : (c.name || tlName(c.src)));
  n.dataset.type = c.type; n.style.left = (c.start * TL.zoom) + 'px'; n.style.width = Math.max(4, c.dur * TL.zoom) + 'px';
  n.title = (c.type === 'text' || c.type === 'caption' ? c.text : (c.name || c.src)) + '\n' + tlFmt(c.start) + ' → ' + tlFmt(c.start + c.dur);
  if (c.type === 'image') n.style.backgroundImage = 'linear-gradient(rgba(15,118,110,.55), rgba(15,118,110,.55)), url("' + c.src.replace(/"/g, '%22') + '")';
  const hl = el('div', 'h l'), hr = el('div', 'h r'); n.append(lb, hl, hr);
  const drag = (mode) => e => {
    e.stopPropagation(); e.preventDefault(); if (e.button !== 0) return;
    if (TL.sel !== c.id) { TL.sel = c.id; for (const x of TL.ui.inner.querySelectorAll('.tl-clip.sel')) x.classList.remove('sel'); n.classList.add('sel'); tlProps(true); }
    const x0 = e.clientX, s0 = c.start, d0 = c.dur, in0 = c.in || 0, sp = c.speed || 1, max = c.srcDur ? (c.srcDur - in0) / sp : Infinity;
    let ns = s0, nd = d0, ni = in0, lane = null, moved = false;
    n.setPointerCapture(e.pointerId); n.classList.add('dragging');
    n.onpointermove = ev => {
      const dt = (ev.clientX - x0) / TL.zoom; if (Math.abs(ev.clientX - x0) > 2) moved = true;
      if (mode === 'move') {
        ns = Math.max(0, tlSnapT(s0 + dt, c.id)); const se = tlSnapT(s0 + dt + d0, c.id); if (se !== s0 + dt + d0) ns = Math.max(0, se - d0);
        const hit = document.elementFromPoint(ev.clientX, ev.clientY), ln = hit && hit.closest && hit.closest('.tl-lane');
        lane = ln && ln.dataset.kind === TL_KIND_OF[c.type] && ln.dataset.track !== tr.id ? ln : null;
        if (lane) lane.append(n); else if (n.parentNode.dataset.track !== tr.id) TL.ui.inner.querySelector('.tl-lane[data-track="' + tr.id + '"]').append(n);
      } else if (mode === 'l') {
        let s = tlSnapT(s0 + dt, c.id); s = Math.max(0, Math.min(s0 + d0 - 0.1, s));
        if (c.in != null) s = Math.max(s, s0 - in0 / sp);
        ns = s; nd = s0 + d0 - s; ni = c.in != null ? in0 + (s - s0) * sp : ni;
      } else {
        let e2 = tlSnapT(s0 + d0 + dt, c.id); nd = Math.max(0.1, Math.min(max, e2 - s0));
      }
      n.style.left = (ns * TL.zoom) + 'px'; n.style.width = Math.max(4, nd * TL.zoom) + 'px';
    };
    n.onpointerup = () => {
      n.onpointermove = n.onpointerup = null; n.classList.remove('dragging');
      if (!moved) { tlSeekInto(c, x0); return; }
      const to = lane && lane.dataset.track;
      tlChange(t => {
        const f = tlFind(c.id); if (!f) return;
        f.c.start = Math.round(ns * 1000) / 1000; f.c.dur = Math.round(nd * 1000) / 1000; if (f.c.in != null) f.c.in = Math.round(Math.max(0, ni) * 1000) / 1000;
        if (to) { f.tr.clips = f.tr.clips.filter(x => x.id !== c.id); t.tracks.find(y => y.id === to).clips.push(f.c); }
      }, mode === 'move' ? '↔ köçür' : '✂ kəs (kənar)');
    };
  };
  n.onpointerdown = drag('move'); hl.onpointerdown = drag('l'); hr.onpointerdown = drag('r');
  n.ondblclick = () => { if (c.type === 'text' || c.type === 'caption') { const ta = TL.ui.pane.querySelector('textarea.tl-txt'); if (ta) { ta.focus(); ta.select(); } } };
  return n;
}
function tlSeekInto(c, clientX) { const lane = TL.ui.inner.querySelector('.tl-lane[data-track]'); if (!lane) return; tlSeek((clientX - lane.getBoundingClientRect().left) / TL.zoom); }

/* ---------- Xüsusiyyətlər paneli ---------- */
function tlField(label, inp, wide) { const d = el('div', wide ? 'wide' : null), l = el('label', 'fl', label); if (!inp.id) inp.id = 'tlf-' + Math.random().toString(36).slice(2, 8); l.htmlFor = inp.id; d.append(l, inp); if (wide) d.style.gridColumn = '1 / -1'; return d; }
function tlNum(c, key, label, o = {}) {
  const i = el('input'); i.type = 'number'; i.step = o.step || 'any'; if (o.min != null) i.min = o.min; if (o.max != null) i.max = o.max;
  const get = () => (o.get ? o.get(c) : c[key]);
  i.value = get() == null ? '' : Math.round(get() * 1000) / 1000; i.dataset.k = key;
  i.oninput = () => { const v = parseFloat(i.value); if (!isFinite(v)) return; tlChange(() => { const f = tlFind(c.id); if (f) (o.set ? o.set(f.c, v) : (f.c[key] = v)); }, label, c.id + key); };
  i._sync = cc => { if (document.activeElement !== i) { const v = o.get ? o.get(cc) : cc[key]; i.value = v == null ? '' : Math.round(v * 1000) / 1000; } };
  return tlField(label, i);
}
function tlProps(force) {
  const ui = TL.ui; if (!ui) return;
  const f = TL.sel && tlFind(TL.sel), box = ui.props;
  if (!force && box._for === TL.sel && f) { for (const i of box.querySelectorAll('input,textarea,select')) if (i._sync) i._sync(f.c); return; }
  box._for = TL.sel; box.replaceChildren();
  if (!f) { box.append(el('h4', null, '🎛 Xüsusiyyətlər'), el('div', 'mx-help', 'Timeline-da klip seç: vaxt, mövqe, ölçü, səs, mətn və animasiya burada. Kitabxanadan media əlavə etmək üçün kliklə və ya treki üzərinə sürüşdür.')); return; }
  const { c } = f, g = el('div', 'tl-grid');
  box.append(el('h4', null, (TL_ICON[TL_KIND_OF[c.type]] || '') + ' ' + ({ video: 'Video', image: 'Şəkil', audio: 'Səs', text: 'Mətn', caption: 'Altyazı' }[c.type]) + ' klipi'));
  if (c.type === 'text' || c.type === 'caption') {
    const ta = el('textarea', 'tl-txt'); ta.rows = 2; ta.value = c.text; ta.maxLength = 1000;
    ta.oninput = () => { if (!ta.value.trim()) return; tlChange(() => { const k = tlFind(c.id); if (k) k.c.text = ta.value; }, 'mətn', c.id + 'text'); };
    ta._sync = cc => { if (document.activeElement !== ta) ta.value = cc.text; };
    box.append(tlField('Mətn', ta, true));
  }
  g.append(tlNum(c, 'start', 'Başlanğıc (san)', { min: 0 }), tlNum(c, 'dur', 'Müddət (san)', { min: 0.1 }));
  if (c.type === 'video' || c.type === 'audio') g.append(tlNum(c, 'in', 'Mənbədən başla (san)', { min: 0 }), tlNum(c, 'speed', 'Sürət (×)', { min: 0.25, max: 4, step: 0.05 }), tlNum(c, 'volume', 'Səs (0–2)', { min: 0, max: 2, step: 0.05 }));
  if (c.type !== 'audio') g.append(tlNum(c, 'x', 'X (%)', { get: k => k.x * 100, set: (k, v) => { k.x = v / 100; } }), tlNum(c, 'y', 'Y (%)', { get: k => k.y * 100, set: (k, v) => { k.y = v / 100; } }),
    tlNum(c, 'scale', 'Ölçü (×)', { min: 0.05, max: 10, step: 0.05 }), tlNum(c, 'rotation', 'Dönmə (°)', { min: -360, max: 360 }), tlNum(c, 'opacity', 'Şəffaflıq (0–1)', { min: 0, max: 1, step: 0.05 }));
  g.append(tlNum(c, 'fadeIn', 'Fade in (san)', { min: 0 }), tlNum(c, 'fadeOut', 'Fade out (san)', { min: 0 }));
  if (c.type === 'video' || c.type === 'image') {
    const s = el('select'); for (const [v, t] of [['cover', 'Doldur (kəs)'], ['contain', 'Sığdır'], ['fill', 'Dartı']]) s.append(new Option(t, v)); s.value = c.fit || 'cover';
    s.onchange = () => tlChange(() => { tlFind(c.id).c.fit = s.value; }, 'fit'); g.append(tlField('Kadra yerləşmə', s));
  }
  box.append(g);
  if (c.type === 'text' || c.type === 'caption') {
    const st = c.style || {}, g2 = el('div', 'tl-grid');
    const setS = (k, v, note) => tlChange(() => { const f2 = tlFind(c.id); f2.c.style = { ...(f2.c.style || {}), [k]: v }; }, note || 'stil', c.id + 's' + k);
    const font = el('select'); for (const fn of TL_FONTS) font.append(new Option(fn, fn)); font.value = st.font || 'Inter'; font.onchange = () => setS('font', font.value);
    const size = el('input'); size.type = 'number'; size.min = 8; size.max = 600; size.value = st.size || 64; size.oninput = () => { const v = +size.value; if (v >= 8) setS('size', v); };
    const col = el('input'); col.type = 'color'; col.value = /^#[0-9a-f]{6}$/i.test(st.color || '') ? st.color : '#ffffff'; col.oninput = () => setS('color', col.value);
    const bgOn = el('select'); for (const [v, t] of [['transparent', 'Fonsuz'], ['rgba(0,0,0,0.55)', 'Qara yarımşəffaf'], ['#000000', 'Qara'], ['#ffffff', 'Ağ'], ['#ff7a3d', 'Narıncı'], ['#2563eb', 'Mavi']]) bgOn.append(new Option(t, v));
    bgOn.value = [...bgOn.options].some(o => o.value === st.bg) ? st.bg : 'transparent'; bgOn.onchange = () => setS('bg', bgOn.value);
    const wt = el('select'); for (const w of [400, 600, 700, 800, 900]) wt.append(new Option(String(w), String(w))); wt.value = String(st.weight || 700); wt.onchange = () => setS('weight', +wt.value);
    const an = el('select'); for (const [v, t] of TL_ANIMS) an.append(new Option(t, v)); an.value = st.anim || 'none'; an.onchange = () => setS('anim', an.value);
    const al = el('select'); for (const [v, t] of [['center', 'Mərkəz'], ['left', 'Sol'], ['right', 'Sağ']]) al.append(new Option(t, v)); al.value = st.align || 'center'; al.onchange = () => setS('align', al.value);
    const sk = el('select'); for (const [v, t] of [['transparent', 'Yox'], ['#000000', 'Qara kontur'], ['#ffffff', 'Ağ kontur']]) sk.append(new Option(t, v)); sk.value = [...sk.options].some(o => o.value === st.stroke) ? st.stroke : 'transparent'; sk.onchange = () => setS('stroke', sk.value);
    g2.append(tlField('Şrift', font), tlField('Ölçü (px)', size), tlField('Rəng', col), tlField('Fon', bgOn), tlField('Qalınlıq', wt), tlField('Kontur', sk), tlField('Animasiya', an), tlField('Düzləndirmə', al));
    box.append(el('h4', null, '🔤 Stil'), g2);
  }
  const acts = el('div', 'tl-acts');
  acts.append(mxBtn('✂ Kəs (S)', null, tlSplit), mxBtn('⧉ Kopya', null, tlDuplicate), mxBtn('🗑 Sil', 'danger', tlDelete));
  box.append(acts);
}

/* ---------- Layihələr ---------- */
async function tlLoadList() {
  const { status, d } = await MED('project_list');
  if (status === 200) TL.list = d.projects || [];
  else if (TL.list === null) TL.list = [];
  tlProjUI();
}
async function tlOpen(id) {
  if (TL.dirty) await tlSave();
  tlPlay(false);
  const { status, d } = await MED('project_get', { id });
  if (status !== 200) { miniToast(d.error || 'Layihə açılmadı'); try { localStorage.removeItem(TL_LS); } catch (e) {} TL.proj = TL.t = null; tlProjUI(); tlDrawTimeline(); tlEmpty(); return; }
  TL.proj = d.project; TL.t = d.project.timeline; TL.ver = d.project.version; TL.exports = d.exports || [];
  TL.undo = []; TL.redo = []; TL.sel = null; TL.ph = 0; TL.dirty = false;
  for (const [, m] of TL.media) { try { m.el.pause && m.el.pause(); } catch (e) {} } TL.media.clear();
  try { localStorage.setItem(TL_LS, String(id)); } catch (e) {}
  tlProjUI(); tlEmpty(); tlDrawTimeline(); tlProps(true); tlStatus('✓ v' + TL.ver);
  if (typeof tlAfterOpen === 'function') tlAfterOpen();
}
async function tlCreate(preset) {
  const title = prompt('Videonun adı', 'Yeni video'); if (title === null) return;
  const { status, d } = await MED('project_create', { title: title.trim() || 'Yeni video', preset });
  if (status !== 200) return miniToast(d.error || 'Yaradılmadı');
  await tlLoadList(); tlOpen(d.project.id);
}
function tlProjUI() {
  const ui = TL.ui; if (!ui) return;
  const sel = ui.psel, list = TL.list || [];
  const sig = list.map(p => p.id + ':' + p.title + ':' + p.version).join() + '|' + (TL.proj && TL.proj.id);
  if (sel._sig !== sig) {
    sel._sig = sig; sel.replaceChildren(new Option(list.length ? '🎞 Layihə seç…' : 'Hələ layihə yoxdur', ''));
    for (const p of list) sel.append(new Option(p.title + ' · ' + tlFmt(p.duration) + ' · ' + p.owner, p.id));
    sel.value = TL.proj ? String(TL.proj.id) : '';
  }
  const has = !!TL.proj; ui.ren.hidden = ui.del.hidden = ui.ver.hidden = !has;
}
function tlEmpty() {
  const ui = TL.ui; if (!ui) return;
  ui.empty.hidden = !!TL.t; ui.cv.hidden = !TL.t;
}
async function tlVersions() {
  const box = TL.ui.verBox; if (!TL.proj) return;
  if (!box.hidden) { box.hidden = true; return; }
  box.hidden = false; box.replaceChildren(el('div', 'mx-help', 'Yüklənir…'));
  const { status, d } = await MED('project_versions', { id: TL.proj.id });
  box.replaceChildren();
  if (status !== 200) return box.append(el('div', 'cmderr', d.error || 'Alınmadı'));
  for (const v of d.versions) {
    const b = el('button'); b.type = 'button'; b.append(el('b', null, 'v' + v.version), el('span', null, v.author + ' · ' + (v.note || '') ), el('small', null, ago(v.created_at)));
    b.disabled = +v.version === TL.ver;
    b.onclick = async () => { if (!confirm('v' + v.version + ' geri qaytarılsın? (Yeni versiya kimi saxlanır, heç nə itmir.)')) return; const r = await MED('project_restore', { id: TL.proj.id, version: v.version }); if (r.status !== 200) return miniToast(r.d.error || 'Alınmadı'); tlAdopt(r.d.project); box.hidden = true; };
    box.append(b);
  }
}

/* ---------- DOM: bir dəfə qurulur ---------- */
function tlBuild(R) {
  const r = R._r, ui = TL.ui = {};
  /* səhnə: layihə zolağı + kətan + boş vəziyyət */
  const proj = el('div', 'tl-proj'), psel = el('select'), add = mxBtn('＋ Yeni', 'go'), ren = mxBtn('✎'), del = mxBtn('🗑', 'danger'), ver = mxBtn('🕘 Versiyalar'), st = el('span', 'st');
  psel.setAttribute('aria-label', 'Layihə'); ren.title = 'Adını dəyiş'; del.title = 'Layihəni sil';
  psel.onchange = () => { if (psel.value) tlOpen(+psel.value); };
  add.onclick = () => tlCreate('landscape');
  ren.onclick = async () => { const n = prompt('Yeni ad', TL.proj.title); if (!n || !n.trim()) return; const x = await MED('project_rename', { id: TL.proj.id, title: n.trim() }); if (x.status !== 200) return miniToast(x.d.error || 'Alınmadı'); TL.proj.title = n.trim(); tlLoadList(); };
  del.onclick = async () => { if (!confirm('"' + TL.proj.title + '" layihəsi silinsin? Bu geri qaytarılmır.')) return; const x = await MED('project_delete', { id: TL.proj.id }); if (x.status !== 200) return miniToast(x.d.error || 'Alınmadı'); TL.proj = TL.t = null; try { localStorage.removeItem(TL_LS); } catch (e) {} await tlLoadList(); tlEmpty(); tlDrawTimeline(); tlProps(true); };
  ver.onclick = () => tlVersions();
  proj.append(psel, add, ren, del, ver, st);
  const box = el('div', 'tl-canvas-box'), cv = el('canvas'), empty = el('div', 'tl-empty');
  cv.setAttribute('aria-label', 'Video önizləməsi'); cv.onclick = () => tlPlay();
  const er = el('div', 'row');
  for (const [p, lbl] of TL_PRESET.map(x => [x[0], x[1]])) er.append(mxBtn('＋ ' + lbl + ' video', p === 'landscape' ? 'go' : null, () => tlCreate(p)));
  empty.append(el('b', null, '🎞 Timeline redaktoru'), el('div', null, 'Yeni video layihəsi yarat və ya yuxarıdan aç. Media kitabxanadan sürüşdür, kəs, mətn və altyazı əlavə et, MP4 kimi çıxar.'), el('div', 'mx-help', 'Claude da eyni layihəni redaktə edə bilir (planet-media MCP): dəyişikliklər burada canlı görünür.'), er);
  box.append(cv, empty);
  const verBox = el('div', 'tl-ver'); verBox.hidden = true;
  const bot = el('div'); bot.append(verBox);
  r.tlStage.append(proj, box, bot);
  /* aşağı: alət zolağı + treklər */
  const bar = el('div', 'tl-bar'), play = mxBtn('▶'), tm = el('span', 'tm', '00:00.0 / 00:00.0'), split = mxBtn('✂'), delc = mxBtn('🗑'), un = mxBtn('↶'), re = mxBtn('↷'), txt = mxBtn('🔤 Mətn'), cap = mxBtn('💬 Altyazı'), zoom = el('input'), addTr = el('select'), snap = mxBtn('🧲');
  play.title = 'Oynat / dayandır (boşluq)'; split.title = 'Seçilmiş klipi oynatma xəttində kəs (S)'; delc.title = 'Seçilmişi sil (Del)'; un.title = 'Geri al (⌘Z)'; re.title = 'Yenidən et (⇧⌘Z)'; snap.title = 'Yapışma (snap)';
  zoom.type = 'range'; zoom.min = 8; zoom.max = 240; zoom.value = TL.zoom; zoom.setAttribute('aria-label', 'Miqyas');
  zoom.oninput = () => { TL.zoom = +zoom.value; tlDrawTimeline(); };
  addTr.append(new Option('＋ Trek', ''), new Option('🎞 Video', 'video'), new Option('🎵 Səs', 'audio'), new Option('🔤 Mətn', 'text'), new Option('💬 Altyazı', 'caption'));
  addTr.onchange = () => { const k = addTr.value; addTr.value = ''; if (!k || !TL.t) return; tlChange(t => { const tr = { id: tlRid('t_'), kind: k, name: { video: 'Video', audio: 'Səs', text: 'Mətn', caption: 'Altyazı' }[k] + ' ' + (t.tracks.filter(x => x.kind === k).length + 1), muted: false, hidden: false, clips: [] }; k === 'audio' ? t.tracks.unshift(tr) : t.tracks.push(tr); }, '+ trek'); };
  play.onclick = () => tlPlay(); split.onclick = tlSplit; delc.onclick = tlDelete; un.onclick = () => tlUndo(false); re.onclick = () => tlUndo(true);
  txt.onclick = () => tlAddText('text'); cap.onclick = () => tlAddText('caption');
  snap.classList.toggle('go', TL.snap); snap.onclick = () => { TL.snap = !TL.snap; snap.classList.toggle('go', TL.snap); };
  bar.append(play, tm, split, delc, un, re, el('span', 'sp'), txt, cap, addTr, snap, el('span', null, '🔍'), zoom);
  const scroll = el('div', 'tl-scroll'), inner = el('div', 'tl-inner'); scroll.append(inner);
  scroll.addEventListener('wheel', e => { if (e.ctrlKey || e.metaKey) { e.preventDefault(); TL.zoom = Math.max(8, Math.min(240, TL.zoom * (e.deltaY < 0 ? 1.15 : 0.87))); zoom.value = TL.zoom; tlDrawTimeline(); } }, { passive: false });
  r.tlFoot.append(bar, scroll);
  /* sağ panel: xüsusiyyətlər + (2–3-cü mərhələ: altyazı, eksport, Claude) */
  const props = el('div', 'tl-sec'), extra = el('div');
  r.tlPane.append(props, extra);
  Object.assign(ui, { proj, psel, ren, del, ver, st, box, cv, empty, verBox, bar, play, tm, scroll, inner, props, extra, pane: r.tlPane });
  new ResizeObserver(() => { if (MX.editMode === 'timeline') tlDrawTimeline(); }).observe(scroll);
  /* klaviatura */
  addEventListener('keydown', e => {
    if (!mxeRoot || mxeRoot.hidden || MX.editMode !== 'timeline' || !TL.t) return;
    if (e.target.closest && e.target.closest('input,textarea,select,[contenteditable]')) return;
    const mod = e.metaKey || e.ctrlKey;
    if (e.key === ' ') { e.preventDefault(); tlPlay(); }
    else if ((e.key === 's' || e.key === 'S') && !mod) { e.preventDefault(); tlSplit(); }
    else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); tlDelete(); }
    else if (mod && (e.key === 'z' || e.key === 'Z')) { e.preventDefault(); tlUndo(e.shiftKey); }
    else if (mod && (e.key === 'd' || e.key === 'D')) { e.preventDefault(); tlDuplicate(); }
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); e.stopPropagation(); tlSeek(TL.ph + (e.key === 'ArrowLeft' ? -1 : 1) * (e.shiftKey ? 1 : 1 / (TL.t.fps || 30))); }
  }, true);
  /* canlı: başqası (Claude) dəyişəndə */
  if (window.RT && RT.on) RT.on('media.project', ev => {
    if (TL.list !== null) tlLoadList();
    if (!TL.proj || +ev.ref !== +TL.proj.id) return;
    const dt = ev.data || {};
    if (dt.deleted) { miniToast('🗑 Layihə silindi (' + (dt.by || '') + ')'); TL.proj = TL.t = null; tlEmpty(); tlDrawTimeline(); return; }
    if (dt.export && typeof tlAfterOpen === 'function') tlAfterOpen();
    if ((dt.version || 0) > TL.ver && !TL.saving) tlRemote(dt);
  });
  setInterval(() => {                                              // RT yoxdursa: 4 san-dan bir versiyanı yoxla
    if (!TL.proj || !mxeRoot || mxeRoot.hidden || MX.editMode !== 'timeline' || TL.saving || (window.RT && RT.connected && RT.connected())) return;
    MED('project_get', { id: TL.proj.id }).then(({ status, d }) => { if (status === 200 && d.project.version > TL.ver && !TL.saving) tlRemote({ by: d.project.updated_by, project: d.project }); });
  }, 4000);
  TL.built = true;
}
async function tlRemote(dt) {
  if (TL.dirty) { await tlSave(); return; }                         // öz dəyişikliyim gözləyir: saxla, 409 olarsa adopt edəcək
  let p = dt.project;
  if (!p) { const { status, d } = await MED('project_get', { id: TL.proj.id }); if (status !== 200) return; p = d.project; TL.exports = d.exports || TL.exports; }
  if (p.version <= TL.ver) return;
  // öz saxlamalarımız buraya gəlmir (versiya artıq bizimdir); Claude da mənim tokenimlə işləyə bilər, ona görə qeydi göstəririk
  tlAdopt(p, '🔄 ' + (p.updated_by || 'Kimsə') + ' dəyişdi' + (dt.note ? ': ' + dt.note : '') + ' · v' + p.version);
}

/** media.js → mxEdit (Timeline rejimi) hər yenilənmədə çağırır */
function tlRender(R) {
  if (!TL.built) {
    tlBuild(R);
    MED('asset_list').then(({ status, d }) => { if (status === 200) { MX.assets = d.assets || []; R._libSig = ''; mxEdit(); } });
    tlLoadList().then(() => { let id = 0; try { id = +localStorage.getItem(TL_LS) || 0; } catch (e) {} if (id && (TL.list || []).some(p => p.id === id)) tlOpen(id); else { tlEmpty(); tlDrawTimeline(); tlProps(true); } });
    return;
  }
  tlProjUI();
}

/* ====== 2-ci mərhələ: altyazı (Whisper, brauzerdə), eksport (mediabunny → MP4), OpenCut ====== */
const TL_MB = 'https://cdn.jsdelivr.net/npm/mediabunny@1.61.3/+esm';
const TL_TF = 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@4.3.1';
const TL_WHISPER = [['onnx-community/whisper-base', 'Base · sürətli (~80 MB)'], ['onnx-community/whisper-small', 'Small · dəqiq (~250 MB)'], ['onnx-community/whisper-large-v3-turbo', 'Large v3 Turbo · ən dəqiq (WebGPU)']];
const TL_LANGS = [['', 'Avtomatik'], ['azerbaijani', 'Azərbaycan'], ['english', 'English'], ['russian', 'Русский'], ['turkish', 'Türkçe']];
let tlMbMod = null;
const tlMb = () => (tlMbMod = tlMbMod || import(TL_MB));
const tlAudioCache = new Map();
/** Mənbənin səsini decode et (sampleRate ilə). Səs yoxdursa null */
function tlDecodeAudio(src, rate) {
  const k = src + '@' + rate;
  if (!tlAudioCache.has(k)) tlAudioCache.set(k, (async () => {
    const buf = await (await fetch(src)).arrayBuffer();
    const ac = new OfflineAudioContext(1, 1, rate);
    try { return await ac.decodeAudioData(buf); } catch (e) { return null; }
  })());
  return tlAudioCache.get(k);
}
function tlMediaClips(t, types) { const out = []; for (const tr of t.tracks) for (const c of tr.clips) if (types.includes(c.type)) out.push({ c, tr }); return out; }

/* ---------- 💬 Avtomatik altyazı ---------- */
let tlAsr = null;
function tlAsrWorker() {
  if (tlAsr) return tlAsr;
  const code = `
import { pipeline, env } from '${TL_TF}';
env.allowLocalModels = false;
let pipe = null, cur = '';
self.onmessage = async (e) => {
  const { model, audio, language, id } = e.data;
  try {
    if (!pipe || cur !== model) {
      const device = (self.navigator && navigator.gpu) ? 'webgpu' : 'wasm';
      pipe = await pipeline('automatic-speech-recognition', model, { device, dtype: device === 'webgpu' ? 'fp32' : 'q8',
        progress_callback: p => { if (p.status === 'progress') self.postMessage({ id, progress: p.progress, file: p.file }); } });
      cur = model;
    }
    self.postMessage({ id, stage: 'transcribe' });
    const out = await pipe(audio, { return_timestamps: true, chunk_length_s: 30, stride_length_s: 5, ...(language ? { language, task: 'transcribe' } : {}) });
    self.postMessage({ id, done: true, chunks: out.chunks || [], text: out.text });
  } catch (err) { self.postMessage({ id, error: String(err && err.message || err) }); }
};`;
  tlAsr = new Worker(URL.createObjectURL(new Blob([code], { type: 'text/javascript' })), { type: 'module' });
  return tlAsr;
}
async function tlCaptions(btn, prog, opts) {
  const t = TL.t; if (!t) return;
  const sel = TL.sel && tlFind(TL.sel);
  const target = sel && (sel.c.type === 'video' || sel.c.type === 'audio') ? sel : tlMediaClips(t, ['video', 'audio']).filter(x => !x.tr.muted)[0];
  if (!target) return miniToast('Altyazı üçün səsli video və ya səs klipi lazımdır');
  const { c } = target, sp = c.speed || 1;
  btn.disabled = true; prog.hidden = false; setText(prog, 'Səs hazırlanır…');
  try {
    const buf = await tlDecodeAudio(c.src, 16000);
    if (!buf) throw new Error('Bu klipdə səs tapılmadı');
    const a = Math.floor((c.in || 0) * 16000), b = Math.min(buf.length, Math.floor(((c.in || 0) + c.dur * sp) * 16000));
    const audio = buf.getChannelData(0).slice(a, b);
    const w = tlAsrWorker(), id = Date.now();
    const res = await new Promise((ok, no) => {
      w.onmessage = e => {
        const d = e.data; if (d.id !== id) return;
        if (d.error) no(new Error(d.error));
        else if (d.progress != null) setText(prog, 'Model yüklənir: ' + Math.round(d.progress) + '% · ' + (d.file || '').split('/').pop());
        else if (d.stage) setText(prog, '🎙 Mətnə çevrilir… (' + Math.round(audio.length / 16000) + ' san səs)');
        else if (d.done) ok(d);
      };
      w.onerror = e => no(new Error(e.message || 'Worker xətası'));
      w.postMessage({ model: opts.model, audio, language: opts.lang, id }, [audio.buffer]);
    });
    const cues = (res.chunks || []).map(k => {
      const s0 = k.timestamp && k.timestamp[0] != null ? k.timestamp[0] : 0, e0 = k.timestamp && k.timestamp[1] != null ? k.timestamp[1] : s0 + 2;
      return { start: c.start + s0 / sp, end: c.start + Math.max(e0, s0 + 0.3) / sp, text: String(k.text || '').trim() };
    }).filter(q => q.text && q.start < c.start + c.dur);
    if (!cues.length) throw new Error('Danışıq tapılmadı');
    tlChange(tt => {
      let tr = tt.tracks.find(x => x.kind === 'caption');
      if (!tr) { tr = { id: tlRid('t_'), kind: 'caption', name: 'Altyazı', muted: false, hidden: false, clips: [] }; tt.tracks.push(tr); }
      if (opts.replace) tr.clips = tr.clips.filter(x => x.start + x.dur <= c.start || x.start >= c.start + c.dur);
      for (const q of cues) tr.clips.push({ id: tlRid('c_'), type: 'caption', text: q.text.slice(0, 1000), start: Math.round(q.start * 100) / 100, dur: Math.max(0.3, Math.round((Math.min(q.end, c.start + c.dur) - q.start) * 100) / 100),
        x: 0.5, y: 0.86, scale: 1, rotation: 0, opacity: 1, fadeIn: 0, fadeOut: 0, style: { font: 'Inter', size: Math.round(t.width / 20), weight: 700, color: '#ffffff', bg: 'rgba(0,0,0,0.55)', stroke: 'transparent', align: 'center', italic: false, anim: 'none' } });
    }, '💬 avtomatik altyazı (' + cues.length + ')');
    setText(prog, '✅ ' + cues.length + ' altyazı əlavə olundu. Mətnləri kliklə düzəldə bilərsən.');
  } catch (e) { setText(prog, '❌ ' + e.message); }
  btn.disabled = false;
}

/* ---------- ⬇ MP4 eksport (önizləmə ilə eyni çəkmə) ---------- */
async function tlMixAudio(t, rate) {
  const len = Math.max(1, Math.ceil(t.duration * rate));
  const ctx = new OfflineAudioContext(2, len, rate);
  let any = false;
  for (const { c, tr } of tlMediaClips(t, ['video', 'audio'])) {
    if (tr.muted || (c.volume != null && c.volume <= 0)) continue;
    const buf = await tlDecodeAudio(c.src, rate); if (!buf) continue;
    const s = ctx.createBufferSource(), g = ctx.createGain(), sp = c.speed || 1, v = c.volume == null ? 1 : c.volume;
    s.buffer = buf; s.playbackRate.value = sp; s.connect(g); g.connect(ctx.destination);
    g.gain.setValueAtTime(c.fadeIn > 0 ? 0 : v, c.start);
    if (c.fadeIn > 0) g.gain.linearRampToValueAtTime(v, c.start + c.fadeIn);
    if (c.fadeOut > 0) { g.gain.setValueAtTime(v, Math.max(c.start, c.start + c.dur - c.fadeOut)); g.gain.linearRampToValueAtTime(0, c.start + c.dur); }
    s.start(c.start, c.in || 0, c.dur * sp); any = true;
  }
  return any ? ctx.startRendering() : null;
}
async function tlExport(btn, prog, opts) {
  const t = tlClone(TL.t); if (!t || !t.duration) return miniToast('Timeline boşdur');
  if (!('VideoEncoder' in window)) return miniToast('Bu brauzer WebCodecs dəstəkləmir: Chrome/Edge/Safari 17+ istifadə et');
  btn.disabled = true; prog.hidden = false; setText(prog, 'Hazırlanır…');
  const sinks = new Map(), inputs = [];
  try {
    const MB = await tlMb();
    const sc = opts.scale || 1, W = Math.round(t.width * sc / 2) * 2, H = Math.round(t.height * sc / 2) * 2, fps = t.fps || 30;
    const cv = document.createElement('canvas'); cv.width = W; cv.height = H;
    const ctx = cv.getContext('2d');
    const out = new MB.Output({ format: new MB.Mp4OutputFormat({ fastStart: 'in-memory' }), target: new MB.BufferTarget() });
    const vs = new MB.CanvasSource(cv, { codec: 'avc', quality: opts.quality === 'medium' ? MB.QUALITY_MEDIUM : MB.QUALITY_HIGH });
    out.addVideoTrack(vs, { frameRate: fps });
    setText(prog, '🎵 Səs qarışdırılır…');
    const mix = await tlMixAudio(t, 48000);
    let as = null;
    if (mix) { as = new MB.AudioBufferSource({ codec: 'aac', quality: MB.QUALITY_HIGH }); out.addAudioTrack(as); }
    await out.start();
    // video klipləri üçün dəqiq kadr oxuyucuları (CanvasSink)
    for (const { c } of tlMediaClips(t, ['video'])) {
      const inp = new MB.Input({ source: new MB.UrlSource(c.src), formats: MB.ALL_FORMATS }); inputs.push(inp);
      const tr = await inp.getPrimaryVideoTrack();
      if (tr) sinks.set(c.id, new MB.CanvasSink(tr));
    }
    const imgs = new Map();
    for (const { c } of tlMediaClips(t, ['image'])) imgs.set(c.id, await new Promise(ok => { const i = new Image(); i.crossOrigin = 'anonymous'; i.onload = () => ok(i); i.onerror = () => ok(null); i.src = c.src; }));
    const frames = Math.ceil(t.duration * fps), t0 = performance.now();
    for (let f = 0; f < frames; f++) {
      const time = f / fps, cur = new Map();
      for (const tr of t.tracks) for (const c of tr.clips) {
        if (c.type !== 'video' || tr.hidden || time < c.start || time >= c.start + c.dur || !sinks.has(c.id)) continue;
        const w = await sinks.get(c.id).getCanvas((c.in || 0) + (time - c.start) * (c.speed || 1));
        if (w) cur.set(c.id, w.canvas);
      }
      ctx.setTransform(sc, 0, 0, sc, 0, 0);
      tlDraw(ctx, t, time, c => (c.type === 'video' ? cur.get(c.id) : imgs.get(c.id)) || null);
      await vs.add(time, 1 / fps);
      if (f % 10 === 0) { const el2 = (performance.now() - t0) / 1000, left = el2 / (f + 1) * (frames - f - 1); setText(prog, '🎬 Render: ' + Math.round(f / frames * 100) + '% · ' + f + '/' + frames + ' kadr · ~' + Math.ceil(left) + ' san qaldı'); }
    }
    if (as && mix) await as.add(mix);
    setText(prog, 'Fayl yığılır…');
    await out.finalize();
    const blob = new Blob([out.target.buffer], { type: 'video/mp4' }), mb = (blob.size / 1048576).toFixed(1);
    const url = URL.createObjectURL(blob), a = el('a', 'gbtn go', '⬇ Yüklə (' + mb + ' MB)');
    a.href = url; a.download = (TL.proj.title || 'video').replace(/[^\p{L}\p{N} _-]/gu, '').trim().slice(0, 60) + '.mp4';
    prog.replaceChildren(el('div', null, '✅ Hazırdır: ' + W + '×' + H + ' · ' + tlFmt(t.duration) + ' · ' + mb + ' MB'), a);
    if (opts.upload) {
      if (blob.size > 50 * 1048576) { prog.append(el('div', 'mx-help', 'Fayl 50 MB-dan böyükdür: Planet-ə yüklənmədi, yalnız endir.')); }
      else {
        const st = el('div', 'mx-help', '⬆ Planet-ə yüklənir…'); prog.append(st);
        const fd = new FormData(); fd.append('action', 'upload_render'); fd.append('project_id', TL.proj.id); fd.append('file', blob, a.download);
        const r = await fetch('media.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
        const d = await r.json().catch(() => ({}));
        setText(st, r.ok ? '✅ Layihənin renderlərinə əlavə olundu (kitabxanada da var)' : '⚠️ Yüklənmədi: ' + (d.error || r.status));
        if (r.ok) { tlAfterOpen(); MED('asset_list').then(({ status, d: x }) => { if (status === 200) { MX.assets = x.assets || []; if (mxeRoot) { mxeRoot._libSig = ''; mxEdit(); } } }); }
      }
    }
  } catch (e) { setText(prog, '❌ Eksport alınmadı: ' + (e && e.message || e)); console.error(e); }
  for (const i of inputs) { try { i.dispose && i.dispose(); } catch (e) {} }
  btn.disabled = false;
}

/* ---------- Sağ paneldəki əlavə bölmələr (layihə açılanda) ---------- */
function tlAfterOpen() {
  const ui = TL.ui; if (!ui || !TL.proj) return;
  const box = ui.extra;
  if (!box._built) {
    box._built = true;
    /* altyazı */
    const cap = el('div', 'tl-sec'), mdl = el('select'), lang = el('select'), rep = el('label', 'chk'), repI = el('input'), cb = mxBtn('💬 Altyazı yarat', 'go'), cp = el('div', 'mx-note');
    for (const [v, t] of TL_WHISPER) mdl.append(new Option(t, v)); mdl.value = TL_WHISPER[0][0];
    for (const [v, t] of TL_LANGS) lang.append(new Option(t, v));
    repI.type = 'checkbox'; repI.checked = true; repI.style.width = 'auto'; rep.style.cssText = 'display:flex;gap:6px;align-items:center;font-size:13px;margin-top:8px'; rep.append(repI, ' Köhnə altyazıları əvəz et');
    cp.hidden = true; cb.style.marginTop = '10px';
    cb.onclick = () => tlCaptions(cb, cp, { model: mdl.value, lang: lang.value, replace: repI.checked });
    const g = el('div', 'tl-grid'); g.append(tlField('Model', mdl), tlField('Dil', lang));
    cap.append(el('h4', null, '💬 Avtomatik altyazı'), el('div', 'mx-help', 'Whisper brauzerində işləyir (OpenCut kimi): səs serverə getmir. Seçilmiş klipin (və ya ilk səsli klipin) danışığı altyazı trekinə yazılır.'), g, rep, cb, cp);
    /* eksport */
    const ex = el('div', 'tl-sec'), q = el('select'), sz = el('select'), up = el('label', 'chk'), upI = el('input'), eb = mxBtn('⬇ MP4 çıxar', 'go'), ep = el('div', 'mx-note'), list = el('div');
    for (const [v, t] of [['high', 'Yüksək keyfiyyət'], ['medium', 'Orta (kiçik fayl)']]) q.append(new Option(t, v));
    for (const [v, t] of [['1', 'Tam ölçü'], ['0.5', 'Yarım ölçü (sürətli)']]) sz.append(new Option(t, v));
    upI.type = 'checkbox'; upI.checked = true; upI.style.width = 'auto'; up.style.cssText = 'display:flex;gap:6px;align-items:center;font-size:13px;margin-top:8px'; up.append(upI, ' Planet-ə yüklə (layihənin renderləri)');
    ep.hidden = true; eb.style.marginTop = '10px';
    eb.onclick = () => tlExport(eb, ep, { quality: q.value, scale: +sz.value, upload: upI.checked });
    const g2 = el('div', 'tl-grid'); g2.append(tlField('Keyfiyyət', q), tlField('Ölçü', sz));
    ex.append(el('h4', null, '⬇ Eksport'), el('div', 'mx-help', 'Brauzerdə render (WebCodecs, mediabunny): önizləmədə gördüyün kimi. H.264 + AAC MP4.'), g2, up, eb, ep, list);
    /* OpenCut */
    const oc = el('div', 'tl-sec'), ob = mxBtn('↗ OpenCut-da aç'), ol = el('div');
    ob.onclick = () => {
      const srcs = [...new Set(tlMediaClips(TL.t, ['video', 'image', 'audio']).map(x => x.c.src))];
      ol.replaceChildren(el('div', 'mx-help', 'OpenCut (açıq mənbə, CapCut tipli) layihəni birbaşa oxuya bilmir: faylları endir, OpenCut-da "Import" ilə at.'));
      for (const s of srcs) { const a = el('a', null, '⬇ ' + tlName(s)); a.href = s; a.download = tlName(s); a.style.cssText = 'display:block;color:var(--accent);font-size:12.5px;margin-top:4px'; ol.append(a); }
      open('https://opencut.app/projects', '_blank', 'noopener');
    };
    oc.append(el('h4', null, '↗ OpenCut'), el('div', 'mx-help', 'Əl ilə daha geniş redaktə üçün: maskalar, keyframe-lər, stikerlər.'), ob, ol);
    box.append(tlClaudeBox(), cap, ex, oc);
    box._list = list;
  }
  /* layihənin renderləri */
  MED('project_get', { id: TL.proj.id }).then(({ status, d }) => {
    if (status !== 200) return; TL.exports = d.exports || [];
    const l = box._list; l.replaceChildren();
    if (TL.exports.length) l.append(el('h4', null, '🎬 Renderlər'));
    for (const x of TL.exports.slice(0, 8)) {
      const r = el('div', 'prow'), a = el('a', null, '⬇ ' + (x.name || 'video.mp4')); a.href = x.url; a.target = '_blank'; a.rel = 'noopener'; a.style.color = 'var(--accent)';
      const v = el('video'); v.src = x.url; v.controls = true; v.preload = 'metadata'; v.style.cssText = 'width:100%;border-radius:8px;margin-top:6px;max-height:200px;background:#000';
      r.append(el('div', 'pm', x.user + ' · ' + ago(x.created_at) + ' · ' + (x.size / 1048576).toFixed(1) + ' MB'), a, v); l.append(r);
    }
  });
}


/* ====== 3-cü mərhələ: 🤖 Claude ilə redaktə (planet-media MCP) və HyperFrames render ====== */
const TL_CLAUDE_TPL = [
  ['✂️ Səssiz yerləri kəs', 'Videodakı səssiz/boş hissələri kəs, kliplər arasında boşluq qalmasın.'],
  ['🔤 Başlıq və sonluq', 'Əvvələ 2 saniyəlik animasiyalı başlıq, sona 2 saniyəlik sonluq yazısı əlavə et.'],
  ['💬 Altyazıları düzəlt', 'Altyazıların yazılışını düzəlt, uzun cümlələri 2 sətirə böl, vaxtları danışığa uyğunlaşdır.'],
  ['📱 9:16 reels-ə çevir', 'Kadrı 9:16 (reels) et, video klipləri kadra yerləşdir, mətnləri yeni ölçüyə uyğunlaşdır.'],
  ['🎵 Musiqi', 'Səs trekini videonun uzunluğuna uyğunlaşdır, sonda 2 saniyəlik fade out et, danışıq olan yerdə musiqini 0.3 səs səviyyəsinə endir.'],
  ['🎬 HyperFrames ilə render', 'Timeline-ı HyperFrames kompozisiyasına çevir (motion graphics keyfiyyətində), `npx hyperframes render` ilə MP4 render et və media_upload_file(path, project_id, as_render=true) ilə layihəyə yüklə.'],
];
function tlClaudeBox() {
  const sec = el('div', 'tl-sec'), comp = el('select'), note = el('div', 'mx-help'), ta = el('textarea'), chips = el('div', 'tl-chips'), err = mxErr(), btn = mxBtn('🤖 Claude-a göndər', 'go'), jobs = el('div');
  comp.setAttribute('aria-label', 'Claude-un işləyəcəyi kompüter');
  ta.rows = 3; ta.placeholder = 'Nə edək? Məs: "ilk 2 saniyəni kəs, hər klipə 0.3 san fade əlavə et, başlığa sarı rəng ver"'; ta.setAttribute('aria-label', 'Claude üçün tapşırıq');
  for (const [t, line] of TL_CLAUDE_TPL) { const b = el('button', null, t); b.type = 'button'; b.onclick = () => { ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + line; ta.focus(); }; chips.append(b); }
  btn.style.marginTop = '10px';
  const fill = () => {
    const users = (data.users || []).filter(u => ME && (u === ME.name || myGrantOn(u)));
    const cur = comp.value || (ME ? ME.name : ''), target = users.includes(cur) ? cur : users[0] || '';
    const sig = users.map(u => u + agentInfo(u).online).join() + target;
    if (comp._sig !== sig) { comp._sig = sig; comp.replaceChildren(...users.map(u => new Option((ME && u === ME.name ? '🖥 Mənim kompüterim' : '🖥 ' + u) + (agentInfo(u).online ? '' : ' · oflayn'), u))); comp.value = target; }
    const a = target ? agentInfo(target) : null, prof = a && a.ag ? a.ag.profile || 'basic' : '';
    note.textContent = !a || !a.ag ? '⚠️ Bu kompüterdə Planet agenti işləmir (📦 Paket).' : (a.online ? '🟢 onlayn' : '⚪ oflayn') + ' · profil: ' + prof + (prof === 'basic' ? ' · ⚠️ MCP alətləri üçün ən azı dev lazımdır' : '') + (a.projects.includes(ME_PROJECT) ? '' : ' · ⚠️ ' + ME_PROJECT + ' proyekti yoxdur');
    const cmds = (data.commands || []).filter(c => c.project === ME_PROJECT && ME && c.creator === ME.name && TL.proj && c.prompt.includes('Layihə #' + TL.proj.id + ' ')).slice(0, 4);
    const jsig = cmds.map(c => c.id + c.status + (c.result || '').length).join();
    if (jobs._sig !== jsig) {
      jobs._sig = jsig; jobs.replaceChildren();
      for (const c of cmds) {
        const st = { pending: '✋ təsdiq gözləyir', queued: '⏳ növbədə', running: '⚙️ işləyir', done: '✅ hazır', failed: '❌ alınmadı' }[c.status] || c.status;
        const lk = el('a', null, 'Claude Code-da aç ↗'); lk.href = 'code.php#' + encodeURIComponent(c.user) + '/' + (c.chat || c.id); lk.target = '_blank'; lk.rel = 'noopener'; lk.style.color = 'var(--accent)';
        const r = el('div', 'prow'), m = el('div', 'pm'); m.append(st + ' · ' + ago(c.created_at) + ' · ', lk);
        r.append(el('div', 'pt', (c.prompt.split('\n').find(x => x.startsWith('Tapşırıq: ')) || '').slice(10, 120) || 'Claude'), m);
        if (c.result) r.append(el('div', 'pb', c.result.slice(0, 600)));
        jobs.append(r);
      }
    }
  };
  comp.onchange = () => { comp._sig = ''; fill(); };
  btn.onclick = async () => {
    mxShowErr(err, '');
    if (!TL.proj) return mxShowErr(err, 'Əvvəlcə layihə aç.');
    const text = ta.value.trim(); if (!text) return mxShowErr(err, 'Tapşırığı yaz və ya şablon seç.');
    if (!comp.value) return mxShowErr(err, 'Kompüter seç.');
    if (TL.dirty) await tlSave();
    const prompt = ['[🎞 MEDIA Timeline] Layihə #' + TL.proj.id + ' "' + TL.proj.title + '" (hazırkı versiya v' + TL.ver + ').',
      'Bu video layihəsini planet-media MCP alətləri ilə redaktə et: əvvəlcə media_timeline_schema, sonra media_get_project(' + TL.proj.id + '). Dəyişiklikləri media_apply_edits ilə (base_version və qısa note ilə) bir neçə məntiqli addımda et; lazım olsa media_list_assets ilə mövcud mediadan istifadə et. Redaktor brauzerdə açıqdır, hər addım canlı görünür.',
      'Tapşırıq: ' + text,
      'Sonda qısa yaz: nə dəyişdi (klip id-ləri ilə) və yeni versiya nömrəsi.'].join('\n\n');
    btn.disabled = true; setText(btn, 'Göndərilir…');
    const { status, d } = await RMT('send', { target: comp.value, project: ME_PROJECT, prompt, fresh: true });
    btn.disabled = false; setText(btn, '🤖 Claude-a göndər');
    if (status !== 200) return mxShowErr(err, d.error || 'Alınmadı');
    miniToast(d.status === 'pending' ? '🤖 Göndərildi: ' + comp.value + ' təsdiqləməlidir' : '🤖 Claude işə başlayır: dəyişikliklər burada canlı görünəcək'); ta.value = ''; load();
  };
  sec.append(el('h4', null, '🤖 Claude ilə redaktə'), el('div', 'mx-help', 'Claude Code seçilən kompüterdə bu layihəni planet-media MCP ilə redaktə edir. İstənilən Claude Code sessiyasında da işləyir: "Planet-də #' + '… layihəsində başlığı dəyiş".'), tlField('Kompüter', comp, true), note, chips, ta, err, btn, jobs);
  sec._fill = fill; TL._claude = sec; fill();
  return sec;
}
// panel yenilənəndə kompüter vəziyyəti və işlər təzələnsin
const _tlRenderBase = tlRender;
tlRender = function (R) { _tlRenderBase(R); if (TL._claude) TL._claude._fill(); };
