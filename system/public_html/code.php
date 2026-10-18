<?php
// Claude Code (tam səhifə): Desktop Universe-in söhbətləri Claude Code UI-si kimi. Solda kompüter, söhbət və sessiya tarixçəsi,
// sağda seçilmiş söhbət və yazı qutusu. Bütün məlumat remote.php-dən gəlir (icazə qaydaları oradadır).
require __DIR__ . '/db.php';
auth_boot();
$me = session_user();
if ($me === null || is_student($me)) {
    header('Location: ./');
    exit;
}
session_write_close();
?><!doctype html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Claude Code · Komanda</title>
<style>
:root {
  --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
  --bg: #f7f6f2; --side: #efede6; --card: #fff; --text: #1b1b19; --muted: #6b6b64; --line: rgba(0,0,0,.09); --hover: rgba(0,0,0,.05); --sel: rgba(0,0,0,.08);
  --work: #d97706; --wait: #dc2626; --idle: #16a34a; --off: #a3a39c; --accent: #c4552d; --code: rgba(0,0,0,.05);
  --shadow: 0 1px 2px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.06);
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #1a1a1d; --side: #131315; --card: #242428; --text: #ececf1; --muted: #9a9aa6; --line: rgba(255,255,255,.09); --hover: rgba(255,255,255,.05); --sel: rgba(255,255,255,.09);
    --work: #fbbf24; --wait: #f87171; --idle: #4ade80; --off: #6b6b78; --accent: #f08a5d; --code: rgba(255,255,255,.07);
    --shadow: 0 1px 2px rgba(0,0,0,.4), 0 12px 32px rgba(0,0,0,.35);
  }
}
* { box-sizing: border-box; }
html, body { height: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; overflow: hidden; }
button { font: inherit; color: inherit; cursor: pointer; }
button:disabled { cursor: default; opacity: .5; }
.mono, code, pre, kbd { font-family: ui-monospace, Menlo, monospace; }
.sp { flex: 1; }
[hidden] { display: none !important; }

.app { display: grid; grid-template-columns: 290px 1fr; height: 100dvh; }

/* ---- Sol panel ---- */
.side { background: var(--side); border-right: 1px solid var(--line); display: flex; flex-direction: column; min-height: 0; padding-top: env(safe-area-inset-top); }
.sh { display: flex; align-items: center; gap: 9px; padding: 14px 14px 10px; }
.logo { width: 26px; height: 26px; border-radius: 8px; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 800; font-size: 14px; flex: none; }
.sh b { font-size: 15px; } .sh .sub { color: var(--muted); font-size: 12.5px; }
.ib { border: 0; background: none; border-radius: 8px; padding: 5px 8px; color: var(--muted); text-decoration: none; font-size: 14px; }
.ib:hover { background: var(--hover); color: var(--text); }
.newchat { margin: 2px 12px 10px; display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); font-weight: 600; font-size: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.newchat:hover:not(:disabled) { border-color: var(--accent); }
.newchat kbd { margin-left: auto; font-size: 11px; color: var(--muted); font-weight: 400; }

.pc { position: relative; margin: 0 12px 10px; }
.pcbtn { width: 100%; display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 10px; border: 1px solid var(--line); background: transparent; text-align: left; font-size: 13.5px; }
.pcbtn:hover { background: var(--hover); }
.pcbtn .pn { font-weight: 600; } .pcbtn .ps { color: var(--muted); font-size: 12px; margin-left: auto; white-space: nowrap; }
.pcmenu { position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 4px); background: var(--card); border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); padding: 5px; max-height: 60vh; overflow: auto; }
.pci { width: 100%; display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 2px 8px; padding: 7px 9px; border: 0; background: none; border-radius: 8px; text-align: left; font-size: 13.5px; }
.pci:hover, .pci.on { background: var(--hover); }
.pci .ps { grid-column: 2 / 4; color: var(--muted); font-size: 12px; }
.pci .lk { color: var(--muted); font-size: 12px; }

.stabs { display: flex; gap: 4px; margin: 0 12px 8px; padding: 3px; border-radius: 10px; background: var(--hover); }
.stabs button { flex: 1; border: 0; background: none; border-radius: 8px; padding: 5px 0; font-size: 13px; color: var(--muted); }
.stabs button.on { background: var(--card); color: var(--text); font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.search { margin: 0 12px 6px; }
.search input { width: 100%; border: 1px solid var(--line); background: transparent; border-radius: 9px; padding: 7px 10px; font: inherit; font-size: 13.5px; color: var(--text); outline: none; }
.search input:focus { border-color: var(--accent); }
.slist { flex: 1; overflow: auto; padding: 4px 8px 12px; min-height: 0; overscroll-behavior: contain; }
.sg { font-size: 11.5px; color: var(--muted); padding: 12px 8px 4px; font-weight: 600; }
.si { width: 100%; display: grid; grid-template-columns: auto 1fr; gap: 1px 8px; align-items: center; border: 0; background: none; border-radius: 9px; padding: 7px 8px; text-align: left; }
.si:hover { background: var(--hover); }
.si.on { background: var(--sel); }
.si .t { font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.si .m { grid-column: 2; font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sempty { color: var(--muted); font-size: 13px; padding: 14px 8px; }
.pendbox { margin: 0 12px 8px; padding: 8px 10px; border-radius: 10px; background: color-mix(in srgb, var(--wait) 12%, transparent); font-size: 12.5px; }
.pendbox button { border: 0; background: none; padding: 0; text-decoration: underline; color: inherit; font-size: 12.5px; }
.sfoot { border-top: 1px solid var(--line); padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); }
.av { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 12px; font-weight: 700; flex: none; }
.sfoot a { color: var(--muted); text-decoration: none; } .sfoot a:hover { color: var(--text); }

.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--off); flex: none; }
.dot.working { background: var(--work); } .dot.waiting { background: var(--wait); } .dot.idle { background: var(--idle); }
.dot.working, .dot.waiting { animation: pulse 1.6s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: .35; } }

/* ---- Əsas sahə ---- */
.main { display: flex; flex-direction: column; min-width: 0; min-height: 0; padding-top: env(safe-area-inset-top); }
.top { display: flex; align-items: center; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--line); min-height: 52px; }
.top .menu { display: none; }
.tt { min-width: 0; display: flex; flex-direction: column; }
.tt b { font-size: 14.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tt span { font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; padding: 3px 9px; border-radius: 99px; border: 1px solid var(--line); color: var(--muted); white-space: nowrap; }
.pill.ok { color: var(--idle); } .pill.warn { color: var(--work); } .pill.no { color: var(--wait); }
.tpills { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }

.scroll { flex: 1; overflow: auto; min-height: 0; overscroll-behavior: contain; scroll-behavior: auto; }
.col { max-width: 820px; margin: 0 auto; padding: 0 20px; }
.thread { padding: 22px 0 28px; }
.day { text-align: center; font-size: 11.5px; color: var(--muted); margin: 18px 0 8px; }

.msg { margin: 0 0 22px; } .msg.enter { animation: rise .35s var(--ease-out); }
@keyframes rise { from { opacity: 0; transform: translateY(6px); } }
.u { margin-left: auto; max-width: 86%; width: fit-content; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 10px 14px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
.u .ut { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14.5px; }
.u .ut.clamp { max-height: 11em; overflow: hidden; -webkit-mask-image: linear-gradient(#000 70%, transparent); mask-image: linear-gradient(#000 70%, transparent); }
.um { display: flex; gap: 6px; align-items: center; justify-content: flex-end; font-size: 11.5px; color: var(--muted); margin-top: 4px; }
.a { margin-top: 12px; display: grid; grid-template-columns: 22px 1fr; gap: 0 8px; }
.a .ic { color: var(--accent); font-size: 15px; line-height: 1.5; text-align: center; }
.a .ab { min-width: 0; }
.st { display: flex; align-items: center; gap: 7px; font-size: 13px; color: var(--muted); flex-wrap: wrap; min-height: 22px; }
.typing { display: inline-flex; gap: 3px; }
.typing i { width: 4px; height: 4px; border-radius: 50%; background: var(--work); animation: hop 1s ease-in-out infinite; }
.typing i:nth-child(2) { animation-delay: .15s; } .typing i:nth-child(3) { animation-delay: .3s; }
@keyframes hop { 50% { transform: translateY(-3px); opacity: .5; } }
.lb { border: 0; background: none; padding: 2px 6px; border-radius: 6px; font-size: 12.5px; color: var(--muted); }
.lb:hover { background: var(--hover); color: var(--text); }
.lb.go { color: var(--idle); font-weight: 600; } .lb.danger { color: var(--wait); }
.acts { display: flex; gap: 2px; flex-wrap: wrap; margin-top: 6px; margin-left: -6px; }

.steps { margin: 6px 0 4px; border-left: 2px solid var(--line); padding-left: 10px; display: grid; gap: 3px; }
.step { display: flex; gap: 8px; font-size: 12.5px; line-height: 1.45; min-width: 0; }
.step .tn { font-family: ui-monospace, Menlo, monospace; font-weight: 600; flex: none; }
.step .ts { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.step .tw { color: var(--muted); margin-left: auto; flex: none; font-size: 11px; }
.step.e .tn { color: var(--accent); }
.step.more { color: var(--muted); }

.res { margin-top: 6px; font-size: 14.5px; position: relative; }
.res.fold { max-height: 22em; overflow: hidden; -webkit-mask-image: linear-gradient(#000 75%, transparent); mask-image: linear-gradient(#000 75%, transparent); }
.res.err { color: var(--wait); }
.md p { margin: 0 0 10px; overflow-wrap: anywhere; white-space: pre-wrap; } .md > :last-child { margin-bottom: 0; }
.md .mdh { font-weight: 700; margin: 14px 0 6px; } .md .mdh:first-child { margin-top: 0; }
.md .mdl { display: grid; gap: 4px; margin: 0 0 10px; } .md .mdi { display: flex; gap: 8px; overflow-wrap: anywhere; } .md .mdb { color: var(--muted); flex: none; min-width: 12px; text-align: right; }
.md code { font-size: 13px; background: var(--code); padding: 1px 5px; border-radius: 5px; }
.md pre { margin: 0 0 10px; padding: 10px 12px; border-radius: 10px; background: var(--code); border: 1px solid var(--line); font-size: 12.5px; line-height: 1.55; overflow: auto; white-space: pre; }

.imgs { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.imgs a, .imgs .ci { position: relative; width: 64px; height: 64px; border-radius: 9px; overflow: hidden; border: 1px solid var(--line); display: block; }
.imgs img { width: 100%; height: 100%; object-fit: cover; display: block; }
.imgs .rm { position: absolute; top: 2px; right: 2px; border: 0; width: 20px; height: 20px; border-radius: 50%; background: rgba(0,0,0,.6); color: #fff; font-size: 11px; padding: 0; }

.ev { display: flex; gap: 8px; font-size: 12.5px; color: var(--muted); margin: 10px 0; align-items: center; }
.ev::before, .ev::after { content: ""; flex: 1; height: 1px; background: var(--line); }
.scard { border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; margin-bottom: 20px; background: var(--card); font-size: 13px; }
.scard b { font-size: 14.5px; } .scard .row { display: flex; gap: 6px 14px; flex-wrap: wrap; color: var(--muted); margin-top: 4px; }
.scard .note { margin-top: 8px; color: var(--muted); font-size: 12.5px; }

.hero { min-height: 52vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 10px; padding: 40px 0; }
.hero .logo { width: 44px; height: 44px; border-radius: 13px; font-size: 22px; }
.hero h1 { margin: 6px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -.01em; }
.hero p { margin: 0; color: var(--muted); max-width: 520px; font-size: 14px; }
.hero .chips { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; margin-top: 8px; }
.hero .chips button { border: 1px solid var(--line); background: var(--card); border-radius: 99px; padding: 6px 12px; font-size: 13px; }
.hero .chips button.on { border-color: var(--accent); color: var(--accent); }

.down { position: absolute; left: 50%; transform: translateX(-50%); top: -46px; border: 1px solid var(--line); background: var(--card); border-radius: 99px; padding: 5px 12px; font-size: 12.5px; box-shadow: var(--shadow); }
.down.new { border-color: var(--accent); color: var(--accent); }

/* ---- Yazı qutusu ---- */
.compwrap { padding: 0 0 calc(14px + env(safe-area-inset-bottom)); }
.compwrap .col { position: relative; }
.comp { border: 1px solid var(--line); background: var(--card); border-radius: 18px; box-shadow: var(--shadow); padding: 10px 10px 8px 14px; transition: border-color .15s; }
.comp:focus-within { border-color: color-mix(in srgb, var(--accent) 55%, var(--line)); }
.comp.drop { border-color: var(--accent); border-style: dashed; }
.comp textarea { width: 100%; border: 0; outline: none; resize: none; background: transparent; color: var(--text); font: inherit; font-size: 15px; line-height: 1.5; max-height: 40vh; min-height: 26px; padding: 2px 0; }
.crow { display: flex; align-items: center; gap: 6px; margin-top: 6px; }
.crow select { border: 1px solid var(--line); background: transparent; color: var(--text); border-radius: 8px; padding: 4px 8px; font: inherit; font-size: 12.5px; max-width: 46%; }
.crow #perm { max-width: 34%; }
.crow .hint { font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.crow .hint.lim { color: var(--work); }
.send { width: 34px; height: 34px; border-radius: 10px; border: 0; background: var(--accent); color: #fff; font-size: 17px; display: grid; place-items: center; flex: none; }
.send:disabled { background: var(--off); opacity: .55; }
.send.stop { background: var(--text); color: var(--bg); font-size: 13px; }
.cnote { font-size: 12px; color: var(--muted); text-align: center; margin-top: 7px; }
.cnote.warn { color: var(--work); }
.cerr { font-size: 12.5px; color: var(--wait); margin: 6px 4px 0; }
.lock { text-align: center; padding: 16px; border: 1px dashed var(--line); border-radius: 16px; color: var(--muted); font-size: 13.5px; }
.lock a { color: var(--accent); }

.toast { position: fixed; left: 50%; bottom: 24px; transform: translateX(-50%); background: var(--text); color: var(--bg); padding: 8px 14px; border-radius: 10px; font-size: 13px; z-index: 50; }
.scrim { display: none; }

@media (max-width: 860px) {
  .app { grid-template-columns: 1fr; }
  .side { position: fixed; z-index: 40; inset: 0 auto 0 0; width: min(86vw, 320px); transform: translateX(-102%); transition: transform .28s var(--ease-out); box-shadow: var(--shadow); }
  .app.open .side { transform: none; }
  .app.open .scrim { display: block; position: fixed; inset: 0; z-index: 30; background: rgba(0,0,0,.35); }
  .top { padding: 8px 10px; } .top .menu { display: block; font-size: 18px; }
  .tpills .pill:not(.main) { display: none; }
  .col { padding: 0 12px; }
  .u { max-width: 94%; }
  .comp textarea, .search input, .crow select { font-size: 16px; }
}
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
</style>
</head>
<body>
<div class="app" id="app">
  <aside class="side" id="side" aria-label="Söhbətlər və sessiyalar">
    <div class="sh"><div class="logo">C</div><div><b>Claude Code</b> <span class="sub">Desktop</span></div><span class="sp"></span><a class="ib" href="./#kainat/@desktop" title="Desktop Universe-ə qayıt">🌌</a></div>
    <button class="newchat" id="newchat" type="button">✎ Yeni söhbət <kbd id="nkbd">⌘⇧O</kbd></button>
    <div class="pc">
      <button class="pcbtn" id="pcbtn" type="button" aria-haspopup="listbox"><span class="dot"></span><span class="pn">…</span><span class="ps"></span> ▾</button>
      <div class="pcmenu" id="pcmenu" role="listbox" hidden></div>
    </div>
    <div class="pendbox" id="pendbox" hidden></div>
    <div class="stabs" role="tablist"><button type="button" data-t="chats" class="on">💬 Söhbətlər</button><button type="button" data-t="sessions">⌨ Sessiyalar</button></div>
    <div class="search"><input id="q" type="search" placeholder="Axtar…" autocomplete="off"></div>
    <div class="slist" id="slist"></div>
    <div class="sfoot"><span class="av" id="meav"></span><span id="mename"></span><span class="sp"></span><a href="./">Panel</a></div>
  </aside>
  <div class="scrim" id="scrim"></div>
  <main class="main">
    <div class="top">
      <button class="ib menu" id="menu" type="button" aria-label="Menyu">☰</button>
      <div class="tt" id="title"><b>Claude Code</b><span></span></div>
      <span class="sp"></span>
      <div class="tpills" id="tpills"></div>
    </div>
    <div class="scroll" id="scroll"><div class="col"><div class="thread" id="thread"></div></div></div>
    <div class="compwrap" id="compwrap">
      <div class="col">
        <button class="down" id="down" type="button" hidden>↓ Sona</button>
        <div id="compbox">
          <div class="comp" id="comp">
            <textarea id="ta" rows="1" maxlength="3500" placeholder="Claude Code nə etsin?"></textarea>
            <div class="imgs" id="imgs" hidden></div>
            <div class="crow">
              <select id="prj" aria-label="Proyekt"></select>
              <select id="perm" aria-label="Rejim">
                <option value="acceptEdits">✎ Accept edits</option>
                <option value="plan">⏸ Plan</option>
                <option value="auto">🤖 Auto</option>
                <option value="manual">✋ Manual</option>
              </select>
              <button class="lb" id="att" type="button" title="Şəkil əlavə et (ən çox 4). Yapışdırmaq və ya sürüşdürmək də olar">🖼</button>
              <input type="file" id="file" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
              <span class="hint" id="hint"></span>
              <span class="sp"></span>
              <button class="send" id="send" type="button" title="Göndər (Enter)" aria-label="Göndər">↑</button>
            </div>
          </div>
          <div class="cerr" id="cerr" hidden></div>
        </div>
        <div class="lock" id="lock" hidden></div>
        <div class="cnote" id="cnote"></div>
      </div>
    </div>
  </main>
</div>
<script>
'use strict';
/* ====== Köməkçilər ====== */
const $ = id => document.getElementById(id);
function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
const COARSE = matchMedia('(pointer: coarse)').matches;
const MAC = /Mac|iPhone|iPad/.test(navigator.platform);
const DAY = 86400;
let offset = 0;
const now = () => Date.now() / 1000 + offset;
const hue = s => { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
function ago(ts) {
  const s = Math.max(0, Math.floor(now() - ts));
  if (s < 10) return 'indi';
  if (s < 60) return s + ' san əvvəl';
  if (s < 3600) return Math.floor(s / 60) + ' dəq əvvəl';
  if (s < 86400) return Math.floor(s / 3600) + ' saat əvvəl';
  return Math.floor(s / 86400) + ' gün əvvəl';
}
function fmtDur(sec) {
  sec = Math.max(0, Math.floor(sec));
  const h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
  if (h) return h + 's ' + m + 'dəq';
  if (m) return m + 'dəq ' + s + 'san';
  return s + ' san';
}
const dayKey = ts => new Date(ts * 1000).toDateString();
function hm(ts) {
  const d = new Date(ts * 1000), t = d.toLocaleTimeString('az', { hour: '2-digit', minute: '2-digit' });
  return dayKey(ts) === dayKey(now()) ? t : d.toLocaleDateString('az', { day: 'numeric', month: 'short' }) + ' ' + t;
}
function dayLabel(ts) {
  const k = dayKey(ts);
  if (k === dayKey(now())) return 'Bu gün';
  if (k === dayKey(now() - DAY)) return 'Dünən';
  const d = new Date(ts * 1000), o = { day: 'numeric', month: 'long' };
  if (d.getFullYear() !== new Date().getFullYear()) o.year = 'numeric';
  return d.toLocaleDateString('az', o);
}
/** Sol paneldə qruplar: Bu gün, Dünən, Son 7 gün, Bu ay, Köhnə */
function ageGroup(ts) {
  const k = dayKey(ts);
  if (k === dayKey(now())) return 'Bu gün';
  if (k === dayKey(now() - DAY)) return 'Dünən';
  const d = now() - ts;
  return d < 7 * DAY ? 'Son 7 gün' : d < 30 * DAY ? 'Son 30 gün' : 'Köhnə';
}
let toastT = 0;
function toast(t) { let n = document.querySelector('.toast'); if (!n) { n = el('div', 'toast'); document.body.append(n); } n.textContent = t; n.hidden = false; clearTimeout(toastT); toastT = setTimeout(() => { n.hidden = true; }, 2200); }
async function copyText(t) { try { await navigator.clipboard.writeText(t); toast('⧉ Kopyalandı'); } catch (e) { toast('Kopyalanmadı'); } }
const stripHead = p => String(p).replace(/^\[[^\]]*\]\n/, '');

async function RMT(action, extra) {
  try {
    const r = await fetch('remote.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    if (r.status === 401) location.href = './';
    return { status: r.status, d: await r.json().catch(() => ({})) };
  } catch (e) { return { status: 0, d: {} }; }
}
async function CTL(action, extra) {
  try {
    const r = await fetch('control.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
    return { status: r.status, d: await r.json().catch(() => ({})) };
  } catch (e) { return { status: 0, d: {} }; }
}

/** Claude cavabı üçün yüngül markdown → DOM (index.php-dəki mdLite ilə eyni). HTML yazılmır: hər şey mətn düyünüdür */
function mdLite(src) {
  const root = el('div', 'md'), lines = String(src).replace(/\r/g, '').split('\n');
  const LI = /^(\s*)([-*•]|\d{1,3}[.)])\s+(.*)$/;
  const inline = (n, s) => {
    const re = /(`[^`\n]+`)|(\*\*[^*\n]+\*\*)/g; let last = 0, m;
    while ((m = re.exec(s))) {
      if (m.index > last) n.append(s.slice(last, m.index));
      n.append(m[1] ? el('code', null, m[1].slice(1, -1)) : el('b', null, m[2].slice(2, -2)));
      last = m.index + m[0].length;
    }
    if (last < s.length) n.append(s.slice(last));
  };
  let i = 0, para = [];
  const flush = () => { if (para.length) { const p = el('p'); inline(p, para.join('\n')); root.append(p); para = []; } };
  while (i < lines.length) {
    const l = lines[i];
    if (/^\s*```/.test(l)) { flush(); const buf = []; i++; while (i < lines.length && !/^\s*```/.test(lines[i])) buf.push(lines[i++]); i++; root.append(el('pre', null, buf.join('\n'))); continue; }
    if (/^\s*\|/.test(l)) { flush(); const buf = []; while (i < lines.length && /^\s*\|/.test(lines[i])) buf.push(lines[i++]); root.append(el('pre', null, buf.join('\n'))); continue; }
    if (/^#{1,6}\s/.test(l)) { flush(); const h = el('div', 'mdh'); inline(h, l.replace(/^#+\s*/, '')); root.append(h); i++; continue; }
    if (LI.test(l)) {
      flush(); const ul = el('div', 'mdl');
      while (i < lines.length) {
        const m = LI.exec(lines[i]); if (!m) break;
        const it = el('div', 'mdi'); it.style.marginLeft = Math.min(3, Math.floor(m[1].length / 2)) * 14 + 'px';
        it.append(el('span', 'mdb', /\d/.test(m[2]) ? m[2].replace(')', '.') : '•')); const t = el('span'); inline(t, m[3]); it.append(t); ul.append(it); i++;
      }
      root.append(ul); continue;
    }
    if (!l.trim()) { flush(); i++; continue; }
    para.push(l); i++;
  }
  flush();
  return root;
}
// Claude Code rejimləri (claude -p --permission-mode). Fon rejimində sual verən yoxdur: icazə istəyən addım rədd olunur
const PERMS = { acceptEdits: '✎ Accept edits', plan: '⏸ Plan', auto: '🤖 Auto', manual: '✋ Manual' };
const PERM_NOTE = {
  acceptEdits: 'faylları redaktə edir, terminal əmrləri məhduddur',
  plan: 'heç nəyi dəyişmir, yalnız plan yazır',
  auto: 'Claude əmrləri özü təhlükəsizliyə görə yoxlayıb icra edir',
  manual: 'yalnız oxuyur: icazə istəyən addımlar (redaktə də) rədd olunur',
};
const CMD_IMG_MAX = 4, CMD_IMG_BYTES = 5 * 1024 * 1024, CMD_IMG_TYPES = /^image\/(png|jpeg|gif|webp)$/;
function shrinkImage(f) {
  if (f.size <= 1.5 * 1024 * 1024 || f.type === 'image/gif') return Promise.resolve(f);
  return new Promise(res => {
    const img = new Image(), u = URL.createObjectURL(f);
    img.onload = () => {
      URL.revokeObjectURL(u);
      const k = Math.min(1, 2000 / Math.max(img.naturalWidth, img.naturalHeight));
      const c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      c.toBlob(b => res(b && b.size < f.size ? new File([b], f.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : f), 'image/jpeg', .9);
    };
    img.onerror = () => { URL.revokeObjectURL(u); res(f); };
    img.src = u;
  });
}
const ls = { get(k) { try { return localStorage.getItem(k) || ''; } catch (e) { return ''; } }, set(k, v) { try { v ? localStorage.setItem(k, v) : localStorage.removeItem(k); } catch (e) {} } };

/* ====== Vəziyyət ======
   target = kimin kompüteri; sel = { k: 'chat', id } | { k: 'new' } | { k: 'sess', id }. URL: #<ad>, #<ad>/<söhbət>, #<ad>/new, #<ad>/s/<sessiya> */
const ST = { pending: ['təsdiq gözləyir', 'waiting'], queued: ['növbədə', ''], running: ['işləyir', 'working'], done: ['bitdi', 'idle'], failed: ['xəta', 'waiting'], canceled: ['ləğv edildi', ''], sending: ['göndərilir', 'working'] };
const S = { me: '', comps: [], pending: [], target: '', tab: 'chats', chats: null, sessions: null, sel: null, msgs: [], sess: null, events: [],
  steps: new Map(), openSteps: new Set(), openRes: new Set(), q: '', imgs: [], tmp: null, last: '', unseen: false, at: {} };
const comp = () => S.comps.find(c => c.name === S.target) || null;
const isMine = () => S.target === S.me;
const canSend = () => { const c = comp(); return !!c && !c.me && !!c.grant; };
const canSee = () => { const c = comp(); return !!c && (c.me || !!c.grant); };
const curChat = () => S.sel && S.sel.k === 'chat' ? (S.chats || []).find(c => c.chat === S.sel.id) || null : null;

function parseHash() {
  const p = location.hash.slice(1).split('/').map(decodeURIComponent);
  const t = p[0] || '';
  let sel = null;
  if (p[1] === 'new') sel = { k: 'new' };
  else if (p[1] === 's' && p[2]) sel = { k: 'sess', id: p[2] };
  else if (+p[1] > 0) sel = { k: 'chat', id: +p[1] };
  return { t, sel };
}
function hashOf(t, sel) { return '#' + encodeURIComponent(t) + (!sel ? '' : sel.k === 'new' ? '/new' : sel.k === 'sess' ? '/s/' + encodeURIComponent(sel.id) : '/' + sel.id); }
function nav(t, sel, replace) {
  const h = hashOf(t, sel);
  if (location.hash === h) return applyHash();
  replace ? history.replaceState(null, '', h) : history.pushState(null, '', h);
  applyHash();
}
function applyHash() {
  const { t, sel } = parseHash();
  if (!t) return;
  const tChanged = t !== S.target;
  if (tChanged) { S.target = t; S.chats = null; S.sessions = null; ls.set('cc:target', t); }
  const same = !tChanged && JSON.stringify(sel) === JSON.stringify(S.sel);
  if (sel && sel.k === 'sess') S.tab = 'sessions';
  if (!same) {
    S.sel = sel; S.msgs = []; S.sess = null; S.events = []; S.unseen = false; S.scrollEnd = true; S.at.sel = 0; lastCount = 0;
    if (!(S.tmp && sel && sel.k === 'chat' && S.tmp.chat === sel.id)) S.tmp = null;    // yeni söhbət indicə yarandı: göndərilən balon qalsın
  }
  if (sel && sel.k === 'chat') ls.set('cc:chat:' + t, sel.id);
  closeSide();
  if (tChanged) S.at.list = 0;
  renderAll();
  tick();
  if (!same && !COARSE && sel && sel.k !== 'sess') $('ta').focus();
}

function renderAll() { renderSide(); renderTop(); renderComposer(); renderThread(); }   // yazı qutusu əvvəl: hero proyekt seçimini oradan oxuyur

/* ====== Yükləmə: hər saniyə nəyin vaxtı çatıb baxılır, tab gizlidirsə gözləyir ====== */
let busy = {};
async function once(key, fn) { if (busy[key]) return; busy[key] = true; try { await fn(); } finally { busy[key] = false; } }
const active = () => S.msgs.some(m => ['queued', 'running'].includes(m.status)) || !!S.tmp || (S.sess && S.sess.status === 'working');
function tick() {
  if (document.hidden) return;
  const t = Date.now();
  if (t - (S.at.ov || 0) > 5000) { S.at.ov = t; once('ov', loadOverview); }
  if (S.target && S.comps.length && t - (S.at.list || 0) > 6000) { S.at.list = t; once('list', loadList); }   // icazələr overview-dan gəlir: əvvəl o yüklənsin
  if (S.target && S.sel && S.sel.k !== 'new' && t - (S.at.sel || 0) > (active() ? 2500 : 10000)) { S.at.sel = t; once('sel', loadSel); }
  renderClock();
}
async function loadOverview() {
  const { status, d } = await RMT('overview', {});
  if (status !== 200) return;
  offset = d.now - Date.now() / 1000;
  S.me = d.me; S.comps = d.computers || []; S.pending = d.pending || [];
  if (!S.target || !S.comps.some(c => c.name === S.target)) {        // ilk açılış: yadda qalan, yoxsa icazə verilən ilk kompüter, yoxsa öz kompüterim
    const saved = ls.get('cc:target'), pick = S.comps.find(c => c.name === saved) || S.comps.find(c => !c.me && c.grant) || S.comps.find(c => c.me) || S.comps[0];
    if (pick) { nav(pick.name, null, true); return; }
  }
  renderSide(); renderTop(); renderComposer();
}
async function loadList() {
  const t = S.target;
  const [c, s] = await Promise.all([RMT('chats', { target: t }), canSee() || S.tab === 'sessions' ? RMT('sessions', { target: t }) : Promise.resolve(null)]);
  if (t !== S.target) return;
  if (c.status === 200) S.chats = c.d.chats || [];
  S.sessions = s && s.status === 200 ? s.d.sessions || [] : s ? { error: s.d.error || 'Alınmadı' } : null;
  const gone = S.sel && S.sel.k === 'chat' && c.status === 200 && !S.tmp && !S.chats.some(x => x.chat === S.sel.id);   // köhnə link / başqa kompüterin söhbəti
  if (gone) ls.set('cc:chat:' + t, '');
  if (!S.sel || gone) {                                              // söhbət seçilməyib: yadda qalan, yoxsa ən son söhbət, yoxsa yeni
    const saved = +ls.get('cc:chat:' + t), ch = S.chats || [];
    const pick = ch.find(x => x.chat === saved) || ch[0];
    nav(t, pick ? { k: 'chat', id: pick.chat } : canSend() ? { k: 'new' } : null, true);
    return;
  }
  renderSide(); renderTop(); renderComposer();
}
async function loadSel() {
  const t = S.target, sel = S.sel;
  if (!sel) return;
  if (sel.k === 'chat') {
    const { status, d } = await RMT('chat', { target: t, chat: sel.id });
    if (t !== S.target || S.sel !== sel || status !== 200) return;
    S.msgs = d.messages || [];
    if (S.tmp && S.msgs.some(m => m.creator === S.me && stripHead(m.prompt) === S.tmp.prompt && m.created_at >= S.tmp.created_at - 3)) S.tmp = null;
    const run = S.msgs.filter(m => m.status === 'running' || (S.openSteps.has(+m.id) && !S.steps.has(+m.id)));
    await Promise.all(run.map(m => loadSteps(m)));
    if (!S.last) { const lm = S.msgs.filter(m => m.creator === S.me).pop(); if (lm) S.last = stripHead(lm.prompt); }
  } else if (sel.k === 'sess') {
    const { status, d } = await RMT('session', { target: t, session_id: sel.id });
    if (t !== S.target || S.sel !== sel) return;
    if (status === 200) { S.sess = d.session; S.events = d.events || []; } else { S.sess = { error: d.error || 'Alınmadı' }; S.events = []; }
  }
  renderThread(); renderTop(); renderComposer();
}
async function loadSteps(m) {
  const { status, d } = await RMT('steps', { target: S.target, id: m.id });
  if (status === 200) S.steps.set(+m.id, d.steps || []);
}

/* ====== Sol panel ====== */
function compState(c) {
  if (!c.agent) return ['', 'agent qoşulmayıb'];
  if (c.pending && c.me) return ['waiting', c.pending + ' təsdiq gözləyir'];
  if (c.running) return ['working', 'Claude işləyir'];
  return c.online ? ['idle', c.login ? 'onlayn' : 'onlayn · Claude girişi yoxdur'] : ['', 'oflayn · ' + ago(c.last_seen)];
}
const accessTxt = c => c.me ? 'sənin kompüterin' : c.grant === 'auto' ? '⚡ icazə: avtomatik' : c.grant ? '✋ icazə: təsdiqlə' : '🔒 icazə yoxdur';
let sideSig = '';
function renderSide() {
  $('mename').textContent = S.me; $('meav').textContent = (S.me || '?')[0]; $('meav').style.background = `hsl(${hue(S.me || '')} 55% 48%)`;
  const c = comp();
  const btn = $('pcbtn');
  if (c) { const [dc, sub] = compState(c); btn.children[0].className = 'dot ' + dc; btn.children[1].textContent = '🖥 ' + c.name + (c.me ? ' (sən)' : ''); btn.children[2].textContent = sub; }
  $('newchat').disabled = !canSend();
  $('newchat').title = canSend() ? 'Təzə söhbət: Claude sıfırdan başlayır' : isMine() ? 'Öz kompüterinə buradan göndərilmir: öz terminalında Claude Code işlət' : 'Bu kompüterə icazən yoxdur';
  // Sahib üçün: təsdiq gözləyən komandalar
  const pb = $('pendbox');
  pb.hidden = !S.pending.length;
  const pSig = S.pending.map(p => p.id).join();
  if (S.pending.length && pb._sig !== pSig) {                          // yalnız dəyişəndə (hər saniyə yenidən qurulsa klik itə bilər)
    pb._sig = pSig;
    const p0 = S.pending[0];
    pb.replaceChildren(el('span', null, '⏳ ' + S.pending.length + ' komanda sənin təsdiqini gözləyir · '));
    const b = el('button', null, 'bax'); b.type = 'button'; b.onclick = () => { S.tab = 'chats'; nav(S.me, p0.chat ? { k: 'chat', id: +p0.chat } : null); }; pb.append(b);
  }
  for (const b of document.querySelectorAll('.stabs button')) b.classList.toggle('on', b.dataset.t === S.tab);
  const sig = [S.target, S.tab, S.q, JSON.stringify(S.sel), JSON.stringify(S.chats), JSON.stringify(S.sessions), Math.floor(now() / 60)].join('|');
  if (sig === sideSig) return; sideSig = sig;
  const box = $('slist'), q = S.q.trim().toLowerCase();
  box.replaceChildren();
  if (S.tab === 'chats') {
    if (S.chats === null) { box.append(el('div', 'sempty', 'Yüklənir…')); return; }
    if (S.sel && S.sel.k === 'new') { const n = el('button', 'si on'); n.append(el('span', 'dot working'), el('span', 't', '✎ Yeni söhbət'), el('span', 'm', 'ilk mesajı yaz')); box.append(n); }
    const list = S.chats.filter(x => !q || (x.title + ' ' + x.project + ' ' + x.creator).toLowerCase().includes(q));
    if (!list.length) box.append(el('div', 'sempty', q ? 'Tapılmadı' : isMine() ? 'Hələ heç kim sənin kompüterinə komanda göndərməyib.' : 'Hələ söhbət yoxdur. Yaz, göndər: ilk söhbət burada görünəcək.'));
    let g = '';
    for (const x of list) {
      const gg = ageGroup(x.last_at); if (gg !== g) { g = gg; box.append(el('div', 'sg', g)); }
      const on = S.sel && S.sel.k === 'chat' && S.sel.id === x.chat;
      const b = el('button', 'si' + (on ? ' on' : '')); b.type = 'button';
      const [lbl, dc] = ST[x.status] || [x.status, ''];
      b.append(el('span', 'dot ' + dc), el('span', 't', x.title || '…'), el('span', 'm', (isMine() ? x.creator + ' · ' : '') + x.project + ' · ' + x.n + ' mesaj · ' + (['done', 'canceled'].includes(x.status) ? ago(x.last_at) : lbl)));
      b.title = x.title + '\n🪐 ' + x.project + ' · ' + x.n + ' mesaj · ' + ago(x.last_at);
      b.onclick = () => nav(S.target, { k: 'chat', id: x.chat });
      box.append(b);
      if (on) requestAnimationFrame(() => { if (b.offsetTop < box.scrollTop || b.offsetTop > box.scrollTop + box.clientHeight - 40) b.scrollIntoView({ block: 'nearest' }); });
    }
  } else {
    if (S.sessions === null) { box.append(el('div', 'sempty', canSee() ? 'Yüklənir…' : '🔒 ' + S.target + '-in sessiyalarına yalnız icazə verdiyi adamlar baxa bilər.')); return; }
    if (S.sessions.error) { box.append(el('div', 'sempty', '🔒 ' + S.sessions.error)); return; }
    const list = S.sessions.filter(x => !q || (x.project + ' ' + x.last_prompt + ' ' + x.last_summary).toLowerCase().includes(q));
    if (!list.length) box.append(el('div', 'sempty', q ? 'Tapılmadı' : 'Son 30 gündə Claude Code sessiyası yoxdur (hook quraşdırılmalıdır).'));
    let g = '';
    for (const x of list) {
      const gg = ageGroup(x.updated_at); if (gg !== g) { g = gg; box.append(el('div', 'sg', g)); }
      const on = S.sel && S.sel.k === 'sess' && S.sel.id === x.session_id;
      const b = el('button', 'si' + (on ? ' on' : '')); b.type = 'button';
      const dc = x.status === 'working' ? 'working' : x.status === 'waiting' ? 'waiting' : x.status === 'idle' && now() - x.updated_at < 1800 ? 'idle' : '';
      b.append(el('span', 'dot ' + dc), el('span', 't', x.last_prompt || x.last_summary || 'Sessiya'), el('span', 'm', '🪐 ' + x.project + ' · ' + x.tool_count + ' alət · ' + x.edits + ' dəyişiklik · ' + ago(x.updated_at)));
      b.onclick = () => nav(S.target, { k: 'sess', id: x.session_id });
      box.append(b);
    }
  }
}
function drawPcMenu() {
  const m = $('pcmenu'); m.replaceChildren();
  for (const c of S.comps) {
    const [dc, sub] = compState(c);
    const b = el('button', 'pci' + (c.name === S.target ? ' on' : '')); b.type = 'button'; b.setAttribute('role', 'option');
    b.append(el('span', 'dot ' + dc), el('b', null, '🖥 ' + c.name + (c.me ? ' (sən)' : '')), el('span', 'lk', c.me ? '' : c.grant ? (c.grant === 'auto' ? '⚡' : '✋') : '🔒'), el('span', 'ps', sub + ' · ' + accessTxt(c)));
    b.onclick = () => { m.hidden = true; if (c.name !== S.target) { const saved = +ls.get('cc:chat:' + c.name); nav(c.name, saved ? { k: 'chat', id: saved } : null); } };
    m.append(b);
  }
}

/* ====== Yuxarı zolaq ====== */
function renderTop() {
  const c = comp(), t = $('title'), cc = curChat();
  let head = 'Claude Code', sub = '';
  if (S.sel && S.sel.k === 'sess') { head = S.sess && S.sess.last_prompt ? S.sess.last_prompt.slice(0, 90) : 'Sessiya'; sub = '⌨ ' + S.target + '-in sessiyası' + (S.sess && S.sess.project ? ' · 🪐 ' + S.sess.project : ''); }
  else if (cc) { head = cc.title || 'Söhbət'; sub = '🖥 ' + S.target + ' · 🪐 ' + cc.project + (isMine() ? ' · ' + cc.creator : ''); }
  else if (S.sel && S.sel.k === 'new') { head = 'Yeni söhbət'; sub = '🖥 ' + S.target; }
  else if (c) { head = '🖥 ' + c.name; sub = accessTxt(c); }
  t.children[0].textContent = head; t.children[1].textContent = sub;
  document.title = (head === 'Claude Code' ? '' : head + ' · ') + 'Claude Code';
  const p = $('tpills'); p.replaceChildren();
  if (!c) return;
  const [dc, txt] = compState(c);
  const st = el('span', 'pill main'); st.append(el('span', 'dot ' + dc), el('span', null, txt)); p.append(st);
  if (!c.me) p.append(el('span', 'pill ' + (c.grant === 'auto' ? 'ok' : c.grant ? 'warn' : 'no'), c.grant === 'auto' ? '⚡ avtomatik' : c.grant ? '✋ hər komanda təsdiqlənir' : '🔒 icazə yoxdur'));
  else p.append(el('span', 'pill', c.granted.length ? '🔐 ' + c.granted.map(x => (x.grantee === '*' ? 'hamı' : x.grantee) + (x.mode === 'auto' ? ' ⚡' : ' ✋')).join(', ') : '🔒 heç kimə icazə yoxdur'));
}

/* ====== Söhbət axını ====== */
const nearBottom = () => { const s = $('scroll'); return s.scrollHeight - s.scrollTop - s.clientHeight < 140; };
function toEnd(smooth) { const s = $('scroll'); s.scrollTo({ top: s.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }); }
function stepRow(e) {
  const r = el('div', 'step' + (['Edit', 'Write', 'MultiEdit', 'NotebookEdit'].includes(e.tool) ? ' e' : ''));
  const tn = e.tool.startsWith('mcp__') ? '🛰 ' + e.tool.split('__').slice(1).join('·') : e.tool;
  r.append(el('span', 'tn', '⏺ ' + tn), el('span', 'ts', e.summary || ''), el('span', 'tw', hm(e.created_at)));
  r.title = e.tool + '\n' + (e.summary || '');
  return r;
}
function stepsBox(list, live) {
  const box = el('div', 'steps'), max = live ? 8 : 60, from = Math.max(0, list.length - max);
  if (from) box.append(el('div', 'step more', '… əvvəlki ' + from + ' addım'));
  for (const e of list.slice(from)) box.append(stepRow(e));
  return box;
}
/** Bir komanda: istifadəçi mesajı (sağda) və Claude-un vəziyyəti, addımları və cavabı (solda, ● ilə) */
function message(m, latest, hidePrj) {
  const w = el('div', 'msg'), id = +m.id, text = stripHead(m.prompt);
  const u = el('div', 'u'), ut = el('div', 'ut', text); u.append(ut);
  const long = text.length > 600 || text.split('\n').length > 9;
  if (long && !S.openRes.has('p' + id)) ut.classList.add('clamp');
  if (m.images && m.images.length) { const ib = el('div', 'imgs'); for (const f of m.images) { const a = el('a'); a.href = 'cmdimg.php?f=' + encodeURIComponent(f); a.target = '_blank'; a.rel = 'noopener'; const im = el('img'); im.src = a.href; im.alt = 'şəkil'; im.loading = 'lazy'; a.append(im); ib.append(a); } u.append(ib); }
  const um = el('div', 'um');
  if (long) { const b = el('button', 'lb', ut.classList.contains('clamp') ? '▾ Tam göstər' : '▴ Yığ'); b.type = 'button'; b.onclick = () => { S.openRes.has('p' + id) ? S.openRes.delete('p' + id) : S.openRes.add('p' + id); redraw(); }; um.append(b); }
  if (canSend() && m.status !== 'sending') { const b = el('button', 'lb', '↻'); b.type = 'button'; b.title = 'Yazı qutusuna qoy: düzəldib yenidən göndər'; b.onclick = () => fill(text); um.append(b); }
  if (isMine()) um.append(el('b', null, m.creator), el('span', null, '·'));
  if (!hidePrj) um.append(el('span', null, '🪐 ' + m.project), el('span', null, '·'));
  if (m.perm && m.perm !== 'acceptEdits') um.append(el('span', null, PERMS[m.perm] || m.perm), el('span', null, '·'));
  const tm = el('span', null, hm(m.created_at)); tm.title = new Date(m.created_at * 1000).toLocaleString('az'); um.append(tm);
  u.append(um); w.append(u);

  const a = el('div', 'a'), ab = el('div', 'ab'), c = comp();
  a.append(el('div', 'ic', '●'), ab);
  const [, dc] = ST[m.status] || ['', ''];
  const st = el('div', 'st'), stt = el('span', null,
    m.status === 'sending' ? 'Göndərilir…' :
    m.status === 'pending' ? (isMine() ? 'Sənin təsdiqini gözləyir' : S.target + '-in təsdiqini gözləyir') :
    m.status === 'queued' ? (c && c.online ? 'Növbədə, indi başlayır…' : 'Növbədə · kompüter oflayndır, onlayn olanda başlayacaq') :
    m.status === 'running' ? 'Claude işləyir' :
    m.status === 'done' ? 'Bitdi' + (m.started_at && m.finished_at ? ' · ' + fmtDur(m.finished_at - m.started_at) : '') :
    m.status === 'failed' ? 'Xəta' + (m.finished_at ? ' · ' + ago(m.finished_at) : '') :
    'Ləğv edildi' + (m.result ? ' · ' + m.result : ''));
  st.append(el('span', 'dot ' + dc), stt);
  if (m.status === 'running') { const el2 = el('span', 'mono', fmtDur(now() - m.started_at)); el2.dataset.since = m.started_at; st.append(el('span', null, '·'), el2); const ty = el('span', 'typing'); ty.append(el('i'), el('i'), el('i')); st.append(ty); }
  ab.append(st);
  const acts = el('div', 'acts'), steps = S.steps.get(id);
  if (m.status === 'running') { if (steps && steps.length) ab.append(stepsBox(steps, true)); }
  else if (m.started_at && S.openSteps.has(id)) ab.append(steps ? (steps.length ? stepsBox(steps, false) : el('div', 'st', 'Addım qeydə alınmayıb (hook yoxdur?)')) : el('div', 'st', 'Yüklənir…'));
  if (m.result && m.status !== 'canceled') {
    const res = el('div', 'res' + (m.status === 'failed' ? ' err' : '')); res.append(mdLite(m.result));
    const longR = m.result.length > 1600 || m.result.split('\n').length > 24, key = 'r' + id, open = latest ? !S.openRes.has('c' + key) : S.openRes.has(key);
    if (longR && !open) res.classList.add('fold');
    ab.append(res);
    if (longR) { const b = el('button', 'lb', open ? '▴ Yığ' : '▾ Tam göstər'); b.type = 'button'; b.onclick = () => { const k = latest ? 'c' + key : key; S.openRes.has(k) ? S.openRes.delete(k) : S.openRes.add(k); redraw(); }; acts.append(b); }
    const cp = el('button', 'lb', '⧉ Kopyala'); cp.type = 'button'; cp.onclick = () => copyText(m.result); acts.append(cp);
  }
  if (m.started_at && m.status !== 'running') {
    const sb = el('button', 'lb', S.openSteps.has(id) ? '▴ Addımları gizlət' : '⚙ Addımlar'); sb.type = 'button';
    sb.onclick = async () => { if (S.openSteps.has(id)) S.openSteps.delete(id); else { S.openSteps.add(id); if (!S.steps.has(id)) { redraw(); await loadSteps(m); } } redraw(); };
    acts.append(sb);
  }
  if (m.status === 'pending' && isMine()) {
    const ok = el('button', 'lb go', '✓ Təsdiq et'), no = el('button', 'lb danger', '✕ Rədd et'); ok.type = no.type = 'button';
    ok.onclick = async () => { ok.disabled = no.disabled = true; await RMT('approve', { id }); refresh(); };
    no.onclick = async () => { ok.disabled = no.disabled = true; await RMT('deny', { id }); refresh(); };
    acts.append(ok, no);
  }
  if (['pending', 'queued', 'running'].includes(m.status) && (isMine() || m.creator === S.me)) {
    const b = el('button', 'lb danger', m.status === 'running' ? '■ Dayandır' : '✕ Ləğv et'); b.type = 'button';
    b.onclick = () => stopCmd(id, b); acts.append(b);
  }
  if (acts.children.length) ab.append(acts);
  w.append(a);
  return w;
}
async function stopCmd(id, b) { if (b) b.disabled = true; const { status, d } = await CTL('stop_command', { id }); if (status !== 200) toast(d.error || 'Alınmadı'); refresh(); }
function refresh() { S.at.sel = 0; S.at.list = 0; S.at.ov = 0; tick(); }

/** Hook sessiyası: istifadəçi promptları, alət addımları, cavab xülasələri (oxumaq üçün, yazmaq yoxdur) */
function sessionView(box) {
  const s = S.sess;
  if (!s) { box.append(el('div', 'hero', 'Yüklənir…')); return; }
  if (s.error) { box.append(el('div', 'hero', '🔒 ' + s.error)); return; }
  const card = el('div', 'scard');
  const dc = s.status === 'working' ? 'working' : s.status === 'waiting' ? 'waiting' : s.status === 'idle' ? 'idle' : '';
  const h = el('div', 'st'); h.append(el('span', 'dot ' + dc), el('b', null, '⌨ Claude Code sessiyası · 🪐 ' + s.project)); card.append(h);
  const row = el('div', 'row');
  row.append(el('span', null, { working: '⚙ işləyir', waiting: '✋ cavab gözləyir', idle: '💤 boşdur', ended: '⏹ bağlanıb' }[s.status] || s.status), el('span', null, s.tool_count + ' alət'), el('span', null, s.edits + ' dəyişiklik'),
    el('span', null, 'başladı ' + hm(s.started_at)), el('span', null, 'son ' + ago(s.updated_at)));
  if (s.cwd) row.append(el('span', 'mono', s.cwd));
  card.append(row, el('div', 'note', 'Bu, ' + S.target + '-in öz terminalındakı Claude Code sessiyasıdır (hook-lardan). Ona buradan yazmaq olmur: davam etmək üçün yeni söhbət aç.'));
  box.append(card);
  let day = '', run = [];
  const flushRun = () => { if (run.length) { const a = el('div', 'a'), ab = el('div', 'ab'); a.append(el('div', 'ic', '⏺'), ab); ab.append(stepsBox(run, false)); box.append(a); run = []; } };
  for (const e of S.events) {
    const k = dayKey(e.created_at); if (k !== day) { flushRun(); day = k; box.append(el('div', 'day', dayLabel(e.created_at))); }
    if (e.event === 'PostToolUse' && e.tool) { run.push(e); continue; }
    flushRun();
    if (e.event === 'UserPromptSubmit') {
      const u = el('div', 'u'); u.style.marginTop = '18px'; u.append(el('div', 'ut', e.summary.replace(/^Tapşırıq:\s*/, '')));
      const um = el('div', 'um'); um.append(el('span', null, hm(e.created_at))); u.append(um); box.append(u);
    } else if (e.event === 'Stop') {
      const a = el('div', 'a'), ab = el('div', 'ab'); a.append(el('div', 'ic', '●'), ab);
      const txt = e.summary.replace(/^Bitdi:\s*/, '');
      ab.append(txt === 'Cavab tamamlandı' ? el('div', 'st', '✓ Cavab tamamlandı · ' + hm(e.created_at)) : mdLite(txt));
      box.append(a);
    } else box.append(el('div', 'ev', (e.event === 'Notification' ? '✋ ' : '') + e.summary + ' · ' + hm(e.created_at)));
  }
  flushRun();
  if (!S.events.length) box.append(el('div', 'ev', 'Hadisə yoxdur'));
}
let threadSig = '', lastCount = 0;
const seenMsg = new Set();
function redraw() { threadSig = ''; renderThread(); }
function renderThread() {
  const box = $('thread'), sel = S.sel;
  let list = [];
  if (sel && sel.k === 'chat') { list = S.msgs.slice(); if (S.tmp && S.tmp.chat === sel.id) list.push(S.tmp); }
  else if (sel && sel.k === 'new' && S.tmp) list = [S.tmp];
  const cc = curChat();
  const sig = JSON.stringify([S.target, sel, list.map(m => [m.id, m.status, (m.result || '').length, (S.steps.get(+m.id) || []).length]), [...S.openSteps], [...S.openRes],
    sel && sel.k === 'sess' ? [S.sess && S.sess.updated_at, S.events.length, S.sess && S.sess.error] : 0, S.chats === null, !!comp(), canSend(), Math.floor(now() / 60)]);
  if (sig === threadSig) return;
  const wasNear = nearBottom(), grew = list.length !== lastCount || (S.events.length && sel && sel.k === 'sess'), firstFill = !lastCount;
  threadSig = sig; lastCount = list.length;
  const keep = $('scroll').scrollTop;
  box.replaceChildren();
  if (sel && sel.k === 'sess') sessionView(box);
  else if (!list.length) box.append(hero());
  else {
    const hidePrj = !!cc && list.every(m => m.project === cc.project);
    let day = '';
    list.forEach((m, i) => {
      const k = dayKey(m.created_at); if (k !== day) { day = k; box.append(el('div', 'day', dayLabel(m.created_at))); }
      const n = message(m, i === list.length - 1, hidePrj);
      if (!firstFill && !seenMsg.has(m.id)) n.classList.add('enter');            // yalnız yeni gələn mesaj canlanır, yenidən çəkiləndə yox
      seenMsg.add(m.id); box.append(n);
    });
  }
  if (S.scrollEnd) { S.scrollEnd = false; requestAnimationFrame(() => toEnd(false)); }
  else if (wasNear) requestAnimationFrame(() => toEnd(grew && !firstFill));
  else { $('scroll').scrollTop = keep; if (grew) S.unseen = true; }
  drawDown();
}
function hero() {
  const h = el('div', 'hero'), c = comp();
  h.append(el('div', 'logo', 'C'));
  if (!c) { h.append(el('h1', null, 'Claude Code'), el('p', null, S.comps.length ? 'Soldan kompüter seç.' : 'Yüklənir…')); return h; }
  if (S.chats === null && S.sel && S.sel.k === 'chat') { h.append(el('p', null, 'Yüklənir…')); return h; }
  if (c.me) { h.append(el('h1', null, 'Sənin kompüterin'), el('p', null, 'Burada başqalarının sənin kompüterinə göndərdiyi söhbətlər görünür. Soldakı "⌨ Sessiyalar"da öz Claude Code sessiyaların var. İcazələri Desktop Universe-də idarə edirsən.')); return h; }
  if (!c.grant) { h.append(el('h1', null, '🔒 ' + c.name), el('p', null, c.name + ' hələ sənə icazə verməyib. Onun kompüterinə komanda göndərmək üçün Desktop Universe-də ' + c.name + '-in planetində sənə "+ İcazə ver" etməlidir.')); return h; }
  h.append(el('h1', null, 'Nə edək?'), el('p', null, c.name + '-in kompüterində, onun Claude Code-u ilə. ' + (c.grant === 'auto' ? 'Komanda dərhal işə düşür.' : c.name + ' hər komandanı təsdiqləyir.') + ' Yeni söhbət: Claude sıfırdan başlayır.'));
  const prjs = c.projects || [];
  if (prjs.length) {
    const chips = el('div', 'chips');
    for (const p of prjs) { const b = el('button', p === $('prj').value ? 'on' : null, '🪐 ' + p); b.type = 'button'; b.onclick = () => { $('prj').value = p; ls.set('cc:prj:' + S.target, p); for (const x of chips.children) x.classList.toggle('on', x === b); $('ta').focus(); updSend(); }; chips.append(b); }
    h.append(chips);
  }
  return h;
}
function drawDown() { const d = $('down'); const show = !nearBottom(); d.hidden = !show; d.classList.toggle('new', S.unseen); d.textContent = S.unseen ? '↓ Yeni cavab' : '↓ Sona'; }
function renderClock() { for (const n of document.querySelectorAll('[data-since]')) n.textContent = fmtDur(now() - +n.dataset.since); renderSide(); renderThread(); }

/* ====== Yazı qutusu ====== */
const ta = $('ta');
let compSig = '';
function renderComposer() {
  const c = comp(), sess = S.sel && S.sel.k === 'sess', cc = curChat();
  $('compbox').hidden = !c || !canSend() || sess;
  const lock = $('lock'), note = $('cnote');
  lock.hidden = true; note.className = 'cnote'; note.textContent = '';
  if (c && sess) { lock.hidden = false; lock.replaceChildren(el('span', null, '⌨ Sessiya yalnız oxumaq üçündür. ')); if (canSend()) { const a = el('a', null, 'Yeni söhbət aç'); a.href = hashOf(S.target, { k: 'new' }); lock.append(a); } }
  else if (c && c.me) { lock.hidden = false; lock.textContent = 'Sənin kompüterin: buradan özünə komanda göndərilmir. Gələn komandaları təsdiq et, sessiyalarına bax.'; }
  else if (c && !c.grant) { lock.hidden = false; lock.replaceChildren(el('span', null, '🔒 ' + c.name + ' sənə icazə verməyib. ')); const a = el('a', null, 'Desktop Universe-də icazə istə'); a.href = './#kainat/desktop%3A' + encodeURIComponent(c.name); lock.append(a); }
  if (!c || !canSend() || sess) return;
  // proyekt: açıq söhbət bir proyektə bağlıdır; yeni söhbətdə seçilir
  const prjs = c.projects || [], lockPrj = cc ? cc.project : '';
  const sig = [S.target, prjs.join(), lockPrj, JSON.stringify(S.sel)].join('|');
  if (sig !== compSig) {
    compSig = sig;
    const sel = $('prj'); sel.replaceChildren();
    if (lockPrj) { sel.append(new Option('🪐 ' + lockPrj, lockPrj)); sel.disabled = true; sel.title = 'Söhbət bu proyektdə davam edir. Başqa proyekt üçün yeni söhbət aç'; }
    else {
      sel.disabled = false; sel.title = 'Komanda bu proyektin qovluğunda işləyəcək';
      if (!prjs.length) sel.append(new Option('proyekt yoxdur', ''));
      for (const p of prjs) sel.append(new Option('🪐 ' + p, p));
      const saved = ls.get('cc:prj:' + S.target); if (prjs.includes(saved)) sel.value = saved;
    }
    ta.placeholder = cc ? 'Cavab yaz…' : c.name + '-in kompüterində Claude Code nə etsin?';
    if (ta._t !== S.target) { ta._t = S.target; ta.value = ls.get('cc:draft:' + S.target); grow(); }   // hər kompüterin öz yarımçıq yazısı
    $('perm').value = PERMS[ls.get('cc:perm:' + S.target)] ? ls.get('cc:perm:' + S.target) : 'acceptEdits';
  }
  if (!prjs.length && !lockPrj) { note.className = 'cnote warn'; note.textContent = c.name + ' hələ heç bir proyekt qurmayıb: onun kompüterində hook ilə Claude Code işlənməlidir.'; }
  else if (!c.online) { note.className = 'cnote warn'; note.textContent = '⚠ Kompüter oflayndır: komanda növbəyə düşür, onlayn olanda işləyəcək.'; }
  else if (!c.login) { note.className = 'cnote warn'; note.textContent = '⚠ ' + c.name + '-in kompüterində Claude girişi yoxdur: komandalar növbədə gözləyəcək.'; }
  else note.textContent = (c.grant === 'auto' ? '⚡ Dərhal işə düşür' : '✋ ' + c.name + ' təsdiqləyəndən sonra işə düşür') + ' · ' + c.name + '-in Claude hesabı ilə · ' + PERMS[$('perm').value] + ': ' + PERM_NOTE[$('perm').value];
  $('perm').title = PERMS[$('perm').value] + ': ' + PERM_NOTE[$('perm').value];
  updSend();
}
const lastActive = () => { const l = S.msgs.filter(m => ['pending', 'queued', 'running'].includes(m.status) && m.creator === S.me).pop(); return l || null; };
function updSend() {
  const b = $('send'), act = lastActive(), empty = !ta.value.trim();
  if (act && empty && !S.tmp) { b.className = 'send stop'; b.textContent = '■'; b.title = act.status === 'running' ? 'Dayandır' : 'Ləğv et'; b.disabled = false; return; }
  b.className = 'send'; b.textContent = '↑'; b.title = 'Göndər (Enter)';
  b.disabled = empty || !$('prj').value || !!S.tmp;
  const left = ta.maxLength - ta.value.length, h = $('hint');
  h.classList.toggle('lim', left < 300);
  h.textContent = left < 300 ? left + ' simvol qalıb' : COARSE ? '' : 'Enter göndərir · Shift+Enter yeni sətir';
}
function grow() { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, innerHeight * .4) + 'px'; }
function fill(t) { ta.value = t; grow(); updSend(); ls.set('cc:draft:' + S.target, t); ta.focus(); ta.setSelectionRange(t.length, t.length); }
function cerr(t) { $('cerr').hidden = !t; $('cerr').textContent = t || ''; }
function drawImgs() {
  const box = $('imgs'); box.hidden = !S.imgs.length;
  box.replaceChildren(...S.imgs.map(im => { const d = el('div', 'ci'), i = el('img'); i.src = im.url; i.alt = im.file.name; const x = el('button', 'rm', '✕'); x.type = 'button'; x.title = 'Şəkli sil'; x.onclick = () => { URL.revokeObjectURL(im.url); S.imgs.splice(S.imgs.indexOf(im), 1); drawImgs(); }; d.append(i, x); return d; }));
}
async function addImgs(files) {
  cerr('');
  for (let f of files) {
    if (!CMD_IMG_TYPES.test(f.type)) { cerr('Yalnız PNG, JPEG, GIF və ya WebP şəkil'); continue; }
    if (S.imgs.length >= CMD_IMG_MAX) { cerr('Ən çox ' + CMD_IMG_MAX + ' şəkil'); break; }
    f = await shrinkImage(f);
    if (f.size > CMD_IMG_BYTES) { cerr('Şəkil çox böyükdür (ən çox 5 MB)'); continue; }
    S.imgs.push({ file: f, url: URL.createObjectURL(f) });
  }
  drawImgs();
}
async function send() {
  const act = lastActive();
  if (act && !ta.value.trim()) return stopCmd(+act.id);
  const c = comp(), prj = $('prj').value, text = ta.value;
  if (!c || !canSend() || S.tmp) return;
  if (!prj) { cerr('Əvvəl proyekt seç'); return; }
  if (!text.trim()) { ta.focus(); return; }
  cerr('');
  const t = S.target, isNew = !S.sel || S.sel.k === 'new', chat = S.sel && S.sel.k === 'chat' ? S.sel.id : 0, imgs = S.imgs.slice(), perm = $('perm').value;
  S.tmp = { id: 9e15, chat: chat || undefined, prompt: text, project: prj, status: 'sending', created_at: now(), creator: S.me, images: [], perm };   // dərhal balon kimi görünsün
  ta.value = ''; grow(); S.imgs = []; drawImgs(); ls.set('cc:draft:' + t, ''); S.scrollEnd = true;
  redraw(); updSend();
  let res;
  try {
    if (imgs.length) {                                            // şəkilli: FormData
      const fd = new FormData(); fd.append('action', 'send'); fd.append('target', t); fd.append('project', prj); fd.append('prompt', text); fd.append('fresh', isNew ? '1' : ''); fd.append('chat', chat); fd.append('perm', perm);
      for (const im of imgs) fd.append('images[]', im.file, im.file.name);
      const r = await fetch('remote.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
      if (r.status === 401) location.href = './';
      res = { status: r.status, d: await r.json().catch(() => ({})) };
    } else res = await RMT('send', { target: t, project: prj, prompt: text, fresh: isNew, chat, perm });
  } catch (e) { res = { status: 0, d: {} }; }
  const { status, d } = res;
  if (status === 200) {
    for (const im of imgs) URL.revokeObjectURL(im.url);
    S.last = text; ls.set('cc:prj:' + t, prj);
    if (d.status === 'pending') toast('✋ Göndərildi, ' + t + '-in təsdiqini gözləyir');
    if (S.tmp) Object.assign(S.tmp, { chat: +d.chat, status: d.status || 'queued' });
    if (isNew && d.chat) nav(t, { k: 'chat', id: +d.chat }, true);
    else redraw();
    refresh();
  } else {                                                          // alınmadı: mətn və şəkillər qutuya qayıdır
    S.tmp = null; fill(text); S.imgs.push(...imgs); drawImgs(); redraw();
    cerr(status === 0 ? 'Bağlantı xətası' : (d.error || 'Göndərilmədi'));
  }
  updSend();
}

/* ====== Hadisələr ====== */
function newChat() { if (canSend()) nav(S.target, { k: 'new' }); }
function closeSide() { $('app').classList.remove('open'); }
$('newchat').onclick = newChat;
$('nkbd').textContent = MAC ? '⌘⇧O' : 'Ctrl⇧O';
$('menu').onclick = () => $('app').classList.add('open');
$('scrim').onclick = closeSide;
$('pcbtn').onclick = e => { e.stopPropagation(); const m = $('pcmenu'); if (m.hidden) drawPcMenu(); m.hidden = !m.hidden; };
document.addEventListener('click', e => { if (!$('pcmenu').hidden && !e.target.closest('.pc')) $('pcmenu').hidden = true; });
for (const b of document.querySelectorAll('.stabs button')) b.onclick = () => { S.tab = b.dataset.t; if (S.tab === 'sessions' && S.sessions === null) S.at.list = 0; sideSig = ''; renderSide(); tick(); };
$('q').oninput = () => { S.q = $('q').value; renderSide(); };
$('scroll').addEventListener('scroll', () => { if (nearBottom()) S.unseen = false; drawDown(); }, { passive: true });
$('down').onclick = () => { S.unseen = false; toEnd(true); };
$('send').onclick = send;
$('att').onclick = () => $('file').click();
$('file').onchange = () => { addImgs([...$('file').files]); $('file').value = ''; };
$('perm').onchange = () => { ls.set('cc:perm:' + S.target, $('perm').value); renderComposer(); };
$('prj').onchange = () => { ls.set('cc:prj:' + S.target, $('prj').value); updSend(); if (S.sel && S.sel.k === 'new') redraw(); };
ta.addEventListener('input', () => { grow(); updSend(); ls.set('cc:draft:' + S.target, ta.value); });
ta.addEventListener('keydown', e => {
  if (e.key === 'Enter' && !e.isComposing && ((e.metaKey || e.ctrlKey) || (!e.shiftKey && !COARSE))) { e.preventDefault(); send(); return; }   // Enter göndərir (telefonda yox), Cmd/Ctrl+Enter həmişə
  if (e.key === 'ArrowUp' && !ta.value && S.last) { e.preventDefault(); fill(S.last); }                                                      // ↑ boş qutuda: son göndərdiyin
});
ta.addEventListener('paste', e => { const fs = [...((e.clipboardData && e.clipboardData.items) || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean); if (fs.length) { e.preventDefault(); addImgs(fs); } });
const cbox = $('comp');
cbox.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); cbox.classList.add('drop'); } });
cbox.addEventListener('dragleave', () => cbox.classList.remove('drop'));
cbox.addEventListener('drop', e => { e.preventDefault(); cbox.classList.remove('drop'); addImgs([...e.dataTransfer.files]); });
document.addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key.toLowerCase() === 'o') { e.preventDefault(); newChat(); }            // yeni söhbət
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('app').classList.add('open'); $('q').focus(); }   // axtarış
  if (e.key === 'Escape') { $('pcmenu').hidden = true; closeSide(); }
});
addEventListener('hashchange', applyHash);
addEventListener('popstate', applyHash);
document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
addEventListener('resize', () => { grow(); drawDown(); });

S.me = <?= json_encode($me['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
renderSide();
if (location.hash.length > 1) applyHash();
tick();
setInterval(tick, 1000);
</script>
</body>
</html>
