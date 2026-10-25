/* ====== MEDIA universi: 📚 Prompt Library, 🔌 Fal Connection, ✂️ Edit Media ======
   index.php-dəki renderX → renderMediaPlanet(kind). Hər panel bir dəfə qurulur, sonra yalnız dəyişən hissələr yenilənir
   (renderX hər saniyə çağırılır: yazılan mətn itməməlidir). Server: media.php (fal açarı serverdə qalır), promptlar: world.php. */
(() => {
  const css = `
.mx-sec { margin-top: 14px; } .mx-sec:first-child { margin-top: 4px; }
.mx-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; } .mx-row > input, .mx-row > select { flex: 1; min-width: 140px; }
.mx-row .gbtn { white-space: nowrap; }
.mx-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.mx-chips button { border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.05); color: #e9e9f3; border-radius: 999px; padding: 4px 11px; font: inherit; font-size: 12.5px; cursor: pointer; }
.mx-chips button.on { background: rgba(255,122,61,.2); border-color: rgba(255,122,61,.6); color: #ffd7c2; }
.mx-key { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); font-size: 13.5px; }
.mx-key .sp { flex: 1; min-width: 140px; } .mx-key code { font-size: 12.5px; color: #ffd7c2; }
.mx-key[data-st="none"] { border-color: rgba(251,191,36,.45); background: rgba(251,191,36,.08); }
.mx-minfo { font-size: 12.5px; color: var(--muted); margin-top: 6px; display: flex; gap: 10px; align-items: flex-start; }
.mx-minfo img { width: 54px; height: 54px; object-fit: cover; border-radius: 8px; flex: none; }
.mx-minfo a { color: var(--accent); }
.mx-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; } .mx-fields .wide { grid-column: 1 / -1; }
.mx-fields .chk { display: flex; gap: 6px; align-items: center; font-size: 13px; margin-top: 14px; } .mx-fields .chk input { width: auto; margin: 0; }
.mx-more { margin-top: 8px; font-size: 13px; } .mx-more summary { cursor: pointer; color: var(--muted); }
.mx-help { font-size: 11.5px; color: var(--muted); margin-top: 3px; }
.mx-gal { display: grid; gap: 10px; }
.mx-gen { padding: 10px 12px; border: 1px solid rgba(255,255,255,.1); border-radius: 12px; background: rgba(255,255,255,.05); }
.mx-gen[data-st="failed"] { border-color: rgba(248,113,113,.45); }
.mx-gen .gm { font-size: 12px; color: var(--muted); display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.mx-gen .gp { font-size: 13px; margin: 4px 0 6px; white-space: pre-wrap; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.mx-gen .gerr { font-size: 12.5px; color: #fecaca; white-space: pre-wrap; overflow-wrap: anywhere; }
.mx-pill { font-size: 11px; font-weight: 700; border-radius: 999px; padding: 1px 8px; border: 1px solid rgba(255,255,255,.2); }
.mx-pill[data-st="queued"], .mx-pill[data-st="running"] { color: #fbbf24; border-color: rgba(251,191,36,.5); }
.mx-pill[data-st="done"] { color: #4ade80; border-color: rgba(74,222,128,.5); } .mx-pill[data-st="failed"] { color: #f87171; border-color: rgba(248,113,113,.5); }
.mx-media { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 6px; }
.mx-media img, .mx-media video { width: 100%; border-radius: 10px; background: #000; display: block; max-height: 260px; object-fit: contain; }
.mx-media audio { width: 100%; }
.mx-src { display: flex; gap: 10px; align-items: center; padding: 8px; border-radius: 12px; border: 1px dashed rgba(255,255,255,.2); margin-top: 8px; min-height: 64px; }
.mx-src img, .mx-src video { width: 110px; max-height: 90px; object-fit: contain; border-radius: 8px; background: #000; flex: none; }
.mx-src .sp { flex: 1; font-size: 12.5px; color: var(--muted); overflow-wrap: anywhere; }
.mx-note { font-size: 12.5px; padding: 8px 10px; border-radius: 10px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.1); color: rgba(233,233,243,.85); margin-top: 8px; }
.mx-note.warn { border-color: rgba(251,191,36,.45); background: rgba(251,191,36,.08); }
.mx-note a { color: var(--accent); }
.mx-spin { display: inline-block; width: 10px; height: 10px; border-radius: 50%; border: 2px solid rgba(251,191,36,.35); border-top-color: #fbbf24; animation: mxs 1s linear infinite; }
@keyframes mxs { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .mx-spin { animation: none; } }
@media (max-width: 560px) { .mx-fields { grid-template-columns: 1fr; } .mx-row > input, .mx-row > select { flex-basis: 100%; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
})();

const MX_CATS = {
  'text-to-image': '🖼 Foto', 'text-to-video': '🎞 Video', 'image-to-video': '🖼→🎞 Şəkildən video',
  'image-to-image': '🖌 Şəkil redaktəsi', 'video-to-video': '🎬 Video redaktəsi',
};
const MX_GEN_CATS = ['text-to-image', 'text-to-video', 'image-to-video'];
const MX_COMMON = ['image_size', 'aspect_ratio', 'resolution', 'duration', 'num_images', 'generate_audio', 'negative_prompt', 'seed', 'style', 'quality'];
const MX_SKIP = ['prompt', 'sync_mode', 'webhook_url', 'enable_safety_checker', 'safety_tolerance', 'output_format'];
const MX = {
  key: null, gens: null, gensAt: 0, models: {}, schema: {}, sel: {}, cat: 'text-to-image', pendingPrompt: '', editSrc: null,
  pf: { mine: true, q: '' }, editMode: 'ai',
};
const MXCAT_LS = 'mx:cat', MXSEL_LS = 'mx:sel';
try { MX.cat = localStorage.getItem(MXCAT_LS) || MX.cat; MX.sel = JSON.parse(localStorage.getItem(MXSEL_LS) || '{}') || {}; } catch (e) {}

async function MED(action, extra) {
  const r = await fetch('media.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({ error: 'Serverdən cavab oxunmadı (' + r.status + ')' }));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
async function MEDUP(file) {
  const fd = new FormData(); fd.append('action', 'upload'); fd.append('file', file);
  const r = await fetch('media.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
  const d = await r.json().catch(() => ({ error: 'Yükləmə alınmadı (' + r.status + ')' }));
  return { status: r.status, d };
}
const mxBtn = (text, cls, fn) => { const b = el('button', 'gbtn' + (cls ? ' ' + cls : ''), text); b.type = 'button'; if (fn) b.onclick = fn; return b; };
const mxErr = () => { const e = el('div', 'cmderr'); e.hidden = true; return e; };
const mxShowErr = (e, msg) => { e.hidden = !msg; setText(e, msg || ''); };
const mxPending = () => (MX.gens || []).some(g => g.status === 'queued' || g.status === 'running');
/** Planetin 3D vəziyyəti: fal-da gözləyən iş varsa "işləyir" */
function mediaState(kind) { return (kind === 'mfal' || kind === 'medit') && mxPending() ? 'working' : 'idle'; }
const mxOpen = () => typeof curPlanet === 'string' && curPlanet.startsWith('media:');

function renderMediaPlanet(kind) {
  if (kind === 'mprompts') return mxPrompts();
  if (!MX.key) mxLoadKey();
  if (MX.gens === null) mxRefresh();
  else if (MX.lastKind !== kind && Date.now() - MX.gensAt > 5000) mxRefresh();   // başqa MEDIA planetinə keçəndə təzələ
  MX.lastKind = kind;
  if (kind === 'mfal') return mxFal();
  return mxEdit();
}

/* ---------- Ümumi: açar, generasiyalar, model kataloqu ---------- */
async function mxLoadKey() {
  MX.key = { loading: true };
  const { status, d } = await MED('key_status');
  MX.key = status === 200 ? d : { has: false, error: d.error };
  if (mxOpen()) render();
}
let mxBusy = false;
async function mxRefresh() {
  if (mxBusy) return; mxBusy = true;
  try {
    const { status, d } = await MED('list');
    if (status === 200) { MX.gens = d.gens || []; MX.gensAt = Date.now(); if (mxOpen()) render(); }
    else if (MX.gens === null) MX.gens = [];
  } finally { mxBusy = false; }
}
// Panel açıqdırsa: gözləyən iş varsa 4 san-dan bir, yoxsa 30 san-dan bir yenilə
setInterval(() => { if (!mxOpen()) MX.lastKind = null; if (mxOpen() && MX.gens !== null && (mxPending() || Date.now() - MX.gensAt > 30000)) mxRefresh(); }, 4000);

async function mxModels(cat, q) {
  const k = cat + '|' + (q || '');
  if (!MX.models[k]) MX.models[k] = MED('models', { category: cat, q: q || '' }).then(({ status, d }) => {
    if (status !== 200) { delete MX.models[k]; throw new Error(d.error || 'Modellər yüklənmədi'); }
    return d.models || [];
  });
  return MX.models[k];
}
async function mxSchema(id) {
  if (!MX.schema[id]) MX.schema[id] = MED('schema', { endpoint_id: id }).then(({ status, d }) => {
    if (status !== 200) { delete MX.schema[id]; throw new Error(d.error || 'Model parametrləri yüklənmədi'); }
    return d.fields || [];
  });
  return MX.schema[id];
}
function mxMediaEl(m) {
  if (m.type === 'video') { const v = el('video'); v.src = m.url; v.controls = true; v.preload = 'metadata'; v.playsInline = true; return v; }
  if (m.type === 'audio') { const a = el('audio'); a.src = m.url; a.controls = true; return a; }
  const i = el('img'); i.src = m.url; i.loading = 'lazy'; i.alt = 'Generasiya nəticəsi'; return i;
}
/** Hazır generasiyalardakı bütün media (Edit Media-da mənbə seçimi üçün) */
const mxAllMedia = () => (MX.gens || []).filter(g => g.status === 'done').flatMap(g => g.media.map((m, i) => ({ ...m, label: '#' + g.id + (g.media.length > 1 ? '.' + (i + 1) : '') + ' · ' + (MX_CATS[g.kind] || g.kind) + ' · ' + (g.prompt || g.model).slice(0, 50) })));

/** Model seçici: kateqoriya üzrə canlı fal kataloqu + axtarış + sənəd linki. onPick(model) seçiləndə */
function mxModelPicker(getCat, onPick) {
  const wrap = el('div'), row = el('div', 'mx-row'), q = el('input'), sel = el('select'), info = el('div', 'mx-minfo'), err = mxErr();
  q.type = 'search'; q.placeholder = 'Model axtar (flux, kling, veo…)'; q.setAttribute('aria-label', 'Model axtar');
  sel.setAttribute('aria-label', 'Model');
  row.append(sel, q); wrap.append(row, info, err);
  let list = [], tm = 0, seq = 0;
  const showInfo = () => {
    const m = list.find(x => x.id === sel.value); info.replaceChildren();
    if (!m) return;
    if (m.thumbnail) { const i = el('img'); i.src = m.thumbnail; i.alt = ''; i.loading = 'lazy'; info.append(i); }
    const t = el('div'); t.append(el('div', null, m.description || m.id));
    const a = el('a', null, '📖 Sənəd və nümunələr'); a.href = m.url; a.target = '_blank'; a.rel = 'noopener';
    const api = el('a', null, 'API'); api.href = m.url.replace(/\/?$/, '/api'); api.target = '_blank'; api.rel = 'noopener';
    const ln = el('div'); ln.style.marginTop = '4px'; ln.append(el('code', null, m.id), ' · ', a, ' · ', api); t.append(ln); info.append(t);
  };
  const load = async () => {
    const cat = getCat(), my = ++seq;
    sel.replaceChildren(new Option('Yüklənir…', '')); sel.disabled = true; mxShowErr(err, '');
    try {
      const r = await mxModels(cat, q.value.trim());
      if (my !== seq) return;
      list = r; sel.replaceChildren();
      if (!r.length) sel.append(new Option('Model tapılmadı', ''));
      for (const m of r) sel.append(new Option(m.name + ' — ' + m.id, m.id));
      const keep = MX.sel[cat];
      if (keep && r.some(m => m.id === keep)) sel.value = keep;
      sel.disabled = !r.length; showInfo(); onPick(list.find(m => m.id === sel.value) || null);
    } catch (e) { if (my === seq) { sel.replaceChildren(new Option('—', '')); mxShowErr(err, e.message); onPick(null); } }
  };
  sel.onchange = () => { MX.sel[getCat()] = sel.value; try { localStorage.setItem(MXSEL_LS, JSON.stringify(MX.sel)); } catch (e) {} showInfo(); onPick(list.find(m => m.id === sel.value) || null); };
  q.oninput = () => { clearTimeout(tm); tm = setTimeout(load, 450); };
  wrap._load = load;
  return wrap;
}

/** fal modelinin parametr forması (OpenAPI-dən). opts.fill: {sahə: dəyər} (məs. mənbə şəkil URL-i). Qaytarır: {node, get()} */
function mxFieldsForm(fields, opts = {}) {
  const node = el('div'), main = el('div', 'mx-fields'), more = el('details', 'mx-more'), moreBox = el('div', 'mx-fields');
  more.append(el('summary', null, 'Əlavə parametrlər'), moreBox);
  const inputs = [];
  for (const f of fields) {
    if (MX_SKIP.includes(f.name)) continue;
    const isUrl = /url/i.test(f.name), arr = f.type === 'array';
    if (arr && !isUrl) continue;                                    // mürəkkəb massivlər (loras və s.) formada yoxdur
    if (f.type === 'object') continue;
    const box = el('div', isUrl || f.name === 'negative_prompt' ? 'wide' : null), id = 'mxf-' + Math.random().toString(36).slice(2, 8);
    const label = el('label', 'fl', f.name.replace(/_/g, ' ') + (f.required ? ' *' : '')); label.htmlFor = id;
    let inp;
    if (f.enum) {
      inp = el('select'); if (!f.required) inp.append(new Option('— standart' + (f.default != null ? ': ' + f.default : '') + ' —', ''));
      for (const v of f.enum) inp.append(new Option(String(v), String(v)));
      if (f.required && f.default != null) inp.value = String(f.default);
    } else if (f.type === 'boolean') {
      const c = el('label', 'chk'); inp = el('input'); inp.type = 'checkbox'; inp.checked = !!f.default; c.append(inp, ' ' + f.name.replace(/_/g, ' '));
      inp.id = id; box.append(c); if (f.description) box.append(el('div', 'mx-help', f.description));
      inputs.push({ f, inp }); (MX_COMMON.includes(f.name) ? main : moreBox).append(box); continue;
    } else if (f.type === 'integer' || f.type === 'number') {
      inp = el('input'); inp.type = 'number'; if (f.min != null) inp.min = f.min; if (f.max != null) inp.max = f.max; if (f.type === 'number') inp.step = 'any';
      if (f.default != null) inp.placeholder = String(f.default);
    } else if (arr) {
      inp = el('textarea'); inp.rows = 2; inp.placeholder = 'Hər sətirdə bir URL';
    } else {
      inp = el('input'); inp.type = isUrl ? 'url' : 'text'; if (f.default != null && f.default !== '') inp.placeholder = String(f.default);
      if (isUrl) inp.placeholder = 'https://…';
    }
    inp.id = id;
    if (opts.fill && opts.fill[f.name] != null) inp.value = arr ? [].concat(opts.fill[f.name]).join('\n') : String(opts.fill[f.name]);
    box.append(label, inp);
    if (isUrl && !opts.noUpload) {                                 // fayl yüklə → URL
      const up = el('input'); up.type = 'file'; up.accept = 'image/*,video/*'; up.hidden = true;
      const ub = mxBtn('📎 Fayl yüklə', null, () => up.click()); ub.style.marginTop = '6px';
      up.onchange = async () => {
        const file = up.files[0]; up.value = ''; if (!file) return;
        setText(ub, 'Yüklənir…'); ub.disabled = true;
        const { status, d } = await MEDUP(file);
        ub.disabled = false; setText(ub, '📎 Fayl yüklə');
        if (status !== 200) return miniToast(d.error || 'Yüklənmədi');
        inp.value = arr && inp.value.trim() ? inp.value.trim() + '\n' + d.url : d.url;
      };
      box.append(ub, up);
    }
    if (f.description) box.append(el('div', 'mx-help', f.description));
    inputs.push({ f, inp });
    (f.required || isUrl || f.enum && MX_COMMON.includes(f.name) || MX_COMMON.includes(f.name) ? main : moreBox).append(box);
  }
  node.append(main); if (moreBox.children.length) node.append(more);
  node._get = () => {
    const out = {};
    for (const { f, inp } of inputs) {
      if (f.type === 'boolean') { if (inp.checked !== !!f.default) out[f.name] = inp.checked; continue; }
      const v = inp.value.trim();
      if (!v) { if (f.required) throw new Error('"' + f.name + '" doldurulmalıdır'); continue; }
      if (f.type === 'array') out[f.name] = v.split(/\s+/).filter(Boolean);
      else if (f.type === 'integer') out[f.name] = parseInt(v, 10);
      else if (f.type === 'number') out[f.name] = parseFloat(v);
      else if (f.enum && f.enum.some(x => typeof x === 'number')) out[f.name] = Number(v);
      else out[f.name] = v;
    }
    return out;
  };
  return node;
}

/** Prompt sahəsi + "📚 Kitabxanadan" seçici */
function mxPromptBox(placeholder) {
  const wrap = el('div'), ta = el('textarea'), pick = el('select');
  ta.rows = 4; ta.placeholder = placeholder; ta.setAttribute('aria-label', 'Prompt');
  pick.setAttribute('aria-label', 'Kitabxanadan prompt seç');
  const fill = () => {
    const ps = (data.prompts || []).filter(p => ME && p.owner === ME.name).concat((data.prompts || []).filter(p => !ME || p.owner !== ME.name));
    const sig = ps.map(p => p.id + ':' + p.updated_at).join();
    if (pick._sig === sig) return; pick._sig = sig;
    pick.replaceChildren(new Option('📚 Kitabxanadan prompt…', ''));
    for (const p of ps.slice(0, 200)) pick.append(new Option((ME && p.owner === ME.name ? '' : p.owner + ': ') + p.title, p.id));
  };
  pick.onchange = () => { const p = (data.prompts || []).find(x => String(x.id) === pick.value); if (p) { ta.value = p.body; ta.focus(); } pick.value = ''; };
  const row = el('div', 'mx-row'); row.append(pick);
  wrap.append(row, ta); wrap._ta = ta; wrap._fill = fill; fill();
  return wrap;
}

/** Generasiya kartı (qalereya) */
function mxGenCard() {
  const n = el('div', 'mx-gen'), gm = el('div', 'gm'), gp = el('div', 'gp'), media = el('div', 'mx-media'), gerr = el('div', 'gerr'), pa = el('div', 'pa');
  pa.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin-top:8px';
  n.append(gm, gp, media, gerr, pa);
  n._upd = g => {
    const sig = [g.status, g.media.length, g.error, g.updated_at].join('|');
    if (n._sig === sig) return; n._sig = sig; n.dataset.st = g.status;
    const pill = el('span', 'mx-pill', { queued: 'növbədə', running: 'hazırlanır', done: 'hazır', failed: 'alınmadı' }[g.status] || g.status); pill.dataset.st = g.status;
    gm.replaceChildren(pill); if (g.status === 'queued' || g.status === 'running') gm.append(el('span', 'mx-spin'));
    gm.append(el('span', null, (MX_CATS[g.kind] || g.kind) + ' · ' + g.model + ' · ' + ago(g.created_at)));
    setText(gp, g.prompt || '(promptsuz)'); gp.title = g.prompt;
    media.replaceChildren(...g.media.map(mxMediaEl));
    setText(gerr, g.error || ''); gerr.hidden = !g.error;
    pa.replaceChildren();
    for (const m of g.media.slice(0, 4)) {
      const o = el('a', 'gbtn', '⬇ Aç'); o.href = m.url; o.target = '_blank'; o.rel = 'noopener'; o.style.cssText = 'padding:3px 10px;font-size:12px;text-decoration:none';
      pa.append(o, mxBtn('✂️ Redaktə et', null, () => { MX.editSrc = { url: m.url, type: m.type }; go('media:Edit Media'); }));
      if (g.media.length > 1) break;
    }
    if (g.media.length) pa.append(mxBtn('⧉ URL', null, async e => { try { await navigator.clipboard.writeText(g.media.map(m => m.url).join('\n')); setText(e.target, 'Kopyalandı ✓'); } catch (x) { setText(e.target, 'Alınmadı'); } }));
    if (g.prompt) pa.append(mxBtn('↻ Promptu götür', null, () => { MX.pendingPrompt = g.prompt; if (MX_GEN_CATS.includes(g.kind)) { MX.cat = g.kind; } go('media:Fal Connection'); if (mfBox) mfBox._applyPending(); }));
    pa.append(mxBtn('🗑', 'danger', async () => { if (!confirm('Bu generasiya siyahıdan silinsin?')) return; await MED('delete', { id: g.id }); MX.gens = MX.gens.filter(x => x.id !== g.id); render(); }));
    for (const b of pa.querySelectorAll('.gbtn')) { b.style.padding = '3px 10px'; b.style.fontSize = '12px'; }
  };
  return n;
}

/* ---------- 📚 Prompt Library ---------- */
let mpBox = null, mpEdit = 0;
function mxPrompts() {
  const box = $('mp-info');
  if (!mpBox) {
    mpBox = box;
    const bar = el('div', 'mx-row'), q = el('input'), add = mxBtn('+ Yeni prompt', 'go');
    q.type = 'search'; q.placeholder = 'Axtar: başlıq, teq, mətn'; q.setAttribute('aria-label', 'Promptlarda axtar');
    bar.append(q, add);
    const chips = el('div', 'mx-chips'), cMine = el('button', 'on', 'Mənim'), cAll = el('button', null, 'Komanda');
    cMine.type = cAll.type = 'button'; chips.append(cMine, cAll);
    const form = el('div', 'mcard'); form.hidden = true;
    const ft = el('div', 'mt'), title = el('input'), tags = el('input'), body = el('textarea'), err = mxErr(), fa = el('div', 'mx-row');
    title.placeholder = 'Başlıq'; title.maxLength = 200; tags.placeholder = 'Teqlər (vergüllə): video, reklam, instagram'; tags.maxLength = 200;
    body.rows = 8; body.className = 'pgout'; body.placeholder = 'Promptun mətni'; body.maxLength = 8000;
    title.setAttribute('aria-label', 'Başlıq'); tags.setAttribute('aria-label', 'Teqlər'); body.setAttribute('aria-label', 'Prompt mətni');
    const save = mxBtn('Yadda saxla', 'go'), cancel = mxBtn('Ləğv et'); fa.style.marginTop = '10px'; fa.append(save, cancel);
    form.append(ft, title, tags, body, err, fa);
    const cnt = el('div', 'lbl'), list = el('div'), empty = el('div', 'empty');
    box.append(el('div', 'mx-note', 'Promptların komanda ilə ümumi kitabxanadadır (Studio → Prompt Generator ilə eyni). Burada yaz, redaktə et, sil; 🔌 ilə fal-da işlət.'),
      el('div', 'mx-sec'), bar, chips, form, cnt, list, empty);
    const openForm = p => {
      mpEdit = p ? +p.id : 0; form.hidden = false; mxShowErr(err, '');
      setText(ft, p ? '✎ Redaktə: ' + p.title : '+ Yeni prompt');
      title.value = p ? p.title : ''; tags.value = p ? p.tags : ''; body.value = p ? p.body : '';
      title.focus(); form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    const closeForm = () => { form.hidden = true; mpEdit = 0; };
    add.onclick = () => openForm(null); cancel.onclick = closeForm;
    save.onclick = async () => {
      save.disabled = true;
      const { status, d } = await WLD('prompt_save', { id: mpEdit, title: title.value, body: body.value, tags: tags.value });
      save.disabled = false;
      if (status !== 200) return mxShowErr(err, d.error || 'Alınmadı');
      closeForm(); miniToast('📚 Prompt saxlandı'); load();
    };
    q.oninput = () => { MX.pf.q = q.value.trim().toLowerCase(); mpBox._sig = ''; mxPrompts(); };
    cMine.onclick = () => { MX.pf.mine = true; cMine.classList.add('on'); cAll.classList.remove('on'); mpBox._sig = ''; mxPrompts(); };
    cAll.onclick = () => { MX.pf.mine = false; cAll.classList.add('on'); cMine.classList.remove('on'); mpBox._sig = ''; mxPrompts(); };
    box._r = { cnt, list, empty, openForm };
  }
  const all = data.prompts || [], mine = all.filter(p => ME && p.owner === ME.name);
  const src = MX.pf.mine ? mine : all, q = MX.pf.q;
  const shown = src.filter(p => !q || (p.title + ' ' + p.tags + ' ' + p.body).toLowerCase().includes(q));
  const sig = [MX.pf.mine, q, shown.map(p => p.id + ':' + p.updated_at).join()].join('|');
  if (box._sig === sig) return; box._sig = sig;
  const r = box._r;
  setText(r.cnt, (MX.pf.mine ? 'Mənim promptlarım' : 'Komandanın promptları') + ' · ' + shown.length + (q ? ' / ' + src.length : ''));
  r.empty.hidden = shown.length > 0;
  setText(r.empty, q ? 'Axtarışa uyğun prompt yoxdur.' : MX.pf.mine ? 'Hələ promptun yoxdur. "+ Yeni prompt" ilə əlavə et.' : 'Kitabxana boşdur.');
  sync(r.list, shown, p => p.id, () => {
    const n = el('div', 'prow'), pt = el('div', 'pt'), pm = el('div', 'pm'), pb = el('div', 'pb'), tg = el('button', 'pbt', 'Tam göstər'), pa = el('div', 'pa');
    const cp = mxBtn('Kopyala'), use = mxBtn('🔌 fal-da işlət'), ed = mxBtn('Redaktə'), del = mxBtn('Sil', 'danger');
    tg.type = 'button'; tg.onclick = () => { pb.classList.toggle('open'); setText(tg, pb.classList.contains('open') ? 'Yığ' : 'Tam göstər'); };
    pa.append(cp, use, ed, del); n.append(pt, pm, pb, tg, pa);
    cp.onclick = async () => { try { await navigator.clipboard.writeText(n._it.body); setText(cp, 'Kopyalandı ✓'); } catch (e) { setText(cp, 'Alınmadı'); } setTimeout(() => setText(cp, 'Kopyala'), 1600); };
    use.onclick = () => { MX.pendingPrompt = n._it.body; go('media:Fal Connection'); if (mfBox) mfBox._applyPending(); };
    ed.onclick = () => box._r.openForm(n._it);
    del.onclick = async () => { if (!confirm('"' + n._it.title + '" silinsin?')) return; const { status, d } = await WLD('prompt_delete', { id: n._it.id }); if (status !== 200) return alert(d.error || 'Alınmadı'); load(); };
    n._r = { pt, pm, pb, tg, ed, del };
    return n;
  }, (n, p) => {
    n._it = p; const r2 = n._r, own = ME && (ME.role === 'admin' || ME.name === p.owner);
    setText(r2.pt, p.title);
    setText(r2.pm, p.owner + ' · ' + ago(p.updated_at) + (p.tags ? ' · #' + p.tags.split(',').map(x => x.trim()).filter(Boolean).join(' #') : ''));
    setText(r2.pb, p.body); r2.tg.hidden = p.body.length < 220 && !p.body.includes('\n\n');
    r2.ed.hidden = r2.del.hidden = !own;
  });
}

/* ---------- 🔌 Fal Connection ---------- */
/** Açar bölməsi: yoxdursa daxil et; varsa maska + dəyiş + sil */
function mxKeyBox() {
  const box = el('div', 'mx-key'), err = mxErr(), wrap = el('div');
  wrap.append(box, err);
  let editing = false;
  const draw = () => {
    const k = MX.key || { loading: true };
    const sig = [k.loading, k.has, k.hint, k.updated_at, editing].join('|');
    if (box._sig === sig) return; box._sig = sig; box.replaceChildren();
    box.dataset.st = k.has ? 'ok' : 'none';
    if (k.loading) { box.append(el('span', 'sp', 'fal açarı yoxlanır…')); return; }
    if (k.has && !editing) {
      const t = el('span', 'sp'); t.append('🔑 fal açarın qoşulub: ', el('code', null, k.hint), ' · ' + ago(k.updated_at));
      box.append(t, mxBtn('Dəyiş', null, () => { editing = true; draw(); }),
        mxBtn('Sil', 'danger', async () => { if (!confirm('fal açarı silinsin? Generasiya dayanacaq.')) return; await MED('key_delete'); MX.key = { has: false }; render(); }));
      return;
    }
    const inp = el('input'), save = mxBtn('Saxla', 'go');
    inp.type = 'password'; inp.autocomplete = 'off'; inp.placeholder = k.has ? 'Yeni fal açarı' : 'fal açarını yapışdır (fal.ai → Dashboard → Keys)'; inp.setAttribute('aria-label', 'fal API açarı');
    const a = el('a', null, 'Açar al ↗'); a.href = 'https://fal.ai/dashboard/keys'; a.target = '_blank'; a.rel = 'noopener'; a.style.color = 'var(--accent)'; a.style.fontSize = '12.5px';
    box.append(el('span', null, k.has ? '🔑 Açarı dəyiş:' : '🔑 fal açarı yoxdur.'), inp, save);
    if (k.has) box.append(mxBtn('Ləğv et', null, () => { editing = false; mxShowErr(err, ''); draw(); }));
    box.append(a);
    const go1 = async () => {
      save.disabled = true; setText(save, 'Yoxlanır…'); mxShowErr(err, '');
      const { status, d } = await MED('key_set', { key: inp.value });
      save.disabled = false; setText(save, 'Saxla');
      if (status !== 200) return mxShowErr(err, d.error || 'Alınmadı');
      inp.value = ''; editing = false; MX.key = d; MX.models = {}; miniToast('🔌 fal açarı yoxlandı və saxlandı'); render();
    };
    save.onclick = go1; inp.onkeydown = e => { if (e.key === 'Enter') go1(); };
  };
  wrap._draw = draw;
  return wrap;
}

let mfBox = null;
function mxFal() {
  const box = $('mf-info');
  if (!mfBox) {
    mfBox = box;
    const key = mxKeyBox();
    const chips = el('div', 'mx-chips');
    for (const c of MX_GEN_CATS) { const b = el('button', null, MX_CATS[c]); b.type = 'button'; b.dataset.c = c; chips.append(b); }
    const pbox = mxPromptBox('Nə yaradaq? Məs: "Bakı bulvarında gün batımı, kinematik, 35mm"');
    const params = el('div'), err = mxErr(), genBtn = mxBtn('✨ Yarat', 'go'), cost = el('span', 'mx-help');
    let model = null, form = null;
    const picker = mxModelPicker(() => MX.cat, async m => {
      model = m; params.replaceChildren(); form = null;
      if (!m) return;
      params.append(el('div', 'mx-help', 'Parametrlər yüklənir…'));
      try {
        const fields = await mxSchema(m.id);
        if (model !== m) return;
        form = mxFieldsForm(fields); params.replaceChildren(form);
        box._hasPrompt = fields.some(f => f.name === 'prompt');
      } catch (e) { params.replaceChildren(el('div', 'cmderr', e.message)); }
    });
    const setCat = c => {
      MX.cat = c; try { localStorage.setItem(MXCAT_LS, c); } catch (e) {}
      for (const b of chips.children) b.classList.toggle('on', b.dataset.c === c);
      picker._load();
    };
    chips.onclick = e => { const b = e.target.closest('button'); if (b) setCat(b.dataset.c); };
    const brow = el('div', 'mx-row'); brow.style.marginTop = '12px'; brow.append(genBtn, cost);
    const galLbl = el('div', 'lbl'), gal = el('div', 'mx-gal'), galEmpty = el('div', 'empty', 'Hələ generasiya yoxdur. Model seç, prompt yaz, "✨ Yarat".');
    box.append(el('div', 'lbl', '🔑 fal.ai açarı'), key,
      el('div', 'lbl', '🎛 Model'), chips, picker,
      el('div', 'lbl', '✍️ Prompt'), pbox, params, err, brow,
      galLbl, gal, galEmpty);
    genBtn.onclick = async () => {
      mxShowErr(err, '');
      if (!MX.key || !MX.key.has) return mxShowErr(err, 'Əvvəlcə yuxarıda fal açarını əlavə et.');
      if (!model) return mxShowErr(err, 'Model seç.');
      let input;
      try { input = form ? form._get() : {}; } catch (e) { return mxShowErr(err, e.message); }
      const p = pbox._ta.value.trim();
      if (p) input.prompt = p; else if (box._hasPrompt !== false && MX.cat.startsWith('text-')) return mxShowErr(err, 'Prompt yaz.');
      genBtn.disabled = true; setText(genBtn, 'Göndərilir…');
      const { status, d } = await MED('submit', { endpoint_id: model.id, kind: MX.cat, input });
      genBtn.disabled = false; setText(genBtn, '✨ Yarat');
      if (status !== 200) return mxShowErr(err, d.error || 'Alınmadı');
      miniToast('✨ fal-a göndərildi: ' + model.name); mxRefresh();
    };
    box._applyPending = () => { if (MX.pendingPrompt) { pbox._ta.value = MX.pendingPrompt; MX.pendingPrompt = ''; if (MX_GEN_CATS.includes(MX.cat)) setCat(MX.cat); } };
    box._r = { key, pbox, gal, galLbl, galEmpty };
    setCat(MX_GEN_CATS.includes(MX.cat) ? MX.cat : 'text-to-image');
  }
  const r = box._r;
  r.key._draw(); r.pbox._fill(); box._applyPending();
  const gens = (MX.gens || []).filter(g => MX_GEN_CATS.includes(g.kind));
  setText(r.galLbl, '🖼 Generasiyalarım · ' + (MX.gens === null ? '…' : gens.length));
  r.galEmpty.hidden = MX.gens === null || gens.length > 0;
  sync(r.gal, gens, g => g.id, mxGenCard, (n, g) => n._upd(g));
}

/* ---------- ✂️ Edit Media ---------- */
const ME_PROJECT = 'media-studio';
let meBox = null;
function mxEdit() {
  const box = $('me-info');
  if (!meBox) {
    meBox = box;
    /* 1. Mənbə */
    const srcView = el('div', 'mx-src'), srcRow = el('div', 'mx-row'), pick = el('select'), up = el('input'), upBtn = mxBtn('📎 Yüklə'), url = el('input');
    pick.setAttribute('aria-label', 'Generasiyalardan mənbə seç');
    up.type = 'file'; up.accept = 'image/*,video/*'; up.hidden = true; upBtn.onclick = () => up.click();
    url.type = 'url'; url.placeholder = 'və ya şəkil/video URL-i yapışdır'; url.setAttribute('aria-label', 'Mənbə URL');
    srcRow.append(pick, upBtn, up);
    const setSrc = s => { MX.editSrc = s; box._srcSig = ''; mxEdit(); };
    pick.onchange = () => { const m = mxAllMedia().find(x => x.url === pick.value); if (m) setSrc({ url: m.url, type: m.type }); };
    url.onchange = () => { const u = url.value.trim(); if (/^https:\/\//.test(u)) setSrc({ url: u, type: /\.(mp4|webm|mov)(\?|$)/i.test(u) ? 'video' : 'image' }); };
    up.onchange = async () => {
      const f = up.files[0]; up.value = ''; if (!f) return;
      setText(upBtn, 'Yüklənir…'); upBtn.disabled = true;
      const { status, d } = await MEDUP(f);
      upBtn.disabled = false; setText(upBtn, '📎 Yüklə');
      if (status !== 200) return miniToast(d.error || 'Yüklənmədi');
      setSrc({ url: d.url, type: d.type });
    };
    /* 2. Rejim */
    const modes = el('div', 'mx-chips'), mAi = el('button', null, '🤖 AI redaktə (fal)'), mCut = el('button', null, '🎬 Montaj (Claude + HyperFrames)');
    mAi.type = mCut.type = 'button'; modes.append(mAi, mCut);
    const aiBox = el('div'), cutBox = el('div');
    /* 2a. AI redaktə: fal image-to-image / video-to-video */
    const aiCat = () => MX.editSrc && MX.editSrc.type === 'video' ? 'video-to-video' : 'image-to-image';
    const aiParams = el('div'), aiErr = mxErr(), aiBtn = mxBtn('🤖 Redaktə et', 'go'), aiPrompt = mxPromptBox('Nəyi dəyişək? Məs: "arxa fonu gecə şəhərinə çevir, işıqları neon et"');
    let aiModel = null, aiForm = null;
    const aiPicker = mxModelPicker(aiCat, async m => {
      aiModel = m; aiForm = null; aiParams.replaceChildren(); if (!m) return;
      aiParams.append(el('div', 'mx-help', 'Parametrlər yüklənir…'));
      try {
        const fields = await mxSchema(m.id); if (aiModel !== m) return;
        aiForm = { fields }; box._fillAi();
      } catch (e) { aiParams.replaceChildren(el('div', 'cmderr', e.message)); }
    });
    box._fillAi = () => {                                        // mənbə dəyişəndə URL sahələri yenidən doldurulur
      if (!aiForm) return;
      const fill = {}, s = MX.editSrc;
      if (s) for (const f of aiForm.fields) {
        if (!/url/i.test(f.name)) continue;
        const want = s.type === 'video' ? /video/i.test(f.name) : /image/i.test(f.name) && !/end_image|mask/i.test(f.name);
        if (want) fill[f.name] = f.type === 'array' ? [s.url] : s.url;
      }
      aiForm.node = mxFieldsForm(aiForm.fields, { fill }); aiParams.replaceChildren(aiForm.node);
    };
    const aiRow = el('div', 'mx-row'); aiRow.style.marginTop = '12px'; aiRow.append(aiBtn);
    aiBox.append(el('div', 'lbl', '🎛 Redaktə modeli'), aiPicker, el('div', 'lbl', '✍️ Nə dəyişsin?'), aiPrompt, aiParams, aiErr, aiRow);
    aiBtn.onclick = async () => {
      mxShowErr(aiErr, '');
      if (!MX.key || !MX.key.has) return mxShowErr(aiErr, 'Əvvəlcə 🔌 Fal Connection planetində fal açarını əlavə et.');
      if (!MX.editSrc) return mxShowErr(aiErr, 'Əvvəlcə yuxarıda mənbə şəkil/video seç.');
      if (!aiModel || !aiForm || !aiForm.node) return mxShowErr(aiErr, 'Model seç.');
      let input; try { input = aiForm.node._get(); } catch (e) { return mxShowErr(aiErr, e.message); }
      const p = aiPrompt._ta.value.trim(); if (!p) return mxShowErr(aiErr, 'Nəyi dəyişmək istədiyini yaz.');
      input.prompt = p;
      aiBtn.disabled = true; setText(aiBtn, 'Göndərilir…');
      const { status, d } = await MED('submit', { endpoint_id: aiModel.id, kind: aiCat(), input });
      aiBtn.disabled = false; setText(aiBtn, '🤖 Redaktə et');
      if (status !== 200) return mxShowErr(aiErr, d.error || 'Alınmadı');
      miniToast('🤖 Redaktə fal-a göndərildi'); mxRefresh();
    };
    /* 2b. Montaj: seçilən kompüterdə Claude Code + HyperFrames (media-studio proyekti) */
    const comp = el('select'), cutPrompt = mxPromptBox('Montaj tapşırığı. Məs: "15 saniyəlik Instagram reels: videonu 3 hissəyə böl, başlığa animasiyalı yazı, sonda logo, fon musiqisi üçün yer saxla"');
    const cutErr = mxErr(), cutBtn = mxBtn('🎬 Claude-a göndər', 'go'), cutNote = el('div', 'mx-note'), cutList = el('div');
    comp.setAttribute('aria-label', 'Montajın işləyəcəyi kompüter');
    const cutRow = el('div', 'mx-row'); cutRow.style.marginTop = '12px'; cutRow.append(cutBtn);
    cutBox.append(el('div', 'mx-note', 'After Effects kimi kompozisiya: Claude Code seçilən kompüterdə HyperFrames (HTML + GSAP → MP4, açıq mənbə, Apache-2.0) ilə montaj edir və MP4 render edir. Mənbə URL-ləri ona ötürülür.'),
      el('div', 'lbl', '🖥 Kompüter'), comp, cutNote, el('div', 'lbl', '✍️ Montaj tapşırığı'), cutPrompt, cutErr, cutRow, el('div', 'lbl', '🕘 Son montajlar'), cutList);
    comp.onchange = () => { box._cutSig = ''; mxEdit(); };
    cutBtn.onclick = async () => {
      mxShowErr(cutErr, '');
      const target = comp.value, text = cutPrompt._ta.value.trim();
      if (!target) return mxShowErr(cutErr, 'Kompüter seç.');
      if (!text) return mxShowErr(cutErr, 'Montaj tapşırığını yaz.');
      const s = MX.editSrc;
      const prompt = ['[🎬 MEDIA · Edit Media] HyperFrames ilə montaj.',
        s ? 'Mənbə fayl (əvvəlcə `curl -L -o assets/<ad> "<URL>"` ilə assets/ qovluğuna endir):\n- ' + s.url + ' (' + (s.type === 'video' ? 'video' : 'şəkil') + ')' : 'Mənbə fayl yoxdur: yalnız mətn, forma və animasiya ilə qur.',
        'Tapşırıq: ' + text,
        'Qaydalar: bu qovluqdakı HyperFrames layihəsindən istifadə et (CLAUDE.md və HyperFrames skill-lərinə bax). Kompozisiyanı HTML/CSS/GSAP ilə yaz, `npx hyperframes render` ilə out/ qovluğuna MP4 render et. Sonda nəticə faylının tam yolunu, müddətini, ölçüsünü və qısa təsvirini yaz.'].join('\n\n');
      cutBtn.disabled = true; setText(cutBtn, 'Göndərilir…');
      const { status, d } = await RMT('send', { target, project: ME_PROJECT, prompt, fresh: true });
      cutBtn.disabled = false; setText(cutBtn, '🎬 Claude-a göndər');
      if (status !== 200) return mxShowErr(cutErr, d.error || 'Alınmadı');
      miniToast(d.status === 'pending' ? '🎬 Göndərildi: ' + target + ' təsdiqləməlidir' : '🎬 Montaj növbəyə düşdü'); load();
    };
    const setMode = m => { MX.editMode = m; mAi.classList.toggle('on', m === 'ai'); mCut.classList.toggle('on', m === 'cut'); aiBox.hidden = m !== 'ai'; cutBox.hidden = m !== 'cut'; if (m === 'ai') aiPicker._load(); };
    mAi.onclick = () => setMode('ai'); mCut.onclick = () => setMode('cut');
    const resLbl = el('div', 'lbl'), resGal = el('div', 'mx-gal');
    box.append(el('div', 'lbl', '🎞 Mənbə'), srcView, srcRow, url, el('div', 'lbl', '🛠 Necə redaktə edək?'), modes, aiBox, cutBox, resLbl, resGal);
    box._r = { srcView, pick, comp, cutNote, cutList, aiPrompt, cutPrompt, resLbl, resGal, aiCat, aiPicker };
    setMode(MX.editMode);
  }
  const r = box._r;
  r.aiPrompt._fill(); r.cutPrompt._fill();
  /* mənbə görünüşü */
  const s = MX.editSrc, all = mxAllMedia();
  const sSig = [s && s.url, all.map(m => m.url).join()].join('|');
  if (box._srcSig !== sSig) {
    box._srcSig = sSig;
    r.srcView.replaceChildren();
    if (s) { r.srcView.append(mxMediaEl(s)); const t = el('div', 'sp'); t.append(el('div', null, s.type === 'video' ? '🎞 Video' : '🖼 Şəkil'), el('div', null, s.url)); r.srcView.append(t); }
    else r.srcView.append(el('div', 'sp', 'Mənbə seçilməyib: generasiyalarından seç, fayl yüklə və ya URL yapışdır.'));
    r.pick.replaceChildren(new Option(all.length ? '🖼 Generasiyalarımdan seç…' : 'Hələ hazır generasiya yoxdur', ''));
    for (const m of all) r.pick.append(new Option(m.label, m.url));
    if (s && all.some(m => m.url === s.url)) r.pick.value = s.url;
    const cat = r.aiCat();
    if (box._aiCat !== cat) { box._aiCat = cat; if (MX.editMode === 'ai') r.aiPicker._load(); }
    else box._fillAi();
  }
  /* montaj: kompüterlər və vəziyyət */
  const users = (data.users || []).filter(u => ME && (u === ME.name || myGrantOn(u)));
  const cur = r.comp.value || (ME ? ME.name : '');
  const target = users.includes(cur) ? cur : users[0] || '';
  const a = target ? agentInfo(target) : null;
  const cmds = (data.commands || []).filter(c => c.project === ME_PROJECT && ME && c.creator === ME.name).slice(0, 6);
  const cSig = [users.join(), target, a && a.online, a && a.projects.join(), a && a.ag && a.ag.profile, cmds.map(c => c.id + c.status).join()].join('|');
  if (box._cutSig !== cSig) {
    box._cutSig = cSig;
    r.comp.replaceChildren(...users.map(u => new Option((ME && u === ME.name ? '🖥 Mənim kompüterim (' + u + ')' : '🖥 ' + u) + (agentInfo(u).online ? '' : ' · oflayn'), u)));
    r.comp.value = target;
    r.cutNote.replaceChildren(); r.cutNote.className = 'mx-note';
    if (!a || !a.ag) { r.cutNote.className = 'mx-note warn'; r.cutNote.append('Bu kompüterdə Planet agenti işləmir. Agenti qur: başlıqdakı 📦 Paket.'); }
    else {
      const prof = (a.ag && a.ag.profile) || 'basic', hasPrj = a.projects.includes(ME_PROJECT);
      if (!hasPrj || prof === 'basic') r.cutNote.className = 'mx-note warn';
      r.cutNote.append((a.online ? '🟢 onlayn' : '⚪ oflayn') + ' · agent profili: ' + prof + ' · ' + (hasPrj ? '✅ ' + ME_PROJECT + ' proyekti var' : '⚠️ ' + ME_PROJECT + ' proyekti qeydiyyatda deyil'));
      if (!hasPrj || prof === 'basic') r.cutNote.append(el('div', 'mx-help', 'Bir dəfəlik quraşdırma: kompüterdə `~/Documents/projects/' + ME_PROJECT + '` qovluğunda HyperFrames (`npx hyperframes init`, Node 22+, ffmpeg) qur, qovluğu Planet-ə qeydiyyatdan keçir və agent profilini ən azı dev et (CLAUDE_TEAM_PROFILE=dev): render üçün `npx` lazımdır.'));
    }
    r.cutList.replaceChildren();
    if (!cmds.length) r.cutList.append(el('div', 'empty', 'Hələ montaj göndərilməyib.'));
    for (const c of cmds) {
      const n = el('div', 'prow'), st = { pending: '✋ təsdiq gözləyir', queued: '⏳ növbədə', running: '⚙️ işləyir', done: '✅ hazır', failed: '❌ alınmadı' }[c.status] || c.status;
      const link = el('a', null, 'Claude Code-da aç ↗'); link.href = 'code.php#' + encodeURIComponent(c.user) + '/' + (c.chat || c.id); link.target = '_blank'; link.rel = 'noopener'; link.style.color = 'var(--accent)';
      const meta = el('div', 'pm'); meta.append(c.user + ' · ' + st + ' · ' + ago(c.created_at) + ' · ', link);
      n.append(el('div', 'pt', (c.prompt.split('\n\n').find(x => x.startsWith('Tapşırıq: ')) || '').slice(10, 140) || 'Montaj'), meta);
      if (c.result) { const b = el('div', 'pb', c.result); n.append(b); }
      r.cutList.append(n);
    }
  }
  /* AI redaktə nəticələri */
  const res = (MX.gens || []).filter(g => g.kind === 'image-to-image' || g.kind === 'video-to-video');
  setText(r.resLbl, '🖌 AI redaktələrim · ' + (MX.gens === null ? '…' : res.length));
  sync(r.resGal, res, g => g.id, mxGenCard, (n, g) => n._upd(g));
}
