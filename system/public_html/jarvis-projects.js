/* Jarvis modulu: proyektlərin sağlamlığı, PR-lar, Claude Code idarəsi (status, konfiq, istifadə, transkript, MCP, CLAUDE.md, icazə qaydası, yeniləmə),
   undo, worktree, skill işlətmə, model/rejim default-ları, cədvəllər və gecikmə paneli. Deklarasiyalar: jarvis_modules/projects.php.
   Ops: ops.php {action:'run', op, args, project} → {id}; nəticə RT 'ops.result' (ref = id) ilə, RT yoxdursa ops.php get ilə 700 ms-dən bir (≤ 90 s).
   Nəticə ~12 s-də gəlsə alət onu qaytarır; gec gəlsə alət "pending" qaytarır, panel yenilənir və Jarvis-ə qeyd gedir.
   Panellər: JarvisHUD (varsa); renderlər saf funksiyalardır (JarvisProjects.render, testlər üçün). */
(() => {
  if (!window.Jarvis || window.JarvisProjects) return;
  const J = window.Jarvis, U = J.util || {};
  const line = U.line || (() => {});
  const need = U.need || ((a, ...ks) => { for (const k of ks) if (a[k] === undefined || a[k] === null || a[k] === '') throw new Error('"' + k + '" lazımdır'); });
  const confirmed = (a, tier) => { if (U.confirmed) return U.confirmed(a, tier); if (a.confirmed !== true) throw new Error('Not confirmed: describe the action to the user, ask for confirmation, then call again with confirmed=true.'); };
  const HUD = () => window.JarvisHUD && typeof window.JarvisHUD.show === 'function' ? window.JarvisHUD : null;
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const WAIT_INLINE = 12000, WAIT_MAX = 90000;

  async function post(url, body) {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'fetch' }, body: JSON.stringify(body) });
    const raw = await r.text().catch(() => ''); let d = {}; try { d = JSON.parse(raw) || {}; } catch (e) {}
    return { status: r.status, d };
  }
  const refresh = () => { try { if (typeof load === 'function') load(); } catch (e) {} };   // index.php feed

  /* ---------- Köməkçilər (məlumat formaları D2-dən asılıdır: hər şey ehtiyatla oxunur) ---------- */
  const get = (o, ...ps) => { for (const p of ps) { let v = o; for (const k of p.split('.')) { if (v == null) break; v = v[k]; } if (v !== undefined && v !== null && v !== '') return v; } return undefined; };
  const arr = v => Array.isArray(v) ? v : [];
  const str = (v, n = 200) => (v == null ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)).slice(0, n);
  const num = v => { const x = typeof v === 'number' ? v : parseFloat(v); return Number.isFinite(x) ? x : null; };
  const yes = v => v === true || v === 1 || v === 'yes' || v === 'true';
  const money = v => { const x = num(v); return x === null ? '—' : '$' + (x >= 100 ? Math.round(x) : x.toFixed(2)); };
  const kfmt = v => { const x = num(v); return x === null ? '—' : x >= 1e6 ? (x / 1e6).toFixed(1) + 'M' : x >= 1e3 ? (x / 1e3).toFixed(1) + 'k' : String(Math.round(x)); };
  const short = (o, n = 1400) => { const s = JSON.stringify(o); return s && s.length > n ? s.slice(0, n) + '…' : s; };
  const CI = c => { c = String(c || '').toLowerCase(); return /success|pass|ok|green|completed_success/.test(c) ? 'ok' : /fail|error|cancel|timed|red|action_required/.test(c) ? 'fail' : /progress|queued|pending|running|waiting/.test(c) ? 'running' : c ? 'warn' : ''; };
  /** Hər hansı obyektin skalyar sahələri → kv */
  /** {açar: obyekt} və ya massiv → massiv (açar $k sahəsinə yazılır); claude_ops by_day/by_project obyektdir */
  const rowsOf = (v, k) => Array.isArray(v) ? v : v && typeof v === 'object' ? Object.entries(v).map(([key, x]) => ({ [k]: key, ...(x && typeof x === 'object' ? x : { value: x }) })) : [];
  const tokOf = x => num(get(x, 'tokens', 'total_tokens')) ?? ((num(get(x, 'input')) || 0) + (num(get(x, 'output')) || 0));
  const costOf = x => num(get(x, 'cost_usd', 'cost')) || 0;
  const localDay = () => { const t = new Date(); return t.getFullYear() + '-' + String(t.getMonth() + 1).padStart(2, '0') + '-' + String(t.getDate()).padStart(2, '0'); };
  const kvOf = (o, skip = []) => Object.entries(o || {}).filter(([k, v]) => !skip.includes(k) && v !== null && v !== undefined && typeof v !== 'object').slice(0, 16).map(([key, value]) => ({ key, value: str(value) }));

  /* ---------- Renderlər: data → HUD spec ---------- */
  const R = {
    health(d, p) {
      if (d.git === null) return { title: `🩺 ${p}`, icon: '🩺', accent: 'amber', layout: 'compact', blocks: [{ type: 'text', text: 'Bu qovluq git repo deyil.' }] };
      const g = get(d, 'git') || d;
      const branch = get(g, 'branch', 'current_branch'), ahead = num(get(g, 'ahead')), behind = num(get(g, 'behind'));
      const dirtyV = get(g, 'dirty', 'changed', 'uncommitted'), dirty = typeof dirtyV === 'number' ? dirtyV : arr(dirtyV).length || (yes(dirtyV) ? 1 : 0);
      const runs = Array.isArray(d.ci) ? d.ci : arr(get(d, 'ci.runs', 'runs', 'ci_runs')), ci = get(d, 'ci') && !Array.isArray(d.ci) && typeof d.ci === 'object' && !d.ci.runs ? d.ci : runs[0] || (Array.isArray(d.ci) ? d.ci[0] : null);
      const ciSt = d.ci_red ? 'fail' : ci ? CI(get(ci, 'conclusion', 'status', 'state')) : typeof d.ci === 'string' ? CI(d.ci) : '';
      const prs = arr(get(d, 'prs', 'pull_requests', 'pr_list', 'gh.prs'));
      const commits = arr(get(d, 'commits', 'last_commits', 'git.commits', 'git.last_commits'));
      const au = get(d, 'audit', 'deps_audit', 'dependency_audit'), deploy = get(d, 'last_deploy'), target = get(d, 'deploy');
      const auBad = au && typeof au === 'object' ? (num(au.critical) || 0) + (num(au.high) || 0) : 0;
      const auTxt = au == null ? '—' : typeof au === 'object' ? (au.error ? str(au.error, 120) : au.summary ? str(au.summary, 120) : ['critical', 'high', 'moderate', 'low'].filter(k => num(au[k])).map(k => k + ' ' + au[k]).join(', ') || '0 zəiflik') : str(au, 120);
      const kv = [{ key: 'Branch', value: str(branch || '—', 60) }];
      if (ahead !== null || behind !== null) kv.push({ key: 'Ahead / behind', value: `↑${ahead || 0} ↓${behind || 0}` });
      kv.push({ key: 'Git', value: (dirty ? `${dirty} dəyişmiş fayl` : 'təmiz') + (get(g, 'upstream') === null ? ' · upstream yoxdur' : '') });
      if (d.gh) kv.push({ key: 'GitHub', value: str(d.gh, 120) });
      kv.push({ key: 'CI', value: d.ci_error ? str(d.ci_error, 120) : ci ? `${ciSt === 'ok' ? '✓' : ciSt === 'fail' ? '✗' : '…'} ${str(get(ci, 'name', 'workflow', 'workflowName') || '', 40)} ${str(get(ci, 'conclusion', 'status') || '', 20)}`.trim() : typeof d.ci === 'string' ? d.ci : '—' });
      kv.push({ key: 'Audit', value: auTxt });
      if (deploy) kv.push({ key: 'Son deploy', value: typeof deploy === 'object' ? str([get(deploy, 'env', 'environment', 'state', 'status'), get(deploy, 'at', 'when', 'created_at', 'date'), get(deploy, 'sha', 'url')].filter(Boolean).join(' · '), 120) : str(deploy, 120) });
      if (target && (!Array.isArray(target) || target.length)) kv.push({ key: 'Deploy üsulu', value: Array.isArray(target) ? target.join(', ').slice(0, 120) : str(target, 120) });
      if (d.prs_error) kv.push({ key: 'PR', value: str(d.prs_error, 120) });
      const blocks = [{ type: 'kv', items: kv }];
      if (prs.length) blocks.push({ type: 'list', title: `Açıq PR-lar (${prs.length})`, items: prs.slice(0, 8).map(x => ({ text: `#${get(x, 'number', 'id') || '?'} ${str(get(x, 'title'), 150)}`,
        sub: [get(x, 'author.login', 'author'), yes(get(x, 'isDraft', 'draft')) ? 'draft' : '', get(x, 'checks', 'statusCheckRollup', 'ci') ? 'checks: ' + str(get(x, 'checks', 'ci'), 40) : ''].filter(Boolean).join(' · '),
        status: CI(get(x, 'checks', 'ci', 'status')) || 'queued' })) });
      if (commits.length) blocks.push({ type: 'timeline', title: 'Son commit-lər', items: commits.slice(0, 6).map(c => typeof c === 'string' ? { text: c.slice(0, 160) } : { time: str(get(c, 'when', 'date', 'ago', 'time'), 24), text: str(get(c, 'subject', 'message', 'title') || c.sha || c.hash, 160) + (c.author ? ' · ' + str(c.author, 30) : '') }) });
      if (runs.length > 1) blocks.push({ type: 'list', title: 'CI', items: runs.slice(0, 5).map(x => ({ text: str(get(x, 'name', 'workflowName', 'workflow') || 'run', 80), sub: str([get(x, 'branch', 'headBranch'), get(x, 'at', 'when', 'created_at', 'createdAt')].filter(Boolean).join(' · '), 80), status: CI(get(x, 'conclusion', 'status')) })) });
      if (prs.length) blocks.push({ type: 'buttons', items: [{ label: `PR #${get(prs[0], 'number') || ''} xülasə`.slice(0, 30), say: `${p} proyektində PR #${get(prs[0], 'number')} xülasəsini göstər.` }] });
      const accent = ciSt === 'fail' || auBad ? 'red' : dirty || behind || ciSt === 'running' ? 'amber' : 'green';
      return { title: `🩺 ${p}`, icon: '🩺', accent, blocks };
    },
    usage(d, days) {
      const byDay = rowsOf(get(d, 'by_day', 'daily') || (Array.isArray(d.days) ? d.days : null), 'date'), byProj = rowsOf(get(d, 'by_project', 'projects'), 'project');
      const tot = get(d, 'total', 'totals');
      const cost = tot ? costOf(tot) : byDay.reduce((s, x) => s + costOf(x), 0), tok = tot ? tokOf(tot) : byDay.reduce((s, x) => s + tokOf(x), 0);
      const useCost = byDay.some(x => costOf(x) > 0);
      const val = x => useCost ? costOf(x) : tokOf(x);
      const blocks = [{ type: 'stat', items: [{ label: 'Xərc', value: money(cost) }, { label: 'Token (in+out)', value: kfmt(tok) }, { label: 'Gün', value: String(days || byDay.length || '—') }] }];
      if (tot && num(tot.cache_read)) blocks[0].items.push({ label: 'Keş oxu', value: kfmt(tot.cache_read) });
      if (byDay.length) blocks.push({ type: 'bar_chart', title: useCost ? 'Gün üzrə xərc ($)' : 'Gün üzrə token', items: byDay.slice(-16).map(x => ({ label: str(get(x, 'date', 'day'), 10).slice(5) || '?', value: +val(x).toFixed(2) })) });
      if (byProj.length) blocks.push({ type: 'bar_chart', title: useCost ? 'Proyekt üzrə xərc ($)' : 'Proyekt üzrə token', items: byProj.slice().sort((a, b) => val(b) - val(a)).slice(0, 8).map(x => ({ label: str(get(x, 'project', 'name'), 30), value: +val(x).toFixed(2) })) });
      return { title: '📊 Claude Code istifadəsi', icon: '📊', accent: 'violet', layout: byDay.length > 8 ? 'wide' : 'normal', blocks };
    },
    config(d, p, mcp) {
      // claude_ops: settings {user, project, local} → hər birində allow/deny/hooks/model; köhnə düz forma da qəbul olunur
      const S = d.settings && typeof d.settings === 'object' ? d.settings : { '': { ...(get(d, 'permissions') || {}), hooks: d.hooks, model: d.model, allow: get(d, 'permissions.allow', 'allow'), deny: get(d, 'permissions.deny', 'deny') } };
      const lv = Object.entries(S), tag = (k, x) => (k ? k + ': ' : '') + str(x, 140);
      const allow = lv.flatMap(([k, v]) => arr(v && v.allow).map(x => tag(k, x))), deny = lv.flatMap(([k, v]) => arr(v && v.deny).map(x => tag(k, x)));
      const hookEv = [...new Set(lv.flatMap(([, v]) => { const h = v && v.hooks; return Array.isArray(h) ? h : h && typeof h === 'object' ? Object.keys(h) : []; }))];
      const model = (['local', 'project', 'user', ''].map(k => S[k] && S[k].model).find(Boolean)) || 'default';
      const names = k => { const v = get(d, k); return (Array.isArray(v) ? v : v && typeof v === 'object' ? Object.entries(v).flatMap(([sc, xs]) => arr(xs).map(x => (sc === 'project' ? '' : sc[0] + ':') + x)) : []).map(x => typeof x === 'string' ? x : get(x, 'name') || '').filter(Boolean); };
      const servers = arr(mcp && get(mcp, 'servers', 'mcp')).concat(arr(get(d, 'mcp', 'mcp_servers', 'mcp_json')));
      const blocks = [{ type: 'kv', items: [{ key: 'Model', value: str(model, 40) }, { key: 'Hooks', value: hookEv.join(', ') || '—' },
        { key: 'Skills', value: names('skills').join(', ').slice(0, 200) || '—' }, { key: 'Agents', value: names('agents').join(', ').slice(0, 200) || '—' }, { key: 'Commands', value: names('commands').join(', ').slice(0, 200) || '—' },
        { key: 'MCP', value: [...new Set(servers.map(s => typeof s === 'string' ? s : get(s, 'name') + (s.status && s.status !== 'connected' ? ` (${s.status})` : '')).filter(Boolean))].join(', ').slice(0, 200) || '—' }] }];
      if (allow.length || deny.length) blocks.push({ type: 'list', title: `İcazələr (${allow.length} allow, ${deny.length} deny)`, items: allow.slice(0, 12).map(x => ({ text: str(x, 150), status: 'ok' })).concat(deny.slice(0, 8).map(x => ({ text: str(x, 150), status: 'fail' }))) });
      const md = get(d, 'claude_md', 'claude_md_head', 'claudeMd');
      if (md) blocks.push({ type: 'code', title: 'CLAUDE.md', lang: 'markdown', text: str(md, 1500) });
      return { title: `⚙️ Claude Code · ${p}`, icon: '⚙️', accent: 'violet', layout: md ? 'wide' : 'normal', blocks };
    },
    transcript(d, p) {
      if (Array.isArray(d.sessions)) {                 // claude_ops: sessions[{prompts[{at,text}], errors[{at,tool,text}], tool_counts, files[{file,edits}]}]
        const ss = d.sessions, tc = {}, fl = {};
        for (const x of ss) { for (const [k, v] of Object.entries(x.tool_counts || {})) tc[k] = (tc[k] || 0) + v; for (const f of arr(x.files)) fl[f.file] = (fl[f.file] || 0) + (f.edits || 1); }
        d = { items: ss.flatMap(x => arr(x.prompts).map(q => ({ time: q.at, type: 'prompt', text: q.text })).concat(arr(x.errors).map(e => ({ time: e.at, type: 'error', text: (e.tool ? e.tool + ': ' : '') + e.text })))).sort((a, b) => String(a.time).localeCompare(String(b.time))),
          tools: tc, files: Object.entries(fl).sort((a, b) => b[1] - a[1]).map(([f, n]) => f + (n > 1 ? ' ×' + n : '')), text: d.note || (ss.length ? '' : 'Bu müddətdə fəaliyyət yoxdur.') };
      }
      let items = arr(get(d, 'items', 'events', 'timeline'));
      const ST = t => /err|fail/.test(t) ? 'fail' : /prompt|user/.test(t) ? 'running' : /tool/.test(t) ? 'done' : '';
      if (items.length) items = items.map(x => typeof x === 'string' ? { text: x.slice(0, 200) } : { time: str(get(x, 'time', 'ts', 'when', 'at'), 24), text: ({ prompt: '💬 ', error: '✗ ' }[get(x, 'type', 'kind')] || (get(x, 'type', 'kind') ? str(get(x, 'type', 'kind'), 12) + ': ' : '')) + str(get(x, 'text', 'summary', 'prompt', 'tool', 'message'), 180), status: ST(str(get(x, 'type', 'kind'), 20)) });
      else items = arr(d.prompts).map(x => ({ text: '💬 ' + str(get(x, 'text', 'prompt') || x, 190), time: str(get(x, 'time'), 24), status: 'running' })).concat(arr(d.errors).map(x => ({ text: '✗ ' + str(get(x, 'text', 'message') || x, 190), time: str(get(x, 'time'), 24), status: 'fail' })));
      const files = arr(get(d, 'files', 'files_changed')), tools = get(d, 'tools', 'tool_counts');
      const blocks = [];
      if (items.length) blocks.push({ type: 'timeline', items: items.slice(-20) });
      const kv = [];
      if (files.length) kv.push({ key: 'Fayllar', value: files.slice(0, 12).map(f => str(get(f, 'path', 'file') || f, 60)).join(', ') });
      if (tools && typeof tools === 'object' && Object.keys(tools).length) kv.push({ key: 'Alətlər', value: (Array.isArray(tools) ? tools.map(t => str(get(t, 'name') || t, 30)) : Object.entries(tools).map(([k, v]) => k + '×' + v)).slice(0, 12).join(', ') });
      if (kv.length) blocks.push({ type: 'kv', items: kv });
      if (!blocks.length) blocks.push({ type: 'text', text: str(get(d, 'text', 'summary') || 'Bu müddətdə fəaliyyət yoxdur.', 1500) });
      return { title: `📜 ${p}: son fəaliyyət`, icon: '📜', accent: items.some(x => x.status === 'fail') ? 'amber' : 'cyan', blocks };
    },
    pr(d, p) {
      const n = get(d, 'number'), files = arr(get(d, 'files')), checks = arr(get(d, 'checks', 'statusCheckRollup'));
      const add = num(get(d, 'additions')) ?? files.reduce((s, f) => s + (num(get(f, 'additions')) || 0), 0), del = num(get(d, 'deletions')) ?? files.reduce((s, f) => s + (num(get(f, 'deletions')) || 0), 0);
      const bad = checks.filter(c => CI(get(c, 'conclusion', 'status', 'state')) === 'fail').length;
      const blocks = [
        { type: 'kv', items: [{ key: 'Başlıq', value: str(get(d, 'title'), 200) }, { key: 'Müəllif', value: str(get(d, 'author.login', 'author') || '—', 40) },
          { key: 'Vəziyyət', value: str([get(d, 'state'), yes(get(d, 'isDraft', 'draft')) ? 'draft' : '', get(d, 'mergeable'), get(d, 'review')].filter(Boolean).join(' · ') || '—', 80) },
          { key: 'Branch', value: str([get(d, 'headRefName', 'head'), get(d, 'baseRefName', 'base')].filter(Boolean).join(' → ') || '—', 80) }] },
        { type: 'stat', items: [{ label: 'Əlavə', value: '+' + kfmt(add) }, { label: 'Silinən', value: '−' + kfmt(del) }, { label: 'Fayl', value: String(num(get(d, 'changed_files', 'changedFiles')) ?? files.length) }, { label: 'Checks', value: checks.length ? `${checks.length - bad}/${checks.length}` : '—' }] },
      ];
      if (files.length) blocks.push({ type: 'list', title: 'Fayllar', items: files.slice(0, 12).map(f => typeof f === 'string' ? { text: f } : { text: str(get(f, 'path', 'filename'), 150), sub: `+${get(f, 'additions', '+') || 0} −${get(f, 'deletions', '-') || 0}` }) });
      if (checks.length) blocks.push({ type: 'list', title: 'Checks', items: checks.slice(0, 8).map(c => ({ text: str(get(c, 'name', 'context'), 80), status: CI(get(c, 'conclusion', 'status', 'state')) })) });
      const body = get(d, 'body', 'body_head');
      if (body) blocks.push({ type: 'text', text: str(body, 600) });
      if (n && String(get(d, 'state') || 'open').toLowerCase() === 'open') blocks.push({ type: 'buttons', items: [{ label: `Merge #${n}`, say: `${p} proyektində PR #${n}-i merge etmək istəyirəm (əvvəl bir cümlə ilə təsdiq soruş).` }] });
      return { title: `🔀 PR #${n || '?'} · ${p}`, icon: '🔀', accent: bad ? 'red' : 'cyan', layout: 'normal', blocks };
    },
    status(info, usage) {
      const u = usage || {}, by = rowsOf(get(u, 'by_day', 'daily') || (Array.isArray(u.days) ? u.days : null), 'date'), today = localDay();
      const c = costOf;
      const td = by.filter(x => str(get(x, 'date', 'day'), 10) === today).reduce((s, x) => s + c(x), 0), wk = get(u, 'total', 'totals') ? costOf(get(u, 'total', 'totals')) : by.reduce((s, x) => s + c(x), 0);
      const login = get(info, 'logged_in', 'login', 'loggedIn'), upd = info.update_available === true ? (info.latest || true) : info.update_available === undefined ? get(info, 'latest') : false;
      const items = [{ label: 'Versiya', value: str(get(info, 'version') || '?', 24) }, { label: 'Giriş', value: login === undefined ? '?' : yes(login) ? '✓' : '✗' }];
      if (usage) items.push({ label: 'Bu gün', value: money(td) }, { label: 'Həftə', value: money(wk) });
      const blocks = [{ type: 'stat', items }, { type: 'kv', items: [{ key: 'Default model', value: str(get(info, 'model', 'default_model') || 'default', 40) }, { key: 'Yeniləmə', value: upd ? (typeof upd === 'string' ? 'var: ' + upd : 'var') : 'yoxdur' }] }];
      if (by.length > 1) blocks.push({ type: 'sparkline', title: 'Gündəlik xərc', values: by.map(c) });
      return { title: '🤖 Claude Code', icon: '🤖', accent: login !== undefined && !yes(login) ? 'red' : upd ? 'amber' : 'green', layout: 'compact', blocks };
    },
    latency(d) {
      let rows = arr(get(d, 'stats', 'kinds', 'rows'));
      if (!rows.length && d && typeof (d.stats || d.kinds) === 'object') rows = Object.entries(d.stats || d.kinds).map(([kind, v]) => ({ kind, ...v }));
      // latency.php: {kinds: [{kind, count, start|first|done: {p50, p90, n}}]} (said → start/first/done, ms); düz {kind, p50, p90} də qəbul olunur
      const ms = v => v == null ? null : Math.round(num(v) || 0);
      const ph = (x, k) => { const o = get(x, k); return o && typeof o === 'object' ? { p50: ms(o.p50), p90: ms(o.p90) } : null; };
      rows = rows.map(x => { const done = ph(x, 'done') || ph(x, 'first') || ph(x, 'start') || { p50: ms(get(x, 'p50', 'p50_ms')) || 0, p90: ms(get(x, 'p90', 'p90_ms')) || 0 };
        return { kind: str(get(x, 'kind'), 30), n: num(get(x, 'count', 'n')) || 0, p50: done.p50 || 0, p90: done.p90 || 0, start: ph(x, 'start'), first: ph(x, 'first') }; }).filter(x => x.kind).slice(0, 16);
      if (!rows.length) return { title: '⏱ Gecikmə (24 saat)', icon: '⏱', accent: 'cyan', layout: 'compact', blocks: [{ type: 'text', text: 'Son 24 saatda ölçü yoxdur.' }] };
      const slow = rows.some(x => x.p90 > 5000), f = o => o ? `${o.p50}/${o.p90}` : '—';
      return { title: '⏱ Gecikmə (24 saat)', icon: '⏱', accent: slow ? 'amber' : 'cyan', layout: 'wide', blocks: [
        { type: 'bar_chart', title: 'p50 dedi→bitdi (ms)', items: rows.map(x => ({ label: x.kind, value: x.p50 })) },
        { type: 'bar_chart', title: 'p90 dedi→bitdi (ms)', items: rows.map(x => ({ label: x.kind, value: x.p90 })) },
        { type: 'table', columns: ['Növ', 'n', 'start p50/p90', 'ilk səs p50/p90', 'bitdi p50/p90'], rows: rows.map(x => [x.kind, String(x.n), f(x.start), f(x.first), `${x.p50}/${x.p90}`]) }] };
    },
    schedules(list, tz) {
      return { title: '⏰ Cədvəllər', icon: '⏰', accent: 'cyan', blocks: list.length ? [{ type: 'list', items: list.slice(0, 20).map(s => ({ text: `#${s.id} ${s.name} · ${s.rule}`,
        sub: `${s.protocol || 'prompt'}${s.project ? ' · ' + s.project : ''} · ${s.enabled ? 'növbəti: ' + (s.next_run || '—') : 'söndürülüb'}`, status: s.enabled ? 'ok' : 'queued' })) }, { type: 'text', text: 'Saat qurşağı: ' + (tz || 'server') }]
        : [{ type: 'text', text: 'Cədvəl yoxdur. Məs.: "hər gecə 03:00-da Dependency Update Protocol".' }] };
    },
    generic(d, title, icon) {
      const blocks = [], kv = kvOf(d, ['output', 'log', 'text', 'stdout', 'diff', 'tail']);
      if (kv.length) blocks.push({ type: 'kv', items: kv });
      const out = get(d, 'output', 'tail', 'log', 'text', 'stdout');
      if (out) blocks.push({ type: 'code', text: str(out, 3000) });
      if (get(d, 'diff')) blocks.push({ type: 'diff', text: str(d.diff, 4000) });
      for (const [k, v] of Object.entries(d || {})) if (Array.isArray(v) && v.length && blocks.length < 6) blocks.push({ type: 'list', title: k, items: v.slice(0, 12).map(x => ({ text: typeof x === 'object' ? str(get(x, 'name', 'path', 'title') || x, 150) : str(x, 150) })) });
      if (!blocks.length) blocks.push({ type: 'text', text: str(d, 1500) || 'Hazırdır.' });
      return { title: title, icon: icon || '🛠', accent: d && (d.ok === false || d.error) ? 'red' : 'green', blocks };
    },
    error(title, icon, err) { return { title, icon: icon || '⚠️', accent: 'red', layout: 'compact', blocks: [{ type: 'text', text: '✗ ' + str(err, 600) }] }; },
  };

  /* ---------- Model üçün yığcam nəticələr ---------- */
  const BRIEF = {
    health(d, p) {
      const g = get(d, 'git') || {}, prs = arr(get(d, 'prs', 'pull_requests', 'pr_list', 'gh.prs')), runs = Array.isArray(d.ci) ? d.ci : arr(get(d, 'ci.runs', 'runs', 'ci_runs'));
      const ci = get(d, 'ci') && !Array.isArray(d.ci) && typeof d.ci === 'object' && !d.ci.runs ? d.ci : runs[0] || d.ci;
      const c0 = arr(get(d, 'commits', 'last_commits', 'git.commits', 'git.last_commits'))[0];
      return { project: p, branch: get(g, 'branch'), ahead: get(g, 'ahead'), behind: get(g, 'behind'), dirty: get(g, 'dirty', 'changed'),
        ci: ci && typeof ci === 'object' ? [get(ci, 'name', 'workflowName'), get(ci, 'conclusion', 'status')].filter(Boolean).join(': ') : ci,
        ci_red: d.ci_red, gh: d.gh, open_prs: prs.length, prs: prs.slice(0, 3).map(x => `#${get(x, 'number')} ${str(get(x, 'title'), 80)}`), audit: get(d, 'audit', 'deps_audit'),
        last_deploy: get(d, 'last_deploy'), deploy_via: arr(d.deploy).join(', ') || undefined, last_commit: c0 ? str(typeof c0 === 'string' ? c0 : get(c0, 'subject', 'message'), 120) : undefined };
    },
    pr: (d, p) => ({ project: p, number: get(d, 'number'), title: get(d, 'title'), state: get(d, 'state'), author: get(d, 'author.login', 'author'), additions: get(d, 'additions'), deletions: get(d, 'deletions'),
      files: get(d, 'changed_files') || arr(get(d, 'files')).length, mergeable: get(d, 'mergeable'), review: get(d, 'review'), checks: arr(get(d, 'checks')).map(c => `${get(c, 'name')}: ${get(c, 'conclusion', 'status')}`).slice(0, 6), body: str(get(d, 'body'), 500) }),
    any: d => { const s = short(d); try { return JSON.parse(s); } catch (e) { return { result: s }; } },
  };

  /* ---------- Proyekt adı: planet adları + agentin qovluqları ---------- */
  function agentFolders() {
    try { const me = typeof ME !== 'undefined' && ME ? ME.name : ''; const a = ((typeof data !== 'undefined' && data && data.agents) || []).find(x => x.user === me);
      return a ? String(a.projects || '').split('\n').filter(Boolean) : []; } catch (e) { return []; }
  }
  function project(q) {
    q = String(q || '').trim();
    if (!q) {
      try { if (typeof curPlanet !== 'undefined' && curPlanet && (typeof xKind !== 'function' || !xKind(curPlanet))) return curPlanet; } catch (e) {}
      const f = agentFolders(); if (f.length === 1) return f[0];
      throw new Error('Hansı proyektdə? Proyekt adını soruş.' + (f.length ? ' Agentin proyektləri: ' + f.join(', ') : ''));
    }
    const best = U.best; if (!best) return q;
    const pl = U.planetNames ? best(U.planetNames(), q, p => [p.name, p.label]) : null; if (pl) return pl.name;
    const f = best(agentFolders(), q, x => x); return f || q;
  }

  /* ---------- Ops: göndər → nəticəni gözlə (RT və ya sorğu) ---------- */
  const waiting = new Map();                     // ops komanda id → finish(r)
  let rtHooked = false;
  function hookRT() {
    if (rtHooked || !window.RT || typeof window.RT.on !== 'function') return;
    rtHooked = true;
    window.RT.on('ops.result', e => { const d = (e && e.data) || {}, f = waiting.get(+(e && e.ref) || +d.id); if (f) f({ ok: d.ok !== false && !(d.data && d.data.error), data: d.data, error: d.error || (d.data && d.data.error) }); });
  }
  const rtUp = () => { try { return !!(window.RT && rtHooked && (typeof window.RT.connected !== 'function' || window.RT.connected())); } catch (e) { return false; } };
  function fromGet(d) {
    const c = d.command || d, st = String(c.status || '');
    if (!/^(done|failed|canceled|error|ok)$/.test(st)) return null;
    let res = c.result !== undefined ? c.result : c.data;
    if (typeof res === 'string') { try { res = JSON.parse(res); } catch (e) { res = res ? { text: res } : {}; } }
    const ok = (st === 'done' || st === 'ok') && !(res && (res.ok === false || res.error));
    return { ok, data: res || {}, error: ok ? undefined : (res && res.error) || c.error || (st === 'canceled' ? 'ləğv edildi' : 'alınmadı') };
  }
  async function opRun(op, args, proj) {
    hookRT();
    const body = { action: 'run', op, args: args || {}, project: proj || '' };
    if (typeof U.saidAt === 'function') body.said_at = U.saidAt();
    const { status, d } = await post('ops.php', body);
    if (status === 404 && !d.error) throw new Error('ops.php hələ quraşdırılmayıb');
    if (status !== 200 || !d.id) throw new Error(d.error || 'ops xətası ' + status);
    return +d.id;
  }
  function opWait(id, max = WAIT_MAX) {
    return new Promise(resolve => {
      let done = false, timer = null; const t0 = Date.now();
      const fin = r => { if (done) return; done = true; waiting.delete(id); clearTimeout(timer); resolve(r); };
      waiting.set(id, fin);
      const poll = async () => {
        if (done) return;
        if (Date.now() - t0 > max) return fin({ ok: false, timeout: true, error: 'Agent ' + Math.round(max / 1000) + ' s ərzində cavab vermədi (kompüterdə agent işləyirmi?)' });
        try {
          const { status, d } = await post('ops.php', { action: 'get', id });
          if (status === 200) { const r = fromGet(d); if (r) return fin(r); }
          else if (status === 403 || status === 404) return fin({ ok: false, error: d.error || 'xəta ' + status });
        } catch (e) { /* şəbəkə: yenidən */ }
        if (!done) timer = setTimeout(poll, rtUp() ? 3000 : 700);   // RT işləyirsə sorğu yalnız ehtiyatdır
      };
      timer = setTimeout(poll, rtUp() ? 1500 : 700);
    });
  }
  /** Bir op: nəticə {ok, data, error} (heç vaxt atmır) */
  const op = (name, args, proj) => opRun(name, args, proj).then(id => opWait(id), e => ({ ok: false, error: String(e.message || e) }));

  /* ---------- Panellər: açar üzrə bir panel (təkrar çağırış eyni paneli yeniləyir) ---------- */
  const cards = new Map();
  function card(key, spec) {
    const h = HUD(); if (!h) return null;
    if (cards.has(key)) { try { h.update(cards.get(key), spec); return cards.get(key); } catch (e) { cards.delete(key); } }
    try { const r = h.show(spec, { onClose: () => cards.delete(key) }); const id = r && typeof r === 'object' ? r.id : r; cards.set(key, id); return id; } catch (e) { console.error('JarvisProjects', e); return null; }
  }
  const sayLate = text => { try { if (typeof J.ready !== 'function' || J.ready()) J.say(text); } catch (e) {} };

  /** Ümumi axın: yüklənir paneli → işi gözlə (≤ 12 s inline, sonra fonda) → render + model üçün xülasə */
  async function opTool(o) {
    card(o.key, { title: o.title, icon: o.icon, accent: 'cyan', layout: 'compact', blocks: [{ type: 'text', text: '⏳ Kompüterdən məlumat alınır…' }] });
    const t0 = Date.now();
    const p = Promise.resolve().then(o.run).catch(e => ({ ok: false, error: String(e.message || e) }));
    const finish = r => {
      if (!r.ok) { card(o.key, R.error(o.title, o.icon, r.error)); return { ok: false, error: r.error }; }
      let spec; try { spec = o.render(r.data || {}); } catch (e) { console.error('JarvisProjects render', e); spec = R.generic(r.data || {}, o.title, o.icon); }
      card(o.key, spec);
      return { ok: true, shown_on_hud: !!HUD(), ...(o.brief ? o.brief(r.data || {}) : BRIEF.any(r.data || {})) };
    };
    const r = await Promise.race([p, sleep(WAIT_INLINE).then(() => null)]);
    if (r) return finish(r);
    p.then(r2 => { const b = finish(r2); line(b.ok ? 'a' : 'e', (b.ok ? '✓ ' : '✗ ') + o.title + (b.ok ? '' : ': ' + b.error));
      sayLate(`[System note, not spoken by the user] "${o.title}" result arrived after ${Math.round((Date.now() - t0) / 1000)} s (panel updated): ${short(b, 1500)}\nTell the user the key point in 1-2 short sentences.`); });
    return { ok: true, pending: true, note: 'Still waiting for the user\'s computer; the HUD panel updates and a note follows. Say you are checking.' };
  }

  /* ---------- Model/rejim default-ları (localStorage jv-cc-defaults) ---------- */
  const DKEY = 'jv-cc-defaults';
  function ccDefaults() {
    let v = {}; try { v = JSON.parse(localStorage.getItem(DKEY) || '{}') || {}; } catch (e) {}
    const o = {};
    if (['opus', 'sonnet', 'haiku'].includes(v.model)) o.model = v.model;
    if (v.mode === 'plan') o.mode = 'plan';
    return o;
  }
  // run_claude_code arqumentlərinə default-ları qoş (C-nin versiyası model/mode-u ötürür); təkrar sarımır
  function wrapRCC() {
    const T = J.tools; if (!T || typeof T.run_claude_code !== 'function' || T.run_claude_code._jvp) return;
    const orig = T.run_claude_code;
    const w = function (a) { const d = ccDefaults(); a = { ...(a || {}) }; if (!a.model && d.model) a.model = d.model; if (!a.mode && d.mode) a.mode = d.mode; return orig.call(this, a); };
    w._jvp = true; J.registerTool('run_claude_code', w);
  }
  wrapRCC(); J.on('start', wrapRCC);
  // Jarvis-in agent_send / dispatch sorğularına model/mode əlavə et (sorğuda yoxdursa, default varsa). Yalnız bu iki endpoint, başqa heç nə.
  if (typeof window.fetch === 'function' && !window.fetch._jvp) {
    const of = window.fetch;
    const nf = function (url, opt) {
      try {
        const u = typeof url === 'string' ? url : '';
        if (opt && typeof opt.body === 'string' && /(^|\/)(jarvis|mission)\.php(\?|$)/.test(u)) {
          const d = ccDefaults();
          if (d.model || d.mode) {
            const b = JSON.parse(opt.body), fill = x => { if (x && typeof x === 'object') { if (!x.model && d.model) x.model = d.model; if (!x.mode && d.mode) x.mode = d.mode; } };
            if (b && b.action === 'agent_send') { fill(b); opt = { ...opt, body: JSON.stringify(b) }; }
            else if (b && b.action === 'dispatch' && Array.isArray(b.tasks)) { b.tasks.forEach(fill); opt = { ...opt, body: JSON.stringify(b) }; }
          }
        }
      } catch (e) { /* toxunma */ }
      return of.call(this, url, opt);
    };
    nf._jvp = true; window.fetch = nf;
  }

  /* ---------- Alətlər ---------- */
  const reg = (n, f) => J.registerTool(n, f);
  reg('project_health', a => { const p = project(a.project);
    return opTool({ key: 'health:' + p, title: `🩺 ${p}`, icon: '🩺', run: () => op('health', { project: p }, p), render: d => R.health(d, p), brief: d => BRIEF.health(d, p) }); });

  reg('pr_summary', a => { const p = project(a.project), n = parseInt(a.number, 10) || 0;
    return opTool({ key: 'pr:' + p, title: `🔀 PR ${n ? '#' + n : ''} · ${p}`, icon: '🔀',
      run: async () => {
        if (n) return op('pr_view', { project: p, number: n }, p);
        let r = await op('pr_view', { project: p }, p);                 // nömrəsiz: ən son açıq PR (D2 dəstəkləyirsə)
        if (r.ok && get(r.data, 'number')) return r;
        const h = await op('health', { project: p }, p);                // ehtiyat: health-dəki PR siyahısından birincisi
        const pr = h.ok ? arr(get(h.data, 'prs', 'pull_requests', 'pr_list', 'gh.prs'))[0] : null;
        if (!pr) return { ok: false, error: h.ok ? 'açıq PR yoxdur' : h.error };
        return op('pr_view', { project: p, number: +get(pr, 'number') }, p);
      }, render: d => R.pr(d, p), brief: d => BRIEF.pr(d, p) }); });

  reg('pr_merge', a => { need(a, 'number'); confirmed(a, 'r'); const p = project(a.project), n = parseInt(a.number, 10);
    return opTool({ key: 'pr:' + p, title: `🔀 Merge #${n} · ${p}`, icon: '🔀', run: () => op('pr_merge', { project: p, number: n, method: a.method || 'squash' }, p), render: d => R.generic(d, `🔀 PR #${n} merge · ${p}`, '🔀') }); });

  reg('undo_last', a => { confirmed(a, 'r'); const p = project(a.project);
    return opTool({ key: 'undo:' + p, title: `↩️ Geri al · ${p}`, icon: '↩️', run: () => op('undo_last', { project: p }, p), render: d => R.generic(d, `↩️ Geri alındı · ${p}`, '↩️') }); });

  reg('worktree_new', a => { need(a, 'name'); const p = project(a.project), name = String(a.name).trim().toLowerCase().replace(/[^a-z0-9._-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    return opTool({ key: 'wt:' + p, title: `🌿 Worktree · ${p}`, icon: '🌿', run: () => op('worktree_new', { project: p, name }, p), render: d => R.generic(d, `🌿 Worktree ${name} · ${p}`, '🌿') }); });

  reg('claude_status', () => opTool({ key: 'cc:status', title: '🤖 Claude Code', icon: '🤖',
    run: async () => { const [i, u] = await Promise.all([op('claude_info', {}), op('usage', { days: 7 })]); return i.ok ? { ok: true, data: { info: i.data, usage: u.ok ? u.data : null } } : i; },
    render: d => R.status(d.info || {}, d.usage), brief: d => ({ version: get(d.info, 'version'), update_available: get(d.info, 'update_available', 'latest'), logged_in: get(d.info, 'logged_in', 'login'),
      latest: get(d.info, 'latest'), model: get(d.info, 'model', 'default_model'), week_cost_usd: d.usage && d.usage.total ? costOf(d.usage.total) : undefined,
      today_cost_usd: d.usage ? rowsOf(d.usage.by_day, 'date').filter(x => x.date === localDay()).reduce((s, x) => s + costOf(x), 0) : undefined }) }));

  reg('claude_config', a => { const p = project(a.project);
    return opTool({ key: 'cc:config:' + p, title: `⚙️ Claude Code · ${p}`, icon: '⚙️',
      run: async () => { const [c, m] = await Promise.all([op('config', { project: p }, p), op('mcp_list', { project: p }, p)]); return c.ok ? { ok: true, data: { config: c.data, mcp: m.ok ? m.data : null } } : c; },
      render: d => R.config(d.config || {}, p, d.mcp), brief: d => { const c = d.config || {}, S = c.settings || {};
        const cnt = k => Object.values(S).reduce((n, v) => n + arr(v && v[k]).length, 0), nm = k => { const v = c[k]; return Array.isArray(v) ? v : v && typeof v === 'object' ? Object.values(v).flat() : []; };
        return { project: p, settings_levels: Object.keys(S), allow_rules: cnt('allow'), deny_rules: cnt('deny'), hooks: [...new Set(Object.values(S).flatMap(v => arr(v && v.hooks)))],
          skills: nm('skills').slice(0, 15), agents: nm('agents').slice(0, 10), commands: nm('commands').slice(0, 10), has_claude_md: !!c.claude_md,
          mcp: arr(d.mcp && d.mcp.servers).map(s => s.name + (s.status ? ' (' + s.status + ')' : '')).slice(0, 12) }; } }); });

  reg('claude_usage', a => { const days = Math.max(1, Math.min(90, parseInt(a.days, 10) || 7));
    return opTool({ key: 'cc:usage', title: '📊 Claude Code istifadəsi', icon: '📊', run: () => op('usage', { days }), render: d => R.usage(d, days),
      brief: d => { const t = get(d, 'total', 'totals') || {}; return { days, cost_usd: costOf(t), tokens_in_out: tokOf(t), partial: d.partial || undefined,
        top_projects: rowsOf(get(d, 'by_project', 'projects'), 'project').slice(0, 4).map(x => ({ project: x.project, cost_usd: costOf(x), tokens: tokOf(x) })) }; } }); });

  reg('claude_transcript', a => { const p = project(a.project), m = Math.max(5, Math.min(1440, parseInt(a.minutes, 10) || 60));
    return opTool({ key: 'cc:tr:' + p, title: `📜 ${p}: son fəaliyyət`, icon: '📜', run: () => op('transcript', { project: p, since_minutes: m }, p), render: d => R.transcript(d, p),
      brief: d => { const ss = arr(d.sessions); return { project: p, minutes: m, sessions: ss.length, prompts: ss.flatMap(x => arr(x.prompts).map(q => str(q.text, 160))).slice(-6),
        errors: ss.flatMap(x => arr(x.errors).map(e => str((e.tool ? e.tool + ': ' : '') + e.text, 160))).slice(-4), files: ss.flatMap(x => arr(x.files).map(f => f.file)).slice(0, 10), note: d.note }; } }); });

  reg('mcp_add', a => { need(a, 'name'); confirmed(a, 'r'); if (!a.command && !a.url) throw new Error('"command" və ya "url" lazımdır');
    const p = project(a.project), args = { project: p, name: String(a.name).trim(), scope: a.scope || 'local' };
    if (a.url) args.url = String(a.url).trim();
    else { const parts = String(a.command).trim().split(/\s+/); args.command = parts[0]; if (parts.length > 1) args.args = parts.slice(1); }   // claude_ops: command + args[]
    return opTool({ key: 'cc:mcp:' + p, title: `🔌 MCP ${args.name}`, icon: '🔌', run: () => op('mcp_add', args, p), render: d => R.generic(d, `🔌 MCP ${args.name} · ${p}`, '🔌') }); });

  reg('claude_md_add', a => { need(a, 'text'); confirmed(a, 'r'); const p = project(a.project);
    return opTool({ key: 'cc:md:' + p, title: `📝 CLAUDE.md · ${p}`, icon: '📝', run: () => op('claude_md_append', { project: p, text: String(a.text).slice(0, 4000) }, p), render: d => R.generic(d, `📝 CLAUDE.md · ${p}`, '📝') }); });

  reg('allow_rule', a => { need(a, 'rule'); confirmed(a, 'r'); const p = project(a.project);
    return opTool({ key: 'cc:allow:' + p, title: `🔓 İcazə · ${p}`, icon: '🔓', run: () => op('allow_rule', { project: p, rule: String(a.rule).trim().slice(0, 300) }, p), render: d => R.generic(d, `🔓 İcazə qaydası · ${p}`, '🔓') }); });

  reg('claude_update', a => { const doc = yes(a.doctor); if (!doc) confirmed(a, 'r');
    return opTool({ key: 'cc:update', title: doc ? '🩺 claude doctor' : '⬆️ Claude Code yeniləmə', icon: doc ? '🩺' : '⬆️', run: () => op(doc ? 'claude_doctor' : 'claude_update', {}), render: d => R.generic(d, doc ? '🩺 claude doctor' : '⬆️ Claude Code yeniləmə', doc ? '🩺' : '⬆️') }); });

  reg('set_model_mode', a => {
    const cur = ccDefaults(), n = { ...cur };
    if (a.model) { if (a.model === 'default') delete n.model; else if (['opus', 'sonnet', 'haiku'].includes(a.model)) n.model = a.model; else throw new Error('model: opus | sonnet | haiku | default'); }
    if (a.mode) { if (a.mode === 'normal') delete n.mode; else if (a.mode === 'plan') n.mode = 'plan'; else throw new Error('mode: plan | normal'); }
    try { localStorage.setItem(DKEY, JSON.stringify(n)); } catch (e) {}
    line('a', `✓ Claude Code default-ları: model ${n.model || 'default'}, rejim ${n.mode || 'normal'}`);
    return { ok: true, model: n.model || 'default', mode: n.mode || 'normal', note: 'Applies to the next run_claude_code / dispatch_agents / run_skill tasks from Jarvis.' };
  });

  reg('run_skill', async a => {
    need(a, 'skill');
    const p = project(a.project), skill = String(a.skill).trim().replace(/^\/+/, '');
    if (!/^[\w:.\-]{1,64}$/.test(skill)) throw new Error('skill adı yanlışdır: ' + a.skill);
    const prompt = `Run the /${skill} skill${a.args ? ' ' + String(a.args).trim().slice(0, 1500) : ''} and report briefly.`;
    const body = { action: 'agent_send', project: p, prompt, ...ccDefaults() };
    if (typeof U.saidAt === 'function') body.said_at = U.saidAt();
    const { status, d } = await post('jarvis.php', body);
    if (status !== 200) throw new Error(d.error || 'Göndərilmədi');
    J.watch(+d.id, { project: p, prompt });
    refresh();
    line('a', `🤖 /${skill} → Claude Code (#${d.id}, ${p})` + (d.agent_online ? '' : ' · agent oflayndır'));
    return { ok: true, task_id: d.id, project: p, skill, agent_online: !!d.agent_online, note: d.agent_online ? 'Running; a note arrives when it finishes.' : 'Agent offline: queued.' };
  });

  /* ---------- Cədvəllər ---------- */
  const sched = async (action, extra) => { const { status, d } = await post('schedule.php', { action, ...extra }); if (status !== 200) throw new Error(d.error || 'xəta ' + status); return d; };
  async function showSchedules() { try { const d = await sched('list'); card('sched', R.schedules(d.schedules || [], d.timezone)); return d; } catch (e) { return null; } }
  reg('schedule_add', async a => {
    need(a, 'name', 'rule');
    const body = { name: String(a.name).trim(), rule: String(a.rule).trim() };
    if (a.protocol) body.protocol = String(a.protocol); else if (a.prompt) body.prompt = String(a.prompt); else throw new Error('"protocol" və ya "prompt" lazımdır');
    if (a.project) body.project = project(a.project); else if (a.prompt) body.project = project('');
    const d = await sched('add', body);
    showSchedules();
    line('a', `⏰ Cədvəl: ${d.schedule.name} · ${d.schedule.rule}`);
    return { ok: true, schedule: d.schedule, timezone: d.timezone };
  });
  reg('schedule_list', async () => {
    const d = await sched('list'); card('sched', R.schedules(d.schedules || [], d.timezone));
    return { timezone: d.timezone, now: d.now, schedules: (d.schedules || []).map(s => ({ id: s.id, name: s.name, rule: s.rule, protocol: s.protocol || undefined, project: s.project || undefined, enabled: s.enabled, next_run: s.next_run, last_run: s.last_run })) };
  });
  reg('schedule_remove', async a => {
    if (!a.id && !a.name) throw new Error('"id" və ya "name" lazımdır');
    const d = await sched('remove', a.id ? { id: +a.id } : { name: String(a.name) });
    showSchedules();
    line('a', `⏰ Cədvəl silindi: ${d.removed.name}`);
    return { ok: true, removed: { id: d.removed.id, name: d.removed.name, rule: d.removed.rule } };
  });

  /* ---------- Gecikmə paneli ---------- */
  reg('latency_stats', async () => {
    const { status, d } = await post('latency.php', { action: 'stats' });
    if (status === 404 && !d.error) throw new Error('latency.php hələ quraşdırılmayıb');
    if (status !== 200) throw new Error(d.error || 'xəta ' + status);
    const spec = R.latency(d); card('latency', spec);
    const rows = arr(d.kinds || d.stats || d.rows);
    return { ok: true, hours: d.hours, kinds: rows.slice(0, 12).map(x => ({ kind: x.kind, count: x.count, done: x.done, first: x.first })) };
  });

  // Cədvəl bildirişləri: schedules_due → jarvis.notice {text, level, schedule_id} (proaktiv modul/RT danışdırır; burada təkrar edilmir)

  window.JarvisProjects = { ccDefaults, render: R, brief: BRIEF, op, project, _fromGet: fromGet };
})();
