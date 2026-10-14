<?php
require __DIR__ . '/db.php';
auth_boot();
$error = '';
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = db();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    $pdo->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 900]);
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ?');
    $cnt->execute([$ip]);
    if ((int)$cnt->fetchColumn() >= 10) {
        $error = 'Çox cəhd oldu, 15 dəqiqə sonra yenidən yoxla';
    } else {
        usleep(500000);
        $st = $pdo->prepare('SELECT name, pass_hash, role FROM users WHERE LOWER(name) = LOWER(?)');
        $st->execute([trim((string)($_POST['user'] ?? ''))]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['ok'] = true;
            $_SESSION['user'] = $u['name'];
            $_SESSION['role'] = $u['role'];
            header('Location: ./');
            exit;
        }
        $pdo->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
        $error = 'Ad və ya parol yanlışdır';
    }
}
$authed = session_user() !== null;
?><!doctype html>
<html lang="az">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Komanda Claude</title>
<style>
:root {
  --ease-out: cubic-bezier(0.23, 1, 0.32, 1);
  --bg: #f4f3ef; --card: rgba(255,255,255,.78); --text: #1b1b19; --muted: #6b6b64; --line: rgba(0,0,0,.09);
  --work: #d97706; --wait: #dc2626; --idle: #16a34a; --off: #a3a39c;
  --accent: #c4552d; --violet: #6d5bd0;
  --glow1: rgba(217,119,6,.20); --glow2: rgba(109,91,208,.18); --glow3: rgba(22,163,74,.14);
  --shadow: 0 1px 2px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.05);
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0f0f12; --card: rgba(30,30,36,.72); --text: #ececf1; --muted: #9a9aa6; --line: rgba(255,255,255,.09);
    --work: #fbbf24; --wait: #f87171; --idle: #4ade80; --off: #6b6b78;
    --accent: #f08a5d; --violet: #9d8cff;
    --glow1: rgba(240,138,93,.20); --glow2: rgba(157,140,255,.20); --glow3: rgba(74,222,128,.12);
    --shadow: 0 1px 2px rgba(0,0,0,.4), 0 12px 32px rgba(0,0,0,.35);
  }
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body { margin: 0; color: var(--text); font: 15px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; min-height: 100vh; overflow-x: hidden; }

/* Fon: yavaş sürüşən işıq ləkələri (yalnız transform) */
body::before {
  content: ""; position: fixed; inset: -25%; z-index: -1; pointer-events: none; will-change: transform;
  background:
    radial-gradient(38% 38% at 18% 22%, var(--glow1), transparent 70%),
    radial-gradient(34% 34% at 82% 18%, var(--glow2), transparent 70%),
    radial-gradient(42% 42% at 62% 86%, var(--glow3), transparent 70%);
  animation: drift 26s ease-in-out infinite alternate;
}
@keyframes drift {
  from { transform: translate3d(-3%, -2%, 0) rotate(0deg) scale(1); }
  to   { transform: translate3d(3%, 3%, 0) rotate(7deg) scale(1.06); }
}

.wrap { max-width: 1120px; margin: 0 auto; padding: 22px 16px 56px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 22px; }
.brand { display: flex; align-items: center; gap: 12px; }
.logo { position: relative; width: 34px; height: 34px; border-radius: 10px; background: linear-gradient(135deg, var(--accent), var(--violet)); display: grid; place-items: center; color: #fff; font-weight: 700; }
.logo::after { content: ""; position: absolute; inset: 0; border-radius: inherit; border: 2px solid var(--accent); opacity: 0; animation: ping 2.4s var(--ease-out) infinite; }
h1 { font-size: 19px; margin: 0; letter-spacing: -.01em; }
h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); margin: 30px 0 12px; display: flex; align-items: center; gap: 10px; font-weight: 600; }
h2 .count { background: var(--line); color: var(--text); border-radius: 999px; padding: 1px 8px; font-size: 11px; }
.meta { display: flex; gap: 14px; align-items: center; color: var(--muted); font-size: 13px; }
.live { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); }
.live .dot { margin: 0; }
.live[data-off] .dot { background: var(--wait); }
.live[data-off] .dot::after { display: none; }
a { color: var(--muted); }

/* Canlı nöqtə */
.dot { position: relative; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--off); flex: none; }
.dot::after { content: ""; position: absolute; inset: 0; border-radius: 50%; background: inherit; opacity: 0; }
.dot.working, .dot.live { background: var(--work); }
.live .dot { background: var(--idle); }
.dot.waiting { background: var(--wait); }
.dot.idle, .dot.online { background: var(--idle); }
.dot.working::after, .dot.waiting::after, .live .dot::after { animation: ping 1.8s var(--ease-out) infinite; }
@keyframes ping { 0% { transform: scale(1); opacity: .55; } 80%, 100% { transform: scale(2.8); opacity: 0; } }

/* Statistika */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px 16px; box-shadow: var(--shadow); backdrop-filter: blur(10px); }
.stat b { display: block; font-size: 26px; line-height: 1.1; font-variant-numeric: tabular-nums; letter-spacing: -.02em; transform-origin: left center; }
.stat span { color: var(--muted); font-size: 12.5px; }
.stat b.bump { animation: bump .45s var(--ease-out); }
@keyframes bump { 0% { transform: scale(1.18); color: var(--accent); } 100% { transform: scale(1); } }

.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
.card { position: relative; background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 16px; box-shadow: var(--shadow); backdrop-filter: blur(10px); }
.enter { animation: enter .45s var(--ease-out) backwards; animation-delay: calc(var(--i, 0) * 45ms); }
@keyframes enter { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
@media (hover: hover) and (pointer: fine) {
  .card { transition: transform .2s var(--ease-out); }
  .card:hover { transform: translateY(-2px); }
}

/* Adam kartı */
.person { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
.avatar { position: relative; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 16px; flex: none; }
.avatar .ring { position: absolute; inset: -5px; border-radius: 50%; opacity: 0;
  background: conic-gradient(from 0deg, var(--work), transparent 65%, var(--work));
  -webkit-mask: radial-gradient(farthest-side, transparent calc(100% - 3px), #000 calc(100% - 2px));
          mask: radial-gradient(farthest-side, transparent calc(100% - 3px), #000 calc(100% - 2px)); }
[data-st="working"] > .person .ring, .stack .avatar[data-st="working"] .ring { opacity: 1; animation: spin 1.6s linear infinite; }
.stack .avatar[data-st="waiting"] .ring { opacity: 1; background: conic-gradient(var(--wait), var(--wait)); animation: breathe 1.4s ease-in-out infinite; }
[data-st="waiting"] > .person .ring { opacity: 1; background: conic-gradient(var(--wait), var(--wait)); animation: breathe 1.4s ease-in-out infinite; }
[data-st="online"] > .person .ring { opacity: .4; background: conic-gradient(var(--idle), var(--idle)); }
[data-st="idle"] > .person .ring { opacity: .55; background: conic-gradient(var(--idle), var(--idle)); }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes breathe { 0%, 100% { opacity: .25; transform: scale(.98); } 50% { opacity: 1; transform: scale(1.04); } }
.pname { font-weight: 650; font-size: 16px; }
.chip { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; color: var(--muted); }
.empty { color: var(--muted); font-size: 13.5px; padding: 10px 0 2px; }

.sess { border-top: 1px solid var(--line); margin-top: 12px; padding-top: 12px; }
.shead { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
.sprompt { margin: 6px 0 2px; font-size: 13.5px; overflow-wrap: anywhere; }
.sline { color: var(--muted); font-size: 13px; overflow-wrap: anywhere; display: flex; gap: 6px; align-items: baseline; }
.sline span.t { min-width: 0; }
.chips { display: flex; gap: 12px; margin-top: 8px; font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }

/* Axan progress zolağı + yazma nöqtələri (iş gedəndə) */
.bar { position: relative; height: 3px; margin-top: 10px; border-radius: 3px; overflow: hidden; background: var(--line); opacity: 0; }
.bar::after { content: ""; position: absolute; inset: 0 auto 0 0; width: 38%; border-radius: inherit;
  background: linear-gradient(90deg, transparent, var(--work), var(--accent), transparent); animation: slide 1.5s linear infinite; }
@keyframes slide { from { transform: translateX(-110%); } to { transform: translateX(270%); } }
.sess[data-st="working"] .bar { opacity: 1; }
.typing { display: none; gap: 3px; align-items: center; flex: none; transform: translateY(-1px); }
.typing i { width: 4px; height: 4px; border-radius: 50%; background: var(--work); animation: hop 1s ease-in-out infinite; }
.typing i:nth-child(2) { animation-delay: .15s; } .typing i:nth-child(3) { animation-delay: .3s; }
@keyframes hop { 0%, 60%, 100% { transform: translateY(0); opacity: .45; } 30% { transform: translateY(-4px); opacity: 1; } }
.sess[data-st="working"] .typing { display: inline-flex; }

/* Proyektlər */
.filters { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 12px; }
.filters button { border: 1px solid var(--line); background: var(--card); color: var(--text); border-radius: 999px; padding: 5px 14px; font: inherit; font-size: 13px; cursor: pointer; transition: background-color .15s ease, color .15s ease; }
.filters button:active { transform: scale(.97); }
.filters button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.proj::after { content: ""; position: absolute; inset: -1px; border-radius: inherit; border: 1.5px solid var(--work); opacity: 0; pointer-events: none; }
.proj[data-st="working"]::after { animation: glow 2.4s ease-in-out infinite; }
.proj[data-st="waiting"]::after { border-color: var(--wait); animation: glow 1.4s ease-in-out infinite; }
@keyframes glow { 0%, 100% { opacity: .15; } 50% { opacity: .9; } }
.pjhead { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.pjname { font-weight: 650; font-size: 16px; overflow-wrap: anywhere; }
.pjtitle { display: flex; align-items: center; gap: 9px; min-width: 0; }
.pjsum { color: var(--muted); font-size: 13px; margin: 8px 0 12px; min-height: 19px; overflow-wrap: anywhere; }
.pjfoot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.stack { display: flex; }
.stack .avatar { width: 26px; height: 26px; font-size: 11px; margin-left: -7px; border: 2px solid var(--bg); }
.stack .avatar:first-child { margin-left: 0; }
.stack .avatar .ring { inset: -4px; }
.pjnums { display: flex; gap: 12px; font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }

/* Canlı axın */
.feed { padding: 6px 16px; }
.row { position: relative; display: grid; grid-template-columns: 62px 1fr; gap: 10px; padding: 10px 0; border-top: 1px solid var(--line); font-size: 13.5px; }
.row:first-child { border-top: 0; }
.row .t { color: var(--muted); font-variant-numeric: tabular-nums; font-size: 12.5px; padding-top: 1px; }
.row .s { overflow-wrap: anywhere; }
.row .u { display: inline-block; font-weight: 650; margin-right: 6px; }
.row small { color: var(--muted); }
.row .k { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 7px; background: var(--muted); }
.row.k-prompt .k { background: var(--accent); } .row.k-edit .k { background: var(--violet); }
.row.k-stop .k { background: var(--idle); } .row.k-wait .k { background: var(--wait); }
.row.fresh::before { content: ""; position: absolute; inset: 0 -16px; background: var(--accent); opacity: 0; pointer-events: none; animation: flash 1.8s var(--ease-out); }
@keyframes flash { 0% { opacity: .16; } 100% { opacity: 0; } }
.row.enter { animation: rowin .32s var(--ease-out) backwards; animation-delay: calc(var(--i, 0) * 35ms); }
@keyframes rowin { from { opacity: 0; transform: translateX(-8px); } to { opacity: 1; transform: none; } }

/* ===== Naviqasiya ===== */
.tabs { display: flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--card); border: 1px solid var(--line); }
.tabs a { padding: 5px 16px; border-radius: 999px; color: var(--muted); text-decoration: none; font-size: 13.5px; font-weight: 600; transition: background-color .15s ease, color .15s ease; }
.tabs a.on { background: var(--accent); color: #fff; }
header { flex-wrap: wrap; }
.gbtn { border: 1px solid var(--line); background: var(--card); color: var(--text); border-radius: 999px; padding: 6px 14px; font: inherit; font-size: 13px; cursor: pointer; transition: transform .15s var(--ease-out); }
.gbtn:active { transform: scale(.97); }
.gbtn[hidden] { display: none; }
.ptop { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
.ptop .sp { flex: 1; }

/* ===== Kainat (həmişə tünd) ===== */
:root { --ease-io: cubic-bezier(0.77, 0, 0.175, 1); }
.space { position: relative; overflow: hidden; isolation: isolate; border-radius: 20px; border: 1px solid rgba(255,255,255,.08);
  background: radial-gradient(120% 90% at 50% 0%, #14172e 0%, #080914 55%, #03030a 100%); color: #e9e9f3; box-shadow: var(--shadow); }
.skyz { position: absolute; inset: 0; z-index: -1; pointer-events: none; transition: transform .6s var(--ease-io), opacity .5s ease; }
.nebula { position: absolute; inset: -20%; will-change: transform;
  background: radial-gradient(35% 30% at 25% 35%, rgba(109,91,208,.24), transparent 70%), radial-gradient(30% 28% at 78% 62%, rgba(217,90,120,.15), transparent 70%), radial-gradient(26% 24% at 55% 12%, rgba(60,160,220,.12), transparent 70%);
  animation: drift 40s ease-in-out infinite alternate; }
.stars { position: absolute; left: 0; top: 0; border-radius: 50%; will-change: opacity, translate; }
.stars.s1 { width: 1px; height: 1px; animation: twinkle 5s ease-in-out infinite alternate, sdrift 70s ease-in-out infinite alternate; }
.stars.s2 { width: 2px; height: 2px; animation: twinkle 7s -2s ease-in-out infinite alternate, sdrift 50s ease-in-out infinite alternate; }
.stars.s3 { width: 3px; height: 3px; animation: twinkle 4s -1s ease-in-out infinite alternate, sdrift 36s ease-in-out infinite alternate; }
@keyframes twinkle { from { opacity: .35; } to { opacity: 1; } }
@keyframes sdrift { from { translate: 0 0; } to { translate: -34px -22px; } }
.shoot { position: absolute; left: var(--sx); top: var(--sy); width: 110px; height: 1.5px; opacity: 0; transform: rotate(24deg);
  background: linear-gradient(90deg, transparent, #fff); animation: shoot 12s linear infinite; animation-delay: var(--sd); }
@keyframes shoot { 0% { opacity: 0; transform: rotate(24deg) translateX(0); } 2% { opacity: 1; } 8% { opacity: 0; transform: rotate(24deg) translateX(460px); } 100% { opacity: 0; } }

.hud { position: absolute; left: 18px; top: 14px; z-index: 2; display: flex; gap: 14px; font-size: 12.5px; color: rgba(233,233,243,.72); }
.hud span:first-child { font-weight: 600; color: #fff; }
.scene { position: relative; width: min(100%, 74vh, 720px); aspect-ratio: 1 / .8; margin: 6px auto 0; container-type: inline-size; touch-action: pan-y; cursor: grab; user-select: none; -webkit-user-select: none; }
.scene:active { cursor: grabbing; }
.cam { position: absolute; inset: 0; perspective: 195cqw; perspective-origin: 50% 45%; }
.rig { position: absolute; left: 0; top: 50%; width: 100%; aspect-ratio: 1; margin-top: -50%; transform-style: preserve-3d;
  transform: rotateX(calc(var(--tx, 58) * 1deg)) rotateZ(calc(var(--rz, -18) * 1deg)); }
.rig::before { content: ""; position: absolute; inset: -4%; border-radius: 50%; pointer-events: none; transform: translateZ(-3px);
  background: radial-gradient(closest-side, rgba(255,170,80,.15), rgba(120,100,220,.07) 55%, transparent 72%); }
.rig::after { content: ""; position: absolute; inset: 6%; border-radius: 50%; pointer-events: none; transform: translateZ(-2px);
  background: conic-gradient(rgba(160,140,255,0), rgba(160,140,255,.12), rgba(160,140,255,0) 30%, rgba(255,160,100,.1) 55%, rgba(255,160,100,0) 80%);
  -webkit-mask: radial-gradient(closest-side, transparent 50%, #000 70%, transparent 100%); mask: radial-gradient(closest-side, transparent 50%, #000 70%, transparent 100%);
  animation: spinr 90s linear infinite; }
@keyframes spinr { to { rotate: 360deg; } }
.skyz { translate: calc(var(--rz, -18) * -.6px) calc((var(--tx, 58) - 58) * -.5px); }
.space.warping .scene { transform: scale(3.4); opacity: 0; transition: transform .6s var(--ease-io), opacity .4s ease .15s; }
.space.warping .skyz { transform: scale(1.7); opacity: .45; }
/* Tam ekran Kainat */
body.v-space .wrap { max-width: none; padding: 12px 14px 14px; }
#space { --H: calc(100vh - 116px); height: var(--H); min-height: 460px; display: flex; flex-direction: column; justify-content: center; }
#space:fullscreen { --H: 100vh; height: 100vh; border-radius: 0; border: 0; }
#space .scene { width: min(100%, calc((var(--H) - 90px) * 1.25)); max-width: none; margin: 0 auto; }
#space .travel { position: absolute; left: 0; right: 0; bottom: 0; padding-bottom: 14px; }
#space:not(.gl) .zbtns { display: none; }
.zbtns { position: absolute; right: 14px; top: 54px; z-index: 3; display: grid; gap: 6px; }
.zbtns button { width: 36px; height: 36px; border-radius: 10px; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9e9f3; font-size: 20px; line-height: 1; cursor: pointer; transition: background-color .15s ease, transform .15s var(--ease-out); }
.zbtns button:active { transform: scale(.94); }
.zbtns button#qbtn { font-size: 12px; font-weight: 700; letter-spacing: .03em; } .zbtns button#sbtn { font-size: 15px; } .zbtns button.blocked { animation: alertpulse 1.6s ease-in-out infinite; border-color: #f87171; }
.zbtns button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
@media (hover: hover) and (pointer: fine) { .zbtns button:hover { background: rgba(255,255,255,.15); } }
.fsbtn { position: absolute; right: 14px; top: 10px; z-index: 3; width: 36px; height: 36px; border-radius: 10px; border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9e9f3; font-size: 18px; line-height: 1; cursor: pointer; transition: background-color .15s ease, transform .15s var(--ease-out); }
.fsbtn:active { transform: scale(.94); }
@media (hover: hover) and (pointer: fine) { .fsbtn:hover { background: rgba(255,255,255,.15); } }
@keyframes arrive { from { opacity: 0; transform: scale(1.35); } to { opacity: 1; transform: none; } }
.scene.arrive { animation: arrive .6s var(--ease-out); }
#orbits { position: absolute; inset: 0; transform-style: preserve-3d; }

.sun { position: absolute; inset: 0; margin: auto; width: 9cqw; height: 9cqw; border-radius: 50%; transform: rotateZ(calc(var(--rz, -18) * -1deg)) rotateX(calc(var(--tx, 58) * -1deg));
  background: radial-gradient(circle at 40% 35%, #fff6c8, #ffb347 45%, #ff7a3d 100%); box-shadow: 0 0 6cqw 1cqw rgba(255,150,60,.42); }
.sun::before { content: ""; position: absolute; inset: -38%; border-radius: 50%;
  background: conic-gradient(rgba(255,170,70,0), rgba(255,170,70,.4), rgba(255,170,70,0) 40%, rgba(255,120,60,.32) 70%, rgba(255,170,70,0));
  -webkit-mask: radial-gradient(closest-side, transparent 52%, #000 56%, transparent 100%); mask: radial-gradient(closest-side, transparent 52%, #000 56%, transparent 100%);
  animation: spin 16s linear infinite; }
.sun::after { content: ""; position: absolute; inset: -6%; border-radius: 50%; border: 2px solid rgba(255,190,100,.55); opacity: 0; animation: sunping 3.4s var(--ease-out) infinite; }
@keyframes sunping { 0% { transform: scale(1); opacity: .55; } 100% { transform: scale(1.9); opacity: 0; } }
.sunlbl { position: absolute; top: 112%; left: 50%; transform: translateX(-50%); font-size: clamp(10px, 1.7cqw, 12px); letter-spacing: .12em; text-transform: uppercase; color: rgba(255,214,160,.75); white-space: nowrap; }

.orbit { position: absolute; inset: calc(50% - var(--d, 50) * .5%); width: calc(var(--d, 50) * 1%); height: calc(var(--d, 50) * 1%);
  pointer-events: none; transform-style: preserve-3d; animation: spin var(--T, 120s) linear infinite; animation-delay: var(--dl, 0s); }
.orbit::before { content: ""; position: absolute; inset: 0; border-radius: 50%; border: 1px dashed rgba(255,255,255,.09); }
.slot { position: absolute; left: 50%; top: 0; width: 0; height: 0; transform-style: preserve-3d; animation: spin-rev var(--T, 120s) linear infinite; animation-delay: var(--dl, 0s); }
@keyframes spin-rev { to { transform: rotate(-360deg); } }
.scene:has(.pbox:hover) .orbit, .scene:has(.pbox:hover) .slot { animation-play-state: paused; }

.pbox { position: absolute; left: 0; top: 0; width: calc(var(--s, 9) * 1cqw); aspect-ratio: 1; transform-style: preserve-3d;
  --bb: translateZ(calc(var(--z, 0) * 1cqw)) translate(-50%, -50%) rotateZ(calc(var(--rz, -18) * -1deg)) rotateX(calc(var(--tx, 58) * -1deg));
  transform: var(--bb);
  border: 0; background: none; padding: 0; color: inherit; font: inherit; cursor: pointer; pointer-events: auto; transition: transform .25s var(--ease-out); }
.pbox:focus-visible { outline: 2px solid #fff; outline-offset: 6px; border-radius: 50%; }
@media (hover: hover) and (pointer: fine) { .pbox:hover { transform: var(--bb) scale(1.12); } }
.pbox:active { transform: var(--bb) scale(.97); }
.pbox.pop { animation: pop .6s var(--ease-out) backwards; animation-delay: calc(var(--i, 0) * 80ms); }
@keyframes pop { from { opacity: 0; scale: .3; } to { opacity: 1; scale: 1; } }
.plabel { position: absolute; top: 100%; left: 50%; transform: translateX(-50%); margin-top: .5em; text-align: center; white-space: nowrap; line-height: 1.25; pointer-events: none; font-size: clamp(10px, 1.8cqw, 13px); text-shadow: 0 1px 6px rgba(0,0,0,.8); }
.plabel b { display: block; font-weight: 650; }
.plabel span { color: rgba(233,233,243,.62); font-size: .88em; }
.pbox[data-st="working"] .plabel span { color: #fbbf24; }
.pbox[data-st="waiting"] .plabel span { color: #f87171; }

/* Planet */
.planet { position: relative; width: 100%; aspect-ratio: 1; border-radius: 50%; transform-style: preserve-3d; }
.planet .aura { position: absolute; inset: -34%; border-radius: 50%; opacity: .26; pointer-events: none;
  background: radial-gradient(closest-side, hsl(var(--h) 90% 62% / .55), transparent); }
.planet .body { position: absolute; inset: 0; border-radius: 50%; overflow: hidden;
  background: radial-gradient(circle at 32% 28%, hsl(var(--h) 75% 72%), hsl(var(--h) 60% 42%) 52%, hsl(var(--h) 55% 15%)); }
.planet .body::after { content: ""; position: absolute; inset: 0; border-radius: 50%; background: radial-gradient(circle at 30% 28%, transparent 38%, rgba(0,0,0,.6) 100%); }
.planet .surf { position: absolute; top: 0; left: 0; width: 200%; height: 100%; background-size: 50% 100%; background-repeat: repeat-x; will-change: transform;
  background-image: radial-gradient(ellipse 30% 20% at 22% 30%, hsl(var(--h) 85% 85% / .38), transparent), radial-gradient(ellipse 24% 17% at 64% 64%, hsl(var(--h) 45% 14% / .42), transparent), radial-gradient(ellipse 16% 11% at 82% 22%, hsl(var(--h) 80% 82% / .3), transparent), radial-gradient(ellipse 20% 12% at 40% 82%, hsl(var(--h) 70% 25% / .35), transparent);
  animation: surf 38s linear infinite; }
@keyframes surf { to { transform: translateX(-50%); } }
.planet .ringd { position: absolute; left: -38%; right: -38%; top: 38%; bottom: 38%; border-radius: 50%; border: 3px solid hsl(var(--h) 55% 78% / .45); transform: rotate(-18deg); pointer-events: none; }
.moons { position: absolute; inset: 0; pointer-events: none; transform-style: preserve-3d; transform: rotateX(72deg); }
.mo { position: absolute; inset: calc(var(--r, -22) * 1%); transform-style: preserve-3d; animation: spin var(--mp, 14s) linear infinite; animation-delay: var(--d, 0s); }
.mm { position: absolute; left: 50%; top: 0; width: 9%; aspect-ratio: 1; margin: -4.5% 0 0 -4.5%; transform-style: preserve-3d; animation: spin-rev var(--mp, 14s) linear infinite; animation-delay: var(--d, 0s); }
.mo[data-st="working"] { --mp: 5s; }
.mo[data-st="offline"], .mo[data-st="ended"] { opacity: .35; --mp: 26s; }
.moon { position: absolute; inset: 0; border-radius: 50%; transform: rotateX(-72deg); box-shadow: 0 0 8px currentColor; }
.pbox[data-st="working"] .aura, .hero[data-st="working"] .aura { opacity: .6; animation: aura 2.2s ease-in-out infinite; }
.pbox[data-st="waiting"] .aura, .hero[data-st="waiting"] .aura { background: radial-gradient(closest-side, rgba(248,113,113,.7), transparent); opacity: .6; animation: aura 1.3s ease-in-out infinite; }
.pbox[data-st="idle"] .aura, .hero[data-st="idle"] .aura { opacity: .34; }
.pbox[data-st="offline"] .body, .hero[data-st="offline"] .body { filter: saturate(.35) brightness(.75); }
@keyframes aura { 0%, 100% { transform: scale(.95); opacity: .3; } 50% { transform: scale(1.16); opacity: .85; } }

.travel { display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; padding: 6px 14px 16px; position: relative; z-index: 2; }
.travel button { display: inline-flex; align-items: center; gap: 8px; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.06); color: #e9e9f3; border-radius: 999px; padding: 5px 13px; font: inherit; font-size: 13px; cursor: pointer; transition: background-color .15s ease, transform .15s var(--ease-out); }
.travel button:active { transform: scale(.97); }
@media (hover: hover) and (pointer: fine) { .travel button:hover { background: rgba(255,255,255,.14); } }
.tdot { width: 9px; height: 9px; border-radius: 50%; background: hsl(var(--h) 70% 55%); }
.sempty { text-align: center; padding: 0 24px 28px; color: rgba(233,233,243,.65); font-size: 14px; }
.space .enter { animation-name: enter; }

/* Planet səhifəsi */
.hero { display: flex; align-items: center; gap: 36px; flex-wrap: wrap; padding: 26px 32px; min-height: 290px; }
.hplanet { position: relative; width: 170px; height: 170px; margin: 22px 34px; flex: none; transform-style: preserve-3d; }
.hplanet.landing { animation: landing .8s var(--ease-out) backwards; }
@keyframes landing { from { opacity: 0; transform: translateY(24px) scale(.5); } to { opacity: 1; transform: none; } }
.hinfo { flex: 1; min-width: 240px; }
.ptitle { font-size: 28px; font-weight: 700; letter-spacing: -.02em; overflow-wrap: anywhere; }
.hinfo .chip { color: rgba(233,233,243,.75); margin: 4px 0 14px; font-size: 13.5px; }
.hstats { display: flex; gap: 22px; flex-wrap: wrap; margin-bottom: 16px; }
.hstats div { min-width: 70px; }
.hstats b { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
.hstats span { font-size: 12px; color: rgba(233,233,243,.6); }
.crew { display: flex; gap: 14px; flex-wrap: wrap; }
.crewm { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.crewm .avatar { width: 28px; height: 28px; font-size: 12px; }
.crewm[data-st="offline"], .crewm[data-st="ended"] { opacity: .5; }
.card.sess { margin-top: 0; padding: 16px; }
.card.sess .shead { gap: 10px; }
.card.sess .shead .avatar { width: 30px; height: 30px; font-size: 13px; }
.card.sess .st { margin-left: auto; font-size: 12.5px; font-weight: 500; color: var(--muted); display: inline-flex; align-items: center; gap: 7px; }
.card.sess[data-st="offline"], .card.sess[data-st="ended"] { opacity: .6; }
.pcard[data-st="working"] .avatar .ring, .crewm[data-st="working"] .avatar .ring { opacity: 1; animation: spin 1.6s linear infinite; }
.pcard[data-st="waiting"] .avatar .ring, .crewm[data-st="waiting"] .avatar .ring { opacity: 1; background: conic-gradient(var(--wait), var(--wait)); animation: breathe 1.4s ease-in-out infinite; }
.pcard[data-st="idle"] .avatar .ring, .crewm[data-st="idle"] .avatar .ring { opacity: .55; background: conic-gradient(var(--idle), var(--idle)); }
.proj { cursor: pointer; }
@media (max-width: 520px) { .hero { padding: 16px; gap: 8px; } .hplanet { margin: 18px auto; } }

/* ===== WebGL qatı və canlı panel (dock) ===== */
#space.gl .scene, #space.gl .skyz { display: none; }
#space.gl .dock { -webkit-backdrop-filter: none; backdrop-filter: none; background: rgba(9,10,20,.9); }
body.v-space::before { display: none; }
.glcanvas { position: absolute; inset: 0; width: 100%; height: 100%; display: block; z-index: 0; touch-action: none; cursor: grab; outline: none; }
.glbls { position: absolute; inset: 0; z-index: 1; pointer-events: none; overflow: hidden; }
.glabel { position: absolute; left: 0; top: 0; pointer-events: auto; background: none; border: 0; padding: 4px 8px; color: #e9e9f3; font: inherit; font-size: 13px; text-align: center; line-height: 1.25; white-space: nowrap; cursor: pointer; text-shadow: 0 1px 8px #000, 0 0 2px #000; will-change: transform; transition: opacity .3s ease; }
.glabel b { display: block; font-weight: 650; transition: transform .2s var(--ease-out); }
.glabel span { color: rgba(233,233,243,.62); font-size: 12px; }
.glabel[data-st="working"] span { color: #fbbf24; } .glabel[data-st="waiting"] span { color: #f87171; }
.glabel.hov b { transform: scale(1.12); }
.glabel.sunl { pointer-events: none; font-size: 11px; letter-spacing: .14em; text-transform: uppercase; color: rgba(255,214,160,.8); }
#space .hud, #space .travel, #space .fsbtn { z-index: 3; }
.dock { position: absolute; z-index: 4; right: 14px; top: 14px; bottom: 14px; width: min(430px, calc(100% - 28px)); overflow: auto; padding: 16px; border-radius: 18px;
  --card: rgba(255,255,255,.06); --text: #ececf1; --muted: #a3a3b2; --line: rgba(255,255,255,.1);
  background: rgba(9,10,20,.74); -webkit-backdrop-filter: blur(16px) saturate(1.2); backdrop-filter: blur(16px) saturate(1.2);
  border: 1px solid rgba(255,255,255,.12); color: var(--text); scrollbar-width: thin;
  opacity: 0; transform: translateX(28px); pointer-events: none; transition: transform .5s var(--ease-out), opacity .3s ease; }
.dock.open { opacity: 1; transform: none; pointer-events: auto; transition-delay: .45s, .45s; }
.dhead { display: flex; gap: 8px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
.dhead .sp { flex: 1; }
.dock .gbtn { background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.14); color: #ececf1; }
.dtitle { font-size: 27px; font-weight: 700; letter-spacing: -.02em; overflow-wrap: anywhere; }
.dock .chip { color: rgba(233,233,243,.75); margin: 4px 0 8px; font-size: 13.5px; }
.dock .hstats { margin: 14px 0; }
.dock h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .09em; color: var(--muted); margin: 22px 0 10px; display: flex; align-items: center; gap: 10px; font-weight: 600; }
.dock h3 .count { background: var(--line); color: var(--text); border-radius: 999px; padding: 1px 8px; font-size: 11px; }
.dlist { display: grid; gap: 10px; }
.dock .card.feed { padding: 4px 12px; }
.dock .row .u { display: inline-block; }
@media (max-width: 700px) { .dock { top: auto; left: 10px; right: 10px; bottom: 10px; width: auto; height: 58%; transform: translateY(28px); } }
@media (prefers-reduced-motion: reduce) { .dock, .dock.open { transition: opacity .2s ease; transform: none; transition-delay: 0s; } }

/* Prompt göndərmə */
.cmdbox [hidden], .cmdc [hidden] { display: none !important; }
.cmdbox textarea, .tokrow input { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 10px 12px; font: inherit; font-size: 14px; resize: vertical; min-height: 64px; }
.tokrow { display: flex; gap: 8px; } .tokrow input { min-height: 0; }
.cmdbox textarea:focus, .tokrow input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.cmdbar { display: flex; align-items: center; justify-content: space-between; margin: 8px 0 10px; gap: 10px; }
.cmdwho { font-size: 12.5px; color: var(--muted); } .cmdwho a { color: var(--muted); cursor: pointer; }
.gbtn.go { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
.gbtn:disabled { opacity: .5; cursor: default; }
.agentrow { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 2px 0 10px; }
.agentrow .gbtn { text-decoration: none; display: inline-block; }
.agenthint { font-size: 12px; color: var(--muted); } .agenthint code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 5px; font-size: 11.5px; }
.cmdbox .agentrow[hidden] { display: none !important; }
.loginhint { font-size: 13px; color: #fbbf24; background: rgba(251,191,36,.08); border: 1px solid rgba(251,191,36,.25); border-radius: 10px; padding: 8px 10px; margin: 2px 0 10px; }
.loginhint code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 5px; font-size: 12px; }
.cmdbox .loginhint[hidden] { display: none !important; }
.cmderr { color: #f87171; font-size: 13px; margin: 6px 0; }
.cmdbox textarea.drop { outline: 2px dashed var(--accent); outline-offset: 1px; }
.cmdimgs { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; } .cmdimgs[hidden] { display: none; }
.cmdimgs .cimg { position: relative; width: 64px; height: 64px; border-radius: 10px; overflow: hidden; border: 1px solid rgba(255,255,255,.16); background: rgba(0,0,0,.3); }
.cmdimgs .cimg img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cmdimgs .cimg .rm { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border: 0; border-radius: 50%; background: rgba(0,0,0,.65); color: #fff; font-size: 11px; cursor: pointer; display: grid; place-items: center; }
.cmdc .ci { display: flex; gap: 6px; flex-wrap: wrap; margin: 6px 0 2px; }
.cmdc .ci a { display: block; width: 56px; height: 56px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(255,255,255,.14); background: rgba(0,0,0,.3); }
.cmdc .ci img { width: 100%; height: 100%; object-fit: cover; display: block; }
.agentst { font-size: 11px; text-transform: none; letter-spacing: 0; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; }
.agentst i { width: 7px; height: 7px; border-radius: 50%; background: var(--off); } .agentst.on i { background: var(--idle); }
.cmdc { padding: 12px 14px; }
.cmdc .ch { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--muted); }
.cmdc .ch b { color: var(--text); font-weight: 600; } .cmdc .ch .st { margin-left: auto; display: inline-flex; align-items: center; gap: 6px; }
.cmdc .cp { margin: 6px 0 2px; font-size: 14px; overflow-wrap: anywhere; white-space: pre-wrap; }
.cmdc details { margin-top: 8px; } .cmdc summary { cursor: pointer; font-size: 12.5px; color: var(--muted); }
.cmdc pre { margin: 8px 0 0; padding: 10px; border-radius: 10px; background: rgba(0,0,0,.35); font: 12.5px/1.5 ui-monospace, Menlo, monospace; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 260px; overflow: auto; }
.cmdc .cx { margin-top: 6px; }

/* Sadə panel */
.dock { padding: 14px; }
.dtop { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.dtop .dtitle { flex: 1; min-width: 0; font-size: 21px; }
.ibtn { width: 34px; height: 34px; border-radius: 10px; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.07); color: #ececf1; font-size: 18px; line-height: 1; cursor: pointer; flex: none; transition: background-color .15s ease, transform .15s var(--ease-out); }
.ibtn:active { transform: scale(.94); } .ibtn[hidden] { display: none; }
@media (hover: hover) and (pointer: fine) { .ibtn:hover { background: rgba(255,255,255,.15); } }
.dnav { display: flex; gap: 6px; }
.dsub { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: rgba(233,233,243,.85); margin-bottom: 14px; }
.dsub .dmeta { color: var(--muted); }
.dtabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; border-radius: 12px; background: rgba(255,255,255,.06); margin-bottom: 14px; }
.dtabs button { border: 0; background: none; color: var(--muted); font: inherit; font-size: 14px; font-weight: 600; padding: 8px; border-radius: 9px; cursor: pointer; transition: background-color .15s ease, color .15s ease; }
.dtabs button.on { background: rgba(255,255,255,.13); color: #fff; }
.dtabs .count { background: var(--line); border-radius: 999px; padding: 0 7px; font-size: 11px; margin-left: 4px; }
.lbl { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); margin: 18px 0 8px; font-weight: 600; }
.agentline { margin-bottom: 8px; min-height: 18px; }
.dock .agentst { font-size: 12.5px; color: var(--muted); }
.dock .card.sess { padding: 10px 12px; }
.dock .sprompt { display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; margin: 4px 0 0; }
.dock .sess .chips { display: none; }
.dock .cmdc { padding: 10px 12px; }
.dock .cmdc .cp { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.dock .row { padding: 8px 0; }
#space.docked .fsbtn, #space.docked .zbtns { right: calc(min(430px, 100% - 28px) + 28px); }
@media (max-width: 700px) { #space.docked .fsbtn, #space.docked .zbtns { display: none; } }

/* Admin və hesab pəncərələri */
.who { font-size: 13px; color: var(--muted); }
.meta .gbtn { text-decoration: none; padding: 4px 11px; font-size: 12.5px; }
.modal { position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.6); display: grid; place-items: center; padding: 16px; }
.modal[hidden] { display: none; }
.mbox { width: min(580px, 100%); max-height: 90vh; overflow: auto; background: #12131f; color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 18px; padding: 18px;
  --card: rgba(255,255,255,.06); --text: #ececf1; --muted: #a3a3b2; --line: rgba(255,255,255,.1); }
.mbox.small { width: min(400px, 100%); }
.mbox input, .mbox textarea { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 10px 12px; font: inherit; font-size: 14px; margin-top: 8px; }
.mbox textarea { font: 12.5px/1.5 ui-monospace, Menlo, monospace; resize: vertical; }
.mbox input:focus, .mbox textarea:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.mhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; font-size: 17px; }
.mnote { font-size: 13px; color: var(--muted); margin: 4px 0 6px; }
.ulist { display: grid; gap: 8px; }
.urow { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); flex-wrap: wrap; }
.urow .un { font-weight: 600; } .urow .ur { font-size: 11px; color: var(--muted); border: 1px solid var(--line); border-radius: 999px; padding: 1px 8px; }
.urow .us { margin-left: auto; font-size: 12.5px; color: var(--muted); display: inline-flex; align-items: center; gap: 6px; }
.urow .gbtn { padding: 4px 11px; font-size: 12.5px; } .urow .gbtn.danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.madd { display: flex; gap: 8px; margin-top: 14px; } .madd input { margin-top: 0; } .mbox .madd select { width: auto; margin-top: 0; } .madd .gbtn { white-space: nowrap; }
.mres { margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line); }
.mbox .cmdbar { margin: 10px 0 0; }

/* Xəbərdarlıq lenti, hesabat, bildirişlər */
.meta { flex-wrap: wrap; justify-content: flex-end; }
.alerts { margin-bottom: 14px; display: grid; gap: 8px; }
.alerts[hidden] { display: none; }
.alert { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; border-radius: 12px; font-size: 13.5px;
  background: rgba(248,113,113,.12); border: 1px solid rgba(248,113,113,.35); color: var(--text); animation: alertpulse 2.4s ease-in-out infinite; }
.alert b { color: #f87171; } .alert a { color: var(--accent); cursor: pointer; text-decoration: underline; }
@keyframes alertpulse { 0%, 100% { border-color: rgba(248,113,113,.25); } 50% { border-color: rgba(248,113,113,.7); } }
@media (prefers-reduced-motion: reduce) { .alert { animation: none; } }
.glabel[data-alert="1"] span::after { content: "  ⚠ toqquşma"; color: #f87171; }
.rcard { padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); }
.rcard .rn { font-weight: 650; display: flex; align-items: center; gap: 8px; }
.rcard .rl { font-size: 13px; color: var(--muted); margin-top: 4px; }
.rcard .rt { font-size: 13px; margin-top: 4px; } .rcard .rt::before { content: "✅ "; }
.mbox code { background: rgba(255,255,255,.08); padding: 1px 5px; border-radius: 5px; font-size: 11.5px; }
#rep .filters, #ntf .filters { margin-bottom: 10px; }
#ntf [hidden], #rep [hidden] { display: none !important; }
/* Dayandır / Bağla düymələri */
.sstop { margin-left: 8px; padding: 2px 10px; font-size: 12px; color: #f87171; border-color: rgba(248,113,113,.35); } .sstop[hidden] { display: none; }
.shead .st + .sstop { margin-left: 8px; }
/* Tapşırıq lövhəsi */
#v-board [hidden], #tk [hidden] { display: none !important; }
.tbadge { background: var(--accent); color: #fff; border-radius: 999px; padding: 0 7px; font-size: 11px; margin-left: 4px; }
.bbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; } .bbar .sp { flex: 1; } .bbar .filters { margin: 0; }
.bbar select, .btoken input, #tk select { background: var(--card); color: var(--text); border: 1px solid var(--line); border-radius: 10px; padding: 7px 10px; font: inherit; font-size: 13.5px; }
#tk select, #msg select, #pl select { width: 100%; margin-top: 8px; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border-color: rgba(255,255,255,.14); padding: 10px 12px; border-radius: 12px; }
#tk select option, #msg select option { color: #111; }
#msg textarea { font: inherit; font-size: 14px; line-height: 1.45; min-height: 96px; }
#msg .filters { margin: 0 0 8px; }
.mrow { padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); display: grid; gap: 4px; }
.mrow .mh { display: flex; gap: 8px; align-items: center; font-size: 12.5px; color: var(--muted); flex-wrap: wrap; } .mrow .mh b { color: var(--text); }
.mrow .mb, .mtoast .mtb { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; }
.mrow.new { border-color: rgba(251,191,36,.5); }
.mtoast .avatar, .mrow .avatar { width: 26px; height: 26px; font-size: 12px; }
.mrow .gbtn { justify-self: start; padding: 3px 10px; font-size: 12.5px; }
.rkt { position: absolute; left: 0; top: 0; visibility: hidden; pointer-events: none; font-size: 11.5px; color: #ffe9c2; background: rgba(24,16,8,.62); border: 1px solid rgba(255,190,110,.45); border-radius: 999px; padding: 1px 8px; white-space: nowrap; text-shadow: 0 1px 4px #000; will-change: transform; }
.kflash { position: absolute; inset: 0; z-index: 2; pointer-events: none; opacity: 0; background: radial-gradient(circle at var(--fx, 50%) var(--fy, 50%), rgba(255,238,200,.95), rgba(255,160,80,.35) 22%, rgba(255,120,60,0) 56%); }
.glabel[data-hit="1"] b::after { content: " 💥"; }
.glabel.shipl span { color: #7dd3fc; } .glabel.shipl b { color: #e4f3ff; }
.dock .filters button { background: rgba(255,255,255,.07); border-color: rgba(255,255,255,.14); color: #ececf1; }
.dock .filters button.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.plchips { max-height: 112px; overflow: auto; }
.plg h3 { margin: 18px 0 8px; } .plg h3 .gbtn { margin-left: auto; padding: 2px 10px; font-size: 12px; text-transform: none; letter-spacing: 0; }
.pli { display: grid; grid-template-columns: auto 1fr auto; gap: 4px 10px; align-items: start; padding: 10px 12px; border: 1px solid rgba(255,255,255,.1); border-radius: 12px; background: rgba(255,255,255,.05); margin-bottom: 8px; }
.pli .chk { width: 22px; height: 22px; border-radius: 7px; border: 1.5px solid rgba(255,255,255,.35); background: none; color: transparent; cursor: pointer; display: grid; place-items: center; font-size: 13px; padding: 0; transition: background-color .15s ease, border-color .15s ease; }
.pli.done .chk { background: #4ade80; border-color: #4ade80; color: #08210f; }
.pli .pt { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; padding-top: 1px; } .pli.done .pt { text-decoration: line-through; opacity: .55; }
.pli .pd { grid-column: 2 / 4; white-space: pre-wrap; font-size: 13px; color: rgba(233,233,243,.78); overflow-wrap: anywhere; }
.pli .pm { grid-column: 2 / 4; font-size: 12px; color: var(--muted); }
.pli .pa { display: flex; gap: 2px; } .pli .pa button { border: 0; background: none; color: rgba(233,233,243,.6); cursor: pointer; font-size: 14px; padding: 2px 5px; border-radius: 6px; }
.pli.tk { grid-template-columns: 1fr auto; } .pli.tk .pd, .pli.tk .pm { grid-column: 1 / 3; }
.pli .tst { font-size: 11.5px; border: 1px solid rgba(255,255,255,.18); border-radius: 999px; padding: 1px 9px; color: rgba(233,233,243,.8); white-space: nowrap; }
.pli .tst[data-s="doing"] { color: #fbbf24; border-color: rgba(251,191,36,.5); } .pli .tst[data-s="review"] { color: #7dd3fc; border-color: rgba(125,211,252,.5); } .pli .tst[data-s="done"] { color: #4ade80; border-color: rgba(74,222,128,.5); }
@media (hover: hover) and (pointer: fine) { .pli .pa button:hover { background: rgba(255,255,255,.12); color: #fff; } .pli:not(.done) .chk:hover { border-color: #4ade80; } }
#pl textarea { font: inherit; font-size: 14px; line-height: 1.45; min-height: 110px; } #pl .filters { margin: 0 0 4px; }
.mtoasts { position: fixed; left: 16px; bottom: 16px; z-index: 70; display: grid; gap: 10px; width: min(360px, calc(100vw - 32px)); pointer-events: none; }
.mtoast { pointer-events: auto; background: #12131f; color: #ececf1; border: 1px solid rgba(251,191,36,.45); border-radius: 14px; padding: 12px 14px; display: grid; gap: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); animation: toastin .28s cubic-bezier(.23,1,.32,1); }
.mtoast.mini { border-color: rgba(255,255,255,.18); font-size: 13.5px; padding: 9px 12px; }
.mtoast .mth { display: flex; align-items: center; gap: 8px; font-size: 13px; } .mtoast .mth .ibtn { margin-left: auto; }
.mtoast .mtw { color: #fbbf24; } .mtoast .gbtn { justify-self: start; padding: 3px 11px; font-size: 12.5px; }
@keyframes toastin { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
@media (prefers-reduced-motion: reduce) { .mtoast { animation: none; } }
.btoken { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 12px; border: 1px solid var(--line); border-radius: 12px; background: var(--card); margin-bottom: 14px; font-size: 13px; color: var(--muted); }
.btoken input { min-width: 200px; }
.bmsg { padding: 10px 12px; border-radius: 12px; font-size: 13.5px; margin-bottom: 14px; background: rgba(248,113,113,.12); border: 1px solid rgba(248,113,113,.3); color: #f87171; }
.bcols { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; align-items: start; }
@media (max-width: 1000px) { .bcols { grid-template-columns: repeat(2, minmax(0, 1fr)); } } @media (max-width: 600px) { .bcols { grid-template-columns: 1fr; } }
.bcol { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 10px; min-height: 120px; }
.bch { display: flex; align-items: center; justify-content: space-between; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); font-weight: 600; padding: 4px 6px 10px; }
.bch .count { background: var(--line); color: var(--text); border-radius: 999px; padding: 0 8px; font-size: 11px; }
.bcol[data-s="doing"] .bch { color: var(--work); } .bcol[data-s="review"] .bch { color: var(--violet); } .bcol[data-s="done"] .bch { color: var(--idle); }
.blist { display: grid; gap: 10px; }
.tcard { background: var(--bg); border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; }
.tcard .tt { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
.tcard .tm { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--muted); margin-top: 6px; }
.tcard .tp { background: var(--line); color: var(--text); border-radius: 6px; padding: 1px 7px; cursor: pointer; }
.tcard .tdesc { font-size: 13px; color: var(--muted); margin-top: 6px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; overflow-wrap: anywhere; white-space: pre-wrap; }
.tcard .tcmd { margin-top: 8px; font-size: 12.5px; } .tcard .tcmd summary { cursor: pointer; color: var(--muted); display: flex; align-items: center; gap: 6px; }
.tcard .tcmd pre { margin: 6px 0 0; padding: 8px; border-radius: 8px; background: rgba(0,0,0,.25); font: 12px/1.45 ui-monospace, Menlo, monospace; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 180px; overflow: auto; color: var(--text); }
.tcard .tact { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; } .tcard .tact .gbtn { padding: 4px 10px; font-size: 12.5px; }
.tcard .tact .danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.tcard .avatar { width: 20px; height: 20px; font-size: 10px; } .tcard .avatar .ring { display: none; }

/* Giriş */
.login { max-width: 330px; margin: 18vh auto 0; }
.login h1 { margin-bottom: 4px; }
.login input, .login button { width: 100%; font: inherit; padding: 10px 12px; border-radius: 9px; border: 1px solid var(--line); margin-top: 10px; }
.login input { background: transparent; color: var(--text); }
.login button { background: var(--accent); border-color: var(--accent); color: #fff; cursor: pointer; transition: transform .15s var(--ease-out); }
.login button:active { transform: scale(.98); }
.err { color: var(--wait); margin-top: 8px; font-size: 14px; }

@media (max-width: 520px) { .row { grid-template-columns: 48px 1fr; } .wrap { padding-top: 16px; } }

/* ===== Yeni: gəmilər, peyklər, balonlar, xüsusi planetlər ===== */
.glabel.galbl { font-size: 15px; padding: 6px 12px; } .glabel.galbl b { font-size: 17px; letter-spacing: -.01em; } .glabel.galbl span { font-size: 12.5px; }
.glabel.galbl.hov b { transform: scale(1.08); }
.maphint { position: absolute; left: 50%; top: 14px; transform: translateX(-50%); z-index: 2; pointer-events: none; font-size: 12.5px; color: rgba(233,233,243,.7); background: rgba(9,10,20,.5); border: 1px solid rgba(255,255,255,.1); border-radius: 999px; padding: 4px 12px; white-space: nowrap; transition: opacity .6s ease; }
.maphint[hidden] { display: block; opacity: 0; }
@media (max-width: 760px) { .maphint { display: none !important; } }
.stools { position: absolute; left: 14px; top: 40px; z-index: 3; display: flex; gap: 6px; flex-wrap: wrap; max-width: calc(100% - 80px); }
.stools button { border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.07); color: #e9e9f3; border-radius: 999px; padding: 4px 11px; font: inherit; font-size: 12.5px; cursor: pointer; transition: background-color .15s ease, transform .15s var(--ease-out); }
.stools button:active { transform: scale(.97); }
.space.docked .stools { display: none; }
.glabel[data-burn="1"] b::after { content: " 💨"; } .glabel[data-burn="2"] b::after { content: " 🔥"; } .glabel[data-burn="3"] b::after { content: " 🔥💥"; }
.glabel[data-burn="2"] span, .glabel[data-burn="3"] span { color: #fb923c; }
.glabel[data-kind="prompt"] b { color: #7dd3fc; } .glabel[data-kind="designer"] b { color: #f9a8d4; } .glabel[data-kind="meeting"] b { color: #e2e8f0; }
.glabel.ushipl { font-size: 12px; padding: 2px 7px; }
.glabel.ushipl b { font-weight: 600; color: var(--sc, #fcd34d); }
.glabel.ushipl span { font-size: 11px; }
.glabel.ushipl[data-warn="1"] span { color: #fca5a5; } .glabel.ushipl[data-warn="2"] span { color: #f87171; font-weight: 600; animation: blinkt 1s steps(2, start) infinite; }
@keyframes blinkt { to { visibility: hidden; } }
.slabel { position: absolute; left: 0; top: 0; visibility: hidden; pointer-events: none; font-size: 10.5px; color: rgba(190,242,234,.85); white-space: nowrap; text-shadow: 0 1px 6px #000; will-change: transform; }
.slabel[data-on="1"] { color: #5eead4; }
.balloon { position: absolute; left: 0; top: 0; visibility: hidden; pointer-events: auto; cursor: pointer; max-width: 210px; padding: 5px 10px 6px; border-radius: 12px;
  background: rgba(255,250,235,.95); color: #1b1b19; font-size: 12px; line-height: 1.3; box-shadow: 0 6px 18px rgba(0,0,0,.45); will-change: transform; }
.balloon::after { content: ""; position: absolute; left: 50%; bottom: -6px; margin-left: -6px; border: 6px solid transparent; border-bottom: 0; border-top-color: rgba(255,250,235,.95); }
.balloon b { display: block; font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.balloon span { color: #b45309; font-weight: 600; }
.balloon[data-lv="1"] span { color: #dc2626; }
.balloon[data-lv="2"] { background: #fee2e2; } .balloon[data-lv="2"]::after { border-top-color: #fee2e2; } .balloon[data-lv="2"] span { color: #b91c1c; }
.balloon[data-lv="2"] { animation: bwiggle 2.4s ease-in-out infinite; }
@keyframes bwiggle { 0%, 100% { margin-top: 0; } 50% { margin-top: -4px; } }
.dock.noproj .dtabs, .dock.noproj #pane-send, .dock.noproj #pane-live { display: none; }
/* Student yalnız öz universini görür: komanda bölmələri gizlidir (server də bağlayır) */
body.student #tab-panel, body.student #tab-board, body.student #b-plan, body.student #b-msg, body.student #b-rep,
body.student a[href^="kit.php"], body.student #t-mcp, body.student #jv-btn { display: none; }
.dinfo { display: grid; gap: 8px; margin: 10px 0 4px; }
.dinfo:empty { display: none; }
.burnbox { padding: 9px 12px; border-radius: 12px; font-size: 13px; background: rgba(251,146,60,.12); border: 1px solid rgba(251,146,60,.4); color: #fed7aa; }
.burnbox[data-lv="3"] { background: rgba(248,113,113,.14); border-color: rgba(248,113,113,.5); color: #fecaca; }
.infobox { padding: 9px 12px; border-radius: 12px; font-size: 13px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); }
.infobox .row2 { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.infobox .row2 .sp { flex: 1; }
.satl { display: flex; flex-wrap: wrap; gap: 6px; }
.satl span { font-size: 12px; border: 1px solid rgba(94,234,212,.35); color: #99f6e4; border-radius: 999px; padding: 1px 9px; }
.satl span[data-on="1"] { background: rgba(94,234,212,.15); }
.mbox select { width: 100%; margin-top: 8px; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); padding: 10px 12px; border-radius: 12px; font: inherit; font-size: 14px; }
.mbox select option, .dock select option { color: #111; }
.mbox label.fl, .dock label.fl { display: block; font-size: 12px; color: var(--muted); margin: 12px 0 -2px; }
.mbox .crow { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 8px; }
.mbox input[type="color"] { width: 46px; height: 34px; padding: 2px; margin: 0; border-radius: 8px; cursor: pointer; }
.mbox input[type="range"] { padding: 0; margin: 0; flex: 1; accent-color: var(--accent); border: 0; background: none; }
.mbox input[type="checkbox"] { width: auto; margin: 0; accent-color: var(--accent); }
.mbox .swatch { width: 26px; height: 26px; border-radius: 50%; border: 1px solid rgba(255,255,255,.3); }
.mbox .chk2 { display: inline-flex; gap: 6px; align-items: center; font-size: 13.5px; cursor: pointer; }
.mbox .filters { margin: 8px 0 0; }
.dock input, .dock textarea, .dock select { width: 100%; box-sizing: border-box; background: rgba(255,255,255,.06); color: #ececf1; border: 1px solid rgba(255,255,255,.14); border-radius: 12px; padding: 9px 11px; font: inherit; font-size: 13.5px; margin-top: 6px; }
.dock textarea { resize: vertical; }
.dock input:focus, .dock textarea:focus, .dock select:focus { outline: 2px solid var(--accent); outline-offset: 1px; }
.pgrid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 10px; }
.pgout { font: 12.5px/1.5 ui-monospace, Menlo, monospace !important; min-height: 180px; }
.prow { padding: 10px 12px; border: 1px solid rgba(255,255,255,.1); border-radius: 12px; background: rgba(255,255,255,.05); margin-bottom: 8px; }
.prow .pt { font-weight: 600; font-size: 14px; overflow-wrap: anywhere; }
.prow .pm { font-size: 12px; color: var(--muted); margin: 2px 0 6px; }
.prow .pb { white-space: pre-wrap; font: 12px/1.45 ui-monospace, Menlo, monospace; color: rgba(233,233,243,.78); max-height: 4.4em; overflow: hidden; }
.prow .pb.pbp:not(.open) { display: none; } .prow .pb.open { max-height: none; overflow: auto; } .prow .pbt { margin-top: 4px; font-size: 12px; color: var(--accent); background: none; border: 0; padding: 0; cursor: pointer; }
.prow .pa { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; } .prow .pa .gbtn { padding: 3px 10px; font-size: 12px; }
.prow .pa .danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.mcard { padding: 12px; border: 1px solid rgba(255,255,255,.1); border-radius: 14px; background: rgba(255,255,255,.05); margin-bottom: 10px; }
.mcard[data-live="1"] { border-color: rgba(94,234,212,.55); background: rgba(94,234,212,.08); }
.mcard .mt { font-weight: 650; font-size: 15px; display: flex; gap: 8px; align-items: center; }
.mcard .mt .lvb[hidden] { display: none; }
.mcard .mt .lvb { font-size: 11px; color: #042f2e; background: #5eead4; border-radius: 999px; padding: 1px 8px; font-weight: 700; }
.mcard .mw { font-size: 12.5px; color: var(--muted); margin: 3px 0 6px; }
.mcard .ma { white-space: pre-wrap; font-size: 13px; color: rgba(233,233,243,.85); margin-bottom: 8px; }
.mcard .who { display: flex; gap: 4px; margin-bottom: 8px; } .mcard .who .avatar { width: 22px; height: 22px; font-size: 11px; } .mcard .who .avatar .ring { display: none; }
.mcard .posts { display: grid; gap: 6px; margin: 8px 0; }
.mcard .post { font-size: 13px; } .mcard .post b { font-weight: 600; } .mcard .post small { color: var(--muted); margin-left: 6px; }
.mcard .pin { display: flex; gap: 6px; } .mcard .pin input { margin-top: 0; }
.mcard .pa { display: flex; gap: 6px; } .mcard .pa .gbtn { padding: 3px 10px; font-size: 12px; } .mcard .pa .danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.attl { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 8px; }
.prow .tsst { font-size: 11.5px; border-radius: 999px; padding: 1px 9px; border: 1px solid rgba(255,255,255,.18); color: rgba(233,233,243,.75); margin-left: 6px; font-weight: 600; }
.prow[data-s="pass"] { border-color: rgba(74,222,128,.4); } .prow[data-s="pass"] .tsst { color: #4ade80; border-color: rgba(74,222,128,.5); }
.prow[data-s="fail"] { border-color: rgba(248,113,113,.5); background: rgba(248,113,113,.07); } .prow[data-s="fail"] .tsst { color: #f87171; border-color: rgba(248,113,113,.55); }
.prow .tnote { font-size: 13px; color: #fecaca; white-space: pre-wrap; margin-top: 4px; }
/* Desktop planeti: söhbət tipli komanda paneli (vəziyyət zolağı, axın, yapışqan yazı qutusu) */
.xd-status { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); }
.xd-row { display: flex; align-items: center; gap: 8px; font-size: 13.5px; } .xd-row .sp { flex: 1; }
.xd-i { width: 22px; height: 22px; border-radius: 50%; border: 1px solid rgba(255,255,255,.22); background: none; color: var(--muted); font: italic 600 13px/1 Georgia, serif; cursor: pointer; flex: none; }
.xd-i.on { background: rgba(255,255,255,.14); color: #fff; }
.xd-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; } .xd-pills:empty { display: none; }
.xd-pill { font-size: 12px; border-radius: 999px; padding: 2px 10px; border: 1px solid rgba(255,255,255,.18); color: rgba(233,233,243,.8); }
.xd-pill.ok { color: #86efac; border-color: rgba(74,222,128,.45); background: rgba(74,222,128,.08); }
.xd-pill.man { color: #fcd34d; border-color: rgba(251,191,36,.45); background: rgba(251,191,36,.08); }
.xd-pill.no { color: #fca5a5; border-color: rgba(248,113,113,.45); background: rgba(248,113,113,.08); }
.xd-pill.warn { color: #fbbf24; border-color: rgba(251,191,36,.5); background: rgba(251,191,36,.1); }
.xd-note { font-size: 12.5px; color: var(--muted); margin-top: 8px; line-height: 1.45; } .xd-note[hidden] { display: none; }
.xd-lbl { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.xd-tb { border: 1px solid rgba(255,255,255,.16); background: rgba(255,255,255,.06); color: #ececf1; border-radius: 999px; padding: 4px 11px; font: inherit; font-size: 12.5px; cursor: pointer; white-space: nowrap; text-transform: none; letter-spacing: 0; }
.xd-tb.on { background: var(--accent); border-color: var(--accent); color: #fff; }
.xd-tb.danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.xd-grant { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid rgba(255,255,255,.1); border-radius: 12px; background: rgba(255,255,255,.05); margin-bottom: 6px; font-size: 13.5px; flex-wrap: wrap; } .xd-grant .sp { flex: 1; }
.xd-add { padding: 10px 12px; border-radius: 12px; border: 1px dashed rgba(255,255,255,.2); margin-top: 8px; } .xd-add[hidden] { display: none; } .xd-add .fl:first-child { margin-top: 0; }
.prow.xd-pend { border-color: rgba(251,191,36,.5); background: rgba(251,191,36,.07); }
.xd-lock { padding: 16px 14px; border-radius: 14px; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.05); margin-top: 14px; text-align: center; }
.xd-lock-t { font-weight: 600; font-size: 15px; margin-bottom: 4px; } .xd-lock .mnote { margin-bottom: 10px; }
.xd-empty { color: var(--muted); font-size: 13px; padding: 14px 12px; border: 1px dashed rgba(255,255,255,.14); border-radius: 12px; text-align: center; }
.xthread { display: grid; gap: 12px; }
.xmsg { display: grid; gap: 6px; }
.xbub { border-radius: 14px; padding: 9px 12px; font-size: 13.5px; max-width: 92%; min-width: 0; }
.xmsg.out .q { justify-self: end; background: rgba(240,138,93,.16); border: 1px solid rgba(240,138,93,.35); border-bottom-right-radius: 4px; }
.xmsg.in .q { justify-self: start; background: rgba(157,140,255,.14); border: 1px solid rgba(157,140,255,.35); border-bottom-left-radius: 4px; }
.xbub.r { justify-self: start; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); border-bottom-left-radius: 4px; }
.xmsg.in .r { justify-self: end; border-bottom-left-radius: 14px; border-bottom-right-radius: 4px; }
.xbub.r[data-s="done"] { border-color: rgba(74,222,128,.4); } .xbub.r[data-s="failed"] { border-color: rgba(248,113,113,.5); background: rgba(248,113,113,.07); } .xbub.r[data-s="canceled"] { opacity: .7; }
.xq { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.45; } .xq.clamp { display: -webkit-box; -webkit-line-clamp: 7; -webkit-box-orient: vertical; overflow: hidden; }
.xbub .xm { font-size: 11.5px; color: var(--muted); margin-top: 6px; }
.xbub .cmdimgs { margin-top: 8px; } .xbub .cmdimgs .cimg { width: 56px; height: 56px; }
.xst { display: flex; align-items: center; gap: 7px; font-size: 12.5px; color: rgba(233,233,243,.85); }
.xbub .pb { white-space: pre-wrap; font: 12px/1.45 ui-monospace, Menlo, monospace; color: rgba(233,233,243,.78); max-height: 4.4em; overflow: hidden; margin-top: 8px; }
.xbub .pb.pbp:not(.open) { display: none; } .xbub .pb.open { max-height: none; overflow: auto; }
.xbub .pbt, .prow.xd-pend .pbt { margin-top: 4px; font-size: 12px; color: var(--accent); background: none; border: 0; padding: 0; cursor: pointer; }
.xbub .pa { display: flex; gap: 6px; margin-top: 8px; } .xbub .pa .gbtn { padding: 3px 10px; font-size: 12px; } .xbub .pa .danger { color: #f87171; border-color: rgba(248,113,113,.35); }
.xd-comp { position: sticky; bottom: -14px; margin: 14px -14px -14px; padding: 10px 14px 14px; background: rgba(9,10,20,.92); -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px); border-top: 1px solid rgba(255,255,255,.1); }
.xd-comp[hidden] { display: none; }
.xd-comp .xd-prj { margin: 0 0 6px; gap: 5px; } .xd-comp .xd-prj button { padding: 3px 11px; font-size: 12.5px; }
.xd-comp textarea { margin-top: 0; min-height: 44px; max-height: 260px; resize: none; line-height: 1.4; }
.xd-comp .cmdimgs { margin-top: 8px; }
.xd-tool { display: flex; align-items: center; gap: 6px; margin-top: 8px; flex-wrap: wrap; } .xd-tool .sp { flex: 1; }
.xd-hint { font-size: 11.5px; color: var(--muted); white-space: nowrap; }
.xd-send { padding: 6px 16px; } .xd-send:disabled { opacity: .45; }
.xd-warn { font-size: 12.5px; color: #fcd34d; margin-top: 8px; } .xd-warn[hidden] { display: none; }
@media (max-width: 520px) { .xd-hint { display: none; } }
.tsbar { display: flex; height: 8px; border-radius: 99px; overflow: hidden; background: rgba(255,255,255,.08); margin: 8px 0 4px; }
.tsbar i { display: block; } .tsbar .p { background: #4ade80; } .tsbar .f { background: #f87171; }
.tdue { font-size: 11.5px; border-radius: 999px; padding: 0 8px; border: 1px solid rgba(251,191,36,.45); color: #b45309; }
.tdue[data-lv="1"], .tdue[data-lv="2"] { border-color: rgba(220,38,38,.5); color: #dc2626; font-weight: 600; }
.dock .tdue { color: #fcd34d; } .dock .tdue[data-lv="1"], .dock .tdue[data-lv="2"] { color: #fca5a5; }
@media (max-width: 520px) { .pgrid { grid-template-columns: 1fr; } }
@media (prefers-reduced-motion: reduce) { .balloon[data-lv="2"], .glabel.ushipl[data-warn="2"] span { animation: none; } }

/* Azaldılmış hərəkət: sonsuz fırlanma/sürüşmə/sıçrayış yox, yalnız yumşaq solma qalır */
@media (prefers-reduced-motion: reduce) {
  body::before, .logo::after, .dot::after, .avatar .ring, .bar::after, .typing i, .proj::after { animation: none !important; }
  [data-st="working"] > .person .ring, .stack .avatar[data-st="working"] .ring { opacity: .9; }
  .bar::after { width: 100%; opacity: .5; }
  .typing { display: none !important; }
  .proj[data-st="working"]::after { opacity: .6; }
  .enter, .row.enter { animation: fadein .25s ease backwards; }
  .stat b.bump, .row.fresh::before { animation: none; }
  .rig::after, .mm, .nebula, .stars, .orbit, .slot, .mo, .sun::before, .sun::after, .aura, .surf, .hplanet.landing, .pbox.pop, .scene.arrive { animation: none !important; }
  .shoot { display: none; }
  .space.warping .scene, .space.warping .skyz { transition: none; }
  @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }
}
</style>
</head>
<body<?= is_student(session_user()) ? ' class="student"' : '' ?>>
<div class="wrap">
<?php if (!$authed): ?>
  <form class="login card" method="post">
    <h1>Komanda Claude</h1>
    <input type="text" name="user" placeholder="İstifadəçi adı" autocomplete="username" autofocus required>
    <input type="password" name="password" placeholder="Parol" autocomplete="current-password" required>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <button type="submit">Daxil ol</button>
  </form>
<?php else: ?>
  <header>
    <div class="brand"><div class="logo">C</div><h1>Komanda Claude</h1></div>
    <nav class="tabs"><a id="tab-panel" href="#">Panel</a><a id="tab-space" href="#kainat">Kainat</a><a id="tab-board" href="#lovhe">Lövhə <span class="tbadge" id="tbadge" hidden></span></a></nav>
    <div class="meta"><span class="live" id="live"><span class="dot"></span><span id="conn">qoşulur…</span></span><span class="who" id="who"></span><button class="gbtn" id="b-adm" hidden>İstifadəçilər</button><button class="gbtn" id="b-prj" hidden>Proyektlər</button><button class="gbtn" id="b-ntf" hidden>Bildirişlər</button><button class="gbtn" id="b-plan" title="Proyektlərin planları, scope-ları və tapşırıqları (kainatdakı uçan gəmi)">🛸 Plan</button><button class="gbtn" id="b-msg" title="Komanda yoldaşına və ya hamıya mesaj (raketlə çatır)">✉ Mesaj <span class="tbadge" id="mbadge" hidden></span></button><button class="gbtn" id="b-rep">📊 Hesabat</button><a class="gbtn" href="kit.php?doc=1" target="_blank" rel="noopener" title="Addım-addım başlanğıc təlimatı">📖 Təlimat</a><a class="gbtn" href="kit.php" title="Quraşdırma paketi (hook, agent, təlimat)">📦 Paket</a><button class="gbtn" id="b-acc">Hesab</button><a href="?logout=1">Çıxış</a></div>
  </header>

  <div class="alerts" id="alerts" hidden></div>

  <div id="v-panel">
  <div class="stats" id="stats"></div>

  <h2>Kim nə edir</h2>
  <div class="grid" id="people"></div>

  <h2>Proyektlər <span class="count" id="pcount">0</span></h2>
  <div class="filters" id="filters"></div>
  <div class="grid" id="projects"></div>
  <div class="empty" id="pempty" hidden>Hələ proyekt yoxdur. Hook quraşdırılmış proyektdə Claude Code işlət.</div>

  <h2>Canlı axın</h2>
  <div class="card feed" id="feed"></div>
  <div class="empty" id="fempty" hidden>Hələ heç nə yoxdur</div>
  </div>

  <div id="v-board" hidden>
    <div class="bbar">
      <div class="filters" id="bf-who"></div>
      <select id="bf-prj" aria-label="Proyekt"></select>
      <span class="sp"></span>
      <button class="gbtn go" id="b-newtask">＋ Yeni tapşırıq</button>
    </div>
    <div class="btoken" id="btoken" hidden>
      <span>"▶ Claude ilə" düyməsi üçün tokenini bir dəfə daxil et (bu brauzerdə qalır):</span>
      <input id="btok-in" type="password" autocomplete="off" placeholder="Token"><button class="gbtn go" id="btok-save">Təsdiq et</button>
      <span class="cmderr" id="btok-err" hidden></span>
    </div>
    <div class="bmsg" id="bmsg" hidden></div>
    <div class="bcols" id="bcols"></div>
  </div>

  <div id="v-space" hidden>
    <div class="space" id="space">
      <div class="hud"><span id="hudA"></span><span id="hudB"></span></div>
      <div class="maphint" id="maphint">🖱 Sürüklə: 360° fırlat · sağ düymə: sürüşdür · təkər: yaxınlaş/uzaqlaş · qalaktikaya klik et</div>
      <div class="stools"><button id="t-ship" title="Öz gəmini fərdiləşdir və deadline təyin et">🚀 Gəmim</button><button id="t-world" title="Özünə planet yarat">🪐 Yeni planet</button><button id="t-mcp" title="MCP inteqrasiyaları üçün peyklər">🛰 MCP peykləri</button></div>
      <button class="fsbtn" id="fs" title="Tam ekran" aria-label="Tam ekran">⛶</button>
      <div class="zbtns"><button id="zin" aria-label="Yaxınlaşdır">+</button><button id="zout" aria-label="Uzaqlaşdır">−</button><button id="zmap" aria-label="Kosmik xəritə" title="Bütün universlərin xəritəsi">🗺</button><button id="sbtn" aria-label="Səs" title="Səs">🔊</button><button id="qbtn" aria-label="Təsvir keyfiyyəti" title="Təsvir keyfiyyəti: Avto / HD (həmişə tam kəskinlik)">HD</button></div>
      <div class="scene" id="scene">
        <div class="cam"><div class="rig">
          <div id="orbits"></div>
          <div class="sun"><div class="sunlbl">Komanda</div></div>
        </div></div>
      </div>
      <div class="travel" id="travel"></div>
      <aside class="dock" id="dock" aria-label="Proyekt">
        <div class="dtop">
          <button class="ibtn" id="pback" title="Kainata qayıt" aria-label="Kainata qayıt">←</button>
          <div class="dtitle" id="dtitle"></div>
          <div class="dnav"><button class="ibtn" id="pprev" hidden aria-label="Əvvəlki planet">‹</button><button class="ibtn" id="pnext" hidden aria-label="Növbəti planet">›</button></div>
        </div>
        <div class="dsub"><span class="dot" id="ddot"></span><span id="dlabel"></span><span class="dmeta" id="dmeta"></span></div>
        <div class="dinfo" id="dinfo"></div>
        <div class="dtabs" role="tablist">
          <button class="on" id="dtab-send" role="tab">Göndər</button>
          <button id="dtab-live" role="tab">Canlı <span class="count" id="pscount">0</span></button>
        </div>
        <div id="pane-send">
          <div class="agentline"><span class="agentst" id="agentst"></span></div>
          <div class="cmdbox">
            <div class="tokrow" id="tokrow" hidden>
              <input id="tokin" type="password" autocomplete="off" placeholder="Öz tokenin (bir dəfə, bu brauzerdə qalır)">
              <button class="gbtn" id="toksave">Təsdiq et</button>
            </div>
            <div id="cmdmain" hidden>
              <textarea id="cmdin" rows="3" maxlength="4000" placeholder="Bu proyektdə nə etsin? (Enter göndərir, Shift+Enter yeni sətir; şəkli yapışdır və ya bura sürüşdür)"></textarea>
              <div class="cmdimgs" id="cmdimgs" hidden></div>
              <input type="file" id="cmdfile" accept="image/png,image/jpeg,image/gif,image/webp" multiple hidden>
              <div class="cmdbar"><span class="cmdwho" id="cmdwho"></span><span style="display:flex;gap:8px"><button class="gbtn" id="cmdatt" title="Şəkil əlavə et (ən çox 4)">🖼 Şəkil</button><button class="gbtn go" id="cmdsend">Göndər</button></span></div>
            </div>
            <div class="agentrow" id="agentrow" hidden>
              <a class="gbtn go" id="agentstart" href="claudeteam://start">▶ Agenti işə sal</a>
              <span class="agenthint" id="agenthint">Düymə işləmirsə, kompüterdə bir dəfə: <code>bash agent/install-launcher.sh</code></span>
            </div>
            <div class="loginhint" id="loginhint" hidden>Agent onlayn, amma <b>Claude girişi yoxdur</b>, tapşırıqlar növbədə gözləyir. Terminalda bir dəfə <code>~/.local/bin/claude</code> yaz və <code>/login</code> et.</div>
            <div class="cmderr" id="cmderr" hidden></div>
            <div class="dlist" id="cmdlist"></div>
          </div>
        </div>
        <div id="pane-live" hidden>
          <div class="dlist" id="psess"></div>
          <div class="empty" id="psempty" hidden>İndi aktiv sessiya yoxdur. Keçmiş fəaliyyət aşağıdakı axındadır.</div>
          <div class="lbl">Son hadisələr</div>
          <div class="card feed" id="pfeed"></div>
          <div class="empty" id="pfempty" hidden>Hələ heç nə yoxdur</div>
        </div>
      </aside>
      <aside class="dock" id="plandock" aria-label="Plan stansiyası">
        <div class="dtop">
          <button class="ibtn" id="plback" title="Kainata qayıt" aria-label="Kainata qayıt">←</button>
          <div class="dtitle">🛸 Plan stansiyası</div>
        </div>
        <div class="dsub"><span class="dmeta" id="plmeta">Proyekt seç: plan, scope və tapşırıqlar</span></div>
        <div class="filters plchips" id="pl-projects"></div>
        <div class="empty" id="plempty" hidden>Hələ proyekt yoxdur. Hook quraşdırılmış proyektdə Claude Code işlət və ya aşağıdan plan əlavə et.</div>
        <div id="pl-body"></div>
        <div class="cmdbar" style="margin-top:14px"><span class="cmdwho">Yeni proyekt üçün plan: adı özün yaz</span><button class="gbtn" id="pl-newproj">+ Başqa proyekt</button></div>
      </aside>
      <aside class="dock" id="xdock" aria-label="Xüsusi planet">
        <div class="dtop">
          <button class="ibtn" id="xback" title="Kainata qayıt" aria-label="Kainata qayıt">←</button>
          <div class="dtitle" id="xtitle"></div>
        </div>
        <div class="dsub"><span class="dmeta" id="xmeta"></span></div>
        <div id="x-prompt" hidden>
          <div class="lbl">✨ Prompt generatoru</div>
          <div class="pgrid">
            <div><label class="fl" for="pg-role">Rol</label><select id="pg-role"></select></div>
            <div><label class="fl" for="pg-lang">Dil</label><select id="pg-lang"><option value="az">Azərbaycan</option><option value="en">English</option><option value="ru">Русский</option></select></div>
          </div>
          <input id="pg-role-c" maxlength="120" placeholder="Öz rolunu yaz" hidden>
          <label class="fl" for="pg-goal">Məqsəd / tapşırıq</label>
          <textarea id="pg-goal" rows="3" maxlength="2000" placeholder="AI nə etməlidir? Məs: Neo üçün lead skorinq qaydalarını yoxla və təkmilləşdir"></textarea>
          <label class="fl" for="pg-ctx">Kontekst (istəyə bağlı)</label>
          <textarea id="pg-ctx" rows="2" maxlength="3000" placeholder="Layihə, auditoriya, mövcud vəziyyət, fayllar…"></textarea>
          <label class="fl" for="pg-con">Məhdudiyyətlər (istəyə bağlı)</label>
          <textarea id="pg-con" rows="2" maxlength="2000" placeholder="Hər sətirdə bir qayda: nə etməməli, uzunluq, stil…"></textarea>
          <div class="pgrid">
            <div><label class="fl" for="pg-fmt">Cavabın formatı</label><select id="pg-fmt"></select></div>
            <div><label class="fl" for="pg-tone">Ton</label><select id="pg-tone"></select></div>
          </div>
          <label class="fl" for="pg-ex">Nümunə (istəyə bağlı)</label>
          <textarea id="pg-ex" rows="2" maxlength="2000" placeholder="Gözlədiyin cavabın kiçik nümunəsi"></textarea>
          <div class="attl"><label class="chk2"><input type="checkbox" id="pg-steps" checked> Addım-addım düşünsün</label><label class="chk2"><input type="checkbox" id="pg-ask"> Əvvəlcə aydınlaşdırıcı suallar versin</label></div>
          <div class="cmdbar"><span class="cmdwho" id="pg-note"></span><button class="gbtn go" id="pg-gen">✨ Prompt yarat</button></div>
          <textarea id="pg-out" class="pgout" rows="10" maxlength="8000" placeholder="Yaradılan prompt burada görünəcək. Əl ilə də dəyişə bilərsən."></textarea>
          <div class="pgrid"><input id="pg-title" maxlength="200" placeholder="Başlıq (kitabxana üçün)"><input id="pg-tags" maxlength="200" placeholder="Teqlər: kod, marketinq…"></div>
          <div class="cmderr" id="pg-err" hidden></div>
          <div class="cmdbar"><span class="cmdwho" id="pg-edit"></span><span style="display:flex;gap:8px"><button class="gbtn" id="pg-new" hidden>Yeni</button><button class="gbtn" id="pg-copy">Kopyala</button><button class="gbtn go" id="pg-save">Kitabxanaya saxla</button></span></div>
          <div class="lbl">📚 Prompt kitabxanası <span class="count" id="pg-cnt">0</span></div>
          <input id="pg-q" placeholder="Axtar: başlıq, teq, mətn" autocomplete="off">
          <div id="pg-list" style="margin-top:10px"></div>
          <div class="empty" id="pg-empty" hidden>Hələ prompt yoxdur. Yuxarıda yarat və saxla.</div>
        </div>
        <div id="x-designer" hidden>
          <div class="dinfo" id="ds-info"></div>
          <div class="lbl" style="display:flex;align-items:center;gap:8px">🎨 Kreativ layihələr <span class="count" id="ds-pcnt">0</span><button class="gbtn" id="ds-addp" style="margin-left:auto;padding:2px 10px;font-size:12px;text-transform:none;letter-spacing:0">+ layihə</button></div>
          <div id="ds-plans"></div>
          <div class="empty" id="ds-pempty" hidden>Hələ layihə yoxdur.</div>
          <div class="lbl" style="display:flex;align-items:center;gap:8px">✏️ Dizayn tapşırıqları <span class="count" id="ds-tcnt">0</span><button class="gbtn" id="ds-addt" style="margin-left:auto;padding:2px 10px;font-size:12px;text-transform:none;letter-spacing:0">+ tapşırıq</button></div>
          <div id="ds-tasks"></div>
          <div class="empty" id="ds-tempty" hidden>Dizayn tapşırığı yoxdur.</div>
        </div>
        <div id="x-test" hidden>
          <div class="lbl">Hansı proyekti test edirik?</div>
          <div class="filters" id="ts-prjs"></div>
          <div class="empty" id="ts-noprj" hidden>Hələ proyekt yoxdur.</div>
          <div id="ts-body" hidden>
            <div class="infobox" id="ts-sum"></div>
            <div class="lbl" style="display:flex;align-items:center;gap:8px">📋 Test ssenariləri <span class="count" id="ts-cnt">0</span></div>
            <div id="ts-list"></div>
            <div class="empty" id="ts-empty" hidden>Bu proyektdə hələ test ssenarisi yoxdur. Aşağıdan əlavə et.</div>
            <input id="ts-ttl" maxlength="200" placeholder="Yeni ssenari: nəyi yoxlayırıq? (məs. Girişdə yanlış parol xəta göstərir)" autocomplete="off">
            <textarea id="ts-steps" rows="2" maxlength="3000" placeholder="Addımlar və gözlənilən nəticə (istəyə bağlı)"></textarea>
            <div class="cmderr" id="ts-err" hidden></div>
            <div class="cmdbar"><span class="cmdwho" id="ts-hint"></span><span style="display:flex;gap:8px"><button class="gbtn" id="ts-cancel" hidden>Ləğv et</button><button class="gbtn go" id="ts-add">+ Ssenari</button></span></div>
            <div class="lbl">🤖 Claude ilə test et</div>
            <div class="mnote">Prompt sənin agentinə gedir və bu proyektin qovluğunda işləyir. Agent kodu oxuyur, test yazır və səhvləri bildirir; terminal əmrlərini (məs. <code>npm test</code>) işlətmir.</div>
            <textarea id="ts-ai" rows="5" maxlength="4000"></textarea>
            <div class="cmderr" id="ts-aierr" hidden></div>
            <div class="cmdbar"><span class="cmdwho" id="ts-aiwho"></span><button class="gbtn go" id="ts-aisend">🧪 Agentə göndər</button></div>
            <div id="ts-cmds"></div>
          </div>
        </div>
        <div id="x-mcpu" hidden><div id="xm-info"></div></div>
        <div id="x-agentu" hidden><div id="xa-info"></div></div>
        <div id="x-deskt" hidden><div id="xd-info"></div></div>
        <div id="x-meeting" hidden>
          <div class="cmdbar" style="margin-top:10px"><span class="cmdwho">Neytral ərazi: heç kimin proyekti deyil. Görüş vaxtı iştirakçıların gəmiləri bura uçur.</span><button class="gbtn go" id="mt-add">+ Görüş təyin et</button></div>
          <div class="lbl">🔴 İndi gedir</div><div id="mt-live"></div><div class="empty" id="mt-live-e">Hazırda görüş yoxdur.</div>
          <div class="lbl">📅 Növbəti görüşlər</div><div id="mt-next"></div><div class="empty" id="mt-next-e">Planlanmış görüş yoxdur.</div>
          <div class="lbl">🕘 Keçmiş (14 gün)</div><div id="mt-past"></div><div class="empty" id="mt-past-e">Yoxdur.</div>
        </div>
      </aside>
      <div class="sempty" id="sempty" hidden>Hələ heç bir dünya yoxdur. Hook quraşdırılmış proyektdə Claude Code işlət, ilk planet yaranacaq.</div>
    </div>
  </div>


<div class="modal" id="adm" hidden>
  <div class="mbox">
    <div class="mhead"><b>İstifadəçilər</b><button class="ibtn" data-close="adm" aria-label="Bağla">✕</button></div>
    <div id="adm-list" class="ulist"></div>
    <div class="madd"><input id="adm-name" maxlength="24" placeholder="Yeni istifadəçinin adı" autocomplete="off"><select id="adm-role" aria-label="İstifadəçi tipi"><option value="member">Üzv</option><option value="student">🎓 Student</option></select><button class="gbtn go" id="adm-add">Əlavə et</button></div>
    <div class="cmderr" id="adm-err" hidden></div>
    <div class="mres" id="adm-res" hidden>
      <div class="mnote" id="adm-note"></div>
      <textarea id="adm-msg" readonly rows="14"></textarea>
      <div class="cmdbar"><span class="cmdwho">Bu mətn yalnız indi göstərilir. Parol və token sonra bərpa olunmur.</span><button class="gbtn go" id="adm-copy">Kopyala</button></div>
    </div>
  </div>
</div>
<div class="modal" id="pl" hidden>
  <div class="mbox small">
    <div class="mhead"><b id="pl-title">Yeni plan</b><button class="ibtn" data-close="pl" aria-label="Bağla">✕</button></div>
    <div class="filters" id="pl-kind"><button data-k="plan" class="on">🗺 Plan</button><button data-k="scope">🎯 Scope</button></div>
    <input id="pl-prj" list="pl-prjs" maxlength="128" placeholder="Proyekt" autocomplete="off"><datalist id="pl-prjs"></datalist>
    <input id="pl-ttl" maxlength="200" placeholder="Başlıq" autocomplete="off">
    <textarea id="pl-det" rows="5" maxlength="3000" placeholder="Ətraflı (istəyə bağlı): məqsəd, addımlar, nəyə daxildir və daxil deyil"></textarea>
    <div class="cmderr" id="pl-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="pl-hint"></span><button class="gbtn go" id="pl-save">Saxla</button></div>
  </div>
</div>
<div class="modal" id="msg" hidden>
  <div class="mbox">
    <div class="mhead"><b>Mesaj</b><button class="ibtn" data-close="msg" aria-label="Bağla">✕</button></div>
    <div class="filters" id="msg-tabs"><button id="mt-write" class="on">Yaz</button><button id="mt-inbox">Qutu <span class="count" id="mt-cnt">0</span></button></div>
    <div id="msg-write">
      <select id="msg-to" aria-label="Kimə"></select>
      <textarea id="msg-body" rows="4" maxlength="500" placeholder="Mesajın mətni (Cmd/Ctrl+Enter ilə göndər)"></textarea>
      <div class="cmderr" id="msg-err" hidden></div>
      <div class="cmdbar"><span class="cmdwho" id="msg-hint"></span><button class="gbtn go" id="msg-send">🚀 Raketlə göndər</button></div>
    </div>
    <div id="msg-inbox" class="ulist" hidden></div>
  </div>
</div>
<div class="mtoasts" id="mtoasts" aria-live="polite"></div>
<div class="modal" id="rep" hidden>
  <div class="mbox">
    <div class="mhead"><b id="rep-title">Hesabat</b><button class="ibtn" data-close="rep" aria-label="Bağla">✕</button></div>
    <div class="filters" id="rep-days"></div>
    <div id="rep-body" class="ulist"></div>
    <div class="cmdbar" id="rep-foot" hidden><span class="cmdwho" id="rep-note"></span><button class="gbtn go" id="rep-send">Telegram-a göndər</button></div>
  </div>
</div>
<div class="modal" id="ntf" hidden>
  <div class="mbox">
    <div class="mhead"><b>Bildirişlər (Telegram)</b><button class="ibtn" data-close="ntf" aria-label="Bağla">✕</button></div>
    <div class="mnote">Komanda qrupuna bildiriş gedir: yeni tapşırıq, yoxlamaya hazır, bitdi, fayl toqquşması və gündəlik hesabat. Bir dəfə qur:</div>
    <ol class="mnote" style="padding-left:18px;margin:6px 0 10px;line-height:1.6">
      <li>Telegram-da <b>@BotFather</b>-ə <code>/newbot</code> yaz, ad ver, sənə <b>bot tokeni</b> verəcək.</li>
      <li>Yeni qrup yarat, botu və komanda yoldaşlarını əlavə et. Qrupa bir mesaj yaz.</li>
      <li>Brauzerdə <code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code> aç, cavabda <code>"chat":{"id":-100…}</code> sətrindəki rəqəm <b>chat ID</b>-dir.</li>
    </ol>
    <input id="ntf-tok" type="password" autocomplete="off" placeholder="Bot tokeni">
    <input id="ntf-chat" autocomplete="off" placeholder="Chat ID (məsələn -1001234567890)">
    <div class="mnote" id="ntf-state"></div>
    <div class="cmderr" id="ntf-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="ntf-ok"></span><span style="display:flex;gap:8px"><button class="gbtn danger" id="ntf-clear">Sil</button><button class="gbtn" id="ntf-test">Test göndər</button><button class="gbtn go" id="ntf-save">Saxla</button></span></div>
  </div>
</div>
<div class="modal" id="tk" hidden>
  <div class="mbox small">
    <div class="mhead"><b>Yeni tapşırıq</b><button class="ibtn" data-close="tk" aria-label="Bağla">✕</button></div>
    <input id="tk-title" maxlength="200" placeholder="Başlıq (nə edilməlidir?)" autocomplete="off">
    <textarea id="tk-det" rows="4" maxlength="3000" placeholder="Ətraflı (istəyə bağlı). Claude bunu prompt kimi alacaq."></textarea>
    <input id="tk-prj" list="tk-prjs" maxlength="128" placeholder="Proyekt" autocomplete="off"><datalist id="tk-prjs"></datalist>
    <select id="tk-who" aria-label="Kimə"></select>
    <label class="fl" for="tk-due">Son tarix (istəyə bağlı): yaxınlaşanda kainatda xatırlatma balonu çıxır</label>
    <input id="tk-due" type="datetime-local">
    <div class="cmderr" id="tk-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="tk-hint"></span><button class="gbtn go" id="tk-save">Yarat</button></div>
  </div>
</div>
<div class="modal" id="prj" hidden>
  <div class="mbox">
    <div class="mhead"><b>Proyektlər</b><button class="ibtn" data-close="prj" aria-label="Bağla">✕</button></div>
    <div class="mnote">Adı dəyişmək yalnız saytda göstərilən adı dəyişir, kompüterdəki qovluq adı toxunulmaz qalır. Silmək proyektin bütün tarixçəsini silir (hook hələ qurulubsa, yeni fəaliyyətdə proyekt yenidən yaranar).</div>
    <div id="prj-list" class="ulist"></div>
    <div class="cmderr" id="prj-err" hidden></div>
  </div>
</div>
<div class="modal" id="acc" hidden>
  <div class="mbox small">
    <div class="mhead"><b>Hesab</b><button class="ibtn" data-close="acc" aria-label="Bağla">✕</button></div>
    <div class="mnote" id="acc-who"></div>
    <input id="acc-cur" type="password" placeholder="Cari parol" autocomplete="current-password">
    <input id="acc-new" type="password" placeholder="Yeni parol (ən azı 8 simvol)" autocomplete="new-password">
    <div class="cmderr" id="acc-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="acc-ok"></span><button class="gbtn go" id="acc-save">Parolu dəyiş</button></div>
    <div class="mnote" style="margin-top:16px;border-top:1px solid var(--line);padding-top:12px"><b>🎵 Mənim musiqim</b><br>Sən işləyəndə planetdə çalır, kamera yaxınlaşdıqca ucalır. MP3, ən çox 8 MB. Yenisini yükləsən köhnəni əvəz edir.</div>
    <audio id="snd-prev" controls preload="none" hidden style="width:100%;margin-top:8px"></audio>
    <input id="snd-file" type="file" accept=".mp3,audio/mpeg">
    <div class="cmderr" id="snd-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="snd-state"></span><span style="display:flex;gap:8px"><button class="gbtn danger" id="snd-del" hidden>Standarta qayıt</button><button class="gbtn go" id="snd-up">Yüklə</button></span></div>
  </div>
</div>
<div class="modal" id="shipm" hidden>
  <div class="mbox small">
    <div class="mhead"><b id="sh-title">🚀 Mənim gəmim</b><button class="ibtn" data-close="shipm" aria-label="Bağla">✕</button></div>
    <div id="sh-view" class="mnote" hidden></div>
    <div id="sh-edit">
      <label class="fl" for="sh-name">Gəminin adı</label>
      <input id="sh-name" maxlength="60" autocomplete="off">
      <label class="fl">Model</label>
      <div class="filters" id="sh-model"><button data-m="shuttle">🚀 Şatl</button><button data-m="saucer">🛸 Boşqab</button><button data-m="fighter">✈️ Qırıcı</button></div>
      <div class="crow"><input type="color" id="sh-color" aria-label="Gövdə rəngi"><span class="mnote">Gövdə</span><input type="color" id="sh-accent" aria-label="Qanad və zolaq rəngi"><span class="mnote">Qanad/zolaq</span></div>
      <div class="mnote" style="margin-top:14px;border-top:1px solid var(--line);padding-top:12px"><b>⏰ Deadline</b><br>24 saatdan az qalanda gəminin üstündə miqalka yanıb-sönür, vaxt keçəndə qırmızı çaxır.</div>
      <input id="sh-dl" type="datetime-local">
      <input id="sh-mission" maxlength="200" placeholder="Missiya: nəyə görə deadline? (məs. Neo v2 buraxılışı)" autocomplete="off">
      <div class="cmderr" id="sh-err" hidden></div>
      <div class="cmdbar"><span class="cmdwho" id="sh-ok"></span><span style="display:flex;gap:8px"><button class="gbtn danger" id="sh-dlclear">Deadline-ı sil</button><button class="gbtn go" id="sh-save">Saxla</button></span></div>
    </div>
  </div>
</div>
<div class="modal" id="wm" hidden>
  <div class="mbox small">
    <div class="mhead"><b id="w-title">🪐 Yeni planet</b><button class="ibtn" data-close="wm" aria-label="Bağla">✕</button></div>
    <input id="w-name" maxlength="60" placeholder="Planetin adı (tapşırıqlar bu ada yazılır)" autocomplete="off">
    <textarea id="w-descr" rows="2" maxlength="1000" placeholder="Təsvir (istəyə bağlı)" style="font:inherit;font-size:14px"></textarea>
    <label class="fl">Səth</label>
    <div class="filters" id="w-surf"><button data-s="0">🌍 Yaşıl</button><button data-s="1">🪐 Qaz</button><button data-s="2">🌋 Lava</button><button data-s="3">🌑 Qaya</button><button data-s="4">🧊 Buz</button><button data-s="5">🏜 Səhra</button></div>
    <label class="fl" for="w-hue">Rəng çaları</label>
    <div class="crow"><input type="range" id="w-hue" min="0" max="359"><span class="swatch" id="w-sw"></span></div>
    <label class="fl">Ölçü</label>
    <div class="filters" id="w-size"><button data-z="1">Kiçik</button><button data-z="2">Orta</button><button data-z="3">Böyük</button></div>
    <div class="crow"><label class="chk2"><input type="checkbox" id="w-ring"> Halqa</label></div>
    <div class="cmderr" id="w-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho" id="w-hint">Dəyişikliklər kainatda dərhal görünür</span><span style="display:flex;gap:8px"><button class="gbtn danger" id="w-del" hidden>Sil</button><button class="gbtn go" id="w-save">Yarat</button></span></div>
  </div>
</div>
<div class="modal" id="mcpm" hidden>
  <div class="mbox">
    <div class="mhead"><b>🛰 MCP peykləri</b><button class="ibtn" data-close="mcpm" aria-label="Bağla">✕</button></div>
    <div class="mnote">Claude Code-da MCP alətləri (<code>mcp__server__tool</code>) işlədiləndə həmin proyektin planeti ətrafında peyk avtomatik yaranır; son 10 dəqiqədə işlədilibsə, yaşıl işığı yanır və planetə şüa göndərir. Burada peykləri əl ilə də qeyd edə bilərsən. Proyekt boş qalsa, peyk günəşin ətrafında fırlanır (hamı üçün).</div>
    <div class="lbl">Avtomatik tapılanlar (30 gün)</div>
    <div id="mcp-auto" class="ulist"></div>
    <div class="lbl">Qeyd olunanlar</div>
    <div id="mcp-list" class="ulist"></div>
    <div class="lbl" id="mcp-ftitle">Yeni peyk</div>
    <div class="pgrid"><input id="mcp-name" maxlength="64" placeholder="Server adı (məs. figma)" autocomplete="off"><input id="mcp-prj" list="mcp-prjs" maxlength="128" placeholder="Planet (boş = günəş)" autocomplete="off"></div><datalist id="mcp-prjs"></datalist>
    <input id="mcp-descr" maxlength="500" placeholder="Nə üçündür? (istəyə bağlı)" autocomplete="off">
    <div class="cmderr" id="mcp-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho"></span><span style="display:flex;gap:8px"><button class="gbtn" id="mcp-cancel" hidden>Ləğv et</button><button class="gbtn go" id="mcp-save">Əlavə et</button></span></div>
  </div>
</div>
<div class="modal" id="mtm" hidden>
  <div class="mbox small">
    <div class="mhead"><b id="mt-title">🤝 Görüş təyin et</b><button class="ibtn" data-close="mtm" aria-label="Bağla">✕</button></div>
    <input id="mt-ttl" maxlength="200" placeholder="Mövzu" autocomplete="off">
    <textarea id="mt-agenda" rows="4" maxlength="3000" placeholder="Gündəm (istəyə bağlı)" style="font:inherit;font-size:14px"></textarea>
    <div class="pgrid">
      <div><label class="fl" for="mt-at">Başlayır</label><input id="mt-at" type="datetime-local"></div>
      <div><label class="fl" for="mt-min">Müddət</label><select id="mt-min"><option value="15">15 dəq</option><option value="30" selected>30 dəq</option><option value="45">45 dəq</option><option value="60">1 saat</option><option value="90">1,5 saat</option><option value="120">2 saat</option></select></div>
    </div>
    <label class="fl">İştirakçılar</label>
    <div class="attl" id="mt-att"></div>
    <div class="cmderr" id="mt-err" hidden></div>
    <div class="cmdbar"><span class="cmdwho"></span><button class="gbtn go" id="mt-save">Saxla</button></div>
  </div>
</div>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"}}</script>
<script type="module" src="kainat.js?v=<?= (int)@filemtime(__DIR__ . '/kainat.js') ?>"></script>
<script>
const STATUS = { working: 'işləyir', waiting: 'cavabını gözləyir', idle: 'boşdur', online: 'saytdadır', ended: 'bitdi', offline: 'oflayn' };
const EDITS = ['Edit', 'Write', 'MultiEdit', 'NotebookEdit'];
const $ = id => document.getElementById(id);
const RANK = { waiting: 3, working: 2, idle: 1, online: 0, offline: 0, ended: 0 };
let data = null, offset = 0, filter = 'all', first = true, lastOk = 0;
let view = 'panel', curPlanet = null, curShip = false, lastPlanet = null, stagger = false, quiet = false, warping = false;
const SHIPKEY = '~plan';
const primed = {};
const REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
const MAXP = 8;
function prime(id, fn) { const un = !primed[id]; stagger = quiet = un; fn(); primed[id] = true; stagger = quiet = false; }

const hue = s => { let h = 0; for (const c of s) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
const tint = u => `hsl(${hue(u)} 58% 50%)`;
const now = () => Date.now() / 1000 + offset;
function el(tag, cls, text) { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
function setText(n, t) { if (n.textContent !== t) n.textContent = t; }
function setSt(n, st) { if (n.dataset.st !== st) n.dataset.st = st; }
function setCls(n, c) { if (n.className !== c) n.className = c; }
function ago(ts) {
  const s = Math.max(0, Math.floor(now() - ts));
  if (s < 10) return 'indi';
  if (s < 60) return s + ' san əvvəl';
  if (s < 3600) return Math.floor(s / 60) + ' dəq əvvəl';
  if (s < 86400) return Math.floor(s / 3600) + ' saat əvvəl';
  return Math.floor(s / 86400) + ' gün əvvəl';
}
const clock = ts => new Date(ts * 1000).toLocaleTimeString('az', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
function eff(s) {
  if (s.status === 'ended') return 'ended';
  const quiet = now() - s.updated_at;
  if (quiet > 900) return 'offline';                               // 15 dəq səssizlik = oflayn
  return s.status === 'working' && quiet > 420 ? 'idle' : s.status; // 7 dəq heç nə gəlməyibsə, çox güman dayandırılıb
}
function avatar(u) {
  const a = el('div', 'avatar'); a.style.background = tint(u);
  a.append(el('span', 'ring'), el('span', null, u[0].toUpperCase()));
  return a;
}

/* Açarlı yeniləmə: mövcud elementlər yerində qalır ki, sonsuz animasiyalar kəsilməsin */
function sync(parent, items, key, make, update, opts) {
  const have = new Map();
  for (const c of parent.children) have.set(c.dataset.k, c);
  let prev = null, i = 0;
  for (const it of items) {
    const k = String(key(it));
    let n = have.get(k);
    if (n) have.delete(k);
    else {
      n = make(it); n.dataset.k = k;
      if (!opts || opts.enter !== false) { n.classList.add('enter'); n.style.setProperty('--i', stagger ? Math.min(i, 10) : 0); }
    }
    update(n, it);
    const ref = prev ? prev.nextSibling : parent.firstChild;
    if (n !== ref) parent.insertBefore(n, ref);
    prev = n; i++;
  }
  have.forEach(n => n.remove());
}

/* ---- Statistika ---- */
const statDefs = [['working', 'İndi işləyir'], ['active', 'Aktiv sessiya'], ['edits', 'Dəyişiklik (24 saat)'], ['projects', 'Proyekt (30 gün)']];
function renderStats(v) {
  const box = $('stats');
  if (!box.children.length) for (const [k, label] of statDefs) {
    const s = el('div', 'stat'); s.dataset.k = k; s.append(el('b', null, '0'), el('span', null, label)); box.append(s);
  }
  for (const s of box.children) {
    const b = s.firstChild, val = String(v[s.dataset.k]);
    if (b.textContent !== val) {
      const initial = b.textContent === '0' && first;
      b.textContent = val;
      if (!initial) { b.classList.remove('bump'); void b.offsetWidth; b.classList.add('bump'); }
    }
  }
}

/* ---- Adamlar ---- */
function makePerson(u) {
  const n = el('article', 'card');
  const p = el('div', 'person'), av = avatar(u), info = el('div');
  const chip = el('div', 'chip'), dot = el('span', 'dot'), label = el('span');
  chip.append(dot, label); info.append(el('div', 'pname', u), chip); p.append(av, info);
  const list = el('div'), empty = el('div', 'empty', 'Hazırda aktiv sessiya yoxdur');
  n.append(p, list, empty);
  n._r = { dot, label, list, empty };
  return n;
}
function makeSession(sess) {
  const n = el('div', 'sess');
  const head = el('div', 'shead'), dot = el('span', 'dot'), proj = el('span');
  head.append(dot, proj);
  const stop = el('button', 'gbtn sstop', 'Bağla'); stop.title = 'Bu kartı bağla (ilişib qalıbsa)';
  stop.onclick = async () => { await CTL('stop_session', { user: sess.user, session_id: sess.session_id }); load(); };
  head.append(stop);
  const prompt = el('div', 'sprompt'), line = el('div', 'sline'), sum = el('span', 't');
  const typing = el('span', 'typing'); typing.append(el('i'), el('i'), el('i'));
  line.append(typing, sum);
  const chips = el('div', 'chips'), a = el('span'), e = el('span'), t = el('span');
  chips.append(a, e, t);
  n.append(head, prompt, line, el('div', 'bar'), chips);
  n._r = { dot, proj, prompt, sum, a, e, t, stop };
  return n;
}
function isOnline(u) { return (data.presence || []).some(p => p.user === u && now() - p.last_seen < 25); }
function renderPeople() {
  sync($('people'), data.users, u => u, makePerson, (n, u) => {
    const r = n._r;
    const mine = data.sessions.filter(s => s.user === u).map(s => ({ ...s, st: eff(s) })).filter(s => RANK[s.st] > 0);
    const top = mine.reduce((m, s) => Math.max(m, RANK[s.st]), 0);
    const st = top === 0 && isOnline(u) ? 'online' : ['offline', 'idle', 'working', 'waiting'][top];
    setSt(n, st); setCls(r.dot, 'dot ' + st); setText(r.label, STATUS[st]);
    r.empty.hidden = mine.length > 0;
    sync(r.list, mine, s => s.session_id, makeSession, (sn, s) => {
      const q = sn._r;
      setSt(sn, s.st); setCls(q.dot, 'dot ' + s.st); q.stop.hidden = !canStopSession(s.user);
      setText(q.proj, s.project);
      setText(q.prompt, s.last_prompt ? '“' + s.last_prompt + '”' : ''); q.prompt.hidden = !s.last_prompt;
      setText(q.sum, s.last_summary);
      setText(q.a, ago(s.updated_at)); setText(q.e, s.edits + ' dəyişiklik'); setText(q.t, s.tool_count + ' əməliyyat');
    });
  });
}

/* ---- Proyektlər ---- */
function buildProjects() {
  const m = new Map();
  for (const r of data.projects) {
    let p = m.get(r.project);
    if (!p) { p = { name: r.project, users: new Map(), edits: 0, tools: 0, sessions: 0, last: 0, st: 'offline', summary: '', sumAt: 0 }; m.set(r.project, p); }
    p.users.set(r.user, { st: 'offline' });
    p.edits += +r.edits; p.tools += +r.tools; p.sessions += +r.sessions; p.last = Math.max(p.last, +r.last_at);
  }
  for (const s of data.sessions) {
    const p = m.get(s.project); if (!p) continue;
    const st = eff(s), u = p.users.get(s.user);
    if (u && RANK[st] > RANK[u.st]) u.st = st;
    if (RANK[st] > RANK[p.st]) p.st = st;
    if (s.updated_at >= p.sumAt) { p.sumAt = s.updated_at; p.summary = s.user + ' · ' + s.last_summary; }
  }
  return [...m.values()].sort((a, b) => b.last - a.last);
}
function makeProject() {
  const n = el('article', 'card proj');
  const head = el('div', 'pjhead'), title = el('div', 'pjtitle'), dot = el('span', 'dot'), name = el('span', 'pjname');
  title.append(dot, name);
  const stack = el('div', 'stack');
  head.append(title, stack);
  const sum = el('div', 'pjsum');
  const foot = el('div', 'pjfoot'), nums = el('div', 'pjnums'), a = el('span'), e = el('span'), t = el('span'), s = el('span');
  nums.append(s, e, t); foot.append(a, nums);
  n.append(head, sum, foot);
  n._r = { dot, name, stack, sum, a, e, t, s };
  n.tabIndex = 0; n.setAttribute('role', 'link');
  n.onclick = () => go(n.dataset.k);
  n.onkeydown = ev => { if (ev.key === 'Enter') go(n.dataset.k); };
  return n;
}
function renderProjects() {
  const all = buildProjects();
  $('pcount').textContent = all.length;
  const list = all.filter(p => filter === 'all' || p.users.has(filter)).slice(0, 12);
  $('pempty').hidden = list.length > 0;
  sync($('projects'), list, p => p.name, makeProject, (n, p) => {
    const r = n._r;
    setSt(n, p.st); setCls(r.dot, 'dot ' + p.st);
    setText(r.name, p.name);
    setText(r.sum, p.st === 'offline' || p.st === 'ended' ? '' : p.summary);
    setText(r.a, ago(p.last));
    setText(r.s, p.sessions + ' sessiya'); setText(r.e, p.edits + ' dəyişiklik'); setText(r.t, p.tools + ' əməliyyat');
    sync(r.stack, [...p.users.entries()], ([u]) => u, ([u]) => avatar(u), (an, [u, v]) => { setSt(an, v.st); an.title = u; });
  });
}
function renderFilters() {
  sync($('filters'), ['all', ...data.users], f => f, f => {
    const b = el('button', null, f === 'all' ? 'Hamısı' : f);
    b.onclick = () => { filter = f; render(); };
    return b;
  }, (b, f) => setCls(b, f === filter ? 'on' : ''));
}

/* ---- Canlı axın ---- */
function rowKind(e) {
  if (e.event === 'UserPromptSubmit') return 'k-prompt';
  if (e.event === 'Stop') return 'k-stop';
  if (e.event === 'Notification') return 'k-wait';
  if (EDITS.includes(e.tool)) return 'k-edit';
  return '';
}
function makeRow(e) {
  const n = el('div', 'row ' + rowKind(e));
  const s = el('div', 's'), u = el('span', 'u', e.user); u.style.color = tint(e.user);
  s.append(el('i', 'k'), u, document.createTextNode(e.summary + ' '), el('small', null, '· ' + e.project));
  n.append(el('div', 't', clock(e.created_at)), s);
  if (!quiet) n.classList.add('fresh');
  return n;
}
function renderFeedInto(box, empty, evs, id, limit) {
  evs = evs.slice(0, limit || 40);
  empty.hidden = evs.length > 0; box.hidden = evs.length === 0;
  prime(id, () => sync(box, evs, e => e.id, makeRow, () => {}));
}

/* ====== Kainat: planetlər ====== */
function stars(n, w, h, glow) {
  const a = [];
  for (let i = 0; i < n; i++)
    a.push(`${(Math.random() * w - 60).toFixed(0)}px ${(Math.random() * h - 60).toFixed(0)}px 0 ${glow ? '.6px' : '0'} rgba(255,255,255,${(.35 + Math.random() * .65).toFixed(2)})`);
  return a.join(',');
}
function makeSky(box, w, h, k) {
  const z = el('div', 'skyz'); z.append(el('div', 'nebula'));
  for (const [n, c] of [[90, 's1'], [38, 's2'], [10, 's3']]) {
    const d = el('div', 'stars ' + c); d.style.boxShadow = stars(Math.round(n * k), w, h, c === 's3'); z.append(d);
  }
  for (let i = 0; i < 2; i++) {
    const t = el('div', 'shoot');
    t.style.setProperty('--sx', (8 + Math.random() * 50) + '%'); t.style.setProperty('--sy', (4 + Math.random() * 35) + '%');
    t.style.setProperty('--sd', (i * 6 + Math.random() * 4) + 's'); z.append(t);
  }
  box.prepend(z);
}
function makePlanet(h) {
  const p = el('div', 'planet'); p.style.setProperty('--h', h);
  const body = el('div', 'body'); body.append(el('div', 'surf'));
  p.append(el('div', 'aura'), body);
  if (h % 3 === 0) p.append(el('div', 'ringd'));
  p.append(el('div', 'moons'));
  return p;
}
function syncMoons(planet, users) {
  const box = planet.querySelector('.moons');
  sync(box, [...users.entries()], ([u]) => u, ([u]) => {
    const mo = el('div', 'mo'), mm = el('div', 'mm'), m = el('i', 'moon');
    m.style.background = tint(u); m.style.color = tint(u);
    mo.style.setProperty('--d', (-(hue(u) % 100) / 100 * 14) + 's'); mo.title = u; mm.append(m); mo.append(mm); return mo;
  }, (mo, [u, v]) => {
    setSt(mo, v.st);
    const r = String(-(22 + [...box.children].indexOf(mo) * 9));
    if (mo._r !== r) { mo._r = r; mo.style.setProperty('--r', r); }
  }, { enter: false });
}
function planetSub(p) { return RANK[p.st] > 0 ? STATUS[p.st] : ago(p.last); }

function makeOrbit(p) {
  const o = el('div', 'orbit'), sl = el('div', 'slot'), b = el('button', 'pbox pop');
  const h = hue(p.name), T = 150 + (h % 90);
  o.style.setProperty('--T', T + 's');
  o.style.setProperty('--dl', (-((h * 7) % 360) / 360 * T) + 's');
  if (stagger) b.style.setProperty('--i', h % 6);
  b.style.setProperty('--z', ((h % 7) - 3) * 0.9);
  const planet = makePlanet(h), label = el('div', 'plabel'), nm = el('b'), sub = el('span');
  label.append(nm, sub); b.append(planet, label); sl.append(b); o.append(sl);
  b.onclick = () => { if (dragged) { dragged = false; return; } warp(o.dataset.k, b); };
  o._r = { b, planet, nm, sub };
  return o;
}
function renderSpace() {
  const all = buildProjects();
  // bütün universlər eyni kosmik xəritədədir: hamısının planetləri birlikdə göndərilir
  const full = spaceList(all), lists = {}, unis = visUnis();
  for (const u of UNIVERSES) lists[u.id] = unis.includes(u) ? uniList(full, u.id) : [];
  const everything = unis.flatMap(u => lists[u.id].map(p => ({ ...p, uni: u.id })));
  const names = new Set(everything.map(p => p.name));
  const here = navUni ? lists[navUni] : [];
  const sums = {
    main: [all.length + ' proyekt', all.filter(p => p.st === 'working').length + ' işləyir'],
    studio: [lists.studio.length + ' stansiya', lists.studio.filter(p => p.st === 'working' || p.st === 'meet').length + ' aktiv'],
    mcp: [lists.mcp.length + ' MCP server', lists.mcp.filter(p => p.st === 'working').length + ' indi işləyir'],
    agent: [lists.agent.length + ' agent', (data.users || []).filter(u => agentInfo(u).online).length + ' onlayn'],
    desktop: [lists.desktop.length + ' kompüter', (data.users || []).filter(u => agentInfo(u).online).length + ' onlayn'],
    student: [lists.student.length + ' student planeti', (data.students || []).filter(u => (data.presence || []).some(x => x.user === u && now() - x.last_seen < 60)).length + ' saytda'] };
  const hud = navUni ? [UNIVERSES.find(u => u.id === navUni).icon + ' ' + UNIVERSES.find(u => u.id === navUni).name + ' · ' + sums[navUni][0], sums[navUni][1]] : ['🗺 Kosmik xəritə', unis.length + ' univers'];
  setText($('hudA'), hud[0]); setText($('hudB'), hud[1]);
  $('sempty').hidden = everything.length > 0;
  $('maphint').hidden = !!navUni || !!curPlanet || curShip;
  const list = K && glOn ? here : lists[isStu() ? 'student' : 'main'];
  if (K && glOn) {
    K.setHidden(UNIVERSES.filter(u => !unis.includes(u)).map(u => u.id));
    K.setUniverses(unis.map(u => ({ id: u.id, icon: u.icon, name: u.name, sub: sums[u.id].join(' · ') })));
    K.setData(everything.map(p => ({ name: p.name, uni: p.uni, label: p.label || null, kind: p.kind, look: p.look || null, hue: p.hue ?? null, R: p.R || 0, st: p.st, edits: p.edits, tools: p.tools, sub: p.sub, alert: (data.alerts || []).some(a => a.project === p.name),
      burn: p.burn, sats: p.sats, fail: !!p.fail, users: [...p.users].map(([u, v]) => ({ name: u, st: v.st, color: tint(u) })) })));
    syncHits(); K.setAudio(data.sounds || {});
    const pl = data.plans || [], op = k => pl.filter(x => x.kind === k && x.status === 'open').length;
    K.setShip({ sub: op('plan') + ' plan · ' + op('scope') + ' scope' });
    K.setShips(shipItems(names));
    K.setBalloons(balloonItems(names));
    K.setSunSats((data.mcp || []).filter(x => !x.project || !names.has(x.project)).map(x => ({ name: x.name, manual: true })));
    fireAgentRockets();
  } else {
    const step = Math.min(9, 46 / Math.max(list.length, 1));
    prime('orbits', () => sync($('orbits'), list, p => p.name, makeOrbit, (o, p) => {
      const r = o._r, d = (48 + list.indexOf(p) * step).toFixed(1);
      if (o._d !== d) { o._d = d; o.style.setProperty('--d', d); }
      const sz = (5 + Math.min(5, Math.log2(1 + p.edits + p.tools / 4) * .6)).toFixed(1);
      if (r.b._s !== sz) { r.b._s = sz; r.b.style.setProperty('--s', sz); }
      setSt(r.b, p.st); setText(r.nm, p.label || p.name); setText(r.sub, p.sub);
      syncMoons(r.planet, p.users);
    }, { enter: false }));
  }
  prime('travel', () => sync($('travel'), [...(!isStu() && (navUni === 'main' || !(K && glOn)) ? [{ name: SHIPKEY, label: '🛸 Plan' }] : []), ...list], p => p.name, p => {
    const b = el('button'), d = el('i', 'tdot'); d.style.setProperty('--h', hue(p.name)); b.append(d, el('span', null, p.label || p.name));
    b.onclick = () => go(p.name); return b;
  }, () => {}));
  if (curPlanet) { if (xKind(curPlanet)) renderX(curPlanet); else renderDock(all); }
  if (curShip) renderPlan();
}
/* ====== 3D kamera: sürüşdür = fırlat/əy, boş vaxtda yavaş dönür ====== */
const cam = { tx: 58, rz: -18, auto: !REDUCE };
let dragged = false, drag = null, lastT = 0;
function applyCam() {
  const sp = $('space');
  sp.style.setProperty('--tx', cam.tx.toFixed(2)); sp.style.setProperty('--rz', cam.rz.toFixed(2));
}
function camLoop(t) {
  const dt = Math.min(.1, (t - lastT) / 1000 || 0); lastT = t;
  if (view === 'space' && cam.auto && !drag && !warping && !document.hidden && !$('scene').querySelector('.pbox:hover')) {
    cam.rz = (cam.rz + dt * 1.2) % 360; applyCam();
  }
  requestAnimationFrame(camLoop);
}
function initCam() {
  applyCam();
  $('scene').addEventListener('pointerdown', e => { drag = { x: e.clientX, y: e.clientY, tx: cam.tx, rz: cam.rz }; dragged = false; });
  addEventListener('pointermove', e => {
    if (!drag) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (Math.abs(dx) + Math.abs(dy) > 5) dragged = true;
    if (!dragged) return;
    cam.rz = drag.rz + dx * .35; cam.tx = Math.max(38, Math.min(80, drag.tx - dy * .25)); applyCam();
  });
  addEventListener('pointerup', () => { drag = null; });
  addEventListener('pointercancel', () => { drag = null; });
  requestAnimationFrame(camLoop);
}
function warp(name, btn) {
  if (REDUCE || warping) return go(name);
  warping = true;
  const sc = $('scene'), r = btn.getBoundingClientRect(), s = sc.getBoundingClientRect();
  sc.style.transformOrigin = (r.left + r.width / 2 - s.left) + 'px ' + (r.top + r.height / 2 - s.top) + 'px';
  $('space').classList.add('warping');
  setTimeout(() => { warping = false; go(name); }, 520);
}

/* ====== Planet paneli (dock) ====== */
function makePSess(s) {
  const n = el('article', 'card sess pcard');
  const head = el('div', 'shead'), nm = el('span', null, s.user), st = el('span', 'st'), dot = el('span', 'dot'), lab = el('span');
  st.append(dot, lab);
  const stop = el('button', 'gbtn sstop', 'Bağla'); stop.title = 'Bu kartı bağla (ilişib qalıbsa)';
  stop.onclick = async () => { await CTL('stop_session', { user: s.user, session_id: s.session_id }); load(); };
  head.append(avatar(s.user), nm, st, stop);
  const prompt = el('div', 'sprompt'), line = el('div', 'sline'), sum = el('span', 't');
  const typing = el('span', 'typing'); typing.append(el('i'), el('i'), el('i')); line.append(typing, sum);
  const chips = el('div', 'chips'), a = el('span'), e = el('span'), t = el('span'); chips.append(a, e, t);
  n.append(head, prompt, line, el('div', 'bar'), chips);
  n._r = { dot, lab, prompt, sum, a, e, t, stop };
  return n;
}
/* ====== Prompt göndərmə (yalnız öz maşınına) ====== */
const TKEY = 'tdtoken';
let myName = null, cmdBusy = false, agentStarting = 0;
const getTok = () => { try { return localStorage.getItem(TKEY) || ''; } catch (e) { return ''; } };
const setTok = v => { try { v ? localStorage.setItem(TKEY, v) : localStorage.removeItem(TKEY); } catch (e) {} };
async function cmdApi(action, extra) {
  const r = await fetch('command.php', { method: 'POST', headers: { 'X-Team-Token': getTok(), 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) { setTok(''); myName = null; }
  return { status: r.status, d };
}
async function identify() {
  if (!getTok()) { myName = null; return; }
  try { const { status, d } = await cmdApi('whoami'); myName = status === 200 ? d.user : null; } catch (e) { myName = null; }
}
const AGENT_TTL = 30;   // agent bu qədər saniyə səs verməyibsə oflayn sayılır (agent 10 san-dan bir ping edir, tapşırıq icrasında 3 san-dan bir poll)
const CMD_ST = { pending: ['təsdiq gözləyir', 'waiting'], queued: ['növbədə', 'offline'], running: ['işləyir', 'working'], done: ['bitdi', 'idle'], failed: ['xəta', 'waiting'], canceled: ['ləğv edildi', 'offline'] };
function makeCmd() {
  const n = el('div', 'card cmdc');
  const h = el('div', 'ch'), who = el('b'), when = el('span'), st = el('span', 'st'), dot = el('span', 'dot'), lab = el('span');
  st.append(dot, lab); h.append(who, when, st);
  const cp = el('div', 'cp'), ci = el('div', 'ci'), det = el('details'), sm = el('summary', null, 'Nəticə'), pre = el('pre');
  det.append(sm, pre); ci.hidden = true;
  const cx = el('button', 'gbtn cx', 'Ləğv et'); cx.hidden = true;
  n.append(h, cp, ci, det, cx);
  n._r = { who, when, dot, lab, cp, ci, det, pre, cx };
  return n;
}
function renderCmd() {
  if (!data) return;
  const on = !!(getTok() && myName);
  $('tokrow').hidden = on; $('cmdmain').hidden = !on;
  if (on) {
    const w = $('cmdwho');
    if (!w.dataset.n || w.dataset.n !== myName) {
      w.dataset.n = myName; w.replaceChildren(document.createTextNode(myName + ' kompüterinə · '), Object.assign(el('a', null, 'tokeni sil'), { onclick: () => { setTok(''); myName = null; renderCmd(); } }));
    }
    const ag = (data.agents || []).find(a => a.user === myName);
    const live = ag && now() - ag.last_seen < AGENT_TTL;
    const s = $('agentst'); setCls(s, 'agentst' + (live ? ' on' : ''));
    s.replaceChildren(el('i'), document.createTextNode(live ? 'agent onlayn' : 'agent oflayn'));
    s.title = live ? '' : 'Kompüterində işlət: python3 agent/claude-team-agent.py';
    if (live) agentStarting = 0;
    const noLogin = !!live && +ag.claude_ok === 0;
    $('loginhint').hidden = !noLogin;
    if (noLogin) s.replaceChildren(el('i'), document.createTextNode('agent onlayn · Claude girişi yoxdur'));
    const starting = !live && agentStarting && now() - agentStarting < 20;
    $('agentrow').hidden = !!live;
    setText($('agentstart'), starting ? 'Başladılır…' : '▶ Agenti işə sal');
    $('agentstart').style.pointerEvents = starting ? 'none' : '';
    $('agentstart').style.opacity = starting ? .6 : '';
    $('agenthint').hidden = !!starting;
  } else { const s = $('agentst'); s.replaceChildren(); setCls(s, 'agentst'); $('agentrow').hidden = true; $('loginhint').hidden = true; }
  const list = (data.commands || []).filter(c => c.project === curPlanet).slice(0, 4);
  sync($('cmdlist'), list, c => c.id, makeCmd, (n, c) => {
    const r = n._r, [lbl, dc] = CMD_ST[c.status] || [c.status, 'offline'];
    setText(r.who, c.user); setText(r.when, ago(c.created_at));
    setCls(r.dot, 'dot ' + dc); setText(r.lab, lbl);
    setText(r.cp, c.prompt);
    const imgs = c.images || [], ik = imgs.join('|');
    if (r.ci._k !== ik) {                                   // prompta əlavə şəkillər (klik: böyük aç)
      r.ci._k = ik;
      r.ci.replaceChildren(...imgs.map(f => { const a = el('a'); a.href = 'cmdimg.php?f=' + encodeURIComponent(f); a.target = '_blank'; a.rel = 'noopener'; const im = el('img'); im.src = a.href; im.alt = 'şəkil'; im.loading = 'lazy'; a.append(im); return a; }));
    }
    r.ci.hidden = !imgs.length;
    const hasRes = c.status === 'done' || c.status === 'failed';
    r.det.hidden = !hasRes; setText(r.pre, c.result || '');
    if (hasRes && !r.det._opened) { r.det._opened = true; r.det.open = c.status === 'failed' || (c.result || '').length < 400; }
    r.cx.hidden = !((c.status === 'queued' || c.status === 'running') && canStopSession(c.user));
    setText(r.cx, c.status === 'running' ? 'Dayandır' : 'Ləğv et');
    r.cx.onclick = async () => { await CTL('stop_command', { id: c.id }); load(); };
  });
}
function cmdError(t) { const e = $('cmderr'); e.hidden = !t; e.textContent = t || ''; }
/* Prompta şəkil: düymə, yapışdırma (Cmd/Ctrl+V) və ya sürüşdürmə. Böyük şəkillər brauzerdə kiçildilir ki, yükləmə limitinə düşsün. */
const CMD_IMG_MAX = 4, CMD_IMG_BYTES = 5 * 1024 * 1024, CMD_IMG_TYPES = /^image\/(png|jpeg|gif|webp)$/;
let cmdImgs = [];                                         // { file, url }
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
async function cmdAddImages(files) {
  for (let f of files) {
    if (!CMD_IMG_TYPES.test(f.type)) { cmdError('Yalnız PNG, JPEG, GIF və ya WebP şəkil'); continue; }
    if (cmdImgs.length >= CMD_IMG_MAX) { cmdError('Ən çox ' + CMD_IMG_MAX + ' şəkil'); break; }
    f = await shrinkImage(f);
    if (f.size > CMD_IMG_BYTES) { cmdError('Şəkil çox böyükdür (ən çox 5 MB)'); continue; }
    cmdImgs.push({ file: f, url: URL.createObjectURL(f) });
  }
  renderCmdImgs();
}
function cmdClearImages() { for (const im of cmdImgs) URL.revokeObjectURL(im.url); cmdImgs = []; renderCmdImgs(); }
function renderCmdImgs() {
  const box = $('cmdimgs'); box.hidden = !cmdImgs.length;
  box.replaceChildren(...cmdImgs.map(im => {
    const d = el('div', 'cimg'), img = el('img'); img.src = im.url; img.alt = im.file.name;
    const x = el('button', 'rm', '✕'); x.type = 'button'; x.title = 'Şəkli sil';
    x.onclick = () => { URL.revokeObjectURL(im.url); cmdImgs = cmdImgs.filter(z => z !== im); renderCmdImgs(); };
    d.append(img, x); return d;
  }));
}
async function sendCmd() {
  const ta = $('cmdin'), text = ta.value.trim();
  if ((!text && !cmdImgs.length) || cmdBusy || !curPlanet) return;
  if (!text) return cmdError('Şəkillə birlikdə nə etmək lazım olduğunu da yaz');
  cmdBusy = true; $('cmdsend').disabled = true; cmdError('');
  try {
    let res;
    if (cmdImgs.length) {                                  // şəkilli: FormData (JSON yox)
      const fd = new FormData(); fd.append('action', 'send'); fd.append('project', curPlanet); fd.append('prompt', text);
      for (const im of cmdImgs) fd.append('images[]', im.file, im.file.name);
      const r = await fetch('command.php', { method: 'POST', headers: { 'X-Team-Token': getTok() }, body: fd });
      res = { status: r.status, d: await r.json().catch(() => ({})) };
      if (r.status === 401) { setTok(''); myName = null; }
    } else res = await cmdApi('send', { project: curPlanet, prompt: text });
    const { status, d } = res;
    if (status === 200) { ta.value = ''; cmdClearImages(); load(); }
    else cmdError(status === 401 ? 'Token yanlışdır, yenidən daxil et.' : (d.error || 'Göndərmək alınmadı'));
  } catch (e) { cmdError('Bağlantı xətası'); }
  cmdBusy = false; $('cmdsend').disabled = false; renderCmd();
}
let dockTab = 'send';
function setTab(t) {
  dockTab = t;
  $('pane-send').hidden = t !== 'send'; $('pane-live').hidden = t !== 'live';
  $('dtab-send').classList.toggle('on', t === 'send'); $('dtab-live').classList.toggle('on', t === 'live');
}
function initCmd() {
  $('dtab-send').onclick = () => setTab('send'); $('dtab-live').onclick = () => setTab('live');
  $('cmdsend').onclick = sendCmd;
  $('agentstart').addEventListener('click', () => { agentStarting = now(); setTimeout(renderCmd, 50); });
  // Enter göndərir (Shift+Enter yeni sətir); yazı tərkibi (IME) zamanı yox
  $('cmdin').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendCmd(); } });
  $('cmdatt').onclick = () => $('cmdfile').click();
  $('cmdfile').onchange = () => { cmdAddImages([...$('cmdfile').files]); $('cmdfile').value = ''; };
  $('cmdin').addEventListener('paste', e => {
    const fs = [...((e.clipboardData && e.clipboardData.items) || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean);
    if (fs.length) { e.preventDefault(); cmdAddImages(fs); }
  });
  const ta = $('cmdin');
  ta.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); ta.classList.add('drop'); } });
  ta.addEventListener('dragleave', () => ta.classList.remove('drop'));
  ta.addEventListener('drop', e => { e.preventDefault(); ta.classList.remove('drop'); cmdAddImages([...e.dataTransfer.files]); });
  $('toksave').onclick = async () => {
    const v = $('tokin').value.trim(); if (!v) return;
    setTok(v); $('tokin').value = ''; await identify();
    if (!myName) cmdError('Token yanlışdır.'); else cmdError('');
    renderCmd();
  };
  $('tokin').addEventListener('keydown', e => { if (e.key === 'Enter') $('toksave').click(); });
  identify().then(renderCmd);
}

function renderDock(all) {
  const p = all.find(x => x.name === curPlanet) || { name: curPlanet, users: new Map(), edits: 0, tools: 0, sessions: 0, last: 0, st: 'offline', summary: '' };
  $('dock').classList.add('open');
  setText($('dtitle'), curPlanet);
  setCls($('ddot'), 'dot ' + p.st);
  setText($('dlabel'), p.last ? (RANK[p.st] > 0 ? STATUS[p.st] : 'oflayn') : 'məlumat yoxdur');
  setText($('dmeta'), p.last ? `· ${p.sessions} sessiya · ${p.edits} dəyişiklik · ${ago(p.last)}` : '');

  const names = all.map(x => x.name).sort((a, b) => a.localeCompare(b));
  const i = names.indexOf(curPlanet), prev = names[(i - 1 + names.length) % names.length], next = names[(i + 1) % names.length];
  const multi = names.length > 1 && i >= 0;
  for (const [id, nm] of [['pprev', prev], ['pnext', next]]) {
    const b = $(id); b.hidden = !multi;
    if (multi) { b.title = nm; b.onclick = () => go(nm); }
  }
  const ss = data.sessions.filter(x => x.project === curPlanet).map(x => ({ ...x, st: eff(x) }))
    .filter(x => RANK[x.st] > 0)
    .sort((a, b) => RANK[b.st] - RANK[a.st] || b.updated_at - a.updated_at);
  setText($('pscount'), String(ss.length));
  $('psempty').hidden = ss.length > 0;
  prime('psess:' + curPlanet, () => sync($('psess'), ss, x => x.session_id, makePSess, (n, x) => {
    const q = n._r; q.stop.hidden = !canStopSession(x.user);
    setSt(n, x.st); setCls(q.dot, 'dot ' + x.st); setText(q.lab, STATUS[x.st]);
    setText(q.prompt, x.last_prompt ? '“' + x.last_prompt + '”' : ''); q.prompt.hidden = !x.last_prompt;
    setText(q.sum, x.last_summary);
    setText(q.a, ago(x.updated_at)); setText(q.e, x.edits + ' dəyişiklik'); setText(q.t, x.tool_count + ' əməliyyat');
  }));
  renderFeedInto($('pfeed'), $('pfempty'), data.events.filter(e => e.project === curPlanet), 'pfeed:' + curPlanet, 8);
  renderCmd();
  renderDInfo(curPlanet);
  $('dock').classList.toggle('noproj', !all.some(x => x.name === curPlanet));   // istifadəçi planetinin qovluğu yoxdur: agent bölməsi gizli
}

/* ====== Görünüşlər və naviqasiya ====== */
function go(name) { location.hash = name ? '#kainat/' + encodeURIComponent(name) : '#kainat/@' + (curPlanet ? uniOf(curPlanet) : curShip ? 'main' : navUni || 'main'); }
let K = null, glOn = false;
function initGL() {
  if (glOn) return;
  K = window.Kainat;
  if (!K || !K.mount($('space'))) { K = null; return; }
  glOn = true;
  $('space').classList.add('gl');
  K.onPick = name => go(name);
  K.onShip = () => go(SHIPKEY);
  K.onUniverse = id => { navUni = id; render(); };
  K.onPickUni = id => { location.hash = '#kainat/@' + id; };
  $('zmap').onclick = () => { if (curPlanet || curShip) location.hash = '#kainat'; else flyTo(null); };
  if (navTarget !== undefined) { K.flyUni(navTarget, true); navTarget = undefined; }
  K.onUserShip = u => openShip(u);
  if (ME) K.setMe(ME.name);
  K.onBalloon = id => { const t = (data.tasks || []).find(x => +x.id === +id); if (t) go(t.project); };
  $('zin').onclick = () => K.zoom(.78); $('zout').onclick = () => K.zoom(1.28);
  let q = 'auto'; try { q = localStorage.getItem('tdq') === 'high' ? 'high' : 'auto'; } catch (e) {}
  const qb = $('qbtn'), showQ = () => { qb.classList.toggle('on', q === 'high'); qb.title = q === 'high' ? 'HD açıqdır: həmişə tam kəskinlik (yavaş kompüterdə donma ola bilər). Basanda Avto-ya qayıdır.' : 'Avto: kompüter çətinləşəndə kəskinliyi özü azaldır. Basanda HD (həmişə tam kəskin) açılır.'; };
  K.setQuality(q); showQ();
  let snd = true; try { snd = localStorage.getItem('tds') !== 'off'; } catch (e) {}
  const sb = $('sbtn');
  const showS = () => {
    const st = K.soundState();
    sb.textContent = snd ? '🔊' : '🔇'; sb.classList.toggle('blocked', snd && st.blocked);
    sb.title = !snd ? 'Səs söndürülüb. Basanda açılır.' : st.blocked ? 'Brauzer səsi bloklayıb: bir dəfə də klik et' : 'İstifadəçi işləyəndə onun səsi çalır: planetə nə qədər yaxınsansa, o qədər yüksək eşidilir. Basanda söndürülür.';
  };
  K.setAudio((data && data.sounds) || {}, snd); showS();
  setInterval(showS, 1000);
  sb.onclick = () => { snd = !snd; try { localStorage.setItem('tds', snd ? 'on' : 'off'); } catch (e) {} K.setSound(snd); showS(); };
  qb.onclick = () => { q = q === 'high' ? 'auto' : 'high'; try { localStorage.setItem('tdq', q); } catch (e) {} K.setQuality(q); showQ(); };
  K.setActive(view === 'space');
  if (view === 'space') { render(); K.focus(curShip ? '~ship' : curPlanet); }
}
function setView(v, name) {
  view = v;
  curShip = v === 'space' && name === SHIPKEY;
  curPlanet = v === 'space' && !curShip ? (name || null) : null;
  if (curPlanet) lastPlanet = curPlanet;
  document.body.classList.toggle('v-space', v === 'space');
  $('v-panel').hidden = v !== 'panel'; $('v-space').hidden = v !== 'space'; $('v-board').hidden = v !== 'board';
  $('tab-panel').classList.toggle('on', v === 'panel'); $('tab-space').classList.toggle('on', v === 'space'); $('tab-board').classList.toggle('on', v === 'board');
  const spc = !!(curPlanet && xKind(curPlanet));
  $('dock').classList.toggle('open', !!curPlanet && !spc);
  $('xdock').classList.toggle('open', spc);
  $('plandock').classList.toggle('open', curShip);
  $('space').classList.toggle('docked', !!curPlanet || curShip);
  $('space').classList.remove('warping'); $('scene').style.transformOrigin = '';
  if (v === 'space' && !glOn) { const sc = $('scene'); sc.classList.remove('arrive'); void sc.offsetWidth; sc.classList.add('arrive'); }
  if (K) { K.setActive(v === 'space'); K.focus(curShip ? '~ship' : curPlanet); }
  render(); load();
}
function route() {
  const [v, p] = location.hash.slice(1).split('/');
  if (isStu() && v !== 'kainat') { location.replace('#kainat/@student'); return; }   // student üçün yalnız kainat
  if (v === 'kainat') {
    const name = p ? decodeURIComponent(p) : null;
    // #kainat = bütün universlərin xəritəsi, #kainat/@mcp = universin ümumi görünüşü, #kainat/<planet> = planet
    if (name && name[0] === '@') { setView('space', null); flyTo(UNIVERSES.some(u => u.id === name.slice(1)) ? name.slice(1) : 'main'); }
    else if (name) setView('space', name);
    else { setView('space', null); flyTo(null); }
  }
  else if (v === 'lovhe') setView('board');
  else setView('panel');
}
function renderPanel() {
  const act = data.sessions.filter(s => RANK[eff(s)] > 0);
  renderStats({
    working: act.filter(s => eff(s) === 'working').length,
    active: act.length,
    edits: data.sessions.reduce((a, s) => a + (+s.edits || 0), 0),
    projects: new Set(data.projects.map(p => p.project)).size,
  });
  prime('people', renderPeople); prime('filters', renderFilters); prime('projects', renderProjects);
  renderFeedInto($('feed'), $('fempty'), data.events.filter(e => filter === 'all' || e.user === filter), 'feed');
}
function render() {
  if (!data) return;
  updateTaskBadge(); renderAlerts(); updateMsgBadge();
  if (view === 'panel') renderPanel(); else if (view === 'board') renderBoard(); else renderSpace();
  first = false;
}

async function load() {
  if (document.hidden) return;
  try {
    const q = view === 'space' && curPlanet ? (curPlanet.startsWith('mcp:') ? '?mcp=' + encodeURIComponent(curPlanet.slice(4)) : xKind(curPlanet) ? '' : '?project=' + encodeURIComponent(curPlanet)) : '';
    const r = await fetch('feed.php' + q, { cache: 'no-store' });
    if (r.status === 401) return location.reload();
    data = await r.json();
    setMe(data.me);
    fireEvents();
    fireMessages();
    offset = data.now - Date.now() / 1000;
    lastOk = Date.now();
    render();
  } catch (e) { /* növbəti cəhd 3 san sonra */ }
}
/* ====== Hesab və istifadəçi idarəsi ====== */
async function CTL(action, extra) {
  const r = await fetch('control.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  if (r.status !== 200 && typeof bmsg === 'function') bmsg(d.error || 'Alınmadı');
  return { status: r.status, d };
}
const canStopSession = u => !!(ME && (ME.name === u || ME.role === 'admin'));
async function ADM(action, extra) {
  const r = await fetch('admin.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
let ME = null;
function setMe(me) {
  if (!me || (ME && ME.name === me.name && ME.role === me.role)) return;
  ME = me; setText($('who'), me.name); if (K) K.setMe(me.name);
  if (me.role === 'student') { setText($('t-world'), '🪐 Planetim'); $('t-world').title = 'Öz planetini fərdiləşdir'; } $('b-adm').hidden = me.role !== 'admin'; $('b-prj').hidden = me.role !== 'admin'; $('b-ntf').hidden = me.role !== 'admin';
}
const openM = id => { $(id).hidden = false; };
const closeM = id => {
  $(id).hidden = true;
  if (id === 'shipm' && shipDraft) { shipDraft = null; render(); }
  if (id === 'wm' && wDraft) { wDraft = null; render(); }
};
function admErr(t) { const e = $('adm-err'); e.hidden = !t; e.textContent = t || ''; }
function welcomeText(name, token, password) {
  const url = location.origin + location.pathname.replace(/[^/]*$/, '');
  return `Salam! Komanda Claude panelinə dəvət olunmusan.

Panel: ${url}
İstifadəçi adı: ${name}
Parol: ${password}
Token (kompüterində lazım olacaq, heç kimlə paylaşma): ${token}

Başlamaq üçün:
1. Sayta daxil ol, parolu sağ yuxarıdakı "Hesab" düyməsindən dəyiş.
2. "📖 Təlimat" düyməsi addım-addım təlimatı açır, "📦 Paket" düyməsi isə quraşdırma paketini (hook və agent faylları) yükləyir. Paketi arxivdən çıxar.
3. Paketdəki BASLA.md faylını oxu və addımları izlə (təxminən 10 dəqiqə).
4. Proyekti panelə qoşmaq üçün TERMİNALDA (brauzerdə yox!) hər proyekt üçün, paketin qovluğunda:

URL='${url}ingest.php'
TOKEN='${token}'
bash hook/install.sh "$URL" "$TOKEN" /yol/proyektin/qovlugu

5. Claude Code kompüterində girişli olmalıdır:  claude auth status

Qaydalar: tokenini və parolunu paylaşma. Saytdan göndərdiyin promptlar yalnız öz kompüterində işləyir.`;
}
function showCreds(kind, d) {
  const res = $('adm-res'); res.hidden = false;
  if (kind === 'self') {
    const url = location.origin + location.pathname.replace(/[^/]*$/, '');
    $('adm-note').textContent = 'Sənin yeni giriş məlumatların. Köhnə token və parol artıq işləmir. Kompüterində konfiqurasiyanı yenilə (aşağıdakı əmri işlət), sonra agentı yenidən başlat.';
    $('adm-msg').value = `İstifadəçi adı: ${d.name}\nParol: ${d.password}\nToken: ${d.token}\n\nKompüterində (bir dəfə):\nprintf 'URL=%s\\nTOKEN=%s\\n' '${url}ingest.php' '${d.token}' > ~/.claude-team-dash.conf && chmod 600 ~/.claude-team-dash.conf\npkill -f claude-team-agent.py`;
  } else {
    $('adm-note').textContent = d.name + ' üçün göndərəcəyin mətn:';
    $('adm-msg').value = d.role === 'student'
      ? `Salam! Student Universe-ə dəvət olunmusan 🎓\n\nSayt: ${location.origin + location.pathname.replace(/[^/]*$/, '')}\nİstifadəçi adı: ${d.name}\nParol: ${d.password}\n\nDaxil ol, parolu sağ yuxarıdakı "Hesab" düyməsindən dəyiş. "🪐 Planetim" düyməsi ilə öz planetini fərdiləşdirə bilərsən.`
      : welcomeText(d.name, d.token, d.password);
  }
}
async function admRefresh() {
  const { d } = await ADM('list'); if (!d.users) return;
  const box = $('adm-list'); box.replaceChildren();
  for (const u of d.users) {
    const row = el('div', 'urow');
    row.append(el('span', 'un', u.name), el('span', 'ur', u.role === 'admin' ? 'admin' : u.role === 'student' ? '🎓 student' : 'üzv'));
    const live = u.agent_seen && d.now - u.agent_seen < AGENT_TTL;
    const st = el('span', 'us'); st.append(el('span', 'dot ' + (live ? 'idle' : '')), document.createTextNode(live ? 'agent onlayn' : u.agent_seen ? 'agent oflayn' : 'agent heç qoşulmayıb'));
    row.append(st);
    const rs = el('button', 'gbtn', 'Token/parol yenilə');
    rs.onclick = async () => {
      if (!confirm(u.name + ' üçün token və parol yenilənsin? Köhnələri işləməyəcək.')) return;
      const { status, d: r } = await ADM('reset', { name: u.name });
      if (status === 200) { admErr(''); showCreds(u.name === ME.name ? 'self' : 'user', r); admRefresh(); } else admErr(r.error || 'Alınmadı');
    };
    row.append(rs);
    if (u.role !== 'admin') {
      const del = el('button', 'gbtn danger', 'Sil');
      del.onclick = async () => {
        if (!confirm(u.name + ' silinsin? Bütün fəaliyyət tarixçəsi və tapşırıqları da silinəcək. Geri qaytarılmır.')) return;
        const { status, d: r } = await ADM('delete', { name: u.name });
        if (status === 200) { admErr(''); $('adm-res').hidden = true; admRefresh(); } else admErr(r.error || 'Alınmadı');
      };
      row.append(del);
    }
    box.append(row);
  }
}
function prjErr(t) { const e = $('prj-err'); e.hidden = !t; e.textContent = t || ''; }
async function prjRefresh() {
  const { d } = await ADM('projects'); if (!d.projects) return;
  const box = $('prj-list'); box.replaceChildren();
  if (!d.projects.length) box.append(el('div', 'mnote', 'Hələ proyekt yoxdur.'));
  for (const p of d.projects) {
    const row = el('div', 'urow');
    const nm = el('span', 'un', p.project); row.append(nm);
    const meta = [p.sessions + ' sessiya', ago(p.last_at), p.users.join(', ')];
    if (p.folders.length) meta.push('qovluq: ' + p.folders.join(', '));
    row.append(el('span', 'us', meta.filter(Boolean).join(' · ')));
    const ren = el('button', 'gbtn', 'Adı dəyiş');
    ren.onclick = () => {
      const inp = el('input'); inp.value = p.project; inp.maxLength = 60; inp.style.marginTop = '0'; inp.style.flex = '1'; inp.style.minWidth = '140px';
      const ok = el('button', 'gbtn go', 'Saxla'), no = el('button', 'gbtn', 'Ləğv et');
      row.replaceChildren(inp, ok, no); inp.focus(); inp.select();
      no.onclick = prjRefresh;
      const save = async () => {
        const to = inp.value.trim(); if (!to || to === p.project) return prjRefresh();
        const { status, d: r } = await ADM('rename_project', { from: p.project, to });
        if (status === 200) { prjErr(''); if (curPlanet === p.project) go(to); load(); prjRefresh(); } else { prjErr(r.error || 'Alınmadı'); }
      };
      ok.onclick = save; inp.addEventListener('keydown', e => { if (e.key === 'Enter') save(); if (e.key === 'Escape') { e.stopPropagation(); prjRefresh(); } });
    };
    const del = el('button', 'gbtn danger', 'Sil');
    del.onclick = async () => {
      if (!confirm('"' + p.project + '" proyekti və bütün tarixçəsi silinsin? Geri qaytarılmır.')) return;
      const { status, d: r } = await ADM('delete_project', { name: p.project });
      if (status === 200) { prjErr(''); if (curPlanet === p.project) go(); load(); prjRefresh(); } else prjErr(r.error || 'Alınmadı');
    };
    row.append(ren, del); box.append(row);
  }
}
function initAdmin() {
  $('b-prj').onclick = () => { prjErr(''); openM('prj'); prjRefresh(); };
  document.querySelectorAll('[data-close]').forEach(b => { b.onclick = () => closeM(b.dataset.close); });
  for (const id of ['adm', 'acc', 'prj']) $(id).addEventListener('pointerdown', e => { if (e.target === $(id)) closeM(id); });
  addEventListener('keydown', e => { if (e.key === 'Escape') { for (const m of ['adm', 'acc', 'prj', 'rep', 'ntf', 'tk', 'msg', 'pl', 'shipm', 'wm', 'mcpm', 'mtm']) if (!$(m).hidden) closeM(m); } });
  $('b-adm').onclick = () => { $('adm-res').hidden = true; admErr(''); openM('adm'); admRefresh(); };
  $('adm-add').onclick = async () => {
    const name = $('adm-name').value.trim(); if (!name) return;
    $('adm-add').disabled = true; admErr('');
    const { status, d } = await ADM('create', { name, role: $('adm-role').value });
    $('adm-add').disabled = false;
    if (status === 200) { $('adm-name').value = ''; showCreds('user', d); admRefresh(); } else admErr(d.error || 'Alınmadı');
  };
  $('adm-name').addEventListener('keydown', e => { if (e.key === 'Enter') $('adm-add').click(); });
  $('adm-copy').onclick = async () => {
    try { await navigator.clipboard.writeText($('adm-msg').value); setText($('adm-copy'), 'Kopyalandı ✓'); } catch (e) { $('adm-msg').select(); setText($('adm-copy'), 'Mətni seç və kopyala'); }
    setTimeout(() => setText($('adm-copy'), 'Kopyala'), 2000);
  };
  $('b-acc').onclick = () => { setText($('acc-who'), 'Daxil olub: ' + (ME ? ME.name : '')); $('acc-err').hidden = true; setText($('acc-ok'), ''); openM('acc'); $('acc-cur').focus(); };
  $('acc-save').onclick = async () => {
    const e = $('acc-err'); e.hidden = true; setText($('acc-ok'), '');
    const { status, d } = await ADM('passwd', { current: $('acc-cur').value, new: $('acc-new').value });
    if (status === 200) { setText($('acc-ok'), 'Parol dəyişdi ✓'); $('acc-cur').value = ''; $('acc-new').value = ''; }
    else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
}
/* Canlı hadisələr planetdə partlayış yaradır */
let lastEvId = 0, evInit = false;
function fireEvents() {
  if (!data || !data.events || !data.events.length) return;
  const max = data.events.reduce((m, e) => Math.max(m, +e.id), 0);
  if (!evInit) { evInit = true; lastEvId = max; return; }
  if (K && glOn && view === 'space') {
    for (const e of data.events.slice().reverse()) {
      if (+e.id > lastEvId && (e.event === 'PostToolUse' || e.event === 'UserPromptSubmit')) K.burst(e.project, EDITS.includes(e.tool));
    }
  }
  lastEvId = Math.max(lastEvId, max);
}
/* ====== Plan stansiyası: proyekt üzrə plan, scope və tapşırıqlar ====== */
const PL_GROUPS = [['plan', '🗺 Planlar'], ['scope', '🎯 Scope-lar'], ['task', '📌 Tapşırıqlar']];
let plProject = null, plKind = 'plan', plEditId = 0;
const plExtra = new Set();
async function PLN(action, extra) {
  const r = await fetch('plans.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
function planProjects() {
  const out = buildProjects().map(p => p.name), seen = new Set(out), rest = new Set(plExtra);
  for (const x of [...(data.plans || []), ...(data.tasks || [])]) rest.add(x.project);
  for (const n of seen) rest.delete(n);
  return out.concat([...rest].sort((a, b) => a.localeCompare(b)));
}
const planOpen = pr => (data.plans || []).filter(x => x.project === pr && x.status === 'open').length + (data.tasks || []).filter(t => t.project === pr && t.status !== 'done').length;
const canEditPlan = x => !!(ME && (ME.role === 'admin' || ME.name === x.creator));
function makePlGroup(g) {
  const n = el('section', 'plg'), h = el('h3'), c = el('span', 'count'), add = el('button', 'gbtn', '+ əlavə et');
  h.append(el('span', null, g[1]), c, add);
  const list = el('div', 'pll'), empty = el('div', 'empty', g[0] === 'task' ? 'Bu proyektdə tapşırıq yoxdur' : 'Hələ yoxdur. Birinci sən əlavə et.');
  n.append(h, list, empty);
  add.onclick = () => g[0] === 'task' ? openTaskModal(plProject) : openPlanModal(g[0]);
  n._r = { c, list, empty };
  return n;
}
function makePlItem() {
  const n = el('div', 'pli'), chk = el('button', 'chk', '✓'), pt = el('div', 'pt'), pa = el('div', 'pa'), pd = el('div', 'pd'), pm = el('div', 'pm');
  const ed = el('button', null, '✎'), del = el('button', null, '🗑'); ed.title = 'Dəyiş'; del.title = 'Sil'; pa.append(ed, del);
  n.append(chk, pt, pa, pd, pm);
  chk.onclick = async () => { const { status, d } = await PLN('status', { id: n._it.id, status: n._it.status === 'done' ? 'open' : 'done' }); if (status === 200) load(); else bmsg(d.error || 'Alınmadı'); };
  ed.onclick = () => openPlanModal(n._it.kind, n._it);
  del.onclick = async () => { if (!confirm('"' + n._it.title + '" silinsin?')) return; const { status, d } = await PLN('delete', { id: n._it.id }); if (status === 200) load(); else bmsg(d.error || 'Alınmadı'); };
  n._r = { chk, pt, pd, pm, pa };
  return n;
}
function makePlTask() {
  const n = el('div', 'pli tk'), pt = el('div', 'pt'), st = el('span', 'tst'), pd = el('div', 'pd'), pm = el('div', 'pm');
  n.append(pt, st, pd, pm);
  n._r = { pt, st, pd, pm };
  return n;
}
function renderPlan() {
  if (!data) return;
  const projs = planProjects();
  const TST = Object.fromEntries(TCOLS);
  if (!plProject || !projs.includes(plProject)) plProject = (lastPlanet && projs.includes(lastPlanet) ? lastPlanet : projs[0]) || null;
  $('plempty').hidden = projs.length > 0;
  sync($('pl-projects'), projs, p => p, p => el('button'), (b, p) => {
    setText(b, p + ' · ' + planOpen(p)); setCls(b, p === plProject ? 'on' : ''); b.onclick = () => { plProject = p; renderPlan(); };
  }, { enter: false });
  const body = $('pl-body');
  if (!plProject) { body.replaceChildren(); return; }
  sync(body, PL_GROUPS, g => g[0], makePlGroup, (n, g) => {
    const r = n._r, kind = g[0];
    const rows = kind === 'task'
      ? (data.tasks || []).filter(t => t.project === plProject).sort((a, b) => TCOLS.findIndex(c => c[0] === a.status) - TCOLS.findIndex(c => c[0] === b.status) || a.id - b.id)
      : (data.plans || []).filter(x => x.project === plProject && x.kind === kind).sort((a, b) => (a.status === 'done') - (b.status === 'done') || a.id - b.id);
    setText(r.c, String(rows.length)); r.empty.hidden = rows.length > 0;
    sync(r.list, rows, x => x.id, kind === 'task' ? makePlTask : makePlItem, (it, x) => {
      const q = it._r; it._it = x;
      setText(q.pt, x.title); setText(q.pd, x.details || ''); q.pd.hidden = !x.details;
      if (kind === 'task') {
        setText(q.st, TST[x.status] || x.status); q.st.dataset.s = x.status;
        const dl = dueInfo(x);
        setText(q.pm, x.assignee + (x.creator !== x.assignee ? ' · təyin edən ' + x.creator : '') + ' · ' + ago(x.updated_at) + (dl ? ' · ' + dl.text : ''));
      } else {
        setCls(it, 'pli' + (x.status === 'done' ? ' done' : ''));
        setText(q.pm, x.creator + ' · ' + ago(x.created_at)); q.pa.hidden = !canEditPlan(x);
        q.pa.firstChild.hidden = q.pa.lastChild.hidden = !canEditPlan(x);
      }
    });
  });
}
function openPlanModal(kind, it) {
  plEditId = it ? it.id : 0; plKind = kind || 'plan';
  const known = planProjects();
  $('pl-prjs').replaceChildren(...known.map(p => Object.assign(document.createElement('option'), { value: p })));
  $('pl-prj').value = it ? it.project : (plProject || ''); $('pl-prj').disabled = !!it;
  $('pl-ttl').value = it ? it.title : ''; $('pl-det').value = it ? it.details : '';
  $('pl-err').hidden = true;
  setText($('pl-title'), it ? 'Dəyiş' : 'Yeni ' + (plKind === 'scope' ? 'scope' : 'plan'));
  for (const b of $('pl-kind').children) { b.classList.toggle('on', b.dataset.k === plKind); b.disabled = !!it; }
  setText($('pl-hint'), plKind === 'scope' ? 'Scope: nəyə daxildir, nəyə yox' : 'Plan: nə, nə vaxt, necə');
  openM('pl'); $('pl-ttl').focus();
}
function initPlan() {
  $('b-plan').onclick = () => go(SHIPKEY);
  $('plback').onclick = () => go();
  $('pl-newproj').onclick = () => { const n = (prompt('Proyektin adı:') || '').trim(); if (n) { plExtra.add(n.slice(0, 128)); plProject = n.slice(0, 128); renderPlan(); } };
  for (const b of $('pl-kind').children) b.onclick = () => { if (plEditId) return; plKind = b.dataset.k; for (const x of $('pl-kind').children) x.classList.toggle('on', x === b); setText($('pl-title'), 'Yeni ' + (plKind === 'scope' ? 'scope' : 'plan')); setText($('pl-hint'), plKind === 'scope' ? 'Scope: nəyə daxildir, nəyə yox' : 'Plan: nə, nə vaxt, necə'); };
  $('pl').addEventListener('pointerdown', e => { if (e.target === $('pl')) closeM('pl'); });
  $('pl-save').onclick = async () => {
    const e = $('pl-err'); e.hidden = true; $('pl-save').disabled = true;
    const body = { title: $('pl-ttl').value, details: $('pl-det').value };
    const { status, d } = plEditId ? await PLN('update', { id: plEditId, ...body }) : await PLN('create', { kind: plKind, project: $('pl-prj').value, ...body });
    $('pl-save').disabled = false;
    if (status === 200) { if (!plEditId) plProject = $('pl-prj').value.trim(); closeM('pl'); await load(); if (curShip) renderPlan(); }
    else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('pl-ttl').addEventListener('keydown', ev => { if (ev.key === 'Enter') $('pl-save').click(); });
}
/* ====== Mesajlar: raketlə çatdırılır (kainatda), planet zədələnir ====== */
const MREAD = 'tdmread';
const msgSeen = new Set();
let msgInit = false, msgTab = 'write';
const getRead = () => { try { return +localStorage.getItem(MREAD) || 0; } catch (e) { return 0; } };
const setRead = v => { try { localStorage.setItem(MREAD, String(v)); } catch (e) {} };
const toMe = m => !!(ME && m.from_user !== ME.name && (m.to_user === '*' || m.to_user === ME.name));
const inMine = m => !!(ME && (m.from_user === ME.name || toMe(m)));
/** İstifadəçinin hazırda işlədiyi (ən son aktiv) proyekti = onun planeti */
function userPlanet(u) {
  let best = null;
  for (const s of data.sessions || []) {
    if (s.user !== u) continue;
    const sc = (RANK[eff(s)] > 0 ? 1e12 : 0) + s.updated_at;
    if (!best || sc > best.sc) best = { sc, p: s.project };
  }
  if (!best) for (const r of data.projects || []) if (r.user === u && (!best || +r.last_at > best.sc)) best = { sc: +r.last_at, p: r.project };
  return best ? best.p : null;
}
function msgTargets(m) { return m.to_user === '*' ? (data.users || []).filter(u => u !== m.from_user) : [m.to_user]; }
function syncHits() {
  if (!(K && glOn) || !data.messages) return;
  const items = [];
  for (const m of data.messages) {
    const age = data.now - m.created_at;
    if (age > 200) continue;
    for (const to of new Set(msgTargets(m).map(userPlanet).filter(Boolean))) items.push({ id: +m.id, project: to, age });
  }
  if (items.length) K.syncHits(items);
}
function updateMsgBadge() {
  const rd = getRead(), n = ME ? (data.messages || []).filter(m => toMe(m) && +m.id > rd).length : 0;
  const b = $('mbadge'); b.hidden = n === 0; setText(b, String(n));
  setText($('mt-cnt'), String((data.messages || []).filter(inMine).length));
  if (!$('msg').hidden && msgTab === 'inbox') renderInbox();
}
function showToast(m) {
  const box = $('mtoasts'), t = el('div', 'mtoast'), h = el('div', 'mth');
  const x = el('button', 'ibtn', '✕'); x.setAttribute('aria-label', 'Bağla'); x.onclick = () => t.remove();
  h.append(avatar(m.from_user), el('b', null, m.from_user), el('span', 'mtw', m.to_user === '*' ? '→ hamıya' : '→ sənə'), x);
  const rp = el('button', 'gbtn', 'Cavab yaz'); rp.onclick = () => { t.remove(); openMsg(m.from_user); };
  t.append(h, el('div', 'mtb', m.body), rp);
  box.prepend(t);
  while (box.children.length > 4) box.lastChild.remove();
  setTimeout(() => t.remove(), 25000);
}
function miniToast(text) {
  const box = $('mtoasts'), t = el('div', 'mtoast mini', text);
  box.prepend(t); setTimeout(() => t.remove(), 4000);
}
function playMessage(m) {
  const sent = !!(ME && m.from_user === ME.name);
  let dur = 0;
  if (K && glOn && view === 'space') {
    const from = userPlanet(m.from_user), seen = new Set();
    let i = 0;
    for (const u of msgTargets(m)) {
      const to = userPlanet(u);
      if (!to || seen.has(to)) continue;
      seen.add(to);
      dur = Math.max(dur, K.rocket({ id: +m.id, from, to, hue: hue(m.from_user), who: m.from_user, delay: i * .35, cinema: sent ? (m.to_user === '*' ? 'wide' : 'chase') : null }));
      i++;
    }
  }
  if (sent) miniToast(m.to_user === '*' ? '🚀 Raketlər hamıya yola düşdü' : '🚀 Raket ' + m.to_user + '-ə yola düşdü');
  if (toMe(m)) setTimeout(() => showToast(m), dur * 1000);
  updateMsgBadge();
}
function fireMessages() {
  const list = data.messages || [];
  if (!msgInit) { msgInit = true; list.forEach(m => msgSeen.add(+m.id)); return; }
  for (const m of list.slice().reverse()) if (!msgSeen.has(+m.id)) { msgSeen.add(+m.id); playMessage(m); }
}
function msgHint() {
  const to = $('msg-to').value, me = ME && userPlanet(ME.name);
  const dst = to === '*' ? null : userPlanet(to);
  setText($('msg-hint'), !(data.users || []).some(u => u !== ME.name) ? 'Başqa istifadəçi yoxdur' :
    to === '*' ? '🚀 Hamının planetinə raket gedəcək' :
    dst ? '🪐 ' + (me || 'günəş') + ' → ' + dst : to + '-in aktiv planeti yoxdur, mesaj yenə də çatacaq');
}
function openMsg(to) {
  const sel = $('msg-to'); sel.replaceChildren();
  sel.append(Object.assign(document.createElement('option'), { value: '*', textContent: 'Hamıya' }));
  for (const u of data.users || []) if (u !== ME.name) sel.append(Object.assign(document.createElement('option'), { value: u, textContent: u }));
  sel.value = [...sel.options].some(o => o.value === to) ? to : '*';
  $('msg-err').hidden = true; $('msg-send').disabled = false;
  showMsgTab('write'); msgHint(); openM('msg'); $('msg-body').focus();
}
function showMsgTab(t) {
  msgTab = t;
  $('mt-write').classList.toggle('on', t === 'write'); $('mt-inbox').classList.toggle('on', t === 'inbox');
  $('msg-write').hidden = t !== 'write'; $('msg-inbox').hidden = t !== 'inbox';
  if (t === 'inbox') { renderInbox(); setRead(Math.max(getRead(), ...(data.messages || []).map(m => +m.id))); updateMsgBadge(); }
}
function renderInbox() {
  const box = $('msg-inbox'), rd = getRead(), list = (data.messages || []).filter(inMine);
  box.replaceChildren();
  if (!list.length) { box.append(el('div', 'mnote', 'Son 24 saatda mesaj yoxdur.')); return; }
  for (const m of list) {
    const r = el('div', 'mrow' + (toMe(m) && +m.id > rd ? ' new' : '')), h = el('div', 'mh');
    h.append(avatar(m.from_user), el('b', null, m.from_user), document.createTextNode(m.to_user === '*' ? '→ hamıya' : m.from_user === ME.name ? '→ ' + m.to_user : '→ sənə'), el('span', null, '· ' + ago(m.created_at)));
    r.append(h, el('div', 'mb', m.body));
    if (m.from_user !== ME.name) { const b = el('button', 'gbtn', 'Cavab yaz'); b.onclick = () => openMsg(m.from_user); r.append(b); }
    box.append(r);
  }
}
async function sendMsg() {
  const e = $('msg-err'); e.hidden = true;
  const body = $('msg-body').value.trim(), to = $('msg-to').value;
  if (!body) { e.hidden = false; e.textContent = 'Mesaj boşdur'; return; }
  $('msg-send').disabled = true;
  let res = { status: 0, d: {} };
  try {
    const r = await fetch('message.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action: 'send', to, body }) });
    res = { status: r.status, d: await r.json().catch(() => ({})) };
  } catch (err) { /* şəbəkə xətası */ }
  $('msg-send').disabled = false;
  if (res.status === 401) return location.reload();
  if (res.status !== 200 || !res.d.message) { e.hidden = false; e.textContent = res.d.error || 'Göndərilmədi'; return; }
  const m = res.d.message;
  $('msg-body').value = ''; closeM('msg');
  msgSeen.add(+m.id); data.messages = [m, ...(data.messages || [])];
  const nav = view !== 'space';
  if (nav) go();                                                // raketi görmək üçün kainata keç
  setTimeout(() => playMessage(m), nav ? 650 : 0);
  load();
}
/* ====== Öz musiqim (MP3) ====== */
function sndState() {
  const u = data && data.sounds && ME ? data.sounds[ME.name] : null, own = !!(u && u.includes('/u/'));
  setText($('snd-state'), own ? 'Öz musiqin yüklənib ✓' : u ? 'Standart musiqi' : 'Musiqi yoxdur');
  const a = $('snd-prev'); a.hidden = !u;
  if (u && a.dataset.src !== u) { a.dataset.src = u; a.src = u; }
  $('snd-del').hidden = !own;
}
async function SND(fd) {
  const r = await fetch('sound.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
function initSound() {
  const open0 = $('b-acc').onclick;
  $('b-acc').onclick = () => { open0(); $('snd-err').hidden = true; $('snd-file').value = ''; sndState(); };
  const err = t => { const e = $('snd-err'); e.hidden = !t; e.textContent = t || ''; };
  $('snd-up').onclick = async () => {
    err('');
    const f = $('snd-file').files[0];
    if (!f) return err('Əvvəl MP3 faylı seç');
    if (f.size > 8 * 1024 * 1024) return err('Fayl çox böyükdür (ən çox 8 MB)');
    $('snd-up').disabled = true; setText($('snd-state'), 'Yüklənir…');
    const fd = new FormData(); fd.append('action', 'upload'); fd.append('file', f);
    let res; try { res = await SND(fd); } catch (e) { res = { status: 0, d: {} }; }
    $('snd-up').disabled = false;
    if (res.status !== 200) { sndState(); return err(res.d.error || 'Yüklənmədi'); }
    $('snd-file').value = ''; await load(); sndState();
  };
  $('snd-del').onclick = async () => {
    const fd = new FormData(); fd.append('action', 'delete');
    await SND(fd); await load(); sndState();
  };
}
function initMsg() {
  $('b-msg').onclick = () => { if (data && ME) openMsg(); };
  $('mt-write').onclick = () => showMsgTab('write'); $('mt-inbox').onclick = () => showMsgTab('inbox');
  $('msg-to').onchange = msgHint;
  $('msg-send').onclick = sendMsg;
  $('msg-body').addEventListener('keydown', e => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) sendMsg(); });
  $('msg').addEventListener('pointerdown', e => { if (e.target === $('msg')) closeM('msg'); });
}
/* ====== Tapşırıq lövhəsi ====== */
const TCOLS = [['todo', 'Gözləyir'], ['doing', 'İşləyir'], ['review', 'Yoxlamada'], ['done', 'Bitdi']];
let bfWho = 'me', bfPrj = '';
async function TSK(action, extra) {
  const r = await fetch('tasks.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
function bmsg(t) { const e = $('bmsg'); e.hidden = !t; e.textContent = t || ''; if (t) setTimeout(() => { if (e.textContent === t) bmsg(''); }, 6000); }
function boardTasks() {
  return (data.tasks || []).filter(t => (bfWho === 'all' || (ME && t.assignee === ME.name)) && (!bfPrj || t.project === bfPrj));
}
function updateTaskBadge() {
  const n = ME ? (data.tasks || []).filter(t => t.assignee === ME.name && t.status === 'todo').length : 0;
  const b = $('tbadge'); b.hidden = n === 0; setText(b, String(n));
  const base = 'Komanda Claude'; const nt = n ? '(' + n + ') ' + base : base; if (document.title !== nt) document.title = nt;
}
function makeTask() {
  const n = el('div', 'tcard');
  const tt = el('div', 'tt'), tm = el('div', 'tm'), tp = el('span', 'tp'), who = el('span'), age = el('span'), due = el('span', 'tdue');
  tm.append(tp, who, age, due);
  const desc = el('div', 'tdesc'), cmd = el('details', 'tcmd'), sm = el('summary'), pre = el('pre'); cmd.append(sm, pre);
  const act = el('div', 'tact');
  n.append(tt, tm, desc, cmd, act);
  n._r = { tt, tp, who, age, due, desc, cmd, sm, pre, act, sig: '' };
  return n;
}
/** Tapşırıq kartını yeniləyir (lövhədə və Designer planetində) */
function updTask(n, t) {
  const r = n._r;
  setText(r.tt, t.title); setText(r.tp, t.project); r.tp.onclick = () => go(t.project);
  setText(r.who, t.assignee + (t.creator !== t.assignee ? ' · ' + t.creator + ' təyin etdi' : '')); setText(r.age, ago(t.updated_at));
  const dl = dueInfo(t); r.due.hidden = !dl; if (dl) { setText(r.due, dl.text); r.due.dataset.lv = String(dl.level); }
  setText(r.desc, t.details || ''); r.desc.hidden = !t.details;
  const cm = t.command_id && (data.commands || []).find(x => +x.id === +t.command_id);
  r.cmd.hidden = !cm;
  if (cm) {
    const [lbl] = CMD_ST[cm.status] || [cm.status];
    setText(r.sm, 'Claude: ' + lbl); setText(r.pre, cm.result || (cm.status === 'queued' ? 'Agent götürənə qədər növbədə gözləyir…' : cm.status === 'running' ? 'İşləyir…' : ''));
    if ((cm.status === 'done' || cm.status === 'failed') && !r.cmd._o) { r.cmd._o = true; r.cmd.open = cm.status === 'failed'; }
  }
  const btns = taskButtons(t), sig = t.status + '|' + btns.map(b => b[0]).join(',') + '|' + (ME ? ME.name : '');
  if (r.sig !== sig) {
    r.sig = sig; r.act.replaceChildren(...btns.map(([label, cls, fn]) => { const b = el('button', 'gbtn ' + cls, label); b.onclick = fn; return b; }));
  } else { [...r.act.children].forEach((b, i) => { b.onclick = btns[i][2]; }); }
}
function taskButtons(t) {
  const mine = ME && t.assignee === ME.name, admin = ME && ME.role === 'admin', creator = ME && t.creator === ME.name;
  const canMove = mine || admin || creator, canEdit = admin || creator, B = [];
  const mv = (label, to, cls) => B.push([label, cls || '', async () => { const { status, d } = await TSK('move', { id: t.id, status: to }); if (status !== 200) bmsg(d.error || 'Alınmadı'); load(); }]);
  if (mine && (t.status === 'todo' || t.status === 'doing')) {
    B.push(['▶ Claude ilə', 'go', async () => {
      if (!getTok() || !myName) { $('btoken').hidden = false; $('btok-in').focus(); return; }
      const { status, d } = await cmdApi('run_task', { task_id: t.id });
      if (status !== 200) bmsg(d.error || 'Göndərmək alınmadı'); else bmsg('');
      load();
    }]);
  }
  if (canMove && t.status === 'todo') mv('Başla', 'doing');
  if (canMove && t.status === 'doing') mv('Yoxlamaya ver', 'review');
  if (canMove && t.status === 'doing') B.push(['Dayandır', 'danger', async () => {
    const cm = (data.commands || []).find(x => +x.id === +t.command_id);
    if (cm && (cm.status === 'running' || cm.status === 'queued')) await CTL('stop_command', { id: cm.id });
    else { const { status, d } = await TSK('move', { id: t.id, status: 'todo' }); if (status !== 200) bmsg(d.error || 'Alınmadı'); }
    load();
  }]);
  if (canMove && (t.status === 'doing' || t.status === 'review')) mv('Bitdi', 'done');
  if (canMove && t.status === 'review') mv('Geri qaytar', 'doing');
  if (canMove && t.status === 'done') mv('Yenidən aç', 'todo');
  if (canEdit) B.push(['Sil', 'danger', async () => { if (!confirm('"' + t.title + '" silinsin?')) return; await TSK('delete', { id: t.id }); load(); }]);
  return B;
}
function renderBoard() {
  updateTaskBadge();
  const box = $('bcols');
  if (!box.children.length) for (const [k, label] of TCOLS) {
    const c = el('div', 'bcol'); c.dataset.s = k;
    const h = el('div', 'bch'); h.append(el('span', null, label), el('span', 'count', '0')); c.append(h, el('div', 'blist'));
    box.append(c);
  }
  // süzgəclər
  sync($('bf-who'), ['me', 'all'], k => k, k => { const b = el('button', null, k === 'me' ? 'Mənim' : 'Hamısı'); b.onclick = () => { bfWho = k; renderBoard(); }; return b; }, (b, k) => setCls(b, k === bfWho ? 'on' : ''), { enter: false });
  const prjs = [...new Set([...(data.projects || []).map(p => p.project), ...(data.tasks || []).map(t => t.project)])].sort();
  const sel = $('bf-prj'), want = ['', ...prjs].join('|');
  if (sel._opts !== want) { sel._opts = want; sel.replaceChildren(...['', ...prjs].map(p => Object.assign(document.createElement('option'), { value: p, textContent: p || 'Bütün proyektlər' }))); }
  if (sel.value !== bfPrj) sel.value = bfPrj;
  const list = boardTasks();
  const myTok = !!(getTok() && myName);
  if (myTok) $('btoken').hidden = true;
  for (const c of box.children) {
    const s = c.dataset.s, col = list.filter(t => t.status === s);
    setText(c.querySelector('.count'), String(col.length));
    sync(c.querySelector('.blist'), col, t => t.id, makeTask, updTask);
  }
}
function openTaskModal(prj) {
  const known = [...new Set([...(data.projects || []).map(p => p.project), ...(data.worlds || []).map(w => w.name), ...SPECIALS.map(x => x.name)])].sort();
  $('tk-prjs').replaceChildren(...known.map(p => Object.assign(document.createElement('option'), { value: p })));
  const who = $('tk-who'); who.replaceChildren();
  const isAdmin = ME && ME.role === 'admin';
  for (const u of (isAdmin ? data.users : [ME.name])) who.append(Object.assign(document.createElement('option'), { value: u, textContent: (isAdmin ? 'Kimə: ' : 'Mənə: ') + u }));
  if (ME) who.value = ME.name; who.disabled = !isAdmin;
  $('tk-hint').textContent = isAdmin ? 'Hər kəsə təyin edə bilərsən.' : 'Tapşırıq sənə təyin olunacaq.';
  $('tk-err').hidden = true;
  if (prj) $('tk-prj').value = prj; else if (!$('tk-prj').value && curPlanet) $('tk-prj').value = curPlanet;
  openM('tk'); $('tk-title').focus();
}
function initBoard() {
  $('bf-prj').onchange = () => { bfPrj = $('bf-prj').value; renderBoard(); };
  $('b-newtask').onclick = () => openTaskModal();
  $('tk').addEventListener('pointerdown', e => { if (e.target === $('tk')) closeM('tk'); });
  $('tk-save').onclick = async () => {
    const e = $('tk-err'); e.hidden = true; $('tk-save').disabled = true;
    const { status, d } = await TSK('create', { title: $('tk-title').value, details: $('tk-det').value, project: $('tk-prj').value, assignee: $('tk-who').value, due_at: fromLocal($('tk-due').value) });
    $('tk-save').disabled = false;
    if (status === 200) { $('tk-title').value = ''; $('tk-det').value = ''; $('tk-due').value = ''; closeM('tk'); load(); }
    else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('btok-save').onclick = async () => {
    const v = $('btok-in').value.trim(); if (!v) return;
    setTok(v); $('btok-in').value = ''; await identify();
    const e = $('btok-err'); e.hidden = !!myName; e.textContent = myName ? '' : 'Token yanlışdır';
    renderBoard();
  };
  $('btok-in').addEventListener('keydown', e => { if (e.key === 'Enter') $('btok-save').click(); });
}
/* ====== Toqquşma lenti ====== */
function renderAlerts() {
  const box = $('alerts'), al = data.alerts || [];
  box.hidden = !al.length;
  sync(box, al.slice(0, 3), a => a.id, a => el('div', 'alert'), (n, a) => {
    n.replaceChildren(el('b', null, '⚠ Toqquşma riski'), document.createTextNode(`${a.user_a} və ${a.user_b} eyni faylı dəyişir: ${a.file} `));
    const l = el('a', null, a.project); l.onclick = () => go(a.project); n.append(l, document.createTextNode(' · ' + ago(a.created_at)));
  }, { enter: false });
}

/* ====== Hesabat və bildirişlər ====== */
let repDay = 'today';
async function REP(action, day) {
  const r = await fetch('report.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, day }) });
  const d = await r.json().catch(() => ({})); if (r.status === 401) location.reload(); return { status: r.status, d };
}
async function repLoad() {
  sync($('rep-days'), ['today', 'yesterday'], k => k, k => { const b = el('button', null, k === 'today' ? 'Bu gün' : 'Dünən'); b.onclick = () => { repDay = k; repLoad(); }; return b; }, (b, k) => setCls(b, k === repDay ? 'on' : ''), { enter: false });
  const { d } = await REP('get', repDay); if (!d.rows) return;
  setText($('rep-title'), d.title);
  const box = $('rep-body'); box.replaceChildren();
  const hhmm = ts => new Date(ts * 1000).toLocaleTimeString('az', { hour: '2-digit', minute: '2-digit' });
  for (const r of d.rows) {
    const c = el('div', 'rcard'), n = el('div', 'rn'); n.append(avatar(r.user), document.createTextNode(r.user)); c.append(n);
    const active = r.events > 0 || r.done.length || r.cmd_done;
    c.append(el('div', 'rl', active
      ? `${r.projects.length} proyekt (${r.projects.slice(0, 5).join(', ')}) · ${r.edits} fayl dəyişikliyi · ${r.prompts} prompt${r.first ? ' · ' + hhmm(r.first) + '–' + hhmm(r.last) : ''}`
      : 'Fəaliyyət yoxdur'));
    for (const t of r.done.slice(0, 6)) c.append(el('div', 'rt', t));
    const open = [r.doing && r.doing + ' işləyir', r.review && r.review + ' yoxlamada', r.todo && r.todo + ' gözləyir'].filter(Boolean);
    if (open.length) c.append(el('div', 'rl', '📌 ' + open.join(', ')));
    if (r.cmd_done || r.cmd_failed) c.append(el('div', 'rl', `Claude tapşırıqları: ${r.cmd_done} bitdi${r.cmd_failed ? ', ' + r.cmd_failed + ' xəta' : ''}`));
    box.append(c);
  }
  $('rep-foot').hidden = !(ME && ME.role === 'admin');
}
async function ntfLoad() {
  const { d } = await ADM('settings_get');
  setText($('ntf-state'), d.configured ? 'Hazırda: qoşulub (token ' + d.token_hint + ', chat ' + d.chat + '). Dəyişmək üçün yeni dəyər yaz, tokeni boş buraxsan köhnəsi qalır.' : 'Hələ qoşulmayıb.');
  $('ntf-chat').value = d.chat || '';
}
function initReport() {
  $('b-rep').onclick = () => { openM('rep'); repLoad(); };
  $('rep').addEventListener('pointerdown', e => { if (e.target === $('rep')) closeM('rep'); });
  $('rep-send').onclick = async () => {
    setText($('rep-note'), 'Göndərilir…');
    const { status, d } = await REP('send', repDay);
    setText($('rep-note'), status === 200 && d.ok ? 'Telegram-a göndərildi ✓' : (d.error || 'Göndərilmədi'));
  };
  $('b-ntf').onclick = () => { $('ntf-err').hidden = true; setText($('ntf-ok'), ''); $('ntf-tok').value = ''; openM('ntf'); ntfLoad(); };
  $('ntf').addEventListener('pointerdown', e => { if (e.target === $('ntf')) closeM('ntf'); });
  $('ntf-save').onclick = async () => {
    const e = $('ntf-err'); e.hidden = true; setText($('ntf-ok'), '');
    const { status, d } = await ADM('settings_set', { tg_token: $('ntf-tok').value, tg_chat: $('ntf-chat').value });
    if (status === 200) { $('ntf-tok').value = ''; setText($('ntf-ok'), 'Saxlandı ✓'); ntfLoad(); } else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('ntf-test').onclick = async () => {
    const e = $('ntf-err'); e.hidden = true; setText($('ntf-ok'), 'Göndərilir…');
    const { status, d } = await ADM('settings_test');
    if (status === 200) setText($('ntf-ok'), 'Test mesajı göndərildi ✓'); else { setText($('ntf-ok'), ''); e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('ntf-clear').onclick = async () => { if (!confirm('Telegram ayarları silinsin? Bildirişlər dayanacaq.')) return; await ADM('settings_clear'); $('ntf-tok').value = ''; $('ntf-chat').value = ''; setText($('ntf-ok'), 'Silindi'); ntfLoad(); };
}
/* ====== Kainatın yeni hissələri: fərdi və xüsusi planetlər, gəmilər, deadline-lar, MCP peykləri ====== */
const DAY = 86400;
const SPECIALS = [{ name: 'Prompt Generator', kind: 'prompt', icon: '✨' }, { name: 'Designer', kind: 'designer', icon: '🎨' }, { name: 'Meeting Planet', kind: 'meeting', icon: '🤝' }, { name: 'Test Planet', kind: 'test', icon: '🧪' }];
const specialOf = n => SPECIALS.find(x => x.name === n) || null;
let shipDraft = null, wDraft = null;
async function WLD(action, extra) {
  const r = await fetch('world.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
const z2 = n => String(n).padStart(2, '0');
const toLocal = ts => { if (!+ts) return ''; const d = new Date(ts * 1000); return `${d.getFullYear()}-${z2(d.getMonth() + 1)}-${z2(d.getDate())}T${z2(d.getHours())}:${z2(d.getMinutes())}`; };
const fromLocal = v => v ? Math.floor(new Date(v).getTime() / 1000) : 0;
const AZ_MON = ['yan', 'fev', 'mar', 'apr', 'may', 'iyn', 'iyl', 'avq', 'sen', 'okt', 'noy', 'dek'];
function when(ts) {
  const d = new Date(ts * 1000), t = new Date(now() * 1000), hm = z2(d.getHours()) + ':' + z2(d.getMinutes());
  const day = x => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime(), diff = Math.round((day(d) - day(t)) / 864e5);
  if (diff === 0) return 'bu gün ' + hm;
  if (diff === 1) return 'sabah ' + hm;
  if (diff === -1) return 'dünən ' + hm;
  return d.getDate() + ' ' + AZ_MON[d.getMonth()] + ' ' + hm;
}
function fmtDur(sec) {
  sec = Math.max(0, Math.floor(sec));
  const d = Math.floor(sec / DAY), h = Math.floor(sec % DAY / 3600), m = Math.floor(sec % 3600 / 60);
  if (d) return d + 'g ' + h + 's';
  if (h) return h + 's ' + m + 'dəq';
  return Math.max(1, m) + ' dəq';
}
/** Tapşırığın son tarixi: mətn və səviyyə (0 = bir gündən çox, 1 = 3 saatdan az, 2 = keçib) */
function dueInfo(t) {
  if (!+t.due_at || t.status === 'done') return null;
  const left = t.due_at - now();
  return { left, level: left < 0 ? 2 : left < 3 * 3600 ? 1 : 0, text: left < 0 ? '⏰ ' + fmtDur(-left) + ' gecikir' : '⏳ ' + fmtDur(left) + ' qalıb' };
}

/* 24 saat qaydası: açıq tapşırığı olan planetdə 24 saat ərzində heç nə bitməyibsə, planet tüstülənir → yanır → xaosa düşür */
function idleOf(name) {
  const a = (data.activity || []).find(r => r.project === name);
  if (!a || !+a.open) return 0;
  return Math.max(0, now() - Math.max(+a.last_done || 0, +a.oldest_open || 0));
}
function burnOf(name) { const s = idleOf(name); return s < DAY ? 0 : Math.min(1, .2 + (s - DAY) / (2 * DAY) * .8); }
function satsOf(name) {
  const m = new Map();
  for (const u of data.mcp_use || []) if (u.project === name) m.set(u.name, { name: u.name, active: now() - u.last_at < 600, n: +u.n, last: +u.last_at });
  for (const x of data.mcp || []) if (x.project === name && !m.has(x.name)) m.set(x.name, { name: x.name, manual: true });
  return [...m.values()].slice(0, 8);
}
function liveMeetings() { const t = now(); return (data.meetings || []).filter(m => +m.starts_at <= t && t < +m.starts_at + m.minutes * 60); }
const blankP = name => ({ name, users: new Map(), edits: 0, tools: 0, sessions: 0, last: 0, st: 'offline', summary: '', sumAt: 0 });

/** Kainatdakı bütün planetlər: proyektlər + istifadəçi planetləri + xüsusi planetlər (xarici orbitlərdə) */
function spaceList(all) {
  const byName = new Map(all.map(p => [p.name, p]));
  const out = all.slice(0, MAXP).sort((a, b) => a.name.localeCompare(b.name)).map(p => ({ ...p, kind: 'project' }));
  const idx = new Map(out.map((p, i) => [p.name, i]));
  const worlds = (data.worlds || []).map(w => ({ ...w }));
  if (wDraft) {
    const w = wDraft.id ? worlds.find(x => +x.id === +wDraft.id) : null;
    if (w) Object.assign(w, wDraft); else if (!wDraft.id) worlds.push({ ...wDraft, owner: ME ? ME.name : '' });
  }
  for (const w of worlds) {
    if (!w.name) continue;
    let p = idx.has(w.name) ? out[idx.get(w.name)] : null;
    if (!p) { p = byName.has(w.name) ? { ...byName.get(w.name) } : blankP(w.name); idx.set(w.name, out.length); out.push(p); }
    p.kind = 'custom'; p.world = w; p.look = { surface: +w.surface, hue: +w.hue, ring: +w.ring ? 1 : 0, size: +w.size || 2 };
  }
  for (const sp of SPECIALS) {
    if (idx.has(sp.name)) continue;
    const p = byName.has(sp.name) ? { ...byName.get(sp.name) } : blankP(sp.name);
    p.kind = sp.kind; p.label = sp.icon + ' ' + sp.name; out.push(p);
  }
  for (const p of out) {
    p.burn = burnOf(p.name); p.sats = satsOf(p.name); p.sub = planetSub(p);
    if (p.kind === 'custom') {
      if (!p.last) { p.st = 'idle'; p.sub = (p.world.owner || '') + ' planeti'; }
    } else if (p.kind === 'designer') {
      const ts = (data.tasks || []).filter(t => t.project === p.name && t.status !== 'done');
      p.st = ts.some(t => t.status === 'doing') ? 'working' : 'idle';
      p.sub = ts.length ? ts.length + ' dizayn tapşırığı' : 'kreativ işlər';
    } else if (p.kind === 'meeting') {
      const live = liveMeetings(), next = (data.meetings || []).find(m => +m.starts_at > now());
      p.st = live.length ? 'meet' : 'idle';
      p.sub = live.length ? '🔴 indi: ' + live[0].title : next ? 'növbəti: ' + when(next.starts_at) : 'neytral ərazi';
    } else if (p.kind === 'prompt') {
      p.st = 'idle'; p.sub = (data.prompts || []).length + ' prompt';
    } else if (p.kind === 'test') {
      const ts = data.tests || [], f = ts.filter(t => t.status === 'fail').length;
      p.fail = f > 0; p.st = f ? 'waiting' : 'idle';
      p.sub = ts.length ? (f ? f + ' xəta · ' : '') + ts.filter(t => t.status === 'pass').length + '/' + ts.length + ' keçdi' : 'proyekt seç, test et';
    }
    if (p.kind !== 'test') { const f = (data.tests || []).filter(t => t.project === p.name && t.status === 'fail').length; if (f) p.sub += ' · 🧪 ' + f + ' xəta'; }
    if (p.burn > 0) p.sub += ' · ' + Math.floor(idleOf(p.name) / 3600) + 's task bitməyib';
  }
  return out;
}

/* ====== Universlər: Komanda (proyektlər), Studio (xüsusi planetlər), MCP, Agent ====== */
const UNIVERSES = [
  { id: 'main', icon: '🌌', name: 'Komanda', hint: 'Proyektlər və istifadəçi planetləri' },
  { id: 'studio', icon: '🎨', name: 'Studio', hint: 'Prompt Generator, Designer, Meeting, Test' },
  { id: 'mcp', icon: '🛰', name: 'MCP Universe', hint: 'MCP serverləri və onları işlədən proyektlər' },
  { id: 'agent', icon: '🤖', name: 'Agent Universe', hint: 'AI agentləri, tapşırıqları və avtomatlaşdırma' },
  { id: 'desktop', icon: '🖥', name: 'Desktop Universe', hint: 'Komanda yoldaşlarının kompüterlərindəki Claude Code: icazə verənə uzaqdan komanda göndər' },
  { id: 'student', icon: '🎓', name: 'Student Universe', hint: 'Hər studentin öz planeti' },
];
/** Student yalnız öz universini görür (server də yalnız onun məlumatını verir) */
const isStu = () => document.body.classList.contains('student');
const visUnis = () => isStu() ? UNIVERSES.filter(u => u.id === 'student') : UNIVERSES;
const stuWorld = n => (data && data.worlds || []).find(w => w.name === n && (data.students || []).includes(w.owner));
let navUni = null, navTarget;             // navUni: kameranın hazırda olduğu univers (3D-dən gəlir), null = xəritə
/** Universə (null = bütün xəritəyə) uç; 3D hələ hazır deyilsə, hazır olanda */
function flyTo(id) { if (isStu()) id = 'student'; if (K && glOn) K.flyUni(id); else navTarget = id; }
const uniOf = n => !n ? navUni || (isStu() ? 'student' : 'main') : n.startsWith('mcp:') ? 'mcp' : n.startsWith('agent:') ? 'agent' : n.startsWith('desktop:') ? 'desktop' : specialOf(n) ? 'studio' : stuWorld(n) ? 'student' : 'main';
/** Xüsusi panel açılan planetlər: Studio stansiyaları, MCP serverləri, agentlər */
function xKind(n) {
  if (!n) return null;
  if (n.startsWith('mcp:')) return { kind: 'mcpu', icon: '🛰', name: n.slice(4) };
  if (n.startsWith('agent:')) return { kind: 'agentu', icon: '🤖', name: n.slice(6) };
  if (n.startsWith('desktop:')) return { kind: 'deskt', icon: '🖥', name: n.slice(8) };
  return specialOf(n);
}
function uniList(full, u) {
  if (u === 'studio') return full.filter(p => specialOf(p.name));
  if (u === 'mcp') return mcpList();
  if (u === 'agent') return agentList();
  if (u === 'desktop') return desktopList();
  if (u === 'student') return full.filter(p => p.kind === 'custom' && stuWorld(p.name));
  return full.filter(p => !specialOf(p.name) && !stuWorld(p.name));
}
function mcpServers() {
  const m = new Map();
  const add = n => { if (!m.has(n)) m.set(n, { name: n, n: 0, last: 0, projects: new Map(), manual: null }); return m.get(n); };
  for (const u of data.mcp_use || []) { const x = add(u.name); x.n += +u.n; x.last = Math.max(x.last, +u.last_at); x.projects.set(u.project, (x.projects.get(u.project) || 0) + +u.n); }
  for (const x of data.mcp || []) { const y = add(x.name); y.manual = x; if (x.project && !y.projects.has(x.project)) y.projects.set(x.project, 0); }
  return [...m.values()].sort((a, b) => a.name.localeCompare(b.name));
}
function mcpList() {
  return mcpServers().slice(0, 14).map(x => {
    const act = now() - x.last < 600, day = now() - x.last < DAY;
    return { name: 'mcp:' + x.name, label: '🛰 ' + x.name, kind: 'mcp', hue: hue(x.name), R: 2.3 + Math.min(1.8, Math.log2(1 + x.n) * .3),
      st: act ? 'working' : day || !x.n ? 'idle' : 'offline',
      sub: x.n ? x.n + ' çağırış · ' + x.projects.size + ' proyekt · ' + ago(x.last) : 'əl ilə qeyd olunub',
      users: new Map([...x.projects.keys()].map(p => [p, { st: act ? 'working' : 'idle' }])), edits: 0, tools: 0, burn: 0, sats: [] };
  });
}
function agentInfo(u) {
  const ag = (data.agents || []).find(a => a.user === u);
  const online = !!(ag && now() - ag.last_seen < AGENT_TTL);
  const cm = (data.commands || []).filter(c => c.user === u);
  return { ag, online, cm, run: cm.filter(c => c.status === 'running'), queued: cm.filter(c => c.status === 'queued'), login: !ag || +ag.claude_ok === 1 };
}
function agentList() {
  return (data.users || []).map(u => {
    const a = agentInfo(u);
    const st = a.run.length ? 'working' : a.queued.length ? 'waiting' : a.online ? 'idle' : 'offline';
    const sub = (a.online ? 'onlayn' : a.ag ? 'oflayn' : 'qoşulmayıb') + (a.run.length ? ' · işləyir' : '') + (a.queued.length ? ' · ' + a.queued.length + ' növbədə' : '') + (a.online && !a.login ? ' · Claude girişi yoxdur' : '');
    return { name: 'agent:' + u, label: '🤖 ' + u, kind: 'agent', hue: hue(u), R: 3, st, sub,
      users: new Map(a.cm.map(c => [c.project, { st: c.status === 'running' ? 'working' : 'idle' }])), edits: 0, tools: 0, burn: 0, sats: [] };
  });
}
/* Agent Universe: yeni tapşırıq agentə raketlə çatır (tapşırığı başqası təyin edibsə, onun agentindən), bitəndə agent planetində partlayış */
let agentCmdInit = false; const agentCmdSeen = new Map();
function fireAgentRockets() {
  const cs = data.commands || [];
  if (!agentCmdInit) { agentCmdInit = true; for (const c of cs) agentCmdSeen.set(+c.id, c.status); return; }
  for (const c of cs.slice().reverse()) {
    const prev = agentCmdSeen.get(+c.id);
    if (prev === c.status) continue;
    agentCmdSeen.set(+c.id, c.status);
    if (!K || !glOn) continue;
    if (prev === undefined) {
      const t = (data.tasks || []).find(x => +x.command_id === +c.id);
      const from = t && t.creator !== c.user ? 'agent:' + t.creator : null;
      const rem = c.creator && c.creator !== c.user;                 // başqasının kompüterinə: göndərənin Desktop planetindən
      K.rocket({ id: 1e6 + +c.id, from: rem ? 'desktop:' + c.creator : from, to: rem ? 'desktop:' + c.user : 'agent:' + c.user, who: rem ? c.creator : c.project, hue: hue(c.creator || c.user), soft: true, delay: 0 });
    } else if (c.status === 'done' || c.status === 'failed') K.burst((c.creator && c.creator !== c.user ? 'desktop:' : 'agent:') + c.user, c.status === 'done');
  }
}
/* ====== Desktop Universe: başqasının kompüterindəki Claude Code-a komanda (YALNIZ sahibinin icazəsi ilə) ====== */
async function RMT(action, extra) {
  const r = await fetch('remote.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify({ action, ...extra }) });
  const d = await r.json().catch(() => ({}));
  if (r.status === 401) location.reload();
  return { status: r.status, d };
}
/** Uzun nəticə: 4 sətirdə kəsilir, "Tam göstər" ilə açılır */
const openRes = new Set();                        // açıq saxlanılan nəticələr (siyahı yenilənəndə yığılmasın)
function resultBox(text, key, label, hidden) {
  const w = el('div'), pb = el('div', hidden ? 'pb pbp' : 'pb', text); w.append(pb);
  if (hidden || text.length > 260 || text.split('\n').length > 4) {
    const lb = label || 'Tam göstər', t = el('button', 'pbt', '▾ ' + lb);
    const sync = o => { pb.classList.toggle('open', o); setText(t, o ? '▴ Yığ' : '▾ ' + lb); };
    t.onclick = () => { const o = !pb.classList.contains('open'); o ? openRes.add(key) : openRes.delete(key); sync(o); };
    sync(openRes.has(key));
    w.append(t);
  }
  return w;
}
/** Komandaya əlavə olunmuş şəkillər (klik: böyük aç) */
function imgStrip(c) {
  const imgs = c.images || [];
  if (!imgs.length) return null;
  const box = el('div', 'cmdimgs');
  for (const f of imgs) { const a = el('a'); a.href = 'cmdimg.php?f=' + encodeURIComponent(f); a.target = '_blank'; a.rel = 'noopener'; const d = el('div', 'cimg'), im = el('img'); im.src = a.href; im.alt = 'şəkil'; im.loading = 'lazy'; d.append(im); a.append(d); box.append(a); }
  return box;
}
/** Promptun tamamı: başlıqda yalnız ilk sətir görünür, uzundursa "Promptun tamamı" ilə açılır */
function promptBox(c) {
  const full = c.prompt.replace(/^\[[^\]]*\]\n/, '');
  return full.length > 120 || full.includes('\n') ? resultBox(full, 'p' + c.id, 'Promptun tamamı', true) : null;
}
function desktopList() {
  return (data.users || []).map(u => {
    const a = agentInfo(u), pend = a.cm.filter(c => c.status === 'pending').length;
    const st = pend ? 'waiting' : a.run.length ? 'working' : a.online ? 'idle' : 'offline';
    const gr = (data.grants || []).filter(g => g.owner === u).length;
    const sub = (a.online ? 'kompüter onlayn' : 'oflayn') + (pend ? ' · ' + pend + ' təsdiq gözləyir' : a.run.length ? ' · işləyir' : '') + (ME && ME.name === u ? ' · sənin kompüterin' : '');
    return { name: 'desktop:' + u, label: '🖥 ' + u, kind: 'desktop', hue: hue(u), R: 3, st, sub,
      users: new Map(), edits: 0, tools: 0, burn: 0, sats: [] };
  });
}
const myGrantOn = owner => { const gs = (data.grants || []).filter(g => g.owner === owner && ME && (g.grantee === ME.name || g.grantee === '*')); return gs.find(g => g.grantee === ME.name) || gs[0] || null; };
/* Desktop planeti: söhbət kimi. Yuxarıda vəziyyət zolağı, ortada komanda axını (köhnədən yeniyə), aşağıda yapışqan yazı qutusu.
   Sahib üçün: təsdiq gözləyənlər və icazələr axının üstündə. Hər blok yalnız öz imzası dəyişəndə yenilənir (yazılan mətn itməsin). */
const xdOpt = (v, t) => Object.assign(document.createElement('option'), { value: v, textContent: t });
const xdPrompt = c => c.prompt.replace(/^\[[^\]]*\]\n/, '');
/** Söhbətdə bir komanda: göndərilən mətn (sağda) və Claude Code-un cavabı/vəziyyəti (solda) */
function xdMessage(c, u, mine, a) {
  const w = el('div', 'xmsg' + (mine ? ' in' : ' out'));
  const q = el('div', 'xbub q'), text = xdPrompt(c), long = text.length > 420 || text.split('\n').length > 7;
  const body = el('div', 'xq', text); q.append(body);
  if (long) {
    const t = el('button', 'pbt'), key = 'p' + c.id, draw = o => { body.classList.toggle('clamp', !o); setText(t, o ? '▴ Yığ' : '▾ Tam göstər'); };
    t.onclick = () => { const o = body.classList.contains('clamp'); o ? openRes.add(key) : openRes.delete(key); draw(o); };
    draw(openRes.has(key)); q.append(t);
  }
  { const ib = imgStrip(c); if (ib) q.append(ib); }
  q.append(el('div', 'xm', (mine ? c.creator + ' · ' : '') + '🪐 ' + c.project + ' · ' + ago(c.created_at)));
  w.append(q);
  const [, dc] = CMD_ST[c.status] || [c.status, 'offline'];
  const r = el('div', 'xbub r'); r.dataset.s = c.status;
  const st = el('div', 'xst'), txt =
    c.status === 'pending' ? (mine ? 'Sənin təsdiqini gözləyir' : u + '-in təsdiqini gözləyir') :
    c.status === 'queued' ? (a.online ? 'Növbədə, indi başlayır…' : 'Növbədə · kompüter oflayndır, onlayn olanda başlayacaq') :
    c.status === 'running' ? 'Claude işləyir · ' + ago(c.started_at) :
    c.status === 'done' ? 'Bitdi · ' + ago(c.finished_at) :
    c.status === 'failed' ? 'Xəta · ' + ago(c.finished_at) :
    'Ləğv edildi' + (c.result ? ' · ' + c.result : '');
  st.append(el('span', 'dot ' + dc), el('span', null, txt)); r.append(st);
  if (c.result && c.status !== 'canceled') r.append(resultBox(c.result, c.id));
  if (['pending', 'queued', 'running'].includes(c.status)) {
    const pa = el('div', 'pa'), b = el('button', 'gbtn danger', c.status === 'running' ? '■ Dayandır' : '✕ Ləğv et');
    b.onclick = async () => { b.disabled = true; await CTL('stop_command', { id: c.id }); load(); }; pa.append(b); r.append(pa);
  }
  w.append(r);
  return w;
}
function renderDesktopPlanet(u) {
  const box = $('xd-info'); if (!ME) return;
  const mine = ME.name === u, a = agentInfo(u);
  const fresh = box._u !== u;
  if (fresh) { box._u = u; box.replaceChildren(el('div', 'xd-status'), el('div', 'xd-manage'), el('div', 'xthread'), el('div', 'xd-comp')); box._s = {}; box._imgs = []; box._fresh = false; box._prj = ''; box._addOpen = false; }
  const [status, manage, thread, comp] = box.children;
  const g = mine ? null : myGrantOn(u);
  const grants = (data.grants || []).filter(x => x.owner === u);
  const rc = a.cm.filter(c => c.creator && c.creator !== u && (mine || c.creator === ME.name));
  const pend = mine ? rc.filter(c => c.status === 'pending') : [];

  /* 1. Vəziyyət zolağı: bir baxışda kompüter, Claude girişi və icazə */
  const stSig = [u, a.online, a.login, !!a.run.length, a.ag && a.ag.last_seen, mine, g && g.mode, grants.map(x => x.grantee + x.mode).join(), Math.floor(now() / 20)].join('|');
  if (box._s.st !== stSig) {
    box._s.st = stSig; status.replaceChildren();
    const row = el('div', 'xd-row');
    row.append(el('span', 'dot ' + (a.online ? (a.run.length ? 'working' : 'idle') : 'offline')),
      el('b', null, a.online ? (a.run.length ? 'Kompüter onlayn · Claude işləyir' : 'Kompüter onlayn') : a.ag ? 'Kompüter oflayn · son ' + ago(a.ag.last_seen) : 'Agent hələ qoşulmayıb'));
    const inf = el('button', 'xd-i', 'i'); inf.type = 'button'; inf.title = 'Bu necə işləyir?'; inf.setAttribute('aria-label', 'Məlumat');
    row.append(el('span', 'sp'), inf); status.append(row);
    const pills = el('div', 'xd-pills');
    if (a.online && !a.login) pills.append(el('span', 'xd-pill warn', '⚠ Claude girişi yoxdur: komandalar növbədə gözləyəcək'));
    if (mine) pills.append(el('span', 'xd-pill ' + (grants.length ? 'ok' : 'no'), grants.length ? '🔐 İcazə: ' + grants.map(x => x.grantee === '*' ? 'hamı' : x.grantee).join(', ') : '🔒 Heç kimə icazə verməmisən'));
    else pills.append(el('span', 'xd-pill ' + (g ? (g.mode === 'auto' ? 'ok' : 'man') : 'no'), g ? (g.mode === 'auto' ? '✅ İcazən var · ⚡ komanda dərhal işə düşür' : '✅ İcazən var · ✋ ' + u + ' hər komandanı təsdiqləyir') : '🔒 ' + u + ' sənə icazə verməyib'));
    status.append(pills);
    const note = el('div', 'xd-note', mine
      ? 'Bura sənin kompüterindir. Yalnız icazə verdiyin adamlar komanda göndərə bilər; manual rejimdə hər komandanı sən təsdiqləyirsən, avtomatikdə dərhal işə düşür. İcazəni istənilən vaxt geri ala bilərsən.'
      : 'Komanda ' + u + '-in öz Claude hesabı ilə, onun kompüterində, agentin məhdud icazələri ilə işləyir: fayl oxuma və redaktə, git (pull, commit; push və merge yalnız sahibi "full" səviyyə seçibsə). Dağıdıcı git əmrləri (reset --hard, clean, zorla push) və digər terminal əmrləri bloklanıb. Nəticə bitəndə burada görünür.');
    note.hidden = !box._info; inf.classList.toggle('on', !!box._info);
    inf.onclick = () => { box._info = !box._info; note.hidden = !box._info; inf.classList.toggle('on', box._info); };
    status.append(note);
  }

  /* 2. Sahib: təsdiq gözləyənlər və icazələr. Qonaq (icazəsiz): icazə istə */
  const mSig = [u, mine, !!g, pend.map(c => c.id + (c.images || []).length).join(), grants.map(x => x.grantee + x.mode).join(), (data.users || []).join(), Math.floor(now() / 30)].join('|');
  if (box._s.m !== mSig) {
    box._s.m = mSig; manage.replaceChildren();
    if (mine) {
      if (pend.length) {
        manage.append(el('div', 'lbl', '⏳ Təsdiqini gözləyir · ' + pend.length));
        for (const c of pend) {
          const r = el('div', 'prow xd-pend');
          r.append(el('div', 'pt', c.creator + ' → 🪐 ' + c.project), el('div', 'pm', ago(c.created_at)), el('div', 'xq', xdPrompt(c)));
          { const ib = imgStrip(c); if (ib) r.append(ib); }
          const pa = el('div', 'pa'), ok = el('button', 'gbtn go', '✓ Təsdiq et'), no = el('button', 'gbtn danger', '✕ Rədd et');
          ok.onclick = async () => { ok.disabled = no.disabled = true; await RMT('approve', { id: c.id }); await load(); };
          no.onclick = async () => { ok.disabled = no.disabled = true; await RMT('deny', { id: c.id }); await load(); };
          pa.append(ok, no); r.append(pa); manage.append(r);
        }
      }
      const gh = el('div', 'lbl xd-lbl'); gh.append(el('span', null, '🔐 Kimə icazə vermisən'));
      const add = el('button', 'xd-tb', '+ İcazə ver'); add.type = 'button'; gh.append(add); manage.append(gh);
      if (!grants.length) manage.append(el('div', 'empty', 'Heç kimə. Heç kim kompüterinə komanda göndərə bilməz.'));
      for (const x of grants) {
        const r = el('div', 'xd-grant'), who = x.grantee === '*' ? 'Hamı' : x.grantee;
        r.append(el('b', null, who), el('span', 'xd-pill ' + (x.mode === 'auto' ? 'ok' : 'man'), x.mode === 'auto' ? '⚡ avtomatik' : '✋ təsdiqlə'), el('span', 'sp'));
        if (x.grantee !== '*') {                                     // rejimi icazəni silmədən dəyiş
          const tg = el('button', 'xd-tb', x.mode === 'auto' ? '✋ Təsdiqlə' : '⚡ Avtomatik'); tg.title = x.mode === 'auto' ? 'Manual rejimə keç: hər komandanı sən təsdiqləyirsən' : 'Avtomatik rejimə keç: komandalar təsdiqsiz işləyir';
          tg.onclick = async () => {
            const to = x.mode === 'auto' ? 'approve' : 'auto';
            if (to === 'auto' && !confirm(who + ' təsdiqsiz, birbaşa sənin kompüterində Claude Code-a fayl dəyişdirə biləcək komandalar göndərə biləcək. Avtomatik rejimə keçilsin?')) return;
            await RMT('grant', { grantee: x.grantee, mode: to }); await load();
          };
          r.append(tg);
        }
        const rv = el('button', 'xd-tb danger', 'Geri al'); rv.title = 'İcazəni geri al: gözləyən komandaları da ləğv olunur';
        rv.onclick = async () => { if (!confirm(who + ' üçün icazə geri alınsın?')) return; await RMT('revoke', { grantee: x.grantee }); await load(); };
        r.append(rv); manage.append(r);
      }
      const f = el('div', 'xd-add'); f.hidden = !box._addOpen;
      const sel = el('select'), mode = el('select');
      for (const x of data.users || []) if (x !== u && !grants.some(y => y.grantee === x)) sel.append(xdOpt(x, x));
      if (!grants.some(y => y.grantee === '*')) sel.append(xdOpt('*', 'Hamıya (yalnız təsdiqlə rejimi)'));
      mode.append(xdOpt('approve', '✋ Hər komandanı mən təsdiqləyim'), xdOpt('auto', '⚡ Təsdiqsiz, dərhal işləsin'));
      sel.onchange = () => { mode.disabled = sel.value === '*'; if (sel.value === '*') mode.value = 'approve'; }; sel.onchange();
      const gb = el('button', 'gbtn go', 'İcazə ver'), err = el('div', 'cmderr'); err.hidden = true;
      gb.onclick = async () => {
        err.hidden = true;
        if (!sel.value) { err.hidden = false; err.textContent = 'Hamıya artıq icazə verilib'; return; }
        if (mode.value === 'auto' && !confirm(sel.value + ' təsdiqsiz, birbaşa sənin kompüterində Claude Code-a fayl dəyişdirə biləcək komandalar göndərə biləcək. Əminsən?')) return;
        const { status, d } = await RMT('grant', { grantee: sel.value, mode: mode.value });
        if (status === 200) { box._addOpen = false; await load(); } else { err.hidden = false; err.textContent = d.error || 'Alınmadı'; }
      };
      const bar = el('div', 'cmdbar'); bar.append(el('span', 'cmdwho', 'İstədiyin vaxt geri ala bilərsən.'), gb);
      f.append(el('label', 'fl', 'Kimə'), sel, el('label', 'fl', 'Rejim'), mode, err, bar); manage.append(f);
      add.onclick = () => { box._addOpen = !box._addOpen; f.hidden = !box._addOpen; add.classList.toggle('on', box._addOpen); if (box._addOpen) sel.focus(); };
      add.classList.toggle('on', !!box._addOpen);
    } else if (!g) {
      const lock = el('div', 'xd-lock');
      lock.append(el('div', 'xd-lock-t', '🔒 ' + u + ' hələ sənə icazə verməyib'), el('div', 'mnote', 'Onun kompüterinə komanda göndərmək üçün ' + u + ' Desktop planetində sənə icazə verməlidir. Bir mesajla xatırlat:'));
      const rq = el('button', 'gbtn go', '✉ İcazə istə');
      rq.onclick = () => { openMsg(u); $('msg-body').value = 'Salam! Desktop Universe-də mənə Claude Code-una komanda göndərməyə icazə verə bilərsən? (Desktop planetinə gir → "+ İcazə ver")'; };
      lock.append(rq); manage.append(lock);
    }
  }

  /* 3. Söhbət axını: köhnədən yeniyə, ən sonuncusu yazı qutusunun üstündə */
  const list = rc.filter(c => c.status !== 'pending' || !mine).slice(0, 12).reverse();
  const tSig = [u, mine, a.online, list.map(c => c.id + c.status + (c.result || '').length).join(), Math.floor(now() / 30)].join('|');
  if (box._s.t !== tSig) {
    box._s.t = tSig; thread.replaceChildren();
    thread.append(el('div', 'lbl', mine ? '🖥 Kompüterinə göndərilənlər' : '🖥 Söhbət · ' + u + '-in Claude Code-u'));
    if (!list.length) thread.append(el('div', 'xd-empty', mine ? 'Hələ heç kim komanda göndərməyib.' : (g ? 'Hələ komanda göndərməmisən. Aşağıda yaz, Enter ilə göndər.' : 'İcazə alandan sonra burada söhbət görünəcək.')));
    for (const c of list) thread.append(xdMessage(c, u, mine, a));
  }

  /* 4. Yazı qutusu (yalnız icazəsi olan qonaq üçün): proyekt çipləri, böyüyən sahə, şəkil, yeni söhbət, Enter göndərir */
  const prjs = [...new Set((data.projects || []).filter(p => p.user === u).map(p => p.project))].sort();
  const cSig = [u, mine, g && g.mode, prjs.join(), a.online].join('|');
  if (box._s.c !== cSig) {
    box._s.c = cSig;
    const prevTa = comp.querySelector('textarea'), prevTxt = prevTa ? prevTa.value : '';
    comp.replaceChildren(); comp.hidden = mine || !g;
    if (!mine && g) {
      const key = 'xdprj:' + u; let prj = box._prj || ''; try { prj = prj || localStorage.getItem(key) || ''; } catch (e) {}
      if (!prjs.includes(prj)) prj = prjs[0] || '';
      const chips = el('div', 'filters xd-prj'), upd = () => { bt.disabled = !prj || !ta.value.trim(); };
      const drawChips = () => { for (const b of chips.children) b.classList.toggle('on', b.dataset.p === prj); };
      for (const p of prjs) { const b = el('button', null, '🪐 ' + p); b.type = 'button'; b.dataset.p = p; b.title = 'Komanda bu proyektin qovluğunda işləyəcək'; b.onclick = () => { prj = box._prj = p; try { localStorage.setItem(key, p); } catch (e) {} drawChips(); upd(); ta.focus(); }; chips.append(b); }
      if (!prjs.length) chips.append(el('span', 'xd-warn', u + ' hələ heç bir proyekt qurmayıb: onun kompüterində hook ilə Claude Code işlənməlidir.'));
      const ta = el('textarea'); ta.rows = 2; ta.maxLength = 3500; ta.placeholder = u + '-in kompüterində Claude Code nə etsin?'; ta.value = prevTxt;
      const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(260, ta.scrollHeight) + 'px'; };
      const err = el('div', 'cmderr'); err.hidden = true;
      const imgs = box._imgs, strip = el('div', 'cmdimgs'), file = el('input'); file.type = 'file'; file.accept = 'image/png,image/jpeg,image/gif,image/webp'; file.multiple = true; file.hidden = true;
      const drawImgs = () => { strip.hidden = !imgs.length; strip.replaceChildren(...imgs.map(im => { const d = el('div', 'cimg'), i = el('img'); i.src = im.url; i.alt = im.file.name; const x = el('button', 'rm', '✕'); x.type = 'button'; x.title = 'Şəkli sil'; x.onclick = () => { URL.revokeObjectURL(im.url); imgs.splice(imgs.indexOf(im), 1); drawImgs(); }; d.append(i, x); return d; })); };
      const addImgs = async files => { err.hidden = true; for (let f of files) { if (!CMD_IMG_TYPES.test(f.type)) { err.hidden = false; err.textContent = 'Yalnız PNG, JPEG, GIF və ya WebP şəkil'; continue; } if (imgs.length >= CMD_IMG_MAX) { err.hidden = false; err.textContent = 'Ən çox ' + CMD_IMG_MAX + ' şəkil'; break; } f = await shrinkImage(f); if (f.size > CMD_IMG_BYTES) { err.hidden = false; err.textContent = 'Şəkil çox böyükdür (ən çox 5 MB)'; continue; } imgs.push({ file: f, url: URL.createObjectURL(f) }); } drawImgs(); };
      const tool = el('div', 'xd-tool');
      const att = el('button', 'xd-tb', '🖼 Şəkil'); att.type = 'button'; att.title = 'Şəkil əlavə et (ən çox 4). Yapışdırmaq və ya qutuya sürüşdürmək də olar';
      att.onclick = () => file.click(); file.onchange = () => { addImgs([...file.files]); file.value = ''; };
      const fb = el('button', 'xd-tb' + (box._fresh ? ' on' : ''), '🆕 Yeni söhbət'); fb.type = 'button';
      fb.title = 'Seçilidirsə Claude əvvəlki söhbəti yadda saxlamadan sıfırdan başlayır. Seçilməyibsə eyni proyektdəki söhbət davam edir';
      fb.onclick = () => { box._fresh = !box._fresh; fb.classList.toggle('on', box._fresh); fb.setAttribute('aria-pressed', box._fresh); };
      fb.setAttribute('aria-pressed', !!box._fresh);
      const bt = el('button', 'gbtn go xd-send', 'Göndər ➤'); bt.type = 'button';
      tool.append(att, fb, el('span', 'sp'), el('span', 'xd-hint', 'Enter göndərir · Shift+Enter yeni sətir'), bt);
      const send = async () => {
        err.hidden = true; if (!prj) { err.hidden = false; err.textContent = 'Əvvəl proyekt seç'; return; } if (!ta.value.trim()) { ta.focus(); return; }
        bt.disabled = true; setText(bt, 'Göndərilir…');
        let res;
        try {
          if (imgs.length) {                                       // şəkilli: FormData (JSON yox)
            const fd = new FormData(); fd.append('action', 'send'); fd.append('target', u); fd.append('project', prj); fd.append('prompt', ta.value); fd.append('fresh', box._fresh ? '1' : '');
            for (const im of imgs) fd.append('images[]', im.file, im.file.name);
            const r = await fetch('remote.php', { method: 'POST', headers: { 'X-Requested-With': 'fetch' }, body: fd });
            res = { status: r.status, d: await r.json().catch(() => ({})) };
            if (r.status === 401) location.reload();
          } else res = await RMT('send', { target: u, project: prj, prompt: ta.value, fresh: !!box._fresh });
        } catch (e) { res = { status: 0, d: {} }; }
        const { status, d } = res;
        setText(bt, 'Göndər ➤');
        if (status === 200) {
          ta.value = ''; grow(); for (const im of imgs) URL.revokeObjectURL(im.url); imgs.length = 0; drawImgs(); box._fresh = false; fb.classList.remove('on'); fb.setAttribute('aria-pressed', 'false');
          miniToast(d.status === 'pending' ? '🖥 Göndərildi, ' + u + '-in təsdiqini gözləyir' : '🖥 Komanda ' + u + '-in kompüterinə yola düşdü');
          await load(); $('xdock').scrollTo({ top: 1e9, behavior: 'smooth' }); ta.focus();
        } else { err.hidden = false; err.textContent = status === 0 ? 'Bağlantı xətası' : (d.error || 'Göndərilmədi'); }
        upd();
      };
      bt.onclick = send;
      ta.addEventListener('input', () => { grow(); upd(); });
      ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } });   // Enter göndərir, Shift+Enter yeni sətir
      ta.addEventListener('paste', e => { const fs = [...((e.clipboardData && e.clipboardData.items) || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean); if (fs.length) { e.preventDefault(); addImgs(fs); } });
      ta.addEventListener('dragover', e => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); ta.classList.add('drop'); } });
      ta.addEventListener('dragleave', () => ta.classList.remove('drop'));
      ta.addEventListener('drop', e => { e.preventDefault(); ta.classList.remove('drop'); addImgs([...e.dataTransfer.files]); });
      const warn = el('div', 'xd-warn');
      warn.textContent = !a.online ? '⚠ Kompüter oflayndır: komanda növbəyə düşür, onlayn olanda işləyəcək.' : g.mode !== 'auto' ? '✋ ' + u + ' komandanı təsdiqləyəndən sonra işə düşəcək.' : '';
      warn.hidden = !warn.textContent;
      comp.append(chips, ta, strip, file, err, tool, warn); drawChips(); drawImgs(); upd(); requestAnimationFrame(grow);
    }
  }
  if (fresh) requestAnimationFrame(() => { $('xdock').scrollTop = $('xdock').scrollHeight; });   // söhbət kimi: ən yeni mesaj və yazı qutusu görünsün
}

function renderMcpPlanet(name) {
  const x = mcpServers().find(s => s.name === name);
  const box = $('xm-info'), evs = (data.events || []).filter(e => (e.tool || '').startsWith('mcp__' + name + '__')).slice(0, 12);
  const sig = [name, x && x.n, x && x.last, x && x.manual && x.manual.id, x && [...x.projects].join(), evs.map(e => e.id).join(), Math.floor(now() / 30)].join('|');
  if (box._sig === sig) return; box._sig = sig; box.replaceChildren();
  if (!x) { box.append(el('div', 'empty', 'Bu server artıq yoxdur.')); return; }
  const act = now() - x.last < 600;
  const head = el('div', 'infobox'), r = el('div', 'row2');
  r.append(el('b', null, (act ? '🟢 ' : '') + name), el('span', 'sp'));
  const ed = el('button', 'gbtn', x.manual ? '✎ Dəyiş' : '+ Qeyd et');
  ed.onclick = () => { mcpReset(); renderMcp(); if (x.manual) { mcpEdit = +x.manual.id; setText($('mcp-save'), 'Saxla'); setText($('mcp-ftitle'), 'Dəyiş: ' + name); $('mcp-cancel').hidden = false; } $('mcp-name').value = name; $('mcp-prj').value = x.manual ? x.manual.project : ''; $('mcp-descr').value = x.manual ? x.manual.descr : ''; openM('mcpm'); };
  r.append(ed); head.append(r);
  head.append(el('div', 'mnote', x.n ? `${x.n} çağırış (30 gün) · son: ${ago(x.last)}${act ? ' · indi işləyir' : ''}` : 'Hələ çağırış qeydə alınmayıb (əl ilə qeyd olunub).'));
  if (x.manual && x.manual.descr) head.append(el('div', null, x.manual.descr));
  box.append(head);
  box.append(el('div', 'lbl', '🪐 İşlədən proyektlər'));
  const chips = el('div', 'filters');
  for (const [pr, n] of [...x.projects].sort((a, b) => b[1] - a[1])) { const b = el('button', null, pr + (n ? ' · ' + n : '')); b.onclick = () => go(pr); chips.append(b); }
  if (!x.projects.size) chips.append(el('span', 'mnote', 'Yoxdur'));
  box.append(chips);
  box.append(el('div', 'lbl', '⚡ Son çağırışlar'));
  if (!evs.length) box.append(el('div', 'empty', 'Son hadisələrdə bu serverin çağırışı yoxdur.'));
  for (const e of evs) {
    const row = el('div', 'prow'); row.append(el('div', 'pt', e.tool.split('__').slice(2).join('__') || e.tool), el('div', 'pm', e.user + ' · ' + e.project + ' · ' + ago(e.created_at)));
    if (e.summary) row.append(el('div', 'pb', e.summary));
    box.append(row);
  }
}
function renderAgentPlanet(u) {
  const a = agentInfo(u), mine = ME && ME.name === u, admin = ME && ME.role === 'admin';
  const box = $('xa-info');
  const sig = [u, a.online, a.login, a.cm.map(c => c.id + c.status + (c.result || '').length).join(), Math.floor(now() / 15)].join('|');
  if (box._sig === sig) return; box._sig = sig; box.replaceChildren();
  const head = el('div', 'infobox');
  head.append(el('div', null, (a.online ? '🟢 Agent onlayn' : a.ag ? '⚫ Agent oflayn · son: ' + ago(a.ag.last_seen) : '⚫ Agent heç qoşulmayıb') + (a.online && !a.login ? ' · ⚠ Claude girişi yoxdur' : '')));
  const done = a.cm.filter(c => c.status === 'done').length, fail = a.cm.filter(c => c.status === 'failed').length;
  head.append(el('div', 'mnote', `${a.run.length} işləyir · ${a.queued.length} növbədə · ${done} bitdi · ${fail} xəta (son 40 tapşırıq içində)`));
  if (mine && !a.online) { const l = el('a', 'gbtn go', '▶ Agenti işə sal'); l.href = 'claudeteam://start'; l.style.textDecoration = 'none'; l.style.display = 'inline-block'; l.style.marginTop = '6px'; head.append(l); }
  box.append(head);
  const tasks = (data.tasks || []).filter(t => t.assignee === u && t.status !== 'done');
  if (tasks.length) {
    box.append(el('div', 'lbl', '📌 Ona təyin olunan açıq tapşırıqlar'));
    for (const t of tasks.slice(0, 6)) { const row = el('div', 'prow'); row.append(el('div', 'pt', t.title), el('div', 'pm', t.project + ' · ' + (TCOLS.find(c => c[0] === t.status) || [, t.status])[1] + (t.creator !== u ? ' · təyin edən ' + t.creator : ''))); box.append(row); }
  }
  box.append(el('div', 'lbl', '🔁 Avtomatlaşdırma axını'));
  if (!a.cm.length) box.append(el('div', 'empty', 'Bu agentə hələ tapşırıq göndərilməyib. Proyekt planetinin panelindən "Göndər" ilə prompt göndər.'));
  for (const c of a.cm.slice(0, 12)) {
    const [lbl] = CMD_ST[c.status] || [c.status];
    const row = el('div', 'prow'), t = (data.tasks || []).find(x => +x.command_id === +c.id);
    row.dataset.s = c.status === 'done' ? 'pass' : c.status === 'failed' ? 'fail' : '';
    const rem = c.creator && c.creator !== u;                      // Desktop Universe-dən uzaqdan göndərilib
    row.append(el('div', 'pt', xdPrompt(c).split('\n')[0].slice(0, 120)), el('div', 'pm', '🪐 ' + c.project + ' · ' + lbl + ' · ' + ago(c.created_at) + (rem ? ' · 🖥 ' + c.creator + ' → ' + u : t && t.creator !== u ? ' · ' + t.creator + ' → ' + u : '')));
    { const pbx = promptBox(c); if (pbx) row.append(pbx); const ib = imgStrip(c); if (ib) row.append(ib); }
    if (c.result) row.append(resultBox(c.result, c.id));
    if ((mine || admin) && (c.status === 'running' || c.status === 'queued')) { const pa = el('div', 'pa'), st = el('button', 'gbtn danger', 'Dayandır'); st.onclick = async () => { await CTL('stop_command', { id: c.id }); load(); }; pa.append(st); row.append(pa); }
    box.append(row);
  }
}

function shipOf(u) {
  const s = (data.ships || []).find(x => x.user === u) || { user: u, name: u + ' gəmisi', model: 'shuttle', color: '#cfd6e6', accent: '#f59e0b', deadline: 0, mission: '' };
  return shipDraft && ME && u === ME.name ? { ...s, ...shipDraft } : s;
}
function shipItems(names) {
  const live = liveMeetings();
  return [...new Set([...(data.users || []), ...(data.students || [])])].map(u => {
    const s = shipOf(u);
    const act = (data.sessions || []).filter(x => x.user === u).map(x => ({ ...x, st: eff(x) })).filter(x => RANK[x.st] > 0)
      .sort((a, b) => RANK[b.st] - RANK[a.st] || b.updated_at - a.updated_at);
    let planet = act.length ? act[0].project : null, st = act.length ? act[0].st : 'offline';
    if (live.some(m => m.attendees.split(',').includes(u))) { planet = 'Meeting Planet'; st = 'working'; }
    if (!planet && agentInfo(u).online) planet = 'agent:' + u;   // boş vaxtda: Agent Universe-də öz agentinin ətrafında
    const sw = !planet && (data.students || []).includes(u) && (data.worlds || []).find(w => w.owner === u);
    if (sw) planet = sw.name;                                    // student: həmişə öz planetinin ətrafında
    if (planet && !names.has(planet)) planet = null;
    const dl = +s.deadline, left = dl - now();
    const warn = !dl ? 0 : left < 0 ? 2 : left < DAY ? 1 : 0;
    const sub = u + (dl ? ' · ⏰ ' + (left < 0 ? fmtDur(-left) + ' keçib' : fmtDur(left)) : '');
    return { user: u, name: s.name, model: s.model, color: s.color, accent: s.accent, planet, st, warn, sub };
  });
}
/** Deadline-ına bir gündən az qalmış (və ya keçmiş) tapşırıqlar: planetin üstündə, planet kainatda yoxdursa icraçının gəmisinin üstündə */
function balloonItems(names) {
  const out = [];
  for (const t of data.tasks || []) {
    const dl = dueInfo(t);
    if (!dl || dl.left > DAY) continue;
    const o = { key: 't' + t.id, id: t.id, title: t.title, left: dl.text, level: dl.level, due: +t.due_at };
    if (names.has(t.project)) o.planet = t.project; else o.ship = t.assignee;
    out.push(o);
  }
  return out.sort((a, b) => a.due - b.due);
}

/* ---- Planet panelində əlavə məlumat: 24 saat qaydası, istifadəçi planeti, MCP peykləri ---- */
function renderDInfo(name) {
  const box = $('dinfo');
  const idle = idleOf(name), burn = burnOf(name);
  const w = (data.worlds || []).find(x => x.name === name);
  const sats = satsOf(name);
  const open = (data.tasks || []).filter(t => t.project === name && t.status !== 'done');
  const soon = open.map(t => ({ t, d: dueInfo(t) })).filter(x => x.d && x.d.left < 3 * DAY).sort((a, b) => a.d.left - b.d.left).slice(0, 4);
  const sig = [Math.floor(idle / 600), burn > 0, w && w.updated_at, sats.map(s => s.name + s.active).join(), open.length, soon.map(x => x.t.id + x.d.text).join(), ME && ME.name].join('|');
  if (box._sig === sig) return;
  box._sig = sig; box.replaceChildren();
  if (burn > 0) {
    const lv = burn >= .6 ? 3 : burn >= .3 ? 2 : 1;
    const b = el('div', 'burnbox'); b.dataset.lv = String(lv);
    b.append(el('b', null, ['', '💨 Tüstülənir', '🔥 Yanır', '💥 Xaos'][lv]), document.createTextNode(` · son ${fmtDur(idle)} ərzində heç bir tapşırıq bitməyib (${open.length} açıq). Bir tapşırığı bitir, planet sakitləşsin.`));
    box.append(b);
  }
  if (w) {
    const b = el('div', 'infobox'), r = el('div', 'row2');
    r.append(el('span', null, '🪐 ' + w.owner + ' planeti'), el('span', 'sp'));
    if (ME && (ME.role === 'admin' || ME.name === w.owner)) { const e = el('button', 'gbtn', '✎ Dəyiş'); e.onclick = () => openWorld(w); r.append(e); }
    b.append(r);
    if (w.descr) b.append(el('div', 'mnote', w.descr));
    box.append(b);
  }
  const tb = el('div', 'infobox'), tr = el('div', 'row2');
  tr.append(el('span', null, '📌 ' + open.length + ' açıq tapşırıq'), el('span', 'sp'));
  const add = el('button', 'gbtn', '+ tapşırıq'); add.onclick = () => openTaskModal(name); tr.append(add);
  tb.append(tr);
  for (const { t, d } of soon) { const l = el('div', 'mnote'), c = el('span', 'tdue', d.text); c.dataset.lv = String(d.level); l.append(document.createTextNode(t.title + ' · ' + t.assignee + ' '), c); tb.append(l); }
  box.append(tb);
  if (sats.length) {
    const b = el('div', 'infobox'), l = el('div', 'satl');
    b.append(el('div', 'mnote', '🛰 MCP peykləri'));
    for (const s of sats) { const c = el('span', null, s.name + (s.n ? ' · ' + s.n : '')); c.dataset.on = s.active ? '1' : ''; c.title = s.active ? 'Son 10 dəqiqədə işlədilib' : s.last ? 'Son: ' + ago(s.last) : 'Əl ilə qeyd olunub'; l.append(c); }
    b.append(l); box.append(b);
  }
}

/* ---- Gəmi: öz gəmini fərdiləşdir, deadline təyin et; başqasının gəmisinə bax ---- */
let shipModel = 'shuttle';
function openShip(u) {
  if (!data || !ME) return;
  u = u || ME.name;
  const s = shipOf(u), mine = u === ME.name;
  $('sh-edit').hidden = !mine; $('sh-view').hidden = mine;
  setText($('sh-title'), (mine ? '🚀 Mənim gəmim' : '🚀 ' + s.name));
  if (!mine) {
    const dl = +s.deadline, left = dl - now();
    const it = shipItems(new Set(spaceList(buildProjects()).map(p => p.name))).find(x => x.user === u);
    $('sh-view').replaceChildren(
      el('div', null, 'Pilot: ' + u),
      el('div', null, 'Model: ' + ({ shuttle: 'Şatl', saucer: 'Boşqab', fighter: 'Qırıcı' }[s.model] || s.model)),
      el('div', null, it && it.planet ? 'İndi: ' + it.planet + ' ətrafında' : 'İndi: kainatın kənarında patrul'),
      el('div', null, dl ? '⏰ Deadline: ' + when(dl) + (left < 0 ? ' (' + fmtDur(-left) + ' keçib)' : ' (' + fmtDur(left) + ' qalıb)') + (s.mission ? ' · ' + s.mission : '') : 'Deadline yoxdur'));
    openM('shipm'); return;
  }
  $('sh-name').value = s.name; $('sh-color').value = s.color; $('sh-accent').value = s.accent;
  $('sh-dl').value = toLocal(s.deadline); $('sh-mission').value = s.mission || '';
  shipModel = s.model;
  for (const b of $('sh-model').children) b.classList.toggle('on', b.dataset.m === shipModel);
  $('sh-err').hidden = true; setText($('sh-ok'), '');
  openM('shipm'); $('sh-name').focus();
}
function shipPreview() {
  shipDraft = { name: $('sh-name').value || (ME ? ME.name : ''), model: shipModel, color: $('sh-color').value, accent: $('sh-accent').value, deadline: fromLocal($('sh-dl').value), mission: $('sh-mission').value };
  render();
}
async function shipSave(clearDl) {
  if (clearDl) { $('sh-dl').value = ''; $('sh-mission').value = ''; }
  const e = $('sh-err'); e.hidden = true;
  const { status, d } = await WLD('ship_save', { name: $('sh-name').value, model: shipModel, color: $('sh-color').value, accent: $('sh-accent').value, deadline: fromLocal($('sh-dl').value), mission: $('sh-mission').value });
  if (status === 200) { setText($('sh-ok'), clearDl ? 'Deadline silindi ✓' : 'Saxlandı ✓'); await load(); shipDraft = null; render(); }
  else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
}

/* ---- İstifadəçi planeti: yarat və redaktə et ---- */
let wEdit = null;
function openWorld(w) {
  wEdit = w || null;
  const v = w || { name: '', descr: '', surface: 0, hue: Math.floor(Math.random() * 360), ring: 0, size: 2 };
  $('w-name').value = v.name; $('w-descr').value = v.descr || ''; $('w-hue').value = v.hue; $('w-ring').checked = !!+v.ring;
  for (const b of $('w-surf').children) b.classList.toggle('on', +b.dataset.s === +v.surface);
  for (const b of $('w-size').children) b.classList.toggle('on', +b.dataset.z === +v.size);
  setText($('w-title'), w ? '🪐 ' + w.name : '🪐 Yeni planet'); setText($('w-save'), w ? 'Saxla' : 'Yarat');
  $('w-del').hidden = !w || isStu(); $('w-err').hidden = true;   // student öz planetini silə bilməz, yalnız dəyişir
  openM('wm'); worldPreview(); $('w-name').focus();
}
function worldForm() {
  const pick = (box, k) => { const b = [...box.children].find(x => x.classList.contains('on')); return b ? +b.dataset[k] : 0; };
  return { name: $('w-name').value.trim(), descr: $('w-descr').value, surface: pick($('w-surf'), 's'), hue: +$('w-hue').value, ring: $('w-ring').checked ? 1 : 0, size: pick($('w-size'), 'z') || 2 };
}
function worldPreview() {
  const f = worldForm();
  $('w-sw').style.background = `hsl(${f.hue} 50% 56%)`;
  wDraft = { ...f, id: wEdit ? wEdit.id : 0, name: f.name || (wEdit ? wEdit.name : 'Yeni planet') };
  render();
}

/* ---- MCP peykləri ---- */
let mcpEdit = 0;
function renderMcp() {
  const auto = $('mcp-auto'); auto.replaceChildren();
  const use = (data.mcp_use || []).slice().sort((a, b) => b.last_at - a.last_at);
  if (!use.length) auto.append(el('div', 'mnote', 'Hələ heç bir MCP aləti işlədilməyib.'));
  for (const u of use) {
    const r = el('div', 'urow'); r.append(el('span', 'un', '🛰 ' + u.name), el('span', 'ur', u.project));
    r.append(el('span', 'us', u.n + ' çağırış · ' + ago(u.last_at) + (now() - u.last_at < 600 ? ' · ● aktiv' : ''))); auto.append(r);
  }
  const box = $('mcp-list'); box.replaceChildren();
  const list = data.mcp || [];
  if (!list.length) box.append(el('div', 'mnote', 'Hələ əl ilə qeyd olunan peyk yoxdur.'));
  for (const m of list) {
    const r = el('div', 'urow'); r.append(el('span', 'un', '🛰 ' + m.name), el('span', 'ur', m.project || '☀ günəş'));
    r.append(el('span', 'us', (m.descr ? m.descr + ' · ' : '') + m.owner));
    if (ME && (ME.role === 'admin' || ME.name === m.owner)) {
      const e = el('button', 'gbtn', 'Dəyiş'), x = el('button', 'gbtn danger', 'Sil');
      e.onclick = () => { mcpEdit = +m.id; $('mcp-name').value = m.name; $('mcp-prj').value = m.project; $('mcp-descr').value = m.descr; setText($('mcp-save'), 'Saxla'); setText($('mcp-ftitle'), 'Dəyiş: ' + m.name); $('mcp-cancel').hidden = false; };
      x.onclick = async () => { if (!confirm('"' + m.name + '" peyki silinsin?')) return; await WLD('mcp_delete', { id: m.id }); await load(); renderMcp(); };
      r.append(e, x);
    }
    box.append(r);
  }
  const prjs = [...new Set([...(data.projects || []).map(p => p.project), ...(data.worlds || []).map(w => w.name), ...SPECIALS.map(x => x.name)])].sort();
  $('mcp-prjs').replaceChildren(...prjs.map(p => Object.assign(document.createElement('option'), { value: p })));
}
function mcpReset() { mcpEdit = 0; $('mcp-name').value = ''; $('mcp-descr').value = ''; setText($('mcp-save'), 'Əlavə et'); setText($('mcp-ftitle'), 'Yeni peyk'); $('mcp-cancel').hidden = true; $('mcp-err').hidden = true; }

/* ---- Xüsusi planetlərin paneli ---- */
function renderX(name) {
  const sp = xKind(name);
  setText($('xtitle'), sp.icon + ' ' + sp.name);
  for (const k of ['prompt', 'designer', 'meeting', 'test', 'mcpu', 'agentu', 'deskt']) $('x-' + k).hidden = k !== sp.kind;
  if (sp.kind === 'deskt') { setText($('xmeta'), ME && ME.name === sp.name ? 'Sənin kompüterin · kimə uzaqdan komanda icazəsi verdiyin' : sp.name + '-in kompüteri · Claude Code-una uzaqdan komanda'); return renderDesktopPlanet(sp.name); }
  if (sp.kind === 'mcpu') { setText($('xmeta'), 'MCP Universe · server, onu işlədən proyektlər və son çağırışlar'); return renderMcpPlanet(sp.name); }
  if (sp.kind === 'agentu') { setText($('xmeta'), 'Agent Universe · ' + sp.name + ' istifadəçisinin AI agenti'); return renderAgentPlanet(sp.name); }
  if (sp.kind === 'prompt') { setText($('xmeta'), 'AI promptlarını yarat, saxla və komanda ilə paylaş'); renderPrompts(); }
  else if (sp.kind === 'designer') { setText($('xmeta'), 'Dizayn işləri, kreativ layihələr və dizayn tapşırıqları'); renderDesigner(); }
  else if (sp.kind === 'test') { setText($('xmeta'), 'Proyekt seç və test et: ssenarilər, nəticələr, Claude ilə yoxlama'); renderTests(); }
  else { setText($('xmeta'), 'Görüşlər və müzakirələr üçün neytral planet'); renderMeetings(); }
}

/* Prompt Generator */
const PG_ROLES = [
  ['dev', 'Təcrübəli proqramçı', 'an experienced senior software engineer', 'опытный senior-разработчик'],
  ['design', 'UI/UX dizayner', 'a senior UI/UX designer', 'опытный UI/UX-дизайнер'],
  ['mkt', 'Marketinq mütəxəssisi', 'a performance marketing expert', 'эксперт по маркетингу'],
  ['analyst', 'Data analitik', 'a data analyst', 'аналитик данных'],
  ['writer', 'Kopirayter', 'a professional copywriter', 'профессиональный копирайтер'],
  ['pm', 'Məhsul meneceri', 'a product manager', 'продакт-менеджер'],
  ['teacher', 'Müəllim', 'a patient teacher', 'терпеливый преподаватель'],
  ['custom', 'Öz rolum…', '', ''],
];
const PG_FMT = [
  ['md', 'Markdown, başlıqlarla', 'Markdown with headings', 'Markdown с заголовками'],
  ['list', 'Qısa siyahı', 'a short bullet list', 'короткий маркированный список'],
  ['table', 'Cədvəl', 'a table', 'таблица'],
  ['json', 'JSON', 'valid JSON only, no extra text', 'только валидный JSON, без лишнего текста'],
  ['code', 'Kod (izahla)', 'code with a brief explanation', 'код с кратким пояснением'],
  ['steps', 'Addım-addım plan', 'a numbered step-by-step plan', 'пошаговый нумерованный план'],
  ['free', 'Sərbəst mətn', 'free-form prose', 'свободный текст'],
];
const PG_TONE = [
  ['pro', 'Peşəkar', 'professional', 'профессиональный'],
  ['simple', 'Sadə, başa düşülən', 'simple and easy to understand', 'простой и понятный'],
  ['short', 'Qısa və konkret', 'brief and to the point', 'кратко и по делу'],
  ['deep', 'Ətraflı', 'thorough and detailed', 'подробный'],
];
const PG_T = {
  az: { role: 'Rol', roleL: r => `Sən ${/^\p{Lu}\p{Ll}/u.test(r) ? r[0].toLocaleLowerCase('az') + r.slice(1) : r} kimi çıxış edirsən.`, task: 'Tapşırıq', ctx: 'Kontekst', con: 'Məhdudiyyətlər', fmt: 'Cavabın formatı', tone: 'Ton', ex: 'Nümunə', how: 'Necə işləməli',
    steps: 'Cavab verməzdən əvvəl tapşırığı addım-addım düşün.', ask: 'Məlumat çatmırsa, əvvəlcə aydınlaşdırıcı suallar ver, sonra cavab yaz.', lang: 'Cavabı Azərbaycan dilində yaz.', col: 1 },
  en: { role: 'Role', roleL: r => `You are ${r}.`, task: 'Task', ctx: 'Context', con: 'Constraints', fmt: 'Output format', tone: 'Tone', ex: 'Example', how: 'How to work',
    steps: 'Think through the task step by step before answering.', ask: 'If information is missing, ask clarifying questions first, then answer.', lang: 'Answer in English.', col: 2 },
  ru: { role: 'Роль', roleL: r => `Ты — ${r}.`, task: 'Задача', ctx: 'Контекст', con: 'Ограничения', fmt: 'Формат ответа', tone: 'Тон', ex: 'Пример', how: 'Как работать',
    steps: 'Прежде чем отвечать, продумай задачу шаг за шагом.', ask: 'Если информации не хватает, сначала задай уточняющие вопросы, потом отвечай.', lang: 'Отвечай на русском языке.', col: 3 },
};
let pgEditId = 0;
function buildPrompt() {
  const L = PG_T[$('pg-lang').value] || PG_T.az, c = L.col;
  const pick = (arr, id) => (arr.find(x => x[0] === $(id).value) || arr[0]);
  const role = $('pg-role').value === 'custom' ? $('pg-role-c').value.trim() : pick(PG_ROLES, 'pg-role')[c] || pick(PG_ROLES, 'pg-role')[1];
  const goal = $('pg-goal').value.trim(), ctx = $('pg-ctx').value.trim(), ex = $('pg-ex').value.trim();
  const cons = $('pg-con').value.split('\n').map(x => x.trim().replace(/^[-•*]\s*/, '')).filter(Boolean);
  if (!goal) return null;
  const P = [];
  if (role) P.push('# ' + L.role, L.roleL(role), '');
  P.push('# ' + L.task, goal, '');
  if (ctx) P.push('# ' + L.ctx, ctx, '');
  if (cons.length) P.push('# ' + L.con, ...cons.map(x => '- ' + x), '');
  P.push('# ' + L.fmt, pick(PG_FMT, 'pg-fmt')[c], '');
  P.push('# ' + L.tone, pick(PG_TONE, 'pg-tone')[c], '');
  if (ex) P.push('# ' + L.ex, ex, '');
  const how = [];
  if ($('pg-steps').checked) how.push(L.steps);
  if ($('pg-ask').checked) how.push(L.ask);
  how.push(L.lang);
  P.push('# ' + L.how, ...how.map(x => '- ' + x));
  return P.join('\n');
}
function makePromptRow() {
  const n = el('div', 'prow'), pt = el('div', 'pt'), pm = el('div', 'pm'), pb = el('div', 'pb'), pa = el('div', 'pa');
  const cp = el('button', 'gbtn', 'Kopyala'), ed = el('button', 'gbtn', 'Redaktə'), del = el('button', 'gbtn danger', 'Sil');
  pa.append(cp, ed, del); n.append(pt, pm, pb, pa);
  cp.onclick = async () => { try { await navigator.clipboard.writeText(n._it.body); setText(cp, 'Kopyalandı ✓'); } catch (e) { setText(cp, 'Alınmadı'); } setTimeout(() => setText(cp, 'Kopyala'), 1600); };
  ed.onclick = () => { const x = n._it; pgEditId = +x.id; $('pg-out').value = x.body; $('pg-title').value = x.title; $('pg-tags').value = x.tags; setText($('pg-edit'), 'Redaktə: ' + x.title); setText($('pg-save'), 'Yadda saxla'); $('pg-new').hidden = false; $('pg-out').focus(); };
  del.onclick = async () => { if (!confirm('"' + n._it.title + '" silinsin?')) return; const { status, d } = await WLD('prompt_delete', { id: n._it.id }); if (status !== 200) alert(d.error || 'Alınmadı'); if (+n._it.id === pgEditId) pgReset(); load(); };
  n._r = { pt, pm, pb, ed, del };
  return n;
}
function renderPrompts() {
  const q = $('pg-q').value.trim().toLowerCase();
  const all = data.prompts || [];
  const list = all.filter(p => !q || (p.title + ' ' + p.tags + ' ' + p.body).toLowerCase().includes(q));
  setText($('pg-cnt'), String(all.length)); $('pg-empty').hidden = list.length > 0;
  sync($('pg-list'), list, p => p.id, makePromptRow, (n, p) => {
    n._it = p; const r = n._r, own = ME && (ME.role === 'admin' || ME.name === p.owner);
    setText(r.pt, p.title); setText(r.pm, p.owner + ' · ' + ago(p.updated_at) + (p.tags ? ' · #' + p.tags.split(',').map(x => x.trim()).filter(Boolean).join(' #') : ''));
    setText(r.pb, p.body); r.ed.hidden = r.del.hidden = !own;
  });
}
function pgReset() { pgEditId = 0; setText($('pg-edit'), ''); setText($('pg-save'), 'Kitabxanaya saxla'); $('pg-new').hidden = true; $('pg-err').hidden = true; }

/* Designer */
function renderDesigner() {
  const P = 'Designer';
  const plans = (data.plans || []).filter(x => x.project === P).sort((a, b) => (a.status === 'done') - (b.status === 'done') || a.id - b.id);
  const tasks = (data.tasks || []).filter(t => t.project === P).sort((a, b) => TCOLS.findIndex(c => c[0] === a.status) - TCOLS.findIndex(c => c[0] === b.status) || (+a.due_at || 9e9) - (+b.due_at || 9e9));
  setText($('ds-pcnt'), String(plans.length)); setText($('ds-tcnt'), String(tasks.length));
  $('ds-pempty').hidden = plans.length > 0; $('ds-tempty').hidden = tasks.length > 0;
  sync($('ds-plans'), plans, x => x.id, makePlItem, (it, x) => {
    const q = it._r; it._it = x;
    setText(q.pt, x.title); setText(q.pd, x.details || ''); q.pd.hidden = !x.details;
    setCls(it, 'pli' + (x.status === 'done' ? ' done' : ''));
    setText(q.pm, x.creator + ' · ' + ago(x.created_at)); q.pa.firstChild.hidden = q.pa.lastChild.hidden = !canEditPlan(x);
  });
  sync($('ds-tasks'), tasks, t => t.id, makeTask, updTask);
  const box = $('ds-info'), burn = burnOf(P);
  const sig = String(Math.floor(idleOf(P) / 600)) + burn;
  if (box._sig !== sig) {
    box._sig = sig; box.replaceChildren();
    if (burn > 0) { const b = el('div', 'burnbox'); b.dataset.lv = burn >= .6 ? '3' : '2'; b.textContent = `🔥 Son ${fmtDur(idleOf(P))} ərzində heç bir dizayn tapşırığı bitməyib. Birini bitir, planet sakitləşsin.`; box.append(b); }
  }
}

/* Meeting Planet */
function makeMeeting() {
  const n = el('div', 'mcard'), mt = el('div', 'mt'), ttl = el('span'), lv = el('span', 'lvb', 'CANLI'), mw = el('div', 'mw'), who = el('div', 'who'), ma = el('div', 'ma');
  mt.append(ttl, lv);
  const posts = el('div', 'posts'), pin = el('div', 'pin'), inp = el('input'), send = el('button', 'gbtn go', 'Yaz');
  inp.maxLength = 2000; inp.placeholder = 'Müzakirəyə yaz…'; pin.append(inp, send);
  const pa = el('div', 'pa'), ed = el('button', 'gbtn', 'Dəyiş'), del = el('button', 'gbtn danger', 'Sil'); pa.append(ed, del);
  n.append(mt, mw, who, ma, posts, pin, pa);
  const post = async () => {
    const body = inp.value.trim(); if (!body) return;
    send.disabled = true; const { status, d } = await WLD('meeting_post', { meeting_id: n._it.id, body }); send.disabled = false;
    if (status === 200) { inp.value = ''; load(); } else alert(d.error || 'Alınmadı');
  };
  send.onclick = post; inp.addEventListener('keydown', e => { if (e.key === 'Enter') post(); });
  ed.onclick = () => openMeeting(n._it);
  del.onclick = async () => { if (!confirm('"' + n._it.title + '" görüşü silinsin?')) return; await WLD('meeting_delete', { id: n._it.id }); load(); };
  n._r = { ttl, lv, mw, who, ma, posts, pa, sig: '', psig: '' };
  return n;
}
function updMeeting(n, m) {
  n._it = m; const r = n._r, t = now(), end = +m.starts_at + m.minutes * 60, live = +m.starts_at <= t && t < end;
  n.dataset.live = live ? '1' : '';
  r.lv.hidden = !live; setText(r.ttl, m.title);
  setText(r.mw, when(m.starts_at) + ' · ' + m.minutes + ' dəq · ' + (live ? fmtDur(end - t) + ' qalıb' : t < +m.starts_at ? fmtDur(+m.starts_at - t) + ' sonra' : 'bitib') + ' · təşkil edən ' + m.creator);
  const sig = m.attendees + '|' + m.agenda;
  if (r.sig !== sig) { r.sig = sig; r.who.replaceChildren(...m.attendees.split(',').filter(Boolean).map(u => { const a = avatar(u); a.title = u; return a; })); setText(r.ma, m.agenda || ''); r.ma.hidden = !m.agenda; }
  const ps = (data.meeting_posts || []).filter(p => +p.meeting_id === +m.id);
  const psig = ps.map(p => p.id).join();
  if (r.psig !== psig) {
    r.psig = psig;
    r.posts.replaceChildren(...ps.map(p => { const d = el('div', 'post'); const b = el('b', null, p.user + ': '); b.style.color = tint(p.user); d.append(b, document.createTextNode(p.body), el('small', null, clock(p.created_at).slice(0, 5))); return d; }));
  }
  r.pa.hidden = !(ME && (ME.role === 'admin' || ME.name === m.creator));
}
function renderMeetings() {
  const t = now(), ms = data.meetings || [];
  const live = ms.filter(m => +m.starts_at <= t && t < +m.starts_at + m.minutes * 60);
  const next = ms.filter(m => +m.starts_at > t);
  const past = ms.filter(m => +m.starts_at + m.minutes * 60 <= t).reverse();
  for (const [id, list] of [['mt-live', live], ['mt-next', next], ['mt-past', past]]) {
    $(id + '-e').hidden = list.length > 0;
    sync($(id), list, m => m.id, makeMeeting, updMeeting);
  }
}
let mtEdit = 0;
function openMeeting(m) {
  mtEdit = m ? +m.id : 0;
  const start = m ? +m.starts_at : Math.ceil(now() / 1800) * 1800;
  $('mt-ttl').value = m ? m.title : ''; $('mt-agenda').value = m ? m.agenda : ''; $('mt-at').value = toLocal(start); $('mt-min').value = String(m ? m.minutes : 30);
  const att = m ? m.attendees.split(',') : (data.users || []);
  $('mt-att').replaceChildren(...(data.users || []).map(u => { const l = el('label', 'chk2'), c = el('input'); c.type = 'checkbox'; c.value = u; c.checked = att.includes(u); l.append(c, document.createTextNode(u)); return l; }));
  setText($('mt-title'), m ? '🤝 Görüşü dəyiş' : '🤝 Görüş təyin et'); $('mt-err').hidden = true;
  openM('mtm'); $('mt-ttl').focus();
}

/* Test Planet: proyekt seç → ssenariləri yoxla (keçdi / xəta), yeni raund, Claude-a test promptu */
let tsProject = (() => { try { return localStorage.getItem('tdtest') || null; } catch (e) { return null; } })(), tsEdit = 0;
const TS_ST = { todo: 'yoxlanmayıb', pass: '✓ keçdi', fail: '✗ xəta' };
async function tsSet(t, status) {
  let note = '';
  if (status === 'fail') { note = prompt('Nə səhv oldu? (istəyə bağlı)', t.note || ''); if (note === null) return; }
  const { status: st, d } = await WLD('test_status', { id: t.id, status, note });
  if (st !== 200) alert(d.error || 'Alınmadı'); load();
}
function makeTestRow() {
  const n = el('div', 'prow'), pt = el('div', 'pt'), ttl = el('span'), sst = el('span', 'tsst'), pm = el('div', 'pm'), pb = el('div', 'pb'), note = el('div', 'tnote'), pa = el('div', 'pa');
  pt.append(ttl, sst);
  const ok = el('button', 'gbtn', '✓ Keçdi'), bad = el('button', 'gbtn danger', '✗ Xəta'), rs = el('button', 'gbtn', '↺'), ed = el('button', 'gbtn', '✎'), del = el('button', 'gbtn danger', '🗑');
  rs.title = 'Yoxlanmayıb et'; ed.title = 'Dəyiş'; del.title = 'Sil';
  pa.append(ok, bad, rs, ed, del); n.append(pt, pm, pb, note, pa);
  ok.onclick = () => tsSet(n._it, 'pass'); bad.onclick = () => tsSet(n._it, 'fail'); rs.onclick = () => tsSet(n._it, 'todo');
  ed.onclick = () => { const t = n._it; tsEdit = +t.id; $('ts-ttl').value = t.title; $('ts-steps').value = t.steps; setText($('ts-add'), 'Saxla'); $('ts-cancel').hidden = false; $('ts-ttl').focus(); };
  del.onclick = async () => { if (!confirm('"' + n._it.title + '" silinsin?')) return; await WLD('test_delete', { id: n._it.id }); load(); };
  n._r = { ttl, sst, pm, pb, note, ok, rs, ed, del };
  return n;
}
function tsReset() { tsEdit = 0; $('ts-ttl').value = ''; $('ts-steps').value = ''; setText($('ts-add'), '+ Ssenari'); $('ts-cancel').hidden = true; $('ts-err').hidden = true; }
function tsAiDefault(prj, list) {
  const open = list.filter(t => t.status !== 'pass');
  return `"${prj}" proyektini test et:\n1. Mövcud testləri tap və nəzərdən keçir.\n2. Əsas funksiyalar üçün çatışmayan testləri yaz.\n3. Kodda tapdığın səhvləri və riskləri qısa siyahı ilə bildir (fayl və sətir göstər).`
    + (open.length ? '\n\nXüsusilə bu ssenariləri yoxla:\n' + open.slice(0, 12).map(t => '- ' + t.title + (t.status === 'fail' && t.note ? ' (xəta: ' + t.note + ')' : '')).join('\n') : '');
}
function renderTests() {
  const names = [...new Set([...buildProjects().map(p => p.name), ...(data.worlds || []).map(w => w.name), ...(data.tests || []).map(t => t.project)])].filter(n => n !== 'Test Planet').sort((a, b) => a.localeCompare(b));
  $('ts-noprj').hidden = names.length > 0;
  if (tsProject && !names.includes(tsProject)) tsProject = null;
  const cnt = n => { const l = (data.tests || []).filter(t => t.project === n); const f = l.filter(t => t.status === 'fail').length; return l.length ? ' · ' + (f ? f + '✗' : l.filter(t => t.status === 'pass').length + '/' + l.length) : ''; };
  sync($('ts-prjs'), names, n => n, () => el('button'), (b, n) => {
    setText(b, n + cnt(n)); setCls(b, n === tsProject ? 'on' : '');
    b.onclick = () => { tsProject = n; try { localStorage.setItem('tdtest', n); } catch (e) {} tsReset(); $('ts-ai').value = ''; renderTests(); };
  }, { enter: false });
  $('ts-body').hidden = !tsProject;
  if (!tsProject) return;
  const list = (data.tests || []).filter(t => t.project === tsProject);
  const pass = list.filter(t => t.status === 'pass').length, fail = list.filter(t => t.status === 'fail').length, todo = list.length - pass - fail;
  const last = list.reduce((m, t) => Math.max(m, +t.tested_at || 0), 0);
  const sum = $('ts-sum'), sig = [tsProject, pass, fail, todo, last].join('|');
  if (sum._sig !== sig) {
    sum._sig = sig; sum.replaceChildren();
    const r = el('div', 'row2'); r.append(el('b', null, '🧪 ' + tsProject), el('span', 'sp'));
    const goB = el('button', 'gbtn', 'Planetə get →'); goB.onclick = () => go(tsProject);
    const rnd = el('button', 'gbtn', '↺ Yeni raund'); rnd.title = 'Bütün ssenariləri "yoxlanmayıb" et';
    rnd.onclick = async () => { if (!confirm('Bütün nəticələr sıfırlansın və yeni test raundu başlasın?')) return; await WLD('test_reset', { project: tsProject }); load(); };
    r.append(rnd, goB); sum.append(r);
    if (list.length) {
      const bar = el('div', 'tsbar'), P = el('i', 'p'), F = el('i', 'f'); P.style.width = (pass / list.length * 100) + '%'; F.style.width = (fail / list.length * 100) + '%'; bar.append(P, F); sum.append(bar);
      sum.append(el('div', 'mnote', `${pass} keçdi · ${fail} xəta · ${todo} yoxlanmayıb` + (last ? ' · son yoxlama ' + ago(last) : '')));
    } else sum.append(el('div', 'mnote', 'Ssenari əlavə et, sonra hər birini yoxlayıb nəticəni qeyd et.'));
  }
  const order = { fail: 0, todo: 1, pass: 2 };
  const rows = list.slice().sort((a, b) => order[a.status] - order[b.status] || a.id - b.id);
  setText($('ts-cnt'), String(list.length)); $('ts-empty').hidden = list.length > 0;
  sync($('ts-list'), rows, t => t.id, makeTestRow, (n, t) => {
    n._it = t; const r = n._r, own = ME && (ME.role === 'admin' || ME.name === t.creator);
    n.dataset.s = t.status; setText(r.ttl, t.title); setText(r.sst, TS_ST[t.status] || t.status);
    setText(r.pm, t.tested_at ? (t.status === 'pass' ? 'keçdi: ' : 'xəta: ') + t.tested_by + ', ' + ago(t.tested_at) : 'yaradan ' + t.creator);
    setText(r.pb, t.steps || ''); r.pb.hidden = !t.steps;
    setText(r.note, t.status === 'fail' && t.note ? '⚠ ' + t.note : ''); r.note.hidden = !(t.status === 'fail' && t.note);
    r.ok.hidden = t.status === 'pass'; r.rs.hidden = t.status === 'todo'; r.ed.hidden = r.del.hidden = !own;
  });
  if (!$('ts-ai').value || $('ts-ai')._auto === $('ts-ai').value) { const v = tsAiDefault(tsProject, list); $('ts-ai').value = v; $('ts-ai')._auto = v; }
  const myTok = !!(getTok() && myName);
  setText($('ts-aiwho'), myTok ? 'Göndərən: ' + myName : 'Əvvəlcə tokenini daxil et (planetin panelində "Göndər" bölməsi)');
  $('ts-aisend').disabled = !myTok;
  const cmds = (data.commands || []).filter(c => c.project === tsProject).slice(0, 4);
  sync($('ts-cmds'), cmds, c => c.id, () => { const n = el('div', 'prow'), pm = el('div', 'pm'), pb = el('div', 'pb'); n.append(pm, pb); n._r = { pm, pb }; return n; }, (n, c) => {
    const [lbl] = CMD_ST[c.status] || [c.status];
    setText(n._r.pm, '🤖 ' + c.user + ' · ' + lbl + ' · ' + ago(c.created_at)); setText(n._r.pb, c.result || c.prompt);
  });
}

function initWorld() {
  // Test Planet
  $('ts-cancel').onclick = tsReset;
  $('ts-add').onclick = async () => {
    const e = $('ts-err'); e.hidden = true;
    const { status, d } = await WLD('test_save', { id: tsEdit, project: tsProject, title: $('ts-ttl').value, steps: $('ts-steps').value });
    if (status === 200) { tsReset(); load(); } else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('ts-ttl').addEventListener('keydown', e => { if (e.key === 'Enter') $('ts-add').click(); });
  $('ts-aisend').onclick = async () => {
    const e = $('ts-aierr'), text = $('ts-ai').value.trim(); e.hidden = true; if (!text || !tsProject) return;
    $('ts-aisend').disabled = true;
    const { status, d } = await cmdApi('send', { project: tsProject, prompt: text });
    $('ts-aisend').disabled = false;
    if (status === 200) { setText($('ts-aiwho'), 'Göndərildi ✓ agent götürəndə işləyəcək'); load(); }
    else { e.hidden = false; e.textContent = status === 401 ? 'Token yanlışdır' : (d.error || 'Alınmadı'); }
  };
  // gəmi
  $('t-ship').onclick = () => openShip();
  for (const b of $('sh-model').children) b.onclick = () => { shipModel = b.dataset.m; for (const x of $('sh-model').children) x.classList.toggle('on', x === b); shipPreview(); };
  for (const id of ['sh-name', 'sh-color', 'sh-accent', 'sh-dl', 'sh-mission']) $(id).addEventListener('input', shipPreview);
  $('sh-save').onclick = () => shipSave(false);
  $('sh-dlclear').onclick = () => shipSave(true);
  // planet
  $('t-world').onclick = () => openWorld(isStu() ? (data && data.worlds || []).find(w => ME && w.owner === ME.name) || null : null);
  for (const box of ['w-surf', 'w-size']) for (const b of $(box).children) b.onclick = () => { for (const x of $(box).children) x.classList.toggle('on', x === b); worldPreview(); };
  for (const id of ['w-name', 'w-hue', 'w-ring']) $(id).addEventListener('input', worldPreview);
  $('w-save').onclick = async () => {
    const f = worldForm(), e = $('w-err'); e.hidden = true; $('w-save').disabled = true;
    const { status, d } = await WLD('planet_save', { ...f, id: wEdit ? wEdit.id : 0 });
    $('w-save').disabled = false;
    if (status === 200) { wDraft = null; closeM('wm'); await load(); go(f.name); }
    else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('w-del').onclick = async () => {
    if (!wEdit || !confirm('"' + wEdit.name + '" planeti silinsin? Tapşırıqları lövhədə qalacaq.')) return;
    const { status, d } = await WLD('planet_delete', { id: wEdit.id });
    if (status === 200) { wDraft = null; closeM('wm'); await load(); go(); } else { $('w-err').hidden = false; $('w-err').textContent = d.error || 'Alınmadı'; }
  };
  // MCP
  $('t-mcp').onclick = () => { mcpReset(); $('mcp-prj').value = curPlanet || ''; renderMcp(); openM('mcpm'); };
  $('mcp-cancel').onclick = mcpReset;
  $('mcp-save').onclick = async () => {
    const e = $('mcp-err'); e.hidden = true;
    const { status, d } = await WLD('mcp_save', { id: mcpEdit, name: $('mcp-name').value, project: $('mcp-prj').value, descr: $('mcp-descr').value });
    if (status === 200) { mcpReset(); await load(); renderMcp(); } else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  // Prompt Generator
  const opts = (id, arr) => $(id).replaceChildren(...arr.map(x => Object.assign(document.createElement('option'), { value: x[0], textContent: x[1] })));
  opts('pg-role', PG_ROLES); opts('pg-fmt', PG_FMT); opts('pg-tone', PG_TONE);
  $('pg-role').onchange = () => { $('pg-role-c').hidden = $('pg-role').value !== 'custom'; };
  $('pg-gen').onclick = () => {
    const out = buildPrompt();
    if (!out) { setText($('pg-note'), 'Əvvəlcə məqsədi yaz'); $('pg-goal').focus(); return; }
    $('pg-out').value = out; setText($('pg-note'), 'Hazırdır ✓ dəyişə, kopyalaya və ya saxlaya bilərsən');
    if (!$('pg-title').value) $('pg-title').value = $('pg-goal').value.trim().split('\n')[0].slice(0, 80);
  };
  $('pg-copy').onclick = async () => { try { await navigator.clipboard.writeText($('pg-out').value); setText($('pg-copy'), 'Kopyalandı ✓'); } catch (e) { $('pg-out').select(); } setTimeout(() => setText($('pg-copy'), 'Kopyala'), 1600); };
  $('pg-new').onclick = () => { pgReset(); $('pg-out').value = ''; $('pg-title').value = ''; $('pg-tags').value = ''; };
  $('pg-save').onclick = async () => {
    const e = $('pg-err'); e.hidden = true;
    const { status, d } = await WLD('prompt_save', { id: pgEditId, title: $('pg-title').value, body: $('pg-out').value, tags: $('pg-tags').value });
    if (status === 200) { pgEditId = +d.id; setText($('pg-edit'), 'Saxlandı ✓ ' + $('pg-title').value); setText($('pg-save'), 'Yadda saxla'); $('pg-new').hidden = false; load(); }
    else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('pg-q').addEventListener('input', renderPrompts);
  // Designer
  $('ds-addt').onclick = () => openTaskModal('Designer');
  $('ds-addp').onclick = () => { plProject = 'Designer'; openPlanModal('plan'); };
  // Meeting
  $('mt-add').onclick = () => openMeeting(null);
  $('mt-save').onclick = async () => {
    const e = $('mt-err'); e.hidden = true; $('mt-save').disabled = true;
    const attendees = [...$('mt-att').querySelectorAll('input:checked')].map(c => c.value);
    const { status, d } = await WLD('meeting_save', { id: mtEdit, title: $('mt-ttl').value, agenda: $('mt-agenda').value, starts_at: fromLocal($('mt-at').value), minutes: +$('mt-min').value, attendees });
    $('mt-save').disabled = false;
    if (status === 200) { closeM('mtm'); load(); } else { e.hidden = false; e.textContent = d.error || 'Alınmadı'; }
  };
  $('xback').onclick = () => go();
  for (const id of ['shipm', 'wm', 'mcpm', 'mtm']) $(id).addEventListener('pointerdown', e => { if (e.target === $(id)) closeM(id); });
}

function pulse() {                                    // hər saniyə: vaxtlar və statuslar canlı yenilənir
  const ok = Date.now() - lastOk < 9000;
  const live = $('live');
  if (ok) live.removeAttribute('data-off'); else live.setAttribute('data-off', '');
  setText($('conn'), ok ? 'canlı · ' + clock(now()) : 'bağlantı yoxdur');
  if (data && !document.hidden) render();
}
makeSky($('space'), 2400, 1500, 3.4);
initCmd();
initAdmin();
initBoard();
initReport();
initMsg();
initSound();
initPlan();
initWorld();
if (document.fullscreenEnabled) $('fs').onclick = () => document.fullscreenElement ? document.exitFullscreen() : $('space').requestFullscreen();
else $('fs').hidden = true;
initCam();
$('pback').onclick = () => go();
addEventListener('hashchange', route);
addEventListener('keydown', e => {
  if (!(view === 'space' && (curPlanet || curShip)) || e.target.closest('input,textarea,select')) return;
  if (e.key === 'Escape') go();
  else if (curShip || xKind(curPlanet)) return;
  else if (e.key === 'ArrowLeft' && !$('pprev').hidden) $('pprev').click();
  else if (e.key === 'ArrowRight' && !$('pnext').hidden) $('pnext').click();
});
setInterval(load, 3000);
setInterval(pulse, 1000);
document.addEventListener('visibilitychange', load);
addEventListener('kainat-ready', initGL);
route();
if (window.Kainat) initGL();
</script>
<script src="jarvis.js?v=<?= (int)@filemtime(__DIR__ . '/jarvis.js') ?>"></script>
<?php endif; ?>
</div>
</body>
</html>
