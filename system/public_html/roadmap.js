/* Qalan işlər: bütün səhifələrdə görünən yapışqan siyahı (sol-aşağı). Unudulmamalı iri işlər, alt bəndlər, sahiblər.
   Məlumat feed.php-dən (data.roadmap) gəlir; dəyişikliklər roadmap.php ilə. index.php-dəki qlobal data, ME, tint istifadə olunur. */
(() => {
  if (document.body.classList.contains('student')) return;
  const css = `
.rm { position: fixed; left: 16px; bottom: 16px; z-index: 58; font-size: 13.5px; color: var(--text); }
.rm-pill { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--line); background: linear-gradient(var(--card), var(--card)), var(--bg); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); color: var(--text); border-radius: 999px; padding: 8px 14px 8px 12px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; box-shadow: 0 8px 24px rgba(0,0,0,.25); transition: transform .15s ease; }
.rm-pill:active { transform: scale(.97); }
.rm-pill b { min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--accent); color: #fff; font-size: 11.5px; display: inline-grid; place-items: center; }
.rm-pill .rm-bar { width: 46px; height: 4px; border-radius: 4px; background: var(--line); overflow: hidden; } .rm-pill .rm-bar i { display: block; height: 100%; background: #22c55e; }
.rm-card { position: absolute; left: 0; bottom: calc(100% + 10px); width: min(420px, calc(100vw - 32px)); max-height: min(calc(100vh - 80px), 640px); display: flex; flex-direction: column; background: linear-gradient(var(--card), var(--card)), var(--bg); -webkit-backdrop-filter: blur(18px); backdrop-filter: blur(18px); border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 20px 50px rgba(0,0,0,.35); overflow: hidden; transform-origin: bottom left; animation: rm-in .18s ease-out; }
.rm-card[hidden] { display: none; }
@keyframes rm-in { from { opacity: 0; transform: translateY(8px) scale(.98); } }
.rm-hd { display: flex; align-items: center; gap: 8px; padding: 12px 14px 8px; }
.rm-hd h3 { margin: 0; font-size: 15px; flex: 1; } .rm-hd small { color: var(--muted, rgba(150,150,170,.9)); font-weight: 500; font-size: 12px; margin-left: 6px; }
.rm-x { border: 0; background: transparent; color: inherit; width: 28px; height: 28px; border-radius: 8px; cursor: pointer; font-size: 15px; opacity: .7; } .rm-x:hover { opacity: 1; background: rgba(127,127,127,.12); }
.rm-tools { display: flex; gap: 6px; padding: 0 14px 8px; flex-wrap: wrap; }
.rm-tools button { border: 1px solid var(--line); background: transparent; color: inherit; border-radius: 999px; padding: 3px 10px; font: inherit; font-size: 12px; cursor: pointer; }
.rm-tools button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.rm-list { flex: 1; min-height: min(140px, 22vh); overflow: auto; padding: 2px 8px 8px; scrollbar-width: thin; }
.rm-it { display: grid; grid-template-columns: 22px 1fr auto; gap: 8px; align-items: start; padding: 7px 6px; border-radius: 10px; }
.rm-it:hover { background: rgba(127,127,127,.08); }
.rm-it.sub { margin-left: 24px; padding-top: 5px; padding-bottom: 5px; font-size: 13px; }
.rm-it input[type=checkbox] { width: 16px; height: 16px; margin: 2px 0 0; accent-color: #22c55e; cursor: pointer; }
.rm-t { line-height: 1.35; word-break: break-word; } .rm-t .n { opacity: .55; margin-right: 4px; font-variant-numeric: tabular-nums; }
.rm-it.done .rm-t { text-decoration: line-through; opacity: .5; }
.rm-own { display: flex; gap: 3px; margin-top: 4px; flex-wrap: wrap; }
.rm-own span { font-size: 11px; padding: 1px 7px 1px 2px; border-radius: 999px; background: rgba(127,127,127,.12); display: inline-flex; align-items: center; gap: 4px; }
.rm-own i { width: 16px; height: 16px; border-radius: 50%; color: #fff; font-style: normal; font-size: 9.5px; font-weight: 700; display: inline-grid; place-items: center; }
.rm-act { display: flex; gap: 2px; opacity: 0; transition: opacity .15s; } .rm-it:hover .rm-act, .rm-it:focus-within .rm-act { opacity: 1; }
@media (hover: none) { .rm-act { opacity: .8; } }
.rm-act button { border: 0; background: transparent; color: inherit; width: 24px; height: 24px; border-radius: 6px; cursor: pointer; font-size: 12px; opacity: .75; } .rm-act button:hover { opacity: 1; background: rgba(127,127,127,.15); }
.rm-edit { display: grid; gap: 6px; } .rm-edit input[type=text] { font: inherit; padding: 6px 8px; border-radius: 8px; border: 1px solid var(--line); background: transparent; color: inherit; width: 100%; box-sizing: border-box; }
.rm-who { display: flex; gap: 4px; flex-wrap: wrap; } .rm-who button { border: 1px solid var(--line); background: transparent; color: inherit; border-radius: 999px; padding: 2px 9px; font: inherit; font-size: 11.5px; cursor: pointer; } .rm-who button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.rm-edit .row { display: flex; gap: 6px; justify-content: flex-end; } .rm-edit .row button { border: 1px solid var(--line); background: transparent; color: inherit; border-radius: 8px; padding: 4px 10px; font: inherit; font-size: 12px; cursor: pointer; } .rm-edit .row .go { background: var(--accent); border-color: var(--accent); color: #fff; }
.rm-new { border-top: 1px solid var(--line); padding: 10px 12px 12px; }
.rm-new .rm-edit:not(.act) .rm-who, .rm-new .rm-edit:not(.act) .row { display: none; }
.rm-empty { padding: 18px; text-align: center; opacity: .6; }
body.has-rm .mtoasts { bottom: 64px; }
@media (max-width: 600px) { .rm { left: 12px; bottom: 12px; } .rm-card { position: fixed; left: 8px; right: 8px; bottom: 64px; width: auto; max-height: 72vh; } }
@media (prefers-reduced-motion: reduce) { .rm-card { animation: none; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const E = (t, c, txt) => { const n = document.createElement(t); if (c) n.className = c; if (txt != null) n.textContent = txt; return n; };
  const store = { get: (k, d) => { try { const v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} } };
  const S = Object.assign({ open: false, mine: false, hideDone: false }, store.get('rm-ui', {}));
  const save = () => store.set('rm-ui', S);

  const root = E('div', 'rm'); root.setAttribute('data-jv-obstacle', '');
  const pill = E('button', 'rm-pill'); pill.type = 'button'; pill.title = 'Qalan işlər (Alt+L)';
  const card = E('section', 'rm-card'); card.setAttribute('aria-label', 'Qalan işlər'); card.hidden = true;
  const hd = E('div', 'rm-hd'), h3 = E('h3', null, '📌 Qalan işlər'), cnt = E('small'), x = E('button', 'rm-x', '✕');
  x.type = 'button'; x.setAttribute('aria-label', 'Bağla'); h3.append(cnt); hd.append(h3, x);
  const tools = E('div', 'rm-tools'), bMine = E('button', null, '👤 Mənim'), bDone = E('button', null, '✓ Bitənləri gizlət');
  bMine.type = bDone.type = 'button'; tools.append(bMine, bDone);
  const list = E('div', 'rm-list'), nw = E('div', 'rm-new');
  card.append(hd, tools, list, nw); root.append(card, pill); document.body.append(root); document.body.classList.add('has-rm');

  let items = [], editing = null, busy = false;
  const users = () => (typeof data !== 'undefined' && data && data.users) || [];
  const me = () => (typeof ME !== 'undefined' && ME ? ME.name : '');
  const color = u => typeof tint === 'function' ? tint(u) : '#6366f1';
  const owners = it => String(it.owners || '').split(',').filter(Boolean);
  const kids = id => items.filter(i => +i.parent_id === id);
  const isMine = it => owners(it).includes(me()) || kids(+it.id).some(k => owners(k).includes(me()));

  async function call(action, extra) {
    if (busy) return; busy = true;
    try {
      const r = await fetch('roadmap.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
      const d = await r.json().catch(() => ({}));
      if (r.status === 401) return location.reload();
      if (r.status !== 200) { if (typeof bmsg === 'function') bmsg(d.error || 'Alınmadı'); return null; }
      if (d.items) { items = d.items; if (typeof data !== 'undefined' && data) data.roadmap = d.items; sig = JSON.stringify(items); draw(); }
      return d;
    } finally { busy = false; }
  }

  /** Sahib seçici (istifadəçi düymələri) */
  function whoPicker(sel) {
    const w = E('div', 'rm-who');
    for (const u of users()) {
      const b = E('button', sel.has(u) ? 'on' : null, u); b.type = 'button';
      b.onclick = () => { sel.has(u) ? sel.delete(u) : sel.add(u); b.classList.toggle('on', sel.has(u)); };
      w.append(b);
    }
    return w;
  }
  function editor({ title = '', own = [], ok, cancel, ph, focus = true }) {
    const f = E('form', 'rm-edit'), inp = E('input'), sel = new Set(own), row = E('div', 'row');
    inp.type = 'text'; inp.value = title; inp.placeholder = ph || 'İşin adı'; inp.maxLength = 300;
    const go = E('button', 'go', 'Saxla'); go.type = 'submit';
    if (cancel) { const c = E('button', null, 'Ləğv'); c.type = 'button'; c.onclick = cancel; row.append(c); }
    row.append(go); f.append(inp, whoPicker(sel), row);
    f.onsubmit = e => { e.preventDefault(); const t = inp.value.trim(); if (t) ok(t, [...sel]); };
    inp.onkeydown = e => { if (e.key === 'Escape' && cancel) { e.stopPropagation(); cancel(); } };
    if (focus) setTimeout(() => inp.focus(), 0);
    return f;
  }

  function row(it, n, sub) {
    const id = +it.id, el = E('div', 'rm-it' + (sub ? ' sub' : '') + (+it.done ? ' done' : ''));
    if (editing && editing.id === id) {
      el.style.gridTemplateColumns = '1fr';
      el.append(editor({ title: it.title, own: owners(it), cancel: () => { editing = null; draw(); }, ok: (t, o) => { editing = null; call('save', { id, title: t, owners: o }); } }));
      return el;
    }
    const cb = E('input'); cb.type = 'checkbox'; cb.checked = !!+it.done; cb.setAttribute('aria-label', 'Bitdi: ' + it.title);
    cb.onchange = () => call('toggle', { id, done: cb.checked });
    const body = E('div'), t = E('div', 'rm-t');
    if (!sub) t.append(E('span', 'n', n + '.')); t.append(document.createTextNode(it.title));
    body.append(t);
    const os = owners(it);
    if (os.length) { const ow = E('div', 'rm-own'); for (const u of os) { const s = E('span'), i = E('i', null, u.slice(0, 1).toUpperCase()); i.style.background = color(u); s.append(i, document.createTextNode(u)); ow.append(s); } body.append(ow); }
    if (+it.done && it.done_by) body.title = '✓ ' + it.done_by + (it.done_at && typeof when === 'function' ? ' · ' + when(it.done_at) : '');
    const act = E('div', 'rm-act'), btn = (txt, tip, fn) => { const b = E('button', null, txt); b.type = 'button'; b.title = tip; b.setAttribute('aria-label', tip); b.onclick = fn; act.append(b); };
    if (!sub) btn('＋', 'Alt bənd əlavə et', () => { editing = { add: id }; draw(); });
    btn('✎', 'Dəyiş', () => { editing = { id }; draw(); });
    btn('↑', 'Yuxarı', () => call('move', { id, dir: -1 }));
    btn('↓', 'Aşağı', () => call('move', { id, dir: 1 }));
    btn('🗑', 'Sil', () => { if (confirm('“' + it.title + '” silinsin?' + (kids(id).length ? ' (alt bəndlərlə)' : ''))) call('delete', { id }); });
    el.append(cb, body, act);
    return el;
  }

  function draw() {
    const top = items.filter(i => +i.parent_id === 0);
    const all = items.filter(i => !kids(+i.id).length);                // sayılan işlər: alt bəndi olanlar yox, onların bəndləri
    const left = all.filter(i => !+i.done).length;
    pill.textContent = '';
    const bar = E('span', 'rm-bar'), fill = E('i'); fill.style.width = (all.length ? Math.round((all.length - left) / all.length * 100) : 0) + '%'; bar.append(fill);
    pill.append(document.createTextNode('📌 Qalan işlər'), E('b', null, String(left)), bar);
    pill.setAttribute('aria-expanded', String(S.open));
    cnt.textContent = (all.length - left) + '/' + all.length + ' bitdi';
    bMine.classList.toggle('on', S.mine); bDone.classList.toggle('on', S.hideDone);
    card.hidden = !S.open;
    if (!S.open) return;
    const keep = list.scrollTop; list.textContent = '';
    let n = 0, shown = 0;
    for (const it of top) {
      n++;
      const ks = kids(+it.id), allDone = +it.done || (ks.length && ks.every(k => +k.done));
      if (S.mine && !isMine(it)) continue;
      if (S.hideDone && allDone) continue;
      list.append(row(it, n, false)); shown++;
      for (const k of ks) {
        if (S.mine && !owners(k).includes(me()) && !owners(it).includes(me())) continue;
        if (S.hideDone && +k.done) continue;
        list.append(row(k, 0, true));
      }
      if (editing && editing.add === +it.id) {
        const w = E('div', 'rm-it sub'); w.style.gridTemplateColumns = '1fr';
        w.append(editor({ ph: 'Alt bənd', own: owners(it), cancel: () => { editing = null; draw(); }, ok: (t, o) => { editing = null; call('save', { parent_id: +it.id, title: t, owners: o }); } }));
        list.append(w);
      }
    }
    if (!shown) list.append(E('div', 'rm-empty', S.mine ? 'Sənə aid qalan iş yoxdur 🎉' : 'Siyahı boşdur'));
    list.scrollTop = keep;
    if (!nw.firstChild || nw._users !== users().join(',')) {      // yeni iş formu (istifadəçilər dəyişəndə yenilənir)
      nw.textContent = ''; nw._users = users().join(',');
      const f = editor({ ph: '+ Yeni iş…', own: [], focus: false, ok: (t, o) => call('save', { title: t, owners: o }).then(d => { if (d) { f.reset(); f.querySelectorAll('.rm-who .on').forEach(b => b.click()); } }) });
      const ni = f.querySelector('input'), act = () => f.classList.toggle('act', !!ni.value.trim() || document.activeElement === ni || f.contains(document.activeElement));
      ni.addEventListener('input', act); f.addEventListener('focusin', act); f.addEventListener('focusout', () => setTimeout(act, 120));
      f.addEventListener('reset', () => setTimeout(act, 0));
      nw.append(f);
    }
  }

  const toggle = v => { S.open = v == null ? !S.open : v; save(); draw(); };
  pill.onclick = () => toggle();
  x.onclick = () => toggle(false);
  bMine.onclick = () => { S.mine = !S.mine; save(); draw(); };
  bDone.onclick = () => { S.hideDone = !S.hideDone; save(); draw(); };
  addEventListener('keydown', e => {
    if (e.altKey && e.code === 'KeyL') { e.preventDefault(); toggle(); }
    else if (e.key === 'Escape' && S.open && !editing && card.contains(document.activeElement)) toggle(false);
  });

  // feed hər 3 saniyədə gəlir: siyahı dəyişibsə yenidən çək (redaktə zamanı toxunma)
  let sig = '';
  setInterval(() => {
    if (typeof data === 'undefined' || !data || !data.roadmap || editing) return;
    const s = JSON.stringify(data.roadmap); if (s === sig) return;
    sig = s; items = data.roadmap; draw();
  }, 800);
  draw();
})();
