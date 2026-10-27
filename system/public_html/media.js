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
  wrap._search = term => { q.value = term; clearTimeout(tm); load(); };   // hazır axtarış çipləri üçün
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

/* ---------- ✂️ Edit Media: tam ekran redaktor ----------
   Solda media kitabxanası (generasiyalar + yükləmələr, sürüşdür-burax), ortada böyük səhnə (əvvəl/sonra),
   sağda idarə: 🤖 AI redaktə (fal: şəkil → şəkil, şəkil → video, video → video) və ya 🎬 Montaj (Claude + HyperFrames),
   aşağıda redaktə tarixçəsi. Planet açıq olduqca tam ekran görünür; ✕ / Esc kainata qaytarır. */
const ME_PROJECT = 'media-studio';
const MXE_OPS = {
  'image-to-image': { icon: '🖌', name: 'Şəkli redaktə et', src: 'image', hint: 'Mətnlə dəyiş: fon, rəng, obyekt, stil. Upscale və fon silmə də buradadır.',
    presets: [['Hamısı', ''], ['Redaktə', 'edit'], ['Upscale', 'upscale'], ['Fon sil', 'background'], ['Stil', 'style'], ['Üz/portret', 'face']] },
  'image-to-video': { icon: '🎞', name: 'Şəkli videoya çevir', src: 'image', hint: 'Şəkli canlandır: kamera hərəkəti, animasiya, səhnə. Promptda hərəkəti təsvir et.',
    presets: [['Hamısı', ''], ['Kling', 'kling'], ['Veo', 'veo'], ['Seedance', 'seedance'], ['Hailuo', 'minimax'], ['Wan', 'wan'], ['LTX', 'ltx']] },
  'video-to-video': { icon: '🎬', name: 'Videonu redaktə et', src: 'video', hint: 'Videonun stilini dəyiş, keyfiyyətini artır, dodaq sinxronu, effektlər.',
    presets: [['Hamısı', ''], ['Upscale', 'upscale'], ['Stil', 'style'], ['Redaktə', 'edit'], ['Lipsync', 'lipsync'], ['Səs', 'audio'], ['Fon sil', 'background']] },
};
const MXE_TPL = [
  ['📱 Reels 9:16 · 15 san', 'Format: şaquli 9:16 (1080×1920), 15 saniyə, Instagram Reels / TikTok üçün.'],
  ['🔤 Animasiyalı başlıq', 'Əvvəldə 2 saniyəlik animasiyalı başlıq yazısı (GSAP ilə, iri, oxunaqlı).'],
  ['💬 Altyazı', 'Aşağıda oxunaqlı altyazılar (ağ mətn, yarımşəffaf fon), cümlə-cümlə görünsün.'],
  ['🖼 Slayd-şou', 'Şəkilləri ardıcıl slayd-şou kimi göstər: yumşaq keçidlər, yavaş Ken Burns hərəkəti.'],
  ['✂️ Kəs və birləşdir', 'Videoların ən maraqlı hissələrini kəs, ritmik keçidlərlə birləşdir.'],
  ['🏷 Logo outro', 'Sonda 2 saniyəlik logo/brend ekranı (qaranlıq fon, mərkəzdə ad).'],
];
const MXUP_LS = 'mx:uploads';
try { MX.uploads = JSON.parse(localStorage.getItem(MXUP_LS) || '[]').filter(u => u && u.url && Date.now() - u.at < 13 * 86400e3); } catch (e) { MX.uploads = []; }
MX.editOp = MX.editOp || 'image-to-image'; MX.libF = 'all'; MX.cutSel = []; MX.view = 0; MX.cmp = true;

/** Generasiyanın giriş mediası (əvvəl): parametrlərdəki ilk *url* sahəsi */
function mxGenSource(g) {
  const p = g.params || {};
  for (const k of Object.keys(p)) {
    if (!/url/i.test(k) || /end_image|mask|audio/i.test(k)) continue;
    const v = Array.isArray(p[k]) ? p[k][0] : p[k];
    if (typeof v === 'string' && /^https?:\/\//.test(v)) return { url: v, type: /video/i.test(k) || /\.(mp4|webm|mov)(\?|$)|f=[a-f0-9]{32}\.(mp4|webm|mov)/i.test(v) ? 'video' : 'image' };
  }
  return null;
}
const mxEditGens = () => (MX.gens || []).filter(g => MXE_OPS[g.kind] && mxGenSource(g));
/** Kitabxana: yükləmələr + hazır generasiyalar (təkrarsız) */
function mxLib() {
  const out = [], seen = new Set();
  const add = it => { if (!seen.has(it.url)) { seen.add(it.url); out.push(it); } };
  for (const u of MX.uploads) add({ url: u.url, type: u.type, label: '📎 ' + (u.name || 'Yükləmə'), at: Math.floor(u.at / 1000) });
  for (const g of (MX.gens || []).filter(x => x.status === 'done')) g.media.forEach((m, i) => add({ url: m.url, type: m.type, label: (MX_CATS[g.kind] || g.kind) + ' · ' + (g.prompt || g.model).slice(0, 60) + (g.media.length > 1 ? ' (' + (i + 1) + ')' : ''), at: g.created_at, gen: g.id }));
  for (const a of MX.assets || []) if (a.kind !== 'generation') add({ url: a.url, type: a.type, label: (a.kind === 'export' ? '🎬 ' : '📎 ') + (a.name || 'Fayl'), at: a.created_at });   // serverdəki yükləmələr və renderlər
  return out.filter(x => x.type === 'image' || x.type === 'video' || (x.type === 'audio' && MX.editMode === 'timeline')).sort((a, b) => b.at - a.at);
}
function mxThumb(m) {
  if (m.type === 'audio') { const s = el('span', 'mxe-aud', '🎵'); return s; }
  if (m.type === 'video') { const v = el('video'); v.src = m.url + (m.url.includes('#') ? '' : '#t=0.1'); v.muted = true; v.preload = 'metadata'; v.playsInline = true; return v; }
  const i = el('img'); i.src = m.url; i.loading = 'lazy'; i.alt = ''; return i;
}
async function mxUploadFiles(files) {
  for (const f of [...files].slice(0, 6)) {
    miniToast('📎 Yüklənir: ' + f.name);
    const { status, d } = await MEDUP(f);
    if (status !== 200) { miniToast(d.error || 'Yüklənmədi: ' + f.name); continue; }
    MX.uploads.unshift({ url: d.url, type: d.type, name: f.name.slice(0, 60), at: Date.now() });
    MX.uploads = MX.uploads.slice(0, 60);
    try { localStorage.setItem(MXUP_LS, JSON.stringify(MX.uploads)); } catch (e) {}
    mxePick({ url: d.url, type: d.type });
  }
}
/** Mənbə seç (AI rejimində tək; montajda siyahıya əlavə/çıxar) */
function mxePick(m) {
  if (MX.editMode === 'timeline' && typeof tlAddMedia === 'function') return tlAddMedia(m);   // Timeline: oynatma xəttinə əlavə et
  if (MX.editMode === 'cut') {
    const i = MX.cutSel.findIndex(x => x.url === m.url);
    if (i >= 0) MX.cutSel.splice(i, 1); else if (MX.cutSel.length < 12) MX.cutSel.push({ url: m.url, type: m.type });
  }
  MX.editSrc = { url: m.url, type: m.type }; MX.view = 0;
  if (MXE_OPS[MX.editOp].src !== m.type) MX.editOp = m.type === 'video' ? 'video-to-video' : 'image-to-image';
  mxEdit();
}

/* Tam ekran redaktorun DOM-u bir dəfə qurulur */
let mxeRoot = null;
function mxeBuild() {
  const css = `
.mxe { position: fixed; inset: 0; z-index: 55; display: grid; grid-template-rows: auto 1fr auto; background: radial-gradient(1200px 600px at 70% -10%, rgba(255,106,61,.10), transparent 60%), #07080f; color: #ececf1; }
.mxe[hidden] { display: none; }
html.mxe-on, html.mxe-on body { overflow: hidden; }
html.mxe-on .rm { display: none; }
.mxe-top { display: flex; align-items: center; gap: 12px; padding: 10px 16px; border-bottom: 1px solid rgba(255,255,255,.08); background: rgba(10,11,20,.85); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); min-width: 0; }
.mxe-top .ttl { font-weight: 700; font-size: 16px; white-space: nowrap; } .mxe-top .sub { font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mxe-top .sp { flex: 1; min-width: 8px; }
.mxe-tabs { display: flex; gap: 4px; padding: 3px; border-radius: 12px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); }
.mxe-tabs button { border: 0; background: none; color: #d6d6e2; font: inherit; font-size: 13.5px; padding: 6px 12px; border-radius: 9px; cursor: pointer; white-space: nowrap; }
.mxe-tabs button.on { background: linear-gradient(135deg, rgba(255,122,61,.9), rgba(226,59,46,.9)); color: #fff; font-weight: 600; }
.mxe-key { font-size: 12.5px; border-radius: 999px; padding: 4px 10px; border: 1px solid rgba(255,255,255,.16); white-space: nowrap; color: #d6d6e2; text-decoration: none; }
.mxe-key[data-ok="1"] { border-color: rgba(74,222,128,.45); color: #bbf7d0; } .mxe-key[data-ok="0"] { border-color: rgba(251,191,36,.55); color: #fde68a; }
.mxe-x { width: 36px; height: 36px; border-radius: 10px; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.06); color: #e9e9f3; font-size: 16px; cursor: pointer; flex: none; }
.mxe-body { display: grid; grid-template-columns: 280px minmax(0, 1fr) 400px; min-height: 0; }
.mxe-lib, .mxe-ctl { overflow: auto; padding: 14px; min-height: 0; }
.mxe-lib { border-right: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.02); }
.mxe-ctl { border-left: 1px solid rgba(255,255,255,.08); background: rgba(255,255,255,.02); }
.mxe-ctl input, .mxe-ctl textarea, .mxe-ctl select, .mxe-lib input { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 9px 11px; font: inherit; font-size: 13.5px; margin-top: 6px; }
.mxe-ctl select option { color: #111; } .mxe-ctl textarea { resize: vertical; }
.mxe-ctl label.fl { display: block; font-size: 12px; color: var(--muted); margin: 12px 0 -2px; }
.mxe-ctl input:focus, .mxe-ctl textarea:focus, .mxe-ctl select:focus, .mxe-lib input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.mxe h4 { margin: 14px 0 6px; font-size: 11.5px; letter-spacing: .08em; text-transform: uppercase; color: rgba(233,233,243,.6); font-weight: 700; } .mxe h4:first-child { margin-top: 0; }
.mxe-drop { display: grid; place-items: center; text-align: center; gap: 4px; padding: 14px 10px; border-radius: 14px; border: 1.5px dashed rgba(255,255,255,.22); color: rgba(233,233,243,.8); font-size: 13px; cursor: pointer; transition: background-color .15s ease, border-color .15s ease; }
.mxe-drop:hover, .mxe-drop.over { background: rgba(255,122,61,.08); border-color: rgba(255,122,61,.6); }
.mxe-drop small { color: var(--muted); font-size: 11.5px; }
.mxe-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-top: 10px; }
.mxe-it { position: relative; aspect-ratio: 1; border-radius: 12px; overflow: hidden; border: 2px solid transparent; background: #000; cursor: pointer; padding: 0; }
.mxe-it img, .mxe-it video { width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none; }
.mxe-it.on { border-color: #ff7a3d; box-shadow: 0 0 0 3px rgba(255,122,61,.25); }
.mxe-aud { display: grid; place-items: center; width: 100%; height: 100%; font-size: 34px; background: linear-gradient(135deg, #3b1d5e, #1e293b); }
.mxe-it .tg { position: absolute; left: 6px; bottom: 6px; font-size: 11px; background: rgba(0,0,0,.65); color: #fff; border-radius: 6px; padding: 1px 6px; }
.mxe-it .no { position: absolute; right: 6px; top: 6px; min-width: 20px; height: 20px; border-radius: 10px; background: #ff7a3d; color: #fff; font: 700 11px/20px system-ui; text-align: center; }
.mxe-stage { position: relative; display: grid; grid-template-rows: 1fr auto; min-height: 0; min-width: 0; padding: 14px; gap: 10px; }
.mxe-view { position: relative; display: grid; gap: 12px; min-height: 0; border-radius: 18px; background: repeating-conic-gradient(rgba(255,255,255,.035) 0 25%, transparent 0 50%) 0 0 / 24px 24px, rgba(0,0,0,.35); border: 1px solid rgba(255,255,255,.08); padding: 12px; overflow: hidden; }
.mxe-view.two { grid-template-columns: 1fr 1fr; }
.mxe-pane { position: relative; display: grid; place-items: center; min-height: 0; min-width: 0; }
.mxe-pane img, .mxe-pane video { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,.5); }
.mxe-pane .cap { position: absolute; left: 8px; top: 8px; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; background: rgba(0,0,0,.6); border-radius: 8px; padding: 2px 8px; }
.mxe-pane .wait { display: grid; place-items: center; gap: 10px; color: rgba(233,233,243,.85); font-size: 14px; text-align: center; padding: 20px; }
.mxe-pane .wait .mx-spin { width: 26px; height: 26px; border-width: 3px; }
.mxe-pane .err { color: #fecaca; font-size: 13.5px; white-space: pre-wrap; text-align: center; padding: 20px; overflow-wrap: anywhere; }
.mxe-empty { display: grid; place-items: center; align-content: center; text-align: center; color: rgba(233,233,243,.7); font-size: 14.5px; gap: 6px; padding: 30px; }
.mxe-empty b { font-size: 18px; color: #fff; }
.mxe-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 12.5px; color: var(--muted); min-width: 0; }
.mxe-bar .sp { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mxe-bar .gbtn, .mxe-bar a.gbtn { padding: 4px 11px; font-size: 12.5px; text-decoration: none; }
.mxe-ops { display: grid; gap: 6px; }
.mxe-op { display: flex; gap: 10px; align-items: center; text-align: left; padding: 10px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.04); color: #ececf1; font: inherit; cursor: pointer; }
.mxe-op .i { font-size: 20px; } .mxe-op b { display: block; font-size: 14px; } .mxe-op small { color: var(--muted); font-size: 12px; }
.mxe-op.on { border-color: rgba(255,122,61,.7); background: rgba(255,122,61,.12); }
.mxe-op:disabled { opacity: .4; cursor: not-allowed; }
.mxe-go { width: 100%; margin-top: 14px; padding: 12px !important; font-size: 15px !important; border-radius: 14px !important; }
.mxe-hist { border-top: 1px solid rgba(255,255,255,.08); background: rgba(10,11,20,.85); padding: 8px 14px 10px; min-width: 0; }
.mxe-hist .row { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; scroll-snap-type: x proximity; }
.mxe-h { flex: none; width: 168px; display: grid; grid-template-columns: 56px 1fr; gap: 8px; align-items: center; text-align: left; padding: 6px; border-radius: 12px; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.04); color: #ececf1; font: inherit; cursor: pointer; scroll-snap-align: start; }
.mxe-h.on { border-color: rgba(255,122,61,.7); background: rgba(255,122,61,.12); }
.mxe-h .th { width: 56px; height: 56px; border-radius: 8px; overflow: hidden; background: #000; display: grid; place-items: center; }
.mxe-h .th img, .mxe-h .th video { width: 100%; height: 100%; object-fit: cover; }
.mxe-h .th .mx-spin { width: 22px; height: 22px; border-width: 3px; }
.mxe-h .t { font-size: 12px; line-height: 1.3; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; } .mxe-h .t small { color: var(--muted); }
.mxe-sel { display: grid; gap: 6px; }
.mxe-sel .r { display: grid; grid-template-columns: 22px 44px 1fr auto; gap: 8px; align-items: center; font-size: 12.5px; }
.mxe-sel .r .n { font-weight: 700; color: #ff9a6b; text-align: center; } .mxe-sel .r img, .mxe-sel .r video { width: 44px; height: 44px; object-fit: cover; border-radius: 8px; background: #000; }
.mxe-sel .r span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); }
.mxe-sel .r button { border: 0; background: none; color: #f87171; cursor: pointer; font-size: 15px; }
@media (max-width: 1100px) { .mxe-body { grid-template-columns: 230px minmax(0, 1fr) 340px; } }
@media (max-width: 900px) {
  .mxe { grid-template-rows: auto 1fr; }
  .mxe-body { display: block; overflow: auto; }
  .mxe-lib, .mxe-ctl { overflow: visible; border: 0; }
  .mxe-lib .mxe-grid { grid-template-columns: none; grid-auto-flow: column; grid-auto-columns: 92px; overflow-x: auto; }
  .mxe-stage { min-height: 52vh; padding: 0 14px; }
  .mxe-view { min-height: 46vh; } .mxe-view.two { grid-template-columns: 1fr; }
  .mxe-hist { position: static; }
  .mxe-top .sub, .mxe-key { display: none; }
  .mxe-top { flex-wrap: wrap; row-gap: 8px; }
  .mxe-tabs { order: 5; flex-basis: 100%; } .mxe-tabs button { flex: 1; padding: 7px 9px; font-size: 13px; }
}`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const R = el('div', 'mxe'); R.hidden = true; R.setAttribute('role', 'dialog'); R.setAttribute('aria-label', 'Edit Media redaktoru');
  /* Başlıq */
  const top = el('div', 'mxe-top'), back = el('button', 'mxe-x', '←'), tt = el('div'), tabs = el('div', 'mxe-tabs');
  back.title = back.ariaLabel = 'Kainata qayıt'; back.onclick = () => go();
  tt.append(el('div', 'ttl', '✂️ Edit Media'), el('div', 'sub', 'Foto və videonu promptla redaktə et'));
  const tAi = el('button', null, '🤖 AI redaktə'), tCut = el('button', null, '🎬 Montaj'), tTl = el('button', null, '🎞 Timeline'); tAi.type = tCut.type = tTl.type = 'button';
  tabs.append(tTl, tAi, tCut);
  const key = el('a', 'mxe-key'); key.href = '#kainat/' + encodeURIComponent('media:Fal Connection');
  const close = el('button', 'mxe-x', '✕'); close.title = close.ariaLabel = 'Bağla (Esc)'; close.onclick = () => go();
  top.append(back, tt, el('div', 'sp'), tabs, key, close);
  /* Kitabxana */
  const lib = el('aside', 'mxe-lib'), drop = el('div', 'mxe-drop'), file = el('input'), url = el('input'), lf = el('div', 'mx-chips'), grid = el('div', 'mxe-grid'), lempty = el('div', 'mx-help');
  file.type = 'file'; file.accept = 'image/*,video/*,audio/*'; file.multiple = true; file.hidden = true;
  drop.tabIndex = 0; drop.setAttribute('role', 'button'); drop.append(el('div', null, '📎 Fayl yüklə və ya bura at'), el('small', null, 'Şəkil ≤ 15 MB · video/səs ≤ 50 MB'));
  drop.onclick = () => file.click(); drop.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); file.click(); } };
  file.onchange = () => { const f = file.files; mxUploadFiles(f); file.value = ''; };
  url.type = 'url'; url.placeholder = 'və ya şəkil/video URL-i yapışdır'; url.setAttribute('aria-label', 'Mənbə URL');
  url.onchange = () => { const u = url.value.trim(); if (/^https:\/\//.test(u)) { mxePick({ url: u, type: /\.(mp4|webm|mov)(\?|$)/i.test(u) ? 'video' : 'image' }); url.value = ''; } };
  for (const [k, t] of [['all', 'Hamısı'], ['image', '🖼 Şəkil'], ['video', '🎞 Video'], ['audio', '🎵 Səs']]) { const b = el('button', k === MX.libF ? 'on' : null, t); b.type = 'button'; b.onclick = () => { MX.libF = k; for (const x of lf.children) x.classList.toggle('on', x === b); R._libSig = ''; mxEdit(); }; lf.append(b); }
  lib.append(el('h4', null, '🗂 Media kitabxanası'), drop, file, url, lf, grid, lempty);
  /* Səhnə */
  const stage = el('main', 'mxe-stage'), view = el('div', 'mxe-view'), bar = el('div', 'mxe-bar');
  stage.append(view, bar);
  for (const z of [lib, stage]) {                                    // sürüşdür-burax yükləmə
    z.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); drop.classList.add('over'); } });
    z.addEventListener('dragleave', () => drop.classList.remove('over'));
    z.addEventListener('drop', e => { if (!e.dataTransfer.files.length) return; e.preventDefault(); drop.classList.remove('over'); mxUploadFiles(e.dataTransfer.files); });
  }
  /* İdarə paneli */
  const ctl = el('aside', 'mxe-ctl'), ai = el('div'), cut = el('div');
  // AI: əməliyyat → model (hazır axtarışlar) → prompt → parametrlər
  const ops = el('div', 'mxe-ops'), presets = el('div', 'mx-chips'), opHint = el('div', 'mx-help');
  for (const [k, o] of Object.entries(MXE_OPS)) {
    const b = el('button', 'mxe-op'); b.type = 'button'; b.dataset.op = k;
    const t = el('div'); t.append(el('b', null, o.name), el('small', null, o.src === 'image' ? 'mənbə: şəkil' : 'mənbə: video'));
    b.append(el('span', 'i', o.icon), t); b.onclick = () => { MX.editOp = k; R._opSig = ''; mxEdit(); }; ops.append(b);
  }
  const aiParams = el('div'), aiErr = mxErr(), aiBtn = mxBtn('🤖 Redaktə et', 'go mxe-go');
  const aiPrompt = mxPromptBox('Nəyi dəyişək? Məs: "arxa fonu gecə Bakısına çevir, neon işıqlar" və ya "kamera yavaşca yaxınlaşsın, saçlar küləkdə"');
  let aiModel = null, aiFields = null, aiForm = null;
  const picker = mxModelPicker(() => MX.editOp, async m => {
    aiModel = m; aiFields = null; aiForm = null; aiParams.replaceChildren(); if (!m) return;
    aiParams.append(el('div', 'mx-help', 'Parametrlər yüklənir…'));
    try { const f = await mxSchema(m.id); if (aiModel !== m) return; aiFields = f; R._fill(); }
    catch (e) { aiParams.replaceChildren(el('div', 'cmderr', e.message)); }
  });
  R._fill = () => {                                                  // mənbə URL-i modelin uyğun sahəsinə
    if (!aiFields) return;
    const fill = {}, s = MX.editSrc;
    if (s) for (const f of aiFields) {
      if (!/url/i.test(f.name) || /end_image|mask|audio/i.test(f.name)) continue;
      if (s.type === 'video' ? /video/i.test(f.name) : /image/i.test(f.name)) fill[f.name] = f.type === 'array' ? [s.url] : s.url;
    }
    aiForm = mxFieldsForm(aiFields, { fill }); aiParams.replaceChildren(aiForm);
    aiPrompt.hidden = !aiFields.some(f => f.name === 'prompt');
  };
  ai.append(el('h4', null, '1 · Nə edək?'), ops, opHint, el('h4', null, '2 · Model'), presets, picker,
    el('h4', null, '3 · Tapşırıq'), aiPrompt, aiParams, aiErr, aiBtn);
  aiBtn.onclick = async () => {
    mxShowErr(aiErr, '');
    if (!MX.key || !MX.key.has) return mxShowErr(aiErr, 'Əvvəlcə 🔌 Fal Connection planetində fal açarını əlavə et (yuxarıdakı düymə).');
    if (!MX.editSrc) return mxShowErr(aiErr, 'Kitabxanadan mənbə seç və ya fayl yüklə.');
    if (MXE_OPS[MX.editOp].src !== MX.editSrc.type) return mxShowErr(aiErr, 'Bu əməliyyat üçün ' + (MXE_OPS[MX.editOp].src === 'video' ? 'video' : 'şəkil') + ' seç.');
    if (!aiModel || !aiForm) return mxShowErr(aiErr, 'Model seç.');
    let input; try { input = aiForm._get(); } catch (e) { return mxShowErr(aiErr, e.message); }
    const pf = aiFields.find(f => f.name === 'prompt'), p = aiPrompt._ta.value.trim();
    if (pf && p) input.prompt = p; else if (pf && pf.required) return mxShowErr(aiErr, 'Nə etmək istədiyini yaz.');
    aiBtn.disabled = true; setText(aiBtn, 'Göndərilir…');
    const { status, d } = await MED('submit', { endpoint_id: aiModel.id, kind: MX.editOp, input });
    aiBtn.disabled = false; setText(aiBtn, '🤖 Redaktə et');
    if (status !== 200) return mxShowErr(aiErr, d.error || 'Alınmadı');
    MX.view = d.id; miniToast('🤖 Göndərildi: ' + aiModel.name); await mxRefresh(); mxEdit();
  };
  // Montaj: seçilmiş mənbələr (ardıcıllıq), kompüter, şablonlar, tapşırıq
  const sel = el('div', 'mxe-sel'), comp = el('select'), note = el('div', 'mx-note'), tpl = el('div', 'mx-chips'), cutErr = mxErr(), cutBtn = mxBtn('🎬 Claude-a göndər', 'go mxe-go'), jobs = el('div');
  const cutPrompt = mxPromptBox('Montaj tapşırığı. Məs: "15 saniyəlik reels: videonu 3 hissəyə böl, başlığa animasiyalı yazı, sonda logo"');
  comp.setAttribute('aria-label', 'Montajın işləyəcəyi kompüter'); comp.onchange = () => { R._cutSig = ''; mxEdit(); };
  for (const [t, line] of MXE_TPL) { const b = el('button', null, t); b.type = 'button'; b.onclick = () => { const ta = cutPrompt._ta; ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + line; ta.focus(); }; tpl.append(b); }
  cut.append(el('div', 'mx-note', 'After Effects tipli kompozisiya: Claude Code seçilən kompüterdə HyperFrames (HTML + GSAP → MP4) ilə montaj edib MP4 render edir. Kitabxanadan bir neçə mənbə seç: ardıcıllıq nömrə ilə görünür.'),
    el('h4', null, '1 · Mənbələr'), sel, el('h4', null, '2 · Kompüter'), comp, note, el('h4', null, '3 · Tapşırıq'), tpl, cutPrompt, cutErr, cutBtn, el('h4', null, '🕘 Son montajlar'), jobs);
  cutBtn.onclick = async () => {
    mxShowErr(cutErr, '');
    const target = comp.value, text = cutPrompt._ta.value.trim();
    if (!target) return mxShowErr(cutErr, 'Kompüter seç.');
    if (!text) return mxShowErr(cutErr, 'Montaj tapşırığını yaz və ya şablon seç.');
    const src = MX.cutSel.length ? MX.cutSel : MX.editSrc ? [MX.editSrc] : [];
    const prompt = ['[🎬 MEDIA · Edit Media] HyperFrames ilə montaj.',
      src.length ? 'Mənbə fayllar, bu ardıcıllıqla (əvvəlcə hər birini `curl -L -o assets/<ad> "<URL>"` ilə assets/ qovluğuna endir):\n' + src.map((s, i) => (i + 1) + '. ' + s.url + ' (' + (s.type === 'video' ? 'video' : 'şəkil') + ')').join('\n') : 'Mənbə fayl yoxdur: yalnız mətn, forma və animasiya ilə qur.',
      'Tapşırıq: ' + text,
      'Qaydalar: bu qovluqdakı HyperFrames layihəsindən istifadə et (CLAUDE.md və HyperFrames skill-lərinə bax). Kompozisiyanı HTML/CSS/GSAP ilə yaz, `npx hyperframes render` ilə out/ qovluğuna MP4 render et. Sonda nəticə faylının tam yolunu, müddətini, ölçüsünü və qısa təsvirini yaz.'].join('\n\n');
    cutBtn.disabled = true; setText(cutBtn, 'Göndərilir…');
    const { status, d } = await RMT('send', { target, project: ME_PROJECT, prompt, fresh: true });
    cutBtn.disabled = false; setText(cutBtn, '🎬 Claude-a göndər');
    if (status !== 200) return mxShowErr(cutErr, d.error || 'Alınmadı');
    miniToast(d.status === 'pending' ? '🎬 Göndərildi: ' + target + ' təsdiqləməlidir' : '🎬 Montaj növbəyə düşdü'); load();
  };
  const tlPane = el('div'), tlStage = el('div', 'tl-stage'), tlFoot = el('div', 'tl-foot');   // Timeline (media-timeline.js doldurur)
  ctl.append(ai, cut, tlPane); stage.append(tlStage);
  /* Tarixçə */
  const hist = el('footer', 'mxe-hist'), hrow = el('div', 'row'), hl = el('h4', null, '🕘 Redaktə tarixçəsi');
  hist.append(hl, hrow, tlFoot);
  const body = el('div', 'mxe-body'); body.append(lib, stage, ctl);
  R.append(top, body, hist);
  document.body.append(R);
  const setMode = m => {
    MX.editMode = m; try { localStorage.setItem('mx:mode', m); } catch (e) {}
    tAi.classList.toggle('on', m === 'ai'); tCut.classList.toggle('on', m === 'cut'); tTl.classList.toggle('on', m === 'timeline');
    ai.hidden = m !== 'ai'; cut.hidden = m !== 'cut'; tlPane.hidden = tlStage.hidden = tlFoot.hidden = m !== 'timeline';
    view.hidden = bar.hidden = hl.hidden = hrow.hidden = m === 'timeline'; R.classList.toggle('tl-on', m === 'timeline');
    R._libSig = R._cutSig = R._vSig = R._opSig = ''; mxEdit();
  };
  tAi.onclick = () => setMode('ai'); tCut.onclick = () => setMode('cut'); tTl.onclick = () => setMode('timeline');
  R._r = { key, grid, lempty, view, bar, ops, presets, opHint, picker, aiPrompt, cutPrompt, sel, comp, note, jobs, hrow, hl, tAi, tCut, tTl, ai, cut, tlPane, tlStage, tlFoot };
  R._setMode = setMode;
  mxeRoot = R;
  try { const sm = localStorage.getItem('mx:mode'); if (sm) MX.editMode = sm; } catch (e) {}
  if (!['ai', 'cut', 'timeline'].includes(MX.editMode)) MX.editMode = 'timeline';
  tAi.classList.toggle('on', MX.editMode === 'ai'); tCut.classList.toggle('on', MX.editMode === 'cut'); tTl.classList.toggle('on', MX.editMode === 'timeline');
  ai.hidden = MX.editMode !== 'ai'; cut.hidden = MX.editMode !== 'cut'; tlPane.hidden = tlStage.hidden = tlFoot.hidden = MX.editMode !== 'timeline';
  view.hidden = bar.hidden = hl.hidden = hrow.hidden = MX.editMode === 'timeline'; R.classList.toggle('tl-on', MX.editMode === 'timeline');
}

/** Tam ekranı yalnız Edit Media planeti açıq olanda göstər */
function mxeShow() {
  const on = !!mxeRoot && typeof curPlanet === 'string' && curPlanet === 'media:Edit Media';
  if (mxeRoot && mxeRoot.hidden === on) mxeRoot.hidden = !on;
  document.documentElement.classList.toggle('mxe-on', on);
}
addEventListener('hashchange', () => setTimeout(mxeShow, 0));

function mxEdit() {
  if (!mxeRoot) mxeBuild();
  const box = $('me-info');
  if (!box._note) { box._note = 1; box.replaceChildren(el('div', 'mx-note', '✂️ Redaktor tam ekranda açıqdır. Bağlamaq üçün ✕ və ya Esc.')); }
  mxeShow();
  const R = mxeRoot, r = R._r, s = MX.editSrc;
  if (s && MXE_OPS[MX.editOp].src !== s.type) MX.editOp = s.type === 'video' ? 'video-to-video' : 'image-to-image';   // mənbə növünə uyğun əməliyyat
  r.aiPrompt._fill(); r.cutPrompt._fill();
  /* fal açarı */
  const k = MX.key || {}, kSig = [k.has, k.hint].join();
  if (R._kSig !== kSig) { R._kSig = kSig; r.key.dataset.ok = k.has ? '1' : '0'; setText(r.key, k.loading ? '🔑 …' : k.has ? '🔑 fal ' + k.hint : '🔑 fal açarı əlavə et'); }
  /* kitabxana */
  const items = mxLib().filter(m => MX.libF === 'all' || m.type === MX.libF);
  const lSig = [MX.editMode, s && s.url, MX.cutSel.map(x => x.url).join(), items.map(m => m.url).join()].join('|');
  if (R._libSig !== lSig) {
    R._libSig = lSig;
    sync(r.grid, items, m => m.url, m => {
      const b = el('button', 'mxe-it'); b.type = 'button'; b.title = m.label; b.setAttribute('aria-label', m.label);
      b.append(mxThumb(m), el('span', 'tg', m.type === 'video' ? '▶ video' : m.type === 'audio' ? '🎵 səs' : '🖼'));
      b.draggable = true;                                             // Timeline-a sürüşdür
      b.ondragstart = e => { e.dataTransfer.setData('application/x-mx-media', JSON.stringify(b._m)); e.dataTransfer.effectAllowed = 'copy'; };
      b.onclick = () => mxePick(b._m); return b;
    }, (n, m) => {
      n._m = m;
      const idx = MX.cutSel.findIndex(x => x.url === m.url);
      n.classList.toggle('on', MX.editMode === 'cut' ? idx >= 0 : !!s && s.url === m.url);
      let no = n.querySelector('.no');
      if (MX.editMode === 'cut' && idx >= 0) { if (!no) { no = el('span', 'no'); n.append(no); } setText(no, String(idx + 1)); } else if (no) no.remove();
    }, { enter: false });
    r.lempty.hidden = items.length > 0;
    setText(r.lempty, MX.gens === null ? 'Yüklənir…' : 'Hələ media yoxdur: fayl yüklə və ya 🔌 Fal Connection-da yarat.');
  }
  if (MX.editMode === 'timeline') { if (typeof tlRender === 'function') tlRender(R); return; }   // Timeline öz səhnəsini idarə edir
  /* səhnə: seçilmiş nəticə (əvvəl / sonra) və ya mənbə */
  const g = MX.view ? (MX.gens || []).find(x => x.id === MX.view) : null;
  const vSig = [s && s.url, g && [g.id, g.status, g.media.length, g.error].join(), MX.cmp].join('|');
  if (R._vSig !== vSig) {
    R._vSig = vSig;
    const pane = (m, cap) => { const p = el('div', 'mxe-pane'); if (m) { const e = mxMediaEl(m); if (e.tagName === 'VIDEO') e.loop = true; p.append(e); } if (cap) p.append(el('span', 'cap', cap)); return p; };
    r.view.replaceChildren(); r.bar.replaceChildren(); r.view.classList.remove('two');
    const btn = (t, fn) => mxBtn(t, null, fn), link = (t, u) => { const a = el('a', 'gbtn', t); a.href = u; a.target = '_blank'; a.rel = 'noopener'; return a; };
    if (g) {
      const before = mxGenSource(g), res = g.media[0];
      const after = el('div', 'mxe-pane');
      if (g.status === 'done' && res) { const e = mxMediaEl(res); if (e.tagName === 'VIDEO') e.loop = true; after.append(e); }
      else if (g.status === 'failed') after.append(el('div', 'err', '❌ ' + (g.error || 'Alınmadı')));
      else { const w = el('div', 'wait'); w.append(el('span', 'mx-spin'), el('div', null, (g.status === 'queued' ? 'Növbədədir' : 'Hazırlanır') + '…'), el('small', 'mx-help', g.model + ' · video bir neçə dəqiqə çəkə bilər')); after.append(w); }
      after.append(el('span', 'cap', 'SONRA'));
      if (before && MX.cmp) { r.view.classList.add('two'); r.view.append(pane(before, 'ƏVVƏL'), after); } else r.view.append(after);
      r.bar.append(el('span', 'sp', (MXE_OPS[g.kind] ? MXE_OPS[g.kind].icon + ' ' + MXE_OPS[g.kind].name : g.kind) + ' · ' + g.model + ' · ' + ago(g.created_at) + (g.prompt ? ' · “' + g.prompt.slice(0, 90) + '”' : '')));
      if (before) r.bar.append(btn(MX.cmp ? '◧ Yalnız nəticə' : '◧ Əvvəl / sonra', () => { MX.cmp = !MX.cmp; mxEdit(); }));
      if (res) {
        r.bar.append(btn('↪ Nəticəni mənbə et', () => { MX.editSrc = { url: res.url, type: res.type }; MX.view = 0; if (MXE_OPS[MX.editOp].src !== res.type) MX.editOp = res.type === 'video' ? 'video-to-video' : 'image-to-image'; R._libSig = R._opSig = ''; mxEdit(); }),
          link('⬇ Yüklə', res.url));
      }
      r.bar.append(btn('✕ Bağla', () => { MX.view = 0; mxEdit(); }));
    } else if (s) {
      r.view.append(pane(s, s.type === 'video' ? 'MƏNBƏ · VIDEO' : 'MƏNBƏ · ŞƏKİL'));
      r.bar.append(el('span', 'sp', s.url), btn('⧉ URL', async e => { try { await navigator.clipboard.writeText(s.url); setText(e.target, 'Kopyalandı ✓'); } catch (x) { setText(e.target, 'Alınmadı'); } }), link('⬇ Aç', s.url));
    } else {
      const e = el('div', 'mxe-empty'); e.append(el('b', null, 'Redaktəyə başla'), el('div', null, 'Kitabxanadan şəkil/video seç, fayl at və ya URL yapışdır.'), el('div', 'mx-help', 'Sonra əməliyyatı seç: şəkli redaktə et, şəkli videoya çevir və ya videonu redaktə et.'));
      r.view.append(e);
    }
  }
  /* AI: əməliyyatlar, hazır axtarışlar, model */
  if (MX.editMode !== 'cut') {
    const oSig = [MX.editOp, s && s.type].join();
    if (R._opSig !== oSig) {
      R._opSig = oSig;
      for (const b of r.ops.children) { const o = MXE_OPS[b.dataset.op]; b.classList.toggle('on', b.dataset.op === MX.editOp); b.disabled = !!s && o.src !== s.type; }
      const op = MXE_OPS[MX.editOp];
      setText(r.opHint, op.hint);
      r.presets.replaceChildren(...op.presets.map(([t, q], i) => { const b = el('button', i === 0 ? 'on' : null, t); b.type = 'button'; b.onclick = () => { for (const x of r.presets.children) x.classList.toggle('on', x === b); r.picker._search(q); }; return b; }));
      if (R._pickOp !== MX.editOp) { R._pickOp = MX.editOp; r.picker._search(''); }
      R._fill();
    } else if (R._srcFill !== (s && s.url)) R._fill();
    R._srcFill = s && s.url;
  }
  /* Montaj: mənbələr, kompüter, işlər */
  if (MX.editMode === 'cut') {
    const users = (data.users || []).filter(u => ME && (u === ME.name || myGrantOn(u)));
    const cur = r.comp.value || (ME ? ME.name : ''), target = users.includes(cur) ? cur : users[0] || '';
    const a = target ? agentInfo(target) : null;
    const cmds = (data.commands || []).filter(c => c.project === ME_PROJECT && ME && c.creator === ME.name).slice(0, 8);
    const cSig = [MX.cutSel.map(x => x.url).join(), users.join(), target, a && a.online, a && a.projects.join(), a && a.ag && a.ag.profile, cmds.map(c => c.id + c.status).join()].join('|');
    if (R._cutSig !== cSig) {
      R._cutSig = cSig;
      r.sel.replaceChildren();
      if (!MX.cutSel.length) r.sel.append(el('div', 'mx-help', 'Kitabxanada mənbələrə bas: seçdiyin ardıcıllıqla nömrələnəcək. Mənbəsiz də olar (yalnız mətn/animasiya).'));
      MX.cutSel.forEach((m, i) => {
        const row = el('div', 'r'), rm = el('button', null, '✕'); rm.type = 'button'; rm.title = rm.ariaLabel = 'Siyahıdan çıxar';
        rm.onclick = () => { MX.cutSel.splice(i, 1); R._libSig = ''; mxEdit(); };
        row.append(el('span', 'n', String(i + 1)), mxThumb(m), el('span', null, m.url.split('/').pop().slice(0, 40)), rm); r.sel.append(row);
      });
      r.comp.replaceChildren(...users.map(u => new Option((ME && u === ME.name ? '🖥 Mənim kompüterim (' + u + ')' : '🖥 ' + u) + (agentInfo(u).online ? '' : ' · oflayn'), u)));
      r.comp.value = target;
      r.note.replaceChildren(); r.note.className = 'mx-note';
      if (!a || !a.ag) { r.note.className = 'mx-note warn'; r.note.append('Bu kompüterdə Planet agenti işləmir. Agenti qur: başlıqdakı 📦 Paket.'); }
      else {
        const prof = a.ag.profile || 'basic', hasPrj = a.projects.includes(ME_PROJECT);
        if (!hasPrj || prof === 'basic' || !a.online) r.note.className = 'mx-note warn';
        r.note.append((a.online ? '🟢 onlayn' : '⚪ oflayn') + ' · profil: ' + prof + ' · ' + (hasPrj ? '✅ ' + ME_PROJECT : '⚠️ ' + ME_PROJECT + ' qeydiyyatda deyil'));
        if (!hasPrj || prof === 'basic') r.note.append(el('div', 'mx-help', 'Bir dəfəlik: kompüterdə `~/Documents/projects/' + ME_PROJECT + '`-də HyperFrames (`npx hyperframes init`, Node 22+, ffmpeg), qovluğu Planet-ə qeydiyyatdan keçir, agent profili ən azı dev (CLAUDE_TEAM_PROFILE=dev).'));
      }
      r.jobs.replaceChildren();
      if (!cmds.length) r.jobs.append(el('div', 'empty', 'Hələ montaj göndərilməyib.'));
      for (const c of cmds) {
        const n = el('div', 'prow'), st = { pending: '✋ təsdiq gözləyir', queued: '⏳ növbədə', running: '⚙️ işləyir', done: '✅ hazır', failed: '❌ alınmadı' }[c.status] || c.status;
        const lk = el('a', null, 'Claude Code-da aç ↗'); lk.href = 'code.php#' + encodeURIComponent(c.user) + '/' + (c.chat || c.id); lk.target = '_blank'; lk.rel = 'noopener'; lk.style.color = 'var(--accent)';
        const meta = el('div', 'pm'); meta.append(c.user + ' · ' + st + ' · ' + ago(c.created_at) + ' · ', lk);
        n.append(el('div', 'pt', (c.prompt.split('\n\n').find(x => x.startsWith('Tapşırıq: ')) || '').slice(10, 140) || 'Montaj'), meta);
        if (c.result) n.append(el('div', 'pb', c.result));
        r.jobs.append(n);
      }
    }
  }
  /* Tarixçə (AI redaktələri: mənbəsi olan generasiyalar) */
  const hist = mxEditGens();
  setText(r.hl, '🕘 Redaktə tarixçəsi · ' + (MX.gens === null ? '…' : hist.length));
  sync(r.hrow, hist, x => x.id, () => {
    const b = el('button', 'mxe-h'), th = el('div', 'th'), t = el('div', 't'); b.type = 'button'; b.append(th, t);
    b.onclick = () => { MX.view = b._g.id; mxEdit(); }; return b;
  }, (n, x) => {
    n._g = x; n.classList.toggle('on', MX.view === x.id);
    const sig = [x.status, x.media.length].join();
    if (n._sig !== sig) {
      n._sig = sig; const th = n.firstChild, t = n.lastChild;
      th.replaceChildren(x.status === 'done' && x.media[0] ? mxThumb(x.media[0]) : x.status === 'failed' ? el('span', null, '❌') : el('span', 'mx-spin'));
      t.replaceChildren(el('div', null, (MXE_OPS[x.kind] ? MXE_OPS[x.kind].icon + ' ' : '') + (x.prompt || x.model).slice(0, 60)), el('small', null, ago(x.created_at)));
    }
  }, { enter: false });
}
