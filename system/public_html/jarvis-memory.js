/* Jarvis modulu: yaddaş. Alət deklarasiyaları jarvis_modules/memory.php-dədir, qeydlər serverdə (jarvis_memory) saxlanır və
   hər sessiyanın başında sistem təlimatına əlavə olunur. remember səssiz işləyir, forget təsdiq tələb edir. */
(() => {
  if (!window.Jarvis) return;
  const { confirmed, line } = Jarvis.util;
  async function call(action, extra) {
    const r = await fetch('jarvis.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    const d = await r.json().catch(() => ({}));
    if (r.status !== 200) throw new Error(d.error || 'xəta ' + r.status);
    return d;
  }
  Jarvis.registerTool('remember', async a => {
    const note = String((a && a.note) || '').trim();
    if (!note) throw new Error('note is empty');
    const d = await call('memory_add', { note });
    line('i', '🧠 Yadda saxladım: ' + note.slice(0, 80));
    return { ok: true, saved: note, total: d.count };
  });
  Jarvis.registerTool('forget', async a => {
    confirmed(a);
    const d = await call('memory_forget', { query: String((a && a.query) || '') });
    line('i', '🧠 Unutdum: ' + d.deleted + ' qeyd');
    return { ok: true, deleted: d.deleted };
  });
})();
