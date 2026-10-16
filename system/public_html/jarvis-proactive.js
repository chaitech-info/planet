/* Jarvis modulu: proaktiv xəbərlər. Claude Code tapşırığın bitəndə/alınmayanda Jarvis özü xəbər verir:
   sessiya açıqdırsa danışır, bağlıdırsa sol aşağıda kiçik bildiriş çıxır (klik edəndə Jarvis açılır və nəticəni danışır).
   Həmçinin RT hadisələri: jarvis.notice (proaktiv bildiriş) və ops.result (health: CI qırmızıdır) — sakit saatlarda susur, 2 dəqiqədə ən çox 1 dəfə danışır.
   Sakit saatlar: localStorage jv-quiet = "23-8" (boş = yoxdur). Səsi/mikrofonu özbaşına açmır. Yalnız bu istifadəçinin öz tapşırıqları. */
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
  /** Bildiriş kartı: klik → Jarvis açılır və text-i danışır */
  function card(icon, title, sub, fail, text) {
    close();
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'jvp' + (fail ? ' fail' : '');
    b.innerHTML = '<i></i><div><b></b><span></span></div>';
    b.querySelector('i').textContent = icon; b.querySelector('b').textContent = title; b.querySelector('span').textContent = sub;
    b.onclick = () => {
      close();
      if (JV.panel) JV.panel.hidden = false;
      if (JV.ready()) { JV.say(text); return; }
      let fired = false;   // yalnız bu sessiyada, bir dəfə (dinləyici silinmir, ona görə bayraq)
      JV.on('ready', () => { if (fired) return; fired = true; setTimeout(() => JV.say(text), 600); });
      JV.start();
    };
    document.body.append(b); toast = b; toastT = setTimeout(close, 20000);
  }
  const show = c => { const ok = c.status === 'done'; card(ok ? '✅' : '⚠️', (ok ? 'Claude Code bitirdi' : 'Claude Code alınmadı') + ' · ' + (c.project || ''), 'Jarvis-ə danışdırmaq üçün toxun', !ok, note(c)); };
  function tick() {
    if (document.hidden) return;
    let cmds = null; try { cmds = typeof data !== 'undefined' && data && data.commands; } catch (e) {}
    if (!cmds) return;
    for (const c of cmds) {
      const id = +c.id, fin = c.status === 'done' || c.status === 'failed';
      if (!fin || seen.has(id)) continue;
      seen.add(id);
      if (!primed || told.has(id) || (JV.watching && JV.watching(id)) || ['review', 'think', 'ops'].includes(c.kind) || (me && c.user && c.user !== me)) continue;
      if (JV.ready()) { JV.say(note(c)); told.add(id); } else show(c);
    }
    primed = true;
  }
  setInterval(tick, 4000);
  JV.on('start', close);

  /* ---------- RT: jarvis.notice və CI qırmızı (ops.result, op=health) ---------- */
  const GAP = 120000; let lastSpoke = 0;
  const quietSpec = () => { try { return localStorage.getItem('jv-quiet') || ''; } catch (e) { return ''; } };
  function quiet(d = new Date()) {
    const m = /^(\d{1,2})\s*-\s*(\d{1,2})$/.exec(quietSpec().trim()); if (!m) return false;
    const a = +m[1], b = +m[2], h = d.getHours();
    return a === b ? false : a < b ? h >= a && h < b : h >= a || h < b;
  }
  /** Proaktiv danışıq: sakit saat / limit varsa yalnız kart (sessiya bağlıdırsa da kart) */
  function speak(text, icon, title, fail) {
    if (quiet()) return false;
    if (JV.ready()) { if (Date.now() - lastSpoke < GAP) return false; lastSpoke = Date.now(); JV.say(text); return true; }
    card(icon, title, 'Jarvis-ə danışdırmaq üçün toxun', fail, text); return true;
  }
  const ciRed = new Map();                                   // proyekt → {at, text}; Situation sətri üçün
  /** health nəticəsində son CI qaçışı uğursuzdurmu (claude_ops.py formatı dəqiq bilinmir: bir neçə ehtimal) */
  function ciFail(r) {
    if (!r || typeof r !== 'object') return null;
    const cand = r.ci || r.latest_ci || r.runs || r.gh_runs || r.run_list || (r.github && r.github.runs) || null;
    const first = Array.isArray(cand) ? cand[0] : cand; if (!first || typeof first !== 'object') return null;
    const c = String(first.conclusion || first.status || first.state || '').toLowerCase();
    return /fail|error|timed_out|startup_failure/.test(c) ? (first.name || first.workflowName || first.workflow || 'CI') + ': ' + c : null;
  }
  JV.rt('jarvis.notice', e => {
    const d = (e && e.data) || {}, t = String(d.text || '').slice(0, 400); if (!t) return;
    const bad = /error|fail|crit|warn/i.test(String(d.level || ''));
    speak(`[System note, not spoken by the user] Proactive notice (${d.level || 'info'}): ${t}\nTell the user in one short friendly sentence${bad ? ', plainly' : ''}.`, bad ? '⚠️' : '🔔', t.slice(0, 80), bad);
  });
  JV.rt('ops.result', e => {
    const d = (e && e.data) || {}; if (d.op !== 'health') return;
    const p = (e && e.project) || (d.data && d.data.project) || '', f = ciFail(d.data);
    if (!f) { ciRed.delete(p); return; }
    const was = ciRed.has(p); ciRed.set(p, { at: Date.now(), text: f }); if (was) return;   // eyni qırmızını təkrar danışma
    speak(`[System note, not spoken by the user] CI is red in project "${p}" (${f}). Tell the user in one short sentence and offer to send Claude Code to fix it.`, '🔴', 'CI qırmızı · ' + p, true);
  });
  window.JarvisProactive = { ciRed: () => [...ciRed.keys()].filter(Boolean), quiet, setQuiet: v => { try { localStorage.setItem('jv-quiet', v || ''); } catch (e) {} } };
})();
