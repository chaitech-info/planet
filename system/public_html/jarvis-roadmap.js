/* Jarvis modulu: "Qalan işlər" siyahısı. Alət deklarasiyaları jarvis_modules/roadmap.php-dədir, icra roadmap.php-yə gedir.
   Oxumaq təsdiqsiz işləyir, əlavə etmək və bitmiş işarələmək təsdiq tələb edir. */
(() => {
  if (!window.Jarvis) return;
  const { confirmed, line } = Jarvis.util;
  async function call(action, extra) {
    const r = await fetch('roadmap.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    const d = await r.json().catch(() => ({}));
    if (r.status !== 200) throw new Error(d.error || 'xəta ' + r.status);
    return d;
  }
  const brief = items => (items || []).map(i => ({ id: i.id, parent_id: i.parent_id, title: i.title, owners: i.owners, done: !!+i.done }));
  Jarvis.registerTool('get_roadmap', async () => ({ items: brief((await call('list')).items) }));
  Jarvis.registerTool('add_roadmap_item', async a => {
    confirmed(a);
    const d = await call('save', { title: String((a && a.title) || ''), owners: String((a && a.owners) || ''), parent_id: +(a && a.parent_id) || 0 });
    line('i', '📌 Qalan işlərə əlavə olundu: ' + String(a.title).slice(0, 80));
    if (typeof window.roadmapRefresh === 'function') window.roadmapRefresh();
    return { ok: true, id: d.id, items: brief(d.items) };
  });
  Jarvis.registerTool('set_roadmap_done', async a => {
    confirmed(a);
    const d = await call('toggle', { id: +a.id, done: !!a.done });
    line('i', (a.done ? '✅ Bitdi' : '↩ Geri açıldı') + ': #' + a.id);
    if (typeof window.roadmapRefresh === 'function') window.roadmapRefresh();
    return { ok: true, items: brief(d.items) };
  });
})();
