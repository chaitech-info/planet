/* Real-time hadisə şini (Jarvis Ultra §1): window.RT.
 * RT.on(type | 'command.*' | '*', cb(evt)) → off funksiyası; RT.off(type, cb); RT.connected(); RT.lastId(); RT.mode(); RT.mark(kind, fields).
 * evt = {id, type, user, project, ref, data, created_at}. Əvvəl SSE (events.php), alınmasa long-poll (events.php?poll=1&since=).
 * command.* / approval.* / session.* gələndə qlobal load() dərhal (150 ms debounce) çağırılır; şin sağlamdırsa window.LOAD_EVERY = 10 s (yoxsa 3 s).
 * Gizli tabda 5 dəq sonra şin bağlanır (server prosesləri boşuna tutulmasın); RT.keepAlive = true (məs. Jarvis səs sessiyası) bunu saxlayır. */
(function () {
  'use strict';
  if (window.RT) return;
  const subs = new Map();                 // type → Set(cb)
  let last = 0, es = null, mode = 'sse', ok = false, sseFails = 0, helloT = 0, pollCtl = null, stopped = false, hidT = 0, loadT = 0, pollBack = 0;
  const LOAD_FAST = 3000, LOAD_SLOW = 10000;
  window.LOAD_EVERY = LOAD_FAST;

  function setOk(v) {
    if (ok === v) return;
    ok = v;
    window.LOAD_EVERY = v ? LOAD_SLOW : LOAD_FAST;
    if (!v) kickLoad(0);                 // şin qopdu: bu arada buraxılanları tut
    emit({ id: 0, type: 'rt.status', user: '', project: '', ref: 0, data: { connected: v, mode }, created_at: Math.floor(Date.now() / 1000) });
  }
  function kickLoad(ms) {
    if (typeof window.load !== 'function') return;
    clearTimeout(loadT);
    loadT = setTimeout(() => { try { window.load(); } catch (e) {} }, ms);
  }
  function emit(e) {
    const t = e.type || '', dot = t.indexOf('.');
    for (const k of [t, dot > 0 ? t.slice(0, dot) + '.*' : null, '*']) {
      const s = k && subs.get(k);
      if (s) for (const cb of [...s]) { try { cb(e); } catch (err) { console.error('RT', t, err); } }
    }
  }
  function handle(e) {
    if (!e || typeof e !== 'object' || !e.id || e.id <= last) return;   // təkrar (yenidən qoşulma) → at
    last = e.id;
    if (/^(command|approval|session)\./.test(e.type)) kickLoad(150);
    emit(e);
  }

  /* ---------- SSE ---------- */
  function sse() {
    if (stopped) return;
    if (!window.EventSource) return poll();
    try { es = new EventSource('events.php' + (last ? '?since=' + last : ''), { withCredentials: true }); } catch (e) { return poll(); }
    let hello = false;
    helloT = setTimeout(() => {          // açıldı, amma hello gəlmir → hostinq axını buferləyir: long-poll-a keç
      if (!hello) { closeSse(); mode = 'poll'; poll(); }
    }, 5000);
    es.addEventListener('hello', (m) => {
      hello = true; clearTimeout(helloT); sseFails = 0;
      try { const d = JSON.parse(m.data); if (!last && d.last) last = d.last; } catch (e) {}
      setOk(true);
    });
    es.onmessage = (m) => { try { handle(JSON.parse(m.data)); } catch (e) {} };
    es.onerror = () => {
      // server 55 s-dən sonra bağlayır: brauzer retry: 1500 ilə özü qoşulur (Last-Event-ID ilə). Ardıcıl uğursuzluqda long-poll.
      if (es && es.readyState === 2) {   // CLOSED: brauzer daha cəhd etmir (401/500 və ya SSE olmayan cavab, məs. realtime = poll/off)
        closeSse();
        if (!hello || ++sseFails >= 3) { mode = 'poll'; poll(); } else setTimeout(sse, 1500 * sseFails);
        setOk(false);
      } else if (!hello) { if (++sseFails >= 3) { closeSse(); mode = 'poll'; poll(); } }
    };
  }
  function closeSse() { clearTimeout(helloT); if (es) { try { es.close(); } catch (e) {} es = null; } }

  /* ---------- Long-poll ---------- */
  async function poll() {
    if (stopped || pollCtl) return;
    pollCtl = new AbortController();
    try {
      const r = await fetch('events.php?poll=1&wait=25' + (last ? '&since=' + last : ''), { cache: 'no-store', credentials: 'same-origin', signal: pollCtl.signal });
      if (r.status === 401) { pollCtl = null; setOk(false); stopped = true; return; }
      const d = await r.json();
      if (d.off) { pollCtl = null; setOk(false); stopped = true; return; }   // server şini söndürüb (config realtime = off)
      (d.events || []).forEach(handle);
      if (d.last > last) last = d.last;
      pollBack = 0; setOk(true);
    } catch (e) {
      if (e && e.name === 'AbortError') { pollCtl = null; return; }
      setOk(false); pollBack = Math.min(15000, (pollBack || 1000) * 2);
    }
    pollCtl = null;
    if (!stopped) setTimeout(poll, pollBack);
  }

  function start() { stopped = false; if (es || pollCtl) return; mode === 'sse' ? sse() : poll(); }
  function stop() { stopped = true; closeSse(); if (pollCtl) { try { pollCtl.abort(); } catch (e) {} pollCtl = null; } setOk(false); }

  document.addEventListener('visibilitychange', () => {
    clearTimeout(hidT);
    if (document.hidden) hidT = setTimeout(() => { if (document.hidden && !RT.keepAlive) stop(); }, 300000);
    else if (stopped) { start(); kickLoad(0); }
  });

  const RT = window.RT = {
    keepAlive: false,
    on(type, cb) {
      if (!subs.has(type)) subs.set(type, new Set());
      subs.get(type).add(cb);
      return () => RT.off(type, cb);
    },
    off(type, cb) { const s = subs.get(type); if (s) s.delete(cb); },
    /** Bir dəfəlik gözləmə: RT.wait('ops.result', e => e.ref === id, 60000) → Promise(evt | null) */
    wait(type, pred, ms) {
      return new Promise((res) => {
        let t = 0;
        const off = RT.on(type, (e) => { if (!pred || pred(e)) { clearTimeout(t); off(); res(e); } });
        t = setTimeout(() => { off(); res(null); }, ms || 60000);
      });
    },
    connected: () => ok,
    lastId: () => last,
    mode: () => mode,
    /** Gecikmə ölçüsü: RT.mark('voice', {said_at, start_at, first_at, done_at, ref, meta}) (ms, Date.now()) */
    mark(kind, f) {
      f = f || {};
      return fetch('latency.php', {
        method: 'POST', keepalive: true, credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' },
        body: JSON.stringify({ action: 'mark', kind, ref: f.ref || 0, said_at: f.said_at || 0, start_at: f.start_at || 0, first_at: f.first_at || 0, done_at: f.done_at || 0, meta: f.meta || undefined }),
      }).then((r) => r.json()).catch(() => null);
    },
    stats(all) {
      return fetch('latency.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'stats', all: !!all }) })
        .then((r) => r.json()).catch(() => null);
    },
    _stop: stop, _start: start,
  };
  start();
})();
