/* Jarvis modulu: yaddaş (v2). Alət deklarasiyaları jarvis_modules/memory.php-dədir, qeydlər serverdə (jarvis_memory) saxlanır və
   hər sessiyanın başında sistem təlimatına əlavə olunur. remember səssiz işləyir (istəyə görə proyekt faktı), forget təsdiq tələb edir.
   Sessiya bitəndə panel jurnalından (istifadəçinin dedikləri + edilən əməliyyatlar) qısa xülasə yığılır və kind=summary kimi saxlanır (model çağırışı yoxdur). */
(() => {
  if (!window.Jarvis) return;
  const { confirmed, line } = Jarvis.util;
  async function call(action, extra, opts) {
    const r = await fetch('jarvis.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }), ...opts });
    const d = await r.json().catch(() => ({}));
    if (r.status !== 200) throw new Error(d.error || 'xəta ' + r.status);
    return d;
  }
  Jarvis.registerTool('remember', async a => {
    const note = String((a && a.note) || '').trim();
    if (!note) throw new Error('note is empty');
    const project = a && a.project ? (Jarvis.util.projectOf ? Jarvis.util.projectOf(a.project) : String(a.project)) : '';
    const d = await call('memory_add', { note, project });
    line('i', '🧠 Yadda saxladım' + (project ? ' (' + project + ')' : '') + ': ' + note.slice(0, 80));
    return { ok: true, saved: note, project: project || undefined, total: d.count };
  });
  Jarvis.registerTool('forget', async a => {
    confirmed(a);
    const d = await call('memory_forget', { query: String((a && a.query) || '') });
    line('i', '🧠 Unutdum: ' + d.deleted + ' qeyd');
    return { ok: true, deleted: d.deleted };
  });

  /* ---------- Sessiya xülasəsi ---------- */
  const log = () => document.getElementById('jv-log');
  let mark = null, live = false;
  Jarvis.on('start', () => { const l = log(); mark = l ? l.lastElementChild : null; live = true; });
  /** Sessiya ərzindəki sətirlər: Sən (u) və uğurlu əməliyyatlar (a: "✓ …", "🤖 …"); ≤ 360 simvol */
  function summary() {
    const l = log(); if (!l) return '';
    const rows = [...l.children]; let i = mark && mark.parentNode === l ? rows.indexOf(mark) + 1 : 0;
    const said = [], did = [];
    for (; i < rows.length; i++) {
      const n = rows[i], t = (n.textContent || '').replace(/\s+/g, ' ').trim(); if (!t) continue;
      if (n.classList.contains('u') && t.length > 3) said.push(t.slice(0, 90));
      else if (n.classList.contains('a')) did.push(t.replace(/^✓\s*/, '').slice(0, 70));
    }
    if (said.length < 2 && !did.length) return '';
    const pick = (a, n) => a.length > n ? [...a.slice(0, 2), '…', ...a.slice(-(n - 2))] : a;   // əvvəl + son (mövzu və nəticə)
    let s = 'Danışdıq: ' + pick(said, 5).join('; ');
    if (did.length) s += ' | Edildi: ' + pick(did, 4).join('; ');
    return s.slice(0, 360);
  }
  Jarvis.on('stop', () => {
    if (!live) return; live = false;                    // stop iki dəfə gələ bilər (bağlantı + düymə)
    const s = summary(); if (!s) return;
    call('memory_add', { note: s, kind: 'summary' }, { keepalive: true }).catch(() => {});
  });
  window.JarvisMemory = { summary };
})();
