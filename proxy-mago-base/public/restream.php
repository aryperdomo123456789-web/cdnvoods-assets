<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

require_seeded_or_setup();
Auth::requireLogin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
session_write_close();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CDN Voods — Ao vivo</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        main.live{display:block;padding:20px 24px;max-width:1560px;margin:0 auto}
        .live-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
        .live-head h1{margin:0;font-size:22px;display:flex;align-items:center;gap:10px}
        .pulse{width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 0 rgba(34,197,94,.7);animation:pulse 1.8s infinite}
        @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.55)}70%{box-shadow:0 0 0 10px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
        .live-head .meta{display:flex;align-items:center;gap:10px;font-size:12px;color:var(--muted)}
        .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(132px,1fr));gap:10px;margin-bottom:14px}
        .kpi{background:var(--panel-2);border:1px solid var(--line);border-radius:12px;padding:10px 12px}
        .kpi span{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
        .kpi b{display:block;font-size:24px;line-height:1.15;margin-top:2px;font-variant-numeric:tabular-nums}
        .kpi.accent b{color:var(--accent)}
        .bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px}
        .bar input{width:auto;min-width:190px;padding:8px 11px;font-size:13px;border-radius:10px}
        .chip{border:1px solid var(--line);background:rgba(3,7,18,.5);color:var(--muted);border-radius:999px;padding:6px 13px;font-size:12px;cursor:pointer;margin:0;font-weight:600}
        .chip.on{background:rgba(56,189,248,.16);border-color:rgba(56,189,248,.5);color:#e0f2fe}
        .chip.live.on{background:rgba(34,197,94,.16);border-color:rgba(34,197,94,.5);color:#bbf7d0}
        .spacer{flex:1}
        .live-card{background:var(--panel);border:1px solid var(--line);border-radius:14px;overflow:hidden}
        table.live-t{width:100%;font-size:13px;border-collapse:collapse}
        table.live-t th{position:sticky;top:0;background:#0e1730;font-size:10.5px;padding:9px 10px;z-index:2;white-space:nowrap}
        table.live-t td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:middle}
        table.live-t tr:hover td{background:rgba(56,189,248,.05)}
        .scroll{max-height:62vh;overflow:auto}
        .who{font-weight:700}
        .who a{color:var(--text);text-decoration:none}
        .who a:hover{color:var(--accent-2)}
        .sub{display:block;font-size:11px;color:var(--muted);margin-top:2px}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
        .num{font-variant-numeric:tabular-nums}
        .tag{padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap}
        .k-live{background:rgba(34,197,94,.16);color:#86efac}
        .k-movie{background:rgba(56,189,248,.16);color:#bae6fd}
        .k-series{background:rgba(168,85,247,.18);color:#e9d5ff}
        .k-other{background:rgba(148,163,184,.16);color:#cbd5e1}
        .k-fetch{background:rgba(234,179,8,.16);color:#fde68a}
        .st-on{background:rgba(34,197,94,.16);color:#86efac}
        .st-pause{background:rgba(234,179,8,.16);color:#fde68a}
        .st-end{background:rgba(248,113,113,.16);color:#fca5a5}
        .dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:6px;vertical-align:1px}
        .dot.on{background:var(--accent);animation:pulse 1.8s infinite}
        .dot.off{background:#facc15}
        .dot.end{background:var(--danger)}
        .content{max-width:360px}
        .content b{font-weight:600}
        .empty{padding:26px;text-align:center;color:var(--muted)}
        details.extra{margin-top:16px;background:var(--panel-2);border:1px solid var(--line);border-radius:14px;padding:12px 16px}
        details.extra summary{cursor:pointer;font-weight:700;color:var(--muted)}
        details.extra table{margin-top:10px;font-size:12.5px}
        .fresh{font-size:11px;padding:3px 9px;border-radius:999px}
        .fresh.ok{background:rgba(34,197,94,.14);color:#86efac}
        .fresh.warn{background:rgba(234,179,8,.16);color:#fde68a}
        @media(max-width:980px){.scroll{max-height:none}table.live-t{font-size:12px}}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Rastreio ao vivo do tráfego</span></div>
    <nav>
        <a href="/xui.php">XUI</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/lb.php">LB</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="live">
    <?php if ($flash): ?><div class="alert success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="live-head">
        <h1><span class="pulse"></span> Ao vivo</h1>
        <div class="meta">
            <span class="fresh ok" id="freshbar">verificando frescor…</span>
            <span id="stamp">atualizando…</span>
        </div>
    </div>

    <div class="kpis" id="kpis"></div>

    <div class="bar">
        <input id="q" placeholder="filtrar usuário ou IP">
        <label class="chip live on" id="c_all">todas</label>
        <label class="chip" id="c_live">canais</label>
        <label class="chip" id="c_movie">filmes</label>
        <label class="chip" id="c_series">séries</label>
        <label class="chip" id="c_direct">direct</label>
        <label class="chip" id="c_streaming">só transmitindo</label>
        <span class="spacer"></span>
        <label class="chip" id="c_pause">pausar auto-refresh</label>
    </div>

    <div class="live-card">
        <div class="scroll">
            <table class="live-t">
                <thead><tr>
                    <th>Estado</th><th>Assinante</th><th>Tipo</th><th>Está assistindo</th>
                    <th>IP</th><th>App</th><th>Uptime</th><th>Entrega</th><th>Saída</th><th>Último dado</th>
                </tr></thead>
                <tbody id="body"><tr><td colspan="10" class="empty">carregando conexões…</td></tr></tbody>
            </table>
        </div>
    </div>

    <details class="extra">
        <summary>Parque de assinantes (limites e conexões em uso)</summary>
        <table class="live-t">
            <thead><tr><th>Usuário</th><th>Plano</th><th>Em uso</th><th>Livres</th><th>Direct</th><th>IP final</th><th>Saída</th><th>Status</th></tr></thead>
            <tbody id="userbody"><tr><td colspan="8" class="empty">carregando…</td></tr></tbody>
        </table>
    </details>

    <details class="extra">
        <summary>Saúde do host final de direct source (quem barra a CDN)</summary>
        <div id="dhkpi" class="sub">carregando…</div>
        <table class="live-t">
            <thead><tr><th>Host final</th><th>Veredito</th><th>Culpa</th><th>Hops</th><th>Falha</th><th>Usuários</th><th>Streams</th><th>Visto</th></tr></thead>
            <tbody id="dhbody"><tr><td colspan="8" class="empty">carregando…</td></tr></tbody>
        </table>
    </details>
</main>

<script>
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
const ago = (e) => { const s = Math.max(0, Math.floor(Date.now()/1000) - Number(e||0));
  return s < 60 ? s + 's' : (s < 3600 ? Math.floor(s/60) + 'm' : Math.floor(s/3600) + 'h'); };
const shortApp = (s) => esc((s || '-').slice(0, 26));

const KIND = {
  live:   {cls: 'k-live',   label: 'canal'},
  movie:  {cls: 'k-movie',  label: 'filme'},
  series: {cls: 'k-series', label: 'série'},
  other:  {cls: 'k-other',  label: 'indef.'},
  fetch:  {cls: 'k-fetch',  label: 'playlist'},
};
const STATE = {
  transmitindo: {cls: 'st-on',    dot: 'on'},
  pausado:      {cls: 'st-pause', dot: 'off'},
  encerrando:   {cls: 'st-end',   dot: 'end'},
};
const DELIVERY = {direct_source: 'direct source', direct_proxy: 'direct proxy', restream: 'restream', unknown: '—'};

// Filtros de tela (o servidor devolve totais do parque inteiro; o chip só
// escolhe o que a tabela mostra).
const view = {content: 'all', direct: false, streaming: false, paused: false};
const CHIPS = {c_all: 'all', c_live: 'live', c_movie: 'movie', c_series: 'series'};
Object.keys(CHIPS).forEach((id) => $(id).addEventListener('click', () => {
  view.content = CHIPS[id];
  Object.keys(CHIPS).forEach(k => $(k).classList.toggle('on', k === id));
  $('c_all').classList.toggle('live', view.content === 'all');
  tickLive().catch(() => {});
}));
[['c_direct','direct'],['c_streaming','streaming'],['c_pause','paused']].forEach(([id, key]) => {
  $(id).addEventListener('click', () => {
    view[key] = !view[key];
    $(id).classList.toggle('on', view[key]);
    if (!view.paused) tickLive().catch(() => {});
  });
});
$('q').addEventListener('input', () => { clearTimeout(window.__qt); window.__qt = setTimeout(() => tickLive().catch(() => {}), 350); });

const pollHint = {ms: 0};
function applyMeta(meta) {
  if (Number(meta.poll_after_ms) > 0) pollHint.ms = Number(meta.poll_after_ms);
  const box = $('freshbar');
  const age = Number(meta.data_age_seconds || 0);
  if (meta.degraded) {
    box.className = 'fresh warn';
    box.textContent = 'dado com ' + age + 's de atraso — ' + (meta.reasons || []).join(' | ');
  } else {
    box.className = 'fresh ok';
    box.textContent = 'ao vivo · fonte ' + age + 's · consulta ' + (meta.query_ms || 0) + 'ms';
  }
}
async function getJson(url) {
  const r = await fetch(url, {credentials: 'same-origin'});
  if (!r.ok) throw new Error('http ' + r.status);
  const d = await r.json();
  if (d.error) throw new Error(d.detail || d.error);
  if (d._meta) applyMeta(d._meta);
  return d;
}
const failRow = (id, cols, msg) => { $(id).innerHTML = `<tr><td colspan="${cols}" class="empty">${esc(msg)}</td></tr>`; };

function contentCell(r) {
  const kind = Number(r.is_video) === 1 ? String(r.content_kind || 'other') : 'fetch';
  if (kind === 'fetch') return '<span class="muted">download de lista</span>';
  const name = String(r.content_label || r.content_name || ('stream #' + (r.stream_id || 0)));
  const bits = [];
  if (r.episode_ref) bits.push(String(r.episode_ref));
  if (r.container) bits.push(String(r.container));
  if (Number(r.stream_id) > 0) bits.push('#' + r.stream_id);
  if (r.content_source === 'route') bits.push('tipo pela rota');
  return `<b>${esc(name)}</b><span class="sub">${esc(bits.join(' · '))}</span>`;
}

function renderLive(d) {
  const rows = d.rows || [];
  const t = d.totals || {};
  const ut = d.users_totals || {};
  $('kpis').innerHTML = [
    ['Conexões vivas', t.connections || 0, true],
    ['Transmitindo', t.streaming || 0, false],
    ['Canais', t.live || 0, false],
    ['Filmes', t.movie || 0, false],
    ['Séries', t.series || 0, false],
    ['Direct source', t.direct || 0, false],
    ['Assinantes online', t.users || 0, false],
    ['IPs distintos', t.ips || 0, false],
    ['Slots vendidos', ut.slots_sold || 0, false],
  ].map(([k, v, a]) => `<div class="kpi ${a ? 'accent' : ''}"><span>${k}</span><b class="num">${esc(v)}</b></div>`).join('');

  $('body').innerHTML = rows.length ? rows.map((r) => {
    const kind = Number(r.is_video) === 1 ? String(r.content_kind || 'other') : 'fetch';
    const k = KIND[kind] || KIND.other;
    const st = STATE[String(r.live_state)] || STATE.pausado;
    return `<tr>
      <td><span class="tag ${st.cls}"><span class="dot ${st.dot}"></span>${esc(r.live_state)}</span></td>
      <td class="who"><a href="/restream-user.php?username=${encodeURIComponent(r.username || '')}">${esc(r.username || '-')}</a>
        <span class="sub">${esc(r.public_host || '')}</span></td>
      <td><span class="tag ${k.cls}">${esc(k.label)}</span></td>
      <td class="content">${contentCell(r)}</td>
      <td class="mono">${esc(r.client_ip || '-')}</td>
      <td class="sub" style="margin:0">${shortApp(r.user_agent)}</td>
      <td class="num">${esc(r.uptime_human || '-')}</td>
      <td>${esc(DELIVERY[String(r.delivery_effective)] || r.delivery_effective || '-')}
        ${r.direct_host_effective || r.direct_host ? `<span class="sub mono">${esc(r.direct_host_effective || r.direct_host)}</span>` : ''}</td>
      <td>${esc(r.exit_label || 'main')}</td>
      <td class="sub" style="margin:0">${esc(ago(r.last_seen_epoch))}</td>
    </tr>`;
  }).join('') : '<tr><td colspan="10" class="empty">nenhuma conexão no filtro atual</td></tr>';

  $('stamp').textContent = 'atualizado ' + new Date().toLocaleTimeString('pt-BR');
}

async function tickLive() {
  const p = new URLSearchParams({view: 'live_connections', limit: '150'});
  const q = $('q').value.trim();
  if (q) { p.set(/^[0-9a-fA-F:.]+$/.test(q) && /[.:]/.test(q) ? 'ip' : 'username', q); }
  if (view.content !== 'all') p.set('content', view.content);
  if (view.direct) p.set('direct', '1');
  if (view.streaming) p.set('streaming', '1');
  renderLive(await getJson('/restream-data.php?' + p.toString()));
}

async function tickUsers() {
  const d = await getJson('/restream-data.php?view=users&limit=150');
  const rows = d.rows || [];
  $('userbody').innerHTML = rows.length ? rows.map(u => `<tr>
    <td class="who"><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">${esc(u.username)}</a></td>
    <td>${Number(u.max_connections) > 0 ? esc(u.max_connections) : '∞'}</td>
    <td class="num"><b>${esc(u.connections_used)}</b></td>
    <td class="num">${u.connections_free === null ? '∞' : esc(u.connections_free)}</td>
    <td class="num">${esc(u.direct_sessions_now || 0)}</td>
    <td class="mono">${esc(u.last_ip || '-')}</td>
    <td>${esc(u.lb_labels || 'main')}</td>
    <td>${esc(u.status)}</td>
  </tr>`).join('') : '<tr><td colspan="8" class="empty">nenhum usuário</td></tr>';
}

const VERDICT_CLS = {ok:'st-on', flaky:'st-pause', blocked:'st-end', unreachable:'st-end', degraded:'st-pause', catalog_stale:'st-pause', unknown:'k-other'};
async function tickDirectHealth() {
  const d = await getJson('/restream-data.php?view=direct_health&minutes=60&limit=40');
  const rows = d.hosts || [];
  const v = d.verdicts || {};
  const keys = Object.keys(v).filter(k => Number(v[k]) > 0);
  $('dhkpi').innerHTML = keys.length
    ? keys.map(k => `<span class="tag ${VERDICT_CLS[k] || 'k-other'}">${esc(k)}: ${esc(v[k])}</span>`).join(' ')
    : 'nenhum host final observado na última hora';
  $('dhbody').innerHTML = rows.length ? rows.map(r => `<tr>
    <td class="mono">${esc(r.host)}</td>
    <td><span class="tag ${VERDICT_CLS[r.verdict] || 'k-other'}">${esc(r.verdict)}</span></td>
    <td>${esc(r.blame)}</td><td class="num">${esc(r.hops)}</td><td class="num">${esc(r.fail_rate)}%</td>
    <td class="num">${esc(r.users)}</td><td class="num">${esc(r.streams)}</td><td class="sub">${esc(ago(r.last_epoch))}</td>
  </tr>`).join('') : '<tr><td colspan="8" class="empty">nenhum hop de direct source na janela</td></tr>';
}

function detailOpenByBodyId(id) {
  const body = $(id);
  if (!body) return false;
  const box = body.closest('details');
  return !!(box && box.open);
}

Array.from(document.querySelectorAll('details.extra')).forEach((box) => {
  box.addEventListener('toggle', () => {
    if (!box.open) return;
    if (box.querySelector('#userbody')) { tickUsers().catch((e) => console.warn('users panel degraded', e)); }
    if (box.querySelector('#dhbody')) { tickDirectHealth().catch((e) => console.warn('direct health degraded', e)); }
  });
});

function bootLoop(fn, ms, onFail) {
  let backoff = 1;
  const loop = async () => {
    let next = ms;
    if (document.hidden || view.paused) { setTimeout(loop, 4000); return; }
    try { await fn(); backoff = 1; next = Math.max(ms, pollHint.ms || 0); }
    catch (e) { if (onFail) onFail(e); backoff = Math.min(backoff * 2, 8); next = ms * backoff; }
    setTimeout(loop, next);
  };
  loop();
}

bootLoop(tickLive, 3000, (e) => console.warn('live panel degraded', e));
bootLoop(async () => {
  if (!detailOpenByBodyId('userbody')) return;
  await tickUsers();
}, 30000, (e) => console.warn('users panel degraded', e));
bootLoop(async () => {
  if (!detailOpenByBodyId('dhbody')) return;
  await tickDirectHealth();
}, 45000, (e) => console.warn('direct health degraded', e));
</script>
</body>
</html>
