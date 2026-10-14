/* Ratsiya: komanda yoldaşları ilə bas-danış səs (polis ratsiyası kimi).
   Düyməni (və ya Space) basılı saxlayıb danışırsan, buraxanda klip serverə gedir (radio.php); ratsiyası açıq olanlar 2 saniyədən bir
   Web Worker ilə soruşub çalır. Worker-in taymeri brauzer arxa planda olanda da yavaşlamır, ona görə səs tab arxada olsa da gəlir.
   Səs radio tembrindədir (300–3400 Hz zolaq + yüngül təhrif), hər klipin əvvəlində "xışıltı", sonunda "roger" siqnalı sintez olunur.
   index.php-dəki qlobal `data` (istifadəçilər) və `ME` istifadə olunur. */
(() => {
  if (document.body.classList.contains('student')) return;
  const R = { on: false, since: 0, stream: null, rec: null, chunks: [], mime: '', tx: false, txT0: 0, txTimer: null, q: [], playing: false,
    ctx: null, chain: null, worker: null, to: '*', onUsers: [], log: [], notif: false, busyUntil: 0 };
  const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- UI ---------- */
  const css = `
#rd-btn.on { background: linear-gradient(135deg,#16a34a,#0ea5e9); border-color: transparent; color: #fff; }
#rd-btn.rx { background: linear-gradient(135deg,#f59e0b,#ef4444); border-color: transparent; color: #fff; }
.rd { position: fixed; left: 18px; bottom: 18px; z-index: 70; width: min(300px, calc(100vw - 32px)); display: flex; flex-direction: column; gap: 10px; padding: 14px;
  background: rgba(8,10,20,.88); -webkit-backdrop-filter: blur(18px) saturate(1.3); backdrop-filter: blur(18px) saturate(1.3); color: #e9eef8; border: 1px solid rgba(74,222,128,.3);
  border-radius: 20px; box-shadow: 0 18px 50px rgba(0,0,0,.5); font-size: 13.5px; }
.rd[hidden] { display: none; }
.rd-top { display: flex; align-items: center; gap: 10px; }
.rd-led { width: 12px; height: 12px; border-radius: 50%; background: #4b5563; box-shadow: 0 0 0 3px rgba(255,255,255,.06); flex: none; transition: background-color .2s ease, box-shadow .2s ease; }
.rd-led.on { background: #4ade80; box-shadow: 0 0 10px #4ade80, 0 0 0 3px rgba(74,222,128,.15); }
.rd-led.rx { background: #fbbf24; box-shadow: 0 0 12px #fbbf24; animation: rdblink .5s steps(2) infinite; }
.rd-led.tx { background: #f87171; box-shadow: 0 0 12px #f87171; }
@keyframes rdblink { to { opacity: .35; } }
.rd-ttl { flex: 1; min-width: 0; } .rd-ttl b { display: block; font-size: 14px; letter-spacing: .14em; } .rd-ttl span { color: rgba(203,213,225,.75); font-size: 12.5px; }
.rd-x { border: 0; background: rgba(255,255,255,.08); color: #e9eef8; width: 28px; height: 28px; border-radius: 9px; cursor: pointer; font-size: 14px; }
.rd-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: rgba(203,213,225,.8); }
.rd-row select { flex: 1; background: rgba(255,255,255,.06); color: #e9eef8; border: 1px solid rgba(255,255,255,.14); border-radius: 10px; padding: 6px 8px; font: inherit; font-size: 13px; }
.rd-row select option { color: #111; }
.rd-who { font-size: 12px; color: rgba(148,163,184,.95); min-height: 16px; }
.rd-who b { color: #86efac; font-weight: 600; }
.rd-ptt { position: relative; border: 0; border-radius: 18px; padding: 18px 12px; cursor: pointer; font: inherit; color: #fff; touch-action: none; user-select: none; -webkit-user-select: none;
  background: linear-gradient(160deg,#1f2937,#0b1220); box-shadow: inset 0 1px 0 rgba(255,255,255,.12), 0 8px 24px rgba(0,0,0,.4); transition: transform .08s ease, background .15s ease; display: grid; gap: 4px; justify-items: center; }
.rd-ptt:disabled { opacity: .45; cursor: default; }
.rd-ptt .rd-ptt-i { font-size: 26px; } .rd-ptt .rd-ptt-t { font-weight: 700; letter-spacing: .16em; font-size: 13px; } .rd-ptt .rd-ptt-h { font-size: 11px; color: rgba(203,213,225,.65); }
.rd-ptt.tx { background: linear-gradient(160deg,#ef4444,#991b1b); transform: scale(.97); box-shadow: 0 0 0 4px rgba(239,68,68,.25), 0 0 30px rgba(239,68,68,.5); }
.rd-ptt.tx .rd-ptt-t::after { content: " · DANIŞ"; }
.rd-ptt.rx { background: linear-gradient(160deg,#b45309,#78350f); }
.rd-meter { height: 4px; border-radius: 4px; background: rgba(255,255,255,.1); overflow: hidden; } .rd-meter i { display: block; height: 100%; width: 0; background: linear-gradient(90deg,#4ade80,#fbbf24,#f87171); transition: width .06s linear; }
.rd-log { display: grid; gap: 4px; max-height: 120px; overflow: auto; scrollbar-width: thin; font-size: 12px; color: rgba(203,213,225,.85); }
.rd-log .rl { display: flex; align-items: center; gap: 6px; } .rd-log .rl b { font-weight: 600; } .rd-log .rl span { color: rgba(148,163,184,.9); flex: 1; }
.rd-log .rl button { border: 0; background: rgba(255,255,255,.08); color: #e9eef8; border-radius: 7px; padding: 1px 7px; font-size: 11px; cursor: pointer; }
.rd-bar { display: flex; gap: 8px; }
.rd-bar button { flex: 1; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9eef8; border-radius: 999px; padding: 8px 10px; font: inherit; font-size: 13px; cursor: pointer; }
.rd-bar .go { background: linear-gradient(135deg,#16a34a,#0284c7); border-color: transparent; font-weight: 600; }
.rd-bar .off { background: rgba(248,113,113,.18); border-color: rgba(248,113,113,.5); }
.rd-err { font-size: 12px; color: #fca5a5; } .rd-err[hidden] { display: none; }
@media (max-width: 700px) { .rd { left: 10px; right: 10px; bottom: 10px; width: auto; } }`;
  document.head.append(Object.assign(document.createElement('style'), { textContent: css }));
  const box = document.createElement('div'); box.className = 'rd'; box.hidden = true;
  box.innerHTML = `<div class="rd-top"><div class="rd-led" id="rd-led"></div><div class="rd-ttl"><b>RATSİYA</b><span id="rd-st">bağlıdır</span></div><button class="rd-x" id="rd-close" aria-label="Bağla">✕</button></div>
<div class="rd-row"><span>Kanal</span><select id="rd-to" aria-label="Kanal"><option value="*">📡 Hamıya</option></select></div>
<div class="rd-who" id="rd-who">Ratsiyası açıq olan yoxdur</div>
<button class="rd-ptt" id="rd-ptt" disabled><span class="rd-ptt-i">🎙</span><span class="rd-ptt-t">BAS-DANIŞ</span><span class="rd-ptt-h">basılı saxla · klaviaturada Space</span></button>
<div class="rd-meter"><i id="rd-lv"></i></div>
<div class="rd-err" id="rd-err" hidden></div>
<div class="rd-log" id="rd-log"></div>
<div class="rd-bar"><button class="go" id="rd-power">📻 Aç</button></div>`;
  document.body.append(box);
  const $r = id => document.getElementById(id);
  const btn = document.createElement('button'); btn.className = 'gbtn'; btn.id = 'rd-btn'; btn.title = 'Ratsiya: komanda ilə bas-danış səs (Alt+R)'; btn.textContent = '📻 Ratsiya';
  const jv = document.getElementById('jv-btn'), meta = document.querySelector('header .meta');
  if (jv) jv.after(btn); else if (meta) meta.insertBefore(btn, meta.firstChild.nextSibling);

  const setSt = t => { $r('rd-st').textContent = t; };
  const setLed = s => { $r('rd-led').className = 'rd-led' + (s ? ' ' + s : ''); btn.classList.toggle('on', R.on && s !== 'rx'); btn.classList.toggle('rx', s === 'rx'); };
  const err = t => { const e = $r('rd-err'); e.hidden = !t; e.textContent = t || ''; };
  function fillUsers() {
    // ME və data index.php-də `let` ilə qlobaldır (window-da deyil)
    const sel = $r('rd-to'), me = typeof ME !== 'undefined' && ME ? ME.name : '', users = (typeof data !== 'undefined' && data && data.users) || [];
    const want = users.join('|'); if (sel._w === want) return; sel._w = want;
    const cur = sel.value;
    sel.replaceChildren(Object.assign(document.createElement('option'), { value: '*', textContent: '📡 Hamıya' }), ...users.filter(u => u !== me).map(u => Object.assign(document.createElement('option'), { value: u, textContent: '👤 ' + u })));
    sel.value = [...sel.options].some(o => o.value === cur) ? cur : '*'; R.to = sel.value;
  }
  function drawWho() {
    const w = $r('rd-who');
    w.replaceChildren();
    if (!R.on) { w.textContent = 'Ratsiya bağlıdır. Açanda kim eşidirsə görünür.'; return; }
    if (!R.onUsers.length) { w.textContent = 'Hələ başqa heç kimin ratsiyası açıq deyil (yazdığın klip 2 dəq saxlanır, açanda eşidər).'; return; }
    w.append('Eşidir: '); R.onUsers.forEach((u, i) => { if (i) w.append(', '); w.append(Object.assign(document.createElement('b'), { textContent: u })); });
  }
  function addLog(who, to, dur, blob, mime) {
    const l = $r('rd-log'), row = document.createElement('div'); row.className = 'rl';
    const b = document.createElement('b'); b.textContent = who; const s = document.createElement('span'); s.textContent = (to === '*' ? 'hamıya' : '→ ' + to) + ' · ' + dur.toFixed(1) + ' san · ' + new Date().toLocaleTimeString('az', { hour: '2-digit', minute: '2-digit' });
    row.append(b, s);
    if (blob) { const p = document.createElement('button'); p.textContent = '▶'; p.title = 'Yenidən çal'; p.onclick = () => { if (!R.playing && !R.tx) playClip(blob, mime, who, true); }; row.append(p); }
    l.prepend(row); while (l.children.length > 12) l.lastChild.remove();
  }

  /* ---------- Səs: radio tembri və siqnallar ---------- */
  function ensureCtx() {
    if (R.ctx) { if (R.ctx.state === 'suspended') R.ctx.resume(); return R.ctx; }
    const ctx = R.ctx = new (window.AudioContext || window.webkitAudioContext)();
    const hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.value = 320;
    const lp = ctx.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 3400;
    const ws = ctx.createWaveShaper(); const n = 256, curve = new Float32Array(n);
    for (let i = 0; i < n; i++) { const x = i * 2 / n - 1; curve[i] = Math.tanh(x * 2.2) / Math.tanh(2.2); }   // yüngül "sıxılmış" radio səsi
    ws.curve = curve; ws.oversample = '2x';
    const comp = ctx.createDynamicsCompressor(); comp.threshold.value = -24; comp.ratio.value = 6; comp.attack.value = .003; comp.release.value = .12;
    const out = ctx.createGain(); out.gain.value = 1.15;
    hp.connect(lp); lp.connect(ws); ws.connect(comp); comp.connect(out); out.connect(ctx.destination);
    R.chain = { input: hp, out };
    return ctx;
  }
  function noiseBurst(t0, dur, peak, f0, q, decay) {
    const ctx = R.ctx, len = Math.ceil(ctx.sampleRate * dur), buf = ctx.createBuffer(1, len, ctx.sampleRate), d = buf.getChannelData(0);
    for (let i = 0; i < len; i++) d[i] = Math.random() * 2 - 1;
    const src = ctx.createBufferSource(); src.buffer = buf;
    const bp = ctx.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = f0; bp.Q.value = q;
    const g = ctx.createGain(); g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(peak, t0 + .008); g.gain.exponentialRampToValueAtTime(.001, t0 + dur * (decay || 1));
    src.connect(bp); bp.connect(g); g.connect(ctx.destination); src.start(t0); src.stop(t0 + dur + .02);
  }
  function tone(t0, f, dur, peak, type) {
    const ctx = R.ctx, o = ctx.createOscillator(), g = ctx.createGain(); o.type = type || 'square'; o.frequency.value = f;
    const bp = ctx.createBiquadFilter(); bp.type = 'lowpass'; bp.frequency.value = 2600;
    g.gain.setValueAtTime(0, t0); g.gain.linearRampToValueAtTime(peak, t0 + .006); g.gain.setValueAtTime(peak, t0 + dur - .015); g.gain.linearRampToValueAtTime(0, t0 + dur);
    o.connect(bp); bp.connect(g); g.connect(ctx.destination); o.start(t0); o.stop(t0 + dur + .01);
  }
  /** Kanal açılır: qısa "klik" + xışıltı (polis ratsiyasının squelch səsi). Qaytarır: müddət (san) */
  function squelchOpen() {
    const ctx = ensureCtx(), t = ctx.currentTime + .01;
    tone(t, 90, .018, .25, 'square');                                        // relenin kliki
    noiseBurst(t + .012, .17, .42, 1900, .7, 1);                              // xışıltı
    noiseBurst(t + .05, .25, .12, 900, .4, 1);                                // alt fon
    return .2;
  }
  /** Kanal bağlanır: xışıltı quyruğu + iki tonlu "roger" siqnalı */
  function squelchClose() {
    const ctx = ensureCtx(), t = ctx.currentTime + .01;
    noiseBurst(t, .11, .32, 2300, .9, 1);
    tone(t + .09, 1250, .055, .14, 'square');
    tone(t + .15, 1650, .07, .14, 'square');
    noiseBurst(t + .2, .06, .14, 2800, 1, 1);
    tone(t + .26, 80, .02, .2, 'square');                                     // rele buraxıldı
    return .32;
  }
  const wait = ms => new Promise(r => setTimeout(r, ms));

  /* ---------- Qəbul: worker ilə sorğu (arxa planda da), növbə ilə çalma ---------- */
  const WORKER = `let since=0,t=null,url=${JSON.stringify(new URL('radio.php', location.href).href)};
onmessage=e=>{ if(e.data.cmd==='start'){ since=e.data.since||0; clearTimeout(t); loop(); } else if(e.data.cmd==='stop'){ clearTimeout(t); since=-1; } };
async function loop(){ if(since<0) return;
  try{ const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'fetch'},body:JSON.stringify({action:'poll',since})});
    const d=await r.json(); if(since<0) return; if(r.status===401){ postMessage({dead:true}); return; } since=d.last||since; postMessage(d); }catch(e){}
  t=setTimeout(loop,2000); }`;
  function startWorker() {
    stopWorker();
    const w = R.worker = new Worker(URL.createObjectURL(new Blob([WORKER], { type: 'application/javascript' })));
    w.onmessage = e => {
      const d = e.data || {};
      if (d.dead) { powerOff('Giriş bitib, səhifəni yenilə'); return; }
      if (!R.on) return;
      R.onUsers = d.on || []; drawWho();
      for (const c of d.clips || []) R.q.push(c);
      playNext();
    };
    w.postMessage({ cmd: 'start', since: R.since });
  }
  function stopWorker() { if (R.worker) { R.worker.postMessage({ cmd: 'stop' }); R.worker.terminate(); R.worker = null; } }
  async function fetchClip(id) {
    const r = await fetch('radio.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'clip', id }) });
    if (r.status !== 200) throw new Error('klip ' + r.status);
    return r.blob();
  }
  /** Klipi radio zənciri ilə çalır; bitəndə həll olunur */
  function playBlob(blob, dur) {
    return new Promise(res => {
      const ctx = ensureCtx(), url = URL.createObjectURL(blob), a = new Audio(url); a.preload = 'auto';
      let done = false; const fin = () => { if (done) return; done = true; clearTimeout(tm); URL.revokeObjectURL(url); try { src.disconnect(); } catch (e) {} res(); };
      const src = ctx.createMediaElementSource(a); src.connect(R.chain.input);
      a.onended = fin; a.onerror = fin;
      const tm = setTimeout(fin, (Math.max(1, dur || 10) + 3) * 1000);
      a.play().catch(fin);
    });
  }
  async function playClip(blob, mime, who, replay) {
    R.playing = true; setLed('rx'); $r('rd-ptt').classList.add('rx'); setSt('📻 ' + who + ' danışır…');
    try {
      await wait(squelchOpen() * 1000 - 60);
      await playBlob(blob);
      await wait(squelchClose() * 1000);
    } catch (e) {}
    R.playing = false; $r('rd-ptt').classList.remove('rx'); setLed(R.on ? 'on' : ''); setSt(R.on ? 'açıqdır · dinləyirəm' : 'bağlıdır');
    if (!replay) playNext();
  }
  async function playNext() {
    if (R.playing || R.tx || !R.on || !R.q.length) return;
    const c = R.q.shift();
    let blob; try { blob = await fetchClip(c.id); } catch (e) { playNext(); return; }
    if (document.hidden && R.notif) { try { new Notification('📻 ' + c.user + ' danışır', { body: c.target === '*' ? 'Hamıya · ' + (+c.dur).toFixed(0) + ' san' : 'Sənə · ' + (+c.dur).toFixed(0) + ' san', tag: 'radio', silent: true }); } catch (e) {} }
    addLog(c.user, c.target, +c.dur || 0, blob, c.mime);
    await playClip(blob, c.mime, c.user, false);
  }

  /* ---------- Ötürmə: bas-danış ---------- */
  function pickMime() {
    for (const m of ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']) if (window.MediaRecorder && MediaRecorder.isTypeSupported(m)) return m;
    return '';
  }
  let meterRaf = 0, meterAn = null, meterBuf = null;
  function meter(on) {
    cancelAnimationFrame(meterRaf);
    if (!on) { $r('rd-lv').style.width = '0'; return; }
    if (!meterAn) { meterAn = R.ctx.createAnalyser(); meterAn.fftSize = 512; meterBuf = new Uint8Array(meterAn.fftSize); R.ctx.createMediaStreamSource(R.stream).connect(meterAn); }
    const tick = () => { meterAn.getByteTimeDomainData(meterBuf); let s = 0; for (const v of meterBuf) s += ((v - 128) / 128) ** 2; $r('rd-lv').style.width = Math.min(100, Math.sqrt(s / meterBuf.length) * 400) + '%'; meterRaf = requestAnimationFrame(tick); };
    tick();
  }
  function startTx() {
    if (!R.on || R.tx || !R.stream || !R.mime) return;
    R.tx = true; R.txT0 = Date.now(); R.chunks = [];
    try { R.rec = new MediaRecorder(R.stream, { mimeType: R.mime, audioBitsPerSecond: 32000 }); }
    catch (e) { R.tx = false; err('Yazmaq alınmadı: ' + (e.message || e)); return; }
    R.rec.ondataavailable = ev => { if (ev.data && ev.data.size) R.chunks.push(ev.data); };
    R.rec.onstop = () => finishTx();
    squelchOpen();
    setTimeout(() => { if (R.tx && R.rec && R.rec.state === 'inactive') R.rec.start(); }, 120);   // xışıltıdan sonra yazmağa başla
    if (R.rec.state === 'inactive') setTimeout(() => { if (R.tx && R.rec.state === 'inactive') try { R.rec.start(); } catch (e) {} }, 130);
    $r('rd-ptt').classList.add('tx'); setLed('tx'); setSt('🔴 Danışırsan… buraxanda gedir'); meter(true);
    R.txTimer = setTimeout(stopTx, 60000);                                   // ən çox 60 saniyə
  }
  function stopTx() {
    if (!R.tx) return;
    clearTimeout(R.txTimer); meter(false);
    $r('rd-ptt').classList.remove('tx'); setLed(R.on ? 'on' : '');
    if (R.rec && R.rec.state !== 'inactive') R.rec.stop(); else finishTx();
  }
  async function finishTx() {
    R.tx = false;
    const dur = (Date.now() - R.txT0) / 1000, blob = new Blob(R.chunks, { type: R.mime.split(';')[0] }); R.chunks = [];
    squelchClose();
    if (dur < .45 || blob.size < 200) { setSt('açıqdır · dinləyirəm'); playNext(); return; }
    setSt('📤 göndərilir…');
    const fd = new FormData(); fd.append('action', 'send'); fd.append('to', R.to); fd.append('dur', dur.toFixed(2)); fd.append('mime', blob.type); fd.append('clip', blob, 'clip');
    try {
      const r = await fetch('radio.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
      const d = await r.json().catch(() => ({}));
      if (r.status !== 200) err(d.error || 'Göndərilmədi'); else { err(''); addLog('Sən', R.to, dur, blob, blob.type); }
    } catch (e) { err('Bağlantı xətası'); }
    setSt(R.on ? 'açıqdır · dinləyirəm' : 'bağlıdır');
    playNext();
  }

  /* ---------- Aç / bağla ---------- */
  async function powerOn() {
    if (R.on) return;
    err('');
    if (!window.isSecureContext || !navigator.mediaDevices || !window.MediaRecorder) { err('Ratsiya yalnız HTTPS-də (və ya localhost) işləyir.'); return; }
    R.mime = pickMime(); if (!R.mime) { err('Bu brauzer səs yazmağı dəstəkləmir.'); return; }
    ensureCtx();
    try { R.stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true, channelCount: 1 } }); }
    catch (e) { err('Mikrofona icazə verilmədi: ' + (e.message || e)); return; }
    if ('Notification' in window) { if (Notification.permission === 'default') Notification.requestPermission().then(p => { R.notif = p === 'granted'; }); else R.notif = Notification.permission === 'granted'; }
    R.on = true; R.since = 0; R.q = [];
    fillUsers(); startWorker();
    $r('rd-ptt').disabled = false; setLed('on'); setSt('açıqdır · dinləyirəm'); drawWho();
    const p = $r('rd-power'); p.textContent = '⏻ Bağla'; p.className = 'off';
    squelchOpen();
  }
  function powerOff(msg) {
    if (!R.on) return;
    if (R.tx) stopTx();
    R.on = false; stopWorker(); R.q = [];
    if (R.stream) { R.stream.getTracks().forEach(t => t.stop()); R.stream = null; } meterAn = null; meter(false);
    fetch('radio.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'off' }), keepalive: true }).catch(() => {});
    $r('rd-ptt').disabled = true; setLed(''); setSt(msg || 'bağlıdır'); R.onUsers = []; drawWho();
    const p = $r('rd-power'); p.textContent = '📻 Aç'; p.className = 'go';
    if (R.ctx) squelchClose();
  }

  /* ---------- Hadisələr ---------- */
  const ptt = $r('rd-ptt');
  ptt.addEventListener('pointerdown', e => { e.preventDefault(); ptt.setPointerCapture(e.pointerId); startTx(); });
  for (const ev of ['pointerup', 'pointercancel']) ptt.addEventListener(ev, e => { e.preventDefault(); stopTx(); });
  ptt.addEventListener('contextmenu', e => e.preventDefault());
  const typing = () => { const a = document.activeElement; return a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable); };
  addEventListener('keydown', e => {
    if (e.altKey && e.code === 'KeyR') { e.preventDefault(); box.hidden = !box.hidden; if (!box.hidden) { fillUsers(); if (!R.on) powerOn(); } return; }
    if (e.code === 'Space' && R.on && !box.hidden && !typing()) { e.preventDefault(); if (!e.repeat) startTx(); }
  });
  addEventListener('keyup', e => { if (e.code === 'Space' && R.tx) { e.preventDefault(); stopTx(); } });
  addEventListener('blur', () => { if (R.tx) stopTx(); });
  btn.onclick = () => { box.hidden = !box.hidden; if (!box.hidden) { fillUsers(); if (!R.on) powerOn(); } };
  $r('rd-close').onclick = () => { box.hidden = true; };                     // panel bağlanır, ratsiya açıq qalır (arxada dinləyir)
  $r('rd-power').onclick = () => R.on ? powerOff() : powerOn();
  $r('rd-to').onchange = () => { R.to = $r('rd-to').value; };
  addEventListener('beforeunload', () => { if (R.on) navigator.sendBeacon && fetch('radio.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'off' }), keepalive: true }).catch(() => {}); });
  setInterval(() => { if (!box.hidden) fillUsers(); if (R.on) btn.textContent = R.playing ? '📻 ' + ($r('rd-st').textContent.replace('📻 ', '')) : '📻 Ratsiya · açıq'; else btn.textContent = '📻 Ratsiya'; }, 1500);
  window.Radio = { on: powerOn, off: powerOff, state: () => ({ on: R.on, tx: R.tx, playing: R.playing, listeners: R.onUsers }) };
})();
