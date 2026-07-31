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
    <title>CDN Voods — Painel ao vivo</title>
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .hero{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;margin-bottom:16px}
        .hero p{margin:6px 0 0;opacity:.75}
        .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
        .kpi{background:#111827;border:1px solid #1f2937;border-radius:10px;padding:12px 14px}
        .kpi b{display:block;font-size:28px;line-height:1.1;margin-top:4px}
        .toolbar{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}
        .toolbar input,.toolbar select{padding:8px 10px;min-width:160px}
        .split{display:grid;grid-template-columns:1.15fr .85fr;gap:16px}
        table{width:100%;font-size:13px}
        td,th{padding:7px 8px;vertical-align:top}
        .tag{padding:2px 7px;border-radius:999px;font-size:11px;display:inline-block}
        .ok{background:#064e3b}
        .warn{background:#78350f}
        .bad{background:#7f1d1d}
        .info{background:#1e3a8a}
        .muted{opacity:.68}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .card h2{margin-bottom:8px}
        .compact th,.compact td{padding:6px}
        @media (max-width: 1180px){.split{grid-template-columns:1fr}}
    </style>
</head>
<body class="page-bg">
<header class="topbar">
    <div><strong>CDN Voods</strong> <span>Painel ao vivo enxuto</span></div>
    <nav>
        <a href="/xui.php">XUI</a>
        <a href="/dashboard.php">Domínios</a>
        <a href="/lb.php">LB</a>
        <a href="/logout.php">Sair</a>
    </nav>
</header>

<main class="grid">
    <?php if ($flash): ?><section class="card full"><div class="alert success"><?php echo htmlspecialchars($flash); ?></div></section><?php endif; ?>

    <section class="card full">
        <div class="hero">
            <div>
                <h1 style="margin:0">Painel operacional</h1>
                <p>Somente o que importa: usuários do XUI, conexões em uso, direct aberto agora, IP final, app, conteúdo e saída main/LB.</p>
            </div>
            <div class="muted" id="stamp">atualizando…</div>
        </div>
        <div class="kpis" id="kpis">
            <div class="kpi"><span class="muted">carregando</span><b>…</b></div>
        </div>
    </section>

    <section class="card full">
        <h2>Usuários do XUI</h2>
        <div class="toolbar">
            <input id="u_q" placeholder="buscar usuário">
            <select id="u_mode">
                <option value="all">todos</option>
                <option value="active">só ativos</option>
                <option value="direct">só direct aberto</option>
                <option value="over">só acima do limite</option>
            </select>
            <label class="muted"><input type="checkbox" id="pause"> pausar</label>
        </div>
        <table class="compact">
            <thead><tr>
                <th>Usuário</th><th>Plano</th><th>Em uso</th><th>Livres</th><th>Direct</th>
                <th>IP final</th><th>App</th><th>Último conteúdo</th><th>Uptime</th><th>Saída atual</th><th>Rota do cérebro</th><th>Status</th>
            </tr></thead>
            <tbody id="userbody"><tr><td colspan="12">carregando…</td></tr></tbody>
        </table>
    </section>

    <section class="split full">
        <section class="card">
            <h2>Direct aberto agora</h2>
            <table class="compact">
                <thead><tr>
                    <th>Usuário</th><th>IP</th><th>App</th><th>Conteúdo</th><th>Uptime</th><th>Estado</th><th>Host direct</th><th>Saída</th>
                </tr></thead>
                <tbody id="directbody"><tr><td colspan="8">carregando…</td></tr></tbody>
            </table>
        </section>

        <section class="card">
            <h2>Sessões ao vivo</h2>
            <table class="compact">
                <thead><tr>
                    <th>Usuário</th><th>Tipo</th><th>IP</th><th>App</th><th>Conteúdo</th><th>Uptime</th><th>Estado</th><th>Saída</th>
                </tr></thead>
                <tbody id="sessbody"><tr><td colspan="8">carregando…</td></tr></tbody>
            </table>
        </section>
    </section>
</main>

<script>
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[<>&"]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;'}[c]));
const ago = (epoch) => {
  const s = Math.max(0, Math.floor(Date.now()/1000) - Number(epoch||0));
  if (s < 60) return s + 's';
  if (s < 3600) return Math.floor(s/60) + 'm';
  return Math.floor(s/3600) + 'h';
};
const uptime = (startEpoch) => {
  const s = Math.max(0, Math.floor(Date.now()/1000) - Number(startEpoch||0));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) return `${h}h ${m}m`;
  if (m > 0) return `${m}m ${sec}s`;
  return `${sec}s`;
};
const failRow = (id, cols, msg) => {
  const el = $(id);
  if (el) el.innerHTML = `<tr><td colspan="${cols}" class="muted">${esc(msg)}</td></tr>`;
};
const statusTag = (s) => {
  const cls = s === 'streaming' ? 'ok' : (s === 'over_limit' ? 'bad' : (s === 'full' ? 'warn' : 'info'));
  return `<span class="tag ${cls}">${esc(s)}</span>`;
};
const shortPlayer = (s) => esc((s || '').slice(0, 28) || '-');
const contentName = (row) => esc(row.last_stream_name || row.stream_id || '-');
const displayCurrentExit = (u) => {
  const current = String(u.lb_labels || 'main');
  const route = String(u.route_lb_label || 'main');
  if (current === 'main' && route !== 'main') return route;
  return current;
};
const sessionState = (row) => {
  const delta = Math.max(0, Math.floor(Date.now()/1000) - Number(row.last_seen_epoch || 0));
  if (delta <= 25) return {label: 'tocando', cls: 'ok'};
  if (delta <= HOLD_SECONDS) return {label: 'pausado/sem request', cls: 'warn'};
  return {label: 'encerrando', cls: 'bad'};
};
const HOLD_SECONDS = 150;
const state = {
  users: new Map(),
  sessions: new Map(),
  direct: new Map(),
  userTotals: {},
};

function summarizeUserSessions() {
  const grouped = new Map();
  for (const row of state.sessions.values()) {
    const username = String(row.username || '');
    if (!username) continue;
    const current = grouped.get(username) || {
      activeVideo: 0,
      directNow: 0,
      lastSeenEpoch: 0,
      lastIp: '-',
      lastPlayer: '-',
      lastContent: '-',
      uptimeStartEpoch: 0,
      lbLabel: 'main',
      hasSticky: false,
    };
    current.activeVideo += 1;
    if (Number(row.effective_direct_source || row.direct_source) === 1) current.directNow += 1;
    const lastSeen = Number(row.last_seen_epoch || 0);
    if (lastSeen >= current.lastSeenEpoch) {
      current.lastSeenEpoch = lastSeen;
      current.lastIp = String(row.client_ip || '-');
      current.lastPlayer = String(row.user_agent || '-');
      current.lastContent = String(row.last_stream_name || row.stream_id || row.session_kind || '-');
      current.uptimeStartEpoch = Number(row.uptime_start_epoch || row.direct_first_epoch || row.started_epoch || 0);
      current.lbLabel = String(row.lb_label || 'main');
    }
    current.hasSticky = current.hasSticky || Boolean(row._sticky);
    grouped.set(username, current);
  }
  return grouped;
}

function mergeSticky(prev, next, kind) {
  const now = Math.floor(Date.now() / 1000);
  const out = new Map();
  const ttl = kind === 'users' ? HOLD_SECONDS : HOLD_SECONDS;

  for (const row of next) {
    const key = kind === 'users'
      ? String(row.username || '')
      : String(row.session_key || `${row.username}|${row.client_ip}|${row.stream_id}|${row.session_kind}`);
    if (!key) continue;
    out.set(key, {...row, _sticky_seen_epoch: now, _sticky: false});
  }

  for (const [key, row] of prev.entries()) {
    if (out.has(key)) continue;
    const lastSeen = Number(row.last_seen_epoch || row.session_epoch || row.last_activity_epoch || row._sticky_seen_epoch || 0);
    if ((now - lastSeen) <= ttl) {
      out.set(key, {...row, _sticky: true, _sticky_seen_epoch: lastSeen || now});
    }
  }

  return out;
}

async function getJson(url) {
  const r = await fetch(url, {credentials: 'same-origin'});
  if (!r.ok) throw new Error('http ' + r.status);
  const d = await r.json();
  if (d.error) throw new Error(d.detail || d.error);
  if (d._meta) applyMeta(d._meta);
  return d;
}

// Frescor + polling adaptativo (Fase 1.3/1.4): o servidor manda a idade do
// dado e o intervalo que o painel deve usar. Assim nenhuma aba martela o
// SQLite mais rápido do que a fonte se atualiza.
const pollHint = {ms: 0};
function applyMeta(meta) {
  if (Number(meta.poll_after_ms) > 0) pollHint.ms = Number(meta.poll_after_ms);
  const box = document.getElementById('freshbar');
  if (!box) return;
  const age = Number(meta.data_age_seconds || 0);
  if (meta.degraded) {
    box.className = 'tag warn';
    box.textContent = 'dado com ' + age + 's de atraso — ' + (meta.reasons || []).join(' | ');
  } else {
    box.className = 'tag ok';
    box.textContent = 'ao vivo (fonte com ' + age + 's, consulta ' + (meta.query_ms || 0) + 'ms)';
  }
}

const POLL_USERS_MS = 6000;
const POLL_SESSIONS_MS = 4000;
const USERS_LIMIT = 150;
const SESSIONS_LIMIT = 60;

function renderUsers() {
  const mode = $('u_mode').value;
  const sessionSummary = summarizeUserSessions();
  let rows = Array.from(state.users.values());
  rows = rows.map((u) => {
    const live = sessionSummary.get(String(u.username || ''));
    if (!live) return u;
    const used = Math.max(Number(u.connections_used || 0), Number(live.activeVideo || 0));
    const maxConn = Number(u.max_connections || 0);
    const connectionsFree = u.connections_free === null || maxConn <= 0 ? null : Math.max(0, maxConn - used);
    return {
      ...u,
      connections_used: used,
      connections_free: connectionsFree,
      direct_sessions_now: Math.max(Number(u.direct_sessions_now || 0), Number(live.directNow || 0)),
      last_ip: live.lastIp || u.last_ip,
      last_player: live.lastPlayer || u.last_player,
      last_stream_name: live.lastContent || u.last_stream_name,
      last_epoch: Math.max(Number(u.last_epoch || 0), Number(live.lastSeenEpoch || 0)),
      uptime_start_epoch_live: Number(live.uptimeStartEpoch || 0),
      lb_labels: live.lbLabel || u.lb_labels,
      _sticky: Boolean(u._sticky || live.hasSticky),
      status: used > 0 ? 'streaming' : u.status,
    };
  });
  if (mode === 'active') rows = rows.filter(r => Number(r.connections_used || 0) > 0 || r._sticky);
  if (mode === 'direct') rows = rows.filter(r => Number(r.direct_sessions_now || 0) > 0 || r._sticky);
  rows.sort((a, b) =>
    Number(b.connections_used || 0) - Number(a.connections_used || 0)
    || Number(b.direct_sessions_now || 0) - Number(a.direct_sessions_now || 0)
    || String(a.username || '').localeCompare(String(b.username || ''))
  );

  const totals = state.userTotals || {};
  const kpiConnections = Math.max(
    Number(totals.connections_video || 0),
    rows.reduce((sum, row) => sum + Number(row.connections_used || 0), 0)
  );
  const kpiDirect = rows.reduce((n, r) => n + Number(r.direct_sessions_now || 0), 0);
  const kpiUsersActive = rows.filter(r => Number(r.connections_used || 0) > 0 || Number(r.direct_sessions_now || 0) > 0).length;

  $('kpis').innerHTML = [
    ['Usuários XUI', totals.users_total || rows.length || 0],
    ['Habilitados', totals.users_enabled || 0],
    ['Conexões em uso', kpiConnections],
    ['Slots vendidos', totals.slots_sold || 0],
    ['Direct aberto', kpiDirect],
    ['Usuários ativos', kpiUsersActive]
  ].map(([k,v]) => `<div class="kpi"><span class="muted">${k}</span><b>${esc(v)}</b></div>`).join('');

  $('userbody').innerHTML = rows.length ? rows.map(u => `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(u.username)}">${esc(u.username)}</a></td>
    <td>${Number(u.max_connections) > 0 ? esc(u.max_connections) : '∞'}</td>
    <td><b>${esc(u.connections_used)}</b></td>
    <td>${u.connections_free === null ? '∞' : esc(u.connections_free)}</td>
    <td>${Number(u.direct_sessions_now) > 0 ? '<span class="tag info">'+esc(u.direct_sessions_now)+'</span>' : '0'}</td>
    <td class="mono">${esc(u.last_ip || '-')}</td>
    <td class="muted">${shortPlayer(u.last_player)}</td>
    <td>${esc(u.last_stream_name || u.last_kind || '-')}</td>
    <td>${u.uptime_start_epoch_live ? esc(uptime(u.uptime_start_epoch_live)) : '-'}</td>
    <td>${esc(displayCurrentExit(u))}</td>
    <td>${esc(u.route_lb_label || 'main')}</td>
    <td>${statusTag(u._sticky ? 'streaming' : u.status)}${u._sticky ? ' <span class="muted">uptime mantido</span>' : ''}</td>
  </tr>`).join('') : '<tr><td colspan="12" class="muted">nenhum usuário no filtro atual</td></tr>';

  $('stamp').textContent = 'última atualização: ' + new Date().toLocaleTimeString('pt-BR');
}

async function tickUsers() {
  if ($('pause').checked) return;
  const mode = $('u_mode').value;
  const p = new URLSearchParams({view: 'users', limit: String(USERS_LIMIT)});
  if ($('u_q').value.trim()) p.set('q', $('u_q').value.trim());
  if (mode === 'active' || mode === 'direct') p.set('only_active', '1');
  if (mode === 'over') p.set('over', '1');

  const d = await getJson('/restream-data.php?' + p.toString());
  let rows = d.rows || [];
  if (mode === 'direct') rows = rows.filter(r => Number(r.direct_sessions_now) > 0);
  state.userTotals = d.totals || {};
  state.users = mergeSticky(state.users, rows, 'users');
  renderUsers();
}

async function tickSessions() {
  if ($('pause').checked) return;
  const p = new URLSearchParams({view: 'sessions', limit: String(SESSIONS_LIMIT)});
  if ($('u_q').value.trim()) p.set('username', $('u_q').value.trim());
  const d = await getJson('/restream-data.php?' + p.toString());
  const rows = d.rows || [];
  state.sessions = mergeSticky(state.sessions, rows, 'sessions');
  const mergedRows = Array.from(state.sessions.values())
    .sort((a, b) => Number(b.last_seen_epoch || 0) - Number(a.last_seen_epoch || 0));

  $('sessbody').innerHTML = mergedRows.length ? mergedRows.map(s => {
    const st = sessionState(s);
    return `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(s.username)}">${esc(s.username)}</a></td>
    <td>${esc(s.session_kind)}</td>
    <td class="mono">${esc(s.client_ip)}</td>
    <td class="muted">${shortPlayer(s.user_agent)}</td>
    <td>${contentName(s)}${s._sticky ? ' <span class="muted">(' + esc(ago(s.last_seen_epoch)) + ' sem novo request)</span>' : ''}</td>
    <td>${esc(uptime(s.uptime_start_epoch || s.direct_first_epoch || s.started_epoch))}</td>
    <td><span class="tag ${st.cls}">${esc(st.label)}</span></td>
    <td>${esc(s.lb_label || 'main')}</td>
  </tr>`;
  }).join('') : '<tr><td colspan="8" class="muted">nenhuma sessão ativa</td></tr>';

  const directRows = mergedRows.filter(s => Number(s.effective_direct_source || s.direct_source) === 1);
  state.direct = new Map(directRows.map(s => [String(s.session_key || `${s.username}|${s.client_ip}|${s.stream_id}`), s]));
  const directMerged = Array.from(state.direct.values())
    .sort((a, b) => Number(b.last_seen_epoch || 0) - Number(a.last_seen_epoch || 0));
  $('directbody').innerHTML = directMerged.length ? directMerged.map(s => {
    const st = sessionState(s);
    return `<tr>
    <td><a href="/restream-user.php?username=${encodeURIComponent(s.username)}">${esc(s.username)}</a></td>
    <td class="mono">${esc(s.client_ip)}</td>
    <td class="muted">${shortPlayer(s.user_agent)}</td>
    <td>${contentName(s)}${s._sticky ? ' <span class="muted">(' + esc(ago(s.last_seen_epoch)) + ' sem novo request)</span>' : ''}</td>
    <td>${esc(uptime(s.uptime_start_epoch || s.direct_first_epoch || s.started_epoch))}</td>
    <td><span class="tag ${st.cls}">${esc(st.label)}</span></td>
    <td class="muted">${esc(s.direct_host_effective || s.direct_host || '-')}</td>
    <td>${esc(s.lb_label || 'main')}</td>
  </tr>`;
  }).join('') : '<tr><td colspan="8" class="muted">nenhum direct aberto agora</td></tr>';

  if (state.users.size > 0) {
    renderUsers();
  }
}

function bootLoop(fn, ms, onFail) {
  let backoff = 1;
  const loop = async () => {
    let next = ms;
    if (document.hidden || $('pause').checked) {
      // Aba em segundo plano ou pausada não consulta nada.
      setTimeout(loop, 5000);
      return;
    }
    try {
      await fn();
      backoff = 1;
      next = Math.max(ms, pollHint.ms || 0);
    } catch (e) {
      if (onFail) onFail(e);
      backoff = Math.min(backoff * 2, 8);
      next = ms * backoff;
    }
    setTimeout(loop, next);
  };
  loop();
}

['u_q','u_mode','pause'].forEach(id => $(id).addEventListener('change', () => {
  tickUsers().catch(() => {});
  tickSessions().catch(() => {});
}));

bootLoop(tickUsers, POLL_USERS_MS, (e) => failRow('userbody', 12, 'falha ao carregar usuários: ' + e.message));
bootLoop(tickSessions, POLL_SESSIONS_MS, (e) => {
  failRow('sessbody', 8, 'falha ao carregar sessões: ' + e.message);
  failRow('directbody', 8, 'falha ao carregar direct: ' + e.message);
});
</script>
</body>
</html>
