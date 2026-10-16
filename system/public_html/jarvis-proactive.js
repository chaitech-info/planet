/* Jarvis modulu: proaktiv xəbərlər. Claude Code tapşırığın bitəndə/alınmayanda Jarvis özü xəbər verir:
   sessiya açıqdırsa danışır, bağlıdırsa sol aşağıda kiçik bildiriş çıxır (klik edəndə Jarvis açılır və nəticəni danışır).
   Səsi/mikrofonu özbaşına açmır. Yalnız bu istifadəçinin öz tapşırıqları. Səhifə açılanda artıq bitmişlər nəzərə alınmır. */
(() => {
  if (!window.Jarvis) return;
  const JV = Jarvis, me = (() => { try { return JV.util.userName ? JV.util.userName() : ''; } catch (e) { return ''; } })();
  const seen = new Set(), told = new Set(); let primed = false;
  JV.on('command', c => { if (c && ['done', 'failed', 'canceled'].includes(c.status)) told.add(+c.id); });   // izlənilənləri Jarvis özü artıq danışır

  const css = `.jvp { position: fixed; left: 18px; bottom: 18px; z-index: 69; max-width: min(340px, calc(100vw - 36px)); display: flex; gap: 10px; align-items: center; padding: 10px 12px; border-radius: 14px; cursor: pointer; color: #e9eef8; font: inherit; font-size: 13px; text-align: left;
  background: rgba(8,10,20,.9); border: 1px solid rgba(125,211,252,.35); box-shadow: 0 10px 34px rgba(0,0,0,.5); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); animation: jvp-in .3s ease both; }
.jvp.fail { border-color: rgba(248,113,113,.5); } .jvp b { display: block; font-size: 12.5px; } .jvp span { color: #94a3b8; font-size: 12px; } .jvp i { font-style: normal; font-size: 20px; }
@keyframes jvp-in { from { opacity: 0; transform: translateY(8px); } } @media (prefers-reduced-motion: reduce) { .jvp { animation: none; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  let toast = null, toastT = 0;
  function close() { clearTimeout(toastT); if (toast) toast.remove(); toast = null; }
  function note(c) {
    const ok = c.status === 'done', res = String(c.result || '').trim();
    return `[System note, not spoken by the user] Claude Code task #${c.id} in project "${c.project}" just finished with status "${c.status}". Result (may be truncated):\n${res.slice(-1500) || '(empty)'}\nTell the user in 1-2 short friendly spoken sentences${ok ? '' : ' (say plainly that it failed and why)'}.`;
  }
  function show(c) {
    close();
    const ok = c.status === 'done', b = document.createElement('button');
    b.type = 'button'; b.className = 'jvp' + (ok ? '' : ' fail');
    b.innerHTML = '<i></i><div><b></b><span></span></div>';
    b.querySelector('i').textContent = ok ? '✅' : '⚠️'; b.querySelector('b').textContent = (ok ? 'Claude Code bitirdi' : 'Claude Code alınmadı') + ' · ' + (c.project || '');
    b.querySelector('span').textContent = 'Jarvis-ə danışdırmaq üçün toxun';
    b.onclick = () => {
      close();
      if (JV.panel) JV.panel.hidden = false;
      if (JV.ready()) { JV.say(note(c)); return; }
      let fired = false;   // yalnız bu sessiyada, bir dəfə (dinləyici silinmir, ona görə bayraq)
      JV.on('ready', () => { if (fired) return; fired = true; setTimeout(() => JV.say(note(c)), 600); });
      JV.start();
    };
    document.body.append(b); toast = b; toastT = setTimeout(close, 20000);
  }
  function tick() {
    if (document.hidden) return;
    let cmds = null; try { cmds = typeof data !== 'undefined' && data && data.commands; } catch (e) {}
    if (!cmds) return;
    for (const c of cmds) {
      const id = +c.id, fin = c.status === 'done' || c.status === 'failed';
      if (!fin || seen.has(id)) continue;
      seen.add(id);
      if (!primed || told.has(id) || c.kind === 'review' || (me && c.user && c.user !== me)) continue;
      if (JV.ready()) { JV.say(note(c)); told.add(id); } else show(c);
    }
    primed = true;
  }
  setInterval(tick, 4000);
  JV.on('start', close);
})();
